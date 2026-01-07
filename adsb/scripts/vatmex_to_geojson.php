<?php
// [MXAIR2026]
declare(strict_types=1); // [MXAIR2026-ROLL]

ini_set('memory_limit', '512M'); // [MXAIR2026-ROLL]
ini_set('max_execution_time', '600'); // [MXAIR2026-ROLL]

$config = require __DIR__ . '/../config.php'; // [MXAIR2026-ROLL]
require_once __DIR__ . '/../geojson_helpers.php'; // [MXAIR2026-ROLL]

$isCli = PHP_SAPI === 'cli'; // [MXAIR2026-ROLL]
$importSql = false; // [MXAIR2026-ROLL]
$basePath = null; // [MXAIR2026-ROLL]

if ($isCli) { // [MXAIR2026-ROLL]
    $args = $argv; // [MXAIR2026-ROLL]
    array_shift($args); // [MXAIR2026-ROLL]
    foreach ($args as $arg) { // [MXAIR2026-ROLL]
        if ($arg === '--import-sql') { // [MXAIR2026-ROLL]
            $importSql = true; // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        if ($basePath === null) { // [MXAIR2026-ROLL]
            $basePath = $arg; // [MXAIR2026-ROLL]
        }
    }
    if (!$basePath) { // [MXAIR2026-ROLL]
        fwrite(STDERR, "Usage: php scripts/vatmex_to_geojson.php <path-to-vatmex> [--import-sql]\n"); // [MXAIR2026-ROLL]
        exit(1); // [MXAIR2026-ROLL]
    }
} else { // [MXAIR2026-ROLL]
    header('Content-Type: application/json; charset=utf-8'); // [MXAIR2026-ROLL]
    $payload = json_decode((string)file_get_contents('php://input'), true); // [MXAIR2026-ROLL]
    $basePath = $payload['path'] ?? null; // [MXAIR2026-ROLL]
    $importSql = !empty($payload['import_sql']); // [MXAIR2026-ROLL]
} // [MXAIR2026-ROLL]

$basePath = $basePath ? rtrim((string)$basePath, '/\\') : null; // [MXAIR2026-ROLL]
if (!$basePath || !is_dir($basePath)) { // [MXAIR2026-ROLL]
    $message = 'Provided path does not exist.'; // [MXAIR2026-ROLL]
    if ($isCli) { // [MXAIR2026-ROLL]
        fwrite(STDERR, $message . "\n"); // [MXAIR2026-ROLL]
        exit(1); // [MXAIR2026-ROLL]
    }
    echo json_encode(['error' => $message, 'path' => $basePath], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); // [MXAIR2026-ROLL]
    exit(1); // [MXAIR2026-ROLL]
}

$outputDir = $config['geojson_dir'] ?? (__DIR__ . '/../data'); // [MXAIR2026-ROLL]
if (!is_dir($outputDir)) { // [MXAIR2026-ROLL]
    mkdir($outputDir, 0775, true); // [MXAIR2026-ROLL]
}

libxml_use_internal_errors(true); // [MXAIR2026-ROLL]

$warnings = []; // [MXAIR2026-ROLL]
$logs = []; // [MXAIR2026-ROLL]

function logVatmex(string $message, array &$logs): void
{ // [MXAIR2026-ROLL]
    $logs[] = '[' . gmdate('c') . '] ' . $message; // [MXAIR2026-ROLL]
    error_log($message); // [MXAIR2026-ROLL]
}

function warnVatmex(string $message, array &$warnings): void
{ // [MXAIR2026-ROLL]
    $warnings[] = $message; // [MXAIR2026-ROLL]
    error_log($message); // [MXAIR2026-ROLL]
}

function resolveXmlPath(string $basePath, array $candidates): ?string
{ // [MXAIR2026-ROLL]
    foreach ($candidates as $candidate) { // [MXAIR2026-ROLL]
        $path = rtrim($basePath, '/\\') . '/' . ltrim($candidate, '/\\'); // [MXAIR2026-ROLL]
        if (is_file($path)) { // [MXAIR2026-ROLL]
            return $path; // [MXAIR2026-ROLL]
        }
    }
    $dir = dirname(rtrim($basePath, '/\\') . '/' . ltrim($candidates[0], '/\\')); // [MXAIR2026-ROLL]
    $name = basename($candidates[0]); // [MXAIR2026-ROLL]
    if (is_dir($dir)) { // [MXAIR2026-ROLL]
        foreach (scandir($dir) as $entry) { // [MXAIR2026-ROLL]
            if (strcasecmp($entry, $name) === 0) { // [MXAIR2026-ROLL]
                $path = $dir . '/' . $entry; // [MXAIR2026-ROLL]
                if (is_file($path)) { // [MXAIR2026-ROLL]
                    return $path; // [MXAIR2026-ROLL]
                }
            }
        }
    }
    return null; // [MXAIR2026-ROLL]
}

