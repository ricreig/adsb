<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../auth.php';
requireAuth($config);
$navFile = $config['geojson_dir'] . '/nav-points.geojson';

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function isMexicoCoord(float $lat, float $lon): bool
{
    return $lat >= 10.0 && $lat <= 40.0 && $lon >= -120.0 && $lon <= -80.0;
}

function normalizeCoord(float $lon, float $lat): array
{
    if (isMexicoCoord($lat, $lon)) {
        return [$lon, $lat];
    }
    if (isMexicoCoord($lon, $lat)) {
        return [$lat, $lon];
    }
    return [$lon, $lat];
}

function loadNavpoints(string $path): array
{
    if (!is_file($path)) {
        respond(['error' => 'Navpoints file not found.'], 404);
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        respond(['error' => 'Unable to read navpoints file.'], 500);
    }
    $data = json_decode($contents, true);
    if (!is_array($data)) {
        respond(['error' => 'Invalid navpoints GeoJSON.'], 500);
    }
    return $data;
}

$north = filter_input(INPUT_GET, 'north', FILTER_VALIDATE_FLOAT);
$south = filter_input(INPUT_GET, 'south', FILTER_VALIDATE_FLOAT);
$east = filter_input(INPUT_GET, 'east', FILTER_VALIDATE_FLOAT);
$west = filter_input(INPUT_GET, 'west', FILTER_VALIDATE_FLOAT);
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 2000;
$limit = max(250, min(5000, $limit));

if ($north === false || $south === false || $east === false || $west === false) {
    respond(['error' => 'Missing or invalid bbox parameters.'], 400);
}

$geojson = loadNavpoints($navFile);
$features = $geojson['features'] ?? [];
$matches = 0;
$collected = [];

foreach ($features as $feature) {
    $geometry = $feature['geometry'] ?? null;
    if (!is_array($geometry) || ($geometry['type'] ?? '') !== 'Point') {
        continue;
    }
    $coords = $geometry['coordinates'] ?? null;
    if (!is_array($coords) || count($coords) < 2) {
        continue;
    }
    [$lon, $lat] = normalizeCoord((float)$coords[0], (float)$coords[1]);
    if ($lat < $south || $lat > $north || $lon < $west || $lon > $east) {
        continue;
    }
    $matches++;
    if (count($collected) >= $limit) {
        continue;
    }
    $feature['geometry']['coordinates'] = [$lon, $lat];
    $collected[] = $feature;
}

respond([
    'type' => 'FeatureCollection',
    'features' => $collected,
    'meta' => [
        'total' => $matches,
        'returned' => count($collected),
        'limit' => $limit,
        'truncated' => $matches > $limit,
    ],
]);
