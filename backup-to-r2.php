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

// A run that has to zip several hundred MB of files can take a few minutes.
// If cron is (or gets accidentally left) firing more often than a run takes
// to finish, this stops runs from stacking on top of each other and fighting
// over CPU/bandwidth. The lock is held for as long as $lockHandle stays in
// scope, i.e. until the script exits.
$lockHandle = fopen(rtrim($config['tmp_dir'], '/') . '/backup.lock', 'c');
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another backup run is still in progress — skipping this run.\n");
    exit(0);
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
//
// This host has shell_exec() disabled (common on cheap shared hosting), so
// shelling out to the `mysqldump` binary isn't an option. Instead this dumps
// each database straight over a PDO connection — schema via SHOW CREATE
// TABLE, data via a streamed (unbuffered) SELECT * so a multi-GB table
// doesn't get pulled entirely into memory. It does not dump stored
// procedures/triggers (rare in Laravel apps, which keep schema in
// migrations) — flag it if you rely on those.

function pdo_mysqldump(array $db, string $outputPath): ?string {
    try {
        $pdo = new PDO(
            "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
            $db['user'],
            $db['pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
            ]
        );
    } catch (PDOException $e) {
        return 'connection failed: ' . $e->getMessage();
    }

    $fh = fopen($outputPath, 'wb');
    fwrite($fh, "-- PHP-native dump of {$db['name']} on " . date('Y-m-d H:i:s') . "\n");
    fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);

    foreach ($tables as [$tableName, $tableType]) {
        fwrite($fh, "\n-- ----------------------------\n-- {$tableName}\n-- ----------------------------\n");
        fwrite($fh, "DROP TABLE IF EXISTS `{$tableName}`;\n");

        $createRow = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '``', $tableName) . '`')->fetch(PDO::FETCH_NUM);
        fwrite($fh, $createRow[1] . ";\n\n");

        if ($tableType === 'VIEW') {
            continue; // a view has no data of its own to dump
        }

        // A separate, still-unbuffered statement per table for the actual rows.
        $stmt = $pdo->query('SELECT * FROM `' . str_replace('`', '``', $tableName) . '`');
        $batch = [];
        $columns = null;
        $batchSize = 300;

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $columns ??= array_keys($row);
            $values = array_map(
                fn($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? $v : $pdo->quote($v)),
                $row
            );
            $batch[] = '(' . implode(',', $values) . ')';

            if (count($batch) >= $batchSize) {
                fwrite($fh, "INSERT INTO `{$tableName}` (`" . implode('`,`', $columns) . "`) VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if (!empty($batch) && $columns !== null) {
            fwrite($fh, "INSERT INTO `{$tableName}` (`" . implode('`,`', $columns) . "`) VALUES\n" . implode(",\n", $batch) . ";\n");
        }
    }

    fwrite($fh, "\nSET FOREIGN_KEY_CHECKS=1;\n");
    fwrite($fh, "-- Dump completed on " . date('Y-m-d H:i:s') . "\n");
    fclose($fh);

    return null; // success
}

foreach ($config['databases'] as $db) {
    $dumpFile = rtrim($config['tmp_dir'], '/') . "/{$db['name']}_{$runStamp}.sql";
    $gzFile   = $dumpFile . '.gz';

    $error = pdo_mysqldump($db, $dumpFile);

    if ($error !== null || !is_file($dumpFile) || filesize($dumpFile) === 0) {
        log_line($log, "ERROR: dump failed for database '{$db['name']}'. " . ($error ?? 'no output produced'));
        @unlink($dumpFile);
        continue;
    }

    // pdo_mysqldump() only writes this exact line as the very last thing it
    // does, after a clean finish. Its absence means the dump got cut off
    // partway through (timeout, dropped DB connection, disk full, etc) —
    // better to skip uploading it than to silently ship a broken backup.
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
