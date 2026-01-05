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
    $pdo->exec('CREATE TABLE IF NOT EXISTS flight_strips (
        hex TEXT PRIMARY KEY,
        position INTEGER NOT NULL,
        status TEXT NOT NULL,
        note TEXT,
        updated_at TEXT NOT NULL
    )');
    return $pdo;
}

try {
    $pdo = ensureDb($config['settings_db']);
} catch (Throwable $e) {
    respond(['error' => 'Unable to initialize strip storage.'], 500);
}

function fetchStrips(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT hex, position, status, note, updated_at FROM flight_strips ORDER BY position ASC');
    $strips = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $strips[] = [
            'hex' => $row['hex'],
            'position' => (int)$row['position'],
            'status' => $row['status'],
            'note' => $row['note'],
            'updated_at' => $row['updated_at'],
        ];
    }
    return $strips;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(['strips' => fetchStrips($pdo)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        respond(['error' => 'Invalid JSON payload.'], 400);
    }

    if (isset($payload['order']) && is_array($payload['order'])) {
        $order = array_values(array_filter(array_map('strval', $payload['order'])));
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('UPDATE flight_strips SET position = :position, updated_at = :updated_at WHERE hex = :hex');
        foreach ($order as $index => $hex) {
            $stmt->execute([
                ':position' => $index,
                ':updated_at' => gmdate('c'),
                ':hex' => strtoupper(trim($hex)),
            ]);
        }
        $pdo->commit();
        respond(['strips' => fetchStrips($pdo)]);
    }

    if (!isset($payload['strip']) || !is_array($payload['strip'])) {
        respond(['error' => 'Missing strip payload.'], 400);
    }

    $strip = $payload['strip'];
    $hex = strtoupper(trim((string)($strip['hex'] ?? '')));
    if ($hex === '') {
        respond(['error' => 'Invalid strip hex.'], 400);
    }
    $status = (string)($strip['status'] ?? 'normal');
    $note = isset($strip['note']) ? (string)$strip['note'] : '';

    $stmt = $pdo->prepare('SELECT position, status, note FROM flight_strips WHERE hex = :hex');
    $stmt->execute([':hex' => $hex]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $position = (int)$existing['position'];
        $status = $status ?: $existing['status'];
        $note = $note !== '' ? $note : ($existing['note'] ?? '');
    } else {
        $maxPos = $pdo->query('SELECT MAX(position) FROM flight_strips')->fetchColumn();
        $position = is_numeric($maxPos) ? ((int)$maxPos + 1) : 0;
    }

    $stmt = $pdo->prepare('INSERT INTO flight_strips (hex, position, status, note, updated_at)
        VALUES (:hex, :position, :status, :note, :updated_at)
        ON CONFLICT(hex) DO UPDATE SET position = excluded.position, status = excluded.status, note = excluded.note, updated_at = excluded.updated_at');
    $stmt->execute([
        ':hex' => $hex,
        ':position' => $position,
        ':status' => $status ?: 'normal',
        ':note' => $note,
        ':updated_at' => gmdate('c'),
    ]);

    respond(['strips' => fetchStrips($pdo)]);
}

respond(['error' => 'Method not allowed.'], 405);
