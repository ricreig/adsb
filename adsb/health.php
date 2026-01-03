<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($base === '/') {
    $base = '';
}
$apiBase = $base ? $base . '/api' : '/api';

$dataDir = __DIR__ . '/data';
$cacheDir = $config['feed_cache_dir'] ?? ($dataDir . '/cache');
$dbPath = $config['settings_db'] ?? ($dataDir . '/adsb.sqlite');

$sqliteAvailable = extension_loaded('sqlite3');
$dataDirWritable = is_dir($dataDir) && is_writable($dataDir);
$cacheDirWritable = is_dir($cacheDir) && is_writable($cacheDir);
$sqliteWritable = false;
if (is_file($dbPath)) {
    $sqliteWritable = is_writable($dbPath);
} else {
    $dbDir = dirname($dbPath);
    $sqliteWritable = is_dir($dbDir) && is_writable($dbDir);
}

$status = ($sqliteAvailable && $dataDirWritable && $cacheDirWritable && $sqliteWritable) ? 'ok' : 'degraded';

echo json_encode([
    'status' => $status,
    'app_base' => $base,
    'api_base' => $apiBase,
    'php_version' => PHP_VERSION,
    'sqlite_available' => $sqliteAvailable,
    'writable' => [
        'data_dir' => $dataDirWritable,
        'cache_dir' => $cacheDirWritable,
        'sqlite_file' => $sqliteWritable,
    ],
    'now' => date('c'),
], JSON_UNESCAPED_SLASHES);
