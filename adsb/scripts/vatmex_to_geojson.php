<?php
// [MXAIR2026]
declare(strict_types=1);

ini_set('memory_limit', '512M');
ini_set('max_execution_time', '600');

$config = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../geojson_helpers.php';

$isCli = PHP_SAPI === 'cli';
$importSql = false;
$basePath = null;

if ($isCli) {
    $args = $argv;
    array_shift($args);
    foreach ($args as $arg) {
        if ($arg === '--import-sql') {
            $importSql = true; // [MXAIR2026]
            continue;
        }
        if ($basePath === null) {
            $basePath = $arg;
        }
    }
    if (!$basePath) {
        fwrite(STDERR, "Usage: php scripts/vatmex_to_geojson.php <path-to-vatmex> [--import-sql]\n");
        exit(1);
    }
} else {
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode((string)file_get_contents('php://input'), true);
    $basePath = $payload['path'] ?? null;
    $importSql = !empty($payload['import_sql']); // [MXAIR2026]
}

$basePath = $basePath ? rtrim((string)$basePath, '/\\') : null; // [MXAIR2026]
if (!$basePath || !is_dir($basePath)) {
    $message = 'Provided path does not exist.';
    if ($isCli) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
    echo json_encode(['error' => $message, 'path' => $basePath], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit(1);
}

$outputDir = $config['geojson_dir'] ?? (__DIR__ . '/../data');
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0775, true);
}

libxml_use_internal_errors(true);

function normalizeLayerName(string $filename): string
{
    $base = strtolower(pathinfo($filename, PATHINFO_FILENAME)); // [MXAIR2026]
    $base = preg_replace('/[\s_]+/', '-', $base); // [MXAIR2026]
    $base = preg_replace('/[^a-z0-9\-]/', '-', $base); // [MXAIR2026]
    return trim(preg_replace('/-+/', '-', $base), '-'); // [MXAIR2026]
}

function sanitizeTableName(string $name): ?string
{
    $name = strtolower($name); // [MXAIR2026]
    $name = preg_replace('/[^a-z0-9_]/', '_', $name); // [MXAIR2026]
    $name = preg_replace('/_+/', '_', $name); // [MXAIR2026]
    $name = trim($name, '_'); // [MXAIR2026]
    return $name !== '' ? $name : null; // [MXAIR2026]
}

