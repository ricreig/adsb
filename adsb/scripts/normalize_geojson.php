<?php
declare(strict_types=1);

$dataDir = __DIR__ . '/../data';
$files = glob($dataDir . '/*.geojson') ?: [];

function normalizeDegree(float $value, float $limit): float
{
    $abs = abs($value);
    while ($abs > $limit && $abs <= ($limit * 10)) {
        $value /= 10;
        $abs = abs($value);
    }
    return $value;
}

function normalizeCoordinates($coords)
{
    if (!is_array($coords)) {
        return $coords;
    }
    if (count($coords) >= 2 && is_numeric($coords[0]) && is_numeric($coords[1])) {
        $lon = normalizeDegree((float)$coords[0], 180.0);
        $lat = normalizeDegree((float)$coords[1], 90.0);
        return [$lon, $lat];
    }
    $normalized = [];
    foreach ($coords as $item) {
        $normalized[] = normalizeCoordinates($item);
    }
    return $normalized;
}

foreach ($files as $file) {
    $contents = file_get_contents($file);
    $json = json_decode($contents ?? '', true);
    if (!is_array($json) || !isset($json['features']) || !is_array($json['features'])) {
        fwrite(STDERR, "Invalid GeoJSON: {$file}\n");
        continue;
    }
    foreach ($json['features'] as &$feature) {
        if (!isset($feature['geometry']['coordinates'])) {
            continue;
        }
        $feature['geometry']['coordinates'] = normalizeCoordinates($feature['geometry']['coordinates']);
    }
    unset($feature);
    file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "Normalized: {$file}\n";
}
