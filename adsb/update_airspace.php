<?php
declare(strict_types=1);
/**
 * update_airspace.php
 *
 * Wrapper to convert VATMEX XML files into GeoJSON layers using
 * scripts/vatmex_to_geojson.php. // [MXAIR2026]
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

$script = __DIR__ . '/scripts/vatmex_to_geojson.php'; // [MXAIR2026]
if (!is_file($script)) {
    if ($isCli) {
        fwrite(STDERR, "Script missing: {$script}\n");
        exit(1);
    }
    respond(['error' => 'Conversion script missing.', 'path' => $script], 500);
}

$command = sprintf( // [MXAIR2026]
    '%s %s %s --import-sql', // [MXAIR2026]
    escapeshellcmd(PHP_BINARY),
    escapeshellarg($script),
    escapeshellarg($basePath)
);

$descriptorSpec = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$process = proc_open($command, $descriptorSpec, $pipes);
if (!is_resource($process)) {
    if ($isCli) {
        fwrite(STDERR, "Failed to start conversion process.\n");
        exit(1);
    }
    respond(['error' => 'Failed to start conversion process.'], 500);
}

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

if ($isCli) {
    echo $stdout;
    if ($stderr) {
        fwrite(STDERR, $stderr);
    }
    exit($exitCode);
}

respond([
    'ok' => $exitCode === 0,
    'exit_code' => $exitCode,
    'stdout' => $stdout,
    'stderr' => $stderr,
    'path' => $basePath,
]);
