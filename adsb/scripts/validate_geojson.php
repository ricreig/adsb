<?php
declare(strict_types=1); // [MXAIR2026-ROLL]

$dataDir = __DIR__ . '/../data'; // [MXAIR2026-ROLL]
$files = glob($dataDir . '/*.geojson') ?: []; // [MXAIR2026-ROLL]

$maxExamples = 20; // [MXAIR2026-ROLL]
$examples = []; // [MXAIR2026-ROLL]
$totalErrors = 0; // [MXAIR2026-ROLL]
$logLines = []; // [MXAIR2026-ROLL]

function isValidCoordinate($lon, $lat): bool // [MXAIR2026-ROLL]
{ // [MXAIR2026-ROLL]
    if (!is_numeric($lat) || !is_numeric($lon)) { // [MXAIR2026-ROLL]
        return false; // [MXAIR2026-ROLL]
    }
    $lat = (float)$lat; // [MXAIR2026-ROLL]
    $lon = (float)$lon; // [MXAIR2026-ROLL]
    return $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180; // [MXAIR2026-ROLL]
} // [MXAIR2026-ROLL]

function walkCoordinates($coords, callable $callback): void // [MXAIR2026-ROLL]
{ // [MXAIR2026-ROLL]
    if (!is_array($coords)) { // [MXAIR2026-ROLL]
        return; // [MXAIR2026-ROLL]
    }
    if (count($coords) >= 2 && is_numeric($coords[0]) && is_numeric($coords[1])) { // [MXAIR2026-ROLL]
        $callback($coords); // [MXAIR2026-ROLL]
        return; // [MXAIR2026-ROLL]
    }
    foreach ($coords as $child) { // [MXAIR2026-ROLL]
        walkCoordinates($child, $callback); // [MXAIR2026-ROLL]
    }
} // [MXAIR2026-ROLL]

function isValidGeometry(array $geometry): bool // [MXAIR2026-ROLL]
{ // [MXAIR2026-ROLL]
    $type = $geometry['type'] ?? ''; // [MXAIR2026-ROLL]
    $coords = $geometry['coordinates'] ?? null; // [MXAIR2026-ROLL]
    if (!$type || !is_array($coords)) { // [MXAIR2026-ROLL]
        return false; // [MXAIR2026-ROLL]
    }
    if ($type === 'Point') { // [MXAIR2026-ROLL]
        return count($coords) >= 2; // [MXAIR2026-ROLL]
    }
    if ($type === 'LineString') { // [MXAIR2026-ROLL]
        return count($coords) >= 2; // [MXAIR2026-ROLL]
    }
    if ($type === 'Polygon') { // [MXAIR2026-ROLL]
        return count($coords) >= 1; // [MXAIR2026-ROLL]
    }
    if ($type === 'MultiLineString' || $type === 'MultiPolygon') { // [MXAIR2026-ROLL]
        return count($coords) >= 1; // [MXAIR2026-ROLL]
    }
    return false; // [MXAIR2026-ROLL]
} // [MXAIR2026-ROLL]

echo "file,features,coords,out_of_range,invalid_geometry\n"; // [MXAIR2026-ROLL]

foreach ($files as $file) { // [MXAIR2026-ROLL]
    $contents = file_get_contents($file); // [MXAIR2026-ROLL]
    $json = json_decode($contents ?? '', true); // [MXAIR2026-ROLL]
    if (!is_array($json) || !isset($json['features']) || !is_array($json['features'])) { // [MXAIR2026-ROLL]
        fwrite(STDERR, "Invalid GeoJSON: {$file}\n"); // [MXAIR2026-ROLL]
        $totalErrors++; // [MXAIR2026-ROLL]
        continue; // [MXAIR2026-ROLL]
    }
    $featureCount = count($json['features']); // [MXAIR2026-ROLL]
    $coordCount = 0; // [MXAIR2026-ROLL]
    $outOfRange = 0; // [MXAIR2026-ROLL]
    $invalidGeometry = 0; // [MXAIR2026-ROLL]
    $nameExamples = []; // [MXAIR2026-ROLL]
    foreach ($json['features'] as $idx => $feature) { // [MXAIR2026-ROLL]
        if (!is_array($feature) || !isset($feature['geometry'])) { // [MXAIR2026-ROLL]
            $invalidGeometry++; // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        $geometry = $feature['geometry']; // [MXAIR2026-ROLL]
        if (!is_array($geometry) || !isset($geometry['coordinates'])) { // [MXAIR2026-ROLL]
            $invalidGeometry++; // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        if (!isValidGeometry($geometry)) { // [MXAIR2026-ROLL]
            $invalidGeometry++; // [MXAIR2026-ROLL]
        }
        $props = $feature['properties'] ?? []; // [MXAIR2026-ROLL]
        $name = $props['name'] ?? $props['Name'] ?? $props['ident'] ?? null; // [MXAIR2026-ROLL]
        if ($name && count($nameExamples) < 3) { // [MXAIR2026-ROLL]
            $nameExamples[] = $name; // [MXAIR2026-ROLL]
        }
        walkCoordinates($geometry['coordinates'], function ($coord) use ($file, $idx, &$coordCount, &$outOfRange, &$examples, $maxExamples) { // [MXAIR2026-ROLL]
            $coordCount++; // [MXAIR2026-ROLL]
            $lon = $coord[0] ?? null; // [MXAIR2026-ROLL]
            $lat = $coord[1] ?? null; // [MXAIR2026-ROLL]
            if (!isValidCoordinate($lon, $lat)) { // [MXAIR2026-ROLL]
                $outOfRange++; // [MXAIR2026-ROLL]
                if (count($examples) < $maxExamples) { // [MXAIR2026-ROLL]
                    $examples[] = sprintf( // [MXAIR2026-ROLL]
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
    echo sprintf( // [MXAIR2026-ROLL]
        "%s,%d,%d,%d,%d\n",
        basename($file),
        $featureCount,
        $coordCount,
        $outOfRange,
        $invalidGeometry
    );
    $logLines[] = sprintf( // [MXAIR2026-ROLL]
        "[%s] %s: features=%d coords=%d out_of_range=%d invalid_geometry=%d examples=%s",
        gmdate('c'),
        basename($file),
        $featureCount,
        $coordCount,
        $outOfRange,
        $invalidGeometry,
        $nameExamples ? implode(', ', $nameExamples) : 'n/a'
    );
    if ($outOfRange > 0) { // [MXAIR2026-ROLL]
        $totalErrors += $outOfRange; // [MXAIR2026-ROLL]
    }
    if ($invalidGeometry > 0) { // [MXAIR2026-ROLL]
        $totalErrors += $invalidGeometry; // [MXAIR2026-ROLL]
    }
}

if ($examples) { // [MXAIR2026-ROLL]
    echo "\nExamples (first {$maxExamples}):\n"; // [MXAIR2026-ROLL]
    foreach ($examples as $example) { // [MXAIR2026-ROLL]
        echo "- {$example}\n"; // [MXAIR2026-ROLL]
    }
}

if ($logLines) { // [MXAIR2026-ROLL]
    $logPath = __DIR__ . '/../data/geojson_validation.log'; // [MXAIR2026-ROLL]
    file_put_contents($logPath, implode(PHP_EOL, $logLines) . PHP_EOL, FILE_APPEND); // [MXAIR2026-ROLL]
}

exit($totalErrors > 0 ? 1 : 0); // [MXAIR2026-ROLL]
