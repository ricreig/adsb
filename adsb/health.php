<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/config.php';

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($base === '/') {
    $base = '';
}
$apiBase = $base ? $base . '/api' : '/api';

$errors = [];
$status = 'ok';

$dataDir = __DIR__ . '/data';
$cacheDir = $config['feed_cache_dir'] ?? ($dataDir . '/cache');
$dbPath = $config['settings_db'] ?? ($dataDir . '/adsb.sqlite');

$sqliteAvailable = extension_loaded('sqlite3');
$apcuAvailable = function_exists('apcu_fetch') && (function_exists('apcu_enabled') ? apcu_enabled() : ini_get('apc.enabled'));

if (!is_dir($dataDir)) {
    $errors[] = 'data_dir_missing';
} elseif (!is_writable($dataDir)) {
    $errors[] = 'data_dir_not_writable';
}

if (!is_dir($cacheDir)) {
    $errors[] = 'cache_dir_missing';
} elseif (!is_writable($cacheDir)) {
    $errors[] = 'cache_dir_not_writable';
}

$dbExists = is_file($dbPath);
$dbWritable = false;
if ($dbExists) {
    $dbWritable = is_writable($dbPath);
    if (!$dbWritable) {
        $errors[] = 'settings_db_not_writable';
    }
} elseif (is_dir(dirname($dbPath)) && is_writable(dirname($dbPath))) {
    $tmpHandle = @fopen($dbPath, 'c+');
    if ($tmpHandle) {
        fclose($tmpHandle);
        $dbWritable = true;
        @unlink($dbPath);
    } else {
        $errors[] = 'settings_db_uncreatable';
    }
} else {
    $errors[] = 'settings_db_uncreatable';
}

if ($errors) {
    $status = 'degraded';
}

echo json_encode([
    'status' => $status,
    'base_path' => $base,
    'api_base' => $apiBase,
    'php_version' => PHP_VERSION,
    'sqlite_available' => $sqliteAvailable,
    'apcu_available' => (bool)$apcuAvailable,
    'data_dir_writable' => is_dir($dataDir) && is_writable($dataDir),
    'cache_dir_writable' => is_dir($cacheDir) && is_writable($cacheDir),
    'settings_db_exists' => $dbExists,
    'settings_db_writable' => $dbWritable,
    'errors' => $errors,
    'timestamp' => date('c'),
], JSON_UNESCAPED_SLASHES);
