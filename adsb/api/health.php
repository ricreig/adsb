<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require __DIR__ . '/../config.php';

$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($base === '/') {
    $base = '';
}

$cacheDir = __DIR__ . '/../data/cache';
$lastStatusPath = $cacheDir . '/upstream.status.json';
$lastStatus = null;
if (is_file($lastStatusPath)) {
    $contents = file_get_contents($lastStatusPath);
    if ($contents !== false) {
        $decoded = json_decode($contents, true);
        if (is_array($decoded)) {
            $lastStatus = $decoded;
        }
    }
}

$apcuAvailable = false;
if (function_exists('apcu_fetch')) {
    if (function_exists('apcu_enabled')) {
        $apcuAvailable = apcu_enabled();
    } else {
        $enabled = ini_get('apc.enabled');
        $apcuAvailable = $enabled !== '0' && $enabled !== false;
    }
}

$payload = [
    'base_path_detected' => $base,
    'php_version' => PHP_VERSION,
    'sqlite_available' => extension_loaded('pdo_sqlite'),
    'apcu_available' => $apcuAvailable,
    'last_upstream_status' => $lastStatus,
];

echo json_encode($payload, JSON_UNESCAPED_SLASHES);
