<?php
/**
 * Run this LOCALLY on your own machine (not on cPanel) to list and pull down
 * backups from Cloudflare R2 — either to eyeball them, or to actually do a
 * restore drill into a local scratch database. Untested backups are just
 * assumptions; this is what turns them into something you've verified works.
 *
 * Setup: copy your server's backup-config.php next to this file (or just the
 * 'r2' section of it into a local one) — same format is reused.
 *
 * Usage:
 *   php restore-from-r2.php list [prefix]
 *   php restore-from-r2.php latest <label>            # e.g. "projectname" or a db name
 *   php restore-from-r2.php get <full-key> [outPath]
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$configPath = __DIR__ . '/backup-config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Missing backup-config.php next to this script (copy it down from the server, or make a local one with just the 'r2' section filled in).\n");
    exit(1);
}
$config = require $configPath;
$r2 = $config['r2'];

const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

function r2_canonical_query(array $params): string {
    ksort($params);
    $parts = [];
    foreach ($params as $k => $v) {
        $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
    }
    return implode('&', $parts);
}

function r2_encode_path(string $path): string {
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function r2_sigv4_headers(array $r2, string $method, string $canonicalUri, string $canonicalQuery, string $payloadHash): array {
    $endpointHost = "{$r2['account_id']}.r2.cloudflarestorage.com";
    $region = 'auto';
    $service = 's3';
    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');

    $canonicalHeaders = "host:{$endpointHost}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n";
    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

    $canonicalRequest = implode("\n", [$method, $canonicalUri, $canonicalQuery, $canonicalHeaders, $signedHeaders, $payloadHash]);

    $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
    $stringToSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $credentialScope, hash('sha256', $canonicalRequest)]);

    $kSecret = 'AWS4' . $r2['secret_key'];
    $kDate = hash_hmac('sha256', $dateStamp, $kSecret, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);

    $authHeader = "AWS4-HMAC-SHA256 Credential={$r2['access_key']}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

    return [
        "Host: {$endpointHost}",
        "x-amz-date: {$amzDate}",
        "x-amz-content-sha256: {$payloadHash}",
        "Authorization: {$authHeader}",
    ];
}

function r2_list(array $r2, string $prefix): array {
    $canonicalUri = '/' . rawurlencode($r2['bucket']);
    $query = ['list-type' => '2', 'prefix' => $prefix, 'max-keys' => '1000'];
    $canonicalQuery = r2_canonical_query($query);
    $headers = r2_sigv4_headers($r2, 'GET', $canonicalUri, $canonicalQuery, EMPTY_SHA256);

    $url = "https://{$r2['account_id']}.r2.cloudflarestorage.com{$canonicalUri}?{$canonicalQuery}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        fwrite(STDERR, "List failed: HTTP {$httpCode}\n{$body}\n");
        exit(1);
    }

    $xml = simplexml_load_string($body);
    $items = [];
    if (isset($xml->Contents)) {
        foreach ($xml->Contents as $c) {
            $items[] = [
                'key' => (string) $c->Key,
                'size' => (int) $c->Size,
                'modified' => (string) $c->LastModified,
            ];
        }
    }
    return $items;
}

function r2_download(array $r2, string $key, string $outPath): void {
    $canonicalUri = '/' . rawurlencode($r2['bucket']) . '/' . r2_encode_path($key);
    $headers = r2_sigv4_headers($r2, 'GET', $canonicalUri, '', EMPTY_SHA256);

    $url = "https://{$r2['account_id']}.r2.cloudflarestorage.com{$canonicalUri}";
    $dir = dirname($outPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $fh = fopen($outPath, 'wb');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FILE => $fh,
        CURLOPT_FAILONERROR => false,
        CURLOPT_TIMEOUT => 0,
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fh);

    if ($httpCode !== 200) {
        @unlink($outPath);
        fwrite(STDERR, "Download failed: HTTP {$httpCode} for key {$key}\n");
        exit(1);
    }

    echo "Downloaded -> {$outPath} (" . round(filesize($outPath) / 1024 / 1024, 2) . " MB)\n";
}

// ---- CLI dispatch ---------------------------------------------------------

$command = $argv[1] ?? null;
$fullPrefix = rtrim($r2['key_prefix'] ?? '', '/');

switch ($command) {
    case 'list':
        $prefix = trim($fullPrefix . '/' . ($argv[2] ?? ''), '/');
        $items = r2_list($r2, $prefix);
        if (empty($items)) {
            echo "No objects found under prefix '{$prefix}'.\n";
            break;
        }
        foreach ($items as $item) {
            printf("%-60s %8.2f MB   %s\n", $item['key'], $item['size'] / 1024 / 1024, $item['modified']);
        }
        break;

    case 'latest':
        $label = $argv[2] ?? null;
        if (!$label) {
            fwrite(STDERR, "Usage: php restore-from-r2.php latest <label>\n");
            exit(1);
        }
        $candidates = array_merge(
            r2_list($r2, trim("{$fullPrefix}/db/{$label}/", '/')),
            r2_list($r2, trim("{$fullPrefix}/files/{$label}/", '/'))
        );
        if (empty($candidates)) {
            echo "No backups found for label '{$label}'.\n";
            break;
        }
        usort($candidates, fn($a, $b) => strcmp($b['modified'], $a['modified']));
        $latest = $candidates[0];
        $outPath = __DIR__ . '/restored/' . basename($latest['key']);
        r2_download($r2, $latest['key'], $outPath);
        break;

    case 'get':
        $key = $argv[2] ?? null;
        if (!$key) {
            fwrite(STDERR, "Usage: php restore-from-r2.php get <full-key> [outPath]\n");
            exit(1);
        }
        $outPath = $argv[3] ?? (__DIR__ . '/restored/' . basename($key));
        r2_download($r2, $key, $outPath);
        break;

    default:
        echo "Usage:\n";
        echo "  php restore-from-r2.php list [prefix]\n";
        echo "  php restore-from-r2.php latest <label>\n";
        echo "  php restore-from-r2.php get <full-key> [outPath]\n";
        exit(1);
}
