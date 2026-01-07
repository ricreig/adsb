<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../auth.php';
requireAuth($config);

$defaults = [
    'airport' => [
        'icao' => $config['airport']['icao'],
    ],
    'feed_center' => [
        'lat' => (float)($config['feed_center']['lat'] ?? $config['airport']['lat']),
        'lon' => (float)($config['feed_center']['lon'] ?? $config['airport']['lon']),
        'radius_nm' => (float)($config['feed_radius_nm'] ?? $config['adsb_radius'] ?? 250),
    ],
    'ui_center' => [
        'lat' => (float)($config['ui_center']['lat'] ?? $config['display_center']['lat'] ?? 32.541),
        'lon' => (float)($config['ui_center']['lon'] ?? $config['display_center']['lon'] ?? -116.97),
    ],
    'poll_interval_ms' => (int)$config['poll_interval_ms'],
    'rings' => [
        'distances' => [50, 100, 150, 200, 250],
        'style' => [
            'color' => '#6666ff',
            'weight' => 1,
            'dash' => '6 6',
        ],
    ],
    'labels' => [
        'show_alt' => true,
        'show_gs' => true,
        'show_vs' => true,
        'show_trk' => true,
        'show_sqk' => true,
        'show_labels' => true,
        'min_zoom' => 7,
        'font_size' => 12,
        'color' => '#00ff00',
    ],
    'display' => [
        'basemap' => 'dark',
    ],
    'navpoints' => [
        'enabled' => true,
        'min_zoom' => 7,
        'zone' => 'all',
        'max_points' => 2000,
    ],
    'tracks' => [
        'show_trail' => false,
    ],
    'leader' => [
        'mode' => 'time',
        'time_minutes' => 2,
        'distance_nm' => 2,
    ],
    'category_styles' => [
        'default' => [
            'color' => '#3aa0ff',
            'weight' => 1.5,
            'dash' => '',
        ],
    ],
];

function ensureDatabase(string $dbPath): PDO
{
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            data TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );
    return $pdo;
}

function normalizeBoolean($value): bool
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
}

function normalizeHexColor($value, string $fallback): string
{
    if (!is_string($value)) {
        return $fallback;
    }
    $value = trim($value);
    if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value)) {
        return strtolower($value);
    }
    return $fallback;
}

function fixedFeedCenter(array $config): array
{
    return [
        'lat' => (float)($config['feed_center']['lat'] ?? $config['airport']['lat']),
        'lon' => (float)($config['feed_center']['lon'] ?? $config['airport']['lon']),
        'radius_nm' => (float)($config['feed_radius_nm'] ?? $config['adsb_radius'] ?? 250),
    ];
}

