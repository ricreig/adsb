<?php
declare(strict_types=1);
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
require __DIR__ . '/auth.php';
requireAuth($config);

$isCli = PHP_SAPI === 'cli';
$basePath = null;
if ($isCli) {
    if ($argc < 2) {
        fwrite(STDERR, "Usage: php update_airspace.php <path-to-vatmex>\n");
        exit(1);
    }
    $basePath = rtrim($argv[1], '/');
} else {
    header('Content-Type: application/json; charset=utf-8');
    $basePath = isset($_GET['path']) ? trim((string)$_GET['path']) : null;
    if (!$basePath) {
        $payload = json_decode((string)file_get_contents('php://input'), true);
        if (is_array($payload) && !empty($payload['path'])) {
            $basePath = trim((string)$payload['path']);
        }
    }
}

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!$basePath || !is_dir($basePath)) {
    if ($isCli) {
        fwrite(STDERR, "Provided path does not exist: {$basePath}\n");
        exit(1);
    }
    respond(['error' => 'Provided path does not exist.', 'path' => $basePath], 400);
}

$outputDir = $config['geojson_dir'];
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}

/**
 * Convert an ISO 6709 coordinate token to decimal degrees. Supports:
 *  - ±DD.DDDD / ±DDD.DDDD (degrees)
 *  - ±DDMM.M / ±DDDMM.M (degrees + minutes)
 *  - ±DDMMSS.S / ±DDDMMSS.S (degrees + minutes + seconds)
 *  - Optional hemisphere suffix (N/S/E/W)
 */
function parseCoordinate(string $coord): ?float
{
    $coord = trim($coord);
    if ($coord === '') {
        return null;
    }

    $sign = 1;
    $hemisphere = null;
    if (preg_match('/[NSEW]$/i', $coord)) {
        $hemisphere = strtoupper(substr($coord, -1));
        $coord = substr($coord, 0, -1);
    }
    if (isset($coord[0]) && ($coord[0] === '-' || $coord[0] === '+')) {
        $sign = ($coord[0] === '-') ? -1 : 1;
        $coord = substr($coord, 1);
    }
    if ($hemisphere !== null) {
        if ($hemisphere === 'S' || $hemisphere === 'W') {
            $sign = -1;
        } else {
            $sign = 1;
        }
    }

    if (!preg_match('/^\d+(?:\.\d+)?$/', $coord)) {
        return null;
    }

    [$whole, $fraction] = array_pad(explode('.', $coord, 2), 2, '');
    $len = strlen($whole);
    $value = 0.0;

    if ($len <= 3) {
        $value = (float)$whole;
        if ($fraction !== '') {
            $value += (float)('0.' . $fraction);
        }
    } elseif ($len <= 5) {
        $degLen = $len - 2;
        $deg = (int)substr($whole, 0, $degLen);
        $min = (int)substr($whole, -2);
        $minFrac = $fraction !== '' ? (float)('0.' . $fraction) : 0.0;
        $value = $deg + ($min + $minFrac) / 60;
    } else {
        $degLen = $len - 4;
        $deg = (int)substr($whole, 0, $degLen);
        $min = (int)substr($whole, $degLen, 2);
        $sec = (int)substr($whole, -2);
        $secFrac = $fraction !== '' ? (float)('0.' . $fraction) : 0.0;
        $value = $deg + ($min / 60) + (($sec + $secFrac) / 3600);
    }

    return $sign * $value;
}

/**
 * Parse a coordinate pair text token into a [lon, lat] array.
 */