/**
 * Convert an ISO 6709 coordinate token to decimal degrees.
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

    if (preg_match('/^([+-]?\d+(?:\.\d+)?)([NS])[,\s]+([+-]?\d+(?:\.\d+)?)([EW])$/i', $pair, $m)) {
        $lat = parseCoordinate($m[1] . $m[2]);
        $lon = parseCoordinate($m[3] . $m[4]);
        if ($lat === null || $lon === null) {
            return null;
        }
        return [$lon, $lat];
    }

    if (preg_match('/^([+-]?\d+(?:\.\d+)?)[,\s]+([+-]?\d+(?:\.\d+)?)$/', $pair, $m)) {
        $lat = parseCoordinate($m[1]);
        $lon = parseCoordinate($m[2]);
        if ($lat === null || $lon === null) {
            return null;
        }
        return [$lon, $lat];
    }

    return null;
}

function parseRestrictedAreas(SimpleXMLElement $xml): array
{
    if (!isset($xml->Areas->RestrictedArea)) {
        return [];
    }
    $features = [];
    foreach ($xml->Areas->RestrictedArea as $ra) {
        $coordsText = trim((string)$ra->Area);
        $points = [];
        foreach (explode('/', $coordsText) as $pair) {
            $coord = parseCoordinatePair($pair);
            if ($coord !== null) {
                $points[] = $coord;
            }
        }
        if (count($points) < 3) {
            continue;
        }
        $features[] = [
            'type' => 'Feature',
            'properties' => [
                'name' => (string)$ra['Name'],
                'type' => (string)$ra['Type'],
            ],
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [$points],
            ],
        ];
    }
    return $features;
}

function parseMapFile(SimpleXMLElement $xml, string $baseName): array
{
    if (!isset($xml->Map)) {
        return [];
    }
    $features = [];
    foreach ($xml->Map as $map) {
        $mapName = (string)($map['Name'] ?? $baseName);
        foreach ($map->Line as $line) {
            $lineName = (string)($line['Name'] ?? $mapName);
            $coordsText = trim((string)$line);
            $points = [];
            foreach (explode('/', $coordsText) as $pair) {
                $coord = parseCoordinatePair($pair);
                if ($coord !== null) {
                    $points[] = $coord;
                }
            }
            if (!$points) {
                continue;
            }
            $isClosed = ($points[0] === end($points));
            $geomType = $isClosed ? 'Polygon' : 'LineString';
            $geomCoords = $isClosed ? [$points] : $points;
            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'name' => $lineName,
                ],
                'geometry' => [
                    'type' => $geomType,
                    'coordinates' => $geomCoords,
                ],
            ];
        }
    }
    return $features;
}

function parsePointData(SimpleXMLElement $xml): array
{
    $features = [];
    foreach ($xml->xpath('//Point') as $point) {
        $name = trim((string)$point['Name']);
        $pos = trim((string)$point['Position']);
        if ($name === '' || $pos === '') {
            continue;
        }
        $coord = parseCoordinatePair($pos);
        if ($coord === null) {
            continue;
        }
        $features[] = [
            'type' => 'Feature',
            'properties' => [
                'name' => $name,
                'type' => (string)($point['Type'] ?? ''),
            ],
            'geometry' => [
                'type' => 'Point',
                'coordinates' => $coord,
            ],
        ];
    }
    foreach ($xml->xpath('//Airport') as $airport) {
        $icao = trim((string)$airport['ICAO']);
        $pos = trim((string)$airport['Position']);
        if ($icao === '' || $pos === '') {
            continue;
        }
        $coord = parseCoordinatePair($pos);
        if ($coord === null) {
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
                'coordinates' => $coord,
            ],
        ];
    }
    return $features;
}

function convertXmlToGeojson(string $filePath): ?array
{
    $xml = simplexml_load_file($filePath);
    if ($xml === false) {
        return null;
    }
    $baseName = pathinfo($filePath, PATHINFO_FILENAME);
    $features = parseRestrictedAreas($xml); // [MXAIR2026]
    if (!$features) {
        $features = parseMapFile($xml, $baseName); // [MXAIR2026]
    }
    if (!$features) {
        $features = parsePointData($xml); // [MXAIR2026]
    }
    if (!$features) {
        return null;
    }
    $geojson = [
        'type' => 'FeatureCollection',
        'features' => $features,
    ];
    return normalizeGeojson($geojson);
}

function insertGeojsonLayer(PDO $pdo, string $table, array $features): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT, geometria TEXT)"); // [MXAIR2026]
    $pdo->exec("DELETE FROM {$table}"); // [MXAIR2026]
    $stmt = $pdo->prepare("INSERT INTO {$table} (nombre, geometria) VALUES (:nombre, :geometria)");
    foreach ($features as $feature) {
        $props = $feature['properties'] ?? [];
        $name = $props['name'] ?? $props['Name'] ?? $props['ident'] ?? '';
        $geometry = $feature['geometry'] ?? null;
        if (!$geometry) {
            continue;
        }
        $stmt->execute([
            ':nombre' => $name,
            ':geometria' => json_encode($geometry, JSON_UNESCAPED_SLASHES),
        ]);
    }
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS)
);

$results = [];
$tablesLoaded = [];
$pdo = null;

if ($importSql) {
    $dbPath = $config['settings_db'] ?? (__DIR__ . '/../data/adsb.sqlite');
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }
    if (strtolower($fileInfo->getExtension()) !== 'xml') {
        continue;
    }
    $filePath = $fileInfo->getPathname();
    $layerName = normalizeLayerName($fileInfo->getFilename()); // [MXAIR2026]
    if ($layerName === '') {
        continue;
    }
    $geojson = convertXmlToGeojson($filePath);
    if ($geojson === null) {
        $results[] = ['file' => $filePath, 'status' => 'skipped'];
        continue;
    }
    $outputPath = rtrim($outputDir, '/\\') . '/' . $layerName . '.geojson'; // [MXAIR2026]
    file_put_contents($outputPath, json_encode($geojson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $results[] = ['file' => $filePath, 'output' => $outputPath, 'features' => count($geojson['features'] ?? [])];

    if ($pdo) {
        $table = sanitizeTableName($layerName); // [MXAIR2026]
        if ($table) {
            insertGeojsonLayer($pdo, $table, $geojson['features'] ?? []); // [MXAIR2026]
            $tablesLoaded[] = $table;
        }
    }
}

if ($isCli) {
    foreach ($results as $entry) {
        $line = $entry['file'] . ' -> ' . ($entry['output'] ?? 'skipped');
        if (isset($entry['features'])) {
            $line .= ' (' . $entry['features'] . ' features)';
        }
        echo $line . "\n";
    }
    if ($pdo) {
        echo "Imported tables: " . implode(', ', array_unique($tablesLoaded)) . "\n";
    }
    exit(0);
}

echo json_encode([
    'ok' => true,
    'import_sql' => $importSql,
    'results' => $results,
    'tables' => array_values(array_unique($tablesLoaded)),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
