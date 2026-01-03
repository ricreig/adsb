<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config.php';

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

$callsign = strtoupper(trim((string)($_GET['callsign'] ?? '')));
if ($callsign === '') {
    respond(['error' => 'Missing callsign.'], 400);
}
if (!preg_match('/^[A-Z0-9]{2,8}$/', $callsign)) {
    respond(['error' => 'Invalid callsign.'], 400);
}

$routePath = __DIR__ . '/../data/routes/' . $callsign . '.geojson';
if (!is_file($routePath)) {
    respond(['ok' => false, 'error' => 'Route unavailable.'], 404);
}
$contents = file_get_contents($routePath);
if ($contents === false) {
    respond(['ok' => false, 'error' => 'Unable to read route data.'], 500);
}
$geojson = json_decode($contents, true);
if (!is_array($geojson)) {
    respond(['ok' => false, 'error' => 'Invalid route data.'], 500);
}

$summary = [
    'fix_count' => 0,
    'start' => null,
    'end' => null,
];

$features = $geojson['features'] ?? [];
foreach ($features as $feature) {
    $geometry = $feature['geometry'] ?? null;
    if (!is_array($geometry)) {
        continue;
    }
    if (($geometry['type'] ?? '') === 'LineString') {
        $coords = $geometry['coordinates'] ?? [];
        if (is_array($coords) && count($coords) >= 2) {
            $summary['fix_count'] = count($coords);
            $summary['start'] = $coords[0] ?? null;
            $summary['end'] = $coords[count($coords) - 1] ?? null;
            break;
        }
    }
}

respond([
    'ok' => true,
    'route' => $geojson,
    'summary' => $summary,
]);
