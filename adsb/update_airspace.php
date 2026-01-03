<?php
/**
 * update_airspace.php
 *
 * Utility script to convert vatmex XML files into GeoJSON layers consumable
 * by the ATC display.  Run this script manually or via a cron job each
 * AIRAC cycle.  It parses restricted areas, FIR boundaries, TMA/CTR
 * limits, MVA/MRA polygons and navaid/fix points from the vatmex
 * repository and writes them to the data directory defined in
 * config.php.
 *
 * Usage (from the project root):
 *   php update_airspace.php vatmex/vatmex-mmfr-sector-central
 */

ini_set('memory_limit', '512M');
ini_set('max_execution_time', '600');

$config = require __DIR__ . '/config.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php update_airspace.php <path-to-vatmex>\n");
    exit(1);
}
$basePath = rtrim($argv[1], '/');
if (!is_dir($basePath)) {
    fwrite(STDERR, "Provided path does not exist: $basePath\n");
    exit(1);
}

$outputDir = $config['geojson_dir'];
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}

/**
 * Convert lat/lon strings in DDMMSS.S format to decimal degrees.  vatSys
 * uses ISO 6709 formats; restricted areas often list coordinates as
 * +DDMMSS.SSS±DDDMMSS.SSS.  This helper handles those strings.
 */
function parseCoordinate(string $coord): float
{
    $sign = ($coord[0] === '-') ? -1 : 1;
    $coord = ltrim($coord, '+-');
    [$whole, $fraction] = array_pad(explode('.', $coord, 2), 2, '');
    if (strlen($whole) < 5) {
        return 0.0;
    }
    $mmss = substr($whole, -4);
    $degPart = substr($whole, 0, -4);
    $deg = (int)$degPart;
    $min = (int)substr($mmss, 0, 2);
    $sec = (float)(substr($mmss, 2, 2) . ($fraction !== '' ? '.' . $fraction : ''));
    return $sign * ($deg + $min / 60 + $sec / 3600);
}

/**
 * Write a GeoJSON collection to the output directory.
 */
function writeGeoJSON(string $name, array $features, string $outputDir): void
{
    $geojson = [
        'type' => 'FeatureCollection',
        'features' => $features,
    ];
    $path = $outputDir . '/' . $name . '.geojson';
    file_put_contents($path, json_encode($geojson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "Written: $path\n";
}

/**
 * Parse RestrictedAreas.xml and build GeoJSON polygons for each area.
 */
function parseRestrictedAreas(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }
    $xml = simplexml_load_file($file);
    $features = [];
    foreach ($xml->Areas->RestrictedArea as $ra) {
        $coordsText = trim((string)$ra->Area);
        $points = [];
        foreach (explode('/', $coordsText) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            if (!preg_match('/([+-]\d+\.\d+)([+-]\d+\.\d+)/', $pair, $m)) {
                continue;
            }
            $lat = parseCoordinate($m[1]);
            $lon = parseCoordinate($m[2]);
            $points[] = [$lon, $lat];
        }
        if (count($points) < 3) {
            continue;
        }
        $features[] = [
            'type' => 'Feature',
            'properties' => [
                'name' => (string)$ra['Name'],
                'type' => (string)$ra['Type'],
                'floor' => (string)$ra['AltitudeFloor'],
                'ceiling' => (string)$ra['AltitudeCeiling'],
            ],
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [ $points ],
            ],
        ];
    }
    return $features;
}

/**
 * Parse FIR limits maps.  Each FIR_LIMITS.xml contains lines defining
 * boundaries.  This function extracts polygons (if closed) or
 * polylines and returns them as GeoJSON features.
 */
function parseFirLimits(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }
    $xml = simplexml_load_file($file);
    $features = [];
    foreach ($xml->Map->Line as $line) {
        $name = (string)($line['Name'] ?? 'FIR Boundary');
        $coordsText = trim((string)$line);
        $points = [];
        foreach (explode('/', $coordsText) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            if (!preg_match('/([+-]\d+\.\d+)([+-]\d+\.\d+)/', $pair, $m)) {
                continue;
            }
            $lat = parseCoordinate($m[1]);
            $lon = parseCoordinate($m[2]);
            $points[] = [$lon, $lat];
        }
        if (empty($points)) {
            continue;
        }
        $isClosed = ($points[0] === end($points));
        $geomType = $isClosed ? 'Polygon' : 'LineString';
        $geomCoords = $isClosed ? [ $points ] : $points;
        $features[] = [
            'type' => 'Feature',
            'properties' => [ 'name' => $name ],
            'geometry' => [ 'type' => $geomType, 'coordinates' => $geomCoords ],
        ];
    }
    return $features;
}

/**
 * Parse nav data from vatIS navdata repository.  This uses the YAML
 * extension if available; otherwise returns an empty array.  Only
 * airports are extracted as a demonstration.
 */
function parseNavData(string $navPath): array
{
    $features = [];
    if (!function_exists('yaml_parse_file')) {
        return $features;
    }
    $airportFile = $navPath . '/airports.yaml';
    if (file_exists($airportFile)) {
        $airports = yaml_parse_file($airportFile);
        foreach ($airports as $icao => $data) {
            if (!isset($data['lat'], $data['lon'])) {
                continue;
            }
            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'name' => $icao,
                    'type' => 'airport',
                ],
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [ (float)$data['lon'], (float)$data['lat'] ],
                ],
            ];
        }
    }
    // Additional parsing for fixes.yaml and navaids.yaml could be added here
    return $features;
}

