<?php

$payload = [
    'base_path' => dirname($_SERVER['SCRIPT_NAME']),
    'php_version' => PHP_VERSION,
    'sqlite_available' => extension_loaded('sqlite3'),
    'apcu_available' => extension_loaded('apcu'),
    'now' => date('c'),
];

header('Content-Type: application/json');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
