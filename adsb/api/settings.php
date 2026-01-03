<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config.php';

$defaults = [
    'airport' => [
        'icao' => $config['airport']['icao'],
        'lat' => (float)$config['airport']['lat'],
        'lon' => (float)$config['airport']['lon'],
    ],
    'radius_nm' => 250,
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
        'font_size' => 12,
        'color' => '#00ff00',
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

function normalizeSettings(array $input, array $defaults): array
{
    $settings = $defaults;

    if (isset($input['airport']) && is_array($input['airport'])) {
        $icao = strtoupper(trim((string)($input['airport']['icao'] ?? $settings['airport']['icao'])));
        if (preg_match('/^[A-Z0-9]{3,4}$/', $icao)) {
            $settings['airport']['icao'] = $icao;
        }
        $lat = filter_var($input['airport']['lat'] ?? null, FILTER_VALIDATE_FLOAT);
        $lon = filter_var($input['airport']['lon'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($lat !== false && $lat >= -90 && $lat <= 90) {
            $settings['airport']['lat'] = (float)$lat;
        }
        if ($lon !== false && $lon >= -180 && $lon <= 180) {
            $settings['airport']['lon'] = (float)$lon;
        }
    }

    $radius = filter_var($input['radius_nm'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($radius !== false && $radius > 0) {
        $settings['radius_nm'] = min(250, (float)$radius);
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
        $fontSize = filter_var($input['labels']['font_size'] ?? null, FILTER_VALIDATE_INT);
        if ($fontSize !== false && $fontSize >= 8 && $fontSize <= 24) {
            $settings['labels']['font_size'] = (int)$fontSize;
        }
        $settings['labels']['color'] = normalizeHexColor(
            $input['labels']['color'] ?? '',
            $settings['labels']['color']
        );
    }

    if (isset($input['category_styles']) && is_array($input['category_styles'])) {
        $styles = [];
        foreach ($input['category_styles'] as $key => $style) {
            if (!is_array($style)) {
                continue;
            }
            $styles[$key] = [
                'color' => normalizeHexColor($style['color'] ?? '', $defaults['category_styles']['default']['color']),
                'weight' => $defaults['category_styles']['default']['weight'],
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
    $settings = normalizeSettings($stored, $defaults);
    respond([
        'settings' => $settings,
        'airac_update_enabled' => (bool)$config['airac_update_enabled'],
        'vatmex_dir_configured' => !empty($config['vatmex_dir']),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = file_get_contents('php://input');
    $input = json_decode($payload ?? '', true);
    if (!is_array($input)) {
        respond(['error' => 'Invalid JSON payload.'], 400);
    }

    $settings = normalizeSettings($input, $defaults);
    $stmt = $pdo->prepare('INSERT INTO settings (id, data, updated_at) VALUES (1, :data, :updated_at)
        ON CONFLICT(id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at');
    $stmt->execute([
        ':data' => json_encode($settings, JSON_UNESCAPED_SLASHES),
        ':updated_at' => gmdate('c'),
    ]);

    respond([
        'settings' => $settings,
        'airac_update_enabled' => (bool)$config['airac_update_enabled'],
        'vatmex_dir_configured' => !empty($config['vatmex_dir']),
    ]);
}

respond(['error' => 'Method not allowed.'], 405);
