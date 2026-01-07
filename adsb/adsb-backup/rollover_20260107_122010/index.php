<?php
// index.php
//
// Main entry point for the ATC display.  This page renders a full‑screen
// map using Leaflet and overlays multiple layers of Mexican airspace,
// navaids, and restricted areas.  Aircraft tracks are fetched from
// feed.php via Ajax.  Operators can toggle individual layers and
// customise colours.  Additional flight plan and route data may be
// integrated via external APIs.

// Load configuration
$config = require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
requireAuth($config);
$base = '/' . trim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($base === '/') {
    $base = '/';
} else {
    $base .= '/';
}
// Conservative CSP to reduce third-party execution. // [MXAIR2026-ROLL]
// TODO: remove 'unsafe-inline' once inline scripts/styles are migrated. // [MXAIR2026-ROLL]
$csp = "default-src 'self'; " // [MXAIR2026-ROLL]
    . "base-uri 'self'; " // [MXAIR2026-ROLL]
    . "form-action 'self'; " // [MXAIR2026-ROLL]
    . "frame-ancestors 'self'; " // [MXAIR2026-ROLL]
    . "object-src 'none'; " // [MXAIR2026-ROLL]
    . "script-src 'self' 'unsafe-inline' https://unpkg.com https://cdn.jsdelivr.net; " // [MXAIR2026-ROLL]
    . "style-src 'self' 'unsafe-inline' https://unpkg.com; " // [MXAIR2026-ROLL]
    . "img-src 'self' data: https://*.tile.openstreetmap.org https://*.basemaps.cartocdn.com https://tiles.stadiamaps.com; " // [MXAIR2026-ROLL]
    . "connect-src 'self' https://api.airplanes.live https://airplanes.live https://*.tile.openstreetmap.org https://*.basemaps.cartocdn.com https://tiles.stadiamaps.com; " // [MXAIR2026-ROLL]
    . "font-src 'self' data:;"; // [MXAIR2026-ROLL]
header("Content-Security-Policy: {$csp}"); // [MXAIR2026-ROLL]

