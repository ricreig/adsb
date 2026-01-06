<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../../config.php';
require __DIR__ . '/../../auth.php';
requireAuth($config);

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Method not allowed.'], 405);
}

if (empty($config['airac_update_enabled'])) {
    respond(['error' => 'AIRAC update is disabled.'], 403);
}

$vatmexDir = $config['vatmex_dir'];
if (!$vatmexDir || !is_dir($vatmexDir)) {
    respond(['error' => 'VATMEX directory not configured.'], 400);
}

$rootDir = realpath(__DIR__ . '/../../');
if ($rootDir === false) {
    respond(['error' => 'Unable to resolve application root.'], 500);
}

function runCommand(string $command): array
{
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes);
    if (!is_resource($process)) {
        return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'Failed to start process.'];
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return ['exit_code' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
}

$startedAt = gmdate('c');
$commands = [
    [
        'label' => 'git_pull',
        'command' => sprintf('git -C %s pull --ff-only', escapeshellarg($vatmexDir)),
    ],
    [
        'label' => 'update_airspace',
        'command' => sprintf(
            '%s %s %s',
            escapeshellcmd(PHP_BINARY),
            escapeshellarg($rootDir . '/update_airspace.php'),
            escapeshellarg($vatmexDir)
        ),
    ],
    [
        'label' => 'build_geojson',
        'command' => sprintf(
            '%s %s %s',
            escapeshellcmd(PHP_BINARY),
            escapeshellarg($rootDir . '/build_geojson.php'),
            escapeshellarg($vatmexDir)
        ),
    ],
    [
        'label' => 'validate_geojson',
        'command' => sprintf(
            '%s %s',
            escapeshellcmd(PHP_BINARY),
            escapeshellarg($rootDir . '/scripts/validate_geojson.php')
        ),
    ],
];

$combinedStdout = '';
$combinedStderr = '';
$exitCode = 0;
$ok = true;

foreach ($commands as $cmd) {
    $result = runCommand($cmd['command']);
    $combinedStdout .= sprintf("[%s] %s", $cmd['label'], $result['stdout']);
    $combinedStderr .= sprintf("[%s] %s", $cmd['label'], $result['stderr']);
    $exitCode = $result['exit_code'];
    if ($exitCode !== 0) {
        $ok = false;
        break;
    }
}

$finishedAt = gmdate('c');

$logEntry = [
    'started_at' => $startedAt,
    'finished_at' => $finishedAt,
    'exit_code' => $exitCode,
    'ok' => $ok,
    'stdout' => $combinedStdout,
    'stderr' => $combinedStderr,
];

$logPath = $rootDir . '/data/airac_update.log';
file_put_contents($logPath, json_encode($logEntry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);

respond([
    'ok' => $ok,
    'exit_code' => $exitCode,
    'stdout' => $combinedStdout,
    'stderr' => $combinedStderr,
    'started_at' => $startedAt,
    'finished_at' => $finishedAt,
]);