function normalizeSettings(array $input, array $base, array $config): array
{
    $settings = $base;

    if (isset($input['airport']) && is_array($input['airport'])) {
        $icao = strtoupper(trim((string)($input['airport']['icao'] ?? $settings['airport']['icao'])));
        if (preg_match('/^[A-Z0-9]{3,4}$/', $icao)) {
            $settings['airport']['icao'] = $icao;
        }
        // feed_center is fixed; do not accept airport lat/lon overrides.
    }

    if (isset($input['feed_center']) && is_array($input['feed_center'])) {
        $lat = filter_var($input['feed_center']['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $lon = filter_var($input['feed_center']['lon'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($lat !== false && $lat >= -90 && $lat <= 90) {
            $settings['feed_center']['lat'] = (float)$lat;
        }
        if ($lon !== false && $lon >= -180 && $lon <= 180) {
            $settings['feed_center']['lon'] = (float)$lon;
        }
        $radius = filter_var($input['feed_center']['radius_nm'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($radius !== false && $radius > 0) {
            $settings['feed_center']['radius_nm'] = min(250, (float)$radius);
        }
    }

    $uiInput = null;
    if (isset($input['ui_center']) && is_array($input['ui_center'])) {
        $uiInput = $input['ui_center'];
    } elseif (isset($input['display_center']) && is_array($input['display_center'])) {
        $uiInput = $input['display_center'];
    }

    if ($uiInput !== null) {
        $lat = filter_var($uiInput['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $lon = filter_var($uiInput['lon'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($lat !== false && $lat >= -90 && $lat <= 90) {
            $settings['ui_center']['lat'] = (float)$lat;
        }
        if ($lon !== false && $lon >= -180 && $lon <= 180) {
            $settings['ui_center']['lon'] = (float)$lon;
        }
    }

    $legacyRadius = filter_var($input['radius_nm'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($legacyRadius !== false && $legacyRadius > 0) {
        $settings['feed_center']['radius_nm'] = min(250, (float)$legacyRadius);
    }
    $pollInterval = filter_var($input['poll_interval_ms'] ?? null, FILTER_VALIDATE_INT);
    if ($pollInterval !== false && $pollInterval >= 500 && $pollInterval <= 5000) {
        $settings['poll_interval_ms'] = (int)$pollInterval;
    }

    if (isset($input['rings']) && is_array($input['rings'])) {
        if (isset($input['rings']['distances']) && is_array($input['rings']['distances'])) {
            $distances = [];
            foreach ($input['rings']['distances'] as $dist) {
                $val = filter_var($dist, FILTER_VALIDATE_FLOAT);
                if ($val !== false && $val > 0 && $val <= 250) {
                    $distances[] = (float)$val;
                }
            }
            if ($distances) {
                $settings['rings']['distances'] = array_values($distances);
            }
        }
        if (isset($input['rings']['style']) && is_array($input['rings']['style'])) {
            $settings['rings']['style']['color'] = normalizeHexColor(
                $input['rings']['style']['color'] ?? '',
                $settings['rings']['style']['color']
            );
            $weight = filter_var($input['rings']['style']['weight'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($weight !== false && $weight > 0 && $weight <= 10) {
                $settings['rings']['style']['weight'] = (float)$weight;
            }
            $dash = $input['rings']['style']['dash'] ?? '';
            if (is_string($dash)) {
                $settings['rings']['style']['dash'] = trim($dash);
            }
        }
    }

    if (isset($input['labels']) && is_array($input['labels'])) {
        $settings['labels']['show_alt'] = normalizeBoolean($input['labels']['show_alt'] ?? $settings['labels']['show_alt']);
        $settings['labels']['show_gs'] = normalizeBoolean($input['labels']['show_gs'] ?? $settings['labels']['show_gs']);
        $settings['labels']['show_vs'] = normalizeBoolean($input['labels']['show_vs'] ?? $settings['labels']['show_vs']);
        $settings['labels']['show_trk'] = normalizeBoolean($input['labels']['show_trk'] ?? $settings['labels']['show_trk']);
        $settings['labels']['show_sqk'] = normalizeBoolean($input['labels']['show_sqk'] ?? $settings['labels']['show_sqk']);
        $settings['labels']['show_labels'] = normalizeBoolean($input['labels']['show_labels'] ?? $settings['labels']['show_labels']);
        $minZoom = filter_var($input['labels']['min_zoom'] ?? null, FILTER_VALIDATE_INT);
        if ($minZoom !== false && $minZoom >= 3 && $minZoom <= 14) {
            $settings['labels']['min_zoom'] = (int)$minZoom;
        }
        $fontSize = filter_var($input['labels']['font_size'] ?? null, FILTER_VALIDATE_INT);
        if ($fontSize !== false && $fontSize >= 8 && $fontSize <= 24) {
            $settings['labels']['font_size'] = (int)$fontSize;
        }
        $settings['labels']['color'] = normalizeHexColor(
            $input['labels']['color'] ?? '',
            $settings['labels']['color']
        );
    }

    if (isset($input['display']) && is_array($input['display'])) {
        $basemap = strtolower(trim((string)($input['display']['basemap'] ?? $settings['display']['basemap'])));
        if (in_array($basemap, ['dark', 'light'], true)) {
            $settings['display']['basemap'] = $basemap;
        }
    }

    if (isset($input['navpoints']) && is_array($input['navpoints'])) {
        $settings['navpoints']['enabled'] = normalizeBoolean($input['navpoints']['enabled'] ?? $settings['navpoints']['enabled']);
        $minZoom = filter_var($input['navpoints']['min_zoom'] ?? null, FILTER_VALIDATE_INT);
        if ($minZoom !== false && $minZoom >= 3 && $minZoom <= 14) {
            $settings['navpoints']['min_zoom'] = (int)$minZoom;
        }
        $zone = strtolower(trim((string)($input['navpoints']['zone'] ?? $settings['navpoints']['zone'])));
        if (in_array($zone, ['all', 'nw', 'ne', 'central', 'west', 'south', 'se', 'mmtj-120'], true)) {
            $settings['navpoints']['zone'] = $zone;
        }
        $maxPoints = filter_var($input['navpoints']['max_points'] ?? null, FILTER_VALIDATE_INT);
        if ($maxPoints !== false && $maxPoints >= 250 && $maxPoints <= 5000) {
            $settings['navpoints']['max_points'] = (int)$maxPoints;
        }
    }

    if (isset($input['tracks']) && is_array($input['tracks'])) {
        $settings['tracks']['show_trail'] = normalizeBoolean($input['tracks']['show_trail'] ?? $settings['tracks']['show_trail']);
    }

    if (isset($input['leader']) && is_array($input['leader'])) {
        $mode = strtolower(trim((string)($input['leader']['mode'] ?? $settings['leader']['mode'])));
        if (in_array($mode, ['time', 'distance'], true)) {
            $settings['leader']['mode'] = $mode;
        }
        $timeMinutes = filter_var($input['leader']['time_minutes'] ?? null, FILTER_VALIDATE_INT);
        if ($timeMinutes !== false && in_array($timeMinutes, [1, 2, 3, 4, 5], true)) {
            $settings['leader']['time_minutes'] = (int)$timeMinutes;
        }
        $distanceNm = filter_var($input['leader']['distance_nm'] ?? null, FILTER_VALIDATE_INT);
        if ($distanceNm !== false && in_array($distanceNm, [1, 2, 5, 10, 20], true)) {
            $settings['leader']['distance_nm'] = (int)$distanceNm;
        }
    }

    if (isset($input['category_styles']) && is_array($input['category_styles'])) {
        $styles = [];
        foreach ($input['category_styles'] as $key => $style) {
            if (!is_array($style)) {
                continue;
            }
            $styles[$key] = [
                'color' => normalizeHexColor($style['color'] ?? '', $base['category_styles']['default']['color']),
                'weight' => $base['category_styles']['default']['weight'],
                'dash' => '',
            ];
            $weight = filter_var($style['weight'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($weight !== false && $weight > 0 && $weight <= 10) {
                $styles[$key]['weight'] = (float)$weight;
            }
            if (isset($style['dash']) && is_string($style['dash'])) {
                $styles[$key]['dash'] = trim($style['dash']);
            }
        }
        if ($styles) {
            $settings['category_styles'] = $styles;
        }
    }

    $fixed = fixedFeedCenter($config);
    $settings['feed_center']['lat'] = $fixed['lat'];
    $settings['feed_center']['lon'] = $fixed['lon'];
    $settings['feed_center']['radius_nm'] = $fixed['radius_nm'];

    return $settings;
}

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $pdo = ensureDatabase($config['settings_db']);
} catch (Throwable $e) {
    respond(['error' => 'Unable to initialize settings storage.'], 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query('SELECT data FROM settings WHERE id = 1');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stored = [];
    if ($row && $row['data']) {
        $decoded = json_decode($row['data'], true);
        if (is_array($decoded)) {
            $stored = $decoded;
        }
    }
    $settings = normalizeSettings($stored, $defaults, $config);
    $fixed = fixedFeedCenter($config);
    $needsSeed = false;
    if (empty($stored['ui_center']) || !is_array($stored['ui_center'])) {
        $settings['ui_center'] = $defaults['ui_center'];
        $needsSeed = true;
    }
    if (
        empty($stored['feed_center'])
        || !is_array($stored['feed_center'])
        || (float)($stored['feed_center']['lat'] ?? 0) !== $fixed['lat']
        || (float)($stored['feed_center']['lon'] ?? 0) !== $fixed['lon']
        || (float)($stored['feed_center']['radius_nm'] ?? 0) !== $fixed['radius_nm']
    ) {
        $settings['feed_center'] = $fixed;
        $needsSeed = true;
    }
    if (!$row || $needsSeed) {
        $stmt = $pdo->prepare('INSERT INTO settings (id, data, updated_at) VALUES (1, :data, :updated_at)
            ON CONFLICT(id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at');
        $stmt->execute([
            ':data' => json_encode($settings, JSON_UNESCAPED_SLASHES),
            ':updated_at' => gmdate('c'),
        ]);
    }
    $vatmexRepo = $config['vatmex_repo_dir'] ?? $config['vatmex_dir'] ?? null;
    $airacDir = $config['vatmex_airac_dir'] ?? null;
    $airacCycle = $config['last_airac_cycle'] ?? null;
    respond([
        'settings' => $settings,
        'airac_update_enabled' => (bool)$config['airac_update_enabled'],
        'vatmex_dir_configured' => !empty($config['vatmex_dir']),
        'vatmex_repo_configured' => !empty($vatmexRepo),
        'vatmex_airac_configured' => !empty($airacDir),
        'airac_cycle' => $airacCycle,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = file_get_contents('php://input');
    $input = json_decode($payload ?? '', true);
    if (!is_array($input)) {
        respond(['error' => 'Invalid JSON payload.'], 400);
    }
    $stmt = $pdo->query('SELECT data FROM settings WHERE id = 1');
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stored = [];
    if ($row && $row['data']) {
        $decoded = json_decode($row['data'], true);
        if (is_array($decoded)) {
            $stored = $decoded;
        }
    }
    $current = normalizeSettings($stored, $defaults, $config);
    $settings = normalizeSettings($input, $current, $config);
    $stmt = $pdo->prepare('INSERT INTO settings (id, data, updated_at) VALUES (1, :data, :updated_at)
        ON CONFLICT(id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at');
    $stmt->execute([
        ':data' => json_encode($settings, JSON_UNESCAPED_SLASHES),
        ':updated_at' => gmdate('c'),
    ]);
    $vatmexRepo = $config['vatmex_repo_dir'] ?? $config['vatmex_dir'] ?? null;
    $airacDir = $config['vatmex_airac_dir'] ?? null;
    $airacCycle = $config['last_airac_cycle'] ?? null;

    respond([
        'settings' => $settings,
        'airac_update_enabled' => (bool)$config['airac_update_enabled'],
        'vatmex_dir_configured' => !empty($config['vatmex_dir']),
        'vatmex_repo_configured' => !empty($vatmexRepo),
        'vatmex_airac_configured' => !empty($airacDir),
        'airac_cycle' => $airacCycle,
    ]);
}

respond(['error' => 'Method not allowed.'], 405);