function parseCoordinate(string $coord): ?float
{ // [MXAIR2026-ROLL]
    $coord = trim($coord); // [MXAIR2026-ROLL]
    if ($coord === '') { // [MXAIR2026-ROLL]
        return null; // [MXAIR2026-ROLL]
    }

    $sign = 1; // [MXAIR2026-ROLL]
    $hemisphere = null; // [MXAIR2026-ROLL]
    if (preg_match('/[NSEW]$/i', $coord)) { // [MXAIR2026-ROLL]
        $hemisphere = strtoupper(substr($coord, -1)); // [MXAIR2026-ROLL]
        $coord = substr($coord, 0, -1); // [MXAIR2026-ROLL]
    }
    if (isset($coord[0]) && ($coord[0] === '-' || $coord[0] === '+')) { // [MXAIR2026-ROLL]
        $sign = ($coord[0] === '-') ? -1 : 1; // [MXAIR2026-ROLL]
        $coord = substr($coord, 1); // [MXAIR2026-ROLL]
    }
    if ($hemisphere !== null) { // [MXAIR2026-ROLL]
        if ($hemisphere === 'S' || $hemisphere === 'W') { // [MXAIR2026-ROLL]
            $sign = -1; // [MXAIR2026-ROLL]
        }
    }

    if (!preg_match('/^\d+(?:\.\d+)?$/', $coord)) { // [MXAIR2026-ROLL]
        return null; // [MXAIR2026-ROLL]
    }

    [$whole, $fraction] = array_pad(explode('.', $coord, 2), 2, ''); // [MXAIR2026-ROLL]
    $len = strlen($whole); // [MXAIR2026-ROLL]
    $value = 0.0; // [MXAIR2026-ROLL]

    if ($len <= 3) { // [MXAIR2026-ROLL]
        $value = (float)$whole; // [MXAIR2026-ROLL]
        if ($fraction !== '') { // [MXAIR2026-ROLL]
            $value += (float)('0.' . $fraction); // [MXAIR2026-ROLL]
        }
    } elseif ($len <= 5) { // [MXAIR2026-ROLL]
        $degLen = $len - 2; // [MXAIR2026-ROLL]
        $deg = (int)substr($whole, 0, $degLen); // [MXAIR2026-ROLL]
        $min = (int)substr($whole, -2); // [MXAIR2026-ROLL]
        $minFrac = $fraction !== '' ? (float)('0.' . $fraction) : 0.0; // [MXAIR2026-ROLL]
        $value = $deg + ($min + $minFrac) / 60; // [MXAIR2026-ROLL]
    } else { // [MXAIR2026-ROLL]
        $degLen = $len - 4; // [MXAIR2026-ROLL]
        $deg = (int)substr($whole, 0, $degLen); // [MXAIR2026-ROLL]
        $min = (int)substr($whole, $degLen, 2); // [MXAIR2026-ROLL]
        $sec = (int)substr($whole, -2); // [MXAIR2026-ROLL]
        $secFrac = $fraction !== '' ? (float)('0.' . $fraction) : 0.0; // [MXAIR2026-ROLL]
        $value = $deg + ($min / 60) + (($sec + $secFrac) / 3600); // [MXAIR2026-ROLL]
    }

    return $sign * $value; // [MXAIR2026-ROLL]
}

function parseDmsToken(string $token, int $degDigits): ?float
{ // [MXAIR2026-ROLL]
    $token = trim($token); // [MXAIR2026-ROLL]
    if ($token === '' || !preg_match('/^[+-]/', $token)) { // [MXAIR2026-ROLL]
        return null; // [MXAIR2026-ROLL]
    }
    $sign = $token[0] === '-' ? -1 : 1; // [MXAIR2026-ROLL]
    $body = substr($token, 1); // [MXAIR2026-ROLL]
    $pattern = '/^(\d{' . $degDigits . '})(\d{2})(\d{2}(?:\.\d+)?)$/'; // [MXAIR2026-ROLL]
    if (!preg_match($pattern, $body, $m)) { // [MXAIR2026-ROLL]
        return null; // [MXAIR2026-ROLL]
    }
    $deg = (int)$m[1]; // [MXAIR2026-ROLL]
    $min = (int)$m[2]; // [MXAIR2026-ROLL]
    $sec = (float)$m[3]; // [MXAIR2026-ROLL]
    return $sign * ($deg + ($min / 60) + ($sec / 3600)); // [MXAIR2026-ROLL]
}

function parseDmsPair(string $pair): ?array
{ // [MXAIR2026-ROLL]
    $pair = trim($pair); // [MXAIR2026-ROLL]
    if ($pair === '') { // [MXAIR2026-ROLL]
        return null; // [MXAIR2026-ROLL]
    }
    $len = strlen($pair); // [MXAIR2026-ROLL]
    $splitPos = null; // [MXAIR2026-ROLL]
    for ($i = 1; $i < $len; $i++) { // [MXAIR2026-ROLL]
        $ch = $pair[$i]; // [MXAIR2026-ROLL]
        if ($ch === '+' || $ch === '-') { // [MXAIR2026-ROLL]
            $splitPos = $i; // [MXAIR2026-ROLL]
            break; // [MXAIR2026-ROLL]
        }
    }
    if ($splitPos === null) { // [MXAIR2026-ROLL]
        return null; // [MXAIR2026-ROLL]
    }
    $latToken = substr($pair, 0, $splitPos); // [MXAIR2026-ROLL]
    $lonToken = substr($pair, $splitPos); // [MXAIR2026-ROLL]
    $lat = parseDmsToken($latToken, 2); // [MXAIR2026-ROLL]
    $lon = parseDmsToken($lonToken, 3); // [MXAIR2026-ROLL]
    if ($lat === null || $lon === null) { // [MXAIR2026-ROLL]
        return null; // [MXAIR2026-ROLL]
    }
    return ensureLonLatPair($lat, $lon); // [MXAIR2026-ROLL]
}

function parseCoordinatePair(string $pair): ?array
{ // [MXAIR2026-ROLL]
    $pair = trim($pair); // [MXAIR2026-ROLL]
    if ($pair === '') { // [MXAIR2026-ROLL]
        return null; // [MXAIR2026-ROLL]
    }

    if (preg_match('/^[+-]\d+/', $pair) && preg_match('/[+-]\d+$/', $pair)) { // [MXAIR2026-ROLL]
        $secondSign = strpos($pair, '+', 1); // [MXAIR2026-ROLL]
        $minusPos = strpos($pair, '-', 1); // [MXAIR2026-ROLL]
        if ($secondSign === false || ($minusPos !== false && $minusPos < $secondSign)) { // [MXAIR2026-ROLL]
            $secondSign = $minusPos; // [MXAIR2026-ROLL]
        }
        if ($secondSign !== false) { // [MXAIR2026-ROLL]
            $latToken = substr($pair, 0, $secondSign); // [MXAIR2026-ROLL]
            $lonToken = substr($pair, $secondSign); // [MXAIR2026-ROLL]
            $lat = parseCoordinate($latToken); // [MXAIR2026-ROLL]
            $lon = parseCoordinate($lonToken); // [MXAIR2026-ROLL]
            if ($lat !== null && $lon !== null) { // [MXAIR2026-ROLL]
                return ensureLonLatPair($lat, $lon); // [MXAIR2026-ROLL]
            }
        }
    }

    if (preg_match('/^([+-]\d+(?:\.\d+)?)([+-]\d+(?:\.\d+)?)$/', $pair, $m)) { // [MXAIR2026-ROLL]
        $lat = parseCoordinate($m[1]); // [MXAIR2026-ROLL]
        $lon = parseCoordinate($m[2]); // [MXAIR2026-ROLL]
        if ($lat === null || $lon === null) { // [MXAIR2026-ROLL]
            return null; // [MXAIR2026-ROLL]
        }
        return ensureLonLatPair($lat, $lon); // [MXAIR2026-ROLL]
    }

    if (preg_match('/^([+-]?\d+(?:\.\d+)?)([NS])[,\s]+([+-]?\d+(?:\.\d+)?)([EW])$/i', $pair, $m)) { // [MXAIR2026-ROLL]
        $lat = parseCoordinate($m[1] . $m[2]); // [MXAIR2026-ROLL]
        $lon = parseCoordinate($m[3] . $m[4]); // [MXAIR2026-ROLL]
        if ($lat === null || $lon === null) { // [MXAIR2026-ROLL]
            return null; // [MXAIR2026-ROLL]
        }
        return ensureLonLatPair($lat, $lon); // [MXAIR2026-ROLL]
    }

    if (preg_match('/^([+-]?\d+(?:\.\d+)?)[,\s]+([+-]?\d+(?:\.\d+)?)$/', $pair, $m)) { // [MXAIR2026-ROLL]
        $lat = parseCoordinate($m[1]); // [MXAIR2026-ROLL]
        $lon = parseCoordinate($m[2]); // [MXAIR2026-ROLL]
        if ($lat === null || $lon === null) { // [MXAIR2026-ROLL]
            return null; // [MXAIR2026-ROLL]
        }
        return ensureLonLatPair($lat, $lon); // [MXAIR2026-ROLL]
    }

    $dms = parseDmsPair($pair); // [MXAIR2026-ROLL]
    if ($dms !== null) { // [MXAIR2026-ROLL]
        return $dms; // [MXAIR2026-ROLL]
    }

    return null; // [MXAIR2026-ROLL]
}