function parseCoordinatePair(string $pair): ?array
{
    $pair = trim($pair);
    if ($pair === '') {
        return null;
    }

    if (preg_match('/^[+-]\d+/', $pair) && preg_match('/[+-]\d+$/', $pair)) {
        $secondSign = strpos($pair, '+', 1);
        $minusPos = strpos($pair, '-', 1);
        if ($secondSign === false || ($minusPos !== false && $minusPos < $secondSign)) {
            $secondSign = $minusPos;
        }
        if ($secondSign !== false) {
            $latToken = substr($pair, 0, $secondSign);
            $lonToken = substr($pair, $secondSign);
            $lat = parseCoordinate($latToken);
            $lon = parseCoordinate($lonToken);
            if ($lat !== null && $lon !== null) {
                return [$lon, $lat];
            }
        }
    }

    if (preg_match('/^([+-]\d+(?:\.\d+)?)([+-]\d+(?:\.\d+)?)$/', $pair, $m)) {
        $lat = parseCoordinate($m[1]);
        $lon = parseCoordinate($m[2]);
        if ($lat === null || $lon === null) {
            return null;
        }
        return [$lon, $lat];
    }

    if (preg_match('/^([+-]?\d+(?:\.\d+)?)([NS])[,\\s]+([+-]?\d+(?:\.\d+)?)([EW])$/i', $pair, $m)) {
        $lat = parseCoordinate($m[1] . $m[2]);
        $lon = parseCoordinate($m[3] . $m[4]);
        if ($lat === null || $lon === null) {
            return null;
        }
        return [$lon, $lat];
    }

    if (preg_match('/^([+-]?\d+(?:\.\d+)?)[,\\s]+([+-]?\d+(?:\.\d+)?)$/', $pair, $m)) {
        $lat = parseCoordinate($m[1]);
        $lon = parseCoordinate($m[2]);
        if ($lat === null || $lon === null) {
            return null;
        }
        return [$lon, $lat];
    }

    return null;
}

/**
 * Write a GeoJSON collection to the output directory.
 */
function writeGeoJSON(string $name, array $features, string $outputDir): void
{
    foreach ($features as &$feature) {
        if (!isset($feature['geometry']['coordinates'])) {
            continue;
        }
        $feature['geometry']['coordinates'] = normalizeCoordinates($feature['geometry']['coordinates']);
    }
    unset($feature);
    $geojson = [
        'type' => 'FeatureCollection',
        'features' => $features,
    ];
    $path = $outputDir . '/' . $name . '.geojson';
    file_put_contents($path, json_encode($geojson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "Written: $path\n";
}

/**
 * Normalize coordinates to valid lat/lon ranges by scaling down
 * malformed values (e.g., 204.1683 => 20.41683).
 */
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

function normalizeDegree(float $value, float $limit): float
{
    $abs = abs($value);
    while ($abs > $limit && $abs <= ($limit * 10)) {
        $value /= 10;
        $abs = abs($value);
    }
    return $value;
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
            $coord = parseCoordinatePair($pair);
            if ($coord === null) {
                continue;
            }
            $points[] = $coord;
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
            $coord = parseCoordinatePair($pair);
            if ($coord === null) {
                continue;
            }
            $points[] = $coord;
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
    $fixFile = $navPath . '/fixes.yaml';
    if (file_exists($fixFile)) {
        $fixes = yaml_parse_file($fixFile);
        foreach ($fixes as $name => $data) {
            if (!isset($data['lat'], $data['lon'])) {
                continue;
            }
            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'name' => (string)$name,
                    'type' => 'fix',
                ],
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [ (float)$data['lon'], (float)$data['lat'] ],
                ],
            ];
        }
    }
    $navaidFile = $navPath . '/navaids.yaml';
    if (file_exists($navaidFile)) {
        $navaids = yaml_parse_file($navaidFile);
        foreach ($navaids as $name => $data) {
            if (!isset($data['lat'], $data['lon'])) {
                continue;
            }
            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'name' => (string)$name,
                    'type' => 'navaid',
                    'ident' => $data['ident'] ?? null,
                ],
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [ (float)$data['lon'], (float)$data['lat'] ],
                ],
            ];
        }
    }
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
function parseMapFile(string $file, ?string $category = null): array
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
                $coord = parseCoordinatePair($pair);
                if ($coord === null) {
                    continue;
                }
                $points[] = $coord;
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
 * Recursively scan a directory for map files and return an array keyed by
 * category.  The mapping from filename patterns to category names is
 * defined in $categoryMatchers. Each entry lists regex patterns to test
 * against the file name.
 */
function parseAllMaps(string $mapsDir, array $categoryMatchers): array
{
    $results = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($mapsDir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        $filePath = $fileInfo->getPathname();
        $fileName = $fileInfo->getFilename();
        $layer = null;
        foreach ($categoryMatchers as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $fileName)) {
                    $layer = $category;
                    break 2;
                }
            }
        }
        if ($layer === null) {
            continue;
        }
        $features = parseMapFile($filePath, $layer);
        if (!empty($features)) {
            if (!isset($results[$layer])) {
                $results[$layer] = [];
            }
            $results[$layer] = array_merge($results[$layer], $features);
        }
    }
    return $results;
}

