<?php
/**
 * feed.php
 *
 * Proxy and filter for ADS-B point feed with caching, rate limiting, and
 * geographic filters (MMFR FIR + Mexico border proximity).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/feed_helpers.php';
requireAuth($config);

$cacheDir = $config['feed_cache_dir'] ?? (__DIR__ . '/data/cache');
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0775, true);
}

function loadStoredSettings(array $config): array
{
    $settings = [
        'feed_center' => [
            'lat' => (float)($config['feed_center']['lat'] ?? $config['airport']['lat']),
            'lon' => (float)($config['feed_center']['lon'] ?? $config['airport']['lon']),
            'radius_nm' => (float)($config['feed_radius_nm'] ?? $config['adsb_radius'] ?? 250),
        ],
        'ui_center' => [
            'lat' => (float)($config['ui_center']['lat'] ?? $config['display_center']['lat'] ?? 32.541),
            'lon' => (float)($config['ui_center']['lon'] ?? $config['display_center']['lon'] ?? -116.97),
        ],
    ];
    $dbPath = $config['settings_db'] ?? null;
    if (!$dbPath || !is_file($dbPath)) {
        return $settings;
    }
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->query('SELECT data FROM settings WHERE id = 1');
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['data']) {
            $decoded = json_decode($row['data'], true);
            if (is_array($decoded)) {
                $feed = $decoded['feed_center'] ?? null;
                $lat = filter_var($feed['lat'] ?? null, FILTER_VALIDATE_FLOAT);
                $lon = filter_var($feed['lon'] ?? null, FILTER_VALIDATE_FLOAT);
                $radius = filter_var($feed['radius_nm'] ?? ($decoded['radius_nm'] ?? null), FILTER_VALIDATE_FLOAT);
                if ($lat !== false && $lat >= -90 && $lat <= 90) {
                    $settings['feed_center']['lat'] = (float)$lat;
                }
                if ($lon !== false && $lon >= -180 && $lon <= 180) {
                    $settings['feed_center']['lon'] = (float)$lon;
                }
                if ($radius !== false && $radius > 0 && $radius <= 250) {
                    $settings['feed_center']['radius_nm'] = (float)$radius;
                }
                $ui = $decoded['ui_center'] ?? $decoded['display_center'] ?? null;
                if (isset($ui) && is_array($ui)) {
                    $dlat = filter_var($ui['lat'] ?? null, FILTER_VALIDATE_FLOAT);
                    $dlon = filter_var($ui['lon'] ?? null, FILTER_VALIDATE_FLOAT);
                    if ($dlat !== false && $dlat >= -90 && $dlat <= 90) {
                        $settings['ui_center']['lat'] = (float)$dlat;
                    }
                    if ($dlon !== false && $dlon >= -180 && $dlon <= 180) {
                        $settings['ui_center']['lon'] = (float)$dlon;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        return $settings;
    }
    return enforceFixedFeedCenter($settings, $config);
}

function enforceFixedFeedCenter(array $settings, array $config): array
{
    $settings['feed_center']['lat'] = (float)($config['feed_center']['lat'] ?? $settings['feed_center']['lat']);
    $settings['feed_center']['lon'] = (float)($config['feed_center']['lon'] ?? $settings['feed_center']['lon']);
    $settings['feed_center']['radius_nm'] = (float)($config['feed_radius_nm'] ?? $settings['feed_center']['radius_nm']);
    return $settings;
}

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function nowMs(): int
{
    return (int)round(microtime(true) * 1000);
}

function apcuAvailable(): bool
{
    if (!function_exists('apcu_fetch')) {
        return false;
    }
    if (function_exists('apcu_enabled')) {
        return apcu_enabled();
    }
    $enabled = ini_get('apc.enabled');
    return $enabled !== '0' && $enabled !== false;
}

function readCacheFile(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    $fp = fopen($path, 'r');
    if (!$fp) {
        return null;
    }
    flock($fp, LOCK_SH);
    $contents = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    if (!$contents) {
        return null;
    }
    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        return null;
    }
    return $decoded;
}

function writeCacheFile(string $path, array $payload): void
{
    $fp = fopen($path, 'c+');
    if (!$fp) {
        return;
    }
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($payload, JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
}

function readCache(string $key, string $cacheFile, int $ttlMs, ?array &$stale, ?int &$ageMs, ?bool &$cacheHit): ?array
{
    $cacheHit = false;
    $ageMs = null;
    $stale = null;
    $cached = null;
    if (apcuAvailable()) {
        $success = false;
        $cached = apcu_fetch($key, $success);
        if (!$success || !is_array($cached)) {
            $cached = null;
        }
    } else {
        $cached = readCacheFile($cacheFile);
    }
    if (!$cached || !isset($cached['stored_ms'])) {
        return null;
    }
    $storedMs = (int)$cached['stored_ms'];
    $ageMs = nowMs() - $storedMs;
    if (isset($cached['payload']) && is_array($cached['payload'])) {
        if ($ageMs <= $ttlMs) {
            $cacheHit = true;
            return $cached['payload'];
        }
        $stale = $cached['payload'];
    }
    return null;
}

function writeCache(
    string $key,
    string $cacheFile,
    array $payload,
    int $coordDecimals = 3,
    int $altThreshold = 100
): void
{
    if (isset($payload['ac']) && is_array($payload['ac'])) {
        $payload['ac'] = dedupeEntries($payload['ac'], $altThreshold, $coordDecimals);
        $payload['total'] = count($payload['ac']);
    }
    $entry = [
        'stored_ms' => nowMs(),
        'payload' => $payload,
    ];
    if (apcuAvailable()) {
        apcu_store($key, $entry, 30);
    } else {
        writeCacheFile($cacheFile, $entry);
    }
}

function allowUpstreamRequest(string $lockFile, float $limitSeconds): bool
{
    $now = microtime(true);
    $fp = fopen($lockFile, 'c+');
    if (!$fp) {
        return true;
    }
    flock($fp, LOCK_EX);
    $contents = stream_get_contents($fp);
    $last = $contents !== '' ? (float)$contents : 0.0;
    if (($now - $last) < $limitSeconds) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string)$now);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function acquireUpstreamSlot(string $lockFile, float $limitSeconds, int $waitMs): bool
{
    if (allowUpstreamRequest($lockFile, $limitSeconds)) {
        return true;
    }
    if ($waitMs > 0) {
        usleep($waitMs * 1000);
    }
    return allowUpstreamRequest($lockFile, $limitSeconds);
}

function loadGeojsonPolygons(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        return [];
    }
    $data = json_decode($contents, true);
    if (!is_array($data)) {
        return [];
    }
    $polygons = [];
    $features = $data['features'] ?? [];
    foreach ($features as $feature) {
        $geometry = $feature['geometry'] ?? null;
        if (!is_array($geometry)) {
            continue;
        }
        $type = $geometry['type'] ?? '';
        $coords = $geometry['coordinates'] ?? [];
        if ($type === 'Polygon') {
            if (isset($coords[0]) && is_array($coords[0])) {
                $polygons[] = $coords[0];
            }
        } elseif ($type === 'MultiPolygon') {
            foreach ($coords as $poly) {
                if (isset($poly[0]) && is_array($poly[0])) {
                    $polygons[] = $poly[0];
                }
            }
        }
    }
    return $polygons;
}

function pointInPolygon(float $lat, float $lon, array $polygon): bool
{
    $inside = false;
    $n = count($polygon);
    if ($n < 3) {
        return false;
    }
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $xi = $polygon[$i][0];
        $yi = $polygon[$i][1];
        $xj = $polygon[$j][0];
        $yj = $polygon[$j][1];
        $intersect = (($yi > $lat) !== ($yj > $lat))
            && ($lon < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
        if ($intersect) {
            $inside = !$inside;
        }
    }
    return $inside;
}

function pointInPolygons(float $lat, float $lon, array $polygons): bool
{
    foreach ($polygons as $polygon) {
        if (pointInPolygon($lat, $lon, $polygon)) {
            return true;
        }
    }
    return false;
}

function distancePointToSegmentMeters(float $lat, float $lon, float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $R = 6371000.0;
    $refLatRad = deg2rad(($lat + $lat1 + $lat2) / 3.0);
    $x = deg2rad($lon) * cos($refLatRad) * $R;
    $y = deg2rad($lat) * $R;
    $x1 = deg2rad($lon1) * cos($refLatRad) * $R;
    $y1 = deg2rad($lat1) * $R;
    $x2 = deg2rad($lon2) * cos($refLatRad) * $R;
    $y2 = deg2rad($lat2) * $R;
    $dx = $x2 - $x1;
    $dy = $y2 - $y1;
    if ($dx == 0.0 && $dy == 0.0) {
        return hypot($x - $x1, $y - $y1);
    }
    $t = (($x - $x1) * $dx + ($y - $y1) * $dy) / ($dx * $dx + $dy * $dy);
    $t = max(0.0, min(1.0, $t));
    $projX = $x1 + $t * $dx;
    $projY = $y1 + $t * $dy;
    return hypot($x - $projX, $y - $projY);
}

function distanceToPolygonsNm(float $lat, float $lon, array $polygons): float
{
    $min = INF;
    foreach ($polygons as $polygon) {
        $points = $polygon;
        $count = count($points);
        if ($count < 2) {
            continue;
        }
        for ($i = 0; $i < $count - 1; $i++) {
            $p1 = $points[$i];
            $p2 = $points[$i + 1];
            $dist = distancePointToSegmentMeters($lat, $lon, (float)$p1[1], (float)$p1[0], (float)$p2[1], (float)$p2[0]);
            if ($dist < $min) {
                $min = $dist;
            }
        }
    }
    if (!is_finite($min)) {
        return INF;
    }
    return $min / 1852.0;
}

function updateLastUpstreamStatus(string $path, ?int $status, ?string $error): void
{
    $payload = [
        'status' => $status,
        'error' => $error,
        'timestamp' => date('c'),
    ];
    writeCacheFile($path, $payload);
}

function fetchUpstream(string $url, array $headers, int $timeoutSeconds = 5): array
{
    $headerLines = $headers;
    $headerLines[] = 'User-Agent: ADSB-ATC-Display';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: null;
        $error = $body === false ? curl_error($ch) : null;
        curl_close($ch);
        return [$body, $status, $error];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'header' => $headerLines ? implode("\r\n", $headerLines) : '',
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $status = null;
    if (isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0])) {
        if (preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $matches)) {
            $status = (int)$matches[1];
        }
    }
    return [$body, $status, $body === false ? 'Upstream request failed' : null];
}

function haversineNm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $R = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    $km = $R * $c;
    return $km / 1.852;
}

$storedSettings = loadStoredSettings($config);
$lat = (float)$storedSettings['feed_center']['lat'];
$lon = (float)$storedSettings['feed_center']['lon'];
$radius = (float)$storedSettings['feed_center']['radius_nm'];

$cacheTtlMs = (int)($config['feed_cache_ttl_ms'] ?? 1500);
$cacheMaxStaleMs = (int)($config['feed_cache_max_stale_ms'] ?? 5000);
$rateLimitS = (float)($config['feed_rate_limit_s'] ?? 1.0);

$cacheKey = 'adsb_feed_' . md5(sprintf('%.4f|%.4f|%.1f', $lat, $lon, $radius));
$cacheFile = $cacheDir . '/' . $cacheKey . '.json';

$stalePayload = null;
$ageMs = null;
$cacheHit = false;
$cached = readCache($cacheKey, $cacheFile, $cacheTtlMs, $stalePayload, $ageMs, $cacheHit);
if ($cached !== null) {
    respond([
        'ok' => true,
        'error' => null,
        'upstream_http' => null,
        'cache_hit' => true,
        'cache_stale' => false,
        'age_ms' => $ageMs ?? 0,
        'now' => date('c'),
        'total' => $cached['total'] ?? count($cached['ac'] ?? []),
        'ac' => $cached['ac'] ?? [],
        'feed_center' => $cached['feed_center'] ?? [
            'lat' => (float)$storedSettings['feed_center']['lat'],
            'lon' => (float)$storedSettings['feed_center']['lon'],
            'radius_nm' => (float)$storedSettings['feed_center']['radius_nm'],
        ],
        'ui_center' => $cached['ui_center'] ?? [
            'lat' => (float)$storedSettings['ui_center']['lat'],
            'lon' => (float)$storedSettings['ui_center']['lon'],
        ],
    ]);
}

$lockFile = $cacheDir . '/upstream.lock';
$canRequestUpstream = acquireUpstreamSlot($lockFile, $rateLimitS, 200);
if (!$canRequestUpstream) {
    $fallbackAc = $stalePayload['ac'] ?? [];
    $fallbackTotal = $stalePayload['total'] ?? count($fallbackAc);
    $staleAge = $ageMs ?? $cacheMaxStaleMs + 1;
    if ($stalePayload && $staleAge <= $cacheMaxStaleMs) {
        respond([
            'ok' => true,
            'error' => 'Upstream rate limit active. Serving cached data.',
            'upstream_http' => null,
            'cache_hit' => true,
            'cache_stale' => true,
            'age_ms' => $ageMs ?? 0,
            'now' => date('c'),
            'total' => $fallbackTotal,
            'ac' => $fallbackAc,
            'feed_center' => $stalePayload['feed_center'] ?? [
                'lat' => (float)$storedSettings['feed_center']['lat'],
                'lon' => (float)$storedSettings['feed_center']['lon'],
                'radius_nm' => (float)$storedSettings['feed_center']['radius_nm'],
            ],
            'ui_center' => $stalePayload['ui_center'] ?? [
                'lat' => (float)$storedSettings['ui_center']['lat'],
                'lon' => (float)$storedSettings['ui_center']['lon'],
            ],
        ], 200);
    }
    respond([
        'ok' => false,
        'error' => 'Upstream rate limit active and cache is stale.',
        'upstream_http' => null,
        'cache_hit' => false,
        'cache_stale' => true,
        'age_ms' => $ageMs ?? 0,
        'now' => date('c'),
        'total' => 0,
        'ac' => [],
        'feed_center' => [
            'lat' => (float)$storedSettings['feed_center']['lat'],
            'lon' => (float)$storedSettings['feed_center']['lon'],
            'radius_nm' => (float)$storedSettings['feed_center']['radius_nm'],
        ],
        'ui_center' => [
            'lat' => (float)$storedSettings['ui_center']['lat'],
            'lon' => (float)$storedSettings['ui_center']['lon'],
        ],
    ], 200);
}

$feedUrl = rtrim($config['adsb_feed_url'], '/') . '/' . $lat . '/' . $lon . '/' . $radius;
$headers = [];
if (!empty($config['adsb_api_key'])) {
    $headerName = $config['adsb_api_header'] ?: 'X-API-Key';
    $headers[] = $headerName . ': ' . $config['adsb_api_key'];
}

$upstreamError = null;
[$response, $upstreamStatus, $upstreamError] = fetchUpstream($feedUrl, $headers, 5);

if ($response === false || ($upstreamStatus !== null && $upstreamStatus >= 400)) {
    $errorMessage = $upstreamError;
    if ($errorMessage === null && $upstreamStatus !== null) {
        $errorMessage = 'Upstream HTTP ' . $upstreamStatus;
    }
    $errorMessage ??= 'Upstream request failed';
    updateLastUpstreamStatus($cacheDir . '/upstream.status.json', $upstreamStatus, $errorMessage);
    $fallbackAc = $stalePayload['ac'] ?? [];
    $fallbackTotal = $stalePayload['total'] ?? count($fallbackAc);
    if ($stalePayload) {
        respond([
            'ok' => true,
            'error' => 'Upstream unavailable. Serving cached data.',
            'upstream_http' => $upstreamStatus,
            'cache_hit' => true,
            'cache_stale' => true,
            'age_ms' => $ageMs ?? 0,
            'now' => date('c'),
            'total' => $fallbackTotal,
            'ac' => $fallbackAc,
            'feed_center' => $stalePayload['feed_center'] ?? [
                'lat' => (float)$storedSettings['feed_center']['lat'],
                'lon' => (float)$storedSettings['feed_center']['lon'],
                'radius_nm' => (float)$storedSettings['feed_center']['radius_nm'],
            ],
            'ui_center' => $stalePayload['ui_center'] ?? [
                'lat' => (float)$storedSettings['ui_center']['lat'],
                'lon' => (float)$storedSettings['ui_center']['lon'],
            ],
        ], 200);
    }
    respond([
        'ok' => false,
        'error' => $errorMessage,
        'upstream_http' => $upstreamStatus,
        'cache_hit' => false,
        'cache_stale' => false,
        'age_ms' => $ageMs ?? 0,
        'now' => date('c'),
        'total' => 0,
        'ac' => [],
        'feed_center' => [
            'lat' => (float)$storedSettings['feed_center']['lat'],
            'lon' => (float)$storedSettings['feed_center']['lon'],
            'radius_nm' => (float)$storedSettings['feed_center']['radius_nm'],
        ],
        'ui_center' => [
            'lat' => (float)$storedSettings['ui_center']['lat'],
            'lon' => (float)$storedSettings['ui_center']['lon'],
        ],
    ], 200);
}

$data = json_decode($response, true);
if (!is_array($data) || !isset($data['ac']) || !is_array($data['ac'])) {
    updateLastUpstreamStatus($cacheDir . '/upstream.status.json', $upstreamStatus, 'Invalid upstream JSON');
    respond([
        'ok' => false,
        'error' => 'Invalid ADS-B response.',
        'upstream_http' => $upstreamStatus,
        'cache_hit' => false,
        'age_ms' => 0,
        'now' => time(),
        'total' => 0,
        'ac' => [],
        'feed_center' => [
            'lat' => (float)$storedSettings['feed_center']['lat'],
            'lon' => (float)$storedSettings['feed_center']['lon'],
            'radius_nm' => (float)$storedSettings['feed_center']['radius_nm'],
        ],
        'ui_center' => [
            'lat' => (float)$storedSettings['ui_center']['lat'],
            'lon' => (float)$storedSettings['ui_center']['lon'],
        ],
    ], 200);
}

updateLastUpstreamStatus($cacheDir . '/upstream.status.json', $upstreamStatus, null);

$borderFilterEnabled = (bool)($config['mex_border_filter_enabled'] ?? false);
$mexBorderBufferNm = (float)($config['mex_border_buffer_nm'] ?? 10.0);
$mexPolygons = $borderFilterEnabled ? loadGeojsonPolygons(__DIR__ . '/data/mex-border.geojson') : [];

$filteredByHex = [];
$unfilteredByHex = [];
$coordDecimals = (int)($config['coordinate_round_decimals'] ?? 3);
$altThreshold = (int)($config['altitude_change_threshold_ft'] ?? 100);
$cacheCleanupThreshold = (float)($config['cache_cleanup_threshold'] ?? 300.0);
$filterLogger = static function (string $message): void {
    error_log($message);
};
$feedLat = (float)$lat;
$feedLon = (float)$lon;
$uiLat = (float)$storedSettings['ui_center']['lat'];
$uiLon = (float)$storedSettings['ui_center']['lon'];
$borderLat = (float)($config['border_lat'] ?? 0.0);
$northBufferNm = (float)($config['north_buffer_nm'] ?? 10.0);
foreach ($data['ac'] as $ac) {
    if (!isset($ac['lat'], $ac['lon'])) {
        continue;
    }
    $acLat = (float)$ac['lat'];
    $acLon = (float)$ac['lon'];
    $hex = strtoupper(trim((string)($ac['hex'] ?? '')));
    if ($hex === '') {
        continue;
    }
    $feedDistanceNm = haversineNm($acLat, $acLon, $feedLat, $feedLon);
    if ($feedDistanceNm > $radius) {
        continue;
    }
    $flight = strtoupper(trim((string)($ac['flight'] ?? '')));
    $flight = $flight !== '' ? $flight : null;
    $alt = $ac['alt_baro'] ?? $ac['alt_geom'] ?? null;
    $alt = is_numeric($alt) ? (int)$alt : null;
    $gs = $ac['gs'] ?? null;
    $gs = is_numeric($gs) ? (int)round($gs) : null;
    $track = $ac['track'] ?? null;
    $track = is_numeric($track) ? (int)round($track) : null;
    $baroRate = $ac['baro_rate'] ?? null;
    $geomRate = $ac['geom_rate'] ?? null;
    $squawk = strtoupper(trim((string)($ac['squawk'] ?? '')));
    $squawk = $squawk !== '' ? $squawk : null;
    $emergency = $ac['emergency'] ?? null;
    if (is_string($emergency)) {
        $emergency = strtolower(trim($emergency));
        if ($emergency === '' || $emergency === 'none') {
            $emergency = null;
        }
    }
    $displayDistance = haversineNm($acLat, $acLon, $uiLat, $uiLon);
    $distanceRounded = round($displayDistance, 1);
    $feedDistanceRounded = round($feedDistanceNm, 1);

    $entry = [
        'hex' => $hex,
        'flight' => $flight,
        'reg' => isset($ac['r']) && trim((string)$ac['r']) !== '' ? strtoupper(trim((string)$ac['r'])) : null,
        'type' => isset($ac['t']) && trim((string)$ac['t']) !== '' ? strtoupper(trim((string)$ac['t'])) : null,
        'lat' => $acLat,
        'lon' => $acLon,
        'alt' => $alt,
        'gs' => $gs,
        'track' => $track,
        'squawk' => $squawk,
        'emergency' => $emergency,
        'baro_rate' => is_numeric($baroRate) ? (int)$baroRate : null,
        'geom_rate' => is_numeric($geomRate) ? (int)$geomRate : null,
        'seen_pos' => is_numeric($ac['seen_pos'] ?? null) ? (float)$ac['seen_pos'] : null,
        'dst' => $distanceRounded,
        'dir' => is_numeric($ac['dir'] ?? null) ? (float)$ac['dir'] : null,
        'distance_nm' => $distanceRounded,
        'distance_ui_nm' => $distanceRounded,
        'distance_feed_nm' => $feedDistanceRounded,
    ];
    if (!isset($unfilteredByHex[$hex])) {
        $unfilteredByHex[$hex] = $entry;
    } else {
        if (shouldReplaceEntry($unfilteredByHex[$hex], $entry, $altThreshold, $coordDecimals)) {
            $unfilteredByHex[$hex] = $entry;
        }
    }

    if ($borderFilterEnabled) {
        if ($mexPolygons) {
            $insideMex = pointInPolygons($acLat, $acLon, $mexPolygons);
            if (!$insideMex) {
                $distNm = distanceToPolygonsNm($acLat, $acLon, $mexPolygons);
                if ($distNm > $mexBorderBufferNm) {
                    logFilterDiscard($filterLogger, $entry, 'mex_border_distance');
                    continue;
                }
            }
        } elseif ($borderLat > 0.0) {
            $northLimit = $borderLat + ($northBufferNm / 60.0);
            if ($acLat > $northLimit) {
                logFilterDiscard($filterLogger, $entry, 'north_border_limit');
                continue;
            }
        }
    }

    if ($borderFilterEnabled) {
        if (!isset($filteredByHex[$hex])) {
            $filteredByHex[$hex] = $entry;
            continue;
        }
        if (shouldReplaceEntry($filteredByHex[$hex], $entry, $altThreshold, $coordDecimals)) {
            $filteredByHex[$hex] = $entry;
        }
    }
}

$removedUnfiltered = cleanupStaleEntries($unfilteredByHex, $cacheCleanupThreshold);
$removedFiltered = $borderFilterEnabled ? cleanupStaleEntries($filteredByHex, $cacheCleanupThreshold) : 0;
if ($removedUnfiltered > 0 || $removedFiltered > 0) {
    $filterLogger(sprintf(
        'cache_cleanup removed_unfiltered=%d removed_filtered=%d threshold=%s',
        $removedUnfiltered,
        $removedFiltered,
        $cacheCleanupThreshold
    ));
}

$filtered = array_values($borderFilterEnabled ? $filteredByHex : $unfilteredByHex);

usort($filtered, function (array $a, array $b): int {
    return ($a['distance_nm'] ?? 0) <=> ($b['distance_nm'] ?? 0);
});

$payload = [
    'ac' => $filtered,
    'total' => count($filtered),
    'feed_center' => [
        'lat' => (float)$storedSettings['feed_center']['lat'],
        'lon' => (float)$storedSettings['feed_center']['lon'],
        'radius_nm' => (float)$storedSettings['feed_center']['radius_nm'],
    ],
    'ui_center' => [
        'lat' => (float)$storedSettings['ui_center']['lat'],
        'lon' => (float)$storedSettings['ui_center']['lon'],
    ],
];
writeCache($cacheKey, $cacheFile, $payload, $coordDecimals, $altThreshold);

respond([
    'ok' => true,
    'error' => null,
    'upstream_http' => $upstreamStatus,
    'cache_hit' => false,
    'cache_stale' => false,
    'age_ms' => 0,
    'now' => date('c'),
    'total' => $payload['total'],
    'ac' => $payload['ac'],
]);