function ensureLonLatPair(float $lat, float $lon): ?array
{ // [MXAIR2026-ROLL]
    if (isValidLatValue($lat) && isValidLonValue($lon)) { // [MXAIR2026-ROLL]
        return [$lon, $lat]; // [MXAIR2026-ROLL]
    }
    if (isValidLatValue($lon) && isValidLonValue($lat)) { // [MXAIR2026-ROLL]
        return [$lat, $lon]; // [MXAIR2026-ROLL]
    }
    return null; // [MXAIR2026-ROLL]
}

function extractLineTokens(SimpleXMLElement $line): array
{ // [MXAIR2026-ROLL]
    $text = ''; // [MXAIR2026-ROLL]
    $attrs = ['Points', 'Coordinates', 'Coords', 'Line', 'Fixes', 'Route', 'Path']; // [MXAIR2026-ROLL]
    foreach ($attrs as $attr) { // [MXAIR2026-ROLL]
        if (isset($line[$attr])) { // [MXAIR2026-ROLL]
            $value = trim((string)$line[$attr]); // [MXAIR2026-ROLL]
            if ($value !== '') { // [MXAIR2026-ROLL]
                $text .= ' ' . $value; // [MXAIR2026-ROLL]
            }
        }
    }
    if (trim($text) === '') { // [MXAIR2026-ROLL]
        $text = trim((string)$line); // [MXAIR2026-ROLL]
    }
    $text = str_replace([',', ';'], ' ', $text); // [MXAIR2026-ROLL]
    $tokens = preg_split('/[\s\/]+/', trim($text)) ?: []; // [MXAIR2026-ROLL]
    return array_values(array_filter($tokens, static fn($token) => $token !== '')); // [MXAIR2026-ROLL]
}

function tokenToCoord(string $token, array $pointsIndex): ?array
{ // [MXAIR2026-ROLL]
    $coord = parseCoordinatePair($token); // [MXAIR2026-ROLL]
    if ($coord !== null) { // [MXAIR2026-ROLL]
        return $coord; // [MXAIR2026-ROLL]
    }
    $key = strtoupper(trim($token)); // [MXAIR2026-ROLL]
    if ($key !== '' && isset($pointsIndex[$key])) { // [MXAIR2026-ROLL]
        return $pointsIndex[$key]; // [MXAIR2026-ROLL]
    }
    return null; // [MXAIR2026-ROLL]
}

function buildLineSegments(array $tokens, array $pointsIndex, array &$warnings, string $context): array
{ // [MXAIR2026-ROLL]
    $segments = []; // [MXAIR2026-ROLL]
    $current = []; // [MXAIR2026-ROLL]
    foreach ($tokens as $token) { // [MXAIR2026-ROLL]
        $coord = tokenToCoord($token, $pointsIndex); // [MXAIR2026-ROLL]
        if ($coord !== null) { // [MXAIR2026-ROLL]
            $current[] = $coord; // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        if (count($current) >= 2) { // [MXAIR2026-ROLL]
            $segments[] = $current; // [MXAIR2026-ROLL]
        }
        if (trim($token) !== '') { // [MXAIR2026-ROLL]
            warnVatmex("Unresolved fix/coord {$token} in {$context}", $warnings); // [MXAIR2026-ROLL]
        }
        $current = []; // [MXAIR2026-ROLL]
    }
    if (count($current) >= 2) { // [MXAIR2026-ROLL]
        $segments[] = $current; // [MXAIR2026-ROLL]
    }
    return $segments; // [MXAIR2026-ROLL]
}

function parseRestrictedAreas(SimpleXMLElement $xml, string $sourceFile, array &$warnings): array
{ // [MXAIR2026-ROLL]
    $areas = $xml->Areas->RestrictedArea ?? $xml->RestrictedArea ?? []; // [MXAIR2026-ROLL]
    if (!$areas) { // [MXAIR2026-ROLL]
        return []; // [MXAIR2026-ROLL]
    }
    $features = []; // [MXAIR2026-ROLL]
    foreach ($areas as $ra) { // [MXAIR2026-ROLL]
        $coordsText = trim((string)($ra->Area ?? $ra['Area'] ?? $ra['Coordinates'] ?? '')); // [MXAIR2026-ROLL]
        if ($coordsText === '') { // [MXAIR2026-ROLL]
            $coordsText = trim((string)$ra); // [MXAIR2026-ROLL]
        }
        $points = []; // [MXAIR2026-ROLL]
        foreach (preg_split('/[\/\s]+/', $coordsText) as $pair) { // [MXAIR2026-ROLL]
            $coord = parseCoordinatePair($pair); // [MXAIR2026-ROLL]
            if ($coord !== null) { // [MXAIR2026-ROLL]
                $points[] = $coord; // [MXAIR2026-ROLL]
            }
        }
        if (count($points) < 3) { // [MXAIR2026-ROLL]
            warnVatmex('Restricted area skipped (insufficient points) in ' . $sourceFile, $warnings); // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        $features[] = [ // [MXAIR2026-ROLL]
            'type' => 'Feature', // [MXAIR2026-ROLL]
            'properties' => [ // [MXAIR2026-ROLL]
                'name' => (string)($ra['Name'] ?? $ra['Ident'] ?? ''), // [MXAIR2026-ROLL]
                'type' => (string)($ra['Type'] ?? $ra['Category'] ?? ''), // [MXAIR2026-ROLL]
                'icao' => (string)($ra['ICAO'] ?? ''), // [MXAIR2026-ROLL]
                'class' => (string)($ra['Class'] ?? ''), // [MXAIR2026-ROLL]
                'floor' => (string)($ra['Floor'] ?? ''), // [MXAIR2026-ROLL]
                'ceiling' => (string)($ra['Ceiling'] ?? ''), // [MXAIR2026-ROLL]
                'source_file' => basename($sourceFile), // [MXAIR2026-ROLL]
            ],
            'geometry' => [ // [MXAIR2026-ROLL]
                'type' => 'Polygon', // [MXAIR2026-ROLL]
                'coordinates' => [$points], // [MXAIR2026-ROLL]
            ],
        ];
    }
    return $features; // [MXAIR2026-ROLL]
}

