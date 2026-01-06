<?php

declare(strict_types=1);

$config = require __DIR__ . '/../config.php';

$checks = [];
$errors = [];

function addCheck(array &$checks, string $label, bool $ok, ?string $detail = null): void
{
    $checks[] = [
        'check' => $label,
        'ok' => $ok,
        'detail' => $detail,
    ];
}

$dataDir = __DIR__ . '/../data';
$geojsonDir = $config['geojson_dir'] ?? $dataDir;
$cacheDir = $config['feed_cache_dir'] ?? ($dataDir . '/cache');

addCheck($checks, 'data_dir_exists', is_dir($dataDir));
addCheck($checks, 'geojson_dir_exists', is_dir($geojsonDir), $geojsonDir);
addCheck($checks, 'cache_dir_exists', is_dir($cacheDir), $cacheDir);

$geojsonFiles = is_dir($geojsonDir) ? glob($geojsonDir . '/*.geojson') : [];
if ($geojsonFiles) {
    foreach ($geojsonFiles as $file) {
        $contents = file_get_contents($file);
        $decoded = $contents ? json_decode($contents, true) : null;
        $ok = is_array($decoded) && isset($decoded['type']);
        addCheck($checks, 'geojson_valid:' . basename($file), $ok, $ok ? null : 'Invalid JSON');
        if (!$ok) {
            $errors[] = 'Invalid GeoJSON: ' . $file;
        }
    }
} else {
    addCheck($checks, 'geojson_present', false, 'No GeoJSON files found');
}

$leafletJs = __DIR__ . '/../assets/vendor/leaflet/leaflet.js';
$leafletCss = __DIR__ . '/../assets/vendor/leaflet/leaflet.css';
addCheck($checks, 'leaflet_js_present', is_file($leafletJs), $leafletJs);
addCheck($checks, 'leaflet_css_present', is_file($leafletCss), $leafletCss);

$result = [
    'ok' => empty($errors),
    'checks' => $checks,
    'errors' => $errors,
    'timestamp' => date('c'),
];

header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
