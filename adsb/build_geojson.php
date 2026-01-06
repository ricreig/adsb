<?php
declare(strict_types=1);

ini_set('memory_limit', '512M');
ini_set('max_execution_time', '600');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
requireAuth($config);

$isCli = PHP_SAPI === 'cli';
$basePath = null;
if ($isCli) {
    if ($argc < 2) {
        fwrite(STDERR, "Usage: php build_geojson.php <path-to-vatmex>\n");
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

$airspacePath = $basePath . '/Airspace.xml';
if (!is_file($airspacePath)) {
    if ($isCli) {
        fwrite(STDERR, "Airspace.xml not found at: {$airspacePath}\n");
        exit(1);
    }
    respond(['error' => 'Airspace.xml not found.', 'path' => $airspacePath], 400);
}

$mapsDir = $basePath . '/Maps/TMA Tijuana';
if (!is_dir($mapsDir)) {
    if ($isCli) {
        fwrite(STDERR, "TMA Tijuana maps directory not found at: {$mapsDir}\n");
        exit(1);
    }
    respond(['error' => 'Maps/TMA Tijuana not found.', 'path' => $mapsDir], 400);
}

$warnings = [];
function logWarning(string $message, array &$warnings): void
{
    $warnings[] = $message;
    error_log($message);
}

function parseDmsToken(string $token, int $degDigits): ?float
{
    $token = trim($token);
    if ($token === '' || !preg_match('/^[+-]/', $token)) {
        return null;
    }
    $sign = $token[0] === '-' ? -1 : 1;
    $body = substr($token, 1);
    $pattern = '/^(\d{' . $degDigits . '})(\d{2})(\d{2}(?:\.\d+)?)$/';
    if (!preg_match($pattern, $body, $m)) {
        return null;
    }
    $deg = (int)$m[1];
    $min = (int)$m[2];
    $sec = (float)$m[3];
    return $sign * ($deg + ($min / 60) + ($sec / 3600));
}

function parseDmsPair(string $pair): ?array
{
    $pair = trim($pair);
    if ($pair === '') {
        return null;
    }
    $len = strlen($pair);
    $splitPos = null;
    for ($i = 1; $i < $len; $i++) {
        $ch = $pair[$i];
        if ($ch === '+' || $ch === '-') {
            $splitPos = $i;
            break;
        }
    }
    if ($splitPos === null) {
        return null;
    }
    $latToken = substr($pair, 0, $splitPos);
    $lonToken = substr($pair, $splitPos);
    $lat = parseDmsToken($latToken, 2);
    $lon = parseDmsToken($lonToken, 3);
    if ($lat === null || $lon === null) {
        return null;
    }
    return [$lon, $lat];
}

function addPointFeature(array &$features, array &$index, string $name, string $type, array $coord): void
{
    $key = strtoupper($name);
    if ($key === '') {
        return;
    }
    $index[$key] = $coord;
    $features[] = [
        'type' => 'Feature',
        'properties' => [
            'name' => $name,
            'type' => $type,
        ],
        'geometry' => [
            'type' => 'Point',
            'coordinates' => $coord,
        ],
    ];
}

function classifyPointType(string $type): ?string
{
    $type = strtoupper(trim($type));
    if ($type === 'FIX') {
        return 'fix';
    }
    if (preg_match('/VOR|NDB|DME|TACAN/', $type)) {
        return 'navaid';
    }
    return null;
}

libxml_use_internal_errors(true);
$airspaceXml = simplexml_load_file($airspacePath);
if ($airspaceXml === false) {
    $message = 'Failed to parse Airspace.xml.';
    if ($isCli) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
    respond(['error' => $message], 500);
}

$navFeatures = [];
$pointsIndex = [];

foreach ($airspaceXml->xpath('//Point') as $point) {
    $typeAttr = (string)$point['Type'];
    $name = trim((string)$point['Name']);
    $pos = trim((string)$point['Position']);
    $mapped = classifyPointType($typeAttr);
    if (!$mapped || $name === '' || $pos === '') {
        continue;
    }
    $coord = parseDmsPair($pos);
    if ($coord === null) {
        logWarning("Unable to parse point position for {$name} ({$typeAttr})", $warnings);
        continue;
    }
    addPointFeature($navFeatures, $pointsIndex, $name, $mapped, $coord);
}

foreach ($airspaceXml->xpath('//Airport') as $airport) {
    $icao = trim((string)$airport['ICAO']);
    $pos = trim((string)$airport['Position']);
    if ($icao === '' || $pos === '') {
        continue;
    }
    $coord = parseDmsPair($pos);
    if ($coord === null) {
        logWarning("Unable to parse airport position for {$icao}", $warnings);
        continue;
    }
    addPointFeature($navFeatures, $pointsIndex, $icao, 'airport', $coord);
}

function extractLineTokens(SimpleXMLElement $line): array
{
    $text = '';
    $attrs = ['Points', 'Coordinates', 'Coords', 'Line', 'Fixes'];
    foreach ($attrs as $attr) {
        if (isset($line[$attr])) {
            $value = trim((string)$line[$attr]);
            if ($value !== '') {
                $text .= ' ' . $value;
            }
        }
    }
    if (trim($text) === '') {
        $text = trim((string)$line);
    }
    $text = str_replace([',', ';'], ' ', $text);
    $tokens = preg_split('/[\s\/]+/', trim($text)) ?: [];
    return array_values(array_filter($tokens, static fn($token) => $token !== ''));
}

function pointsEqual(array $a, array $b): bool
{
    return abs($a[0] - $b[0]) < 1e-6 && abs($a[1] - $b[1]) < 1e-6;
}

function inferProcedureKind(string $label): string
{
    $label = strtolower($label);
    if (strpos($label, 'sid') !== false) {
        return 'sid';
    }
    if (strpos($label, 'star') !== false) {
        return 'star';
    }
    if (strpos($label, 'app') !== false || strpos($label, 'approach') !== false) {
        return 'app';
    }
    if (strpos($label, 'rwy') !== false || strpos($label, 'runway') !== false) {
        return 'rwy';
    }
    return 'other';
}

$tmaFeatures = [];
$procedureFeatures = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($mapsDir, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }
    if (strtolower($fileInfo->getExtension()) !== 'xml') {
        continue;
    }
    $filePath = $fileInfo->getPathname();
    $mapXml = simplexml_load_file($filePath);
    if ($mapXml === false) {
        logWarning("Failed to parse map XML: {$filePath}", $warnings);
        continue;
    }
    $mapNode = $mapXml->Map[0] ?? ($mapXml->getName() === 'Map' ? $mapXml : null);
    $mapName = $mapNode ? trim((string)$mapNode['Name']) : '';
    if ($mapName === '') {
        $mapName = $fileInfo->getBasename('.xml');
    }
    $mapLabel = strtolower($mapName . ' ' . $fileInfo->getBasename());
    $isTma = strpos($mapLabel, 'tma') !== false;
    $kind = $isTma ? 'tma' : inferProcedureKind($mapLabel);

    $lines = $mapXml->xpath('//Line') ?: [];
    foreach ($lines as $line) {
        $lineName = trim((string)$line['Name']);
        if ($lineName === '') {
            $lineName = $mapName;
        }
        $tokens = extractLineTokens($line);
        if (!$tokens) {
            continue;
        }
        $segments = [];
        $current = [];
        foreach ($tokens as $token) {
            $coord = parseDmsPair($token);
            if ($coord !== null) {
                $current[] = $coord;
                continue;
            }
            $key = strtoupper(trim($token));
            if ($key !== '' && isset($pointsIndex[$key])) {
                $current[] = $pointsIndex[$key];
                continue;
            }
            if (count($current) >= 2) {
                $segments[] = $current;
            }
            if ($key !== '') {
                logWarning("Unresolved fix/navaid {$key} in {$fileInfo->getBasename()}", $warnings);
            }
            $current = [];
        }
        if (count($current) >= 2) {
            $segments[] = $current;
        }
        if (!$segments) {
            continue;
        }

        if ($isTma) {
            foreach ($segments as $segment) {
                $geometry = null;
                if (count($segment) >= 3 && pointsEqual($segment[0], $segment[count($segment) - 1])) {
                    $geometry = ['type' => 'Polygon', 'coordinates' => [$segment]];
                } else {
                    $geometry = ['type' => 'LineString', 'coordinates' => $segment];
                }
                $tmaFeatures[] = [
                    'type' => 'Feature',
                    'properties' => [
                        'name' => $lineName,
                        'mapName' => $mapName,
                        'kind' => 'tma',
                    ],
                    'geometry' => $geometry,
                ];
            }
        } else {
            $geometry = null;
            if (count($segments) === 1) {
                $geometry = ['type' => 'LineString', 'coordinates' => $segments[0]];
            } else {
                $geometry = ['type' => 'MultiLineString', 'coordinates' => $segments];
            }
            $procedureFeatures[] = [
                'type' => 'Feature',
                'properties' => [
                    'name' => $lineName,
                    'mapName' => $mapName,
                    'kind' => $kind,
                ],
                'geometry' => $geometry,
            ];
        }
    }
}

function writeGeojsonAtomic(string $path, array $features): void
{
    $geojson = [
        'type' => 'FeatureCollection',
        'features' => $features,
    ];
    $tmp = $path . '.tmp';
    file_put_contents($tmp, json_encode($geojson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename($tmp, $path);
}

$outputDirs = array_filter([
    rtrim($config['geojson_dir'], '/'),
    __DIR__ . '/assets/data',
]);

foreach ($outputDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    writeGeojsonAtomic($dir . '/nav-points.geojson', $navFeatures);
    writeGeojsonAtomic($dir . '/tma-tijuana.geojson', $tmaFeatures);
    writeGeojsonAtomic($dir . '/procedures-tijuana.geojson', $procedureFeatures);
}

$logPath = __DIR__ . '/data/geojson_build.log';
if ($warnings) {
    $lines = array_map(static fn($line) => '[' . gmdate('c') . '] ' . $line, $warnings);
    file_put_contents($logPath, implode(PHP_EOL, $lines) . PHP_EOL, FILE_APPEND);
}

$summary = [
    'navpoints' => count($navFeatures),
    'tma' => count($tmaFeatures),
    'procedures' => count($procedureFeatures),
    'warnings' => $warnings,
];

if ($isCli) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

respond($summary);