// -----------------------------------------------------------------------------
// Additional helpers for parsing generic map files into GeoJSON.  Many of the
// VATMEX XML files share a common structure: one or more <Map> elements
// containing <Line> elements with coordinate pairs in ISO 6709 format
// separated by slashes.  The following functions iterate over these files
// categorising boundaries as TMA, CTR, ATZ, ACC or MVA depending on the file
// name and directory.

/**
 * Parse a generic map XML file and extract line geometries.  Each <Line>
 * element becomes either a LineString or Polygon (if closed).  Returns an
 * array of features with a basic name and optional category.
 */
function parseMapFile(string $file, string $category = null): array
{
    if (!file_exists($file)) {
        return [];
    }
    $xml = simplexml_load_file($file);
    if (!$xml) {
        return [];
    }
    $features = [];
    // Determine a sensible default name from the file name
    $baseName = basename($file, '.xml');
    foreach ($xml->Map as $map) {
        $mapName = (string)($map['Name'] ?? $baseName);
        foreach ($map->Line as $line) {
            $lineName = (string)($line['Name'] ?? $mapName);
            $coordsText = trim((string)$line);
            $points = [];
            foreach (explode('/', $coordsText) as $pair) {
                $pair = trim($pair);
                if ($pair === '') {
                    continue;
                }
                if (!preg_match('/([+-]\d+\.\d+)([+-]\d+\.\d+)/', $pair, $m)) {
                    continue;
                }
                $lat = parseCoordinate($m[1]);
                $lon = parseCoordinate($m[2]);
                $points[] = [$lon, $lat];
            }
            if (empty($points)) {
                continue;
            }
            // Determine if closed
            $isClosed = ($points[0] === end($points));
            $geomType = $isClosed ? 'Polygon' : 'LineString';
            $geomCoords = $isClosed ? [$points] : $points;
            $featProps = ['name' => $lineName];
            if ($category) {
                $featProps['category'] = $category;
            }
            $features[] = [
                'type' => 'Feature',
                'properties' => $featProps,
                'geometry' => [ 'type' => $geomType, 'coordinates' => $geomCoords ],
            ];
        }
    }
    return $features;
}

/**
 * Recursively scan a directory for specific map files by suffix and return
 * an array keyed by category.  The mapping from suffix to category is
 * defined in the array $categories.  Each entry lists the suffix pattern
 * (case sensitive) and the resulting output layer name.  E.g.
 * ['_TMA.xml' => 'tma']
 */
function parseAllMaps(string $mapsDir, array $categories): array
{
    $results = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($mapsDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        $filePath = $fileInfo->getPathname();
        $fileName = $fileInfo->getFilename();
        foreach ($categories as $suffix => $layer) {
            if (substr($fileName, -strlen($suffix)) === $suffix) {
                $features = parseMapFile($filePath, $layer);
                if (!empty($features)) {
                    if (!isset($results[$layer])) {
                        $results[$layer] = [];
                    }
                    $results[$layer] = array_merge($results[$layer], $features);
                }
                break;
            }
        }
    }
    return $results;
}

// 1. Restricted areas
$restricted = parseRestrictedAreas($basePath . '/RestrictedAreas.xml');
if (!empty($restricted)) {
    writeGeoJSON('restricted-areas', $restricted, $outputDir);
}

// 2. FIR limits
$firFeatures = [];
foreach (glob($basePath . '/Maps/*/FIR_LIMITS.xml') as $firFile) {
    $firFeatures = array_merge($firFeatures, parseFirLimits($firFile));
}
if (!empty($firFeatures)) {
    writeGeoJSON('fir-limits', $firFeatures, $outputDir);
}

// 3. Parse system maps for TMA, CTR, ATZ, ACC, MVA.  Suffix mapping can be
// customised here.  We consider any file ending in these suffixes and
// group the output features accordingly.  Additional suffixes can be
// appended to extend coverage.
$suffixMapping = [
    '_TMA.xml' => 'tma',
    '_CTR.xml' => 'ctr',
    '_ATZ.xml' => 'atz',
    '_ACC.xml' => 'acc',
    '_MVA.xml' => 'mva',
    '_MRA.xml' => 'mva',
];
// Additionally include MVA/Minimas lines contained in map names with
// "Minimas" or "Vectoreo" inside the file, assign to MVA layer.  We'll
// search and re‑parse those lines explicitly later.
$mapLayers = parseAllMaps($basePath . '/Maps', $suffixMapping);
foreach ($mapLayers as $layerName => $features) {
    if (!empty($features)) {
        writeGeoJSON($layerName, $features, $outputDir);
    }
}

// 4. Nav data (optional) – expects vatis-mmfr-navdata-main alongside the vatmex directory
$navDataPath = dirname($basePath) . '/vatis-mmfr-navdata-main';
if (is_dir($navDataPath)) {
    $navFeatures = parseNavData($navDataPath);
    if (!empty($navFeatures)) {
        writeGeoJSON('nav-points', $navFeatures, $outputDir);
    }
}

echo "Update complete.\n";
