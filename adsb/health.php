<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
requireAuth($config);

$base = '/' . trim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($base === '/') {
    $base = '/';
} else {
    $base .= '/';
}
$apiBase = $base === '/' ? '/api/' : $base . 'api/';

$dataDir = __DIR__ . '/data';
$cacheDir = $config['feed_cache_dir'] ?? ($dataDir . '/cache');
$dbPath = $config['settings_db'] ?? ($dataDir . '/adsb.sqlite');

$sqliteAvailable = extension_loaded('sqlite3');
$dataDirWritable = is_dir($dataDir) && is_writable($dataDir);
$cacheDirWritable = is_dir($cacheDir) && is_writable($cacheDir);
$sqliteWritable = false;
if (is_file($dbPath)) {
    $sqliteWritable = is_writable($dbPath);
} else {
    $dbDir = dirname($dbPath);
    $sqliteWritable = is_dir($dbDir) && is_writable($dbDir);
}

$status = ($sqliteAvailable && $dataDirWritable && $cacheDirWritable && $sqliteWritable) ? 'ok' : 'degraded';

$upstreamStatusPath = $cacheDir . '/upstream.status.json';
$upstreamStatus = null;
if (is_file($upstreamStatusPath)) {
    $contents = file_get_contents($upstreamStatusPath);
    $decoded = $contents ? json_decode($contents, true) : null;
    if (is_array($decoded)) {
        $upstreamStatus = $decoded;
    }
}

$cacheFiles = glob($cacheDir . '/adsb_feed_*.json') ?: [];
$latestCacheAge = null;
if ($cacheFiles) {
    $latestMtime = 0;
    foreach ($cacheFiles as $file) {
        $mtime = filemtime($file);
        if ($mtime && $mtime > $latestMtime) {
            $latestMtime = $mtime;
        }
    }
    if ($latestMtime > 0) {
        $latestCacheAge = time() - $latestMtime;
    }
}

$airacLogPath = $dataDir . '/airac_update.log';
$airacStatus = null;
$airacRecent = [];
if (is_file($airacLogPath)) {
    $lines = file($airacLogPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) {
        $lastLine = $lines[count($lines) - 1];
        $decoded = json_decode($lastLine, true);
        if (is_array($decoded)) {
            $airacStatus = $decoded;
        }
        $recentLines = array_slice($lines, -5);
        foreach ($recentLines as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $airacRecent[] = [
                    'ok' => $entry['ok'] ?? null,
                    'exit_code' => $entry['exit_code'] ?? null,
                    'started_at' => $entry['started_at'] ?? null,
                    'finished_at' => $entry['finished_at'] ?? null,
                ];
            }
        }
    }
}

echo json_encode([
    'status' => $status,
    'app_base' => $base,
    'api_base' => $apiBase,
    'php_version' => PHP_VERSION,
    'sqlite_available' => $sqliteAvailable,
    'writable' => [
        'data_dir' => $dataDirWritable,
        'cache_dir' => $cacheDirWritable,
        'sqlite_file' => $sqliteWritable,
    ],
    'feed' => [
        'upstream' => $upstreamStatus,
        'latest_cache_age_s' => $latestCacheAge,
        'cache_dir' => $cacheDir,
        'cache_entries' => count($cacheFiles),
    ],
    'airac' => [
        'last_update' => $airacStatus,
        'recent_runs' => $airacRecent,
        'log_path' => is_file($airacLogPath) ? $airacLogPath : null,
    ],
    'now' => date('c'),
], JSON_UNESCAPED_SLASHES);
