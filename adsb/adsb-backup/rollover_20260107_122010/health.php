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
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $scheme . '://' . $host . $base;

function functionAvailable(string $name): bool
{
    if (!function_exists($name)) {
        return false;
    }
    $disabled = ini_get('disable_functions');
    if ($disabled === false || $disabled === '') {
        return true;
    }
    $disabledList = array_map('trim', explode(',', $disabled));
    return !in_array($name, $disabledList, true);
}

$gitPath = null;
if (functionAvailable('shell_exec')) {
    $gitPath = trim((string)shell_exec('command -v git 2>/dev/null')) ?: null;
}

$expectedFeed = [
    'lat' => '29.0099590',
    'lon' => '-114.5552580',
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
    : 'FEED_CENTER mismatch: expected 29.0099590/-114.5552580 radius 250 NM.';

$apiBase = $base === '/' ? '/api/' : $base . 'api/';

$dataDir = __DIR__ . '/data';
$cacheDir = $config['feed_cache_dir'] ?? ($dataDir . '/cache');
$dbPath = $config['settings_db'] ?? ($dataDir . '/adsb.sqlite');
$geojsonDir = $config['geojson_dir'] ?? $dataDir;

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
} // [MXAIR2026-ROLL]

$aggregateCacheFile = $cacheDir . '/feed_aggregate.json'; // [MXAIR2026-ROLL]
$aggregatePayload = null; // [MXAIR2026-ROLL]
$aggregateStoredMs = null; // [MXAIR2026-ROLL]
if (is_file($aggregateCacheFile)) { // [MXAIR2026-ROLL]
    $contents = file_get_contents($aggregateCacheFile); // [MXAIR2026-ROLL]
    $decoded = $contents ? json_decode($contents, true) : null; // [MXAIR2026-ROLL]
    if (is_array($decoded)) { // [MXAIR2026-ROLL]
        $aggregatePayload = $decoded['payload'] ?? null; // [MXAIR2026-ROLL]
        $aggregateStoredMs = isset($decoded['stored_ms']) ? (int)$decoded['stored_ms'] : null; // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]
} // [MXAIR2026-ROLL]

$aggregateAgeMs = $aggregateStoredMs !== null ? max(0, (int)round(microtime(true) * 1000) - $aggregateStoredMs) : null; // [MXAIR2026-ROLL]
$aggregateAgeS = $aggregateAgeMs !== null ? $aggregateAgeMs / 1000 : null; // [MXAIR2026-ROLL]
$aggregateTtlS = (int)($config['feed_aggregate_ttl_s'] ?? 90); // [MXAIR2026-ROLL]
$aggregateTtlMs = $aggregateTtlS * 1000; // [MXAIR2026-ROLL]
$aggregateOk = false; // [MXAIR2026-ROLL]
if (is_array($aggregatePayload)) { // [MXAIR2026-ROLL]
    $aggregateTotal = (int)($aggregatePayload['total'] ?? 0); // [MXAIR2026-ROLL]
    $aggregateOk = $aggregateTotal > 0 && $aggregateAgeMs !== null && $aggregateAgeMs <= $aggregateTtlMs; // [MXAIR2026-ROLL]
} // [MXAIR2026-ROLL]

$centerStatusFiles = glob($cacheDir . '/adsb_feed_center_*.json') ?: []; // [MXAIR2026-ROLL]
$centerStatuses = []; // [MXAIR2026-ROLL]
foreach ($centerStatusFiles as $file) { // [MXAIR2026-ROLL]
    $contents = file_get_contents($file); // [MXAIR2026-ROLL]
    $decoded = $contents ? json_decode($contents, true) : null; // [MXAIR2026-ROLL]
    if (!is_array($decoded)) { // [MXAIR2026-ROLL]
        continue; // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]
    $center = $decoded['center'] ?? ($decoded['payload']['feed_center'] ?? null); // [MXAIR2026-ROLL]
    $centerName = null; // [MXAIR2026-ROLL]
    if (is_array($center)) { // [MXAIR2026-ROLL]
        $centerName = $center['name'] ?? null; // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]
    $centerStatuses[] = [ // [MXAIR2026-ROLL]
        'name' => $centerName, // [MXAIR2026-ROLL]
        'upstream_status' => $decoded['center_upstream_status'] ?? ($decoded['upstream_http'] ?? null), // [MXAIR2026-ROLL]
        'center_last_ok_at' => $decoded['center_last_ok_at'] ?? null, // [MXAIR2026-ROLL]
        'center_last_attempt_at' => $decoded['center_last_attempt_at'] ?? null, // [MXAIR2026-ROLL]
        'error' => $decoded['error'] ?? null, // [MXAIR2026-ROLL]
    ]; // [MXAIR2026-ROLL]
} // [MXAIR2026-ROLL]

$cacheTtlMs = (int)($config['feed_aggregate_cache_ttl_ms'] ?? 2500); // [MXAIR2026-ROLL]
$cacheMaxStaleMs = $aggregateTtlMs; // [MXAIR2026-ROLL]
$cacheStale = $aggregateAgeMs !== null ? $aggregateAgeMs > $cacheMaxStaleMs : null; // [MXAIR2026-ROLL]
$latestCacheAge = $aggregateAgeS; // [MXAIR2026-ROLL]
$latestCacheMtime = $aggregateStoredMs !== null ? gmdate('c', (int)floor($aggregateStoredMs / 1000)) : null; // [MXAIR2026-ROLL]
$warnings = []; // [MXAIR2026-ROLL]
if ($cacheStale === true) { // [MXAIR2026-ROLL]
    $warnings[] = 'Feed aggregate is stale or not updating.'; // [MXAIR2026-ROLL]
} // [MXAIR2026-ROLL]
if ($aggregateStoredMs === null) { // [MXAIR2026-ROLL]
    $warnings[] = 'Feed aggregate cache has not been created yet.'; // [MXAIR2026-ROLL]
} // [MXAIR2026-ROLL]
$allowUrlFopen = ini_get('allow_url_fopen');
$allowUrlFopen = $allowUrlFopen !== false && $allowUrlFopen !== '' && $allowUrlFopen !== '0';
$curlAvailable = function_exists('curl_init');
if (function_exists('curl_version')) {
    $curlAvailable = true;
}

$vatmexRepo = $config['vatmex_repo_dir'] ?? $config['vatmex_dir'] ?? null;
$vatmexAirac = $config['vatmex_airac_dir'] ?? null;
$airacCycle = $config['last_airac_cycle'] ?? null;
$airacTokenConfigured = !empty($config['airac_update_token']);
$airacAllowlist = $config['airac_update_ip_allowlist'] ?? [];
// Validate VATMEX/AIRAC paths. // [MXAIR2026-ROLL]
$vatmexRepoOk = is_string($vatmexRepo) && $vatmexRepo !== '' && is_dir($vatmexRepo); // [MXAIR2026-ROLL]
$vatmexAiracOk = is_string($vatmexAirac) && $vatmexAirac !== '' && is_dir($vatmexAirac); // [MXAIR2026-ROLL]
$vatmexMarkerOk = false; // [MXAIR2026-ROLL]
$vatmexMarkerPath = null; // [MXAIR2026-ROLL]
$vatmexAiracMarkerOk = false; // [MXAIR2026-ROLL]
$vatmexAiracMarkerPath = null; // [MXAIR2026-ROLL]
if ($vatmexRepoOk) { // [MXAIR2026-ROLL]
    $markerCandidates = [ // [MXAIR2026-ROLL]
        $vatmexRepo . '/README.md', // [MXAIR2026-ROLL]
        $vatmexRepo . '/README', // [MXAIR2026-ROLL]
    ]; // [MXAIR2026-ROLL]
    foreach ($markerCandidates as $candidate) { // [MXAIR2026-ROLL]
        if (is_file($candidate) && is_readable($candidate)) { // [MXAIR2026-ROLL]
            $contents = file_get_contents($candidate); // [MXAIR2026-ROLL]
            if ($contents !== false) { // [MXAIR2026-ROLL]
                $vatmexMarkerOk = true; // [MXAIR2026-ROLL]
                $vatmexMarkerPath = $candidate; // [MXAIR2026-ROLL]
                break; // [MXAIR2026-ROLL]
            }
        }
    }
} // [MXAIR2026-ROLL]
if ($vatmexAiracOk) { // [MXAIR2026-ROLL]
    $airacCandidates = [ // [MXAIR2026-ROLL]
        $vatmexAirac . '/README.md', // [MXAIR2026-ROLL]
        $vatmexAirac . '/README', // [MXAIR2026-ROLL]
    ]; // [MXAIR2026-ROLL]
    foreach ($airacCandidates as $candidate) { // [MXAIR2026-ROLL]
        if (is_file($candidate) && is_readable($candidate)) { // [MXAIR2026-ROLL]
            $contents = file_get_contents($candidate); // [MXAIR2026-ROLL]
            if ($contents !== false) { // [MXAIR2026-ROLL]
                $vatmexAiracMarkerOk = true; // [MXAIR2026-ROLL]
                $vatmexAiracMarkerPath = $candidate; // [MXAIR2026-ROLL]
                break; // [MXAIR2026-ROLL]
            }
        }
    }
    if (!$vatmexAiracMarkerOk) { // [MXAIR2026-ROLL]
        $xmlCandidates = glob($vatmexAirac . '/*.xml') ?: []; // [MXAIR2026-ROLL]
        if ($xmlCandidates) { // [MXAIR2026-ROLL]
            $vatmexAiracMarkerOk = true; // [MXAIR2026-ROLL]
            $vatmexAiracMarkerPath = $xmlCandidates[0]; // [MXAIR2026-ROLL]
        }
    }
} // [MXAIR2026-ROLL]
if (!$vatmexRepoOk) { // [MXAIR2026-ROLL]
    $warnings[] = 'VATMEX repo path missing or unreadable.'; // [MXAIR2026-ROLL]
} elseif (!$vatmexMarkerOk) { // [MXAIR2026-ROLL]
    $warnings[] = 'VATMEX repo marker missing (README).'; // [MXAIR2026-ROLL]
}
if (!$vatmexAiracOk) { // [MXAIR2026-ROLL]
    $warnings[] = 'VATMEX AIRAC path missing or unreadable.'; // [MXAIR2026-ROLL]
} elseif (!$vatmexAiracMarkerOk) { // [MXAIR2026-ROLL]
    $warnings[] = 'VATMEX AIRAC marker missing (README/XML).'; // [MXAIR2026-ROLL]
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
    'base_url' => $baseUrl,
    'script_name' => $_SERVER['SCRIPT_NAME'] ?? null,
    'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
    'php_version' => PHP_VERSION,
    'sqlite_available' => $sqliteAvailable,
    'writable' => [
        'data_dir' => $dataDirWritable,
        'cache_dir' => $cacheDirWritable,
        'sqlite_file' => $sqliteWritable,
    ],
    'endpoints' => [
        'feed_exists' => is_file(__DIR__ . '/feed.php'),
        'geojson_dir' => $geojsonDir,
        'geojson_layers' => is_dir($geojsonDir) ? count(glob($geojsonDir . '/*.geojson') ?: []) : 0,
        'leaflet_local_js' => is_file(__DIR__ . '/assets/vendor/leaflet/leaflet.js'),
        'leaflet_local_css' => is_file(__DIR__ . '/assets/vendor/leaflet/leaflet.css'),
    ],
    'feed_center' => [
        'expected' => $expectedFeed,
        'actual' => $actualFeed,
        'fixed_ok' => $feedCenterFixedOk,
        'warning' => $feedCenterWarning,
    ],
    'feed' => [
        'upstream' => $upstreamStatus,
        'upstream_last_status_per_center' => $centerStatuses, // [MXAIR2026-ROLL]
        'aggregate_ok' => $aggregateOk, // [MXAIR2026-ROLL]
        'aggregate_age_s' => $aggregateAgeS, // [MXAIR2026-ROLL]
        'latest_cache_age_s' => $latestCacheAge,
        'latest_cache_time' => $latestCacheMtime,
        'cache_stale' => $cacheStale,
        'cache_ttl_ms' => $cacheTtlMs,
        'cache_max_stale_ms' => $cacheMaxStaleMs,
        'cache_dir' => $cacheDir,
        'cache_entries' => count($centerStatusFiles), // [MXAIR2026-ROLL]
    ],
    'http_fetch' => [
        'allow_url_fopen' => $allowUrlFopen,
        'curl_available' => $curlAvailable,
    ],
    'airac' => [
        'last_update' => $airacStatus,
        'recent_runs' => $airacRecent,
        'log_path' => is_file($airacLogPath) ? $airacLogPath : null,
        'update_enabled' => (bool)($config['airac_update_enabled'] ?? false),
        'vatmex_repo_dir' => $vatmexRepo,
        'vatmex_airac_dir' => $vatmexAirac,
        'vatmex_repo_ok' => $vatmexRepoOk, // [MXAIR2026-ROLL]
        'vatmex_airac_ok' => $vatmexAiracOk, // [MXAIR2026-ROLL]
        'vatmex_marker_ok' => $vatmexMarkerOk, // [MXAIR2026-ROLL]
        'vatmex_marker_path' => $vatmexMarkerPath, // [MXAIR2026-ROLL]
        'vatmex_airac_marker_ok' => $vatmexAiracMarkerOk, // [MXAIR2026-ROLL]
        'vatmex_airac_marker_path' => $vatmexAiracMarkerPath, // [MXAIR2026-ROLL]
        'airac_cycle' => $airacCycle,
        'admin_token_configured' => $airacTokenConfigured,
        'ip_allowlist' => $airacAllowlist,
    ],
    'exec' => [
        'proc_open' => functionAvailable('proc_open'),
        'exec' => functionAvailable('exec'),
        'shell_exec' => functionAvailable('shell_exec'),
        'php_binary' => PHP_BINARY,
        'git_path' => $gitPath,
    ],
    'warnings' => $warnings,
    'now' => date('c'),
], JSON_UNESCAPED_SLASHES);