function parsePointIndex(SimpleXMLElement $xml, array &$warnings): array
{ // [MXAIR2026-ROLL]
    $pointsIndex = []; // [MXAIR2026-ROLL]
    foreach ($xml->xpath('//Point') as $point) { // [MXAIR2026-ROLL]
        $name = trim((string)$point['Name']); // [MXAIR2026-ROLL]
        $pos = trim((string)$point['Position']); // [MXAIR2026-ROLL]
        if ($name === '' || $pos === '') { // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        $coord = parseCoordinatePair($pos); // [MXAIR2026-ROLL]
        if ($coord === null) { // [MXAIR2026-ROLL]
            warnVatmex("Unable to parse point position for {$name}", $warnings); // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        $pointsIndex[strtoupper($name)] = $coord; // [MXAIR2026-ROLL]
    }
    foreach ($xml->xpath('//Airport') as $airport) { // [MXAIR2026-ROLL]
        $icao = trim((string)$airport['ICAO']); // [MXAIR2026-ROLL]
        $pos = trim((string)$airport['Position']); // [MXAIR2026-ROLL]
        if ($icao === '' || $pos === '') { // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        $coord = parseCoordinatePair($pos); // [MXAIR2026-ROLL]
        if ($coord === null) { // [MXAIR2026-ROLL]
            warnVatmex("Unable to parse airport position for {$icao}", $warnings); // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        $pointsIndex[strtoupper($icao)] = $coord; // [MXAIR2026-ROLL]
    }
    return $pointsIndex; // [MXAIR2026-ROLL]
}

function parseNavFixes(SimpleXMLElement $xml, string $sourceFile, array &$warnings): array
{ // [MXAIR2026-ROLL]
    $features = []; // [MXAIR2026-ROLL]
    foreach ($xml->xpath('//Point') as $point) { // [MXAIR2026-ROLL]
        $typeAttr = strtoupper(trim((string)$point['Type'])); // [MXAIR2026-ROLL]
        if ($typeAttr !== 'FIX') { // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        $name = trim((string)$point['Name']); // [MXAIR2026-ROLL]
        $pos = trim((string)$point['Position']); // [MXAIR2026-ROLL]
        if ($name === '' || $pos === '') { // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        $coord = parseCoordinatePair($pos); // [MXAIR2026-ROLL]
        if ($coord === null) { // [MXAIR2026-ROLL]
            warnVatmex("Unable to parse fix position for {$name}", $warnings); // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        $features[] = [ // [MXAIR2026-ROLL]
            'type' => 'Feature', // [MXAIR2026-ROLL]
            'properties' => [ // [MXAIR2026-ROLL]
                'name' => $name, // [MXAIR2026-ROLL]
                'type' => 'fix', // [MXAIR2026-ROLL]
                'icao' => (string)($point['ICAO'] ?? ''), // [MXAIR2026-ROLL]
                'class' => (string)($point['Class'] ?? ''), // [MXAIR2026-ROLL]
                'floor' => '', // [MXAIR2026-ROLL]
                'ceiling' => '', // [MXAIR2026-ROLL]
                'source_file' => basename($sourceFile), // [MXAIR2026-ROLL]
            ],
            'geometry' => [ // [MXAIR2026-ROLL]
                'type' => 'Point', // [MXAIR2026-ROLL]
                'coordinates' => $coord, // [MXAIR2026-ROLL]
            ],
        ];
    }
    return $features; // [MXAIR2026-ROLL]
}

function inferAirwayClass(string $label): string
{ // [MXAIR2026-ROLL]
    $label = strtolower($label); // [MXAIR2026-ROLL]
    if (strpos($label, 'upper') !== false || strpos($label, 'high') !== false || strpos($label, 'ul') !== false) { // [MXAIR2026-ROLL]
        return 'upper'; // [MXAIR2026-ROLL]
    }
    if (strpos($label, 'lower') !== false || strpos($label, 'low') !== false || strpos($label, 'll') !== false) { // [MXAIR2026-ROLL]
        return 'lower'; // [MXAIR2026-ROLL]
    }
    return ''; // [MXAIR2026-ROLL]
}

function parseAirways(SimpleXMLElement $xml, array $pointsIndex, string $sourceFile, array &$warnings): array
{ // [MXAIR2026-ROLL]
    $upper = []; // [MXAIR2026-ROLL]
    $lower = []; // [MXAIR2026-ROLL]
    $nodes = $xml->xpath('//Airway|//Airways/Airway|//Route|//AirwaySegment'); // [MXAIR2026-ROLL]
    foreach ($nodes as $node) { // [MXAIR2026-ROLL]
        $name = trim((string)($node['Name'] ?? $node['Ident'] ?? $node['Id'] ?? '')); // [MXAIR2026-ROLL]
        $label = $name . ' ' . (string)($node['Type'] ?? $node['Class'] ?? ''); // [MXAIR2026-ROLL]
        $class = inferAirwayClass($label); // [MXAIR2026-ROLL]
        $segments = []; // [MXAIR2026-ROLL]
        $lineNodes = $node->xpath('.//Line|.//Segment|.//Route'); // [MXAIR2026-ROLL]
        if (!$lineNodes) { // [MXAIR2026-ROLL]
            $lineNodes = [$node]; // [MXAIR2026-ROLL]
        }
        foreach ($lineNodes as $line) { // [MXAIR2026-ROLL]
            $tokens = extractLineTokens($line); // [MXAIR2026-ROLL]
            if (!$tokens) { // [MXAIR2026-ROLL]
                continue; // [MXAIR2026-ROLL]
            }
            $segments = array_merge($segments, buildLineSegments($tokens, $pointsIndex, $warnings, $sourceFile)); // [MXAIR2026-ROLL]
        }
        foreach ($segments as $segment) { // [MXAIR2026-ROLL]
            $feature = [ // [MXAIR2026-ROLL]
                'type' => 'Feature', // [MXAIR2026-ROLL]
                'properties' => [ // [MXAIR2026-ROLL]
                    'name' => $name ?: (string)($node['Ident'] ?? ''), // [MXAIR2026-ROLL]
                    'type' => 'airway', // [MXAIR2026-ROLL]
                    'icao' => (string)($node['ICAO'] ?? ''), // [MXAIR2026-ROLL]
                    'class' => (string)($node['Class'] ?? $class), // [MXAIR2026-ROLL]
                    'floor' => (string)($node['Floor'] ?? ''), // [MXAIR2026-ROLL]
                    'ceiling' => (string)($node['Ceiling'] ?? ''), // [MXAIR2026-ROLL]
                    'source_file' => basename($sourceFile), // [MXAIR2026-ROLL]
                ],
                'geometry' => [ // [MXAIR2026-ROLL]
                    'type' => 'LineString', // [MXAIR2026-ROLL]
                    'coordinates' => $segment, // [MXAIR2026-ROLL]
                ],
            ];
            if ($class === 'upper') { // [MXAIR2026-ROLL]
                $upper[] = $feature; // [MXAIR2026-ROLL]
            } elseif ($class === 'lower') { // [MXAIR2026-ROLL]
                $lower[] = $feature; // [MXAIR2026-ROLL]
            } else { // [MXAIR2026-ROLL]
                $lower[] = $feature; // [MXAIR2026-ROLL]
            }
        }
    }
    return ['upper' => $upper, 'lower' => $lower]; // [MXAIR2026-ROLL]
}

function inferProcedureKind(string $label): string
{ // [MXAIR2026-ROLL]
    $label = strtolower($label); // [MXAIR2026-ROLL]
    if (strpos($label, 'sid') !== false) { // [MXAIR2026-ROLL]
        return 'sid'; // [MXAIR2026-ROLL]
    }
    if (strpos($label, 'star') !== false) { // [MXAIR2026-ROLL]
        return 'star'; // [MXAIR2026-ROLL]
    }
    if (strpos($label, 'app') !== false || strpos($label, 'approach') !== false) { // [MXAIR2026-ROLL]
        return 'app'; // [MXAIR2026-ROLL]
    }
    return 'other'; // [MXAIR2026-ROLL]
}

function parseProcedures(SimpleXMLElement $xml, array $pointsIndex, string $sourceFile, array &$warnings): array
{ // [MXAIR2026-ROLL]
    $procedures = ['sid' => [], 'star' => [], 'app' => []]; // [MXAIR2026-ROLL]
    $lineNodes = $xml->xpath('//Procedure|//SID|//STAR|//APP|//Approach|//Line'); // [MXAIR2026-ROLL]
    foreach ($lineNodes as $node) { // [MXAIR2026-ROLL]
        $name = trim((string)($node['Name'] ?? $node['Ident'] ?? '')); // [MXAIR2026-ROLL]
        $contextLabel = $node->getName() . ' ' . $name . ' ' . (string)($node['Type'] ?? ''); // [MXAIR2026-ROLL]
        $kind = inferProcedureKind($contextLabel); // [MXAIR2026-ROLL]
        if ($kind === 'other') { // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        $tokens = extractLineTokens($node); // [MXAIR2026-ROLL]
        if (!$tokens) { // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        $segments = buildLineSegments($tokens, $pointsIndex, $warnings, $sourceFile); // [MXAIR2026-ROLL]
        foreach ($segments as $segment) { // [MXAIR2026-ROLL]
            $procedures[$kind][] = [ // [MXAIR2026-ROLL]
                'type' => 'Feature', // [MXAIR2026-ROLL]
                'properties' => [ // [MXAIR2026-ROLL]
                    'name' => $name, // [MXAIR2026-ROLL]
                    'type' => $kind, // [MXAIR2026-ROLL]
                    'icao' => (string)($node['ICAO'] ?? ''), // [MXAIR2026-ROLL]
                    'class' => (string)($node['Class'] ?? ''), // [MXAIR2026-ROLL]
                    'floor' => (string)($node['Floor'] ?? ''), // [MXAIR2026-ROLL]
                    'ceiling' => (string)($node['Ceiling'] ?? ''), // [MXAIR2026-ROLL]
                    'source_file' => basename($sourceFile), // [MXAIR2026-ROLL]
                ],
                'geometry' => [ // [MXAIR2026-ROLL]
                    'type' => 'LineString', // [MXAIR2026-ROLL]
                    'coordinates' => $segment, // [MXAIR2026-ROLL]
                ],
            ];
        }
    }
    return $procedures; // [MXAIR2026-ROLL]
}

function parseMapLines(SimpleXMLElement $xml, array $pointsIndex, string $sourceFile, array &$warnings): array
{ // [MXAIR2026-ROLL]
    $features = []; // [MXAIR2026-ROLL]
    $lines = $xml->xpath('//Line') ?: []; // [MXAIR2026-ROLL]
    foreach ($lines as $line) { // [MXAIR2026-ROLL]
        $lineName = trim((string)($line['Name'] ?? '')); // [MXAIR2026-ROLL]
        $tokens = extractLineTokens($line); // [MXAIR2026-ROLL]
        if (!$tokens) { // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        $segments = buildLineSegments($tokens, $pointsIndex, $warnings, $sourceFile); // [MXAIR2026-ROLL]
        foreach ($segments as $segment) { // [MXAIR2026-ROLL]
            $isClosed = count($segment) >= 3 && $segment[0] === $segment[count($segment) - 1]; // [MXAIR2026-ROLL]
            $geometry = $isClosed
                ? ['type' => 'Polygon', 'coordinates' => [$segment]]
                : ['type' => 'LineString', 'coordinates' => $segment]; // [MXAIR2026-ROLL]
            $features[] = [ // [MXAIR2026-ROLL]
                'type' => 'Feature', // [MXAIR2026-ROLL]
                'properties' => [ // [MXAIR2026-ROLL]
                    'name' => $lineName, // [MXAIR2026-ROLL]
                ],
                'geometry' => $geometry, // [MXAIR2026-ROLL]
            ];
        }
    }
    return $features; // [MXAIR2026-ROLL]
}

function writeGeojsonFile(string $outputDir, string $name, array $features): ?string
{ // [MXAIR2026-ROLL]
    $geojson = normalizeGeojson([ // [MXAIR2026-ROLL]
        'type' => 'FeatureCollection', // [MXAIR2026-ROLL]
        'features' => $features, // [MXAIR2026-ROLL]
    ]); // [MXAIR2026-ROLL]
    $outputPath = rtrim($outputDir, '/\\') . '/' . $name . '.geojson'; // [MXAIR2026-ROLL]
    file_put_contents($outputPath, json_encode($geojson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); // [MXAIR2026-ROLL]
    return $outputPath; // [MXAIR2026-ROLL]
}

function insertGeojsonLayer(PDO $pdo, string $table, array $features): void
{ // [MXAIR2026-ROLL]
    $pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT, geometria TEXT)"); // [MXAIR2026-ROLL]
    $pdo->exec("DELETE FROM {$table}"); // [MXAIR2026-ROLL]
    $stmt = $pdo->prepare("INSERT INTO {$table} (nombre, geometria) VALUES (:nombre, :geometria)"); // [MXAIR2026-ROLL]
    foreach ($features as $feature) { // [MXAIR2026-ROLL]
        $props = $feature['properties'] ?? []; // [MXAIR2026-ROLL]
        $name = $props['name'] ?? $props['Name'] ?? $props['ident'] ?? ''; // [MXAIR2026-ROLL]
        $geometry = $feature['geometry'] ?? null; // [MXAIR2026-ROLL]
        if (!$geometry) { // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        $stmt->execute([ // [MXAIR2026-ROLL]
            ':nombre' => $name, // [MXAIR2026-ROLL]
            ':geometria' => json_encode($geometry, JSON_UNESCAPED_SLASHES), // [MXAIR2026-ROLL]
        ]);
    }
}

function sanitizeTableName(string $name): ?string
{ // [MXAIR2026-ROLL]
    $name = strtolower($name); // [MXAIR2026-ROLL]
    $name = preg_replace('/[^a-z0-9_]/', '_', $name); // [MXAIR2026-ROLL]
    $name = preg_replace('/_+/', '_', $name); // [MXAIR2026-ROLL]
    $name = trim($name, '_'); // [MXAIR2026-ROLL]
    return $name !== '' ? $name : null; // [MXAIR2026-ROLL]
}

$airspacePath = resolveXmlPath($basePath, ['Airspace.XML', 'Airspace.xml']); // [MXAIR2026-ROLL]
$restrictedPath = resolveXmlPath($basePath, ['RestrictedAreas.XML', 'RestrictedAreas.xml']); // [MXAIR2026-ROLL]
$mapsPath = $basePath . '/MAPS'; // [MXAIR2026-ROLL]

if (!$airspacePath) { // [MXAIR2026-ROLL]
    warnVatmex('Airspace.XML not found in ' . $basePath, $warnings); // [MXAIR2026-ROLL]
}
if (!$restrictedPath) { // [MXAIR2026-ROLL]
    warnVatmex('RestrictedAreas.XML not found in ' . $basePath, $warnings); // [MXAIR2026-ROLL]
}
if (!is_dir($mapsPath)) { // [MXAIR2026-ROLL]
    warnVatmex('MAPS directory not found in ' . $basePath, $warnings); // [MXAIR2026-ROLL]
}

$pointsIndex = []; // [MXAIR2026-ROLL]
$navFixes = []; // [MXAIR2026-ROLL]
$airwaysUpper = []; // [MXAIR2026-ROLL]
$airwaysLower = []; // [MXAIR2026-ROLL]
$proceduresSid = []; // [MXAIR2026-ROLL]
$proceduresStar = []; // [MXAIR2026-ROLL]
$proceduresApp = []; // [MXAIR2026-ROLL]

if ($airspacePath) { // [MXAIR2026-ROLL]
    $airspaceXml = simplexml_load_file($airspacePath); // [MXAIR2026-ROLL]
    if ($airspaceXml === false) { // [MXAIR2026-ROLL]
        warnVatmex('Failed to parse Airspace.XML', $warnings); // [MXAIR2026-ROLL]
    } else {
        $pointsIndex = parsePointIndex($airspaceXml, $warnings); // [MXAIR2026-ROLL]
        $navFixes = parseNavFixes($airspaceXml, $airspacePath, $warnings); // [MXAIR2026-ROLL]
        $airways = parseAirways($airspaceXml, $pointsIndex, $airspacePath, $warnings); // [MXAIR2026-ROLL]
        $airwaysUpper = $airways['upper']; // [MXAIR2026-ROLL]
        $airwaysLower = $airways['lower']; // [MXAIR2026-ROLL]
        $procedures = parseProcedures($airspaceXml, $pointsIndex, $airspacePath, $warnings); // [MXAIR2026-ROLL]
        $proceduresSid = $procedures['sid']; // [MXAIR2026-ROLL]
        $proceduresStar = $procedures['star']; // [MXAIR2026-ROLL]
        $proceduresApp = $procedures['app']; // [MXAIR2026-ROLL]
    }
}

$restrictedAreas = []; // [MXAIR2026-ROLL]
if ($restrictedPath) { // [MXAIR2026-ROLL]
    $restrictedXml = simplexml_load_file($restrictedPath); // [MXAIR2026-ROLL]
    if ($restrictedXml === false) { // [MXAIR2026-ROLL]
        warnVatmex('Failed to parse RestrictedAreas.XML', $warnings); // [MXAIR2026-ROLL]
    } else {
        $restrictedAreas = parseRestrictedAreas($restrictedXml, $restrictedPath, $warnings); // [MXAIR2026-ROLL]
    }
}

$firLimits = []; // [MXAIR2026-ROLL]
$atzFeatures = []; // [MXAIR2026-ROLL]
$ctrFeatures = []; // [MXAIR2026-ROLL]
$tmaFeatures = []; // [MXAIR2026-ROLL]
$asmgcsFeatures = []; // [MXAIR2026-ROLL]
$rwProcedures = ['sid' => [], 'star' => [], 'app' => []]; // [MXAIR2026-ROLL]

if (is_dir($mapsPath)) { // [MXAIR2026-ROLL]
    $firPath = resolveXmlPath($mapsPath . '/ACC_IDFIR', ['FIR_LIMITS.XML', 'FIR_LIMITS.xml']); // [MXAIR2026-ROLL]
    if ($firPath) { // [MXAIR2026-ROLL]
        $firXml = simplexml_load_file($firPath); // [MXAIR2026-ROLL]
        if ($firXml !== false) { // [MXAIR2026-ROLL]
            $firLimits = parseMapLines($firXml, $pointsIndex, $firPath, $warnings); // [MXAIR2026-ROLL]
            foreach ($firLimits as &$feature) { // [MXAIR2026-ROLL]
                $feature['properties'] = array_merge($feature['properties'] ?? [], [ // [MXAIR2026-ROLL]
                    'type' => 'fir', // [MXAIR2026-ROLL]
                    'icao' => '', // [MXAIR2026-ROLL]
                    'class' => '', // [MXAIR2026-ROLL]
                    'floor' => '', // [MXAIR2026-ROLL]
                    'ceiling' => '', // [MXAIR2026-ROLL]
                    'source_file' => basename($firPath), // [MXAIR2026-ROLL]
                ]);
            }
            unset($feature); // [MXAIR2026-ROLL]
        } else {
            warnVatmex('Failed to parse FIR_LIMITS.XML', $warnings); // [MXAIR2026-ROLL]
        }
    }

    $atzCtrTmaPath = $mapsPath . '/ATZ_CTR_TMA'; // [MXAIR2026-ROLL]
    if (is_dir($atzCtrTmaPath)) { // [MXAIR2026-ROLL]
        foreach (scandir($atzCtrTmaPath) as $icaoDir) { // [MXAIR2026-ROLL]
            if ($icaoDir[0] === '.') { // [MXAIR2026-ROLL]
                continue; // [MXAIR2026-ROLL]
            }
            $icaoPath = $atzCtrTmaPath . '/' . $icaoDir; // [MXAIR2026-ROLL]
            if (!is_dir($icaoPath)) { // [MXAIR2026-ROLL]
                continue; // [MXAIR2026-ROLL]
            }
            $icao = strtoupper($icaoDir); // [MXAIR2026-ROLL]
            $dirIterator = new DirectoryIterator($icaoPath); // [MXAIR2026-ROLL]
            foreach ($dirIterator as $fileInfo) { // [MXAIR2026-ROLL]
                if ($fileInfo->isDot() || !$fileInfo->isFile()) { // [MXAIR2026-ROLL]
                    continue; // [MXAIR2026-ROLL]
                }
                if (strtolower($fileInfo->getExtension()) !== 'xml') { // [MXAIR2026-ROLL]
                    continue; // [MXAIR2026-ROLL]
                }
                $filePath = $fileInfo->getPathname(); // [MXAIR2026-ROLL]
                $baseName = strtolower($fileInfo->getBasename('.xml')); // [MXAIR2026-ROLL]
                $xml = simplexml_load_file($filePath); // [MXAIR2026-ROLL]
                if ($xml === false) { // [MXAIR2026-ROLL]
                    warnVatmex('Failed to parse map XML: ' . $filePath, $warnings); // [MXAIR2026-ROLL]
                    continue; // [MXAIR2026-ROLL]
                }
                $features = parseMapLines($xml, $pointsIndex, $filePath, $warnings); // [MXAIR2026-ROLL]
                if (!$features) { // [MXAIR2026-ROLL]
                    continue; // [MXAIR2026-ROLL]
                }
                $mapType = ''; // [MXAIR2026-ROLL]
                if (strpos($baseName, '_atz') !== false) { // [MXAIR2026-ROLL]
                    $mapType = 'atz'; // [MXAIR2026-ROLL]
                } elseif (strpos($baseName, '_ctr') !== false) { // [MXAIR2026-ROLL]
                    $mapType = 'ctr'; // [MXAIR2026-ROLL]
                } elseif (strpos($baseName, '_tma') !== false) { // [MXAIR2026-ROLL]
                    $mapType = 'tma'; // [MXAIR2026-ROLL]
                } elseif (strpos($baseName, 'asmgcs') !== false) { // [MXAIR2026-ROLL]
                    $mapType = 'asmgcs'; // [MXAIR2026-ROLL]
                } elseif (strpos($baseName, '_rwy') !== false) { // [MXAIR2026-ROLL]
                    $mapType = 'rwy'; // [MXAIR2026-ROLL]
                }
                foreach ($features as &$feature) { // [MXAIR2026-ROLL]
                    $feature['properties'] = array_merge($feature['properties'] ?? [], [ // [MXAIR2026-ROLL]
                        'type' => $mapType, // [MXAIR2026-ROLL]
                        'icao' => $icao, // [MXAIR2026-ROLL]
                        'class' => '', // [MXAIR2026-ROLL]
                        'floor' => '', // [MXAIR2026-ROLL]
                        'ceiling' => '', // [MXAIR2026-ROLL]
                        'source_file' => basename($filePath), // [MXAIR2026-ROLL]
                    ]);
                }
                unset($feature); // [MXAIR2026-ROLL]

                if ($mapType === 'atz') { // [MXAIR2026-ROLL]
                    $atzFeatures = array_merge($atzFeatures, $features); // [MXAIR2026-ROLL]
                } elseif ($mapType === 'ctr') { // [MXAIR2026-ROLL]
                    $ctrFeatures = array_merge($ctrFeatures, $features); // [MXAIR2026-ROLL]
                } elseif ($mapType === 'tma') { // [MXAIR2026-ROLL]
                    $tmaFeatures = array_merge($tmaFeatures, $features); // [MXAIR2026-ROLL]
                } elseif ($mapType === 'asmgcs') { // [MXAIR2026-ROLL]
                    $asmgcsFeatures = array_merge($asmgcsFeatures, $features); // [MXAIR2026-ROLL]
                } elseif ($mapType === 'rwy') { // [MXAIR2026-ROLL]
                    foreach ($features as $feature) { // [MXAIR2026-ROLL]
                        $lineName = (string)($feature['properties']['name'] ?? ''); // [MXAIR2026-ROLL]
                        $procedureKind = inferProcedureKind($baseName . ' ' . $lineName); // [MXAIR2026-ROLL]
                        if ($procedureKind === 'other') { // [MXAIR2026-ROLL]
                            continue; // [MXAIR2026-ROLL]
                        }
                        $feature['properties']['type'] = $procedureKind; // [MXAIR2026-ROLL]
                        $rwProcedures[$procedureKind][] = $feature; // [MXAIR2026-ROLL]
                    }
                }
            }
        }
    }
}

$proceduresSid = array_merge($proceduresSid, $rwProcedures['sid']); // [MXAIR2026-ROLL]
$proceduresStar = array_merge($proceduresStar, $rwProcedures['star']); // [MXAIR2026-ROLL]
$proceduresApp = array_merge($proceduresApp, $rwProcedures['app']); // [MXAIR2026-ROLL]

$outputs = []; // [MXAIR2026-ROLL]
$outputs['fir-limits'] = writeGeojsonFile($outputDir, 'fir-limits', $firLimits); // [MXAIR2026-ROLL]
$outputs['restricted-areas'] = writeGeojsonFile($outputDir, 'restricted-areas', $restrictedAreas); // [MXAIR2026-ROLL]
$outputs['atz'] = writeGeojsonFile($outputDir, 'atz', $atzFeatures); // [MXAIR2026-ROLL]
$outputs['ctr'] = writeGeojsonFile($outputDir, 'ctr', $ctrFeatures); // [MXAIR2026-ROLL]
$outputs['tma'] = writeGeojsonFile($outputDir, 'tma', $tmaFeatures); // [MXAIR2026-ROLL]
$outputs['nav-fixes'] = writeGeojsonFile($outputDir, 'nav-fixes', $navFixes); // [MXAIR2026-ROLL]
$outputs['airways-upper'] = writeGeojsonFile($outputDir, 'airways-upper', $airwaysUpper); // [MXAIR2026-ROLL]
$outputs['airways-lower'] = writeGeojsonFile($outputDir, 'airways-lower', $airwaysLower); // [MXAIR2026-ROLL]
$outputs['procedures-sid'] = writeGeojsonFile($outputDir, 'procedures-sid', $proceduresSid); // [MXAIR2026-ROLL]
$outputs['procedures-star'] = writeGeojsonFile($outputDir, 'procedures-star', $proceduresStar); // [MXAIR2026-ROLL]
$outputs['procedures-app'] = writeGeojsonFile($outputDir, 'procedures-app', $proceduresApp); // [MXAIR2026-ROLL]
if ($asmgcsFeatures) { // [MXAIR2026-ROLL]
    $outputs['aerodrome-asmgcs'] = writeGeojsonFile($outputDir, 'aerodrome-asmgcs', $asmgcsFeatures); // [MXAIR2026-ROLL]
}

$tablesLoaded = []; // [MXAIR2026-ROLL]
if ($importSql) { // [MXAIR2026-ROLL]
    $dbPath = $config['settings_db'] ?? (__DIR__ . '/../data/adsb.sqlite'); // [MXAIR2026-ROLL]
    $pdo = new PDO('sqlite:' . $dbPath); // [MXAIR2026-ROLL]
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // [MXAIR2026-ROLL]
    foreach ($outputs as $key => $path) { // [MXAIR2026-ROLL]
        if (!$path || !is_file($path)) { // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        $data = json_decode((string)file_get_contents($path), true); // [MXAIR2026-ROLL]
        if (!is_array($data)) { // [MXAIR2026-ROLL]
            continue; // [MXAIR2026-ROLL]
        }
        $table = sanitizeTableName($key); // [MXAIR2026-ROLL]
        if ($table) { // [MXAIR2026-ROLL]
            insertGeojsonLayer($pdo, $table, $data['features'] ?? []); // [MXAIR2026-ROLL]
            $tablesLoaded[] = $table; // [MXAIR2026-ROLL]
        }
    }
}

$logPath = __DIR__ . '/../data/geojson_build.log'; // [MXAIR2026-ROLL]
if ($logs || $warnings) { // [MXAIR2026-ROLL]
    $lines = array_merge($logs, array_map(static fn($line) => '[' . gmdate('c') . '] ' . $line, $warnings)); // [MXAIR2026-ROLL]
    file_put_contents($logPath, implode(PHP_EOL, $lines) . PHP_EOL, FILE_APPEND); // [MXAIR2026-ROLL]
}

$results = []; // [MXAIR2026-ROLL]
foreach ($outputs as $key => $path) { // [MXAIR2026-ROLL]
    if (!$path || !is_file($path)) { // [MXAIR2026-ROLL]
        $results[] = ['layer' => $key, 'status' => 'skipped']; // [MXAIR2026-ROLL]
        continue; // [MXAIR2026-ROLL]
    }
    $data = json_decode((string)file_get_contents($path), true); // [MXAIR2026-ROLL]
    $results[] = [ // [MXAIR2026-ROLL]
        'layer' => $key, // [MXAIR2026-ROLL]
        'output' => $path, // [MXAIR2026-ROLL]
        'features' => is_array($data['features'] ?? null) ? count($data['features']) : 0, // [MXAIR2026-ROLL]
    ];
}

if ($isCli) { // [MXAIR2026-ROLL]
    foreach ($results as $entry) { // [MXAIR2026-ROLL]
        $line = ($entry['layer'] ?? 'layer') . ' -> ' . ($entry['output'] ?? 'skipped'); // [MXAIR2026-ROLL]
        if (isset($entry['features'])) { // [MXAIR2026-ROLL]
            $line .= ' (' . $entry['features'] . ' features)'; // [MXAIR2026-ROLL]
        }
        echo $line . "\n"; // [MXAIR2026-ROLL]
    }
    if ($tablesLoaded) { // [MXAIR2026-ROLL]
        echo "Imported tables: " . implode(', ', array_unique($tablesLoaded)) . "\n"; // [MXAIR2026-ROLL]
    }
    if ($warnings) { // [MXAIR2026-ROLL]
        echo "Warnings: " . implode('; ', $warnings) . "\n"; // [MXAIR2026-ROLL]
    }
    exit(0); // [MXAIR2026-ROLL]
}

echo json_encode([ // [MXAIR2026-ROLL]
    'ok' => true, // [MXAIR2026-ROLL]
    'import_sql' => $importSql, // [MXAIR2026-ROLL]
    'results' => $results, // [MXAIR2026-ROLL]
    'tables' => array_values(array_unique($tablesLoaded)), // [MXAIR2026-ROLL]
    'warnings' => $warnings, // [MXAIR2026-ROLL]
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