/**
 * Build catalog metadata for generated GeoJSON layers.
 */
function buildCatalog(string $outputDir, array $metadata = []): array
{
    $files = glob($outputDir . '/*.geojson');
    $layers = [];
    $overallBbox = null;
    foreach ($files as $file) {
        $stats = geojsonStats($file);
        if ($stats === null) {
            continue;
        }
        $layers[$stats['name']] = $stats;
        if ($stats['bbox'] !== null) {
            if ($overallBbox === null) {
                $overallBbox = $stats['bbox'];
            } else {
                $overallBbox = [
                    min($overallBbox[0], $stats['bbox'][0]),
                    min($overallBbox[1], $stats['bbox'][1]),
                    max($overallBbox[2], $stats['bbox'][2]),
                    max($overallBbox[3], $stats['bbox'][3]),
                ];
            }
        }
    }

    $catalog = array_merge([
        'generated_at' => gmdate('c'),
        'bbox' => $overallBbox,
        'layers' => $layers,
    ], $metadata);

    $path = $outputDir . '/catalog.json';
    file_put_contents($path, json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "Written: $path\n";

    return $catalog;
}

function ensureAirspaceDb(string $outputDir): ?PDO
{
    $dbPath = rtrim($outputDir, '/\\') . '/airspace.sqlite';
    try {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE IF NOT EXISTS airspace_layers (
            name TEXT PRIMARY KEY,
            features INTEGER NOT NULL,
            coords INTEGER NOT NULL,
            bytes INTEGER NOT NULL,
            bbox TEXT,
            updated_at TEXT NOT NULL
        )');
        return $pdo;
    } catch (Throwable $e) {
        return null;
    }
}

function persistCatalogToDb(PDO $pdo, array $layers): void
{
    $stmt = $pdo->prepare('INSERT INTO airspace_layers (name, features, coords, bytes, bbox, updated_at)
        VALUES (:name, :features, :coords, :bytes, :bbox, :updated_at)
        ON CONFLICT(name) DO UPDATE SET features = excluded.features, coords = excluded.coords, bytes = excluded.bytes, bbox = excluded.bbox, updated_at = excluded.updated_at');
    foreach ($layers as $layer) {
        $stmt->execute([
            ':name' => $layer['name'],
            ':features' => (int)$layer['features'],
            ':coords' => (int)$layer['coords'],
            ':bytes' => (int)$layer['bytes'],
            ':bbox' => $layer['bbox'] ? json_encode($layer['bbox'], JSON_UNESCAPED_SLASHES) : null,
            ':updated_at' => gmdate('c'),
        ]);
    }
}

function geojsonStats(string $path): ?array
{
    $data = json_decode(file_get_contents($path), true);
    if (!is_array($data)) {
        return null;
    }
    $features = $data['features'] ?? [];
    $bbox = null;
    $coords = 0;
    foreach ($features as $feature) {
        $geometry = $feature['geometry'] ?? null;
        if (!is_array($geometry)) {
            continue;
        }
        $coords += updateBboxFromCoords($geometry['coordinates'] ?? null, $bbox);
    }
    $name = basename($path, '.geojson');
    return [
        'name' => $name,
        'file' => basename($path),
        'features' => count($features),
        'coords' => $coords,
        'bytes' => filesize($path),
        'bbox' => $bbox,
    ];
}

function updateBboxFromCoords($coords, ?array &$bbox): int
{
    $count = 0;
    if (!is_array($coords)) {
        return $count;
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
        return 1;
    }
    foreach ($coords as $item) {
        $count += updateBboxFromCoords($item, $bbox);
    }
    return $count;
}

function detectAirac(string $basePath): ?string
{
    $candidates = [
        $basePath . '/AIRAC.txt',
        $basePath . '/airac.txt',
        $basePath . '/README.md',
        $basePath . '/metadata.json',
    ];
    foreach ($candidates as $candidate) {
        if (!file_exists($candidate)) {
            continue;
        }
        $contents = file_get_contents($candidate);
        if ($contents && preg_match('/AIRAC\\s*[:#-]?\\s*([0-9]{4,6})/i', $contents, $m)) {
            return $m[1];
        }
    }
    return null;
}

function detectVatmexCommit(string $basePath): ?string
{
    $gitDir = $basePath . '/.git';
    if (!is_dir($gitDir)) {
        return null;
    }
    $commit = trim((string)shell_exec('git -C ' . escapeshellarg($basePath) . ' rev-parse --short HEAD 2>/dev/null'));
    return $commit !== '' ? $commit : null;
}

function runGeojsonValidation(string $rootDir): array
{
    $command = sprintf(
        '%s %s',
        escapeshellcmd(PHP_BINARY),
        escapeshellarg($rootDir . '/scripts/validate_geojson.php')
    );
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $rootDir);
    if (!is_resource($process)) {
        return [
            'ok' => false,
            'exit_code' => 1,
            'stdout' => '',
            'stderr' => 'Failed to start validate_geojson.php',
            'out_of_range' => null,
        ];
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $outOfRange = 0;
    if ($stdout) {
        $lines = preg_split('/\r?\n/', trim($stdout));
        foreach ($lines as $idx => $line) {
            if ($idx === 0 || $line === '') {
                continue;
            }
            if (str_contains($line, ',')) {
                $parts = str_getcsv($line);
                $value = $parts[3] ?? null;
                if (is_numeric($value)) {
                    $outOfRange += (int)$value;
                }
            }
        }
    }

    return [
        'ok' => $exitCode === 0,
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'out_of_range' => $outOfRange,
    ];
}

$validation = null;

try {
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

    // 3. Parse system maps for additional categories beyond the classic
    // TMA/CTR/ATZ/ACC/MVA/MRA suffixes.  We scan all map files and classify
    // them using regex patterns on the file name to expand coverage.
    $categoryMatchers = [
        'tma' => ['/_TMA/i', '/\\bTMA\\b/i'],
        'ctr' => ['/_CTR/i', '/\\bCTR\\b/i'],
        'atz' => ['/_ATZ/i', '/\\bATZ\\b/i'],
        'acc' => ['/_ACC/i', '/\\bACC\\b/i'],
        'cta' => ['/_CTA/i', '/\\bCTA\\b/i'],
        'fir' => ['/_FIR/i', '/\\bFIR\\b/i'],
        'mva' => ['/_MVA/i', '/_MRA/i', '/\\bMVA\\b/i', '/\\bMRA\\b/i', '/MINIMAS/i', '/VECTOREO/i'],
        'airways' => ['/_AWY/i', '/\\bAIRWAY\\b/i', '/_UTA/i', '/_LTA/i', '/_UIR/i'],
        'procedures' => ['/_SID/i', '/_STAR/i', '/_APP/i', '/\\bSID\\b/i', '/\\bSTAR\\b/i', '/\\bAPP\\b/i'],
        'vfr' => ['/_VFR/i', '/\\bVFR\\b/i', '/CORRIDOR/i'],
        'sectors' => ['/_SECTOR/i', '/SECTOR/i'],
    ];
    $mapLayers = parseAllMaps($basePath . '/Maps', $categoryMatchers);
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

    // 5. Catalog metadata
    $metadata = [
        'airac' => detectAirac($basePath),
        'vatmex_commit' => detectVatmexCommit($basePath),
    ];
    $catalog = buildCatalog($outputDir, $metadata);
    $pdo = ensureAirspaceDb($outputDir);
    if ($pdo) {
        persistCatalogToDb($pdo, array_values($catalog['layers'] ?? []));
    }
    $validation = runGeojsonValidation(__DIR__);
    if (!$validation['ok'] || ($validation['out_of_range'] !== null && $validation['out_of_range'] > 0)) {
        throw new RuntimeException('GeoJSON validation failed. Out-of-range coordinates detected.');
    }

    if ($isCli) {
        echo "Update complete.\n";
    } else {
        respond([
            'ok' => true,
            'catalog' => $catalog,
            'output_dir' => $outputDir,
            'validation' => $validation,
        ]);
    }
} catch (Throwable $e) {
    if ($isCli) {
        fwrite(STDERR, "Error: {$e->getMessage()}\n");
        exit(1);
    }
    $payload = ['ok' => false, 'error' => $e->getMessage()];
    if ($validation !== null) {
        $payload['validation'] = $validation;
    }
    respond($payload, 500);
}
