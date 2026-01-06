<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../../config.php';
require __DIR__ . '/../../auth.php';
requireAuth($config);
requireAdminAction($config);

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

$rootDir = realpath(__DIR__ . '/../../');
if ($rootDir === false) {
    respond(['error' => 'Unable to resolve application root.'], 500);
}

function functionAvailable(string $name): bool
{
    if (!function_exists($name)) {
        return false;
    }
    $disabled = ini_get('disable_functions');
    if ($disabled === false || $disabled === '') {
        return true;
    }
    $disabledList = array_map('trim', explode(',', $disabled));
    return !in_array($name, $disabledList, true);
}

function runCommand(string $command): array
{
    if (functionAvailable('proc_open')) {
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

    if (functionAvailable('exec')) {
        $output = [];
        $exitCode = 1;
        exec($command . ' 2>&1', $output, $exitCode);
        return ['exit_code' => $exitCode, 'stdout' => implode("\n", $output), 'stderr' => ''];
    }

    if (functionAvailable('shell_exec')) {
        $wrapped = $command . '; printf "\n__EXIT__:%s\n" $?';
        $output = shell_exec($wrapped);
        if ($output === null) {
            return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'shell_exec returned null.'];
        }
        $exitCode = 0;
        if (preg_match('/__EXIT__:(\d+)/', $output, $matches)) {
            $exitCode = (int)$matches[1];
            $output = preg_replace('/\n__EXIT__:\d+\n?/', '', $output);
        }
        return ['exit_code' => $exitCode, 'stdout' => $output, 'stderr' => ''];
    }

    return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'No execution functions available (proc_open/exec/shell_exec).'];
}

function findRepoRoot(string $path, int $maxDepth = 5): ?string
{
    $current = realpath($path);
    if ($current === false) {
        return null;
    }
    for ($i = 0; $i <= $maxDepth; $i++) {
        if (is_dir($current . '/.git')) {
            return $current;
        }
        $parent = dirname($current);
        if ($parent === $current) {
            break;
        }
        $current = $parent;
    }
    return null;
}

function detectAiracCycle(string $dir): ?string
{
    $candidates = [
        $dir . '/AIRAC.txt',
        $dir . '/airac.txt',
        $dir . '/README.md',
        $dir . '/metadata.json',
    ];
    foreach ($candidates as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }
        $contents = file_get_contents($candidate);
        if ($contents && preg_match('/AIRAC\\s*[:#-]?\\s*([0-9]{4,6})/i', $contents, $m)) {
            return $m[1];
        }
    }
    if (preg_match('/(AIRAC|CYCLE)[-_ ]?([0-9]{4,6})/i', $dir, $m)) {
        return $m[2];
    }
    return null;
}

function scoreAiracDir(string $dir): int
{
    $score = 0;
    if (is_file($dir . '/Airspace.xml')) {
        $score += 4;
    }
    if (is_dir($dir . '/Maps')) {
        $score += 3;
    }
    if (is_dir($dir . '/Maps/TMA Tijuana')) {
        $score += 2;
    }
    if (is_file($dir . '/RestrictedAreas.xml')) {
        $score += 2;
    }
    return $score;
}