// Discover available GeoJSON layers.  Each file in the configured
// directory becomes an entry in the layers list.  Files should be
// named in lowercase with hyphens, e.g. tma.geojson, ctr.geojson,
// restricted-areas.geojson, airways-upper.geojson.  The name before
// the .geojson extension is used as the layer identifier.  Files
// beginning with a dot are ignored.
$geojsonDir = $config['geojson_dir'];
$layerFiles = [];
if (is_dir($geojsonDir)) {
    foreach (scandir($geojsonDir) as $file) {
        if ($file[0] === '.') {
            continue;
        }
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'geojson') {
            continue;
        }
        $id = pathinfo($file, PATHINFO_FILENAME);
        $layerFiles[$id] = 'api/geojson.php?layer=' . rawurlencode($id);
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Mexican Airspace Display</title>
    <!-- Leaflet CSS: local primary (Safari-safe) with CDN fallback -->
    <link
        id="leaflet-css"
        rel="stylesheet"
        href="<?php echo htmlspecialchars($base . 'assets/vendor/leaflet/leaflet.css'); ?>"
        onerror="this.onerror=null;this.href='https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';"
    />
    <style>
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            overflow: hidden;
            font-family: sans-serif;
            background: #101820;
            color: #e0e0e0;
            font-size: calc(var(--ui-font-size, 12) * 1px); /* // [MXAIR2026] */
        }
        /* custom variables for label styling */
        :root {
            --label-size: 12;
            --label-color: #00ff00;
            --ui-font-size: 12; /* // [MXAIR2026] */
        }
        /* customise Leaflet tooltip (track label) */
        .leaflet-tooltip.track-label {
            color: var(--track-color, var(--label-color)); /* // [MXAIR2026-ROLL] */
            font-size: calc(var(--label-size) * 1px);
            font-weight: bold;
            white-space: pre;
            text-transform: uppercase;
            background: transparent;
            border: none;
            box-shadow: none;
            padding: 0;
        }
        .leaflet-tooltip.track-label::before { /* // [MXAIR2026-ROLL] */
            display: none; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .leaflet-tooltip.track-label .label-line {
            display: block;
            line-height: 1.15;
        }
        .leaflet-tooltip.track-label .label-muted {
            color: var(--track-muted-color, rgba(0, 255, 0, 0.7)); /* // [MXAIR2026-ROLL] */
        }
        .leaflet-tooltip.track-label .label-note {
            cursor: text;
            text-decoration: underline dotted;
        }
        .leaflet-tooltip.track-label .label-note.note-editing {
            background: rgba(3, 11, 20, 0.75);
            padding: 0 2px;
            border-radius: 2px;
        }
        .leaflet-tooltip.track-label.highlight { /* // [MXAIR2026-ROLL] */
            background: var(--track-highlight-bg, rgba(3, 11, 20, 0.75)); /* // [MXAIR2026-ROLL] */
            border: 1px solid var(--track-border-color, rgba(0, 255, 0, 0.5)); /* // [MXAIR2026-ROLL] */
            border-radius: 4px; /* // [MXAIR2026-ROLL] */
            padding: 4px 6px; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .leaflet-tooltip.track-label.stale { /* // [MXAIR2026-ROLL] */
            opacity: 0.45; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .flight-marker {
            background: transparent !important;
            border: none !important;
        }
        .flight-icon {
            width: 12px;
            height: 12px;
            border: 2px solid var(--flight-color, #50fa7b);
            border-radius: 2px;
            box-sizing: border-box;
            position: relative;
            background: transparent;
            opacity: var(--flight-opacity, 1);
        }
        .navpoint-icon {
            background: transparent !important;
            border: none !important;
        }
        .navpoint-triangle {
            width: 0;
            height: 0;
            border-left: 4px solid transparent;
            border-right: 4px solid transparent;
            border-top: 7px solid #ffd166;
        }
        .flight-icon::after {
            content: '';
            position: absolute;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--flight-color, #50fa7b);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }
        #map {
            position: absolute;
            top: 0;
            left: 0;
            right: 320px;
            height: 100vh;
        }
        body.sidebar-collapsed #map {
            right: 0;
        }
        .leaflet-container img,
        .leaflet-container .leaflet-tile {
            max-width: none !important;
            max-height: none !important;
        }
        #sidebar {
            position: absolute;
            top: 0;
            right: 0;
            width: 320px;
            bottom: 0;
            background: #1a2330;
            overflow-y: auto;
            padding: 10px;
            box-sizing: border-box;
            transition: transform 0.2s ease;
        }
        body.sidebar-collapsed #sidebar {
            transform: translateX(100%);
        }
        #sidebarToggle {
            position: absolute;
            top: 50px;
            right: 330px;
            z-index: 1200;
            background: #1a2330;
            color: #e0e0e0;
            border: 1px solid #3f5270;
            border-radius: 4px;
            padding: 6px 8px;
            cursor: pointer;
        }
        body.sidebar-collapsed #sidebarToggle {
            right: 10px;
        }
        /* // [MXAIR2026] UI control strip */
        #topControls {
            position: absolute;
            top: 10px;
            left: 10px;
            display: flex;
            gap: 6px;
            z-index: 1200;
        }
        .icon-btn {
            width: 34px;
            height: 34px;
            border-radius: 6px;
            border: 1px solid #3f5270;
            background: #1a2330;
            color: #e0e0e0;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        .floating-panel {
            position: absolute;
            top: 54px;
            left: 10px;
            width: 320px;
            max-height: calc(100vh - 80px);
            background: #1b2b42;
            border: 1px solid #3f5270;
            border-radius: 8px;
            z-index: 1200;
            display: none;
            flex-direction: column;
            overflow: hidden;
        }
        .floating-panel.active {
            display: flex;
        }
        .floating-panel .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 10px;
            background: #16263a;
            font-weight: bold;
        }
        .floating-panel .panel-tabs {
            display: flex;
            gap: 4px;
            padding: 6px;
            background: #1f2e44;
        }
        .floating-panel .panel-tabs button {
            flex: 1;
            border: 1px solid #3f5270;
            background: #1a2330;
            color: #e0e0e0;
            padding: 6px 8px;
            cursor: pointer;
            font-size: 12px;
        }
        .floating-panel .panel-tabs button.active {
            background: #2b3a50;
            border-color: #22d3ee;
        }
        .floating-panel .panel-body {
            overflow-y: auto;
            padding: 8px;
            font-size: 12px;
        }
        .panel-section {
            display: none;
        }
        .panel-section.active {
            display: block;
        }
        .panel-actions {
            display: flex;
            gap: 6px;
            margin-top: 8px;
        }
        .strip-detail-popup {
            margin-top: 6px;
            padding: 6px;
            border: 1px solid #22d3ee;
            background: #16263a;
            font-size: 12px;
            color: #cdd6f4;
        }
        #sidebar h2 {
            margin-top: 0;
            font-size: 16px;
            text-align: center;
        }
        .layer-control { /* // [MXAIR2026-ROLL] */
            margin-bottom: 10px; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .layer-control label { /* // [MXAIR2026-ROLL] */
            display: flex; /* // [MXAIR2026-ROLL] */
            align-items: center; /* // [MXAIR2026-ROLL] */
            margin-bottom: 4px; /* // [MXAIR2026-ROLL] */
            cursor: pointer; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .layer-control input[type="checkbox"] { /* // [MXAIR2026-ROLL] */
            margin-right: 6px; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .layer-control input[type="color"] { /* // [MXAIR2026-ROLL] */
            margin-left: auto; /* // [MXAIR2026-ROLL] */
            border: none; /* // [MXAIR2026-ROLL] */
            background: none; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .layer-group { /* // [MXAIR2026-ROLL] */
            border: 1px solid #2f405b; /* // [MXAIR2026-ROLL] */
            border-radius: 6px; /* // [MXAIR2026-ROLL] */
            margin-top: 10px; /* // [MXAIR2026-ROLL] */
            background: #16263a; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .layer-group-header { /* // [MXAIR2026-ROLL] */
            display: flex; /* // [MXAIR2026-ROLL] */
            justify-content: space-between; /* // [MXAIR2026-ROLL] */
            align-items: center; /* // [MXAIR2026-ROLL] */
            padding: 6px 8px; /* // [MXAIR2026-ROLL] */
            background: #1f3148; /* // [MXAIR2026-ROLL] */
            cursor: pointer; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .layer-group-header span { /* // [MXAIR2026-ROLL] */
            font-weight: bold; /* // [MXAIR2026-ROLL] */
            font-size: 12px; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .layer-group-actions { /* // [MXAIR2026-ROLL] */
            display: flex; /* // [MXAIR2026-ROLL] */
            gap: 4px; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .layer-group-actions button { /* // [MXAIR2026-ROLL] */
            font-size: 11px; /* // [MXAIR2026-ROLL] */
            padding: 2px 6px; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .layer-group-body { /* // [MXAIR2026-ROLL] */
            padding: 8px; /* // [MXAIR2026-ROLL] */
            display: none; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .layer-group.open .layer-group-body { /* // [MXAIR2026-ROLL] */
            display: block; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        #flightInfo {
            margin-top: 20px;
            font-size: 14px;
            line-height: 1.4;
        }
        #stripTray {
            margin-top: 10px;
        }
        #stripDetails {
            margin-top: 8px;
            padding: 6px;
            border: 1px solid #3f5270;
            background: #16263a;
            font-size: 12px;
            color: #cdd6f4;
        }
        #flightPlanPanel {
            margin-top: 12px;
            padding: 8px;
            border: 1px solid #3f5270;
            background: #1b2b42;
            font-size: 12px;
        }
        #flightPlanPanel h3 {
            margin: 0 0 6px 0;
            font-size: 13px;
        }
        #flightPlanPanel button {
            margin-top: 6px;
            width: 100%;
        }
        .strip { /* // [MXAIR2026-ROLL] */
            background: #1d2d44; /* // [MXAIR2026-ROLL] */
            border: 1px solid #3f5270; /* // [MXAIR2026-ROLL] */
            padding: 6px; /* // [MXAIR2026-ROLL] */
            margin-bottom: 6px; /* // [MXAIR2026-ROLL] */
            cursor: pointer; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .strip.announced { /* // [MXAIR2026-ROLL] */
            background: #f2f4f7; /* // [MXAIR2026-ROLL] */
            border-color: #d5dbe4; /* // [MXAIR2026-ROLL] */
            color: #1f2937; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .strip.announced .strip-header, /* // [MXAIR2026-ROLL] */
        .strip.announced .strip-meta { /* // [MXAIR2026-ROLL] */
            color: #1f2937; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .strip.assumed { /* // [MXAIR2026-ROLL] */
            border-color: #22c55e; /* // [MXAIR2026-ROLL] */
            background: #173625; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .strip.handoff { /* // [MXAIR2026-ROLL] */
            border-color: #00c1ff; /* // [MXAIR2026-ROLL] */
            background: #0b3a4f; /* // [MXAIR2026-ROLL] */
            opacity: 0.75; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .strip.selected {
            box-shadow: 0 0 0 2px rgba(34, 211, 238, 0.7);
        }
        .strip .strip-call {
            text-decoration: underline dotted;
            cursor: pointer;
        }
        .strip .strip-header {
            font-weight: bold;
            color: #e6f7ff;
            margin-bottom: 4px;
            display: flex;
            justify-content: space-between;
        }
        .strip .strip-meta {
            font-size: 12px;
            color: #c7d5e0;
        }
        .strip .strip-meta span {
            display: inline-block;
            margin-right: 6px;
        }
        .strip .strip-status { /* // [MXAIR2026-ROLL] */
            font-size: 11px; /* // [MXAIR2026-ROLL] */
            font-weight: bold; /* // [MXAIR2026-ROLL] */
            padding: 2px 6px; /* // [MXAIR2026-ROLL] */
            border-radius: 4px; /* // [MXAIR2026-ROLL] */
            background: #2b3a50; /* // [MXAIR2026-ROLL] */
            color: #d0e6ff; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .strip.announced .strip-status { /* // [MXAIR2026-ROLL] */
            background: #e5e7eb; /* // [MXAIR2026-ROLL] */
            color: #1f2937; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .strip.assumed .strip-status { /* // [MXAIR2026-ROLL] */
            background: #22c55e; /* // [MXAIR2026-ROLL] */
            color: #052e13; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .strip.handoff .strip-status { /* // [MXAIR2026-ROLL] */
            background: #00c1ff; /* // [MXAIR2026-ROLL] */
            color: #052338; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .strip.emergency-7700 { /* // [MXAIR2026-ROLL] */
            background: #7f1d1d; /* // [MXAIR2026-ROLL] */
            border-color: #dc2626; /* // [MXAIR2026-ROLL] */
            color: #fef2f2; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .strip.emergency-7600 { /* // [MXAIR2026-ROLL] */
            background: #92400e; /* // [MXAIR2026-ROLL] */
            border-color: #facc15; /* // [MXAIR2026-ROLL] */
            color: #fef3c7; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        .strip.emergency-7500 { /* // [MXAIR2026-ROLL] */
            background: #9a3412; /* // [MXAIR2026-ROLL] */
            border-color: #fb923c; /* // [MXAIR2026-ROLL] */
            color: #ffedd5; /* // [MXAIR2026-ROLL] */
        } /* // [MXAIR2026-ROLL] */
        #brlToggle,
        #brlAirport,
        #brlClear {
            white-space: nowrap;
            padding: 6px 8px;
        }
        #notif {
            position: absolute;
            top: 10px;
            left: 50%;
            transform: translateX(-50%);
            background: #e74c3c;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            display: none;
        }
        #feedStatus {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(14, 21, 32, 0.9);
            color: #e0e0e0;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            z-index: 1000;
        }
        #tileStatus {
            position: absolute;
            top: 50px;
            left: 10px;
            background: rgba(14, 21, 32, 0.9);
            color: #e0e0e0;
            padding: 4px 8px;
            border-radius: 4px;
            display: none;
            font-size: 11px;
            z-index: 1000;
        }
        #feedError {
            position: absolute;
            top: 36px;
            right: 10px;
            background: rgba(176, 58, 46, 0.95);
            color: #fff;
            padding: 6px 8px;
            border-radius: 4px;
            z-index: 1000;
            max-width: 360px;
            display: none;
            font-size: 12px;
            align-items: center;
            gap: 8px;
        }
        #feedError button {
            background: #2b1b18;
            color: #fff;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 3px;
            cursor: pointer;
            font-size: 11px;
            padding: 2px 6px;
        }
        #feedErrorText {
            flex: 1;
        }
        body.sidebar-collapsed #feedError {
            right: 10px !important;
        }
        body.sidebar-collapsed #feedStatus {
            right: 10px !important;
        }
        #debugInfo {
            position: absolute;
            left: 10px;
            bottom: 10px;
            z-index: 1200;
            background: rgba(14, 21, 32, 0.85);
            color: #facc15;
            padding: 4px 6px;
            border: 1px solid #facc15;
            border-radius: 4px;
            font-size: 12px;
            pointer-events: none;
        }
        #errorOverlay {
            position: fixed;
            top: 70px;
            right: 10px;
            width: 360px;
            background: rgba(14, 21, 32, 0.98);
            color: #fff;
            padding: 10px;
            font-size: 12px;
            z-index: 2000;
            display: none;
            max-height: 240px;
            overflow-y: auto;
            white-space: pre-wrap;
            border: 1px solid #b03a2e;
            border-radius: 6px;
        }
        #errorOverlay button {
            float: right;
            background: #2b1b18;
            color: #fff;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 3px;
            cursor: pointer;
            font-size: 11px;
            padding: 2px 6px;
            margin-left: 10px;
        }
        #diagnosticsContent {
            margin: 0;
            white-space: pre-wrap;
        }
        .settings-section {
            margin-top: 10px;
            padding-top: 8px;
            border-top: 1px solid #3f5270;
        }
        .console-box {
            background: #0e1520;
            color: #cdd6f4;
            padding: 8px;
            border-radius: 4px;
            border: 1px solid #3f5270;
            max-height: 160px;
            overflow-y: auto;
            white-space: pre-wrap;
        }
        .spinner {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 2px solid #cdd6f4;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-left: 6px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div id="map"></div>
    <div id="topControls"> <!-- // [MXAIR2026] -->
        <button id="layersToggle" class="icon-btn" type="button" aria-label="Capas">🗺️</button> <!-- // [MXAIR2026] -->
        <button id="settingsToggle" class="icon-btn" type="button" aria-label="Settings">⚙️</button> <!-- // [MXAIR2026] -->
    </div>
    <div id="controlPanel" class="floating-panel" aria-hidden="true"> <!-- // [MXAIR2026] -->
        <div class="panel-header">
            <span id="panelTitle">Panel</span>
            <button id="panelClose" class="icon-btn" type="button" aria-label="Cerrar">✕</button>
        </div>
        <div class="panel-tabs">
            <button type="button" class="panel-tab" data-tab="general">General</button>
            <button type="button" class="panel-tab" data-tab="layers">Capas</button>
            <button type="button" class="panel-tab" data-tab="appearance">Apariencias</button>
        </div>
        <div class="panel-body">
            <div class="panel-section" data-tab="general">
                <h3 style="margin-top:0;font-size:14px;text-align:center;">Display Settings</h3>
                <label style="display:block;margin-bottom:4px;">Primary Airport ICAO
                    <input type="text" id="airportInput" value="<?php echo htmlspecialchars($config['airport']['icao']); ?>" style="width:80px;margin-left:4px;"/>
                </label>
                <label style="display:block;margin-bottom:4px;">Feed Center Lat
                    <input type="number" id="feedCenterLatInput" step="0.0001" value="<?php echo htmlspecialchars($config['feed_center']['lat'] ?? $config['airport']['lat']); ?>" style="width:90px;margin-left:4px;" disabled/>
                </label>
                <label style="display:block;margin-bottom:4px;">Feed Center Lon
                    <input type="number" id="feedCenterLonInput" step="0.0001" value="<?php echo htmlspecialchars($config['feed_center']['lon'] ?? $config['airport']['lon']); ?>" style="width:90px;margin-left:4px;" disabled/>
                </label>
                <label style="display:block;margin-bottom:4px;">UI Center Lat
                    <input type="number" id="displayCenterLatInput" step="0.0001" value="<?php echo htmlspecialchars($config['ui_center']['lat'] ?? $config['display_center']['lat'] ?? 32.541); ?>" style="width:90px;margin-left:4px;"/>
                </label>
                <label style="display:block;margin-bottom:4px;">UI Center Lon
                    <input type="number" id="displayCenterLonInput" step="0.0001" value="<?php echo htmlspecialchars($config['ui_center']['lon'] ?? $config['display_center']['lon'] ?? -116.97); ?>" style="width:90px;margin-left:4px;"/>
                </label>
                <label style="display:block;margin-bottom:4px;">Radius (NM, max 250)
                    <input type="number" id="radiusInput" min="1" max="250" value="<?php echo htmlspecialchars((string)($config['feed_radius_nm'] ?? $config['adsb_radius'] ?? 250)); ?>" style="width:80px;margin-left:4px;" disabled/>
                </label>
                <label style="display:block;margin-bottom:4px;">Polling Interval (ms)
                    <input type="number" id="pollIntervalInput" min="500" max="5000" value="<?php echo (int)$config['poll_interval_ms']; ?>" style="width:80px;margin-left:4px;"/> <!-- // [MXAIR2026] -->
                </label>
                <label style="display:block;margin-bottom:4px;">Range Rings (NM, comma‑sep)
                    <input type="text" id="ringDistances" value="50,100,150,200,250" style="width:120px;margin-left:4px;"/>
                </label>
                <label style="display:block;margin-bottom:4px;">Ring Colour
                    <input type="color" id="ringColour" value="#6666ff" style="margin-left:4px;"/>
                </label>
                <label style="display:block;margin-bottom:4px;">Ring Weight
                    <input type="number" id="ringWeight" min="0.5" max="10" step="0.5" value="1" style="width:60px;margin-left:4px;"/>
                </label>
                <label style="display:block;margin-bottom:4px;">Ring Dash (CSS dash)
                    <input type="text" id="ringDash" value="6 6" style="width:90px;margin-left:4px;"/>
                </label>
                <label style="display:block;margin-bottom:4px;">
                    <input type="checkbox" id="showLabels" checked/>
                    Show Track Labels
                </label>
                <label style="display:block;margin-bottom:4px;">Label Min Zoom
                    <input type="number" id="labelMinZoom" min="3" max="14" value="7" style="width:60px;margin-left:4px;"/>
                </label>
                <label style="display:block;margin-bottom:4px;">
                    <input type="checkbox" id="showAltitude" checked/>
                    Show Altitude
                </label>
                <label style="display:block;margin-bottom:4px;">
                    <input type="checkbox" id="showSpeed" checked/>
                    Show Ground Speed
                </label>
                <label style="display:block;margin-bottom:4px;">
                    <input type="checkbox" id="showVerticalSpeed" checked/>
                    Show Vertical Speed
                </label>
                <label style="display:block;margin-bottom:4px;">
                    <input type="checkbox" id="showTrack" checked/>
                    Show Track
                </label>
                <label style="display:block;margin-bottom:4px;">
                    <input type="checkbox" id="showSquawk" checked/>
                    Show Squawk
                </label>
                <div class="settings-section"> <!-- // [MXAIR2026-ROLL] -->
                    <strong>Fixes</strong> <!-- // [MXAIR2026-ROLL] -->
                    <label style="display:block;margin-bottom:4px;"> <!-- // [MXAIR2026-ROLL] -->
                        <input type="checkbox" id="navpointsEnabled" checked/> <!-- // [MXAIR2026-ROLL] -->
                        Show Fixes <!-- // [MXAIR2026-ROLL] -->
                    </label>
                    <label style="display:block;margin-bottom:4px;">Fixes Min Zoom <!-- // [MXAIR2026-ROLL] -->
                        <input type="number" id="navpointsMinZoom" min="3" max="14" value="7" style="width:60px;margin-left:4px;"/>
                    </label>
                    <label style="display:block;margin-bottom:4px;">Fixes Zone <!-- // [MXAIR2026-ROLL] -->
                        <select id="navpointsZone" style="margin-left:4px;">
                            <option value="all">Todo México</option>
                            <option value="nw">NW México</option>
                            <option value="ne">NE México</option>
                            <option value="central">Centro</option>
                            <option value="west">Occidente</option>
                            <option value="south">Sur</option>
                            <option value="se">Sureste</option>
                            <option value="mmtj-120">Entorno MMTJ (120 NM)</option>
                        </select>
                    </label>
                    <label style="display:block;margin-bottom:4px;">Max Fixes <!-- // [MXAIR2026-ROLL] -->
                        <input type="number" id="navpointsMax" min="250" max="5000" value="2000" style="width:70px;margin-left:4px;"/>
                    </label>
                </div>
                <div class="settings-section">
                    <strong>Tracks &amp; Leading Line</strong>
                    <label style="display:block;margin-bottom:4px;">
                        <input type="checkbox" id="showTrail"/>
                        Show Track Trail
                    </label>
                    <label style="display:block;margin-bottom:4px;">Leading Line Mode
                        <select id="leaderMode" style="margin-left:4px;">
                            <option value="time">Time (minutes)</option>
                            <option value="distance">Distance (NM)</option>
                        </select>
                    </label>
                    <label style="display:block;margin-bottom:4px;" id="leaderTimeLabel">Leading Time
                        <select id="leaderTime" style="margin-left:4px;">
                            <option value="1">1 min</option>
                            <option value="2">2 min</option>
                            <option value="3">3 min</option>
                            <option value="4">4 min</option>
                            <option value="5">5 min</option>
                        </select>
                    </label>
                    <label style="display:block;margin-bottom:4px;" id="leaderDistanceLabel">Leading Distance
                        <select id="leaderDistance" style="margin-left:4px;">
                            <option value="1">1 NM</option>
                            <option value="2">2 NM</option>
                            <option value="5">5 NM</option>
                            <option value="10">10 NM</option>
                            <option value="20">20 NM</option>
                        </select>
                    </label>
                </div>
                <div class="settings-section">
                    <strong>AIRAC</strong> <!-- [MXAIR2026-ROLL] -->
                    <div style="margin-top:6px;">
                        <button id="airacUpdateBtn" style="width:100%;">UPDATE AIRAC (PULL DATA + REBUILD GEOJSON)</button> <!-- [MXAIR2026-ROLL] -->
                        <span id="airacSpinner" class="spinner" style="display:none;"></span>
                    </div>
                    <div id="airacHint" style="margin-top:6px;font-size:12px;color:#9fb3c8;">
                        Configura las rutas AIRAC y habilita <code>airac_update_enabled</code> en <code>config.php</code> para activar el botón. <!-- [MXAIR2026-ROLL] -->
                    </div>
                    <div id="airacConsole" class="console-box" style="margin-top:6px;display:none;"></div>
                </div>
                <button id="applySettings" style="width:100%;margin-top:10px;">Apply Settings</button>
            </div>
            <div class="panel-section" data-tab="layers">
                <div class="panel-actions"> <!-- // [MXAIR2026-ROLL] -->
                    <button id="layersShowAll" type="button">Mostrar todo</button> <!-- // [MXAIR2026-ROLL] -->
                    <button id="layersHideAll" type="button">Ocultar todo</button> <!-- // [MXAIR2026-ROLL] -->
                </div>
                <div class="layer-control" style="margin-top:8px;"> <!-- // [MXAIR2026-ROLL] -->
                    <strong>Filtrar por estaciones</strong> <!-- // [MXAIR2026-ROLL] -->
                    <input type="text" id="stationFilterInput" placeholder="MMMX, MMTJ, ..." style="width:100%;margin-top:4px;"/> <!-- // [MXAIR2026-ROLL] -->
                    <small style="display:block;margin-top:4px;color:#9fb3c8;">Activará SID/STAR/CTR/ATZ/TMA según las estaciones.</small> <!-- // [MXAIR2026-ROLL] -->
                </div>
                <div class="layer-group open" data-group="limits"> <!-- // [MXAIR2026-ROLL] -->
                    <div class="layer-group-header"> <!-- // [MXAIR2026-ROLL] -->
                        <span>Límites</span> <!-- // [MXAIR2026-ROLL] -->
                        <div class="layer-group-actions"> <!-- // [MXAIR2026-ROLL] -->
                            <button type="button" class="group-show" data-group="limits">Mostrar</button> <!-- // [MXAIR2026-ROLL] -->
                            <button type="button" class="group-hide" data-group="limits">Ocultar</button> <!-- // [MXAIR2026-ROLL] -->
                        </div>
                    </div>
                    <div class="layer-group-body" id="layerGroup-limits"></div> <!-- // [MXAIR2026-ROLL] -->
                </div>
                <div class="layer-group" data-group="airspace"> <!-- // [MXAIR2026-ROLL] -->
                    <div class="layer-group-header"> <!-- // [MXAIR2026-ROLL] -->
                        <span>Espacio aéreo</span> <!-- // [MXAIR2026-ROLL] -->
                        <div class="layer-group-actions"> <!-- // [MXAIR2026-ROLL] -->
                            <button type="button" class="group-show" data-group="airspace">Mostrar</button> <!-- // [MXAIR2026-ROLL] -->
                            <button type="button" class="group-hide" data-group="airspace">Ocultar</button> <!-- // [MXAIR2026-ROLL] -->
                        </div>
                    </div>
                    <div class="layer-group-body" id="layerGroup-airspace"></div> <!-- // [MXAIR2026-ROLL] -->
                </div>
                <div class="layer-group" data-group="restricted"> <!-- // [MXAIR2026-ROLL] -->
                    <div class="layer-group-header"> <!-- // [MXAIR2026-ROLL] -->
                        <span>Restrictivas</span> <!-- // [MXAIR2026-ROLL] -->
                        <div class="layer-group-actions"> <!-- // [MXAIR2026-ROLL] -->
                            <button type="button" class="group-show" data-group="restricted">Mostrar</button> <!-- // [MXAIR2026-ROLL] -->
                            <button type="button" class="group-hide" data-group="restricted">Ocultar</button> <!-- // [MXAIR2026-ROLL] -->
                        </div>
                    </div>
                    <div class="layer-group-body" id="layerGroup-restricted"></div> <!-- // [MXAIR2026-ROLL] -->
                </div>
                <div class="layer-group" data-group="navigation"> <!-- // [MXAIR2026-ROLL] -->
                    <div class="layer-group-header"> <!-- // [MXAIR2026-ROLL] -->
                        <span>Navegación</span> <!-- // [MXAIR2026-ROLL] -->
                        <div class="layer-group-actions"> <!-- // [MXAIR2026-ROLL] -->
                            <button type="button" class="group-show" data-group="navigation">Mostrar</button> <!-- // [MXAIR2026-ROLL] -->
                            <button type="button" class="group-hide" data-group="navigation">Ocultar</button> <!-- // [MXAIR2026-ROLL] -->
                        </div>
                    </div>
                    <div class="layer-group-body" id="layerGroup-navigation"></div> <!-- // [MXAIR2026-ROLL] -->
                </div>
                <div class="layer-group" data-group="procedures"> <!-- // [MXAIR2026-ROLL] -->
                    <div class="layer-group-header"> <!-- // [MXAIR2026-ROLL] -->
                        <span>Procedimientos</span> <!-- // [MXAIR2026-ROLL] -->
                        <div class="layer-group-actions"> <!-- // [MXAIR2026-ROLL] -->
                            <button type="button" class="group-show" data-group="procedures">Mostrar</button> <!-- // [MXAIR2026-ROLL] -->
                            <button type="button" class="group-hide" data-group="procedures">Ocultar</button> <!-- // [MXAIR2026-ROLL] -->
                        </div>
                    </div>
                    <div class="layer-group-body" id="layerGroup-procedures"></div> <!-- // [MXAIR2026-ROLL] -->
                </div>
                <div class="layer-group" data-group="aerodrome"> <!-- // [MXAIR2026-ROLL] -->
                    <div class="layer-group-header"> <!-- // [MXAIR2026-ROLL] -->
                        <span>Aeródromo</span> <!-- // [MXAIR2026-ROLL] -->
                        <div class="layer-group-actions"> <!-- // [MXAIR2026-ROLL] -->
                            <button type="button" class="group-show" data-group="aerodrome">Mostrar</button> <!-- // [MXAIR2026-ROLL] -->
                            <button type="button" class="group-hide" data-group="aerodrome">Ocultar</button> <!-- // [MXAIR2026-ROLL] -->
                        </div>
                    </div>
                    <div class="layer-group-body" id="layerGroup-aerodrome"></div> <!-- // [MXAIR2026-ROLL] -->
                </div>
            </div>
            <div class="panel-section" data-tab="appearance">
                <label style="display:block;margin-bottom:4px;">Basemap Style
                    <select id="basemapSelect" style="margin-left:4px;">
                        <option value="dark">Dark (Radar)</option>
                        <option value="light">Light</option>
                    </select>
                </label>
                <label style="display:block;margin-bottom:4px;">Label Font Size
                    <input type="number" id="labelFontSize" min="8" max="24" value="12" style="width:50px;margin-left:4px;"/>
                </label>
                <label style="display:block;margin-bottom:4px;">Label Colour
                    <input type="color" id="labelColour" value="#00ff00" style="margin-left:4px;"/>
                </label>
                <label style="display:block;margin-bottom:4px;">UI Text Size
                    <input type="number" id="uiFontSize" min="10" max="18" value="12" style="width:60px;margin-left:4px;"/>
                </label>
            </div>
        </div>
    </div>
    <button id="sidebarToggle" aria-label="Toggle sidebar">☰ Panel</button>
    <div id="tileStatus">Basemap fallback activated.</div>
    <div id="feedStatus">Feed: -- · Última actualización: --</div>
    <div id="feedError">
        <span id="feedErrorText"></span>
        <button id="feedErrorDetails" type="button">Detalles</button>
    </div>
    <div id="errorOverlay">
        <button id="diagnosticsClose" type="button">Cerrar</button>
        <pre id="diagnosticsContent"></pre>
    </div>
    <div id="sidebar">
        <h2>Tools</h2>
        <div style="display:flex;gap:6px;margin-bottom:10px;">
            <button id="brlToggle" style="flex:1;">BRL</button>
            <button id="brlAirport" style="flex:1;">AP BRL</button>
            <button id="brlClear" style="flex:1;">Clear BRL</button>
        </div>
        <h2>Flight Strips</h2>
        <div id="stripTray"></div>
        <h2>Selected Flight</h2>
        <div id="flightInfo">Click a flight to see details.</div>
        <div id="flightPlanPanel">
            <h3>Flight Plan</h3>
            <div id="flightPlanSummary">Selecciona un vuelo para ver el plan.</div>
            <button id="routeToggleBtn" type="button" disabled>Route OFF</button>
        </div>
    </div>
    <div id="notif"></div>
    <script>
    window.ADSB_BASE_PATH = <?php echo json_encode($base, JSON_UNESCAPED_SLASHES); ?>;
    window.ADSB_BASE = <?php echo json_encode($base, JSON_UNESCAPED_SLASHES); ?>;
    function normalizeBasePath(basePath) {
        if (!basePath) {
            return '/';
        }
        const trimmed = basePath.replace(/^\/+|\/+$/g, '');
        if (!trimmed) {
            return '/';
        }
        return `/${trimmed}/`;
    }
    function detectBasePath() {
        const path = window.location.pathname || '/';
        if (path.endsWith('/')) {
            return path;
        }
        const parts = path.split('/');
        parts.pop();
        return `${parts.join('/')}/`;
    }
    const ADSB_BASE = normalizeBasePath(window.ADSB_BASE || window.ADSB_BASE_PATH || detectBasePath());
    const authToken = new URLSearchParams(window.location.search).get('token');
    function appendAuthToken(url) {
        if (!authToken) {
            return url;
        }
        try {
            const parsed = new URL(url, window.location.origin);
            if (parsed.origin !== window.location.origin) {
                return url;
            }
            if (!parsed.searchParams.has('token')) {
                parsed.searchParams.append('token', authToken);
            }
            return parsed.toString();
        } catch (err) {
            return url;
        }
    }
    window.ADSB_BASE = ADSB_BASE;
    function buildUrl(path) {
        if (!path) {
            return appendAuthToken(location.origin + ADSB_BASE);
        }
        if (/^https?:\/\//i.test(path)) {
            return appendAuthToken(path);
        }
        const cleanPath = path.replace(/^\/+/, '');
        return appendAuthToken(`${location.origin}${ADSB_BASE}${cleanPath}`);
    }
    function apiUrl(path) {
        const cleanPath = path.replace(/^\/+/, '');
        if (cleanPath.startsWith('api/')) {
            return buildUrl(cleanPath);
        }
        return buildUrl(`api/${cleanPath}`);
    }

    // PHP passes the list of available GeoJSON layers as JSON here.
    const geojsonLayers = <?php echo json_encode($layerFiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>;
    let geojsonManifest = { ...geojsonLayers }; // [MXAIR2026-ROLL]

    function getGeojsonLayerUrl(id) {
        if (!geojsonLayers || !geojsonLayers[id]) {
            return null;
        }
        return buildUrl(geojsonLayers[id]);
    }
    function resolveGeojsonFile(id) { // [MXAIR2026-ROLL]
        return geojsonManifest && geojsonManifest[id] ? geojsonManifest[id] : null; // [MXAIR2026-ROLL]
    }
    const errorOverlay = document.getElementById('errorOverlay');
    const diagnosticsContent = document.getElementById('diagnosticsContent');
    const diagnosticsClose = document.getElementById('diagnosticsClose');
    const debugMode = new URLSearchParams(window.location.search).has('debug')
        || localStorage.getItem('adsb_debug') === '1';
    let debugInfo = null;
    if (debugMode) {
        debugInfo = document.createElement('div');
        debugInfo.id = 'debugInfo';
        debugInfo.textContent = 'markers: 0 · tracks: 0 · labels: 0 · polling: 0';
        document.body.appendChild(debugInfo);
    }
    const errorLog = [];
    let diagnosticsDismissed = false;
    const diagnostics = {
        healthStatus: 'pending',
        healthDetail: null,
        leafletSource: 'unknown',
        leafletUrl: null,
        feedStatus: 'unknown',
        feedUpdatedAt: null,
        feedCenterWarning: null,
        lastFeedError: null,
    };

    const defaultSettings = {
        airport: {
            icao: '<?php echo addslashes($config['airport']['icao']); ?>',
        },
        feed_center: {
            lat: <?php echo (float)($config['feed_center']['lat'] ?? $config['airport']['lat']); ?>,
            lon: <?php echo (float)($config['feed_center']['lon'] ?? $config['airport']['lon']); ?>,
            radius_nm: <?php echo (float)($config['feed_radius_nm'] ?? $config['adsb_radius'] ?? 250); ?>,
        },
        ui_center: {
            lat: <?php echo (float)($config['ui_center']['lat'] ?? $config['display_center']['lat'] ?? 32.541); ?>,
            lon: <?php echo (float)($config['ui_center']['lon'] ?? $config['display_center']['lon'] ?? -116.97); ?>,
        },
        poll_interval_ms: <?php echo (int)$config['poll_interval_ms']; ?>,
        rings: {
            distances: [50, 100, 150, 200, 250],
            style: {
                color: '#6666ff',
                weight: 1,
                dash: '6 6',
            },
        },
        labels: {
            show_alt: true,
            show_gs: true,
            show_vs: true,
            show_trk: true,
            show_sqk: true,
            show_labels: true,
            min_zoom: 7,
            font_size: 12,
            color: '#00ff00',
        },
        display: {
            basemap: 'dark',
            ui_font_size: 12, // [MXAIR2026]
        },
        navpoints: {
            enabled: true,
            min_zoom: 7,
            zone: 'all',
            max_points: 2000,
        },
        tracks: {
            show_trail: false,
        },
        leader: {
            mode: 'time',
            time_minutes: 2,
            distance_nm: 2,
        },
        category_styles: {
            default: {
                color: '#3aa0ff',
                weight: 1.5,
                dash: '',
            },
        },
    };

    const expectedFeedCenter = {
        lat: 29.0099590,
        lon: -114.5552580,
        radius_nm: 250,
    };

    const targetTtlMs = <?php echo (int)($config['target_ttl_s'] ?? 120); ?> * 1000; // [MXAIR2026-ROLL]
    const staleThresholdMs = Math.max(15000, Math.floor(targetTtlMs * 0.6)); // [MXAIR2026-ROLL]
    const trackHistoryMaxPoints = <?php echo (int)($config['track_history_max_points'] ?? 80); ?>;
    const trackHistoryMaxAgeMs = <?php echo (int)($config['track_history_max_age_s'] ?? 300); ?> * 1000;

    function getDefaultSettings() {
        return JSON.parse(JSON.stringify(defaultSettings));
    }

    function normalizeSettingsPayload(input) {
        const merged = { ...defaultSettings, ...(input || {}) };
        const feedSource = input && input.feed_center ? input.feed_center : {};
        merged.feed_center = { ...defaultSettings.feed_center, ...feedSource };
        if (input && input.radius_nm && !feedSource.radius_nm) {
            merged.feed_center.radius_nm = input.radius_nm;
        }
        const uiSource = input && input.ui_center ? input.ui_center : (input && input.display_center ? input.display_center : {});
        merged.ui_center = { ...defaultSettings.ui_center, ...uiSource };
        merged.tracks = { ...defaultSettings.tracks, ...(input && input.tracks ? input.tracks : {}) };
        merged.leader = { ...defaultSettings.leader, ...(input && input.leader ? input.leader : {}) };
        return merged;
    }

    let settings = window.settings || getDefaultSettings();
    window.settings = settings;

    function safeLoadStoredSettings() {
        try {
            const raw = localStorage.getItem('adsb_settings');
            if (!raw) {
                return;
            }
            const parsed = JSON.parse(raw);
            if (parsed && typeof parsed === 'object') {
                settings = normalizeSettingsPayload(parsed);
                window.settings = settings;
            }
        } catch (err) {
            console.warn('Failed to load stored settings', err);
        }
    }

    safeLoadStoredSettings();

    function safeStoreSettings() {
        try {
            localStorage.setItem('adsb_settings', JSON.stringify(settings));
        } catch (err) {
            console.warn('Failed to store settings locally', err);
        }
    }

    function renderDiagnostics() {
        const s = window.settings || settings || getDefaultSettings();
        const feedCenter = s && s.feed_center ? s.feed_center : defaultSettings.feed_center;
        const uiCenter = s && s.ui_center ? s.ui_center : defaultSettings.ui_center;
        const feedFixedOk = Number(feedCenter.lat.toFixed(7)) === Number(expectedFeedCenter.lat.toFixed(7))
            && Number(feedCenter.lon.toFixed(7)) === Number(expectedFeedCenter.lon.toFixed(7))
            && Number(feedCenter.radius_nm) === expectedFeedCenter.radius_nm;
        const lines = [
            'Diagnostics',
            `Health: ${diagnostics.healthStatus}`,
            diagnostics.healthDetail ? `Health detail: ${diagnostics.healthDetail}` : null,
            `FEED_CENTER: ${feedCenter.lat.toFixed(7)}, ${feedCenter.lon.toFixed(7)} · ${feedCenter.radius_nm} NM · ${feedFixedOk ? 'FIXED OK' : 'WARNING'}`,
            `UI_CENTER: ${uiCenter.lat.toFixed(3)}, ${uiCenter.lon.toFixed(3)}`,
            diagnostics.feedCenterWarning ? `FEED_CENTER warning: ${diagnostics.feedCenterWarning}` : null,
            `Leaflet: ${diagnostics.leafletSource}`,
            diagnostics.leafletUrl ? `Leaflet URL: ${diagnostics.leafletUrl}` : null,
            `Feed: ${diagnostics.feedStatus}`,
            diagnostics.feedUpdatedAt ? `Feed updated: ${diagnostics.feedUpdatedAt}` : null,
            diagnostics.lastFeedError ? `Last feed error (${diagnostics.lastFeedError.at}): ${diagnostics.lastFeedError.message}` : null,
            diagnostics.lastFeedError && diagnostics.lastFeedError.detail ? `Feed error detail: ${diagnostics.lastFeedError.detail}` : null,
        ].filter(Boolean);
        if (errorLog.length) {
            lines.push('', 'Errors:', ...errorLog);
        }
        diagnosticsContent.textContent = lines.join('\n');
    }

    function reportError(message, detail) {
        const line = detail ? `${message}\n${detail}` : message;
        errorLog.push(line);
        diagnosticsDismissed = false;
        renderDiagnostics();
    }

    function setFeedError(message, detail) {
        diagnostics.lastFeedError = {
            message: message || 'Feed error',
            detail: detail || '',
            at: new Date().toISOString(),
        };
    }

    function clearFeedError() {
        diagnostics.lastFeedError = null;
    }

    window.addEventListener('error', (event) => {
        if (!event) return;
        const detail = event.filename ? `${event.filename}:${event.lineno || ''}` : '';
        reportError(event.message || 'Unhandled error', detail);
    });
    window.addEventListener('unhandledrejection', (event) => {
        const reason = event.reason && event.reason.message ? event.reason.message : String(event.reason || 'Unknown rejection');
        reportError('Unhandled promise rejection', reason);
    });

    function formatFetchErrorDetail(err, fallbackUrl) {
        if (!err) {
            return fallbackUrl ? `URL: ${fallbackUrl}` : 'Unknown error';
        }
        const detailLines = [];
        if (err.url || fallbackUrl) {
            detailLines.push(`URL: ${err.url || fallbackUrl}`);
        }
        if (err.status) {
            detailLines.push(`HTTP ${err.status}${err.statusText ? ` ${err.statusText}` : ''}`);
        }
        if (err.contentType) {
            detailLines.push(`Content-Type: ${err.contentType}`);
        }
        if (err.message) {
            detailLines.push(`Error: ${err.message}`);
        }
        if (err.body) {
            detailLines.push(`Response: ${err.body.slice(0, 200)}`);
        }
        return detailLines.join('\n');
    }

    function fetchJson(url, options, context, allowedContentTypes = ['application/json']) {
        return fetch(url, options).then(async resp => {
            const bodyText = await resp.text().catch(() => '');
            if (!resp.ok) {
                const error = new Error(`${context || 'Request failed'} (${resp.status}) ${resp.statusText}`);
                error.status = resp.status;
                error.statusText = resp.statusText;
                error.url = url;
                error.contentType = resp.headers.get('content-type') || '';
                error.body = bodyText;
                throw error;
            }
            const contentType = resp.headers.get('content-type') || '';
            const allowed = allowedContentTypes.some(type => contentType.includes(type));
            if (!allowed) {
                const error = new Error(`${context || 'Request failed'} (unexpected content-type)`);
                error.status = resp.status;
                error.statusText = resp.statusText;
                error.url = url;
                error.contentType = contentType;
                error.body = bodyText;
                throw error;
            }
            try {
                return JSON.parse(bodyText);
            } catch (err) {
                const error = new Error(`${context || 'Request failed'} (invalid JSON)`);
                error.url = url;
                error.body = bodyText;
                throw error;
            }
        }).catch(err => {
            reportError(context || 'Fetch error', formatFetchErrorDetail(err, url));
            throw err;
        });
    }

    function fetchGeoJson(url, context) { // [MXAIR2026-ROLL]
        return fetch(url, { cache: 'no-store' }).then(async resp => { // [MXAIR2026-ROLL]
            const bodyText = await resp.text().catch(() => ''); // [MXAIR2026-ROLL]
            if (!resp.ok) { // [MXAIR2026-ROLL]
                const error = new Error(`${context || 'Request failed'} (${resp.status}) ${resp.statusText}`); // [MXAIR2026-ROLL]
                error.status = resp.status; // [MXAIR2026-ROLL]
                error.statusText = resp.statusText; // [MXAIR2026-ROLL]
                error.url = url; // [MXAIR2026-ROLL]
                error.contentType = resp.headers.get('content-type') || ''; // [MXAIR2026-ROLL]
                error.body = bodyText; // [MXAIR2026-ROLL]
                throw error; // [MXAIR2026-ROLL]
            }
            let parsed = null; // [MXAIR2026-ROLL]
            try { // [MXAIR2026-ROLL]
                parsed = JSON.parse(bodyText); // [MXAIR2026-ROLL]
            } catch (err) { // [MXAIR2026-ROLL]
                const error = new Error(`${context || 'Request failed'} (invalid GeoJSON)`); // [MXAIR2026-ROLL]
                error.url = url; // [MXAIR2026-ROLL]
                error.contentType = resp.headers.get('content-type') || ''; // [MXAIR2026-ROLL]
                error.body = bodyText; // [MXAIR2026-ROLL]
                throw error; // [MXAIR2026-ROLL]
            }
            if (!parsed || !parsed.type) { // [MXAIR2026-ROLL]
                const error = new Error('GeoJSON missing type'); // [MXAIR2026-ROLL]
                error.url = url; // [MXAIR2026-ROLL]
                throw error; // [MXAIR2026-ROLL]
            }
            return parsed; // [MXAIR2026-ROLL]
        }).catch(err => { // [MXAIR2026-ROLL]
            reportError(context || 'Fetch error', formatFetchErrorDetail(err, url)); // [MXAIR2026-ROLL]
            throw err; // [MXAIR2026-ROLL]
        }); // [MXAIR2026-ROLL]
    }

    function loadScript(url, options = {}) {
        return new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = url;
            if (options.integrity) {
                script.integrity = options.integrity;
            }
            if (options.crossorigin) {
                script.crossOrigin = options.crossorigin;
            }
            script.async = true;
            script.onload = () => resolve(url);
            script.onerror = () => reject(new Error(`Failed to load ${url}`));
            document.head.appendChild(script);
        });
    }

    async function loadLeaflet() {
        const urls = [ // [MXAIR2026-ROLL]
            { // [MXAIR2026-ROLL]
                url: buildUrl('assets/vendor/leaflet/leaflet.js'), // [MXAIR2026-ROLL]
            }, // [MXAIR2026-ROLL]
            { // [MXAIR2026-ROLL]
                url: 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', // [MXAIR2026-ROLL]
            }, // [MXAIR2026-ROLL]
            { // [MXAIR2026-ROLL]
                url: 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js', // [MXAIR2026-ROLL]
            }, // [MXAIR2026-ROLL]
        ];
        const attempted = [];
        const failures = [];
        for (const entry of urls) {
            const url = entry.url;
            attempted.push(url);
            try {
                await loadScript(url, entry);
                if (window.L) {
                    const source = url.includes('/assets/vendor/leaflet/leaflet.js') ? 'local' : 'cdn';
                    return { loaded: true, url, attempted, source };
                }
                failures.push(`${url} (loaded but L undefined)`);
            } catch (err) {
                failures.push(`${url} (${err.message || 'load failed'})`);
            }
        }
        return { loaded: false, attempted, failures };
    }

    function checkHealth() {
        const url = buildUrl('health.php');
        fetchJson(url, {}, `Health check (${url})`)
            .then(data => {
                diagnostics.healthStatus = data && data.status ? data.status : 'unknown';
                diagnostics.healthDetail = data ? JSON.stringify(data, null, 2) : null;
                diagnostics.feedCenterWarning = data && data.feed_center ? data.feed_center.warning : null;
                if (!data || data.status !== 'ok') {
                    reportError('Health check reported degraded status', JSON.stringify(data, null, 2));
                }
                renderDiagnostics();
            })
            .catch(() => {});
    }

    function initLeafletApp() {
    // Create the map
    const map = L.map('map', {
        zoomControl: false, // [MXAIR2026]
        attributionControl: true,
        preferCanvas: true,
    }).setView([
        <?php echo (float)($config['ui_center']['lat'] ?? $config['display_center']['lat'] ?? 32.541); ?>,
        <?php echo (float)($config['ui_center']['lon'] ?? $config['display_center']['lon'] ?? -116.97); ?>
    ], 8);
    L.control.zoom({ position: 'bottomleft' }).addTo(map); // [MXAIR2026]
    const tileStatus = document.getElementById('tileStatus');
    map.createPane('tracks'); // [MXAIR2026-ROLL]
    map.createPane('targets'); // [MXAIR2026-ROLL]
    map.createPane('labels'); // [MXAIR2026-ROLL]
    map.createPane('callouts'); // [MXAIR2026-ROLL]
    map.getPane('tracks').style.zIndex = 350; // [MXAIR2026-ROLL]
    map.getPane('targets').style.zIndex = 450; // [MXAIR2026-ROLL]
    map.getPane('callouts').style.zIndex = 600; // [MXAIR2026-ROLL]
    map.getPane('labels').style.zIndex = 650; // [MXAIR2026-ROLL]
    map.on('zoomend move', () => updateAllCallouts()); // [MXAIR2026-ROLL]
    const trackRenderer = L.canvas({ padding: 0.5 });
    const targetRenderer = L.canvas({ padding: 0.5 });
    const basemapConfig = {
        dark: {
            primary: '<?php echo $config['basemap_dark'] ?? $config['basemap']; ?>',
            fallback: '<?php echo $config['basemap_dark_fallback'] ?? $config['basemap_fallback']; ?>',
            attribution: '<?php echo addslashes($config['basemap_dark_attribution'] ?? $config['basemap_attribution']); ?>',
            fallbackAttribution: '<?php echo addslashes($config['basemap_dark_fallback_attribution'] ?? $config['basemap_fallback_attribution']); ?>',
        },
        light: {
            primary: '<?php echo $config['basemap_light'] ?? $config['basemap_fallback']; ?>',
            fallback: '<?php echo $config['basemap_light_fallback'] ?? $config['basemap']; ?>',
            attribution: '<?php echo addslashes($config['basemap_light_attribution'] ?? $config['basemap_fallback_attribution']); ?>',
            fallbackAttribution: '<?php echo addslashes($config['basemap_light_fallback_attribution'] ?? $config['basemap_attribution']); ?>',
        },
    };
    const basemapLayers = {};
    function createBasemapLayer(mode) {
        const config = basemapConfig[mode];
        if (!config) return null;
        const primary = L.tileLayer(config.primary, {
            maxZoom: 18,
            attribution: config.attribution,
        });
        const fallback = L.tileLayer(config.fallback, {
            maxZoom: 18,
            attribution: config.fallbackAttribution,
        });
        basemapLayers[mode] = {
            primary,
            fallback,
            errors: 0,
            fallbackActive: false,
        };
        primary.on('tileerror', () => {
            const state = basemapLayers[mode];
            state.errors += 1;
            if (state.errors >= 5 && map.hasLayer(state.primary) && !state.fallbackActive) {
                map.removeLayer(state.primary);
                state.fallback.addTo(map);
                state.fallbackActive = true;
                tileStatus.style.display = 'block';
                tileStatus.textContent = 'Basemap fallback activated.';
            }
        });
        primary.on('tileload', () => {
            const state = basemapLayers[mode];
            if (!state.fallbackActive) {
                state.errors = 0;
            }
        });
        return basemapLayers[mode];
    }
    createBasemapLayer('dark');
    createBasemapLayer('light');
    let activeBasemap = null;
    function switchBasemap(mode) {
        const next = basemapLayers[mode] || createBasemapLayer(mode);
        if (!next) {
            return;
        }
        if (activeBasemap) {
            map.removeLayer(activeBasemap.primary);
            map.removeLayer(activeBasemap.fallback);
        }
        next.errors = 0;
        next.fallbackActive = false;
        next.primary.addTo(map);
        activeBasemap = next;
        tileStatus.style.display = 'none';
    }
    switchBasemap('dark');

    // [MXAIR2026] GeoJSON overlay layers (fixed list)
    const layerStateKey = 'adsb_layer_states'; // [MXAIR2026-ROLL]
    const layerToggles = {}; // [MXAIR2026-ROLL]
    const layerDataCache = {}; // [MXAIR2026-ROLL]
    let navpointsLastWarning = 0; // [MXAIR2026-ROLL]
    let navpointsGeojson = null; // [MXAIR2026-ROLL]
    let navpointsGeojsonLoaded = false; // [MXAIR2026-ROLL]
    let navpointsUrl = resolveGeojsonFile('nav-fixes'); // [MXAIR2026-ROLL]
    const navpointColour = '#ffd166'; // [MXAIR2026-ROLL]
    const stationFilterInput = document.getElementById('stationFilterInput'); // [MXAIR2026-ROLL]
    let stationFilterList = []; // [MXAIR2026-ROLL]
    const layerGroupContainers = { // [MXAIR2026-ROLL]
        limits: document.getElementById('layerGroup-limits'), // [MXAIR2026-ROLL]
        airspace: document.getElementById('layerGroup-airspace'), // [MXAIR2026-ROLL]
        restricted: document.getElementById('layerGroup-restricted'), // [MXAIR2026-ROLL]
        navigation: document.getElementById('layerGroup-navigation'), // [MXAIR2026-ROLL]
        procedures: document.getElementById('layerGroup-procedures'), // [MXAIR2026-ROLL]
        aerodrome: document.getElementById('layerGroup-aerodrome'), // [MXAIR2026-ROLL]
    };

    function buildNavpointIcon() {
        return L.divIcon({
            className: 'navpoint-icon',
            html: `<div class="navpoint-triangle" style="border-top-color:${navpointColour}"></div>`,
            iconSize: [8, 8],
            iconAnchor: [4, 4],
        });
    }

    const navpointsLayer = L.layerGroup(); // [MXAIR2026-ROLL]

    const layerDefinitions = { // [MXAIR2026-ROLL]
        fir: { id: 'fir-limits', label: 'FIR/ACC', group: 'limits', file: resolveGeojsonFile('fir-limits'), layer: L.geoJSON(null, { style: { color: '#00b4d8', weight: 2, fill: false } }) }, // [MXAIR2026-ROLL]
        border: { id: 'mex-border', label: 'Frontera', group: 'limits', file: resolveGeojsonFile('mex-border'), layer: L.geoJSON(null, { style: { color: '#f4d35e', weight: 2, fill: false, dashArray: '6 4' } }) }, // [MXAIR2026-ROLL]
        ctr: { id: 'ctr', label: 'CTR', group: 'airspace', file: resolveGeojsonFile('ctr'), layer: L.geoJSON(null, { style: { color: '#06d6a0', weight: 2, fillOpacity: 0.08 } }) }, // [MXAIR2026-ROLL]
        tma: { id: 'tma', label: 'TMA', group: 'airspace', file: resolveGeojsonFile('tma'), layer: L.geoJSON(null, { style: { color: '#5ec8ff', weight: 2, fillOpacity: 0.08 } }) }, // [MXAIR2026-ROLL]
        atz: { id: 'atz', label: 'ATZ', group: 'airspace', file: resolveGeojsonFile('atz'), layer: L.geoJSON(null, { style: { color: '#ffd166', weight: 2, fillOpacity: 0.08 } }) }, // [MXAIR2026-ROLL]
        restricted: { id: 'restricted-areas', label: 'Áreas restringidas', group: 'restricted', file: resolveGeojsonFile('restricted-areas'), layer: L.geoJSON(null, { style: { color: '#ef476f', weight: 2, fillOpacity: 0.12 } }) }, // [MXAIR2026-ROLL]
        navpoints: { id: 'nav-fixes', label: 'Fixes', group: 'navigation', layer: navpointsLayer }, // [MXAIR2026-ROLL]
        airwaysUpper: { id: 'airways-upper', label: 'Aerovías superiores', group: 'navigation', file: resolveGeojsonFile('airways-upper'), layer: L.geoJSON(null, { style: { color: '#9b5de5', weight: 1.5, dashArray: '4 4' } }) }, // [MXAIR2026-ROLL]
        airwaysLower: { id: 'airways-lower', label: 'Aerovías inferiores', group: 'navigation', file: resolveGeojsonFile('airways-lower'), layer: L.geoJSON(null, { style: { color: '#00bbf9', weight: 1.5, dashArray: '2 4' } }) }, // [MXAIR2026-ROLL]
        sid: { id: 'procedures-sid', label: 'SID', group: 'procedures', file: resolveGeojsonFile('procedures-sid'), layer: L.geoJSON(null, { style: { color: '#ff9f1c', weight: 2, dashArray: '6 6' } }) }, // [MXAIR2026-ROLL]
        star: { id: 'procedures-star', label: 'STAR', group: 'procedures', file: resolveGeojsonFile('procedures-star'), layer: L.geoJSON(null, { style: { color: '#2ec4b6', weight: 2, dashArray: '2 6' } }) }, // [MXAIR2026-ROLL]
        app: { id: 'procedures-app', label: 'APP', group: 'procedures', file: resolveGeojsonFile('procedures-app'), layer: L.geoJSON(null, { style: { color: '#e71d36', weight: 2 } }) }, // [MXAIR2026-ROLL]
        asmgcs: { id: 'aerodrome-asmgcs', label: 'ASMGCS', group: 'aerodrome', file: resolveGeojsonFile('aerodrome-asmgcs'), layer: L.geoJSON(null, { style: { color: '#f97316', weight: 1.5 } }) }, // [MXAIR2026-ROLL]
    }; // [MXAIR2026-ROLL]
    const layerAvailability = {}; // [MXAIR2026-ROLL]
    function applyGeojsonManifest(manifest) { // [MXAIR2026-ROLL]
        geojsonManifest = manifest && typeof manifest === 'object' ? { ...manifest } : {}; // [MXAIR2026-ROLL]
        Object.values(layerDefinitions).forEach(def => { // [MXAIR2026-ROLL]
            if (!def || !def.id) { // [MXAIR2026-ROLL]
                return; // [MXAIR2026-ROLL]
            }
            const available = !!geojsonManifest[def.id]; // [MXAIR2026-ROLL]
            layerAvailability[def.id] = available; // [MXAIR2026-ROLL]
            if (Object.prototype.hasOwnProperty.call(def, 'file')) { // [MXAIR2026-ROLL]
                def.file = available ? geojsonManifest[def.id] : null; // [MXAIR2026-ROLL]
            }
            if (def.id === 'nav-fixes') { // [MXAIR2026-ROLL]
                navpointsUrl = available ? geojsonManifest[def.id] : null; // [MXAIR2026-ROLL]
            }
        }); // [MXAIR2026-ROLL]
    }
    function refreshGeojsonManifest() { // [MXAIR2026-ROLL]
        return fetchJson(apiUrl('geojson_manifest.php'), {}, 'GeoJSON manifest') // [MXAIR2026-ROLL]
            .then(data => { // [MXAIR2026-ROLL]
                if (data && data.ok && data.layers) { // [MXAIR2026-ROLL]
                    applyGeojsonManifest(data.layers); // [MXAIR2026-ROLL]
                    syncLayerToggleAvailability(); // [MXAIR2026-ROLL]
                } // [MXAIR2026-ROLL]
            }) // [MXAIR2026-ROLL]
            .catch(err => { // [MXAIR2026-ROLL]
                console.warn('Failed to refresh GeoJSON manifest', err); // [MXAIR2026-ROLL]
            }); // [MXAIR2026-ROLL]
    }
    applyGeojsonManifest(geojsonManifest); // [MXAIR2026-ROLL]

    const stationFilteredLayers = new Set(['ctr', 'tma', 'atz', 'sid', 'star', 'app']); // [MXAIR2026-ROLL]

    function loadLayerState() {
        const defaults = {
            fir: false,
            border: false,
            ctr: false,
            tma: false,
            atz: false,
            restricted: false,
            navpoints: settings.navpoints.enabled, // [MXAIR2026-ROLL]
            asmgcs: false, // [MXAIR2026-ROLL]
            sid: false,
            star: false,
            app: false,
            airwaysUpper: false,
            airwaysLower: false,
        };
        try {
            const raw = localStorage.getItem(layerStateKey);
            if (!raw) {
                return defaults;
            }
            const parsed = JSON.parse(raw);
            return { ...defaults, ...(parsed || {}) };
        } catch (err) {
            return defaults;
        }
    }

    let layerState = loadLayerState();

    function saveLayerState() {
        try {
            localStorage.setItem(layerStateKey, JSON.stringify(layerState));
        } catch (err) {
            console.warn('Failed to store layer state', err);
        }
    }

    function matchesStation(feature) {
        if (!stationFilterList.length) {
            return true;
        }
        const props = feature.properties || {};
        const name = String(props.name || props.ident || props.icao || '').toUpperCase();
        return stationFilterList.some(code => name.includes(code));
    }

    function loadStaticLayerData(key) {
        const def = layerDefinitions[key];
        if (!def || !def.file) {
            return Promise.resolve(null);
        }
        if (layerDataCache[key]) {
            return Promise.resolve(layerDataCache[key]);
        }
        const url = buildUrl(def.file);
        return fetchGeoJson(url, `GeoJSON layer ${key} (${url})`)
            .then(data => {
                const normalized = normalizeGeojson(data, {
                    forcePolygon: ['ctr', 'tma', 'atz', 'restricted'].includes(key),
                });
                layerDataCache[key] = normalized;
                return normalized;
            })
            .catch(err => {
                reportError(`Failed to load ${key} layer`, formatFetchErrorDetail(err, def.file));
                return null;
            });
    }

    function loadNavpointsGeojson() {
        if (navpointsGeojsonLoaded) {
            return Promise.resolve(navpointsGeojson);
        }
        navpointsGeojsonLoaded = true;
        if (!navpointsUrl) { // [MXAIR2026-ROLL]
            reportError('Fixes layer missing', 'nav-fixes.geojson not available'); // [MXAIR2026-ROLL]
            navpointsGeojsonLoaded = false; // [MXAIR2026-ROLL]
            return Promise.resolve(null); // [MXAIR2026-ROLL]
        }
        const url = buildUrl(navpointsUrl); // [MXAIR2026-ROLL]
        return fetchGeoJson(url, `Fixes layer (${url})`) // [MXAIR2026-ROLL]
            .then(data => {
                navpointsGeojson = data;
                return data;
            })
            .catch(err => {
                navpointsGeojsonLoaded = false;
                reportError('Failed to load fixes layer', formatFetchErrorDetail(err, url)); // [MXAIR2026-ROLL]
                return null;
            });
    }

    function refreshLayerData(key) {
        const def = layerDefinitions[key];
        if (!def || !def.layer || !layerDataCache[key]) {
            return;
        }
        if (stationFilteredLayers.has(key)) {
            def.layer.options.filter = matchesStation;
        }
        def.layer.clearLayers();
        def.layer.addData(layerDataCache[key]);
        def.layer.eachLayer(layerEl => {
            if (layerEl.feature && layerEl.feature.properties && layerEl.feature.properties.name) {
                layerEl.bindTooltip(layerEl.feature.properties.name, { permanent: false });
            }
        });
    }

    function setLayerEnabled(key, enabled) {
        const def = layerDefinitions[key];
        if (!def || !def.layer) {
            return;
        }
        layerState[key] = enabled;
        saveLayerState();
        if (key === 'navpoints') {
            settings.navpoints.enabled = enabled;
            updateNavpoints();
            return;
        }
        if (enabled) {
            loadStaticLayerData(key).then(() => {
                refreshLayerData(key);
                if (!map.hasLayer(def.layer)) {
                    def.layer.addTo(map);
                }
            });
        } else if (map.hasLayer(def.layer)) {
            map.removeLayer(def.layer);
        }
    }

    function addLayerToggle(key, labelText, defaultEnabled = false) { // [MXAIR2026-ROLL]
        const def = layerDefinitions[key]; // [MXAIR2026-ROLL]
        if (!def || !def.group || !layerGroupContainers[def.group]) { // [MXAIR2026-ROLL]
            return null; // [MXAIR2026-ROLL]
        }
        const wrapper = document.createElement('div'); // [MXAIR2026-ROLL]
        wrapper.className = 'layer-control'; // [MXAIR2026-ROLL]
        const label = document.createElement('label'); // [MXAIR2026-ROLL]
        const checkbox = document.createElement('input'); // [MXAIR2026-ROLL]
        checkbox.type = 'checkbox'; // [MXAIR2026-ROLL]
        checkbox.checked = layerState[key] ?? defaultEnabled; // [MXAIR2026-ROLL]
        checkbox.addEventListener('change', () => { // [MXAIR2026-ROLL]
            setLayerEnabled(key, checkbox.checked); // [MXAIR2026-ROLL]
        }); // [MXAIR2026-ROLL]
        label.appendChild(checkbox); // [MXAIR2026-ROLL]
        label.appendChild(document.createTextNode(` ${labelText}`)); // [MXAIR2026-ROLL]
        wrapper.appendChild(label); // [MXAIR2026-ROLL]
        layerGroupContainers[def.group].appendChild(wrapper); // [MXAIR2026-ROLL]
        layerToggles[key] = checkbox; // [MXAIR2026-ROLL]
        if (checkbox.checked) { // [MXAIR2026-ROLL]
            setLayerEnabled(key, true); // [MXAIR2026-ROLL]
        }
        return checkbox; // [MXAIR2026-ROLL]
    }

    function syncLayerToggleAvailability() { // [MXAIR2026-ROLL]
        Object.keys(layerDefinitions).forEach(key => { // [MXAIR2026-ROLL]
            const def = layerDefinitions[key]; // [MXAIR2026-ROLL]
            if (!def || !def.id) { // [MXAIR2026-ROLL]
                return; // [MXAIR2026-ROLL]
            }
            const available = layerAvailability[def.id] !== false; // [MXAIR2026-ROLL]
            const toggle = layerToggles[key]; // [MXAIR2026-ROLL]
            if (toggle) { // [MXAIR2026-ROLL]
                toggle.disabled = !available; // [MXAIR2026-ROLL]
                if (!available) { // [MXAIR2026-ROLL]
                    toggle.checked = false; // [MXAIR2026-ROLL]
                    setLayerEnabled(key, false); // [MXAIR2026-ROLL]
                } // [MXAIR2026-ROLL]
            } // [MXAIR2026-ROLL]
            if (!available && def.group && layerGroupContainers[def.group]) { // [MXAIR2026-ROLL]
                const wrapper = toggle ? toggle.closest('.layer-control') : null; // [MXAIR2026-ROLL]
                if (wrapper) { // [MXAIR2026-ROLL]
                    wrapper.remove(); // [MXAIR2026-ROLL]
                } // [MXAIR2026-ROLL]
            } // [MXAIR2026-ROLL]
        }); // [MXAIR2026-ROLL]
        updateGroupStates(); // [MXAIR2026-ROLL]
    }

    function updateGroupStates() { // [MXAIR2026-ROLL]
        Object.keys(layerGroupContainers).forEach(groupId => { // [MXAIR2026-ROLL]
            const container = layerGroupContainers[groupId]; // [MXAIR2026-ROLL]
            if (!container) { // [MXAIR2026-ROLL]
                return; // [MXAIR2026-ROLL]
            }
            const groupEl = container.closest('.layer-group'); // [MXAIR2026-ROLL]
            if (!groupEl) { // [MXAIR2026-ROLL]
                return; // [MXAIR2026-ROLL]
            }
            const hasControls = !!container.querySelector('.layer-control'); // [MXAIR2026-ROLL]
            groupEl.style.display = hasControls ? '' : 'none'; // [MXAIR2026-ROLL]
        }); // [MXAIR2026-ROLL]
    }

    function setGroupEnabled(groupId, enabled) { // [MXAIR2026-ROLL]
        Object.keys(layerDefinitions).forEach(key => { // [MXAIR2026-ROLL]
            const def = layerDefinitions[key]; // [MXAIR2026-ROLL]
            if (!def || def.group !== groupId) { // [MXAIR2026-ROLL]
                return; // [MXAIR2026-ROLL]
            }
            const toggle = layerToggles[key]; // [MXAIR2026-ROLL]
            if (toggle && !toggle.disabled) { // [MXAIR2026-ROLL]
                toggle.checked = enabled; // [MXAIR2026-ROLL]
                setLayerEnabled(key, enabled); // [MXAIR2026-ROLL]
            } // [MXAIR2026-ROLL]
        }); // [MXAIR2026-ROLL]
    }

    document.querySelectorAll('.layer-group-header').forEach(header => { // [MXAIR2026-ROLL]
        header.addEventListener('click', (event) => { // [MXAIR2026-ROLL]
            if (event.target.closest('button')) { // [MXAIR2026-ROLL]
                return; // [MXAIR2026-ROLL]
            }
            const group = header.closest('.layer-group'); // [MXAIR2026-ROLL]
            if (group) { // [MXAIR2026-ROLL]
                group.classList.toggle('open'); // [MXAIR2026-ROLL]
            }
        }); // [MXAIR2026-ROLL]
    }); // [MXAIR2026-ROLL]
    document.querySelectorAll('.group-show').forEach(btn => { // [MXAIR2026-ROLL]
        btn.addEventListener('click', () => setGroupEnabled(btn.dataset.group, true)); // [MXAIR2026-ROLL]
    }); // [MXAIR2026-ROLL]
    document.querySelectorAll('.group-hide').forEach(btn => { // [MXAIR2026-ROLL]
        btn.addEventListener('click', () => setGroupEnabled(btn.dataset.group, false)); // [MXAIR2026-ROLL]
    }); // [MXAIR2026-ROLL]

    addLayerToggle('fir', 'FIR/ACC'); // [MXAIR2026-ROLL]
    addLayerToggle('border', 'Frontera'); // [MXAIR2026-ROLL]
    addLayerToggle('ctr', 'CTR'); // [MXAIR2026-ROLL]
    addLayerToggle('tma', 'TMA'); // [MXAIR2026-ROLL]
    addLayerToggle('atz', 'ATZ'); // [MXAIR2026-ROLL]
    addLayerToggle('restricted', 'Áreas restringidas'); // [MXAIR2026-ROLL]
    addLayerToggle('navpoints', 'Fixes', settings.navpoints.enabled); // [MXAIR2026-ROLL]
    addLayerToggle('airwaysUpper', 'Aerovías superiores'); // [MXAIR2026-ROLL]
    addLayerToggle('airwaysLower', 'Aerovías inferiores'); // [MXAIR2026-ROLL]
    addLayerToggle('sid', 'SID'); // [MXAIR2026-ROLL]
    addLayerToggle('star', 'STAR'); // [MXAIR2026-ROLL]
    addLayerToggle('app', 'APP'); // [MXAIR2026-ROLL]
    addLayerToggle('asmgcs', 'ASMGCS'); // [MXAIR2026-ROLL]
    syncLayerToggleAvailability(); // [MXAIR2026-ROLL]
    refreshGeojsonManifest(); // [MXAIR2026-ROLL]

    function applyStationFilterInput() {
        const raw = (stationFilterInput && stationFilterInput.value ? stationFilterInput.value : '').toUpperCase();
        stationFilterList = raw.split(',').map(item => item.trim()).filter(Boolean);
        stationFilteredLayers.forEach(key => refreshLayerData(key));
        if (stationFilterList.length) {
            ['sid', 'star', 'ctr', 'atz', 'tma'].forEach(key => {
                if (layerToggles[key] && !layerToggles[key].checked) {
                    layerToggles[key].checked = true;
                    setLayerEnabled(key, true);
                }
            });
        }
    }

    if (stationFilterInput) {
        stationFilterInput.addEventListener('input', () => {
            applyStationFilterInput();
        });
    }

    const layersShowAll = document.getElementById('layersShowAll');
    const layersHideAll = document.getElementById('layersHideAll');
    if (layersShowAll) {
        layersShowAll.addEventListener('click', () => {
            Object.keys(layerDefinitions).forEach(key => {
                const toggle = layerToggles[key];
                if (toggle && !toggle.disabled) { // [MXAIR2026-ROLL]
                    toggle.checked = true;
                } else if (toggle) { // [MXAIR2026-ROLL]
                    toggle.checked = false; // [MXAIR2026-ROLL]
                }
                setLayerEnabled(key, toggle && !toggle.disabled); // [MXAIR2026-ROLL]
            });
            updateGroupStates(); // [MXAIR2026-ROLL]
        });
    }
    if (layersHideAll) {
        layersHideAll.addEventListener('click', () => {
            Object.keys(layerDefinitions).forEach(key => {
                const toggle = layerToggles[key];
                if (toggle) {
                    toggle.checked = false;
                }
                setLayerEnabled(key, false);
            });
            updateGroupStates(); // [MXAIR2026-ROLL]
        });
    }

    function navpointsZoneBounds() {
        const bounds = map.getBounds();
        let north = bounds.getNorth();
        let south = bounds.getSouth();
        let east = bounds.getEast();
        let west = bounds.getWest();
        const zones = {
            all: { north: 40.0, south: 10.0, east: -80.0, west: -120.0 },
            nw: { north: 33.5, south: 22.0, east: -100.0, west: -118.0 },
            ne: { north: 32.5, south: 19.0, east: -94.0, west: -106.0 },
            central: { north: 24.5, south: 17.0, east: -94.0, west: -104.5 },
            west: { north: 27.5, south: 16.0, east: -100.0, west: -110.0 },
            south: { north: 20.5, south: 14.0, east: -92.0, west: -103.0 },
            se: { north: 22.5, south: 15.0, east: -86.0, west: -95.0 },
        };
        if (settings.navpoints.zone === 'mmtj-120') {
            const deltaLat = 120 / 60;
            const deltaLon = deltaLat / Math.cos(settings.ui_center.lat * Math.PI / 180);
            north = Math.min(north, settings.ui_center.lat + deltaLat);
            south = Math.max(south, settings.ui_center.lat - deltaLat);
            east = Math.min(east, settings.ui_center.lon + deltaLon);
            west = Math.max(west, settings.ui_center.lon - deltaLon);
        } else {
            const zone = zones[settings.navpoints.zone] || zones.all;
            north = Math.min(north, zone.north);
            south = Math.max(south, zone.south);
            east = Math.min(east, zone.east);
            west = Math.max(west, zone.west);
        }
        return { north, south, east, west };
    }

    function updateNavpoints() {
        if (!settings.navpoints.enabled) {
            navpointsLayer.clearLayers();
            map.removeLayer(navpointsLayer);
            return;
        }
        if (map.getZoom() < settings.navpoints.min_zoom) {
            navpointsLayer.clearLayers();
            map.removeLayer(navpointsLayer);
            return;
        }
        if (!map.hasLayer(navpointsLayer)) {
            navpointsLayer.addTo(map);
        }
        const bbox = navpointsZoneBounds();
        const limit = settings.navpoints.max_points || 2000;
        if (navpointsGeojson) {
            navpointsLayer.clearLayers();
            const normalized = normalizeGeojson(navpointsGeojson);
            const icon = buildNavpointIcon();
            let total = 0;
            let added = 0;
            L.geoJSON(normalized, {
                pointToLayer: (feature, latlng) => {
                    return L.marker(latlng, {
                        icon,
                        pane: 'overlayPane',
                    });
                },
                onEachFeature: (feature, layerEl) => {
                    if (!layerEl) {
                        return;
                    }
                    const name = feature.properties && (feature.properties.id || feature.properties.name);
                    if (name && settings.labels.show_labels && map.getZoom() >= settings.labels.min_zoom) {
                        layerEl.bindTooltip(String(name), {
                            permanent: false,
                            direction: 'top',
                            offset: [0, -6],
                        });
                    }
                },
                filter: (feature) => {
                    const coords = feature.geometry && feature.geometry.coordinates;
                    if (!Array.isArray(coords) || coords.length < 2) {
                        return false;
                    }
                    const normalizedCoord = normalizeLonLat(coords);
                    const lon = normalizedCoord[0];
                    const lat = normalizedCoord[1];
                    if (lat < bbox.south || lat > bbox.north || lon < bbox.west || lon > bbox.east) {
                        return false;
                    }
                    total++;
                    if (added >= limit) {
                        return false;
                    }
                    added++;
                    return true;
                },
            }).addTo(navpointsLayer);
            if (total > limit && Date.now() - navpointsLastWarning > 5000) {
                navpointsLastWarning = Date.now();
                showNotification('Límite de fixes alcanzado; acércate para más detalle.'); // [MXAIR2026-ROLL]
            }
            return;
        }

        loadNavpointsGeojson().then(data => { // [MXAIR2026]
            if (data) {
                updateNavpoints();
            }
        });
    }

    let navpointsTimer = null;
    function scheduleNavpointsUpdate() {
        if (navpointsTimer) {
            clearTimeout(navpointsTimer);
        }
        navpointsTimer = setTimeout(() => {
            updateNavpoints();
        }, 250);
    }

    // Flight data and interactions
    const flights = {}; // keyed by flight id
    const flightMarkers = {}; // marker/vector/track/history/lastUpdate
    const flightStates = {};
    let selectedFlight = null;
    const noteStore = loadNoteStore();
    const stripTray = document.getElementById('stripTray');
    const stripDetails = null; // [MXAIR2026]
    const flightInfoDiv = document.getElementById('flightInfo');
    const flightPlanSummary = document.getElementById('flightPlanSummary');
    const routeToggleBtn = document.getElementById('routeToggleBtn');
    const notif = document.getElementById('notif');
    const feedError = document.getElementById('feedError');
    const feedErrorText = document.getElementById('feedErrorText');
    const feedErrorDetails = document.getElementById('feedErrorDetails');
    const feedStatusEl = document.getElementById('feedStatus');

    let stripOrder = [];
    let stripNotes = {};
    let stripStatuses = {};
    let stripDataCache = {};
    let selectedStrip = null;
    let routeLayer = null;
    let routeActive = false;
    let routePlan = null;
    let lastStateSync = 0;

    function updateDebugInfo() {
        if (!debugInfo) {
            return;
        }
        const feedCenter = settings.feed_center || defaultSettings.feed_center;
        const uiCenter = settings.ui_center || defaultSettings.ui_center;
        const feedFixedOk = Number(feedCenter.lat.toFixed(7)) === Number(expectedFeedCenter.lat.toFixed(7))
            && Number(feedCenter.lon.toFixed(7)) === Number(expectedFeedCenter.lon.toFixed(7))
            && Number(feedCenter.radius_nm) === expectedFeedCenter.radius_nm;
        const markerCount = Object.keys(flightMarkers).length;
        const trackCount = Object.values(flightMarkers).filter(entry => entry.track).length;
        const labelCount = Object.values(flightMarkers).filter(entry => entry.marker && entry.marker.getTooltip()).length;
        const polling = pollTimer ? 1 : 0;
        debugInfo.textContent = [
            `markers: ${markerCount} · tracks: ${trackCount} · labels: ${labelCount} · polling: ${polling}`,
            `FEED_CENTER: ${feedCenter.lat.toFixed(7)}, ${feedCenter.lon.toFixed(7)} · ${feedCenter.radius_nm} NM · ${feedFixedOk ? 'FIXED OK' : 'WARNING'}`,
            `UI_CENTER: ${uiCenter.lat.toFixed(3)}, ${uiCenter.lon.toFixed(3)}`,
        ].join('\n');
    }

    let airacUpdateEnabled = false;
    let vatmexDirConfigured = false;
    let vatmexRepoConfigured = false;
    let vatmexAiracConfigured = false;
    let airacCycle = null;

    // Range ring overlay container
    let rangeRings = [];

    function syncLeaderControls(mode) {
        const normalizedMode = mode === 'distance' ? 'distance' : 'time';
        const timeLabel = document.getElementById('leaderTimeLabel');
        const distanceLabel = document.getElementById('leaderDistanceLabel');
        const timeSelect = document.getElementById('leaderTime');
        const distanceSelect = document.getElementById('leaderDistance');
        if (!timeLabel || !distanceLabel || !timeSelect || !distanceSelect) {
            return;
        }
        const timeActive = normalizedMode === 'time';
        timeLabel.style.opacity = timeActive ? '1' : '0.5';
        distanceLabel.style.opacity = timeActive ? '0.5' : '1';
        timeSelect.disabled = !timeActive;
        distanceSelect.disabled = timeActive;
    }

    // Apply settings to map and UI
    function applySettings() {
        ensureCenters();
        document.documentElement.style.setProperty('--label-size', settings.labels.font_size);
        document.documentElement.style.setProperty('--label-color', settings.labels.color);
        document.documentElement.style.setProperty('--ui-font-size', settings.display.ui_font_size || 12); // [MXAIR2026]
        map.setView([settings.ui_center.lat, settings.ui_center.lon], map.getZoom());
        updateRangeRings();
        switchBasemap(settings.display && settings.display.basemap ? settings.display.basemap : 'dark');
        updateLabelVisibility();
        updateNavpoints();
        if (layerToggles.navpoints) { // [MXAIR2026]
            layerToggles.navpoints.checked = settings.navpoints.enabled; // [MXAIR2026]
        }
        if (!settings.tracks.show_trail) {
            Object.values(flightMarkers).forEach((markerData) => {
                if (markerData.track) {
                    map.removeLayer(markerData.track);
                    markerData.track = null;
                }
                markerData.history = [];
            });
        }
        syncLeaderControls(settings.leader && settings.leader.mode);
    }

    // Create or refresh range rings around the primary airport
    function updateRangeRings() {
        // Remove existing rings
        rangeRings.forEach(r => map.removeLayer(r));
        rangeRings = [];
        const dashArray = settings.rings.style.dash || '';
        settings.rings.distances.forEach(dist => {
            const circle = L.circle([settings.ui_center.lat, settings.ui_center.lon], {
                color: settings.rings.style.color,
                weight: settings.rings.style.weight,
                fill: false,
                radius: dist * 1852, // convert NM to metres
                dashArray: dashArray,
            });
            circle.addTo(map);
            rangeRings.push(circle);
        });
    }

    map.on('zoomend', () => {
        updateLabelVisibility();
        scheduleNavpointsUpdate();
    });
    map.on('moveend', () => { // [MXAIR2026]
        scheduleNavpointsUpdate(); // [MXAIR2026]
    });

    // Utility: compute destination point given distance (NM) and bearing from start
    function destinationPoint(lat, lon, bearing, distanceNm) {
        const R = 6371e3; // metres
        const d = distanceNm * 1852; // nautical miles to metres
        const φ1 = lat * Math.PI / 180;
        const λ1 = lon * Math.PI / 180;
        const θ = (bearing || 0) * Math.PI / 180;
        const φ2 = Math.asin(Math.sin(φ1) * Math.cos(d / R) + Math.cos(φ1) * Math.sin(d / R) * Math.cos(θ));
        const λ2 = λ1 + Math.atan2(Math.sin(θ) * Math.sin(d / R) * Math.cos(φ1), Math.cos(d / R) - Math.sin(φ1) * Math.sin(φ2));
        return [φ2 * 180 / Math.PI, ((λ2 * 180 / Math.PI + 540) % 360) - 180];
    }

    function distanceNm(lat1, lon1, lat2, lon2) {
        const R = 6371e3;
        const φ1 = lat1 * Math.PI / 180;
        const φ2 = lat2 * Math.PI / 180;
        const Δφ = (lat2 - lat1) * Math.PI / 180;
        const Δλ = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(Δφ / 2) * Math.sin(Δφ / 2)
            + Math.cos(φ1) * Math.cos(φ2)
            * Math.sin(Δλ / 2) * Math.sin(Δλ / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        return (R * c) / 1852;
    }

    const mexicoBounds = {
        latMin: 10,
        latMax: 40,
        lonMin: -120,
        lonMax: -80,
    };

    function isMexicoCoord(lat, lon) {
        return lat >= mexicoBounds.latMin && lat <= mexicoBounds.latMax && lon >= mexicoBounds.lonMin && lon <= mexicoBounds.lonMax;
    }

    function isValidLat(lat) {
        const value = coerceNumber(lat);
        return value !== null && value >= -90 && value <= 90;
    }

    function isValidLon(lon) {
        const value = coerceNumber(lon);
        return value !== null && value >= -180 && value <= 180;
    }

    function coerceNumber(value) {
        if (value === null || value === undefined || value === '') {
            return null;
        }
        const num = Number(value);
        if (!Number.isFinite(num)) {
            return null;
        }
        return num;
    }

    function normalizeIdPart(value) {
        if (value === null || value === undefined) {
            return '';
        }
        const text = String(value).trim().toUpperCase();
        return text;
    }

    function buildAircraftId(ac) {
        const explicitId = normalizeIdPart(ac.id || ac._id);
        if (explicitId) {
            return explicitId;
        }
        const hex = normalizeIdPart(ac.hex || ac.icao24 || ac.addr || ac.hexid);
        if (hex) {
            return hex;
        }
        const icao = normalizeIdPart(ac.icao24 || ac.addr || ac.hexid);
        const flight = normalizeIdPart(ac.flight || ac.callsign);
        if (icao && flight) {
            return `${icao}-${flight}`;
        }
        if (icao) {
            return icao;
        }
        if (flight) {
            return flight;
        }
        if (isValidLat(ac.lat) && isValidLon(ac.lon)) {
            return `POS-${Number(ac.lat).toFixed(4)}-${Number(ac.lon).toFixed(4)}`;
        }
        return null;
    }

    function normalizeCenter(center, fallback) {
        if (!center || !isValidLat(center.lat) || !isValidLon(center.lon)) {
            return { ...fallback };
        }
        return { lat: Number(center.lat), lon: Number(center.lon) };
    }

    function ensureCenters() {
        settings.feed_center = normalizeCenter(
            settings.feed_center,
            defaultSettings.feed_center
        );
        if (!settings.feed_center.radius_nm || !Number.isFinite(settings.feed_center.radius_nm)) {
            settings.feed_center.radius_nm = defaultSettings.feed_center.radius_nm;
        }
        settings.ui_center = normalizeCenter(
            settings.ui_center || settings.display_center,
            defaultSettings.ui_center
        );
    }

    function normalizeLonLat(coord) {
        if (!Array.isArray(coord) || coord.length < 2) {
            return coord;
        }
        const lon = parseFloat(coord[0]);
        const lat = parseFloat(coord[1]);
        if (Number.isNaN(lon) || Number.isNaN(lat)) {
            return coord;
        }
        if (!isValidLon(lon) && isValidLon(lat) && isValidLat(lon)) {
            return [lat, lon];
        }
        if (!isValidLat(lat) && isValidLat(lon) && isValidLon(lat)) {
            return [lat, lon];
        }
        if (isMexicoCoord(lat, lon)) {
            return [lon, lat];
        }
        if (isMexicoCoord(lon, lat)) {
            return [lat, lon];
        }
        if (isValidLat(lon) && isValidLon(lat) && !isValidLon(lon)) {
            return [lat, lon];
        }
        return [lon, lat];
    }

    function normalizeCoordsDeep(coords) {
        if (!Array.isArray(coords)) {
            return coords;
        }
        if (coords.length && typeof coords[0] === 'number') {
            return normalizeLonLat(coords);
        }
        return coords.map(item => normalizeCoordsDeep(item));
    }

    function normalizeGeojson(data, options = {}) {
        if (!data || !data.type) {
            return data;
        }
        const forcePolygon = !!options.forcePolygon;
        function closeRing(ring) {
            if (!Array.isArray(ring) || ring.length < 3) {
                return ring;
            }
            const first = ring[0];
            const last = ring[ring.length - 1];
            if (!Array.isArray(first) || !Array.isArray(last)) {
                return ring;
            }
            if (first[0] !== last[0] || first[1] !== last[1]) {
                return [...ring, [...first]];
            }
            return ring;
        }

        function normalizeGeometry(geometry) {
            if (!geometry || !geometry.type || !geometry.coordinates) {
                return geometry;
            }
            const coords = normalizeCoordsDeep(geometry.coordinates);
            if (geometry.type === 'LineString' && forcePolygon) {
                if (coords.length >= 3) {
                    return { type: 'Polygon', coordinates: [closeRing(coords)] };
                }
                return { ...geometry, coordinates: coords };
            }
            if (geometry.type === 'Polygon') {
                return {
                    type: 'Polygon',
                    coordinates: coords.map(ring => closeRing(ring)),
                };
            }
            if (geometry.type === 'MultiPolygon') {
                return {
                    type: 'MultiPolygon',
                    coordinates: coords.map(poly => poly.map(ring => closeRing(ring))),
                };
            }
            return { ...geometry, coordinates: coords };
        }

        if (data.type === 'FeatureCollection') {
            data.features = (data.features || []).map(feature => {
                if (feature.geometry) {
                    feature.geometry = normalizeGeometry(feature.geometry);
                }
                return feature;
            });
            return data;
        }
        if (data.type === 'Feature') {
            if (data.geometry) {
                data.geometry = normalizeGeometry(data.geometry);
            }
            return data;
        }
        if (data.coordinates) {
            data.coordinates = normalizeCoordsDeep(data.coordinates);
        }
        return data;
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function loadNoteStore() {
        try {
            const raw = localStorage.getItem('adsb_notes');
            const parsed = raw ? JSON.parse(raw) : {};
            return parsed && typeof parsed === 'object' ? parsed : {};
        } catch (err) {
            return {};
        }
    }

    function persistNoteStore(store) {
        try {
            localStorage.setItem('adsb_notes', JSON.stringify(store));
        } catch (err) {
            // ignore storage errors
        }
    }

    function saveNote(flightId, note) {
        if (!flightId) {
            return;
        }
        if (note) {
            noteStore[flightId] = note;
            stripNotes[flightId] = note;
        } else {
            delete noteStore[flightId];
            delete stripNotes[flightId];
        }
        persistNoteStore(noteStore);
        const hex = flights[flightId]?.hex;
        if (hex) {
            persistStrip({ hex, note });
        }
    }

    function loadStrips() {
        fetchJson(apiUrl('strips.php'), {}, 'Load strips')
            .then(data => {
                const strips = Array.isArray(data.strips) ? data.strips : [];
                stripOrder = strips
                    .filter(strip => strip && strip.hex)
                    .sort((a, b) => (a.position ?? 0) - (b.position ?? 0))
                    .map(strip => strip.hex);
                stripNotes = {};
                stripStatuses = {};
                stripDataCache = {};
                strips.forEach(strip => {
                    stripNotes[strip.hex] = strip.note || '';
                    stripStatuses[strip.hex] = normalizeStatus(strip.status || 'announced'); // [MXAIR2026-ROLL]
                    flightStates[strip.hex] = normalizeStatus(strip.status || flightStates[strip.hex] || 'announced'); // [MXAIR2026-ROLL]
                    stripDataCache[strip.hex] = { ...strip, _id: strip.hex };
                });
                updateStrips();
            })
            .catch(() => {});
    }

    function persistStrip(strip) {
        if (!strip || !strip.hex) {
            return;
        }
        return fetchJson(apiUrl('strips.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ strip }),
        }, 'Save strip')
            .then(data => {
                if (data && Array.isArray(data.strips)) {
                    stripOrder = data.strips
                        .sort((a, b) => (a.position ?? 0) - (b.position ?? 0))
                        .map(entry => entry.hex);
                    data.strips.forEach(entry => {
                        stripNotes[entry.hex] = entry.note || '';
                        stripStatuses[entry.hex] = normalizeStatus(entry.status || 'announced'); // [MXAIR2026-ROLL]
                        flightStates[entry.hex] = normalizeStatus(entry.status || flightStates[entry.hex] || 'announced'); // [MXAIR2026-ROLL]
                        stripDataCache[entry.hex] = { ...entry, _id: entry.hex };
                    });
                }
            })
            .catch(() => {});
    }

    function persistStripOrder(order) {
        return fetchJson(apiUrl('strips.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ order }),
        }, 'Save strip order')
            .then(data => {
                if (data && Array.isArray(data.strips)) {
                    stripOrder = data.strips
                        .sort((a, b) => (a.position ?? 0) - (b.position ?? 0))
                        .map(entry => entry.hex);
                    updateStrips();
                }
            })
            .catch(() => {});
    }

    function ensureStripForFlight(flightId) {
        if (!flightId) {
            return;
        }
        if (!stripOrder.includes(flightId)) {
            stripOrder.push(flightId);
        }
        const status = normalizeStatus(flightStates[flightId] || stripStatuses[flightId] || 'announced'); // [MXAIR2026-ROLL]
        const note = flights[flightId]?.note || stripNotes[flightId] || '';
        const hex = flights[flightId]?.hex;
        if (hex) {
            persistStrip({
                hex,
                status,
                note,
            });
        }
    }

    function scrollStripIntoView(flightId) { // [MXAIR2026]
        const stripEl = document.querySelector('.strip[data-flight-id=\"' + flightId + '\"]'); // [MXAIR2026]
        if (stripEl) { // [MXAIR2026]
            stripEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); // [MXAIR2026]
        }
    }

    function updateStripDetails(flightId) {
        const stripEl = document.querySelector('.strip[data-flight-id=\"' + flightId + '\"]'); // [MXAIR2026]
        if (!flightId || !stripEl) { // [MXAIR2026]
            return; // [MXAIR2026]
        }
        const ac = flights[flightId] || {};
        const status = getFlightStatus(flightId);
        const note = ac.note || stripNotes[flightId] || '';
        const callsign = ac.flight ? ac.flight.trim().toUpperCase() : (ac.hex || flightId);
        const registration = (ac.reg || ac.registration || '').toString().trim().toUpperCase(); // [MXAIR2026-ROLL]
        const registrationLabel = registration || ac.hex || (ac.flight ? ac.flight.trim().toUpperCase() : ''); // [MXAIR2026-ROLL]
        const alt = ac.alt ? `${ac.alt}FT` : '---';
        const gs = ac.gs ? `${ac.gs}KT` : '---';
        const trk = ac.track ? `${ac.track}°` : '---';
        const type = ac.type || ac.aircraft_type || '---';
        const route = ac.routeSummary || (ac.origin || ac.destination ? `${ac.origin || '---'} → ${ac.destination || '---'}` : 'Sin ruta disponible');
        const popup = document.createElement('div'); // [MXAIR2026]
        popup.className = 'strip-detail-popup'; // [MXAIR2026]
        popup.dataset.flightId = flightId; // [MXAIR2026]
        popup.innerHTML = `
            <strong>${escapeHtml(callsign)}</strong><br>
            Matrícula: ${escapeHtml(registrationLabel || '---')}<br> <!-- // [MXAIR2026-ROLL] -->
            Estado: ${escapeHtml(statusLabels[status] || status.toUpperCase())}<br> <!-- // [MXAIR2026-ROLL] -->
            ALT ${escapeHtml(alt)} · GS ${escapeHtml(gs)} · TRK ${escapeHtml(trk)}<br>
            TYPE ${escapeHtml(type)} · RUTA ${escapeHtml(route)}<br>
            Plan: <span class=\"strip-plan\">${escapeHtml(ac.routeSummary || 'Cargando...')}</span><br>
            Nota: ${escapeHtml(note || '---')}
        `;
        const existing = stripEl.parentElement.querySelector('.strip-detail-popup'); // [MXAIR2026]
        if (existing) {
            existing.remove();
        }
        stripEl.after(popup);
        loadFlightPlan(flightId).then(() => { // [MXAIR2026]
            const summary = flights[flightId]?.routeSummary || 'Sin ruta disponible';
            const target = popup.querySelector('.strip-plan');
            if (target) {
                target.textContent = summary;
            }
        });
    }

    function loadStates() {
        fetchJson(apiUrl('state.php'), {}, 'Load states')
            .then(data => {
                const states = Array.isArray(data.states) ? data.states : [];
                states.forEach(state => {
                    if (!state || !state.hex) {
                        return;
                    }
                    flightStates[state.hex] = normalizeStatus(state.status || flightStates[state.hex] || 'announced'); // [MXAIR2026-ROLL]
                });
                updateStrips();
            })
            .catch(() => {});
    }

    function syncFlightStates(force = false) {
        const now = Date.now();
        if (!force && now - lastStateSync < 5000) {
            return;
        }
        lastStateSync = now;
        const states = Object.values(flights)
            .filter(ac => ac && ac.hex)
            .map(ac => ({
                hex: ac.hex,
                lat: ac.lat,
                lon: ac.lon,
                alt: ac.alt,
                track: ac.track,
                gs: ac.gs,
                status: getFlightStatus(ac._id),
            }));
        if (!states.length) {
            return;
        }
        fetchJson(apiUrl('state.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ states }),
        }, 'Save states')
            .catch(() => {});
    }

    function formatUpdateTime(ts) {
        if (!ts) {
            return '--';
        }
        const iso = new Date(ts).toISOString();
        return `${iso.slice(11, 19)}Z`;
    }

    // Add or update a flight marker on the map
    const statusLabels = { // [MXAIR2026-ROLL]
        announced: 'ANNOUNCED', // [MXAIR2026-ROLL]
        assumed: 'ASSUMED', // [MXAIR2026-ROLL]
        handoff: 'HAND-OFF', // [MXAIR2026-ROLL]
    }; // [MXAIR2026-ROLL]

    function normalizeStatus(status) { // [MXAIR2026-ROLL]
        const value = String(status || '').toLowerCase(); // [MXAIR2026-ROLL]
        if (value === 'assumed') { // [MXAIR2026-ROLL]
            return 'assumed'; // [MXAIR2026-ROLL]
        }
        if (value === 'released' || value === 'handoff' || value === 'hand-off' || value === 'hand_off') { // [MXAIR2026-ROLL]
            return 'handoff'; // [MXAIR2026-ROLL]
        }
        return 'announced'; // [MXAIR2026-ROLL]
    }

    function getFlightStatus(hex) { // [MXAIR2026-ROLL]
        return normalizeStatus(flightStates[hex] || stripStatuses[hex] || 'announced'); // [MXAIR2026-ROLL]
    }

    function emergencyCode(ac) { // [MXAIR2026-ROLL]
        const squawk = (ac && ac.squawk ? String(ac.squawk) : '').trim(); // [MXAIR2026-ROLL]
        if (squawk === '7700') return '7700'; // [MXAIR2026-ROLL]
        if (squawk === '7600') return '7600'; // [MXAIR2026-ROLL]
        if (squawk === '7500') return '7500'; // [MXAIR2026-ROLL]
        return null; // [MXAIR2026-ROLL]
    }

    function statusColor(status) { // [MXAIR2026-ROLL]
        if (status === 'assumed') { // [MXAIR2026-ROLL]
            return '#22c55e'; // [MXAIR2026-ROLL]
        }
        if (status === 'handoff') { // [MXAIR2026-ROLL]
            return '#00c1ff'; // [MXAIR2026-ROLL]
        }
        return '#e5e7eb'; // [MXAIR2026-ROLL]
    }

    function emergencyColor(ac) { // [MXAIR2026-ROLL]
        const code = emergencyCode(ac); // [MXAIR2026-ROLL]
        if (code === '7700') { // [MXAIR2026-ROLL]
            return '#dc2626'; // [MXAIR2026-ROLL]
        }
        if (code === '7600') { // [MXAIR2026-ROLL]
            return '#facc15'; // [MXAIR2026-ROLL]
        }
        if (code === '7500') { // [MXAIR2026-ROLL]
            return '#fb923c'; // [MXAIR2026-ROLL]
        }
        return null; // [MXAIR2026-ROLL]
    }

    function flightColor(status, ac) { // [MXAIR2026-ROLL]
        return emergencyColor(ac) || statusColor(status); // [MXAIR2026-ROLL]
    }

    function hexToRgba(hex, alpha) { // [MXAIR2026-ROLL]
        const clean = (hex || '').replace('#', ''); // [MXAIR2026-ROLL]
        if (clean.length !== 6) { // [MXAIR2026-ROLL]
            return `rgba(0,0,0,${alpha})`; // [MXAIR2026-ROLL]
        }
        const r = parseInt(clean.slice(0, 2), 16); // [MXAIR2026-ROLL]
        const g = parseInt(clean.slice(2, 4), 16); // [MXAIR2026-ROLL]
        const b = parseInt(clean.slice(4, 6), 16); // [MXAIR2026-ROLL]
        return `rgba(${r},${g},${b},${alpha})`; // [MXAIR2026-ROLL]
    }

    function buildFlightIcon(color, opacity = 1, isStale = false) { // [MXAIR2026-ROLL]
        const classes = isStale ? 'flight-icon stale' : 'flight-icon'; // [MXAIR2026-ROLL]
        return L.divIcon({ // [MXAIR2026-ROLL]
            className: 'flight-marker', // [MXAIR2026-ROLL]
            html: `<div class="${classes}" style="--flight-color:${color};--flight-opacity:${opacity};"></div>`, // [MXAIR2026-ROLL]
            iconSize: [12, 12], // [MXAIR2026-ROLL]
            iconAnchor: [6, 6], // [MXAIR2026-ROLL]
        }); // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]

    function updateMarkerIcon(marker, color, opacity = 1, isStale = false) { // [MXAIR2026-ROLL]
        marker.setIcon(buildFlightIcon(color, opacity, isStale)); // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]

    function setTooltipOpacity(marker, opacity) {
        if (!marker || typeof marker.getTooltip !== 'function') {
            return;
        }
        const tooltip = marker.getTooltip();
        if (!tooltip) {
            return;
        }
        if (typeof tooltip.setOpacity === 'function') {
            tooltip.setOpacity(opacity);
        }
        const el = tooltip.getElement ? tooltip.getElement() : null;
        if (el) {
            el.style.opacity = opacity;
            el.style.display = opacity === 0 ? 'none' : '';
        }
    }

    function shouldShowLabel(ac) {
        if (!settings.labels.show_labels) {
            return false;
        }
        if (selectedFlight && selectedFlight === ac._id) {
            return true;
        }
        return map.getZoom() >= (settings.labels.min_zoom || 7);
    }

    function labelPlacement(ac) {
        const track = ac.track || 0;
        if (track >= 45 && track < 135) {
            return { direction: 'right', offset: [12, 0] };
        }
        if (track >= 135 && track < 225) {
            return { direction: 'bottom', offset: [0, 12] };
        }
        if (track >= 225 && track < 315) {
            return { direction: 'left', offset: [-12, 0] };
        }
        return { direction: 'top', offset: [0, -12] };
    }

    function isEmergency(ac) { // [MXAIR2026-ROLL]
        return emergencyCode(ac) !== null || (ac.emergency && ac.emergency !== 'none'); // [MXAIR2026-ROLL]
    }

    function updateTooltipAppearance(marker, ac, status) { // [MXAIR2026-ROLL]
        const tooltip = marker.getTooltip(); // [MXAIR2026-ROLL]
        if (!tooltip) { // [MXAIR2026-ROLL]
            return; // [MXAIR2026-ROLL]
        }
        const el = tooltip.getElement(); // [MXAIR2026-ROLL]
        if (!el) { // [MXAIR2026-ROLL]
            return; // [MXAIR2026-ROLL]
        }
        const color = flightColor(status, ac); // [MXAIR2026-ROLL]
        el.style.setProperty('--track-color', color); // [MXAIR2026-ROLL]
        el.style.setProperty('--track-border-color', hexToRgba(color, 0.6)); // [MXAIR2026-ROLL]
        el.style.setProperty('--track-highlight-bg', hexToRgba('#0b1320', 0.75)); // [MXAIR2026-ROLL]
        el.style.setProperty('--track-muted-color', hexToRgba(color, 0.65)); // [MXAIR2026-ROLL]
    }

    function updateTooltipClass(marker, ac) { // [MXAIR2026-ROLL]
        const tooltip = marker.getTooltip(); // [MXAIR2026-ROLL]
        if (!tooltip) { // [MXAIR2026-ROLL]
            return; // [MXAIR2026-ROLL]
        } // [MXAIR2026-ROLL]
        const el = tooltip.getElement(); // [MXAIR2026-ROLL]
        if (!el) { // [MXAIR2026-ROLL]
            return; // [MXAIR2026-ROLL]
        } // [MXAIR2026-ROLL]
        const status = getFlightStatus(ac._id || ac.hex); // [MXAIR2026-ROLL]
        updateTooltipAppearance(marker, ac, status); // [MXAIR2026-ROLL]
        el.classList.toggle('highlight', selectedFlight === ac._id || isEmergency(ac)); // [MXAIR2026-ROLL]
        el.classList.toggle('stale', ac.is_stale === true); // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]

    function tooltipAnchorPoint(tooltipEl, direction) { // [MXAIR2026-ROLL]
        const containerRect = map.getContainer().getBoundingClientRect(); // [MXAIR2026-ROLL]
        const rect = tooltipEl.getBoundingClientRect(); // [MXAIR2026-ROLL]
        if (!rect.width || !rect.height) { // [MXAIR2026-ROLL]
            return null; // [MXAIR2026-ROLL]
        }
        const xLeft = rect.left - containerRect.left; // [MXAIR2026-ROLL]
        const xRight = rect.right - containerRect.left; // [MXAIR2026-ROLL]
        const yTop = rect.top - containerRect.top; // [MXAIR2026-ROLL]
        const yBottom = rect.bottom - containerRect.top; // [MXAIR2026-ROLL]
        if (direction === 'right') { // [MXAIR2026-ROLL]
            return L.point(xLeft, yTop + rect.height / 2); // [MXAIR2026-ROLL]
        }
        if (direction === 'left') { // [MXAIR2026-ROLL]
            return L.point(xRight, yTop + rect.height / 2); // [MXAIR2026-ROLL]
        }
        if (direction === 'bottom') { // [MXAIR2026-ROLL]
            return L.point(xLeft + rect.width / 2, yTop); // [MXAIR2026-ROLL]
        }
        return L.point(xLeft + rect.width / 2, yBottom); // [MXAIR2026-ROLL]
    }

    function updateCalloutLine(markerData, ac, status) { // [MXAIR2026-ROLL]
        if (!markerData || !markerData.marker) { // [MXAIR2026-ROLL]
            return; // [MXAIR2026-ROLL]
        }
        const tooltip = markerData.marker.getTooltip(); // [MXAIR2026-ROLL]
        const tooltipEl = tooltip ? tooltip.getElement() : null; // [MXAIR2026-ROLL]
        if (!tooltipEl || !shouldShowLabel(ac)) { // [MXAIR2026-ROLL]
            if (markerData.callout) { // [MXAIR2026-ROLL]
                map.removeLayer(markerData.callout); // [MXAIR2026-ROLL]
                markerData.callout = null; // [MXAIR2026-ROLL]
            }
            return; // [MXAIR2026-ROLL]
        }
        const anchorPoint = tooltipAnchorPoint(tooltipEl, tooltip.options.direction); // [MXAIR2026-ROLL]
        if (!anchorPoint) { // [MXAIR2026-ROLL]
            return; // [MXAIR2026-ROLL]
        }
        const labelLatLng = map.containerPointToLatLng(anchorPoint); // [MXAIR2026-ROLL]
        const markerLatLng = markerData.marker.getLatLng(); // [MXAIR2026-ROLL]
        const color = flightColor(status, ac); // [MXAIR2026-ROLL]
        const opacity = status === 'handoff' ? 0.4 : 0.7; // [MXAIR2026-ROLL]
        if (!markerData.callout) { // [MXAIR2026-ROLL]
            markerData.callout = L.polyline([markerLatLng, labelLatLng], { // [MXAIR2026-ROLL]
                color, // [MXAIR2026-ROLL]
                weight: 1, // [MXAIR2026-ROLL]
                opacity, // [MXAIR2026-ROLL]
                pane: 'callouts', // [MXAIR2026-ROLL]
                interactive: false, // [MXAIR2026-ROLL]
            }).addTo(map); // [MXAIR2026-ROLL]
        } else { // [MXAIR2026-ROLL]
            markerData.callout.setLatLngs([markerLatLng, labelLatLng]); // [MXAIR2026-ROLL]
            markerData.callout.setStyle({ color, opacity }); // [MXAIR2026-ROLL]
        }
    }

    function updateAllCallouts() { // [MXAIR2026-ROLL]
        Object.keys(flightMarkers).forEach(id => { // [MXAIR2026-ROLL]
            const ac = flights[id]; // [MXAIR2026-ROLL]
            if (!ac) { // [MXAIR2026-ROLL]
                return; // [MXAIR2026-ROLL]
            }
            updateCalloutLine(flightMarkers[id], ac, getFlightStatus(id)); // [MXAIR2026-ROLL]
        }); // [MXAIR2026-ROLL]
    }

    function getLastSeenMs(ac, markerData) { // [MXAIR2026-ROLL]
        if (!ac) { // [MXAIR2026-ROLL]
            return markerData && markerData.lastUpdate ? markerData.lastUpdate : null; // [MXAIR2026-ROLL]
        } // [MXAIR2026-ROLL]
        if (Number.isFinite(ac.last_seen_ms)) { // [MXAIR2026-ROLL]
            return ac.last_seen_ms; // [MXAIR2026-ROLL]
        } // [MXAIR2026-ROLL]
        if (Number.isFinite(ac.last_seen)) { // [MXAIR2026-ROLL]
            return ac.last_seen; // [MXAIR2026-ROLL]
        } // [MXAIR2026-ROLL]
        return markerData && markerData.lastUpdate ? markerData.lastUpdate : null; // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]

    function applyStaleState(flightId, now) { // [MXAIR2026-ROLL]
        const ac = flights[flightId]; // [MXAIR2026-ROLL]
        const markerData = flightMarkers[flightId]; // [MXAIR2026-ROLL]
        if (!markerData || !ac) { // [MXAIR2026-ROLL]
            return; // [MXAIR2026-ROLL]
        } // [MXAIR2026-ROLL]
        const lastSeen = getLastSeenMs(ac, markerData); // [MXAIR2026-ROLL]
        const stale = lastSeen !== null ? (now - lastSeen > staleThresholdMs) : false; // [MXAIR2026-ROLL]
        ac.is_stale = stale; // [MXAIR2026-ROLL]
        markerData.isStale = stale; // [MXAIR2026-ROLL]
        const status = getFlightStatus(flightId); // [MXAIR2026-ROLL]
        const color = flightColor(status, ac); // [MXAIR2026-ROLL]
        const baseOpacity = status === 'handoff' ? 0.6 : 1; // [MXAIR2026-ROLL]
        const markerOpacity = stale ? Math.min(0.35, baseOpacity) : baseOpacity; // [MXAIR2026-ROLL]
        updateMarkerIcon(markerData.marker, color, markerOpacity, stale); // [MXAIR2026-ROLL]
        markerData.vector.setStyle({ color, opacity: stale ? 0.25 : (status === 'handoff' ? 0.3 : 0.7) }); // [MXAIR2026-ROLL]
        if (markerData.track) { // [MXAIR2026-ROLL]
            markerData.track.setStyle({ color, opacity: stale ? 0.2 : (status === 'handoff' ? 0.3 : 0.7) }); // [MXAIR2026-ROLL]
        } // [MXAIR2026-ROLL]
        updateTooltipClass(markerData.marker, ac); // [MXAIR2026-ROLL]
        updateCalloutLine(markerData, ac, status); // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]

    function bindLabelNoteEditor(marker, flightId) {
        const tooltip = marker.getTooltip();
        if (!tooltip) {
            return;
        }
        const el = tooltip.getElement();
        if (!el) {
            return;
        }
        const noteEl = el.querySelector('.label-note');
        if (!noteEl || noteEl.dataset.noteBound) {
            return;
        }
        noteEl.dataset.noteBound = '1';
        noteEl.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const ac = flights[flightId];
            if (!ac) {
                return;
            }
            if (noteEl.dataset.editing === '1') {
                return;
            }
            noteEl.dataset.editing = '1';
            noteEl.contentEditable = 'true';
            noteEl.classList.add('note-editing');
            noteEl.focus();
            document.execCommand('selectAll', false, null);
            const finish = (commit) => {
                noteEl.dataset.editing = '0';
                noteEl.contentEditable = 'false';
                noteEl.classList.remove('note-editing');
                noteEl.removeEventListener('keydown', onKey);
                if (commit) {
                    const updated = noteEl.textContent.trim();
                    ac.note = updated;
                    saveNote(flightId, updated);
                } else {
                    noteEl.textContent = ac.note || '---';
                }
                const markerData = flightMarkers[flightId];
                if (markerData) {
                    markerData.marker.setTooltipContent(labelFromAc(ac));
                    updateTooltipClass(markerData.marker, ac);
                }
                if (selectedFlight === flightId) {
                    const noteField = document.getElementById('noteField');
                    if (noteField) {
                        noteField.value = ac.note || '';
                    }
                }
                updateStrips();
            };
            const onKey = (evt) => {
                if (evt.key === 'Enter') {
                    evt.preventDefault();
                    finish(true);
                } else if (evt.key === 'Escape') {
                    evt.preventDefault();
                    finish(false);
                }
            };
            noteEl.addEventListener('keydown', onKey);
            noteEl.addEventListener('blur', () => finish(true), { once: true });
        });
    }

    function updateTrackHistory(state, ac, enabled) {
        if (!enabled) {
            state.history = [];
            return state.history;
        }
        const now = Date.now();
        if (!state.history) {
            state.history = [];
        }
        if (!state.history.length) {
            state.history.push({ lat: ac.lat, lon: ac.lon, ts: now, track: ac.track || 0 });
        } else {
            const last = state.history[state.history.length - 1];
            const delta = distanceNm(last.lat, last.lon, ac.lat, ac.lon);
            const timeDelta = now - (last.ts || 0);
            const movedEnough = delta >= 0.03;
            const waitedLongEnough = timeDelta >= 10000;
            if (movedEnough || waitedLongEnough) {
                state.history.push({ lat: ac.lat, lon: ac.lon, ts: now, track: ac.track || 0 });
            }
        }
        const cutoff = now - trackHistoryMaxAgeMs;
        state.history = state.history.filter(pt => pt.ts >= cutoff);
        if (state.history.length > trackHistoryMaxPoints) {
            state.history = state.history.slice(state.history.length - trackHistoryMaxPoints);
        }
        return state.history;
    }

    function leaderDistanceNm(ac) {
        const leader = settings.leader || defaultSettings.leader;
        if (leader.mode === 'distance') {
            return leader.distance_nm || defaultSettings.leader.distance_nm;
        }
        const minutes = leader.time_minutes || defaultSettings.leader.time_minutes;
        return (ac.gs || 0) * (minutes / 60);
    }

    function renderFlight(ac) {
        const id = ac._id;
        if (!id) {
            return;
        }
        const existing = flightMarkers[id];
        const pos = [ac.lat, ac.lon];
        const predictedDistance = leaderDistanceNm(ac);
        const predicted = destinationPoint(ac.lat, ac.lon, ac.track || 0, predictedDistance);
        const status = getFlightStatus(id);
        const color = flightColor(status, ac); // [MXAIR2026-ROLL]
        const showTrail = settings.tracks && settings.tracks.show_trail;
        if (existing) {
            existing.lastUpdate = Date.now();
            existing.marker.setLatLng(pos);
            existing.vector.setLatLngs([pos, predicted]);
            existing.marker.setTooltipContent(labelFromAc(ac));
            const placement = labelPlacement(ac);
            const tooltip = existing.marker.getTooltip();
            if (tooltip) {
                tooltip.options.direction = placement.direction;
                tooltip.options.offset = placement.offset;
            }
            existing.vector.setStyle({ color, opacity: status === 'handoff' ? 0.3 : 0.7 }); // [MXAIR2026-ROLL]
            setTooltipOpacity(existing.marker, shouldShowLabel(ac) ? 1 : 0);
            const history = updateTrackHistory(existing, ac, showTrail);
            if (showTrail) {
                if (!existing.track) {
                    existing.track = L.polyline(history.map(pt => [pt.lat, pt.lon]), {
                        color,
                        weight: 1.0,
                    opacity: status === 'handoff' ? 0.3 : 0.7, // [MXAIR2026-ROLL]
                        renderer: trackRenderer,
                        pane: 'tracks',
                    }).addTo(map);
                } else {
                    existing.track.setLatLngs(history.map(pt => [pt.lat, pt.lon]));
                    existing.track.setStyle({ color, opacity: status === 'handoff' ? 0.3 : 0.7 }); // [MXAIR2026-ROLL]
                }
            } else if (existing.track) {
                map.removeLayer(existing.track);
                existing.track = null;
            }
            updateTooltipClass(existing.marker, ac); // [MXAIR2026-ROLL]
            updateCalloutLine(existing, ac, status); // [MXAIR2026-ROLL]
            bindLabelNoteEditor(existing.marker, id);
            applyStaleState(id, Date.now()); // [MXAIR2026-ROLL]
        } else {
            const marker = L.marker(pos, {
                icon: buildFlightIcon(color),
                pane: 'targets',
            }).addTo(map);
            const vector = L.polyline([pos, predicted], {
                color,
                weight: 1.0,
                opacity: status === 'handoff' ? 0.3 : 0.7, // [MXAIR2026-ROLL]
                dashArray: '4 4',
                renderer: targetRenderer,
                pane: 'targets',
            }).addTo(map);
            const history = updateTrackHistory({ history: [] }, ac, showTrail);
            const track = showTrail
                ? L.polyline(history.map(pt => [pt.lat, pt.lon]), {
                    color,
                    weight: 1.0,
                    opacity: status === 'handoff' ? 0.3 : 0.7, // [MXAIR2026-ROLL]
                    renderer: trackRenderer,
                    pane: 'tracks',
                }).addTo(map)
                : null;
            const placement = labelPlacement(ac);
            marker.bindTooltip(labelFromAc(ac), {
                offset: placement.offset,
                direction: placement.direction,
                permanent: true,
                interactive: true,
                className: 'track-label',
                pane: 'labels',
            });
            setTooltipOpacity(marker, shouldShowLabel(ac) ? 1 : 0);
            marker.on('click', () => {
                handleBrlSelection(flights[id]);
                selectFlight(id);
            });
            marker.on('tooltipopen', () => { // [MXAIR2026-ROLL]
                bindLabelNoteEditor(marker, id); // [MXAIR2026-ROLL]
                updateTooltipClass(marker, ac); // [MXAIR2026-ROLL]
                updateCalloutLine(flightMarkers[id], ac, status); // [MXAIR2026-ROLL]
            });
            flightMarkers[id] = { marker, vector, track, history, lastUpdate: Date.now(), callout: null }; // [MXAIR2026-ROLL]
            applyStaleState(id, Date.now()); // [MXAIR2026-ROLL]
        }
        updateDebugInfo();
    }

    // Generate a label string for a track
    function labelFromAc(ac) {
        const callsign = ac.flight ? ac.flight.trim().toUpperCase() : (ac.hex || ac._id);
        const type = ac.type || ac.aircraft_type || '';
        const line1 = `${escapeHtml(callsign)}${type ? ` ${escapeHtml(type)}` : ''}`;
        const alt = settings.labels.show_alt && ac.alt !== null && ac.alt !== undefined ? `${ac.alt}FT` : 'ALT ---';
        const gs = settings.labels.show_gs && ac.gs !== null && ac.gs !== undefined ? `${ac.gs}KT` : 'SPD ---';
        const line2 = `${alt}  ${gs}`;
        let vsText = 'VS ---';
        if (settings.labels.show_vs) {
            const vs = ac.baro_rate || ac.geom_rate || 0;
            if (vs) {
                const arrow = vs > 0 ? '↑' : '↓';
                vsText = `VS ${Math.abs(vs)}${arrow}`;
            }
        }
        const trkText = settings.labels.show_trk && ac.track !== null && ac.track !== undefined ? `TRK ${ac.track}°` : 'TRK ---';
        const line3 = `${vsText}  ${trkText}`;
        const sqk = settings.labels.show_sqk && ac.squawk ? `SQK ${ac.squawk}` : 'SQK ----';
        const note = ac.note ? escapeHtml(ac.note) : '---';
        const line4 = `${sqk}  NOTE: <span class="label-note" data-flight-id="${escapeHtml(ac._id || '')}">${note}</span>`;
        return [
            `<span class="label-line">${line1}</span>`,
            `<span class="label-line">${line2}</span>`,
            `<span class="label-line">${line3}</span>`,
            `<span class="label-line label-muted">${line4}</span>`,
        ].join('');
    }

    function updateLabelVisibility() {
        Object.keys(flightMarkers).forEach(id => {
            const ac = flights[id];
            if (!ac) return;
            const visible = shouldShowLabel(ac);
            setTooltipOpacity(flightMarkers[id].marker, visible ? 1 : 0);
            updateTooltipClass(flightMarkers[id].marker, ac);
            updateCalloutLine(flightMarkers[id], ac, getFlightStatus(id)); // [MXAIR2026-ROLL]
        });
    }

    function removeFlight(id) {
        const state = flightMarkers[id];
        if (state) {
            map.removeLayer(state.marker);
            map.removeLayer(state.vector);
            if (state.track) {
                map.removeLayer(state.track);
            }
            if (state.callout) { // [MXAIR2026-ROLL]
                map.removeLayer(state.callout); // [MXAIR2026-ROLL]
            }
            delete flightMarkers[id];
        }
        delete flights[id];
        if (selectedFlight === id) {
            selectedFlight = null;
            flightInfoDiv.textContent = 'Click a flight to see details.';
            updateFlightPlanPanel(null);
        }
        const stripEl = document.querySelector('.strip[data-flight-id="' + id + '"]');
        if (stripEl && !stripOrder.includes(id)) {
            stripEl.remove();
        }
        const popup = document.querySelector('.strip-detail-popup'); // [MXAIR2026]
        if (popup && popup.dataset.flightId === id) { // [MXAIR2026]
            popup.remove(); // [MXAIR2026]
        }
        updateDebugInfo();
    }

    // Remove stale flights (not updated recently)
    function pruneFlights() { // [MXAIR2026-ROLL]
        const now = Date.now(); // [MXAIR2026-ROLL]
        Object.keys(flightMarkers).forEach(id => { // [MXAIR2026-ROLL]
            const state = flightMarkers[id]; // [MXAIR2026-ROLL]
            const ac = flights[id]; // [MXAIR2026-ROLL]
            const lastSeen = getLastSeenMs(ac, state); // [MXAIR2026-ROLL]
            if (lastSeen === null) { // [MXAIR2026-ROLL]
                return; // [MXAIR2026-ROLL]
            } // [MXAIR2026-ROLL]
            if (now - lastSeen > targetTtlMs) { // [MXAIR2026-ROLL]
                removeFlight(id); // [MXAIR2026-ROLL]
                return; // [MXAIR2026-ROLL]
            } // [MXAIR2026-ROLL]
            applyStaleState(id, now); // [MXAIR2026-ROLL]
        }); // [MXAIR2026-ROLL]
    } // [MXAIR2026-ROLL]

    // Update flight strips in tray
    function buildStripHtml(ac, status) {
        const flightId = ac._id || ac.hex;
        const callsign = ac.flight ? ac.flight.trim().toUpperCase() : (ac.hex || flightId);
        const registration = (ac.reg || ac.registration || '').toString().trim().toUpperCase(); // [MXAIR2026-ROLL]
        const registrationLabel = registration || ac.hex || (ac.flight ? ac.flight.trim().toUpperCase() : ''); // [MXAIR2026-ROLL]
        const alt = ac.alt ? `${ac.alt}FT` : '---';
        const gs = ac.gs ? `${ac.gs}KT` : '---';
        const trk = ac.track ? `${ac.track}°` : '---';
        const vs = ac.baro_rate || ac.geom_rate;
        const vsText = vs ? `${Math.abs(vs)}${vs > 0 ? '↑' : '↓'}` : '---';
        const sq = ac.squawk || '----';
        const type = ac.type || ac.aircraft_type || '';
        const origin = ac.origin || ac.orig || '';
        const dest = ac.destination || ac.dest || '';
        const eta = ac.eta || '';
        const note = ac.note || stripNotes[flightId] || '';
        const statusLabel = statusLabels[status] || statusLabels.announced; // [MXAIR2026-ROLL]
        const routeSummary = ac.routeSummary || (origin || dest ? `${origin || '---'} → ${dest || '---'}` : 'Sin ruta disponible');
        const detailsPrimary = `
            <span>ALT ${alt}</span>
            <span>SPD ${gs}</span>
            <span>TRK ${trk}</span>
            <span>VS ${vsText}</span>
            <span>SQ ${sq}</span>
        `;
        const detailsSecondary = `
            <span>${type ? `TYPE ${type}` : 'TYPE ---'}</span>
            <span>RUTA ${routeSummary}</span>
            <span>${eta ? `ETA ${eta}` : 'ETA ---'}</span>
            <span>${note ? `NOTE ${note}` : 'NOTE ---'}</span>
        `;
        return `
            <div class="strip-header"> <!-- // [MXAIR2026] -->
                <span>
                    <span class="strip-call" data-flight-id="${flightId}">${callsign}</span>${registrationLabel ? ` <span class="strip-call" data-flight-id="${flightId}">(${registrationLabel})</span>` : ''} <!-- // [MXAIR2026-ROLL] -->
                </span>
                <span class="strip-status">${statusLabel}</span>
            </div>
            <div class="strip-meta">${detailsPrimary}</div>
            <div class="strip-meta">${detailsSecondary}</div>
        `;
    }

    function updateStrips() {
        const activeHexes = new Set(Object.keys(flights));
        const ordered = stripOrder.filter(hex => activeHexes.has(hex));
        Object.keys(flights).forEach(hex => {
            if (!ordered.includes(hex)) {
                ordered.push(hex);
            }
        });
        stripOrder = ordered;

        stripTray.querySelectorAll('.strip').forEach(strip => {
            if (!stripOrder.includes(strip.dataset.flightId)) {
                strip.remove();
            }
        });

        stripOrder.forEach((flightId, index) => {
            const ac = flights[flightId] || stripDataCache[flightId] || { _id: flightId, hex: flightId };
            let strip = document.querySelector('.strip[data-flight-id="' + flightId + '"]');
            if (!strip) {
                strip = document.createElement('div');
                strip.className = 'strip';
                strip.dataset.flightId = flightId;
                strip.draggable = true;
                strip.addEventListener('click', () => {
                    selectedStrip = flightId;
                    updateStripDetails(flightId);
                    selectFlight(flightId);
                    scrollStripIntoView(flightId); // [MXAIR2026]
                });
                strip.addEventListener('dragstart', event => {
                    event.dataTransfer.setData('text/plain', flightId);
                    event.dataTransfer.effectAllowed = 'move';
                });
                strip.addEventListener('dragover', event => {
                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';
                });
                strip.addEventListener('drop', event => {
                    event.preventDefault();
                    const draggedHex = event.dataTransfer.getData('text/plain');
                    if (!draggedHex || draggedHex === flightId) {
                        return;
                    }
                    const newOrder = stripOrder.filter(item => item !== draggedHex);
                    const insertIndex = newOrder.indexOf(flightId);
                    newOrder.splice(insertIndex, 0, draggedHex);
                    stripOrder = newOrder;
                    updateStrips();
                    persistStripOrder(stripOrder);
                });
                stripTray.appendChild(strip);
            }
            const status = getFlightStatus(flightId);
            strip.classList.toggle('assumed', status === 'assumed'); // [MXAIR2026-ROLL]
            strip.classList.toggle('handoff', status === 'handoff'); // [MXAIR2026-ROLL]
            strip.classList.toggle('announced', status === 'announced'); // [MXAIR2026-ROLL]
            strip.classList.toggle('selected', selectedFlight === flightId || selectedStrip === flightId);
            try { // [MXAIR2026-ROLL]
                strip.innerHTML = buildStripHtml(ac, status); // [MXAIR2026-ROLL]
            } catch (err) { // [MXAIR2026-ROLL]
                console.error('Strip render failed', err); // [MXAIR2026-ROLL]
                reportError('Strip render failed', err && err.message ? err.message : String(err || 'Unknown')); // [MXAIR2026-ROLL]
                strip.innerHTML = `<div class="strip-header"><span class="strip-call" data-flight-id="${flightId}">${flightId}</span><span class="strip-status">ERROR</span></div>`; // [MXAIR2026-ROLL]
            } // [MXAIR2026-ROLL]
            strip.querySelectorAll('.strip-call').forEach(el => { // [MXAIR2026]
                if (el.dataset.bound) { // [MXAIR2026]
                    return; // [MXAIR2026]
                }
                el.dataset.bound = '1'; // [MXAIR2026]
                el.addEventListener('click', (event) => { // [MXAIR2026]
                    event.stopPropagation(); // [MXAIR2026]
                    selectedStrip = flightId; // [MXAIR2026]
                    updateStripDetails(flightId); // [MXAIR2026]
                    scrollStripIntoView(flightId); // [MXAIR2026]
                }); // [MXAIR2026]
            });
            const emergency = emergencyCode(ac); // [MXAIR2026-ROLL]
            strip.classList.toggle('emergency-7700', emergency === '7700'); // [MXAIR2026-ROLL]
            strip.classList.toggle('emergency-7600', emergency === '7600'); // [MXAIR2026-ROLL]
            strip.classList.toggle('emergency-7500', emergency === '7500'); // [MXAIR2026-ROLL]
            stripTray.appendChild(strip);
        });
        if (selectedStrip) {
            updateStripDetails(selectedStrip);
        }
    }

    // Select a flight for detailed view and route display
    function selectFlight(flightId) {
        const ac = flights[flightId];
        if (!ac) return;
        selectedFlight = flightId;
        selectedStrip = flightId;
        ensureStripForFlight(flightId);
        updateStripDetails(flightId);
        scrollStripIntoView(flightId); // [MXAIR2026]
        routePlan = null;
        clearRoute();
        // Highlight marker
        Object.keys(flightMarkers).forEach(id => {
            const m = flightMarkers[id].marker; // [MXAIR2026-ROLL]
            const status = getFlightStatus(id); // [MXAIR2026-ROLL]
            const color = id === flightId ? '#22d3ee' : flightColor(status, flights[id]); // [MXAIR2026-ROLL]
            const stale = flights[id]?.is_stale === true; // [MXAIR2026-ROLL]
            updateMarkerIcon(m, color, status === 'handoff' ? 0.6 : 1, stale); // [MXAIR2026-ROLL]
            updateTooltipClass(m, flights[id] || {}); // [MXAIR2026-ROLL]
            updateCalloutLine(flightMarkers[id], flights[id] || {}, status); // [MXAIR2026-ROLL]
        });
        updateStrips();
        scrollStripIntoView(flightId); // [MXAIR2026]
        // Show details
        // Build details HTML
        const status = getFlightStatus(flightId);
        let html = '';
        html += '<strong>CALLSIGN:</strong> ' + (ac.flight ? ac.flight.trim().toUpperCase() : (ac.hex || flightId)) + '<br>';
        html += '<strong>STATUS:</strong> ' + (statusLabels[status] || status.toUpperCase()) + '<br>'; // [MXAIR2026-ROLL]
        html += '<strong>POS:</strong> ' + ac.lat.toFixed(4) + ', ' + ac.lon.toFixed(4) + '<br>';
        if (ac.alt) html += '<strong>ALT:</strong> ' + ac.alt + ' FT<br>';
        if (ac.gs) html += '<strong>GS:</strong> ' + ac.gs + ' KT<br>';
        if (ac.baro_rate || ac.geom_rate) {
            const vs = ac.baro_rate || ac.geom_rate;
            const arrow = vs > 0 ? '↑' : '↓';
            html += '<strong>VS:</strong> ' + Math.abs(vs) + ' ' + arrow + '<br>';
        }
        if (ac.track) html += '<strong>TRK:</strong> ' + ac.track + '°<br>';
        if (ac.squawk) html += '<strong>SQ:</strong> ' + ac.squawk + '<br>';
        if (ac.type) html += '<strong>TYPE:</strong> ' + ac.type + '<br>';
        if (ac.origin || ac.orig || ac.destination || ac.dest) {
            html += '<strong>ROUTE:</strong> ' + (ac.origin || ac.orig || '---') + ' → ' + (ac.destination || ac.dest || '---') + '<br>';
        }
        if (ac.eta) html += '<strong>ETA:</strong> ' + ac.eta + '<br>';
        if (ac.emergency && ac.emergency !== 'none') html += '<strong>EMERG:</strong> ' + ac.emergency + '<br>';
        if (ac.routeSummary) html += '<strong>ROUTE SUMMARY:</strong> ' + ac.routeSummary + '<br>';
        html += '<label>Note / OPMET:<br><textarea id="noteField" rows="2" style="width:95%;">' + (ac.note || '') + '</textarea></label><br>';
        html += '<button id="assumeBtn">Assume</button> ' + // [MXAIR2026-ROLL]
                '<button id="handoffBtn">Hand-Off</button> ' + // [MXAIR2026-ROLL]
                '<button id="saveNoteBtn">Save Note</button>';
        flightInfoDiv.innerHTML = html;
        // Bind buttons
        document.getElementById('assumeBtn').addEventListener('click', () => {
            assumeFlight(flightId);
        });
        document.getElementById('handoffBtn').addEventListener('click', () => { // [MXAIR2026-ROLL]
            releaseFlight(flightId); // [MXAIR2026-ROLL]
        });
        document.getElementById('saveNoteBtn').addEventListener('click', () => {
            const text = document.getElementById('noteField').value.trim();
            flights[flightId].note = text;
            saveNote(flightId, text);
            // Immediately update tooltip with note
            const existing = flightMarkers[flightId];
            if (existing) {
                existing.marker.setTooltipContent(labelFromAc(flights[flightId]));
                updateTooltipClass(existing.marker, flights[flightId]);
            }
            updateStrips();
        });
        updateLabelVisibility();
        updateFlightPlanPanel(flightId);
    }

    // Assume a flight (mark as assumed)
    function assumeFlight(flightId) {
        flightStates[flightId] = 'assumed';
        stripStatuses[flightId] = 'assumed';
        stripOrder = [flightId, ...stripOrder.filter(item => item !== flightId)]; // [MXAIR2026]
        const markerData = flightMarkers[flightId];
        if (markerData) { // [MXAIR2026-ROLL]
            const color = flightColor('assumed', flights[flightId]); // [MXAIR2026-ROLL]
            const stale = flights[flightId]?.is_stale === true; // [MXAIR2026-ROLL]
            updateMarkerIcon(markerData.marker, color, stale ? 0.35 : 1, stale); // [MXAIR2026-ROLL]
            markerData.vector.setStyle({ color }); // [MXAIR2026-ROLL]
            if (markerData.track) { // [MXAIR2026-ROLL]
                markerData.track.setStyle({ color }); // [MXAIR2026-ROLL]
            } // [MXAIR2026-ROLL]
            updateCalloutLine(markerData, flights[flightId], 'assumed'); // [MXAIR2026-ROLL]
        } // [MXAIR2026-ROLL]
        const strip = document.querySelector('.strip[data-flight-id="' + flightId + '"]');
        if (strip) {
            strip.classList.add('assumed');
            strip.classList.remove('handoff'); // [MXAIR2026-ROLL]
        }
        updateStrips();
        persistStripOrder(stripOrder); // [MXAIR2026]
        updateLabelVisibility();
        if (selectedFlight === flightId) {
            selectFlight(flightId);
        }
        const hex = flights[flightId]?.hex;
        if (hex) {
            persistStrip({ hex, status: 'assumed', note: flights[flightId]?.note || stripNotes[flightId] || '' });
        }
    }

    // Hand-off a flight (mark as handoff) // [MXAIR2026-ROLL]
    function releaseFlight(flightId) {
        flightStates[flightId] = 'handoff'; // [MXAIR2026-ROLL]
        stripStatuses[flightId] = 'handoff'; // [MXAIR2026-ROLL]
        const markerData = flightMarkers[flightId];
        if (markerData) { // [MXAIR2026-ROLL]
            const color = flightColor('handoff', flights[flightId]); // [MXAIR2026-ROLL]
            const stale = flights[flightId]?.is_stale === true; // [MXAIR2026-ROLL]
            updateMarkerIcon(markerData.marker, color, stale ? 0.35 : 0.6, stale); // [MXAIR2026-ROLL]
            markerData.vector.setStyle({ color, opacity: 0.3 }); // [MXAIR2026-ROLL]
            if (markerData.track) { // [MXAIR2026-ROLL]
                markerData.track.setStyle({ color, opacity: 0.3 }); // [MXAIR2026-ROLL]
            } // [MXAIR2026-ROLL]
            updateCalloutLine(markerData, flights[flightId], 'handoff'); // [MXAIR2026-ROLL]
        } // [MXAIR2026-ROLL]
        const strip = document.querySelector('.strip[data-flight-id="' + flightId + '"]');
        if (strip) {
            strip.classList.remove('assumed');
            strip.classList.add('handoff'); // [MXAIR2026-ROLL]
        }
        updateStrips();
        updateLabelVisibility();
        if (selectedFlight === flightId) {
            selectFlight(flightId);
        }
        const hex = flights[flightId]?.hex;
        if (hex) {
            persistStrip({ hex, status: 'handoff', note: flights[flightId]?.note || stripNotes[flightId] || '' }); // [MXAIR2026-ROLL]
        }
    }

    function clearRoute() {
        if (routeLayer) {
            map.removeLayer(routeLayer);
            routeLayer = null;
        }
        routeActive = false;
        routeToggleBtn.textContent = 'Route OFF';
    }

    function applyRouteToMap(route) {
        clearRoute();
        if (!route) {
            return;
        }
        const normalized = normalizeGeojson(route);
        routeLayer = L.geoJSON(normalized, {
            style: { color: '#d946ef', weight: 2.2 }
        }).addTo(map);
        if (routeLayer.getBounds().isValid()) {
            map.fitBounds(routeLayer.getBounds(), { maxZoom: 8 });
        }
        routeActive = true;
        routeToggleBtn.textContent = 'Route ON';
    }

    function loadFlightPlan(flightId) {
        const ac = flights[flightId];
        if (!ac) {
            return Promise.resolve(null);
        }
        const callsign = ac.flight ? ac.flight.trim().toUpperCase() : '';
        if (!callsign) {
            ac.routeSummary = 'Sin ruta disponible';
            showNotification('Sin ruta disponible: falta callsign.');
            updateStrips();
            return Promise.resolve(null);
        }
        const url = apiUrl('route.php') + `?callsign=${encodeURIComponent(callsign)}`;
        return fetchJson(url, {}, `Route (${callsign})`)
            .then(data => {
                if (!data.ok || !data.route) {
                    ac.routeSummary = 'Sin ruta disponible';
                    updateStrips();
                    showNotification(data.error || 'Sin ruta disponible para ' + callsign);
                    return null;
                }
                routePlan = { ...data, callsign };
                const summary = data.summary || {};
                const fixCount = summary.fix_count || 0;
                const origin = summary.origin || ac.origin || ac.orig || '';
                const dest = summary.destination || ac.destination || ac.dest || '';
                const fixInfo = fixCount ? `Fixes: ${fixCount}` : 'Fixes: 0';
                ac.routeSummary = origin || dest ? `${origin || '---'} → ${dest || '---'} (${fixInfo})` : fixInfo;
                updateStrips();
                updateFlightPlanPanel(flightId);
                return data;
            })
            .catch(() => {
                ac.routeSummary = 'Sin ruta disponible';
                updateStrips();
                showNotification('Sin ruta disponible para ' + callsign);
                return null;
            });
    }

    function updateFlightPlanPanel(flightId) {
        const ac = flights[flightId];
        if (!ac) {
            flightPlanSummary.textContent = 'Selecciona un vuelo para ver el plan.';
            routeToggleBtn.disabled = true;
            clearRoute();
            return;
        }
        const callsign = ac.flight ? ac.flight.trim().toUpperCase() : '';
        const summary = ac.routeSummary || 'Ruta no cargada.';
        flightPlanSummary.textContent = callsign ? summary : 'Ruta no disponible: falta callsign.';
        routeToggleBtn.disabled = !callsign;
        routeToggleBtn.textContent = routeActive ? 'Route ON' : 'Route OFF';
    }

    // Show a transient notification
    function showNotification(msg) {
        notif.textContent = msg;
        notif.style.display = 'block';
        setTimeout(() => {
            notif.style.display = 'none';
        }, 3000);
    }

    function showFeedError(message) {
        if (message) {
            feedErrorText.textContent = message;
            feedError.style.display = 'flex';
        } else {
            feedError.style.display = 'none';
        }
    }

    let brlMode = null;
    let brlOrigin = null;
    let brlOriginSpeed = null;
    let brlLine = null;
    let brlLabel = null;
    let brlTracking = false;
    const brlToggle = document.getElementById('brlToggle');
    const brlAirport = document.getElementById('brlAirport');
    const brlClear = document.getElementById('brlClear');

    function computeBearingRange(lat1, lon1, lat2, lon2) {
        const R = 6371e3;
        const φ1 = lat1 * Math.PI / 180;
        const φ2 = lat2 * Math.PI / 180;
        const Δφ = (lat2 - lat1) * Math.PI / 180;
        const Δλ = (lon2 - lon1) * Math.PI / 180;
        const a = Math.sin(Δφ / 2) * Math.sin(Δφ / 2)
            + Math.cos(φ1) * Math.cos(φ2)
            * Math.sin(Δλ / 2) * Math.sin(Δλ / 2);
        const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        const distanceNm = (R * c) / 1852;
        const y = Math.sin(Δλ) * Math.cos(φ2);
        const x = Math.cos(φ1) * Math.sin(φ2) - Math.sin(φ1) * Math.cos(φ2) * Math.cos(Δλ);
        const bearing = (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
        return { bearing, distanceNm };
    }

    function formatEta(distanceNm, speedKt) {
        if (!speedKt || speedKt <= 0) {
            return null;
        }
        const totalMinutes = Math.round((distanceNm / speedKt) * 60);
        if (!isFinite(totalMinutes)) {
            return null;
        }
        const hours = Math.floor(totalMinutes / 60);
        const minutes = totalMinutes % 60;
        if (hours > 0) {
            return `${hours}h ${minutes}m`;
        }
        return `${minutes}m`;
    }

    function updateBrlLine(endLatLng) {
        if (!brlOrigin || !endLatLng) {
            return;
        }
        const originLatLng = L.latLng(brlOrigin);
        const lineLatLngs = [originLatLng, endLatLng];
        if (!brlLine) {
            brlLine = L.polyline(lineLatLngs, {
                color: '#facc15',
                weight: 2,
            }).addTo(map);
        } else {
            brlLine.setLatLngs(lineLatLngs);
        }
        const mid = L.latLng(
            (originLatLng.lat + endLatLng.lat) / 2,
            (originLatLng.lng + endLatLng.lng) / 2
        );
        const { bearing, distanceNm } = computeBearingRange(originLatLng.lat, originLatLng.lng, endLatLng.lat, endLatLng.lng);
        const etaText = formatEta(distanceNm, brlOriginSpeed);
        const labelText = etaText
            ? `BRG ${bearing.toFixed(0)}°  RNG ${distanceNm.toFixed(1)} NM  ETA ${etaText}`
            : `BRG ${bearing.toFixed(0)}°  RNG ${distanceNm.toFixed(1)} NM`;
        if (!brlLabel) {
            brlLabel = L.marker(mid, {
                icon: L.divIcon({
                    className: 'brl-label',
                    html: `<div style="background:#0e1520;color:#facc15;padding:2px 6px;border:1px solid #facc15;border-radius:4px;font-size:11px;white-space:nowrap;display:inline-block;">${labelText}</div>` // [MXAIR2026]
                }),
            }).addTo(map);
        } else {
            brlLabel.setLatLng(mid);
            brlLabel.setIcon(L.divIcon({
                className: 'brl-label',
                html: `<div style="background:#0e1520;color:#facc15;padding:2px 6px;border:1px solid #facc15;border-radius:4px;font-size:11px;white-space:nowrap;display:inline-block;">${labelText}</div>` // [MXAIR2026]
            }));
        }
    }

    function clearBrl() {
        if (brlLine) {
            map.removeLayer(brlLine);
            brlLine = null;
        }
        if (brlLabel) {
            map.removeLayer(brlLabel);
            brlLabel = null;
        }
        brlOrigin = null;
        brlOriginSpeed = null;
    }

    function setBrlMode(mode) {
        brlMode = mode;
        brlOrigin = null;
        brlTracking = false;
        brlOriginSpeed = null;
        clearBrl();
        brlToggle.style.background = brlMode === 'manual' ? '#facc15' : '';
        brlToggle.style.color = brlMode === 'manual' ? '#0b0f18' : '';
        brlAirport.style.background = brlMode === 'airport' ? '#facc15' : '';
        brlAirport.style.color = brlMode === 'airport' ? '#0b0f18' : '';
        if (brlMode === 'manual') {
            showNotification('BRL activo: primer clic define origen, segundo clic destino.');
            if (selectedFlight) {
                ensureStripForFlight(selectedFlight);
            }
        } else if (brlMode === 'airport') {
            brlOrigin = [settings.ui_center.lat, settings.ui_center.lon];
            brlTracking = true;
            showNotification('AP BRL activo: clic para destino (origen aeropuerto).');
            if (selectedFlight) {
                ensureStripForFlight(selectedFlight);
            }
        } else {
            showNotification('BRL desactivado');
        }
    }

    function handleBrlSelection(ac) {
        if (!brlMode || !ac || !isFinite(ac.lat) || !isFinite(ac.lon)) {
            return false;
        }
        if (!brlOrigin) {
            brlOrigin = [ac.lat, ac.lon];
            brlOriginSpeed = ac.gs || null;
            brlTracking = true;
            showNotification('BRL: origen definido.');
            return true;
        }
        brlTracking = false;
        updateBrlLine(L.latLng(ac.lat, ac.lon));
        showNotification('BRL: destino definido.');
        return true;
    }

    brlToggle.addEventListener('click', () => {
        setBrlMode(brlMode === 'manual' ? null : 'manual');
    });
    brlAirport.addEventListener('click', () => {
        setBrlMode(brlMode === 'airport' ? null : 'airport');
    });
    brlClear.addEventListener('click', () => {
        clearBrl();
        brlOrigin = brlMode === 'airport' ? [settings.ui_center.lat, settings.ui_center.lon] : null;
        brlTracking = brlMode === 'airport';
        showNotification('BRL borrado');
    });

    map.on('click', (event) => {
        if (!brlMode) {
            return;
        }
        if (brlMode === 'manual') {
            if (!brlOrigin) {
                brlOrigin = [event.latlng.lat, event.latlng.lng];
                brlOriginSpeed = null;
                brlTracking = true;
                showNotification('BRL: origen definido. Clic para destino.');
                return;
            }
            brlTracking = false;
            updateBrlLine(event.latlng);
            showNotification('BRL: destino definido.');
        } else if (brlMode === 'airport') {
            brlTracking = false;
            updateBrlLine(event.latlng);
            showNotification('AP BRL: destino definido.');
        }
    });

    map.on('mousemove', (event) => {
        if (!brlMode || !brlOrigin || !brlTracking) {
            return;
        }
        updateBrlLine(event.latlng);
    });

    // Poll feed.php for live data
    let pollTimer = null;
    let pollInFlight = false;
    let pollAbort = null;
    const pollBackoffSteps = [1000, 2000, 5000, 10000];
    let pollBackoffIndex = 0;
    let lastFeedUpdate = null;

    function scheduleNextPoll(delayMs) {
        if (pollTimer) {
            clearTimeout(pollTimer);
        }
        pollTimer = setTimeout(() => {
            pollFeed();
        }, delayMs);
    }

    function updateFeedStatus(status, message) {
        diagnostics.feedStatus = status;
        diagnostics.feedUpdatedAt = lastFeedUpdate ? formatUpdateTime(lastFeedUpdate) : null;
        const timeText = lastFeedUpdate ? formatUpdateTime(lastFeedUpdate) : '--';
        feedStatusEl.textContent = `Feed: ${status} · Última actualización: ${timeText}`;
        showFeedError(message);
        renderDiagnostics();
    }

    function pollFeed() {
        if (document.hidden) {
            scheduleNextPoll(Math.max(1000, settings.poll_interval_ms || 1500));
            return;
        }
        if (pollInFlight) {
            return;
        }
        pollInFlight = true;
        if (pollAbort) {
            pollAbort.abort();
        }
        pollAbort = new AbortController();
        const radius = Math.min(250, settings.feed_center.radius_nm || 250);
        const url = buildUrl('feed.php');
        fetchJson(url, { signal: pollAbort.signal }, 'Feed request')
            .then(data => {
                if (!data || data.ok !== true) {
                    const message = (data && data.error) ? data.error : 'Upstream feed unavailable.';
                    updateFeedStatus('ERROR', message);
                    reportError('Feed error', message);
                    setFeedError(message, data && data.upstream_http ? `HTTP ${data.upstream_http}` : '');
                    pollBackoffIndex = Math.min(pollBackoffIndex + 1, pollBackoffSteps.length - 1);
                    return;
                }
                lastFeedUpdate = Date.now();
                pollBackoffIndex = 0;
                const hasUpstream = data.upstream_http !== null && data.upstream_http !== undefined;
                const upstreamBad = hasUpstream && Number(data.upstream_http) >= 400;
                const hasError = data.error !== null && data.error !== undefined && data.error !== '';
                const cacheStale = data.cache_stale === true; // [MXAIR2026-ROLL]
                const hasAircraft = Array.isArray(data.ac) && data.ac.length > 0; // [MXAIR2026-ROLL]
                const ageMs = Number.isFinite(data.age_ms) ? data.age_ms : null; // [MXAIR2026-ROLL]
                const cacheMaxStaleMs = Number.isFinite(data.cache_max_stale_ms) ? data.cache_max_stale_ms : null; // [MXAIR2026-ROLL]
                const staleThresholdMs = cacheMaxStaleMs !== null ? cacheMaxStaleMs + 500 : null; // [MXAIR2026-ROLL]
                const aggregateExpired = !hasAircraft && ageMs !== null && staleThresholdMs !== null && ageMs > staleThresholdMs; // [MXAIR2026-ROLL]
                let feedStatus = 'OK'; // [MXAIR2026-ROLL]
                if (!data.ok || aggregateExpired) { // [MXAIR2026-ROLL]
                    feedStatus = 'ERROR'; // [MXAIR2026-ROLL]
                } else if (cacheStale || hasError || upstreamBad) { // [MXAIR2026-ROLL]
                    feedStatus = 'DEGRADED'; // [MXAIR2026-ROLL]
                } // [MXAIR2026-ROLL]
                const warningMessage = feedStatus === 'OK' // [MXAIR2026-ROLL]
                    ? '' // [MXAIR2026-ROLL]
                    : (aggregateExpired ? 'Feed cache stale.' : (hasError ? data.error : (upstreamBad ? `Upstream HTTP ${data.upstream_http}` : 'Feed degraded.'))); // [MXAIR2026-ROLL]
                updateFeedStatus(feedStatus, warningMessage);
                if (feedStatus !== 'OK') {
                    setFeedError(warningMessage || 'Feed degraded', warningMessage);
                } else {
                    clearFeedError();
                }
                try { // [MXAIR2026-ROLL]
                    const seenIds = new Set(); // [MXAIR2026-ROLL]
                    const now = Date.now(); // [MXAIR2026-ROLL]
                    const observedAt = Number.isFinite(data.generated_at_ms) ? data.generated_at_ms : now; // [MXAIR2026-ROLL]
                    (data.ac || []).forEach(ac => { // [MXAIR2026-ROLL]
                        try { // [MXAIR2026-ROLL]
                            if (!ac) { // [MXAIR2026-ROLL]
                                return; // [MXAIR2026-ROLL]
                            }
                            const lat = coerceNumber(ac.lat); // [MXAIR2026-ROLL]
                            const lon = coerceNumber(ac.lon); // [MXAIR2026-ROLL]
                            if (lat === null || lon === null || !isValidLat(lat) || !isValidLon(lon)) { // [MXAIR2026-ROLL]
                                return; // [MXAIR2026-ROLL]
                            }
                            ac.lat = lat; // [MXAIR2026-ROLL]
                            ac.lon = lon; // [MXAIR2026-ROLL]
                            const seenPos = coerceNumber(ac.seen_pos); // [MXAIR2026-ROLL]
                            const lastSeenMs = Number.isFinite(ac.last_seen_ms) // [MXAIR2026-ROLL]
                                ? ac.last_seen_ms // [MXAIR2026-ROLL]
                                : (seenPos !== null ? observedAt - (seenPos * 1000) : now); // [MXAIR2026-ROLL]
                            ac.hex = normalizeIdPart(ac.hex || ac.icao24 || ac.addr || ac.hexid) || null; // [MXAIR2026-ROLL]
                            const flightId = buildAircraftId(ac); // [MXAIR2026-ROLL]
                            if (!flightId) { // [MXAIR2026-ROLL]
                                return; // [MXAIR2026-ROLL]
                            }
                            ac._id = flightId; // [MXAIR2026-ROLL]
                            ac.last_seen_ms = lastSeenMs; // [MXAIR2026-ROLL]
                            seenIds.add(flightId); // [MXAIR2026-ROLL]
                            const previous = flights[flightId] || {}; // [MXAIR2026-ROLL]
                            const note = previous.note || noteStore[flightId] || stripNotes[flightId] || ac.note || ''; // [MXAIR2026-ROLL]
                            flights[flightId] = { ...previous, ...ac, _id: flightId, note, last_seen: lastSeenMs }; // [MXAIR2026-ROLL]
                            try { // [MXAIR2026-ROLL]
                                renderFlight(flights[flightId]); // [MXAIR2026-ROLL]
                            } catch (renderErr) { // [MXAIR2026-ROLL]
                                console.error('Aircraft render failed', renderErr); // [MXAIR2026-ROLL]
                                reportError('Aircraft render failed', renderErr && renderErr.message ? renderErr.message : String(renderErr || 'Unknown')); // [MXAIR2026-ROLL]
                            } // [MXAIR2026-ROLL]
                            stripDataCache[flightId] = flights[flightId]; // [MXAIR2026-ROLL]
                        } catch (err) { // [MXAIR2026-ROLL]
                            console.error('Aircraft update failed', err); // [MXAIR2026-ROLL]
                            reportError('Aircraft update failed', err && err.message ? err.message : String(err || 'Unknown')); // [MXAIR2026-ROLL]
                        } // [MXAIR2026-ROLL]
                    }); // [MXAIR2026-ROLL]
                    pruneFlights(); // [MXAIR2026-ROLL]
                    updateStrips(); // [MXAIR2026-ROLL]
                    updateLabelVisibility(); // [MXAIR2026-ROLL]
                    syncFlightStates(); // [MXAIR2026-ROLL]
                } catch (err) { // [MXAIR2026-ROLL]
                    console.error('Feed render error', err); // [MXAIR2026-ROLL]
                    reportError('Feed render error', err && err.message ? err.message : String(err || 'Unknown')); // [MXAIR2026-ROLL]
                } // [MXAIR2026-ROLL]
            })
            .catch(err => {
                if (err && err.name === 'AbortError') {
                    return;
                }
                console.error('Error fetching feed:', err);
                updateFeedStatus('ERROR', 'Feed error – check diagnostics.');
                setFeedError('Feed request failed', err && err.message ? err.message : String(err || 'Unknown error'));
                pollBackoffIndex = Math.min(pollBackoffIndex + 1, pollBackoffSteps.length - 1);
            })
            .finally(() => {
                pollInFlight = false;
                const nextDelay = pollBackoffIndex > 0
                    ? pollBackoffSteps[pollBackoffIndex]
                    : Math.max(500, settings.poll_interval_ms || 1500);
                scheduleNextPoll(nextDelay);
            });
    }

    function startPolling() {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
        pollBackoffIndex = 0;
        pollFeed();
        updateDebugInfo();
    }

    // Settings panel toggling
    const layersToggle = document.getElementById('layersToggle'); // [MXAIR2026]
    const settingsToggle = document.getElementById('settingsToggle'); // [MXAIR2026]
    const controlPanel = document.getElementById('controlPanel'); // [MXAIR2026]
    const panelTitle = document.getElementById('panelTitle'); // [MXAIR2026]
    const panelClose = document.getElementById('panelClose'); // [MXAIR2026]
    const panelTabs = Array.from(document.querySelectorAll('.panel-tab')); // [MXAIR2026]
    const panelSections = Array.from(document.querySelectorAll('.panel-section')); // [MXAIR2026]
    const airacUpdateBtn = document.getElementById('airacUpdateBtn');
    const airacSpinner = document.getElementById('airacSpinner');
    const airacConsole = document.getElementById('airacConsole');
    const airacHint = document.getElementById('airacHint');
    const sidebarToggle = document.getElementById('sidebarToggle');
    function hideDiagnostics() {
        diagnosticsDismissed = true;
        errorOverlay.style.display = 'none';
    }

    function showDiagnostics() {
        diagnosticsDismissed = false;
        renderDiagnostics();
        errorOverlay.style.display = 'block';
    }

    function updateAiracUi() {
        const airacReady = airacUpdateEnabled && (vatmexRepoConfigured || vatmexDirConfigured);
        airacUpdateBtn.disabled = !airacReady;
        if (airacHint) {
            airacHint.textContent = airacReady
                ? `Listo para actualizar AIRAC${airacCycle ? ` (AIRAC ${airacCycle})` : ''}.` // [MXAIR2026-ROLL]
                : 'Configura las rutas AIRAC y habilita airac_update_enabled en config.php para activar el botón.'; // [MXAIR2026-ROLL]
        }
    }

    diagnosticsClose.addEventListener('click', () => {
        hideDiagnostics();
    });
    feedErrorDetails.addEventListener('click', () => {
        if (errorOverlay.style.display === 'block') {
            hideDiagnostics();
            return;
        }
        showDiagnostics();
    });

    function setSidebarCollapsed(collapsed) {
        document.body.classList.toggle('sidebar-collapsed', collapsed);
        sidebarToggle.textContent = collapsed ? '☰ Show Panel' : '☰ Hide Panel';
        localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
    }

    const storedSidebar = localStorage.getItem('sidebarCollapsed');
    setSidebarCollapsed(storedSidebar === '1');
    sidebarToggle.addEventListener('click', () => {
        const collapsed = document.body.classList.contains('sidebar-collapsed');
        setSidebarCollapsed(!collapsed);
        map.invalidateSize();
    });
    function populateSettingsInputs() { // [MXAIR2026]
        document.getElementById('airportInput').value = settings.airport.icao;
        document.getElementById('feedCenterLatInput').value = settings.feed_center.lat;
        document.getElementById('feedCenterLonInput').value = settings.feed_center.lon;
        document.getElementById('displayCenterLatInput').value = settings.ui_center.lat;
        document.getElementById('displayCenterLonInput').value = settings.ui_center.lon;
        document.getElementById('radiusInput').value = settings.feed_center.radius_nm;
        document.getElementById('pollIntervalInput').value = settings.poll_interval_ms;
        document.getElementById('ringDistances').value = settings.rings.distances.join(',');
        document.getElementById('ringColour').value = settings.rings.style.color;
        document.getElementById('ringWeight').value = settings.rings.style.weight;
        document.getElementById('ringDash').value = settings.rings.style.dash;
        document.getElementById('labelFontSize').value = settings.labels.font_size;
        document.getElementById('labelColour').value = settings.labels.color;
        document.getElementById('uiFontSize').value = settings.display.ui_font_size || 12; // [MXAIR2026]
        document.getElementById('showLabels').checked = settings.labels.show_labels;
        document.getElementById('labelMinZoom').value = settings.labels.min_zoom;
        document.getElementById('basemapSelect').value = settings.display.basemap || 'dark';
        document.getElementById('showAltitude').checked = settings.labels.show_alt;
        document.getElementById('showSpeed').checked = settings.labels.show_gs;
        document.getElementById('showVerticalSpeed').checked = settings.labels.show_vs;
        document.getElementById('showTrack').checked = settings.labels.show_trk;
        document.getElementById('showSquawk').checked = settings.labels.show_sqk;
        document.getElementById('navpointsEnabled').checked = settings.navpoints.enabled;
        document.getElementById('navpointsMinZoom').value = settings.navpoints.min_zoom;
        document.getElementById('navpointsZone').value = settings.navpoints.zone;
        document.getElementById('navpointsMax').value = settings.navpoints.max_points;
        document.getElementById('showTrail').checked = settings.tracks.show_trail;
        document.getElementById('leaderMode').value = settings.leader.mode || 'time';
        document.getElementById('leaderTime').value = settings.leader.time_minutes || 2;
        document.getElementById('leaderDistance').value = settings.leader.distance_nm || 2;
        syncLeaderControls(settings.leader && settings.leader.mode);
        updateAiracUi();
    }

    function setActivePanelTab(tabId) { // [MXAIR2026]
        panelTabs.forEach(tab => {
            tab.classList.toggle('active', tab.dataset.tab === tabId);
        });
        panelSections.forEach(section => {
            section.classList.toggle('active', section.dataset.tab === tabId);
        });
        if (panelTitle) {
            panelTitle.textContent = tabId === 'layers' ? 'Capas' : (tabId === 'appearance' ? 'Apariencias' : 'General');
        }
    }

    function openControlPanel(tabId) { // [MXAIR2026]
        controlPanel.classList.add('active');
        controlPanel.setAttribute('aria-hidden', 'false');
        setActivePanelTab(tabId);
        populateSettingsInputs();
    }

    function closeControlPanel() { // [MXAIR2026]
        controlPanel.classList.remove('active');
        controlPanel.setAttribute('aria-hidden', 'true');
    }

    panelTabs.forEach(tab => {
        tab.addEventListener('click', () => openControlPanel(tab.dataset.tab));
    });
    layersToggle.addEventListener('click', () => openControlPanel('layers')); // [MXAIR2026]
    settingsToggle.addEventListener('click', () => openControlPanel('general')); // [MXAIR2026]
    panelClose.addEventListener('click', closeControlPanel); // [MXAIR2026]
    // Apply settings on button click
    document.getElementById('applySettings').addEventListener('click', () => {
        settings.airport.icao = document.getElementById('airportInput').value.trim().toUpperCase();
        settings.feed_center = { ...defaultSettings.feed_center };
        settings.ui_center.lat = parseFloat(document.getElementById('displayCenterLatInput').value);
        settings.ui_center.lon = parseFloat(document.getElementById('displayCenterLonInput').value);
        const pollVal = parseInt(document.getElementById('pollIntervalInput').value, 10);
        settings.poll_interval_ms = isNaN(pollVal) ? settings.poll_interval_ms : Math.min(5000, Math.max(500, pollVal));
        const rd = document.getElementById('ringDistances').value.split(',').map(x => parseFloat(x));
        settings.rings.distances = rd.filter(x => !isNaN(x) && x > 0);
        settings.rings.style.color = document.getElementById('ringColour').value;
        settings.rings.style.weight = parseFloat(document.getElementById('ringWeight').value) || settings.rings.style.weight;
        settings.rings.style.dash = document.getElementById('ringDash').value;
        settings.labels.font_size = parseInt(document.getElementById('labelFontSize').value, 10) || settings.labels.font_size;
        settings.labels.color = document.getElementById('labelColour').value;
        settings.display.ui_font_size = parseInt(document.getElementById('uiFontSize').value, 10) || settings.display.ui_font_size; // [MXAIR2026]
        settings.labels.show_labels = document.getElementById('showLabels').checked;
        settings.labels.min_zoom = parseInt(document.getElementById('labelMinZoom').value, 10) || settings.labels.min_zoom;
        settings.labels.show_alt = document.getElementById('showAltitude').checked;
        settings.labels.show_gs = document.getElementById('showSpeed').checked;
        settings.labels.show_vs = document.getElementById('showVerticalSpeed').checked;
        settings.labels.show_trk = document.getElementById('showTrack').checked;
        settings.labels.show_sqk = document.getElementById('showSquawk').checked;
        settings.display.basemap = document.getElementById('basemapSelect').value;
        settings.navpoints.enabled = document.getElementById('navpointsEnabled').checked;
        settings.navpoints.min_zoom = parseInt(document.getElementById('navpointsMinZoom').value, 10) || settings.navpoints.min_zoom;
        settings.navpoints.zone = document.getElementById('navpointsZone').value;
        settings.navpoints.max_points = parseInt(document.getElementById('navpointsMax').value, 10) || settings.navpoints.max_points;
        settings.tracks.show_trail = document.getElementById('showTrail').checked;
        settings.leader.mode = document.getElementById('leaderMode').value;
        settings.leader.time_minutes = parseInt(document.getElementById('leaderTime').value, 10) || settings.leader.time_minutes;
        settings.leader.distance_nm = parseInt(document.getElementById('leaderDistance').value, 10) || settings.leader.distance_nm;
        saveSettings();
    });

    document.getElementById('leaderMode').addEventListener('change', (event) => {
        syncLeaderControls(event.target.value);
    });

    function saveSettings() {
        fetchJson(apiUrl('settings.php'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(settings),
        })
            .then(data => {
                if (data.settings) {
                    settings = normalizeSettingsPayload(data.settings);
                    airacUpdateEnabled = !!data.airac_update_enabled;
                    vatmexDirConfigured = !!data.vatmex_dir_configured;
                    vatmexRepoConfigured = !!data.vatmex_repo_configured;
                    vatmexAiracConfigured = !!data.vatmex_airac_configured;
                    airacCycle = data.airac_cycle || null;
                    window.settings = settings;
                    safeStoreSettings();
                    applySettings();
                    updateAiracUi();
                    startPolling();
                    closeControlPanel(); // [MXAIR2026]
                    showNotification('Settings saved');
                }
            })
            .catch(() => {
                showNotification('Failed to save settings');
            });
    }

    function loadSettings() {
        fetchJson(apiUrl('settings.php'), {}, 'Load settings')
            .then(data => {
                if (data.settings) {
                    settings = normalizeSettingsPayload(data.settings);
                }
                ensureCenters();
                airacUpdateEnabled = !!data.airac_update_enabled;
                vatmexDirConfigured = !!data.vatmex_dir_configured;
                vatmexRepoConfigured = !!data.vatmex_repo_configured;
                vatmexAiracConfigured = !!data.vatmex_airac_configured;
                airacCycle = data.airac_cycle || null;
                window.settings = settings;
                safeStoreSettings();
                applySettings();
                updateAiracUi();
                loadStates();
                loadStrips();
                startPolling();
            })
            .catch(() => {
                ensureCenters();
                applySettings();
                vatmexDirConfigured = false;
                vatmexRepoConfigured = false;
                vatmexAiracConfigured = false;
                airacCycle = null;
                updateAiracUi();
                loadStates();
                loadStrips();
                startPolling();
            });
    }

    airacUpdateBtn.addEventListener('click', () => {
        if (!airacUpdateEnabled || !(vatmexRepoConfigured || vatmexDirConfigured)) {
            return;
        }
        airacSpinner.style.display = 'inline-block';
        airacConsole.style.display = 'block';
        airacConsole.textContent = 'Running AIRAC update...';
        fetchJson(apiUrl('admin/airac_update.php'), { method: 'POST' }, 'AIRAC update')
            .then(data => {
                const output = [
                    `OK: ${data.ok}`,
                    `Exit code: ${data.exit_code}`,
                    `Started: ${data.started_at}`,
                    `Finished: ${data.finished_at}`,
                    data.airac_cycle ? `AIRAC: ${data.airac_cycle}` : null,
                    data.vatmex_repo_dir ? `AIRAC repo: ${data.vatmex_repo_dir}` : null, // [MXAIR2026-ROLL]
                    data.vatmex_airac_dir ? `AIRAC dir: ${data.vatmex_airac_dir}` : null, // [MXAIR2026-ROLL]
                    '',
                    'STDOUT:',
                    data.stdout || '(empty)',
                    '',
                    'STDERR:',
                    data.stderr || '(empty)',
                ].filter(Boolean).join('\n');
                airacConsole.textContent = output;
            })
            .catch(err => {
                const message = err && err.message ? err.message : 'AIRAC update failed to start.';
                const detail = err && err.body ? err.body : '';
                airacConsole.textContent = [message, detail].filter(Boolean).join('\n');
            })
            .finally(() => {
                airacSpinner.style.display = 'none';
            });
    });

    routeToggleBtn.addEventListener('click', () => {
        if (!selectedFlight) {
            return;
        }
        if (routeActive) {
            clearRoute();
            return;
        }
        const callsign = flights[selectedFlight]?.flight ? flights[selectedFlight].flight.trim().toUpperCase() : null;
        if (routePlan && callsign && routePlan.callsign === callsign && routePlan.route) {
            applyRouteToMap(routePlan.route);
            return;
        }
        loadFlightPlan(selectedFlight).then(data => {
            if (data && data.route) {
                applyRouteToMap(data.route);
            }
        });
    });

    loadSettings();
    }

    document.addEventListener('DOMContentLoaded', async () => {
        checkHealth();
        const result = await loadLeaflet();
        if (!result.loaded || !window.L) {
            diagnostics.leafletSource = 'failed';
            diagnostics.leafletUrl = null;
            const detailLines = [
                'Leaflet failed to load (L undefined).',
                '',
                'Tried URLs:',
                ...result.attempted.map(url => `- ${url}`),
                '',
                'Failures:',
                ...result.failures.map(err => `- ${err}`),
                '',
                'Tip: likely blocked CDN or SRI mismatch; check network or local assets.',
            ];
            reportError('Leaflet failed to load (L undefined)', detailLines.join('\n'));
            return;
        }
        diagnostics.leafletSource = result.source || 'unknown';
        diagnostics.leafletUrl = result.url || null;
        if (errorOverlay.style.display === 'block') {
            renderDiagnostics();
        }
        initLeafletApp();
    });
    </script>
</body>
</html>
