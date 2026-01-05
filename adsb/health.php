<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
requireAuth($config);

$expectedFeed = [
    'lat' => '29.8839810',
    'lon' => '-114.0747826',
    'radius_nm' => 250,
];
$actualFeed = [
    'lat' => number_format((float)($config['feed_center']['lat'] ?? $config['airport']['lat'] ?? 0), 7, '.', ''),
    'lon' => number_format((float)($config['feed_center']['lon'] ?? $config['airport']['lon'] ?? 0), 7, '.', ''),
    'radius_nm' => (int)($config['feed_radius_nm'] ?? $config['adsb_radius'] ?? 0),
];
$feedCenterFixedOk = $actualFeed['lat'] === $expectedFeed['lat']
    && $actualFeed['lon'] === $expectedFeed['lon']
    && $actualFeed['radius_nm'] === $expectedFeed['radius_nm'];
$feedCenterWarning = $feedCenterFixedOk
    ? null
    : 'FEED_CENTER mismatch: expected 29.8839810/-114.0747826 radius 250 NM.';

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

$status = 'ok';

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
$latestCacheMtime = null;
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
        $latestCacheMtime = date('c', $latestMtime);
    }
}
$cacheTtlMs = (int)($config['feed_cache_ttl_ms'] ?? 1500);
$cacheMaxStaleMs = (int)($config['feed_cache_max_stale_ms'] ?? 5000);
$cacheStale = null;
if ($latestCacheAge !== null) {
    $cacheStale = ($latestCacheAge * 1000) > $cacheMaxStaleMs;
}
$warnings = [];
if ($cacheStale === true) {
    $warnings[] = 'Feed cache is stale or not updating.';
}
if ($latestCacheAge === null) {
    $warnings[] = 'Feed cache has not been created yet.';
}
$allowUrlFopen = ini_get('allow_url_fopen');
$allowUrlFopen = $allowUrlFopen !== false && $allowUrlFopen !== '' && $allowUrlFopen !== '0';
$curlAvailable = function_exists('curl_init');

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
    'feed_center' => [
        'expected' => $expectedFeed,
        'actual' => $actualFeed,
        'fixed_ok' => $feedCenterFixedOk,
        'warning' => $feedCenterWarning,
    ],
    'feed' => [
        'upstream' => $upstreamStatus,
        'latest_cache_age_s' => $latestCacheAge,
        'latest_cache_time' => $latestCacheMtime,
        'cache_stale' => $cacheStale,
        'cache_ttl_ms' => $cacheTtlMs,
        'cache_max_stale_ms' => $cacheMaxStaleMs,
        'cache_dir' => $cacheDir,
        'cache_entries' => count($cacheFiles),
    ],
    'http_fetch' => [
        'allow_url_fopen' => $allowUrlFopen,
        'curl_available' => $curlAvailable,
    ],
    'airac' => [
        'last_update' => $airacStatus,
        'recent_runs' => $airacRecent,
        'log_path' => is_file($airacLogPath) ? $airacLogPath : null,
    ],
    'warnings' => $warnings,
    'now' => date('c'),
], JSON_UNESCAPED_SLASHES);
