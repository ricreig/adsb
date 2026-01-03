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
        $layerFiles[$id] = 'data/' . $file;
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
        .leaflet-tooltip {
            color: var(--label-color);
            font-size: calc(var(--label-size) * 1px);
            font-weight: bold;
            white-space: nowrap;
            text-transform: uppercase;
        }
        #map {
            position: absolute;
            top: 0;
            left: 0;
            right: 320px;
            height: 100vh;
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
        .strip {
            background: #243752;
            border: 1px solid #3f5270;
            padding: 6px;
            margin-bottom: 6px;
            cursor: pointer;
        }
        .strip.assumed {
            border-color: #007acc;
            background: #28446d;
        }
        .strip.released {
            opacity: 0.5;
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
        #errorOverlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: #b03a2e;
            color: #fff;
            padding: 6px 10px;
            font-size: 12px;
            z-index: 2000;
            display: none;
            max-height: 160px;
            overflow-y: auto;
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
    <div id="tileStatus">Basemap fallback activated.</div>
    <div id="feedError" style="display:none;position:absolute;top:10px;right:340px;background:#b03a2e;color:#fff;padding:6px 10px;border-radius:4px;z-index:1000;max-width:360px;"></div>
    <div id="errorOverlay"></div>
    <div id="sidebar">
        <h2>Airspace Layers</h2>
        <div id="layerControls"></div>
        <h2>Flight Strips</h2>
        <div id="stripTray"></div>
        <h2>Selected Flight</h2>
        <div id="flightInfo">Click a flight to see details.</div>

        <h2>Settings</h2>
        <button id="settingsToggle" style="width:100%;margin-bottom:10px;">Open Settings</button>
        <div id="settingsPanel" style="display:none;padding:6px;border:1px solid #3f5270;background:#243752;font-size:13px;">
            <h3 style="margin-top:0;font-size:14px;text-align:center;">Display Settings</h3>
            <label style="display:block;margin-bottom:4px;">Primary Airport ICAO
                <input type="text" id="airportInput" value="<?php echo htmlspecialchars($config['airport']['icao']); ?>" style="width:80px;margin-left:4px;"/>
            </label>
            <label style="display:block;margin-bottom:4px;">Primary Airport Lat
                <input type="number" id="airportLatInput" step="0.0001" value="<?php echo htmlspecialchars($config['airport']['lat']); ?>" style="width:90px;margin-left:4px;"/>
            </label>
            <label style="display:block;margin-bottom:4px;">Primary Airport Lon
                <input type="number" id="airportLonInput" step="0.0001" value="<?php echo htmlspecialchars($config['airport']['lon']); ?>" style="width:90px;margin-left:4px;"/>
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
    const errorLog = [];
    const diagnostics = {
        healthStatus: 'pending',
        healthDetail: null,
        leafletSource: 'unknown',
        leafletUrl: null,
    };

    function renderDiagnostics() {
        const lines = [
            'Diagnostics',
            `Health: ${diagnostics.healthStatus}`,
            diagnostics.healthDetail ? `Health detail: ${diagnostics.healthDetail}` : null,
            `Leaflet: ${diagnostics.leafletSource}`,
            diagnostics.leafletUrl ? `Leaflet URL: ${diagnostics.leafletUrl}` : null,
        ].filter(Boolean);
        if (errorLog.length) {
            lines.push('', 'Errors:', ...errorLog);
        }
        errorOverlay.textContent = lines.join('\n');
        errorOverlay.style.display = 'block';
    }

    function reportError(message, detail) {
        const line = detail ? `${message}\n${detail}` : message;
        errorLog.push(line);
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
        if (err.message) {
            detailLines.push(`Error: ${err.message}`);
        }
        if (err.body) {
            detailLines.push(`Response: ${err.body.slice(0, 200)}`);
        }
        return detailLines.join('\n');
    }

    function fetchJson(url, options, context) {
        return fetch(url, options).then(async resp => {
            if (!resp.ok) {
                const body = await resp.text().catch(() => '');
                const error = new Error(`${context || 'Request failed'} (${resp.status}) ${resp.statusText}`);
                error.status = resp.status;
                error.statusText = resp.statusText;
                error.url = url;
                error.body = body;
                throw error;
            }
            return resp.json();
        }).catch(err => {
            reportError(context || 'Fetch error', formatFetchErrorDetail(err, url));
            throw err;
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
                if (errorOverlay.style.display === 'block') {
                    renderDiagnostics();
                }
            })
            .catch(() => {});
    }

    function initLeafletApp() {
    // Create the map
    const map = L.map('map', {
        zoomControl: true,
        attributionControl: true,
    }).setView([<?php echo $config['airport']['lat']; ?>, <?php echo $config['airport']['lon']; ?>], 8);
    const tileStatus = document.getElementById('tileStatus');
    const primaryTiles = L.tileLayer('<?php echo $config['basemap']; ?>', {
        maxZoom: 18,
        attribution: '<?php echo addslashes($config['basemap_attribution']); ?>',
    });
    const fallbackTiles = L.tileLayer('<?php echo $config['basemap_fallback']; ?>', {
        maxZoom: 18,
        attribution: '<?php echo addslashes($config['basemap_fallback_attribution']); ?>',
    });
    let tileErrors = 0;
    primaryTiles.on('tileerror', () => {
        tileErrors += 1;
        if (tileErrors >= 5 && !map.hasLayer(fallbackTiles)) {
            map.removeLayer(primaryTiles);
            fallbackTiles.addTo(map);
            tileStatus.style.display = 'block';
        }
    });
    primaryTiles.addTo(map);

    // Container for GeoJSON overlay layers
    const overlays = {};

    // Colours per layer (defaults).  Operators can change via colour inputs.
    const layerColours = {};

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
    function loadLayer(id) {
        if (overlays[id]) {
            map.addLayer(overlays[id]);
            return;
        }
        const url = buildUrl(geojsonLayers[id]);
        fetchJson(url, {}, `GeoJSON layer ${id} (${url})`)
            .then(data => {
                const layer = L.geoJSON(data, {
                    style: feature => {
                        return {
                            color: layerColours[id],
                            weight: 1.5,
                            fill: false
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

    // Remove a GeoJSON layer when unchecked
    function removeLayer(id) {
        if (overlays[id]) {
            map.removeLayer(overlays[id]);
        }
    }

    // Flight data and interactions
    const flights = {}; // keyed by hex
    const flightMarkers = {};
    const stripTray = document.getElementById('stripTray');
    const flightInfoDiv = document.getElementById('flightInfo');
    const notif = document.getElementById('notif');
    const feedError = document.getElementById('feedError');

    const defaultSettings = {
        airport: {
            icao: '<?php echo addslashes($config['airport']['icao']); ?>',
            lat: <?php echo (float)$config['airport']['lat']; ?>,
            lon: <?php echo (float)$config['airport']['lon']; ?>,
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
            font_size: 12,
            color: '#00ff00',
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

    // Range ring overlay container
    let rangeRings = [];

    // Apply settings to map and UI
    function applySettings() {
        document.documentElement.style.setProperty('--label-size', settings.labels.font_size);
        document.documentElement.style.setProperty('--label-color', settings.labels.color);
        map.setView([settings.airport.lat, settings.airport.lon], map.getZoom());
        updateRangeRings();
    }

    // Create or refresh range rings around the primary airport
    function updateRangeRings() {
        // Remove existing rings
        rangeRings.forEach(r => map.removeLayer(r));
        rangeRings = [];
        const dashArray = settings.rings.style.dash || '';
        settings.rings.distances.forEach(dist => {
            const circle = L.circle([settings.airport.lat, settings.airport.lon], {
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

    // Add or update a flight marker on the map
    function renderFlight(ac) {
        const id = ac.hex;
        const existing = flightMarkers[id];
        const pos = [ac.lat, ac.lon];
        const vectorLengthNm = 2; // minutes ahead – 2 minutes for short leader
        const predictedDistance = (ac.gs || 0) * (vectorLengthNm / 60);
        const predicted = destinationPoint(ac.lat, ac.lon, ac.track, predictedDistance);
        if (existing) {
            existing.marker.setLatLng(pos);
            existing.vector.setLatLngs([pos, predicted]);
            existing.marker.setTooltipContent(labelFromAc(ac));
        } else {
            const marker = L.circleMarker(pos, {
                radius: 4,
                color: 'lime',
                fillColor: 'lime',
                fillOpacity: 1,
            }).addTo(map);
            const vector = L.polyline([pos, predicted], {
                color: 'lime',
                weight: 1.0,
                opacity: 0.7,
                dashArray: '4 4'
            }).addTo(map);
            marker.bindTooltip(labelFromAc(ac), {
                offset: [10, 0],
                direction: 'right',
                permanent: false
            });
            marker.on('click', () => selectFlight(id));
            flightMarkers[id] = { marker, vector };
        }
    }

    // Generate a label string for a track
    function labelFromAc(ac) {
        const parts = [];
        // Always callsign/hex in uppercase
        const callsign = ac.flight ? ac.flight.trim().toUpperCase() : ac.hex;
        parts.push(callsign);
        if (settings.labels.show_alt && ac.alt !== null && ac.alt !== undefined) {
            parts.push(ac.alt + 'FT');
        }
        if (settings.labels.show_gs && ac.gs !== null && ac.gs !== undefined) {
            parts.push(ac.gs + 'KT');
        }
        if (settings.labels.show_vs) {
            const vs = ac.baro_rate || ac.geom_rate || 0;
            if (vs) {
                const arrow = vs > 0 ? '↑' : '↓';
                parts.push(Math.abs(vs) + ' ' + arrow);
            }
        }
        if (settings.labels.show_trk && ac.track !== null && ac.track !== undefined) {
            parts.push(ac.track + '°');
        }
        if (settings.labels.show_sqk && ac.squawk) {
            parts.push(ac.squawk);
        }
        // Append free text note if any
        if (ac.note) {
            parts.push(ac.note.toUpperCase());
        }
        return parts.join(' \u00a0 ');
    }

    // Remove stale flights (not updated in last poll)
    function pruneFlights(seenIds) {
        Object.keys(flightMarkers).forEach(id => {
            if (!seenIds.has(id)) {
                map.removeLayer(flightMarkers[id].marker);
                map.removeLayer(flightMarkers[id].vector);
                delete flightMarkers[id];
                delete flights[id];
                // Remove from strip tray if not assumed
                const stripEl = document.querySelector('.strip[data-hex="' + id + '"]');
                if (stripEl && !stripEl.classList.contains('assumed')) {
                    stripEl.remove();
                }
            }
        });
    }

    // Update flight strips in tray
    function updateStrips() {
        // Remove strips that no longer exist (non‑assumed)
        document.querySelectorAll('#stripTray .strip').forEach(strip => {
            const id = strip.dataset.hex;
            if (!flights[id] && !strip.classList.contains('assumed')) {
                strip.remove();
            }
        });
        // Add or update strips
        Object.values(flights).forEach(ac => {
            let strip = document.querySelector('.strip[data-hex="' + ac.hex + '"]');
            if (!strip) {
                strip = document.createElement('div');
                strip.className = 'strip';
                strip.dataset.hex = ac.hex;
                strip.addEventListener('click', () => {
                    selectFlight(ac.hex);
                });
                stripTray.appendChild(strip);
            }
            // Label for strip
            strip.textContent = ac.flight ? ac.flight.trim() : ac.hex;
            // Highlight emergency squawk
            if (['7500','7600','7700'].includes(ac.squawk)) {
                strip.style.background = '#8a0e0e';
            } else if (strip.classList.contains('assumed')) {
                strip.style.background = '#28446d';
            } else {
                strip.style.background = '#243752';
            }
        });
    }

    // Select a flight for detailed view and route display
    function selectFlight(hex) {
        const ac = flights[hex];
        if (!ac) return;
        // Highlight marker
        Object.keys(flightMarkers).forEach(id => {
            const m = flightMarkers[id].marker;
            m.setStyle({ color: id === hex ? 'cyan' : 'lime', fillColor: id === hex ? 'cyan' : 'lime' });
        });
        // Show details
        // Build details HTML
        let html = '';
        html += '<strong>CALLSIGN:</strong> ' + (ac.flight ? ac.flight.trim().toUpperCase() : ac.hex) + '<br>';
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
        if (ac.emergency && ac.emergency !== 'none') html += '<strong>EMERG:</strong> ' + ac.emergency + '<br>';
        html += '<label>Note / OPMET:<br><textarea id="noteField" rows="2" style="width:95%;">' + (ac.note || '') + '</textarea></label><br>';
        html += '<button id="assumeBtn">Assume</button> ' +
                '<button id="releaseBtn">Release</button> ' +
                '<button id="routeBtn">Route</button> ' +
                '<button id="saveNoteBtn">Save Note</button>';
        flightInfoDiv.innerHTML = html;
        // Bind buttons
        document.getElementById('assumeBtn').addEventListener('click', () => {
            assumeFlight(hex);
        });
        document.getElementById('releaseBtn').addEventListener('click', () => {
            releaseFlight(hex);
        });
        document.getElementById('routeBtn').addEventListener('click', () => {
            drawRoute(hex);
        });
        document.getElementById('saveNoteBtn').addEventListener('click', () => {
            const text = document.getElementById('noteField').value.trim();
            flights[hex].note = text;
            // Immediately update tooltip with note
            const existing = flightMarkers[hex];
            if (existing) {
                existing.marker.setTooltipContent(labelFromAc(flights[hex]));
            }
        });
    }

    // Assume a flight (mark as assumed)
    function assumeFlight(hex) {
        const strip = document.querySelector('.strip[data-hex="' + hex + '"]');
        if (strip) {
            strip.classList.add('assumed');
            strip.classList.remove('released');
        }
    }

    // Release a flight (mark as released)
    function releaseFlight(hex) {
        const strip = document.querySelector('.strip[data-hex="' + hex + '"]');
        if (strip) {
            strip.classList.remove('assumed');
            strip.classList.add('released');
        }
    }

    // Placeholder for route drawing.  In a production system this would
    // query a flight plan API to retrieve the route (fixes and navaids)
    // associated with the aircraft.  This function draws a simple path
    // between the selected track and the destination of its flight plan if
    // available.  It uses a GeoJSON line stored on the server as a
    // placeholder.
    let routeLayer = null;
    function drawRoute(hex) {
        // Remove previous route
        if (routeLayer) {
            map.removeLayer(routeLayer);
            routeLayer = null;
        }
        const ac = flights[hex];
        if (!ac) return;
        // Example: load a GeoJSON representing the flight plan by callsign.
        // The file should be located under data/routes/{callsign}.geojson.
        const callsign = ac.flight.trim();
        const url = buildUrl('data/routes/' + callsign + '.geojson');
        fetch(url)
            .then(resp => resp.json())
            .then(gjson => {
                routeLayer = L.geoJSON(gjson, {
                    style: { color: 'yellow', weight: 2.0 }
                }).addTo(map);
                map.fitBounds(routeLayer.getBounds(), { maxZoom: 8 });
            })
            .catch(() => {
                showNotification('Route not available for ' + callsign);
            });
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
            feedError.textContent = message;
            feedError.style.display = 'block';
        } else {
            feedError.style.display = 'none';
        }
    }

    // Poll feed.php for live data
    function pollFeed() {
        if (document.hidden) {
            return;
        }
        const radius = Math.min(250, settings.radius_nm || 250);
        const url = buildUrl('feed.php') + '?lat=' + encodeURIComponent(settings.airport.lat) + '&lon=' + encodeURIComponent(settings.airport.lon) + '&radius_nm=' + encodeURIComponent(radius);
        fetchJson(url, {}, 'Feed request')
            .then(data => {
                if (!data.ok) {
                    showFeedError(data.error || 'Upstream feed unavailable.');
                } else {
                    showFeedError('');
                }
                const seen = new Set();
                (data.ac || []).forEach(ac => {
                    flights[ac.hex] = ac;
                    seen.add(ac.hex);
                    renderFlight(ac);
                });
                pruneFlights(seen);
                updateStrips();
            })
            .catch(err => {
                console.error('Error fetching feed:', err);
                showFeedError('Feed error – check console.');
            });
    }

    let pollTimer = null;
    function startPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        pollFeed();
        const interval = Math.max(500, settings.poll_interval_ms || 1500);
        pollTimer = setInterval(pollFeed, interval);
    }

    // Settings panel toggling
    const settingsToggle = document.getElementById('settingsToggle');
    const settingsPanel = document.getElementById('settingsPanel');
    const airacUpdateBtn = document.getElementById('airacUpdateBtn');
    const airacSpinner = document.getElementById('airacSpinner');
    const airacConsole = document.getElementById('airacConsole');
    settingsToggle.addEventListener('click', () => {
        settingsPanel.style.display = settingsPanel.style.display === 'none' ? 'block' : 'none';
        settingsToggle.textContent = settingsPanel.style.display === 'none' ? 'Open Settings' : 'Close Settings';
        // populate inputs with current settings
        document.getElementById('airportInput').value = settings.airport.icao;
        document.getElementById('airportLatInput').value = settings.airport.lat;
        document.getElementById('airportLonInput').value = settings.airport.lon;
        document.getElementById('radiusInput').value = settings.radius_nm;
        document.getElementById('pollIntervalInput').value = settings.poll_interval_ms;
        document.getElementById('ringDistances').value = settings.rings.distances.join(',');
        document.getElementById('ringColour').value = settings.rings.style.color;
        document.getElementById('ringWeight').value = settings.rings.style.weight;
        document.getElementById('ringDash').value = settings.rings.style.dash;
        document.getElementById('labelFontSize').value = settings.labels.font_size;
        document.getElementById('labelColour').value = settings.labels.color;
        document.getElementById('showAltitude').checked = settings.labels.show_alt;
        document.getElementById('showSpeed').checked = settings.labels.show_gs;
        document.getElementById('showVerticalSpeed').checked = settings.labels.show_vs;
        document.getElementById('showTrack').checked = settings.labels.show_trk;
        document.getElementById('showSquawk').checked = settings.labels.show_sqk;
        airacUpdateBtn.style.display = airacUpdateEnabled ? 'inline-block' : 'none';
    });
    // Apply settings on button click
    document.getElementById('applySettings').addEventListener('click', () => {
        settings.airport.icao = document.getElementById('airportInput').value.trim().toUpperCase();
        settings.airport.lat = parseFloat(document.getElementById('airportLatInput').value);
        settings.airport.lon = parseFloat(document.getElementById('airportLonInput').value);
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
        settings.labels.show_alt = document.getElementById('showAltitude').checked;
        settings.labels.show_gs = document.getElementById('showSpeed').checked;
        settings.labels.show_vs = document.getElementById('showVerticalSpeed').checked;
        settings.labels.show_trk = document.getElementById('showTrack').checked;
        settings.labels.show_sqk = document.getElementById('showSquawk').checked;
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
                airacUpdateEnabled = !!data.airac_update_enabled;
                applySettings();
                startPolling();
            })
            .catch(() => {
                applySettings();
                startPolling();
            });
    }

    airacUpdateBtn.addEventListener('click', () => {
        if (!airacUpdateEnabled) {
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
