<?php
/**
 * feed.php
 *
 * Proxy for the ADS‑B point feed.  This script queries the configured
 * ADS‑B API endpoint for a radius around the configured airport and filters
 * the results based on geographical criteria.  It also supports rudimentary
 * caching and optionally merging additional metadata.
 */

header('Content-Type: application/json; charset=utf-8');

// Load configuration
$config = require __DIR__ . '/config.php';

// Helper: convert nautical miles to degrees of latitude.  One degree of
// latitude corresponds to approximately 60 nautical miles.
function nm_to_deg($nm)
{
    return $nm / 60.0;
}

// Build query URL for the ADS‑B API.  The radius (NM) and centre point can
// be overridden via query parameters.  If not provided, fall back to
// configured defaults.
$lat = isset($_GET['lat']) ? (float)$_GET['lat'] : $config['airport']['lat'];
$lon = isset($_GET['lon']) ? (float)$_GET['lon'] : $config['airport']['lon'];
$radius = isset($_GET['radius']) ? (float)$_GET['radius'] : $config['adsb_radius'];
if ($radius <= 0 || $radius > 250) {
    $radius = $config['adsb_radius'];
}

$feedUrl = rtrim($config['adsb_feed_url'], '/') . "/{$lat}/{$lon}/{$radius}";

// Retrieve the feed.  Use file_get_contents for simplicity; curl would
// allow more fine‑grained control.  Suppress warnings on failure and
// handle errors gracefully.
$response = @file_get_contents($feedUrl);
if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Unable to retrieve ADS‑B data']);
    exit;
}

// Decode JSON from the feed.  If decoding fails, return an error.
$data = json_decode($response, true);
if (!is_array($data) || !isset($data['ac'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid ADS‑B response']);
    exit;
}

// Filter aircraft: remove those more than north_buffer_nm north of the border.
$borderLat = $config['border_lat'];
$northBufferDeg = nm_to_deg($config['north_buffer_nm']);
$northCutoff = $borderLat + $northBufferDeg;

// Optionally restrict to FIR (MMFR) by longitude/latitude bounds.  For
// demonstration we keep all aircraft south of the cutoff; northern
// aircraft beyond the buffer are discarded.
$filtered = [];
foreach ($data['ac'] as $ac) {
    if (!isset($ac['lat']) || !isset($ac['lon'])) {
        continue;
    }
    // Filter by north cutoff
    if ($ac['lat'] > $northCutoff) {
        continue;
    }
    // Accept all other tracks within radius
    $filtered[] = [
        'hex' => $ac['hex'] ?? '',
        'flight' => trim($ac['flight'] ?? ''),
        'reg' => $ac['r'] ?? '',
        'type' => $ac['t'] ?? '',
        'lat' => (float)$ac['lat'],
        'lon' => (float)$ac['lon'],
        'alt' => $ac['alt_baro'] ?? null,
        'gs' => $ac['gs'] ?? null,
        'track' => $ac['track'] ?? null,
        'squawk' => $ac['squawk'] ?? null,
        'emergency' => $ac['emergency'] ?? null,
        'seen_pos' => $ac['seen_pos'] ?? null,
        // Include distance/direction if present
        'dst' => $ac['dst'] ?? null,
        'dir' => $ac['dir'] ?? null,
    ];
}

// Output filtered tracks
echo json_encode([
    'timestamp' => time(),
    'count' => count($filtered),
    'ac' => $filtered,
]);
exit;