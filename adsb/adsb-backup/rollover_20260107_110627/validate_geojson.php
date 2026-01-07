<?php
declare(strict_types=1);

$dataDir = __DIR__ . '/../data';
$files = glob($dataDir . '/*.geojson') ?: [];

$maxExamples = 20;
$examples = [];
$totalErrors = 0;

function isValidCoordinate($lon, $lat): bool
{
    if (!is_numeric($lat) || !is_numeric($lon)) {
        return false;
    }
    $lat = (float)$lat;
    $lon = (float)$lon;
    return $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180;
}

function walkCoordinates($coords, callable $callback): void
{
    if (!is_array($coords)) {
        return;
    }
    if (count($coords) >= 2 && is_numeric($coords[0]) && is_numeric($coords[1])) {
        $callback($coords);
        return;
    }
    foreach ($coords as $child) {
        walkCoordinates($child, $callback);
    }
}

echo "file,features,coords,out_of_range\n";

foreach ($files as $file) {
    $contents = file_get_contents($file);
    $json = json_decode($contents ?? '', true);
    if (!is_array($json) || !isset($json['features']) || !is_array($json['features'])) {
        fwrite(STDERR, "Invalid GeoJSON: {$file}\n");
        $totalErrors++;
        continue;
    }
    $featureCount = count($json['features']);
    $coordCount = 0;
    $outOfRange = 0;
    foreach ($json['features'] as $idx => $feature) {
        if (!is_array($feature) || !isset($feature['geometry'])) {
            continue;
        }
        $geometry = $feature['geometry'];
        if (!is_array($geometry) || !isset($geometry['coordinates'])) {
            continue;
        }
        walkCoordinates($geometry['coordinates'], function ($coord) use ($file, $idx, &$coordCount, &$outOfRange, &$examples, $maxExamples) {
            $coordCount++;
            $lon = $coord[0] ?? null;
            $lat = $coord[1] ?? null;
            if (!isValidCoordinate($lon, $lat)) {
                $outOfRange++;
                if (count($examples) < $maxExamples) {
                    $examples[] = sprintf(
                        "%s | feature %d | lon=%s lat=%s",
                        basename($file),
                        $idx,
                        var_export($lon, true),
                        var_export($lat, true)
                    );
                }
            }
        });
    }
    echo sprintf(
        "%s,%d,%d,%d\n",
        basename($file),
        $featureCount,
        $coordCount,
        $outOfRange
    );
    if ($outOfRange > 0) {
        $totalErrors += $outOfRange;
    }
}

if ($examples) {
    echo "\nExamples (first {$maxExamples}):\n";
    foreach ($examples as $example) {
        echo "- {$example}\n";
    }
}

exit($totalErrors > 0 ? 1 : 0);
