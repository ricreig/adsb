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
        if ($id === 'mex-border') {
            continue;
        }
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
        }
        /* custom variables for label styling */
        :root {
            --label-size: 12;
            --label-color: #00ff00;
        }
        /* customise Leaflet tooltip (track label) */
        .leaflet-tooltip.track-label {
            color: var(--label-color);
            font-size: calc(var(--label-size) * 1px);
            font-weight: bold;
            white-space: pre;
            text-transform: uppercase;
            background: transparent;
            border: none;
            box-shadow: none;
            padding: 0;
        }
        .leaflet-tooltip.track-label .label-line {
            display: block;
            line-height: 1.15;
        }
        .leaflet-tooltip.track-label .label-muted {
            color: rgba(0, 255, 0, 0.7);
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
        .leaflet-tooltip.track-label.highlight {
            background: rgba(3, 11, 20, 0.75);
            border: 1px solid rgba(0, 255, 0, 0.5);
            border-radius: 4px;
            padding: 4px 6px;
        }
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
            top: 10px;
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
        #sidebar h2 {
            margin-top: 0;
            font-size: 16px;
            text-align: center;
        }
        .layer-control {
            margin-bottom: 10px;
        }
        .layer-control label {
            display: flex;
            align-items: center;
            margin-bottom: 4px;
            cursor: pointer;
        }
        .layer-control input[type="checkbox"] {
            margin-right: 6px;
        }
        .layer-control input[type="color"] {
            margin-left: auto;
            border: none;
            background: none;
        }
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
        .strip {
            background: #1d2d44;
            border: 1px solid #3f5270;
            padding: 6px;
            margin-bottom: 6px;
            cursor: pointer;
        }
        .strip.assumed {
            border-color: #00c1ff;
            background: #1c4058;
        }
        .strip.pending {
            border-color: #facc15;
            background: #3a2f1a;
        }
        .strip.released {
            opacity: 0.5;
            border-color: #5c6b7a;
        }
        .strip.selected {
            box-shadow: 0 0 0 2px rgba(34, 211, 238, 0.7);
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
        .strip .strip-status {
            font-size: 11px;
            font-weight: bold;
            padding: 2px 6px;
            border-radius: 4px;
            background: #2b3a50;
            color: #d0e6ff;
        }
        .strip.assumed .strip-status {
            background: #22c55e;
            color: #052e13;
        }
        .strip.pending .strip-status {
            background: #facc15;
            color: #1f2937;
        }
        .strip.released .strip-status {
            background: #4b5563;
            color: #d1d5db;
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
            right: 340px;
            background: rgba(14, 21, 32, 0.9);
            color: #e0e0e0;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            z-index: 1000;
        }
        #tileStatus {
            position: absolute;
            top: 10px;
            left: 10px;
            background: #f39c12;
            color: #1a1a1a;
            padding: 6px 10px;
            border-radius: 4px;
            display: none;
            font-size: 12px;
            z-index: 1000;
        }
        #feedError {
            position: absolute;
            top: 36px;
            right: 340px;
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
        <h2>Airspace Layers</h2>
        <div id="layerControls"></div>
        <h2>Tools</h2>
        <div style="display:flex;gap:6px;margin-bottom:10px;">
            <button id="brlToggle" style="flex:1;">BRL</button>
            <button id="brlAirport" style="flex:1;">AP BRL</button>
            <button id="brlClear" style="flex:1;">Clear BRL</button>
        </div>
        <h2>Flight Strips</h2>
        <div id="stripTray"></div>
        <div id="stripDetails">Selecciona una tira para ver detalles.</div>
        <h2>Selected Flight</h2>
        <div id="flightInfo">Click a flight to see details.</div>
        <div id="flightPlanPanel">
            <h3>Flight Plan</h3>
            <div id="flightPlanSummary">Selecciona un vuelo para ver el plan.</div>
            <button id="routeToggleBtn" type="button" disabled>Route OFF</button>
        </div>

        <h2>Settings</h2>
        <button id="settingsToggle" style="width:100%;margin-bottom:10px;">Open Settings</button>
        <div id="settingsPanel" style="display:none;padding:6px;border:1px solid #3f5270;background:#243752;font-size:13px;">
            <h3 style="margin-top:0;font-size:14px;text-align:center;">Display Settings</h3>
            <label style="display:block;margin-bottom:4px;">Primary Airport ICAO
                <input type="text" id="airportInput" value="<?php echo htmlspecialchars($config['airport']['icao']); ?>" style="width:80px;margin-left:4px;"/>
            </label>
            <label style="display:block;margin-bottom:4px;">Feed Center Lat
                <input type="number" id="feedCenterLatInput" step="0.0001" value="<?php echo htmlspecialchars($config['airport']['lat']); ?>" style="width:90px;margin-left:4px;"/>
            </label>
            <label style="display:block;margin-bottom:4px;">Feed Center Lon
                <input type="number" id="feedCenterLonInput" step="0.0001" value="<?php echo htmlspecialchars($config['airport']['lon']); ?>" style="width:90px;margin-left:4px;"/>
            </label>
            <label style="display:block;margin-bottom:4px;">Display Center Lat
                <input type="number" id="displayCenterLatInput" step="0.0001" value="<?php echo htmlspecialchars($config['display_center']['lat'] ?? 32.541); ?>" style="width:90px;margin-left:4px;"/>
            </label>
            <label style="display:block;margin-bottom:4px;">Display Center Lon
                <input type="number" id="displayCenterLonInput" step="0.0001" value="<?php echo htmlspecialchars($config['display_center']['lon'] ?? -116.97); ?>" style="width:90px;margin-left:4px;"/>
            </label>
            <label style="display:block;margin-bottom:4px;">Radius (NM, max 250)
                <input type="number" id="radiusInput" min="1" max="250" value="250" style="width:80px;margin-left:4px;"/>
            </label>
            <label style="display:block;margin-bottom:4px;">Polling Interval (ms)
                <input type="number" id="pollIntervalInput" min="500" max="5000" value="1500" style="width:80px;margin-left:4px;"/>
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
            <label style="display:block;margin-bottom:4px;">Label Font Size
                <input type="number" id="labelFontSize" min="8" max="24" value="12" style="width:50px;margin-left:4px;"/>
            </label>
            <label style="display:block;margin-bottom:4px;">Label Colour
                <input type="color" id="labelColour" value="#00ff00" style="margin-left:4px;"/>
            </label>
            <label style="display:block;margin-bottom:4px;">
                <input type="checkbox" id="showLabels" checked/>
                Show Track Labels
            </label>
            <label style="display:block;margin-bottom:4px;">Label Min Zoom
                <input type="number" id="labelMinZoom" min="3" max="14" value="7" style="width:60px;margin-left:4px;"/>
            </label>
            <label style="display:block;margin-bottom:4px;">Basemap Style
                <select id="basemapSelect" style="margin-left:4px;">
                    <option value="dark">Dark (Radar)</option>
                    <option value="light">Light</option>
                </select>
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
            <div class="settings-section">
                <strong>Navpoints</strong>
                <label style="display:block;margin-bottom:4px;">
                    <input type="checkbox" id="navpointsEnabled" checked/>
                    Show Navpoints
                </label>
                <label style="display:block;margin-bottom:4px;">Navpoints Min Zoom
                    <input type="number" id="navpointsMinZoom" min="3" max="14" value="7" style="width:60px;margin-left:4px;"/>
                </label>
                <label style="display:block;margin-bottom:4px;">Navpoints Zone
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
                <label style="display:block;margin-bottom:4px;">Max Navpoints
                    <input type="number" id="navpointsMax" min="250" max="5000" value="2000" style="width:70px;margin-left:4px;"/>
                </label>
            </div>
            <div class="settings-section">
                <strong>Category Styles (future)</strong>
                <div style="font-size:12px;color:#9fb3c8;margin-top:4px;">
                    Placeholder for per-layer styling defaults (loaded/saved with settings).
                </div>
            </div>
            <div class="settings-section">
                <strong>AIRAC / VATMEX</strong>
                <div style="margin-top:6px;">
                    <button id="airacUpdateBtn" style="width:100%;display:none;">UPDATE AIRAC (PULL VATMEX + REBUILD GEOJSON)</button>
                    <span id="airacSpinner" class="spinner" style="display:none;"></span>
                </div>
                <div id="airacConsole" class="console-box" style="margin-top:6px;display:none;"></div>
            </div>
            <button id="applySettings" style="width:100%;margin-top:10px;">Apply Settings</button>
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
    const ADSB_BASE = normalizeBasePath(window.ADSB_BASE || window.ADSB_BASE_PATH || '/');
    window.ADSB_BASE = ADSB_BASE;
    function buildUrl(path) {
        if (!path) {
            return location.origin + ADSB_BASE;
        }
        if (/^https?:\/\//i.test(path)) {
            return path;
        }
        const cleanPath = path.replace(/^\/+/, '');
        return `${location.origin}${ADSB_BASE}${cleanPath}`;
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
    const errorOverlay = document.getElementById('errorOverlay');
    const diagnosticsContent = document.getElementById('diagnosticsContent');
    const diagnosticsClose = document.getElementById('diagnosticsClose');
    const errorLog = [];
    let diagnosticsDismissed = false;
    const diagnostics = {
        healthStatus: 'pending',
        healthDetail: null,
        leafletSource: 'unknown',
        leafletUrl: null,
        feedStatus: 'unknown',
        feedUpdatedAt: null,
    };

    function renderDiagnostics() {
        const lines = [
            'Diagnostics',
            `Health: ${diagnostics.healthStatus}`,
            diagnostics.healthDetail ? `Health detail: ${diagnostics.healthDetail}` : null,
            `Leaflet: ${diagnostics.leafletSource}`,
            diagnostics.leafletUrl ? `Leaflet URL: ${diagnostics.leafletUrl}` : null,
            `Feed: ${diagnostics.feedStatus}`,
            diagnostics.feedUpdatedAt ? `Feed updated: ${diagnostics.feedUpdatedAt}` : null,
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

    function fetchGeoJson(url, context) {
        return fetchJson(
            url,
            { cache: 'no-store' },
            context,
            ['application/json', 'application/geo+json', 'application/vnd.geo+json']
        ).then(data => {
            if (!data || !data.type) {
                const error = new Error('GeoJSON missing type');
                error.url = url;
                throw error;
            }
            return data;
        });
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
        const urls = [
            {
                url: 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js',
            },
            {
                url: 'https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js',
            },
            {
                url: buildUrl('assets/vendor/leaflet/leaflet.js'),
            },
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
        zoomControl: true,
        attributionControl: true,
        preferCanvas: true,
    }).setView([
        <?php echo (float)($config['display_center']['lat'] ?? 32.541); ?>,
        <?php echo (float)($config['display_center']['lon'] ?? -116.97); ?>
    ], 8);
    const tileStatus = document.getElementById('tileStatus');
    map.createPane('tracks');
    map.createPane('targets');
    map.createPane('labels');
    map.getPane('tracks').style.zIndex = 350;
    map.getPane('targets').style.zIndex = 450;
    map.getPane('labels').style.zIndex = 650;
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

    // Container for GeoJSON overlay layers
    const overlays = {};
    const bboxLayers = new Set([
        'airways',
        'procedures',
        'mva',
        'vfr',
        'sectors',
        'restricted-areas',
        'fir-limits',
        'nav-points',
        'navaids',
        'fixes',
    ]);

    // Colours per layer (defaults).  Operators can change via colour inputs.
    const layerColours = {};

    // Navpoints layer
    const navpointsLayer = L.layerGroup();
    const navpointRenderer = L.canvas({ padding: 0.5 });
    let navpointsRequest = null;
    let navpointsLastWarning = 0;

    // Create layer controls in the sidebar
    const layerControlsDiv = document.getElementById('layerControls');
    Object.keys(geojsonLayers).forEach(id => {
        // Default random colour for each layer
        const defaultColour = '#' + Math.floor(Math.random() * 0xffffff).toString(16).padStart(6, '0');
        layerColours[id] = defaultColour;
        const wrapper = document.createElement('div');
        wrapper.className = 'layer-control';
        const label = document.createElement('label');
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.checked = false;
        checkbox.dataset.layerId = id;
        const span = document.createElement('span');
        span.textContent = id.replace(/-/g, ' ');
        const colourPicker = document.createElement('input');
        colourPicker.type = 'color';
        colourPicker.value = defaultColour;
        colourPicker.dataset.layerId = id;
        // Event: toggling layer visibility
        checkbox.addEventListener('change', (e) => {
            const lid = e.target.dataset.layerId;
            if (e.target.checked) {
                loadLayer(lid);
            } else {
                removeLayer(lid);
            }
        });
        // Event: colour change
        colourPicker.addEventListener('input', (e) => {
            const lid = e.target.dataset.layerId;
            layerColours[lid] = e.target.value;
            if (overlays[lid]) {
                overlays[lid].setStyle({ color: e.target.value, weight: 1.5 });
            }
        });
        label.appendChild(checkbox);
        label.appendChild(span);
        label.appendChild(colourPicker);
        wrapper.appendChild(label);
        layerControlsDiv.appendChild(wrapper);
    });

    // Load a GeoJSON layer when checked
    function buildLayerUrl(id) {
        let url = buildUrl(geojsonLayers[id]);
        if (bboxLayers.has(id)) {
            const bounds = map.getBounds();
            url += `&north=${encodeURIComponent(bounds.getNorth())}`;
            url += `&south=${encodeURIComponent(bounds.getSouth())}`;
            url += `&east=${encodeURIComponent(bounds.getEast())}`;
            url += `&west=${encodeURIComponent(bounds.getWest())}`;
        }
        return url;
    }

    function loadLayer(id, forceReload = false) {
        if (overlays[id] && !forceReload) {
            map.addLayer(overlays[id]);
            return;
        }
        if (overlays[id]) {
            map.removeLayer(overlays[id]);
            delete overlays[id];
        }
        const url = buildLayerUrl(id);
        fetchGeoJson(url, `GeoJSON layer ${id} (${url})`)
            .then(data => {
                const normalized = normalizeGeojson(data, {
                    forcePolygon: ['atz', 'ctr', 'tma', 'restricted-areas'].includes(id),
                });
                const layer = L.geoJSON(normalized, {
                    style: feature => {
                        return {
                            color: layerColours[id],
                            weight: 1.5,
                            fill: true,
                            fillOpacity: 0.15
                        };
                    },
                    onEachFeature: (feature, layerEl) => {
                        if (feature.properties && feature.properties.name) {
                            layerEl.bindTooltip(feature.properties.name, { permanent: false });
                        }
                    }
                });
                overlays[id] = layer;
                layer.addTo(map);
            })
            .catch(err => console.error('Error loading layer ' + id, err));
    }

    let bboxReloadTimer = null;
    function scheduleBboxLayerReload() {
        if (bboxReloadTimer) {
            clearTimeout(bboxReloadTimer);
        }
        bboxReloadTimer = setTimeout(() => {
            Object.keys(overlays).forEach(id => {
                if (bboxLayers.has(id) && map.hasLayer(overlays[id])) {
                    loadLayer(id, true);
                }
            });
        }, 600);
    }

    // Remove a GeoJSON layer when unchecked
    function removeLayer(id) {
        if (overlays[id]) {
            map.removeLayer(overlays[id]);
        }
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
            const deltaLon = deltaLat / Math.cos(settings.display_center.lat * Math.PI / 180);
            north = Math.min(north, settings.display_center.lat + deltaLat);
            south = Math.max(south, settings.display_center.lat - deltaLat);
            east = Math.min(east, settings.display_center.lon + deltaLon);
            west = Math.max(west, settings.display_center.lon - deltaLon);
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
        const url = apiUrl('navpoints.php')
            + `?north=${encodeURIComponent(bbox.north)}`
            + `&south=${encodeURIComponent(bbox.south)}`
            + `&east=${encodeURIComponent(bbox.east)}`
            + `&west=${encodeURIComponent(bbox.west)}`
            + `&limit=${encodeURIComponent(limit)}`;
        if (navpointsRequest) {
            navpointsRequest.abort();
        }
        navpointsRequest = new AbortController();
        fetchJson(url, { signal: navpointsRequest.signal }, 'Navpoints request')
            .then(data => {
                navpointsLayer.clearLayers();
                const normalized = normalizeGeojson(data);
                L.geoJSON(normalized, {
                    pointToLayer: (feature, latlng) => {
                        return L.circleMarker(latlng, {
                            radius: 2.5,
                            color: '#ffd166',
                            fillColor: '#ffd166',
                            fillOpacity: 0.9,
                            weight: 0,
                            renderer: navpointRenderer,
                        });
                    },
                    onEachFeature: (feature, layerEl) => {
                        const name = feature.properties && (feature.properties.id || feature.properties.name);
                        if (name && settings.labels.show_labels && map.getZoom() >= settings.labels.min_zoom) {
                            layerEl.bindTooltip(String(name), {
                                permanent: false,
                                direction: 'top',
                                offset: [0, -6],
                            });
                        }
                    },
                }).addTo(navpointsLayer);
                if (data.meta && data.meta.truncated && Date.now() - navpointsLastWarning > 5000) {
                    navpointsLastWarning = Date.now();
                    showNotification('Límite de navpoints alcanzado; acércate para más detalle.');
                }
            })
            .catch(() => {});
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
    const flights = {}; // keyed by hex
    const flightMarkers = {}; // marker/vector/track/history/lastUpdate
    const flightStates = {};
    let selectedFlight = null;
    const noteStore = loadNoteStore();
    const stripTray = document.getElementById('stripTray');
    const stripDetails = document.getElementById('stripDetails');
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

    const defaultSettings = {
        airport: {
            icao: '<?php echo addslashes($config['airport']['icao']); ?>',
        },
        feed_center: {
            lat: <?php echo (float)$config['airport']['lat']; ?>,
            lon: <?php echo (float)$config['airport']['lon']; ?>,
        },
        display_center: {
            lat: <?php echo (float)($config['display_center']['lat'] ?? 32.541); ?>,
            lon: <?php echo (float)($config['display_center']['lon'] ?? -116.97); ?>,
        },
        radius_nm: 250,
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
        },
        navpoints: {
            enabled: true,
            min_zoom: 7,
            zone: 'all',
            max_points: 2000,
        },
        category_styles: {
            default: {
                color: '#3aa0ff',
                weight: 1.5,
                dash: '',
            },
        },
    };

    let settings = JSON.parse(JSON.stringify(defaultSettings));
    let airacUpdateEnabled = false;
    let vatmexDirConfigured = false;

    // Range ring overlay container
    let rangeRings = [];

    // Apply settings to map and UI
    function applySettings() {
        ensureCenters();
        document.documentElement.style.setProperty('--label-size', settings.labels.font_size);
        document.documentElement.style.setProperty('--label-color', settings.labels.color);
        map.setView([settings.display_center.lat, settings.display_center.lon], map.getZoom());
        updateRangeRings();
        switchBasemap(settings.display && settings.display.basemap ? settings.display.basemap : 'dark');
        updateLabelVisibility();
        updateNavpoints();
    }

    // Create or refresh range rings around the primary airport
    function updateRangeRings() {
        // Remove existing rings
        rangeRings.forEach(r => map.removeLayer(r));
        rangeRings = [];
        const dashArray = settings.rings.style.dash || '';
        settings.rings.distances.forEach(dist => {
            const circle = L.circle([settings.display_center.lat, settings.display_center.lon], {
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
    map.on('moveend', () => {
        scheduleNavpointsUpdate();
        scheduleBboxLayerReload();
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
        return Number.isFinite(lat) && lat >= -90 && lat <= 90;
    }

    function isValidLon(lon) {
        return Number.isFinite(lon) && lon >= -180 && lon <= 180;
    }

    function normalizeCenter(center, fallback) {
        if (!center || !isValidLat(center.lat) || !isValidLon(center.lon)) {
            return { ...fallback };
        }
        return { lat: Number(center.lat), lon: Number(center.lon) };
    }

    function ensureCenters() {
        settings.feed_center = normalizeCenter(
            settings.feed_center || settings.airport,
            defaultSettings.feed_center
        );
        settings.display_center = normalizeCenter(
            settings.display_center,
            defaultSettings.display_center
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

    function saveNote(hex, note) {
        if (!hex) {
            return;
        }
        if (note) {
            noteStore[hex] = note;
            stripNotes[hex] = note;
        } else {
            delete noteStore[hex];
            delete stripNotes[hex];
        }
        persistNoteStore(noteStore);
        persistStrip({ hex, note });
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
                    stripStatuses[strip.hex] = strip.status || 'normal';
                    flightStates[strip.hex] = strip.status || flightStates[strip.hex] || 'normal';
                    stripDataCache[strip.hex] = strip;
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
                        stripStatuses[entry.hex] = entry.status || 'normal';
                        flightStates[entry.hex] = entry.status || flightStates[entry.hex] || 'normal';
                        stripDataCache[entry.hex] = entry;
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

    function ensureStripForFlight(hex) {
        if (!hex) {
            return;
        }
        if (!stripOrder.includes(hex)) {
            stripOrder.push(hex);
        }
        const status = flightStates[hex] || stripStatuses[hex] || 'normal';
        const note = flights[hex]?.note || stripNotes[hex] || '';
        persistStrip({
            hex,
            status,
            note,
        });
    }

    function updateStripDetails(hex) {
        if (!hex) {
            stripDetails.textContent = 'Selecciona una tira para ver detalles.';
            return;
        }
        const ac = flights[hex] || {};
        const status = getFlightStatus(hex);
        const note = ac.note || stripNotes[hex] || '';
        const callsign = ac.flight ? ac.flight.trim().toUpperCase() : hex;
        const alt = ac.alt ? `${ac.alt}FT` : '---';
        const gs = ac.gs ? `${ac.gs}KT` : '---';
        const trk = ac.track ? `${ac.track}°` : '---';
        stripDetails.innerHTML = `
            <strong>${escapeHtml(callsign)}</strong><br>
            Estado: ${escapeHtml(status.toUpperCase())}<br>
            ALT ${escapeHtml(alt)} · GS ${escapeHtml(gs)} · TRK ${escapeHtml(trk)}<br>
            Nota: ${escapeHtml(note || '---')}
        `;
    }

    function loadStates() {
        fetchJson(apiUrl('state.php'), {}, 'Load states')
            .then(data => {
                const states = Array.isArray(data.states) ? data.states : [];
                states.forEach(state => {
                    if (!state || !state.hex) {
                        return;
                    }
                    flightStates[state.hex] = state.status || flightStates[state.hex] || 'normal';
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
        const states = Object.values(flights).map(ac => ({
            hex: ac.hex,
            lat: ac.lat,
            lon: ac.lon,
            alt: ac.alt,
            track: ac.track,
            gs: ac.gs,
            status: getFlightStatus(ac.hex),
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
    function getFlightStatus(hex) {
        return flightStates[hex] || stripStatuses[hex] || 'normal';
    }

    function flightColor(status) {
        if (status === 'assumed') {
            return '#00c1ff';
        }
        if (status === 'released') {
            return '#8f9bb3';
        }
        return '#50fa7b';
    }

    function buildFlightIcon(color, opacity = 1) {
        return L.divIcon({
            className: 'flight-marker',
            html: `<div class="flight-icon" style="--flight-color:${color};--flight-opacity:${opacity};"></div>`,
            iconSize: [12, 12],
            iconAnchor: [6, 6],
        });
    }

    function updateMarkerIcon(marker, color, opacity = 1) {
        marker.setIcon(buildFlightIcon(color, opacity));
    }

    function shouldShowLabel(ac) {
        if (!settings.labels.show_labels) {
            return false;
        }
        if (selectedFlight && selectedFlight === ac.hex) {
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

    function isEmergency(ac) {
        return ['7500', '7600', '7700'].includes(ac.squawk) || (ac.emergency && ac.emergency !== 'none');
    }

    function updateTooltipClass(marker, ac) {
        const tooltip = marker.getTooltip();
        if (!tooltip) {
            return;
        }
        const el = tooltip.getElement();
        if (!el) {
            return;
        }
        el.classList.toggle('highlight', selectedFlight === ac.hex || isEmergency(ac));
    }

    function bindLabelNoteEditor(marker, hex) {
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
            const ac = flights[hex];
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
                    saveNote(hex, updated);
                } else {
                    noteEl.textContent = ac.note || '---';
                }
                const markerData = flightMarkers[hex];
                if (markerData) {
                    markerData.marker.setTooltipContent(labelFromAc(ac));
                    updateTooltipClass(markerData.marker, ac);
                }
                if (selectedFlight === hex) {
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

    function shouldAppendTrackPoint(history, ac) {
        if (!history.length) {
            return true;
        }
        const last = history[history.length - 1];
        const delta = distanceNm(last.lat, last.lon, ac.lat, ac.lon);
        const headingDelta = Math.abs(((ac.track || 0) - (last.track || 0) + 540) % 360 - 180);
        return delta >= 0.2 || headingDelta >= 15;
    }

    function updateTrackHistory(state, ac) {
        const now = Date.now();
        if (!state.history) {
            state.history = [];
        }
        if (shouldAppendTrackPoint(state.history, ac)) {
            state.history.push({ lat: ac.lat, lon: ac.lon, ts: now, track: ac.track || 0 });
        }
        const cutoff = now - 5 * 60 * 1000;
        state.history = state.history.filter(pt => pt.ts >= cutoff);
        const maxPoints = 35;
        if (state.history.length > maxPoints) {
            state.history = state.history.slice(state.history.length - maxPoints);
        }
        return state.history;
    }

    function renderFlight(ac) {
        const id = ac.hex;
        const existing = flightMarkers[id];
        const pos = [ac.lat, ac.lon];
        const vectorLengthNm = 2; // minutes ahead – 2 minutes for short leader
        const predictedDistance = (ac.gs || 0) * (vectorLengthNm / 60);
        const predicted = destinationPoint(ac.lat, ac.lon, ac.track, predictedDistance);
        const status = getFlightStatus(id);
        const color = flightColor(status);
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
            updateMarkerIcon(existing.marker, color, status === 'released' ? 0.6 : 1);
            existing.vector.setStyle({ color, opacity: status === 'released' ? 0.3 : 0.7 });
            existing.marker.setTooltipOpacity(shouldShowLabel(ac) ? 1 : 0);
            const history = updateTrackHistory(existing, ac);
            if (existing.track) {
                existing.track.setLatLngs(history.map(pt => [pt.lat, pt.lon]));
                existing.track.setStyle({ color, opacity: status === 'released' ? 0.3 : 0.7 });
            }
            updateTooltipClass(existing.marker, ac);
            bindLabelNoteEditor(existing.marker, id);
        } else {
            const marker = L.marker(pos, {
                icon: buildFlightIcon(color),
                pane: 'targets',
            }).addTo(map);
            const vector = L.polyline([pos, predicted], {
                color,
                weight: 1.0,
                opacity: status === 'released' ? 0.3 : 0.7,
                dashArray: '4 4',
                renderer: targetRenderer,
                pane: 'targets',
            }).addTo(map);
            const history = updateTrackHistory({ history: [] }, ac);
            const track = L.polyline(history.map(pt => [pt.lat, pt.lon]), {
                color,
                weight: 1.0,
                opacity: status === 'released' ? 0.3 : 0.7,
                renderer: trackRenderer,
                pane: 'tracks',
            }).addTo(map);
            const placement = labelPlacement(ac);
            marker.bindTooltip(labelFromAc(ac), {
                offset: placement.offset,
                direction: placement.direction,
                permanent: true,
                interactive: true,
                className: 'track-label',
                pane: 'labels',
            });
            marker.setTooltipOpacity(shouldShowLabel(ac) ? 1 : 0);
            marker.on('click', () => {
                handleBrlSelection(flights[id]);
                selectFlight(id);
            });
            marker.on('tooltipopen', () => {
                bindLabelNoteEditor(marker, id);
                updateTooltipClass(marker, ac);
            });
            flightMarkers[id] = { marker, vector, track, history, lastUpdate: Date.now() };
        }
    }

    // Generate a label string for a track
    function labelFromAc(ac) {
        const callsign = ac.flight ? ac.flight.trim().toUpperCase() : ac.hex;
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
        const line4 = `${sqk}  NOTE: <span class="label-note" data-hex="${escapeHtml(ac.hex || '')}">${note}</span>`;
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
            flightMarkers[id].marker.setTooltipOpacity(visible ? 1 : 0);
            updateTooltipClass(flightMarkers[id].marker, ac);
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
            delete flightMarkers[id];
        }
        delete flights[id];
        if (selectedFlight === id) {
            selectedFlight = null;
            flightInfoDiv.textContent = 'Click a flight to see details.';
            updateFlightPlanPanel(null);
        }
        const stripEl = document.querySelector('.strip[data-hex="' + id + '"]');
        if (stripEl && !stripOrder.includes(id)) {
            stripEl.remove();
        }
    }

    // Remove stale flights (not updated recently)
    function pruneFlights() {
        const now = Date.now();
        Object.keys(flightMarkers).forEach(id => {
            const state = flightMarkers[id];
            if (!state || !state.lastUpdate) {
                return;
            }
            if (now - state.lastUpdate <= 90 * 1000) {
                return;
            }
            removeFlight(id);
        });
    }

    // Update flight strips in tray
    function buildStripHtml(ac, status) {
        const callsign = ac.flight ? ac.flight.trim().toUpperCase() : ac.hex;
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
        const note = ac.note || stripNotes[ac.hex] || '';
        const statusLabel = status === 'assumed' ? 'ASUMIDA' : status === 'released' ? 'LIBERADA' : 'PENDIENTE';
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
            <div class="strip-header">
                <span>${callsign}</span>
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
            if (!stripOrder.includes(strip.dataset.hex)) {
                strip.remove();
            }
        });

        stripOrder.forEach((hex, index) => {
            const ac = flights[hex] || stripDataCache[hex] || { hex };
            let strip = document.querySelector('.strip[data-hex="' + hex + '"]');
            if (!strip) {
                strip = document.createElement('div');
                strip.className = 'strip';
                strip.dataset.hex = hex;
                strip.draggable = true;
                strip.addEventListener('click', () => {
                    selectedStrip = hex;
                    updateStripDetails(hex);
                    selectFlight(hex);
                });
                strip.addEventListener('dragstart', event => {
                    event.dataTransfer.setData('text/plain', hex);
                    event.dataTransfer.effectAllowed = 'move';
                });
                strip.addEventListener('dragover', event => {
                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';
                });
                strip.addEventListener('drop', event => {
                    event.preventDefault();
                    const draggedHex = event.dataTransfer.getData('text/plain');
                    if (!draggedHex || draggedHex === hex) {
                        return;
                    }
                    const newOrder = stripOrder.filter(item => item !== draggedHex);
                    const insertIndex = newOrder.indexOf(hex);
                    newOrder.splice(insertIndex, 0, draggedHex);
                    stripOrder = newOrder;
                    updateStrips();
                    persistStripOrder(stripOrder);
                });
                stripTray.appendChild(strip);
            }
            const status = getFlightStatus(hex);
            strip.classList.toggle('assumed', status === 'assumed');
            strip.classList.toggle('released', status === 'released');
            strip.classList.toggle('pending', status === 'normal');
            strip.classList.toggle('selected', selectedFlight === hex || selectedStrip === hex);
            strip.innerHTML = buildStripHtml(ac, status);
            if (['7500','7600','7700'].includes(ac.squawk)) {
                strip.style.background = '#8a0e0e';
            } else {
                strip.style.background = '';
            }
            stripTray.appendChild(strip);
        });
        if (selectedStrip) {
            updateStripDetails(selectedStrip);
        }
    }

    // Select a flight for detailed view and route display
    function selectFlight(hex) {
        const ac = flights[hex];
        if (!ac) return;
        selectedFlight = hex;
        selectedStrip = hex;
        ensureStripForFlight(hex);
        updateStripDetails(hex);
        routePlan = null;
        clearRoute();
        // Highlight marker
        Object.keys(flightMarkers).forEach(id => {
            const m = flightMarkers[id].marker;
            const status = getFlightStatus(id);
            const color = id === hex ? '#22d3ee' : flightColor(status);
            updateMarkerIcon(m, color, status === 'released' ? 0.6 : 1);
            updateTooltipClass(m, flights[id] || {});
        });
        updateStrips();
        // Show details
        // Build details HTML
        const status = getFlightStatus(hex);
        let html = '';
        html += '<strong>CALLSIGN:</strong> ' + (ac.flight ? ac.flight.trim().toUpperCase() : ac.hex) + '<br>';
        html += '<strong>STATUS:</strong> ' + status.toUpperCase() + '<br>';
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
        html += '<button id="assumeBtn">Assume</button> ' +
                '<button id="releaseBtn">Release</button> ' +
                '<button id="saveNoteBtn">Save Note</button>';
        flightInfoDiv.innerHTML = html;
        // Bind buttons
        document.getElementById('assumeBtn').addEventListener('click', () => {
            assumeFlight(hex);
        });
        document.getElementById('releaseBtn').addEventListener('click', () => {
            releaseFlight(hex);
        });
        document.getElementById('saveNoteBtn').addEventListener('click', () => {
            const text = document.getElementById('noteField').value.trim();
            flights[hex].note = text;
            saveNote(hex, text);
            // Immediately update tooltip with note
            const existing = flightMarkers[hex];
            if (existing) {
                existing.marker.setTooltipContent(labelFromAc(flights[hex]));
                updateTooltipClass(existing.marker, flights[hex]);
            }
            updateStrips();
        });
        updateLabelVisibility();
        updateFlightPlanPanel(hex);
    }

    // Assume a flight (mark as assumed)
    function assumeFlight(hex) {
        flightStates[hex] = 'assumed';
        stripStatuses[hex] = 'assumed';
        const markerData = flightMarkers[hex];
        if (markerData) {
            const color = flightColor('assumed');
            updateMarkerIcon(markerData.marker, color);
            markerData.vector.setStyle({ color });
            if (markerData.track) {
                markerData.track.setStyle({ color });
            }
        }
        const strip = document.querySelector('.strip[data-hex="' + hex + '"]');
        if (strip) {
            strip.classList.add('assumed');
            strip.classList.remove('released');
        }
        updateStrips();
        updateLabelVisibility();
        if (selectedFlight === hex) {
            selectFlight(hex);
        }
        persistStrip({ hex, status: 'assumed', note: flights[hex]?.note || stripNotes[hex] || '' });
    }

    // Release a flight (mark as released)
    function releaseFlight(hex) {
        flightStates[hex] = 'released';
        stripStatuses[hex] = 'released';
        const markerData = flightMarkers[hex];
        if (markerData) {
            const color = flightColor('released');
            updateMarkerIcon(markerData.marker, color, 0.6);
            markerData.vector.setStyle({ color, opacity: 0.3 });
            if (markerData.track) {
                markerData.track.setStyle({ color, opacity: 0.3 });
            }
        }
        const strip = document.querySelector('.strip[data-hex="' + hex + '"]');
        if (strip) {
            strip.classList.remove('assumed');
            strip.classList.add('released');
        }
        updateStrips();
        updateLabelVisibility();
        if (selectedFlight === hex) {
            selectFlight(hex);
        }
        persistStrip({ hex, status: 'released', note: flights[hex]?.note || stripNotes[hex] || '' });
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

    function loadFlightPlan(hex) {
        const ac = flights[hex];
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
                updateFlightPlanPanel(hex);
                return data;
            })
            .catch(() => {
                ac.routeSummary = 'Sin ruta disponible';
                updateStrips();
                showNotification('Sin ruta disponible para ' + callsign);
                return null;
            });
    }

    function updateFlightPlanPanel(hex) {
        const ac = flights[hex];
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
                    html: `<div style="background:#0e1520;color:#facc15;padding:2px 6px;border:1px solid #facc15;border-radius:4px;font-size:11px;">${labelText}</div>`
                }),
            }).addTo(map);
        } else {
            brlLabel.setLatLng(mid);
            brlLabel.setIcon(L.divIcon({
                className: 'brl-label',
                html: `<div style="background:#0e1520;color:#facc15;padding:2px 6px;border:1px solid #facc15;border-radius:4px;font-size:11px;">${labelText}</div>`
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
            brlOrigin = [settings.display_center.lat, settings.display_center.lon];
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
        brlOrigin = brlMode === 'airport' ? [settings.display_center.lat, settings.display_center.lon] : null;
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
        const radius = Math.min(250, settings.radius_nm || 250);
        const url = buildUrl('feed.php') + '?lat=' + encodeURIComponent(settings.feed_center.lat)
            + '&lon=' + encodeURIComponent(settings.feed_center.lon)
            + '&radius_nm=' + encodeURIComponent(radius);
        fetchJson(url, { signal: pollAbort.signal }, 'Feed request')
            .then(data => {
                if (!data || !data.ok) {
                    const message = (data && data.error) ? data.error : 'Upstream feed unavailable.';
                    updateFeedStatus('DEGRADED', message);
                    reportError('Feed degraded', message);
                    pollBackoffIndex = Math.min(pollBackoffIndex + 1, pollBackoffSteps.length - 1);
                    return;
                }
                lastFeedUpdate = Date.now();
                pollBackoffIndex = 0;
                const hasUpstream = data.upstream_http !== null && data.upstream_http !== undefined;
                const upstreamBad = hasUpstream && Number(data.upstream_http) !== 200;
                const feedOk = data.error === null
                    && data.cache_stale === false
                    && !upstreamBad;
                const degraded = !feedOk;
                const feedStatus = degraded
                    ? (data.cache_stale ? 'DEGRADED (CACHE)' : 'DEGRADED')
                    : 'OK';
                const warningMessage = !feedOk
                    ? (data.error || (upstreamBad ? `Upstream HTTP ${data.upstream_http}` : 'Feed degraded.'))
                    : '';
                updateFeedStatus(feedStatus, warningMessage);
                const seenHexes = new Set();
                (data.ac || []).forEach(ac => {
                    if (!ac || !isValidLat(ac.lat) || !isValidLon(ac.lon)) {
                        return;
                    }
                    if (!ac.hex) {
                        return;
                    }
                    seenHexes.add(ac.hex);
                    const previous = flights[ac.hex] || {};
                    const note = previous.note || noteStore[ac.hex] || stripNotes[ac.hex] || ac.note || '';
                    flights[ac.hex] = { ...previous, ...ac, note };
                    renderFlight(flights[ac.hex]);
                    stripDataCache[ac.hex] = flights[ac.hex];
                });
                Object.keys(flightMarkers).forEach(hex => {
                    if (!seenHexes.has(hex)) {
                        removeFlight(hex);
                    }
                });
                pruneFlights();
                updateStrips();
                updateLabelVisibility();
                syncFlightStates();
            })
            .catch(err => {
                if (err && err.name === 'AbortError') {
                    return;
                }
                console.error('Error fetching feed:', err);
                updateFeedStatus('DEGRADED', 'Feed error – check diagnostics.');
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
    }

    // Settings panel toggling
    const settingsToggle = document.getElementById('settingsToggle');
    const settingsPanel = document.getElementById('settingsPanel');
    const airacUpdateBtn = document.getElementById('airacUpdateBtn');
    const airacSpinner = document.getElementById('airacSpinner');
    const airacConsole = document.getElementById('airacConsole');
    const sidebarToggle = document.getElementById('sidebarToggle');
    diagnosticsClose.addEventListener('click', () => {
        diagnosticsDismissed = true;
        errorOverlay.style.display = 'none';
    });
    feedErrorDetails.addEventListener('click', () => {
        diagnosticsDismissed = false;
        renderDiagnostics();
        errorOverlay.style.display = 'block';
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
    settingsToggle.addEventListener('click', () => {
        settingsPanel.style.display = settingsPanel.style.display === 'none' ? 'block' : 'none';
        settingsToggle.textContent = settingsPanel.style.display === 'none' ? 'Open Settings' : 'Close Settings';
        // populate inputs with current settings
        document.getElementById('airportInput').value = settings.airport.icao;
        document.getElementById('feedCenterLatInput').value = settings.feed_center.lat;
        document.getElementById('feedCenterLonInput').value = settings.feed_center.lon;
        document.getElementById('displayCenterLatInput').value = settings.display_center.lat;
        document.getElementById('displayCenterLonInput').value = settings.display_center.lon;
        document.getElementById('radiusInput').value = settings.radius_nm;
        document.getElementById('pollIntervalInput').value = settings.poll_interval_ms;
        document.getElementById('ringDistances').value = settings.rings.distances.join(',');
        document.getElementById('ringColour').value = settings.rings.style.color;
        document.getElementById('ringWeight').value = settings.rings.style.weight;
        document.getElementById('ringDash').value = settings.rings.style.dash;
        document.getElementById('labelFontSize').value = settings.labels.font_size;
        document.getElementById('labelColour').value = settings.labels.color;
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
        airacUpdateBtn.style.display = (airacUpdateEnabled && vatmexDirConfigured) ? 'inline-block' : 'none';
    });
    // Apply settings on button click
    document.getElementById('applySettings').addEventListener('click', () => {
        settings.airport.icao = document.getElementById('airportInput').value.trim().toUpperCase();
        settings.feed_center.lat = parseFloat(document.getElementById('feedCenterLatInput').value);
        settings.feed_center.lon = parseFloat(document.getElementById('feedCenterLonInput').value);
        settings.display_center.lat = parseFloat(document.getElementById('displayCenterLatInput').value);
        settings.display_center.lon = parseFloat(document.getElementById('displayCenterLonInput').value);
        const radiusVal = parseFloat(document.getElementById('radiusInput').value);
        settings.radius_nm = isNaN(radiusVal) ? settings.radius_nm : Math.min(250, Math.max(1, radiusVal));
        const pollVal = parseInt(document.getElementById('pollIntervalInput').value, 10);
        settings.poll_interval_ms = isNaN(pollVal) ? settings.poll_interval_ms : Math.min(5000, Math.max(500, pollVal));
        const rd = document.getElementById('ringDistances').value.split(',').map(x => parseFloat(x));
        settings.rings.distances = rd.filter(x => !isNaN(x) && x > 0);
        settings.rings.style.color = document.getElementById('ringColour').value;
        settings.rings.style.weight = parseFloat(document.getElementById('ringWeight').value) || settings.rings.style.weight;
        settings.rings.style.dash = document.getElementById('ringDash').value;
        settings.labels.font_size = parseInt(document.getElementById('labelFontSize').value, 10) || settings.labels.font_size;
        settings.labels.color = document.getElementById('labelColour').value;
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
        saveSettings();
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
                    settings = data.settings;
                    airacUpdateEnabled = !!data.airac_update_enabled;
                    vatmexDirConfigured = !!data.vatmex_dir_configured;
                    applySettings();
                    startPolling();
                    settingsPanel.style.display = 'none';
                    settingsToggle.textContent = 'Open Settings';
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
                    settings = data.settings;
                }
                ensureCenters();
                airacUpdateEnabled = !!data.airac_update_enabled;
                vatmexDirConfigured = !!data.vatmex_dir_configured;
                applySettings();
                loadStates();
                loadStrips();
                startPolling();
            })
            .catch(() => {
                ensureCenters();
                applySettings();
                vatmexDirConfigured = false;
                loadStates();
                loadStrips();
                startPolling();
            });
    }

    airacUpdateBtn.addEventListener('click', () => {
        if (!airacUpdateEnabled || !vatmexDirConfigured) {
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
                    '',
                    'STDOUT:',
                    data.stdout || '(empty)',
                    '',
                    'STDERR:',
                    data.stderr || '(empty)',
                ].join('\n');
                airacConsole.textContent = output;
            })
            .catch(() => {
                airacConsole.textContent = 'AIRAC update failed to start.';
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
