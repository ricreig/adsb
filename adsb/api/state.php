<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config.php';
require __DIR__ . '/../auth.php';
requireAuth($config);

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureDb(string $dbPath): PDO
{
    $dir = dirname($dbPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE IF NOT EXISTS flight_states (
        hex TEXT PRIMARY KEY,
        data TEXT NOT NULL,
        updated_at TEXT NOT NULL
    )');
    return $pdo;
}

try {
    $pdo = ensureDb($config['settings_db']);
} catch (Throwable $e) {
    respond(['error' => 'Unable to initialize state storage.'], 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $hex = strtoupper(trim((string)($_GET['hex'] ?? '')));
    if ($hex !== '') {
        $stmt = $pdo->prepare('SELECT hex, data, updated_at FROM flight_states WHERE hex = :hex');
        $stmt->execute([':hex' => $hex]);
    } else {
        $stmt = $pdo->query('SELECT hex, data, updated_at FROM flight_states ORDER BY updated_at DESC');
    }
    $states = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $decoded = json_decode($row['data'], true);
        $states[] = array_merge(
            ['hex' => $row['hex'], 'updated_at' => $row['updated_at']],
            is_array($decoded) ? $decoded : []
        );
    }
    respond(['states' => $states]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        respond(['error' => 'Invalid JSON payload.'], 400);
    }
    $entries = [];
    if (isset($payload['states']) && is_array($payload['states'])) {
        $entries = $payload['states'];
    } elseif (isset($payload['state']) && is_array($payload['state'])) {
        $entries = [$payload['state']];
    } else {
        respond(['error' => 'Missing state data.'], 400);
    }

    $stmt = $pdo->prepare('INSERT INTO flight_states (hex, data, updated_at)
        VALUES (:hex, :data, :updated_at)
        ON CONFLICT(hex) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at');
    foreach ($entries as $entry) {
        if (!is_array($entry) || empty($entry['hex'])) {
            continue;
        }
        $hex = strtoupper(trim((string)$entry['hex']));
        if ($hex === '') {
            continue;
        }
        $data = [
            'lat' => isset($entry['lat']) ? (float)$entry['lat'] : null,
            'lon' => isset($entry['lon']) ? (float)$entry['lon'] : null,
            'alt' => isset($entry['alt']) ? (int)$entry['alt'] : null,
            'track' => isset($entry['track']) ? (int)$entry['track'] : null,
            'gs' => isset($entry['gs']) ? (int)$entry['gs'] : null,
            'status' => isset($entry['status']) ? (string)$entry['status'] : null,
        ];
        $stmt->execute([
            ':hex' => $hex,
            ':data' => json_encode($data, JSON_UNESCAPED_SLASHES),
            ':updated_at' => gmdate('c'),
        ]);
    }
    respond(['ok' => true]);
}

respond(['error' => 'Method not allowed.'], 405);
