<?php
$config = require __DIR__ . '/../config.php';

$layer = isset($_GET['layer']) ? strtolower(trim($_GET['layer'])) : '';
$layer = preg_replace('/[^a-z0-9\-]/', '', $layer);
if ($layer === '') {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Missing layer parameter']);
    exit;
}

$path = rtrim($config['geojson_dir'], '/\') . '/' . $layer . '.geojson';
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

header('Content-Type: application/geo+json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($data, JSON_UNESCAPED_SLASHES);
