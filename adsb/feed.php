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

const FEED_SCHEMA_VERSION = 3; // [MXAIR2026-ROLL]

function loadStoredSettings(array $config): array
{
    $settings = [
        'feed_center' => [ // [MXAIR2026]
            'lat' => (float)($config['feed_center']['lat'] ?? $config['airport']['lat']), // [MXAIR2026]
            'lon' => (float)($config['feed_center']['lon'] ?? $config['airport']['lon']), // [MXAIR2026]
            'radius_nm' => (float)($config['feed_radius_nm'] ?? $config['adsb_radius'] ?? 250), // [MXAIR2026]
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
    return enforceFixedFeedCenter($settings, $config); // [MXAIR2026]
}

function enforceFixedFeedCenter(array $settings, array $config): array
{
    $settings['feed_center']['lat'] = (float)($config['feed_center']['lat'] ?? $settings['feed_center']['lat']); // [MXAIR2026]
    $settings['feed_center']['lon'] = (float)($config['feed_center']['lon'] ?? $settings['feed_center']['lon']); // [MXAIR2026]
    $settings['feed_center']['radius_nm'] = (float)($config['feed_radius_nm'] ?? $settings['feed_center']['radius_nm']); // [MXAIR2026]
    return $settings; // [MXAIR2026]
}

function loadFeedCenters(array $config, array $storedSettings): array
{
    $centers = $config['feed_centers'] ?? null; // [MXAIR2026]
    $normalized = [];
    if (is_array($centers)) {
        foreach ($centers as $center) {
            if (!is_array($center)) {
                continue;
            }
            $lat = filter_var($center['lat'] ?? null, FILTER_VALIDATE_FLOAT);
            $lon = filter_var($center['lon'] ?? null, FILTER_VALIDATE_FLOAT);
            $radius = filter_var($center['radius_nm'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($lat === false || $lon === false || $radius === false) {
                continue;
            }
            $normalized[] = [
                'name' => $center['name'] ?? null,
                'lat' => (float)$lat,
                'lon' => (float)$lon,
                'radius_nm' => (float)$radius,
            ];
        }
    }
    if (!$normalized) {
        $normalized[] = [
            'name' => 'default',
            'lat' => (float)$storedSettings['feed_center']['lat'],
            'lon' => (float)$storedSettings['feed_center']['lon'],
            'radius_nm' => (float)$storedSettings['feed_center']['radius_nm'],
        ];
    }
    return $normalized; // [MXAIR2026]
}

function nowMs(): int
{
    return (int)round(microtime(true) * 1000);
}

function respond(array $payload, int $status = 200): void
{
    $payload['schema_version'] = FEED_SCHEMA_VERSION; // [MXAIR2026-ROLL]
    $payload['generated_at'] = gmdate('c'); // [MXAIR2026-ROLL]
    $payload['generated_at_ms'] = nowMs(); // [MXAIR2026-ROLL]
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
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

function buildEntryId(array $ac, float $lat, float $lon): ?string
{
    $hex = strtoupper(trim((string)($ac['hex'] ?? $ac['hexid'] ?? '')));
    if ($hex !== '') {
        return $hex;
    }
    $icao = strtoupper(trim((string)($ac['icao24'] ?? $ac['addr'] ?? $ac['hexid'] ?? '')));
    $flight = strtoupper(trim((string)($ac['flight'] ?? $ac['callsign'] ?? '')));
    if ($icao !== '' && $flight !== '') {
        return $icao . '-' . $flight;
    }
    if ($icao !== '') {
        return $icao;
    }
    if ($flight !== '') {
        return $flight;
    }
    if (is_finite($lat) && is_finite($lon)) {
        return sprintf('POS-%.4f-%.4f', $lat, $lon);
    }
    return null;
}

function centerCacheKey(array $center): string
{ // [MXAIR2026-ROLL]
    $payload = [ // [MXAIR2026-ROLL]
        'name' => (string)($center['name'] ?? ''), // [MXAIR2026-ROLL]
        'lat' => (float)($center['lat'] ?? 0), // [MXAIR2026-ROLL]
        'lon' => (float)($center['lon'] ?? 0), // [MXAIR2026-ROLL]
        'radius_nm' => (float)($center['radius_nm'] ?? 0), // [MXAIR2026-ROLL]
    ]; // [MXAIR2026-ROLL]
    return 'adsb_feed_center_' . md5(json_encode($payload, JSON_UNESCAPED_SLASHES)); // [MXAIR2026-ROLL]
}

function loadCenterCache(string $path): ?array
{ // [MXAIR2026-ROLL]
    $cached = readCacheFile($path); // [MXAIR2026-ROLL]
    if (!$cached || !is_array($cached)) { // [MXAIR2026-ROLL]
        return null; // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]
    return $cached; // [MXAIR2026-ROLL]
}

function writeCenterCache(string $path, array $payload): void
{ // [MXAIR2026-ROLL]
    writeCacheFile($path, $payload); // [MXAIR2026-ROLL]
}

function loadRoundRobinState(string $path, int $total): array
{ // [MXAIR2026-ROLL]
    $state = readCacheFile($path); // [MXAIR2026-ROLL]
    $index = 0; // [MXAIR2026-ROLL]
    if (is_array($state) && isset($state['index'])) { // [MXAIR2026-ROLL]
        $index = (int)$state['index']; // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]
    if ($total > 0) { // [MXAIR2026-ROLL]
        $index = ($index % $total + $total) % $total; // [MXAIR2026-ROLL]
    } else { // [MXAIR2026-ROLL]
        $index = 0; // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]
    return [ // [MXAIR2026-ROLL]
        'index' => $index, // [MXAIR2026-ROLL]
        'updated_ms' => (int)($state['updated_ms'] ?? 0), // [MXAIR2026-ROLL]
    ]; // [MXAIR2026-ROLL]
}

function saveRoundRobinState(string $path, int $index): void
{ // [MXAIR2026-ROLL]
    writeCacheFile($path, [ // [MXAIR2026-ROLL]
        'index' => $index, // [MXAIR2026-ROLL]
        'updated_ms' => nowMs(), // [MXAIR2026-ROLL]
    ]); // [MXAIR2026-ROLL]
}

function selectCentersToRefresh(array $centers, int $maxPerRequest, bool $roundRobinEnabled, string $statePath): array
{ // [MXAIR2026-ROLL]
    $total = count($centers); // [MXAIR2026-ROLL]
    if ($total === 0) { // [MXAIR2026-ROLL]
        return []; // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]
    $max = max(1, min($maxPerRequest, $total)); // [MXAIR2026-ROLL]
    if (!$roundRobinEnabled) { // [MXAIR2026-ROLL]
        return range(0, $max - 1); // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]
    $state = loadRoundRobinState($statePath, $total); // [MXAIR2026-ROLL]
    $start = $state['index']; // [MXAIR2026-ROLL]
    $indices = []; // [MXAIR2026-ROLL]
    for ($i = 0; $i < $max; $i++) { // [MXAIR2026-ROLL]
        $indices[] = ($start + $i) % $total; // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]
    $nextIndex = ($start + $max) % $total; // [MXAIR2026-ROLL]
    saveRoundRobinState($statePath, $nextIndex); // [MXAIR2026-ROLL]
    return $indices; // [MXAIR2026-ROLL]
}

function isoTimeFromMs(int $ms): string
{ // [MXAIR2026-ROLL]
    return gmdate('c', (int)floor($ms / 1000)); // [MXAIR2026-ROLL]
}

function compareAircraftCandidate(array $current, array $candidate): bool
{ // [MXAIR2026-ROLL]
    $currentSeen = $current['meta_seen_pos'] ?? null; // [MXAIR2026-ROLL]
    $candidateSeen = $candidate['meta_seen_pos'] ?? null; // [MXAIR2026-ROLL]
    if (is_numeric($candidateSeen) && is_numeric($currentSeen)) { // [MXAIR2026-ROLL]
        if ((float)$candidateSeen !== (float)$currentSeen) { // [MXAIR2026-ROLL]
            return (float)$candidateSeen < (float)$currentSeen; // [MXAIR2026-ROLL]
        } // [MXAIR2026-ROLL]
    } elseif (is_numeric($candidateSeen) && !is_numeric($currentSeen)) { // [MXAIR2026-ROLL]
        return true; // [MXAIR2026-ROLL]
    } elseif (!is_numeric($candidateSeen) && is_numeric($currentSeen)) { // [MXAIR2026-ROLL]
        return false; // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]
    $currentDist = $current['meta_distance_nm'] ?? null; // [MXAIR2026-ROLL]
    $candidateDist = $candidate['meta_distance_nm'] ?? null; // [MXAIR2026-ROLL]
    if (is_numeric($candidateDist) && is_numeric($currentDist)) { // [MXAIR2026-ROLL]
        if ((float)$candidateDist !== (float)$currentDist) { // [MXAIR2026-ROLL]
            return (float)$candidateDist < (float)$currentDist; // [MXAIR2026-ROLL]
        } // [MXAIR2026-ROLL]
    } elseif (is_numeric($candidateDist) && !is_numeric($currentDist)) { // [MXAIR2026-ROLL]
        return true; // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]
    return false; // [MXAIR2026-ROLL]
}

function aggregateAircraft(array $centerPayloads): array
{ // [MXAIR2026-ROLL]
    $byId = []; // [MXAIR2026-ROLL]
    foreach ($centerPayloads as $centerPayload) { // [MXAIR2026-ROLL]
        $center = $centerPayload['center']; // [MXAIR2026-ROLL]
        $acList = $centerPayload['ac'] ?? []; // [MXAIR2026-ROLL]
        if (!is_array($acList)) { // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        } // [MXAIR2026-ROLL]
        foreach ($acList as $ac) { // [MXAIR2026-ROLL]
            if (!is_array($ac) || !isset($ac['lat'], $ac['lon'])) { // [MXAIR2026-ROLL]
                continue; // [MXAIR2026-ROLL]
            } // [MXAIR2026-ROLL]
            $lat = (float)$ac['lat']; // [MXAIR2026-ROLL]
            $lon = (float)$ac['lon']; // [MXAIR2026-ROLL]
            $id = buildEntryId($ac, $lat, $lon); // [MXAIR2026-ROLL]
            if ($id === null) { // [MXAIR2026-ROLL]
                continue; // [MXAIR2026-ROLL]
            } // [MXAIR2026-ROLL]
            $distanceNm = haversineNm($lat, $lon, (float)$center['lat'], (float)$center['lon']); // [MXAIR2026-ROLL]
            $candidate = [ // [MXAIR2026-ROLL]
                'ac' => $ac, // [MXAIR2026-ROLL]
                'meta_seen_pos' => is_numeric($ac['seen_pos'] ?? null) ? (float)$ac['seen_pos'] : null, // [MXAIR2026-ROLL]
                'meta_distance_nm' => $distanceNm, // [MXAIR2026-ROLL]
            ]; // [MXAIR2026-ROLL]
            if (!isset($byId[$id]) || compareAircraftCandidate($byId[$id], $candidate)) { // [MXAIR2026-ROLL]
                $byId[$id] = $candidate; // [MXAIR2026-ROLL]
            } // [MXAIR2026-ROLL]
        } // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]
    $merged = []; // [MXAIR2026-ROLL]
    foreach ($byId as $entry) { // [MXAIR2026-ROLL]
        $merged[] = $entry['ac']; // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]
    return $merged; // [MXAIR2026-ROLL]
}
function closestFeedCenter(float $lat, float $lon, array $centers): ?array
{ // [MXAIR2026]
    $closest = null;
    foreach ($centers as $center) {
        $distance = haversineNm($lat, $lon, (float)$center['lat'], (float)$center['lon']);
        if ($closest === null || $distance < $closest['distance_nm']) {
            $closest = [
                'distance_nm' => $distance,
                'center' => $center,
            ];
        }
    }
    return $closest;
}

$storedSettings = loadStoredSettings($config); // [MXAIR2026-ROLL]
$feedCenters = loadFeedCenters($config, $storedSettings); // [MXAIR2026-ROLL]
$primaryCenter = $feedCenters[0] ?? [ // [MXAIR2026-ROLL]
    'lat' => (float)$storedSettings['feed_center']['lat'], // [MXAIR2026-ROLL]
    'lon' => (float)$storedSettings['feed_center']['lon'], // [MXAIR2026-ROLL]
    'radius_nm' => (float)$storedSettings['feed_center']['radius_nm'], // [MXAIR2026-ROLL]
]; // [MXAIR2026-ROLL]

$coordDecimals = (int)($config['coordinate_round_decimals'] ?? 3); // [MXAIR2026-ROLL]
$altThreshold = (int)($config['altitude_change_threshold_ft'] ?? 100); // [MXAIR2026-ROLL]
$centerCacheTtlMs = (int)($config['feed_center_cache_ttl_ms'] ?? 8000); // [MXAIR2026-ROLL]
$aggregateCacheTtlMs = (int)($config['feed_aggregate_cache_ttl_ms'] ?? 2500); // [MXAIR2026-ROLL]
$aggregateTtlS = (int)($config['feed_aggregate_ttl_s'] ?? 90); // [MXAIR2026-ROLL]
$aggregateTtlMs = max(1000, $aggregateTtlS * 1000); // [MXAIR2026-ROLL]
$centerMaxStaleMs = max($aggregateTtlMs, $centerCacheTtlMs); // [MXAIR2026-ROLL]
$centerSpacingMs = (int)($config['feed_center_request_spacing_ms'] ?? 1100); // [MXAIR2026-ROLL]
$maxCentersPerRequest = (int)($config['feed_max_centers_per_request'] ?? 1); // [MXAIR2026-ROLL]
$roundRobinEnabled = (bool)($config['feed_round_robin_enabled'] ?? true); // [MXAIR2026-ROLL]
$backoffMs = 25000; // [MXAIR2026-ROLL]

$headers = []; // [MXAIR2026-ROLL]
if (!empty($config['adsb_api_key'])) { // [MXAIR2026-ROLL]
    $headerName = $config['adsb_api_header'] ?: 'X-API-Key'; // [MXAIR2026-ROLL]
    $headers[] = $headerName . ': ' . $config['adsb_api_key']; // [MXAIR2026-ROLL]
} // [MXAIR2026-ROLL]

$rrStatePath = $cacheDir . '/feed_rr_state.json'; // [MXAIR2026-ROLL]
$selectedCenterIndexes = selectCentersToRefresh($feedCenters, $maxCentersPerRequest, $roundRobinEnabled, $rrStatePath); // [MXAIR2026-ROLL]
$centerPayloads = []; // [MXAIR2026-ROLL]
$centerStatuses = []; // [MXAIR2026-ROLL]
$upstreamStatus = null; // [MXAIR2026-ROLL]
$upstreamError = null; // [MXAIR2026-ROLL]
$cacheHit = false; // [MXAIR2026-ROLL]
$aggregateStale = false; // [MXAIR2026-ROLL]
$updatedCenters = 0; // [MXAIR2026-ROLL]
$globalLockFile = $cacheDir . '/upstream_rr.lock'; // [MXAIR2026-ROLL]
$latestCenterUpstreamStatus = null; // [MXAIR2026-ROLL]
$latestCenterAttemptMs = null; // [MXAIR2026-ROLL]

foreach ($feedCenters as $index => $center) { // [MXAIR2026-ROLL]
    $centerKey = centerCacheKey($center); // [MXAIR2026-ROLL]
    $centerCacheFile = $cacheDir . '/' . $centerKey . '.json'; // [MXAIR2026-ROLL]
    $centerCache = loadCenterCache($centerCacheFile); // [MXAIR2026-ROLL]
    $storedMs = $centerCache['stored_ms'] ?? null; // [MXAIR2026-ROLL]
    $ageMs = is_numeric($storedMs) ? (nowMs() - (int)$storedMs) : null; // [MXAIR2026-ROLL]
    $backoffUntilMs = (int)($centerCache['backoff_until_ms'] ?? 0); // [MXAIR2026-ROLL]
    $centerLastAttemptMs = (int)($centerCache['center_last_attempt_ms'] ?? 0); // [MXAIR2026-ROLL]
    $centerLastOkMs = (int)($centerCache['center_last_ok_ms'] ?? 0); // [MXAIR2026-ROLL]
    $cacheFresh = $ageMs !== null && $ageMs <= $centerCacheTtlMs; // [MXAIR2026-ROLL]
    $cacheStaleAllowed = $ageMs !== null && $ageMs <= $centerMaxStaleMs; // [MXAIR2026-ROLL]
    $shouldRefresh = in_array($index, $selectedCenterIndexes, true) && (!$cacheFresh); // [MXAIR2026-ROLL]
    $centerStatus = [ // [MXAIR2026-ROLL]
        'name' => (string)($center['name'] ?? ('center-' . $index)), // [MXAIR2026-ROLL]
        'cache_age_ms' => $ageMs, // [MXAIR2026-ROLL]
        'cache_hit' => $cacheFresh, // [MXAIR2026-ROLL]
        'used_stale' => false, // [MXAIR2026-ROLL]
        'upstream_http' => $centerCache['center_upstream_status'] ?? ($centerCache['upstream_http'] ?? null), // [MXAIR2026-ROLL]
        'error' => null, // [MXAIR2026-ROLL]
        'updated' => false, // [MXAIR2026-ROLL]
        'backoff_until_ms' => $backoffUntilMs ?: null, // [MXAIR2026-ROLL]
        'center_last_ok_at' => $centerLastOkMs > 0 ? isoTimeFromMs($centerLastOkMs) : null, // [MXAIR2026-ROLL]
        'center_last_attempt_at' => $centerLastAttemptMs > 0 ? isoTimeFromMs($centerLastAttemptMs) : null, // [MXAIR2026-ROLL]
    ]; // [MXAIR2026-ROLL]

    if ($shouldRefresh && nowMs() >= $backoffUntilMs) { // [MXAIR2026-ROLL]
        $limitSeconds = max(1.0, $centerSpacingMs / 1000); // [MXAIR2026-ROLL]
        if (acquireUpstreamSlot($globalLockFile, $limitSeconds, 0)) { // [MXAIR2026-ROLL]
            $feedUrl = rtrim($config['adsb_feed_url'], '/') . '/' . $center['lat'] . '/' . $center['lon'] . '/' . $center['radius_nm']; // [MXAIR2026-ROLL]
            $attemptMs = nowMs(); // [MXAIR2026-ROLL]
            [$response, $status, $error] = fetchUpstream($feedUrl, $headers, 5); // [MXAIR2026-ROLL]
            if ($status !== null) { // [MXAIR2026-ROLL]
                $upstreamStatus = $status; // [MXAIR2026-ROLL]
            } // [MXAIR2026-ROLL]
            if ($response !== false && $status !== null && $status < 400) { // [MXAIR2026-ROLL]
                $data = json_decode($response, true); // [MXAIR2026-ROLL]
                if (is_array($data) && isset($data['ac']) && is_array($data['ac'])) { // [MXAIR2026-ROLL]
                    $centerLastAttemptMs = $attemptMs; // [MXAIR2026-ROLL]
                    $centerLastOkMs = $attemptMs; // [MXAIR2026-ROLL]
                    $centerCache = [ // [MXAIR2026-ROLL]
                        'stored_ms' => $attemptMs, // [MXAIR2026-ROLL]
                        'payload' => [ // [MXAIR2026-ROLL]
                            'ac' => $data['ac'], // [MXAIR2026-ROLL]
                            'total' => count($data['ac']), // [MXAIR2026-ROLL]
                            'feed_center' => $center, // [MXAIR2026-ROLL]
                        ], // [MXAIR2026-ROLL]
                        'center' => $center, // [MXAIR2026-ROLL]
                        'center_upstream_status' => $status, // [MXAIR2026-ROLL]
                        'error' => null, // [MXAIR2026-ROLL]
                        'last_ok_ms' => $attemptMs, // [MXAIR2026-ROLL]
                        'center_last_ok_ms' => $attemptMs, // [MXAIR2026-ROLL]
                        'center_last_ok_at' => isoTimeFromMs($attemptMs), // [MXAIR2026-ROLL]
                        'center_last_attempt_ms' => $attemptMs, // [MXAIR2026-ROLL]
                        'center_last_attempt_at' => isoTimeFromMs($attemptMs), // [MXAIR2026-ROLL]
                        'backoff_until_ms' => 0, // [MXAIR2026-ROLL]
                    ]; // [MXAIR2026-ROLL]
                    writeCenterCache($centerCacheFile, $centerCache); // [MXAIR2026-ROLL]
                    $ageMs = 0; // [MXAIR2026-ROLL]
                    $cacheFresh = true; // [MXAIR2026-ROLL]
                    $centerStatus['updated'] = true; // [MXAIR2026-ROLL]
                    $centerStatus['upstream_http'] = $status; // [MXAIR2026-ROLL]
                    $centerStatus['center_last_ok_at'] = isoTimeFromMs($attemptMs); // [MXAIR2026-ROLL]
                    $centerStatus['center_last_attempt_at'] = isoTimeFromMs($attemptMs); // [MXAIR2026-ROLL]
                    $updatedCenters++; // [MXAIR2026-ROLL]
                } else { // [MXAIR2026-ROLL]
                    $upstreamError = 'Invalid upstream JSON'; // [MXAIR2026-ROLL]
                    $centerStatus['error'] = $upstreamError; // [MXAIR2026-ROLL]
                } // [MXAIR2026-ROLL]
            } else { // [MXAIR2026-ROLL]
                $statusText = $status !== null ? 'Upstream HTTP ' . $status : 'Upstream request failed'; // [MXAIR2026-ROLL]
                $upstreamError = $error ?? $statusText; // [MXAIR2026-ROLL]
                $centerStatus['error'] = $upstreamError; // [MXAIR2026-ROLL]
                $centerLastAttemptMs = $attemptMs; // [MXAIR2026-ROLL]
                $centerStatus['center_last_attempt_at'] = isoTimeFromMs($attemptMs); // [MXAIR2026-ROLL]
                if ($status === 429) { // [MXAIR2026-ROLL]
                    $backoffUntilMs = nowMs() + $backoffMs; // [MXAIR2026-ROLL]
                } // [MXAIR2026-ROLL]
                if ($centerCache) { // [MXAIR2026-ROLL]
                    $centerCache['center_upstream_status'] = $status; // [MXAIR2026-ROLL]
                    $centerCache['error'] = $upstreamError; // [MXAIR2026-ROLL]
                    $centerCache['backoff_until_ms'] = $backoffUntilMs; // [MXAIR2026-ROLL]
                    $centerCache['center_last_attempt_ms'] = $attemptMs; // [MXAIR2026-ROLL]
                    $centerCache['center_last_attempt_at'] = isoTimeFromMs($attemptMs); // [MXAIR2026-ROLL]
                    writeCenterCache($centerCacheFile, $centerCache); // [MXAIR2026-ROLL]
                } else { // [MXAIR2026-ROLL]
                    writeCenterCache($centerCacheFile, [ // [MXAIR2026-ROLL]
                        'stored_ms' => 0, // [MXAIR2026-ROLL]
                        'payload' => null, // [MXAIR2026-ROLL]
                        'center' => $center, // [MXAIR2026-ROLL]
                        'center_upstream_status' => $status, // [MXAIR2026-ROLL]
                        'error' => $upstreamError, // [MXAIR2026-ROLL]
                        'center_last_attempt_ms' => $attemptMs, // [MXAIR2026-ROLL]
                        'center_last_attempt_at' => isoTimeFromMs($attemptMs), // [MXAIR2026-ROLL]
                        'backoff_until_ms' => $backoffUntilMs, // [MXAIR2026-ROLL]
                    ]); // [MXAIR2026-ROLL]
                } // [MXAIR2026-ROLL]
            } // [MXAIR2026-ROLL]
        } else { // [MXAIR2026-ROLL]
            $centerStatus['error'] = 'rate_limited'; // [MXAIR2026-ROLL]
        } // [MXAIR2026-ROLL]
    } elseif ($shouldRefresh && nowMs() < $backoffUntilMs) { // [MXAIR2026-ROLL]
        $centerStatus['error'] = 'backoff_active'; // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]

    if ($cacheFresh || $cacheStaleAllowed) { // [MXAIR2026-ROLL]
        if (!$cacheFresh) { // [MXAIR2026-ROLL]
            $centerStatus['used_stale'] = true; // [MXAIR2026-ROLL]
            $aggregateStale = true; // [MXAIR2026-ROLL]
        } else { // [MXAIR2026-ROLL]
            $cacheHit = true; // [MXAIR2026-ROLL]
        } // [MXAIR2026-ROLL]
        if ($centerCache && isset($centerCache['payload']['ac']) && is_array($centerCache['payload']['ac'])) { // [MXAIR2026-ROLL]
            $centerPayloads[] = [ // [MXAIR2026-ROLL]
                'center' => $center, // [MXAIR2026-ROLL]
                'ac' => $centerCache['payload']['ac'], // [MXAIR2026-ROLL]
            ]; // [MXAIR2026-ROLL]
        } // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]

    $centerStatuses[] = $centerStatus; // [MXAIR2026-ROLL]
    $centerStatusUpstream = $centerStatus['upstream_http'] ?? null; // [MXAIR2026-ROLL]
    $centerStatusAttemptMs = $centerLastAttemptMs > 0 ? $centerLastAttemptMs : null; // [MXAIR2026-ROLL]
    if ($centerStatusUpstream !== null && ($latestCenterAttemptMs === null || ($centerStatusAttemptMs !== null && $centerStatusAttemptMs > $latestCenterAttemptMs))) { // [MXAIR2026-ROLL]
        $latestCenterAttemptMs = $centerStatusAttemptMs; // [MXAIR2026-ROLL]
        $latestCenterUpstreamStatus = $centerStatusUpstream; // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]
} // [MXAIR2026-ROLL]

if ($upstreamStatus === null && $latestCenterUpstreamStatus !== null) { // [MXAIR2026-ROLL]
    $upstreamStatus = $latestCenterUpstreamStatus; // [MXAIR2026-ROLL]
} // [MXAIR2026-ROLL]
if ($upstreamError) { // [MXAIR2026-ROLL]
    updateLastUpstreamStatus($cacheDir . '/upstream.status.json', $upstreamStatus, $upstreamError); // [MXAIR2026-ROLL]
} else { // [MXAIR2026-ROLL]
    updateLastUpstreamStatus($cacheDir . '/upstream.status.json', $upstreamStatus, null); // [MXAIR2026-ROLL]
} // [MXAIR2026-ROLL]

$mergedAircraft = aggregateAircraft($centerPayloads); // [MXAIR2026-ROLL]
$data = ['ac' => $mergedAircraft]; // [MXAIR2026-ROLL]

$borderFilterEnabled = (bool)($config['mex_border_filter_enabled'] ?? false);
$mexBorderBufferNm = (float)($config['mex_border_buffer_nm'] ?? 10.0);
$mexPolygons = $borderFilterEnabled ? loadGeojsonPolygons(__DIR__ . '/data/mex-border.geojson') : [];

$filteredByHex = [];
$unfilteredByHex = [];
$cacheCleanupThreshold = (float)($config['cache_cleanup_threshold'] ?? ($config['target_ttl_s'] ?? 300.0));
$filterLogger = static function (string $message): void {
    error_log($message);
};
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
    $hex = strtoupper(trim((string)($ac['hex'] ?? $ac['hexid'] ?? '')));
    $id = buildEntryId($ac, $acLat, $acLon);
    if ($id === null) {
        continue;
    }
    $closest = closestFeedCenter($acLat, $acLon, $feedCenters); // [MXAIR2026]
    if ($closest === null) { // [MXAIR2026]
        continue; // [MXAIR2026]
    }
    $feedDistanceNm = (float)$closest['distance_nm']; // [MXAIR2026]
    $centerRadius = (float)($closest['center']['radius_nm'] ?? 0); // [MXAIR2026]
    if ($centerRadius > 0 && $feedDistanceNm > $centerRadius) { // [MXAIR2026]
        continue; // [MXAIR2026]
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
        'id' => $id,
        'hex' => $hex !== '' ? $hex : null,
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
    if (!isset($unfilteredByHex[$id])) {
        $unfilteredByHex[$id] = $entry;
    } else {
        if (shouldReplaceEntry($unfilteredByHex[$id], $entry, $altThreshold, $coordDecimals)) {
            $unfilteredByHex[$id] = $entry;
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
        if (!isset($filteredByHex[$id])) {
            $filteredByHex[$id] = $entry;
            continue;
        }
        if (shouldReplaceEntry($filteredByHex[$id], $entry, $altThreshold, $coordDecimals)) {
            $filteredByHex[$id] = $entry;
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

$payload = [ // [MXAIR2026-ROLL]
    'ac' => $filtered, // [MXAIR2026-ROLL]
    'total' => count($filtered), // [MXAIR2026-ROLL]
    'feed_centers' => $feedCenters, // [MXAIR2026-ROLL]
    'feed_center' => [ // [MXAIR2026-ROLL]
        'lat' => (float)$primaryCenter['lat'], // [MXAIR2026-ROLL]
        'lon' => (float)$primaryCenter['lon'], // [MXAIR2026-ROLL]
        'radius_nm' => (float)$primaryCenter['radius_nm'], // [MXAIR2026-ROLL]
    ], // [MXAIR2026-ROLL]
    'ui_center' => [ // [MXAIR2026-ROLL]
        'lat' => (float)$storedSettings['ui_center']['lat'], // [MXAIR2026-ROLL]
        'lon' => (float)$storedSettings['ui_center']['lon'], // [MXAIR2026-ROLL]
    ], // [MXAIR2026-ROLL]
]; // [MXAIR2026-ROLL]

$aggregateCacheFile = $cacheDir . '/feed_aggregate.json'; // [MXAIR2026-ROLL]
$aggregateCache = readCacheFile($aggregateCacheFile); // [MXAIR2026-ROLL]
$aggregateCacheAgeMs = null; // [MXAIR2026-ROLL]
$previousAggregateMs = null; // [MXAIR2026-ROLL]
if (is_array($aggregateCache) && isset($aggregateCache['stored_ms'])) { // [MXAIR2026-ROLL]
    $previousAggregateMs = (int)$aggregateCache['stored_ms']; // [MXAIR2026-ROLL]
    $aggregateCacheAgeMs = nowMs() - $previousAggregateMs; // [MXAIR2026-ROLL]
} // [MXAIR2026-ROLL]

$storePath = $cacheDir . '/feed_store.json'; // [MXAIR2026-ROLL]
$store = readCacheFile($storePath); // [MXAIR2026-ROLL]
$storeAircraft = is_array($store['aircraft'] ?? null) ? $store['aircraft'] : []; // [MXAIR2026-ROLL]
$nowMs = nowMs(); // [MXAIR2026-ROLL]
$aggregateById = []; // [MXAIR2026-ROLL]
foreach ($payload['ac'] as $entry) { // [MXAIR2026-ROLL]
    $seenPos = is_numeric($entry['seen_pos'] ?? null) ? (float)$entry['seen_pos'] : null; // [MXAIR2026-ROLL]
    $lastSeenMs = $seenPos !== null ? max(0, $nowMs - (int)round($seenPos * 1000)) : $nowMs; // [MXAIR2026-ROLL]
    $entry['last_seen_ms'] = $lastSeenMs; // [MXAIR2026-ROLL]
    $entry['stale'] = false; // [MXAIR2026-ROLL]
    $aggregateById[$entry['id']] = $entry; // [MXAIR2026-ROLL]
    $storeAircraft[$entry['id']] = [ // [MXAIR2026-ROLL]
        'last_seen_ms' => $lastSeenMs, // [MXAIR2026-ROLL]
        'entry' => $entry, // [MXAIR2026-ROLL]
    ]; // [MXAIR2026-ROLL]
} // [MXAIR2026-ROLL]

foreach ($storeAircraft as $id => $stored) { // [MXAIR2026-ROLL]
    $lastSeenMs = (int)($stored['last_seen_ms'] ?? 0); // [MXAIR2026-ROLL]
    if ($nowMs - $lastSeenMs > $aggregateTtlMs) { // [MXAIR2026-ROLL]
        unset($storeAircraft[$id]); // [MXAIR2026-ROLL]
        continue; // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]
    if (!isset($aggregateById[$id]) && isset($stored['entry']) && is_array($stored['entry'])) { // [MXAIR2026-ROLL]
        $staleEntry = $stored['entry']; // [MXAIR2026-ROLL]
        $staleEntry['stale'] = true; // [MXAIR2026-ROLL]
        $staleEntry['last_seen_ms'] = $lastSeenMs; // [MXAIR2026-ROLL]
        $aggregateById[$id] = $staleEntry; // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]
} // [MXAIR2026-ROLL]

$aggregateList = array_values($aggregateById); // [MXAIR2026-ROLL]
usort($aggregateList, function (array $a, array $b): int { // [MXAIR2026-ROLL]
    return ($a['distance_nm'] ?? 0) <=> ($b['distance_nm'] ?? 0); // [MXAIR2026-ROLL]
}); // [MXAIR2026-ROLL]

$payload['ac'] = $aggregateList; // [MXAIR2026-ROLL]
$payload['total'] = count($aggregateList); // [MXAIR2026-ROLL]
$payload['generated_at'] = gmdate('c'); // [MXAIR2026-ROLL]
$payload['generated_at_ms'] = $nowMs; // [MXAIR2026-ROLL]

writeCacheFile($storePath, [ // [MXAIR2026-ROLL]
    'stored_ms' => $nowMs, // [MXAIR2026-ROLL]
    'aircraft' => $storeAircraft, // [MXAIR2026-ROLL]
]); // [MXAIR2026-ROLL]

writeCacheFile($aggregateCacheFile, [ // [MXAIR2026-ROLL]
    'stored_ms' => $nowMs, // [MXAIR2026-ROLL]
    'payload' => $payload, // [MXAIR2026-ROLL]
]); // [MXAIR2026-ROLL]

$latestSeenMs = null; // [MXAIR2026-ROLL]
foreach ($aggregateList as $entry) { // [MXAIR2026-ROLL]
    if (isset($entry['last_seen_ms']) && is_numeric($entry['last_seen_ms'])) { // [MXAIR2026-ROLL]
        $lastSeen = (int)$entry['last_seen_ms']; // [MXAIR2026-ROLL]
        if ($latestSeenMs === null || $lastSeen > $latestSeenMs) { // [MXAIR2026-ROLL]
            $latestSeenMs = $lastSeen; // [MXAIR2026-ROLL]
        } // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]
} // [MXAIR2026-ROLL]
$aggregateAgeMs = $latestSeenMs !== null ? max(0, $nowMs - $latestSeenMs) : null; // [MXAIR2026-ROLL]

$hasData = $payload['total'] > 0; // [MXAIR2026-ROLL]
$ok = $hasData || $cacheHit || $updatedCenters > 0; // [MXAIR2026-ROLL]
$errorMessage = $ok ? null : ($upstreamError ?? 'No feed data available'); // [MXAIR2026-ROLL]
$aggregateStale = $aggregateAgeMs === null || $aggregateAgeMs > $aggregateTtlMs; // [MXAIR2026-ROLL]
$responseAgeMs = $previousAggregateMs !== null ? max(0, $nowMs - $previousAggregateMs) : 0; // [MXAIR2026-ROLL]

respond([ // [MXAIR2026-ROLL]
    'ok' => $ok, // [MXAIR2026-ROLL]
    'upstream_http' => $upstreamStatus, // [MXAIR2026-ROLL]
    'upstream_status_per_center' => $centerStatuses, // [MXAIR2026-ROLL]
    'centers_status' => $centerStatuses, // [MXAIR2026-ROLL]
    'cache_hit' => $cacheHit, // [MXAIR2026-ROLL]
    'cache_stale' => $aggregateStale, // [MXAIR2026-ROLL]
    'age_ms' => $responseAgeMs, // [MXAIR2026-ROLL]
    'aggregate_age_ms' => $aggregateAgeMs, // [MXAIR2026-ROLL]
    'cache_max_stale_ms' => $aggregateTtlMs, // [MXAIR2026-ROLL]
    'now' => date('c'), // [MXAIR2026-ROLL]
    'total' => $payload['total'], // [MXAIR2026-ROLL]
    'ac' => $payload['ac'], // [MXAIR2026-ROLL]
    'error' => $errorMessage, // [MXAIR2026-ROLL]
    'feed_centers' => $feedCenters, // [MXAIR2026-ROLL]
    'feed_center' => $payload['feed_center'], // [MXAIR2026-ROLL]
    'ui_center' => $payload['ui_center'], // [MXAIR2026-ROLL]
]); // [MXAIR2026-ROLL]
