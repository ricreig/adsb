<?php

declare(strict_types=1);

$version = '1.9.4';
$targetDir = dirname(__DIR__) . '/assets/vendor/leaflet';
$imagesDir = $targetDir . '/images';
$urls = [
    'leaflet.js' => "https://unpkg.com/leaflet@{$version}/dist/leaflet.js",
    'leaflet.css' => "https://unpkg.com/leaflet@{$version}/dist/leaflet.css",
    'images/marker-icon.png' => "https://unpkg.com/leaflet@{$version}/dist/images/marker-icon.png",
    'images/marker-icon-2x.png' => "https://unpkg.com/leaflet@{$version}/dist/images/marker-icon-2x.png",
    'images/marker-shadow.png' => "https://unpkg.com/leaflet@{$version}/dist/images/marker-shadow.png",
];

function fetchUrl(string $url): string
{
    if (!ini_get('allow_url_fopen') && !function_exists('curl_init')) {
        throw new RuntimeException('HTTP fetch unavailable (allow_url_fopen disabled and curl missing).');
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'ADSB-Leaflet-Fetcher');
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if ($body === false || $status >= 400) {
            $error = $body === false ? curl_error($ch) : 'HTTP ' . $status;
            curl_close($ch);
            throw new RuntimeException('Failed to fetch ' . $url . ': ' . $error);
        }
        curl_close($ch);
        return (string)$body;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'ignore_errors' => true,
            'header' => "User-Agent: ADSB-Leaflet-Fetcher\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('Failed to fetch ' . $url);
    }
    return (string)$body;
}

if (!is_dir($imagesDir)) {
    mkdir($imagesDir, 0775, true);
}

$results = [];
foreach ($urls as $path => $url) {
    $dest = $targetDir . '/' . $path;
    $contents = fetchUrl($url);
    file_put_contents($dest, $contents);
    $results[] = $dest;
}

echo "Leaflet assets downloaded:\n" . implode("\n", $results) . "\n";
