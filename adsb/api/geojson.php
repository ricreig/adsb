<?php
$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../auth.php';
requireAuth($config);

$layer = isset($_GET['layer']) ? strtolower(trim($_GET['layer'])) : '';
$layer = preg_replace('/[^a-z0-9\-]/', '', $layer);
if ($layer === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Missing layer parameter']);
    exit;
}

$path = rtrim($config['geojson_dir'], '/\\') . '/' . $layer . '.geojson';
if (!is_file($path)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Layer not found']);
    exit;
}

$contents = @file_get_contents($path);
if ($contents === false) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Failed to read layer']);
    exit;
}

$contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
$data = json_decode($contents, true);
if (!is_array($data) || !isset($data['type'])) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Invalid GeoJSON']);
    exit;
}

$north = filter_input(INPUT_GET, 'north', FILTER_VALIDATE_FLOAT);
$south = filter_input(INPUT_GET, 'south', FILTER_VALIDATE_FLOAT);
$east = filter_input(INPUT_GET, 'east', FILTER_VALIDATE_FLOAT);
$west = filter_input(INPUT_GET, 'west', FILTER_VALIDATE_FLOAT);
$useBbox = $north !== false && $south !== false && $east !== false && $west !== false;
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);
if ($limit !== null && $limit !== false) {
    $limit = max(50, min(5000, $limit));
} else {
    $limit = null;
}

function updateBbox($coords, ?array &$bbox): void
{
    if (!is_array($coords)) {
        return;
    }
    if (count($coords) === 2 && is_numeric($coords[0]) && is_numeric($coords[1])) {
        $lon = (float)$coords[0];
        $lat = (float)$coords[1];
        if ($bbox === null) {
            $bbox = [$lon, $lat, $lon, $lat];
        } else {
            $bbox[0] = min($bbox[0], $lon);
            $bbox[1] = min($bbox[1], $lat);
            $bbox[2] = max($bbox[2], $lon);
            $bbox[3] = max($bbox[3], $lat);
        }
        return;
    }
    foreach ($coords as $item) {
        updateBbox($item, $bbox);
    }
}

if ($useBbox && isset($data['features']) && is_array($data['features'])) {
    $bbox = [$west, $south, $east, $north];
    $filtered = [];
    foreach ($data['features'] as $feature) {
        $geometry = $feature['geometry'] ?? null;
        if (!is_array($geometry)) {
            continue;
        }
        $featureBbox = null;
        updateBbox($geometry['coordinates'] ?? null, $featureBbox);
        if ($featureBbox === null) {
            continue;
        }
        $intersects = !($featureBbox[2] < $bbox[0]
            || $featureBbox[0] > $bbox[2]
            || $featureBbox[3] < $bbox[1]
            || $featureBbox[1] > $bbox[3]);
        if ($intersects) {
            $filtered[] = $feature;
        }
    }
    $data['features'] = $filtered;
}

$catalogPath = rtrim($config['geojson_dir'], '/\\') . '/catalog.json';
$catalog = null;
if (is_file($catalogPath)) {
    $catalogData = json_decode((string)file_get_contents($catalogPath), true);
    if (is_array($catalogData)) {
        $catalog = $catalogData;
    }
}
$layerMeta = $catalog['layers'][$layer] ?? null;
$totalFeatures = $layerMeta['features'] ?? (isset($data['features']) && is_array($data['features']) ? count($data['features']) : 0);

if ($limit !== null && isset($data['features']) && is_array($data['features'])) {
    if (count($data['features']) > $limit) {
        $data['features'] = array_slice($data['features'], 0, $limit);
    }
}

$meta = [
    'layer' => $layer,
    'total_features' => $totalFeatures,
    'returned' => isset($data['features']) && is_array($data['features']) ? count($data['features']) : 0,
    'limit' => $limit,
    'truncated' => $limit !== null && $totalFeatures > $limit,
];
$data['meta'] = $meta;

header('Content-Type: application/geo+json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($data, JSON_UNESCAPED_SLASHES);
