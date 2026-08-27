<?php
/**
 * Daily database + file backup to Cloudflare R2.
 * Run via cPanel Cron Job (no SSH required):
 *   php -q /home/youruser/backup-scripts/backup-to-r2.php
 *
 * Setup:
 *   1. cp backup-config.sample.php backup-config.php, fill it in, chmod 600.
 *   2. Keep both files OUTSIDE public_html.
 *   3. Add a cPanel Cron Job: minute=0 hour=0 * * * (12:00am daily)
 *      Command: php -q /home/youruser/backup-scripts/backup-to-r2.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$configPath = __DIR__ . '/backup-config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Missing backup-config.php — copy backup-config.sample.php and fill it in.\n");
    exit(1);
}
$config = require $configPath;

$runStamp = date('Y-m-d_His');
$log = [];

function log_line(array &$log, string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    $log[] = $line;
    fwrite(STDOUT, $line . "\n");
}

function write_log_file(string $logFile, array $log): void {
    @file_put_contents($logFile, implode("\n", $log) . "\n", FILE_APPEND);
}

function fail_hard(array $config, array &$log, string $msg): void {
    log_line($log, 'FATAL: ' . $msg);
    write_log_file($config['log_file'], $log);
    exit(1);
}

// ---- sanity checks -------------------------------------------------------

if (!is_dir($config['tmp_dir']) && !mkdir($config['tmp_dir'], 0700, true)) {
    fail_hard($config, $log, "Could not create tmp_dir: {$config['tmp_dir']}");
}

$canExec = function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true);
if (!$canExec) {
    log_line($log, 'WARNING: shell_exec() is disabled on this host — database dumps will be SKIPPED. File backups will still run. Ask your host to enable shell_exec for mysqldump, or move databases to a host that allows it.');
}

$producedFiles = [];

function dump_has_completed_marker(string $path): bool {
    $fh = fopen($path, 'rb');
    $size = filesize($path);
    $readSize = min(4096, $size);
    fseek($fh, -$readSize, SEEK_END);
    $tail = fread($fh, $readSize);
    fclose($fh);
    return str_contains($tail, '-- Dump completed on');
}

// ---- database dumps -------------------------------------------------------

foreach ($config['databases'] as $db) {
    if (!$canExec) {
        break;
    }

    $dumpFile = rtrim($config['tmp_dir'], '/') . "/{$db['name']}_{$runStamp}.sql";
    $gzFile   = $dumpFile . '.gz';

    // Credentials file instead of --password on the CLI, so the password
    // never appears in `ps aux` output (visible to other users on shared hosting).
    $credFile = tempnam($config['tmp_dir'], 'mycnf_');
    chmod($credFile, 0600);
    file_put_contents($credFile, sprintf(
        "[client]\nhost=%s\nuser=%s\npassword=%s\n",
        $db['host'], $db['user'], $db['pass']
    ));

    $cmd = sprintf(
        'mysqldump --defaults-extra-file=%s --single-transaction --routines --triggers %s 2>&1',
        escapeshellarg($credFile),
        escapeshellarg($db['name'])
    );

    $output = shell_exec($cmd . ' > ' . escapeshellarg($dumpFile));
    @unlink($credFile);

    if (!is_file($dumpFile) || filesize($dumpFile) === 0) {
        log_line($log, "ERROR: mysqldump produced no output for database '{$db['name']}'. Output: " . trim((string) $output));
        @unlink($dumpFile);
        continue;
    }

    // mysqldump only writes this exact line as the very last thing it does,
    // after a clean finish. Its absence means the dump got cut off partway
    // through (timeout, dropped DB connection, disk full, etc) — better to
    // skip uploading it than to silently ship a broken backup.
    if (!dump_has_completed_marker($dumpFile)) {
        log_line($log, "ERROR: dump for '{$db['name']}' looks truncated/incomplete (missing '-- Dump completed' marker) — NOT uploading it.");
        @unlink($dumpFile);
        continue;
    }

    // Gzip in pure PHP so we don't depend on a system `gzip` binary either.
    $in = fopen($dumpFile, 'rb');
    $out = gzopen($gzFile, 'wb9');
    while (!feof($in)) {
        gzwrite($out, fread($in, 1024 * 1024));
    }
    fclose($in);
    gzclose($out);
    unlink($dumpFile);

    log_line($log, "Dumped database '{$db['name']}' -> " . basename($gzFile) . ' (' . round(filesize($gzFile) / 1024 / 1024, 2) . ' MB)');
    $producedFiles[] = [
        'path' => $gzFile,
        'key'  => "db/{$db['name']}/{$runStamp}.sql.gz",
    ];
}

// ---- file directory zips ----------------------------------------------

foreach ($config['directories'] as $dir) {
    if (!is_dir($dir['path'])) {
        log_line($log, "ERROR: directory not found, skipping: {$dir['path']}");
        continue;
    }

    $zipFile = rtrim($config['tmp_dir'], '/') . "/{$dir['label']}_{$runStamp}.zip";
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        log_line($log, "ERROR: could not create zip for '{$dir['label']}'");
        continue;
    }

    $exclude = $dir['exclude'] ?? [];
    $basePath = rtrim($dir['path'], '/');
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $file) {
        $relativePath = substr($file->getPathname(), strlen($basePath) + 1);

        $skip = false;
        foreach ($exclude as $ex) {
            if (str_starts_with($relativePath, rtrim($ex, '/') . '/') || $relativePath === $ex) {
                $skip = true;
                break;
            }
        }
        if ($skip) {
            continue;
        }

        if ($file->isDir()) {
            $zip->addEmptyDir($relativePath);
        } else {
            $zip->addFile($file->getPathname(), $relativePath);
        }
    }

    $zip->close();

    if (!is_file($zipFile) || filesize($zipFile) === 0) {
        log_line($log, "ERROR: zip is empty for '{$dir['label']}'");
        @unlink($zipFile);
        continue;
    }

    log_line($log, "Zipped '{$dir['label']}' -> " . basename($zipFile) . ' (' . round(filesize($zipFile) / 1024 / 1024, 2) . ' MB)');
    $producedFiles[] = [
        'path' => $zipFile,
        'key'  => "files/{$dir['label']}/{$runStamp}.zip",
    ];
}

if (empty($producedFiles)) {
    fail_hard($config, $log, 'Nothing was produced (no db dumps, no file zips) — aborting before upload.');
}

// ---- upload to Cloudflare R2 (S3-compatible, SigV4) ------------------------

function r2_put_object(array $r2, string $localPath, string $key, array &$log): bool {
    $endpointHost = "{$r2['account_id']}.r2.cloudflarestorage.com";
    $region = 'auto';
    $service = 's3';
    $objectKey = ltrim(($r2['key_prefix'] ?? '') . $key, '/');

    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $payloadHash = hash_file('sha256', $localPath); // streams the file, doesn't load it into memory

    $canonicalUri = '/' . $r2['bucket'] . '/' . implode('/', array_map('rawurlencode', explode('/', $objectKey)));
    $canonicalHeaders = "host:{$endpointHost}\n" .
        "x-amz-content-sha256:{$payloadHash}\n" .
        "x-amz-date:{$amzDate}\n";
    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

    $canonicalRequest = implode("\n", [
        'PUT',
        $canonicalUri,
        '',
        $canonicalHeaders,
        $signedHeaders,
        $payloadHash,
    ]);

    $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $amzDate,
        $credentialScope,
        hash('sha256', $canonicalRequest),
    ]);

    $kSecret = 'AWS4' . $r2['secret_key'];
    $kDate = hash_hmac('sha256', $dateStamp, $kSecret, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authHeader = "AWS4-HMAC-SHA256 Credential={$r2['access_key']}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

    $url = "https://{$endpointHost}{$canonicalUri}";
    $fileHandle = fopen($localPath, 'rb');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_PUT => true,
        CURLOPT_INFILE => $fileHandle,
        CURLOPT_INFILESIZE => filesize($localPath),
        CURLOPT_HTTPHEADER => [
            "x-amz-date: {$amzDate}",
            "x-amz-content-sha256: {$payloadHash}",
            "Authorization: {$authHeader}",
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 0, // let large uploads take as long as they need
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    fclose($fileHandle);

    if ($httpCode >= 200 && $httpCode < 300) {
        log_line($log, "Uploaded -> r2://{$r2['bucket']}/{$objectKey}");
        return true;
    }

    log_line($log, "ERROR uploading {$objectKey}: HTTP {$httpCode} {$curlError} {$response}");
    return false;
}

$allUploadsOk = true;
foreach ($producedFiles as $file) {
    $ok = r2_put_object($config['r2'], $file['path'], $file['key'], $log);
    $allUploadsOk = $allUploadsOk && $ok;
    // Always clean up local temp file whether upload succeeded or not —
    // shared hosting disk quotas are tight and this runs daily.
    @unlink($file['path']);
}

write_log_file($config['log_file'], $log);

if ($allUploadsOk && !empty($config['healthcheck_url'])) {
    @file_get_contents($config['healthcheck_url']);
}

exit($allUploadsOk ? 0 : 1);
