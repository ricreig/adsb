<?php
// GeoJSON manifest endpoint. // [MXAIR2026-ROLL]

$config = require __DIR__ . '/../config.php'; // [MXAIR2026-ROLL]
require __DIR__ . '/../auth.php'; // [MXAIR2026-ROLL]
requireAuth($config); // [MXAIR2026-ROLL]

header('Content-Type: application/json; charset=utf-8'); // [MXAIR2026-ROLL]

$geojsonDir = $config['geojson_dir'] ?? (__DIR__ . '/../data'); // [MXAIR2026-ROLL]
$layers = []; // [MXAIR2026-ROLL]
if (is_dir($geojsonDir)) { // [MXAIR2026-ROLL]
    foreach (scandir($geojsonDir) as $file) { // [MXAIR2026-ROLL]
        if ($file[0] === '.') { // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'geojson') { // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        $id = pathinfo($file, PATHINFO_FILENAME); // [MXAIR2026-ROLL]
        if ($id === '') { // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        $layers[$id] = 'api/geojson.php?layer=' . rawurlencode($id); // [MXAIR2026-ROLL]
    }
}

ksort($layers); // [MXAIR2026-ROLL]

echo json_encode([
    'ok' => true, // [MXAIR2026-ROLL]
    'generated_at' => date('c'), // [MXAIR2026-ROLL]
    'layers' => $layers, // [MXAIR2026-ROLL]
], JSON_UNESCAPED_SLASHES); // [MXAIR2026-ROLL]