function detectAiracDir(string $repoDir): ?array
{
    $candidates = [];
    $rootScore = scoreAiracDir($repoDir);
    if ($rootScore > 0) {
        $candidates[] = [
            'dir' => $repoDir,
            'score' => $rootScore,
            'cycle' => detectAiracCycle($repoDir),
            'mtime' => filemtime($repoDir) ?: 0,
        ];
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($repoDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    $iterator->setMaxDepth(4);
    foreach ($iterator as $item) {
        if (!$item->isDir()) {
            continue;
        }
        $dir = $item->getPathname();
        if (strpos($dir, DIRECTORY_SEPARATOR . '.git') !== false) {
            continue;
        }
        $score = scoreAiracDir($dir);
        if ($score <= 0) {
            continue;
        }
        $candidates[] = [
            'dir' => $dir,
            'score' => $score,
            'cycle' => detectAiracCycle($dir),
            'mtime' => filemtime($dir) ?: 0,
        ];
    }
    if (!$candidates) {
        return null;
    }
    usort($candidates, function (array $a, array $b): int {
        $cycleA = is_numeric($a['cycle']) ? (int)$a['cycle'] : 0;
        $cycleB = is_numeric($b['cycle']) ? (int)$b['cycle'] : 0;
        if ($cycleA !== $cycleB) {
            return $cycleB <=> $cycleA;
        }
        if ($a['score'] !== $b['score']) {
            return $b['score'] <=> $a['score'];
        }
        return $b['mtime'] <=> $a['mtime'];
    });
    return $candidates[0];
}

function writeRuntimeConfig(string $path, array $values): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $contents = "<?php\nreturn " . var_export($values, true) . ";\n";
    $tmp = $path . '.tmp';
    file_put_contents($tmp, $contents);
    rename($tmp, $path);
}

function rotateLogIfNeeded(string $logPath, int $maxBytes = 5242880): void
{
    if (!is_file($logPath)) {
        return;
    }
    $size = filesize($logPath);
    if ($size === false || $size < $maxBytes) {
        return;
    }
    $timestamp = gmdate('Ymd_His');
    $rotated = $logPath . '.' . $timestamp;
    rename($logPath, $rotated);
}

$vatmexRepo = $config['vatmex_repo_dir'] ?? null;
if (!$vatmexRepo && !empty($config['vatmex_dir'])) {
    $vatmexRepo = $config['vatmex_dir'];
}
if (!$vatmexRepo || !is_dir($vatmexRepo)) {
    respond(['error' => 'VATMEX directory not configured.'], 400);
}
$vatmexRepo = realpath($vatmexRepo) ?: $vatmexRepo;
$repoRoot = findRepoRoot($vatmexRepo) ?? $vatmexRepo;
if (!is_dir($repoRoot)) {
    respond(['error' => 'VATMEX repo root not found.', 'path' => $vatmexRepo], 400);
}

$startedAt = gmdate('c');
$combinedStdout = '';
$combinedStderr = '';
$exitCode = 0;
$ok = true;

$gitResult = runCommand(sprintf('git -C %s pull --ff-only', escapeshellarg($repoRoot)));
$combinedStdout .= sprintf("[git_pull] %s", $gitResult['stdout']);
$combinedStderr .= sprintf("[git_pull] %s", $gitResult['stderr']);
$exitCode = $gitResult['exit_code'];
if ($exitCode !== 0) {
    $ok = false;
}

$airacCandidate = null;
$airacDir = null;
$airacCycle = null;
if ($ok) {
    $airacCandidate = detectAiracDir($repoRoot);
    if ($airacCandidate === null) {
        $ok = false;
        $exitCode = 1;
        $combinedStderr .= '[detect_airac] Unable to locate AIRAC directory in VATMEX repo.';
    } else {
        $airacDir = $airacCandidate['dir'];
        $airacCycle = $airacCandidate['cycle'];
    }
}

if ($ok && $airacDir) {
    $runtimeConfigPath = $rootDir . '/data/runtime_config.php';
    writeRuntimeConfig($runtimeConfigPath, [
        'vatmex_repo_dir' => $repoRoot,
        'vatmex_airac_dir' => $airacDir,
        'vatmex_dir' => $airacDir,
        'last_airac_cycle' => $airacCycle,
    ]);
}

$commands = [];
if ($ok && $airacDir) {
    $commands = [
        [
            'label' => 'update_airspace',
            'command' => sprintf(
                '%s %s %s',
                escapeshellcmd(PHP_BINARY),
                escapeshellarg($rootDir . '/update_airspace.php'),
                escapeshellarg($airacDir)
            ),
        ],
        [
            'label' => 'build_geojson',
            'command' => sprintf(
                '%s %s %s',
                escapeshellcmd(PHP_BINARY),
                escapeshellarg($rootDir . '/build_geojson.php'),
                escapeshellarg($airacDir)
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
}

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
    'vatmex_repo_dir' => $repoRoot,
    'vatmex_airac_dir' => $airacDir,
    'airac_cycle' => $airacCycle,
];

$logPath = $rootDir . '/data/airac_update.log';
rotateLogIfNeeded($logPath);
file_put_contents($logPath, json_encode($logEntry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);

respond([
    'ok' => $ok,
    'exit_code' => $exitCode,
    'stdout' => $combinedStdout,
    'stderr' => $combinedStderr,
    'started_at' => $startedAt,
    'finished_at' => $finishedAt,
    'vatmex_repo_dir' => $repoRoot,
    'vatmex_airac_dir' => $airacDir,
    'airac_cycle' => $airacCycle,
]);
