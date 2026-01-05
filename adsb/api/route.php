<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../auth.php';
requireAuth($config);

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureDb(string $dbPath): PDO
{
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE IF NOT EXISTS flight_plans (
        callsign TEXT PRIMARY KEY,
        data TEXT NOT NULL,
        summary TEXT NOT NULL,
        updated_at INTEGER NOT NULL,
        failure_count INTEGER NOT NULL DEFAULT 0,
        next_retry_at INTEGER
    )');
    return $pdo;
}

function buildSummary(array $geojson): array
{
    $summary = [
        'fix_count' => 0,
        'start' => null,
        'end' => null,
    ];
    $features = $geojson['features'] ?? [];
    foreach ($features as $feature) {
        $geometry = $feature['geometry'] ?? null;
        if (!is_array($geometry)) {
            continue;
        }
        if (($geometry['type'] ?? '') === 'LineString') {
            $coords = $geometry['coordinates'] ?? [];
            if (is_array($coords) && count($coords) >= 2) {
                $summary['fix_count'] = count($coords);
                $summary['start'] = $coords[0] ?? null;
                $summary['end'] = $coords[count($coords) - 1] ?? null;
                break;
            }
        }
    }
    return $summary;
}

function loadLocalRoute(string $callsign): ?array
{
    $routePath = __DIR__ . '/../data/routes/' . $callsign . '.geojson';
    if (!is_file($routePath)) {
        return null;
    }
    $contents = file_get_contents($routePath);
    if ($contents === false) {
        return null;
    }
    $geojson = json_decode($contents, true);
    return is_array($geojson) ? $geojson : null;
}

function fetchUpstreamRoute(array $config, string $callsign): ?array
{
    $apiUrl = $config['flight_plan_api_url'] ?? null;
    if (!$apiUrl) {
        return null;
    }
    $query = strpos($apiUrl, '?') === false ? '?' : '&';
    $url = $apiUrl . $query . 'callsign=' . urlencode($callsign);
    $headers = [];
    if (!empty($config['flight_plan_api_key'])) {
        $headers[] = 'Authorization: Bearer ' . $config['flight_plan_api_key'];
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 5,
            'header' => $headers ? implode("\r\n", $headers) : '',
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return null;
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return null;
    }
    if (isset($decoded['route']) && is_array($decoded['route'])) {
        return $decoded['route'];
    }
    return isset($decoded['type']) ? $decoded : null;
}

$callsign = strtoupper(trim((string)($_GET['callsign'] ?? '')));
if ($callsign === '') {
    respond(['error' => 'Missing callsign.'], 400);
}
if (!preg_match('/^[A-Z0-9]{2,8}$/', $callsign)) {
    respond(['error' => 'Invalid callsign.'], 400);
}

try {
    $pdo = ensureDb($config['settings_db']);
} catch (Throwable $e) {
    respond(['error' => 'Unable to initialize flight plan storage.'], 500);
}

$cacheTtl = (int)($config['flight_plan_cache_ttl'] ?? 900);
$now = time();
$stmt = $pdo->prepare('SELECT data, summary, updated_at, failure_count, next_retry_at FROM flight_plans WHERE callsign = :callsign');
$stmt->execute([':callsign' => $callsign]);
$cached = $stmt->fetch(PDO::FETCH_ASSOC);
if ($cached && isset($cached['data'], $cached['summary'])) {
    $age = $now - (int)$cached['updated_at'];
    if ($age <= $cacheTtl) {
        respond([
            'ok' => true,
            'cached' => true,
            'stale' => false,
            'route' => json_decode($cached['data'], true),
            'summary' => json_decode($cached['summary'], true),
        ]);
    }
}

$nextRetry = $cached && $cached['next_retry_at'] ? (int)$cached['next_retry_at'] : null;
if ($nextRetry && $now < $nextRetry && $cached) {
    respond([
        'ok' => true,
        'cached' => true,
        'stale' => true,
        'route' => json_decode($cached['data'], true),
        'summary' => json_decode($cached['summary'], true),
        'backoff_until' => $nextRetry,
    ]);
}

$route = loadLocalRoute($callsign);
if ($route === null) {
    $route = fetchUpstreamRoute($config, $callsign);
}

if ($route === null) {
    $failureCount = $cached ? ((int)$cached['failure_count'] + 1) : 1;
    $backoffSeconds = min(900, 60 * (2 ** min($failureCount, 4)));
    $nextRetry = $now + $backoffSeconds;
    $stmt = $pdo->prepare('INSERT INTO flight_plans (callsign, data, summary, updated_at, failure_count, next_retry_at)
        VALUES (:callsign, :data, :summary, :updated_at, :failure_count, :next_retry_at)
        ON CONFLICT(callsign) DO UPDATE SET failure_count = excluded.failure_count, next_retry_at = excluded.next_retry_at');
    $stmt->execute([
        ':callsign' => $callsign,
        ':data' => $cached['data'] ?? json_encode(['type' => 'FeatureCollection', 'features' => []], JSON_UNESCAPED_SLASHES),
        ':summary' => $cached['summary'] ?? json_encode(['fix_count' => 0], JSON_UNESCAPED_SLASHES),
        ':updated_at' => $cached['updated_at'] ?? $now,
        ':failure_count' => $failureCount,
        ':next_retry_at' => $nextRetry,
    ]);
    respond([
        'ok' => false,
        'error' => 'Route unavailable.',
        'backoff_until' => $nextRetry,
    ], 200);
}

$summary = buildSummary($route);
$stmt = $pdo->prepare('INSERT INTO flight_plans (callsign, data, summary, updated_at, failure_count, next_retry_at)
    VALUES (:callsign, :data, :summary, :updated_at, 0, NULL)
    ON CONFLICT(callsign) DO UPDATE SET data = excluded.data, summary = excluded.summary, updated_at = excluded.updated_at, failure_count = 0, next_retry_at = NULL');
$stmt->execute([
    ':callsign' => $callsign,
    ':data' => json_encode($route, JSON_UNESCAPED_SLASHES),
    ':summary' => json_encode($summary, JSON_UNESCAPED_SLASHES),
    ':updated_at' => $now,
]);

respond([
    'ok' => true,
    'cached' => false,
    'stale' => false,
    'route' => $route,
    'summary' => $summary,
]);
