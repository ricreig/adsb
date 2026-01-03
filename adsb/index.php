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
    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-D4o8gbzKmClWazdh7szkT1gG5b6yoZn6g8hmrmMcvm8=" crossorigin="" />
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
            bottom: 0;
            left: 0;
            right: 300px;
        }
        #sidebar {
            position: absolute;
            top: 0;
            right: 0;
            width: 300px;
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
    </style>
</head>
<body>
    <div id="map"></div>
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
            <label style="display:block;margin-bottom:4px;">Range Rings (NM, comma‑sep)
                <input type="text" id="ringDistances" value="50,100,150,200,250" style="width:120px;margin-left:4px;"/>
            </label>
            <label style="display:block;margin-bottom:4px;">Ring Colour
                <input type="color" id="ringColour" value="#6666ff" style="margin-left:4px;"/>
            </label>
            <label style="display:block;margin-bottom:4px;">Ring Style
                <select id="ringStyle" style="margin-left:4px;">
                    <option value="solid">Solid</option>
                    <option value="dashed">Dashed</option>
                    <option value="dotted">Dotted</option>
                </select>
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
            <button id="applySettings" style="width:100%;margin-top:6px;">Apply Settings</button>
        </div>
    </div>
    <div id="notif"></div>
    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-o1w/X8WdUNwH5tXbSb5Cqx630DGm9LdlVNV4cCZmZyQ=" crossorigin=""></script>
    <script>
    // PHP passes the list of available GeoJSON layers as JSON here.
    const geojsonLayers = <?php echo json_encode($layerFiles, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); ?>;

    // Create the map
    const map = L.map('map', {
        zoomControl: true,
        attributionControl: false,
    }).setView([<?php echo $config['airport']['lat']; ?>, <?php echo $config['airport']['lon']; ?>], 8);
    // Add base map tile layer
    L.tileLayer('<?php echo $config['basemap']; ?>', {
        maxZoom: 18,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);

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
        const url = geojsonLayers[id];
        fetch(url)
            .then(resp => resp.json())
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

    // Global settings object with defaults.  These values can be overridden
    // by user interaction through the Settings panel and persisted in
    // localStorage.  Distances are in nautical miles.
    let settings = {
        airportIcao: '<?php echo addslashes($config['airport']['icao']); ?>',
        airportLat: <?php echo (float)$config['airport']['lat']; ?>,
        airportLon: <?php echo (float)$config['airport']['lon']; ?>,
        ringDistances: [50, 100, 150, 200, 250],
        ringColour: '#6666ff',
        ringStyle: 'solid',
        labelSize: 12,
        labelColour: '#00ff00',
        showAltitude: true,
        showSpeed: true,
        showVerticalSpeed: true,
        showTrack: true,
        showSquawk: true,
    };

    // Load settings from localStorage if present
    (function loadStoredSettings() {
        try {
            const stored = localStorage.getItem('atcSettings');
            if (stored) {
                const obj = JSON.parse(stored);
                Object.assign(settings, obj);
            }
        } catch (e) {}
    })();

    // Range ring overlay container
    let rangeRings = [];

    // Apply settings to map and UI
    function applySettings() {
        // Update CSS variables for label
        document.documentElement.style.setProperty('--label-size', settings.labelSize);
        document.documentElement.style.setProperty('--label-color', settings.labelColour);
        // Update map view
        map.setView([settings.airportLat, settings.airportLon], map.getZoom());
        // Update rings
        updateRangeRings();
        // Persist to localStorage
        try {
            localStorage.setItem('atcSettings', JSON.stringify(settings));
        } catch (e) {}
    }

    // Create or refresh range rings around the primary airport
    function updateRangeRings() {
        // Remove existing rings
        rangeRings.forEach(r => map.removeLayer(r));
        rangeRings = [];
        const styleMap = {
            solid: '',
            dashed: '6 6',
            dotted: '1 8',
        };
        const dashArray = styleMap[settings.ringStyle] || '';
        settings.ringDistances.forEach(dist => {
            const circle = L.circle([settings.airportLat, settings.airportLon], {
                color: settings.ringColour,
                weight: 1,
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
        if (settings.showAltitude && ac.alt !== null && ac.alt !== undefined) {
            parts.push(ac.alt + 'FT');
        }
        if (settings.showSpeed && ac.gs !== null && ac.gs !== undefined) {
            parts.push(ac.gs + 'KT');
        }
        if (settings.showVerticalSpeed) {
            const vs = ac.baro_rate || ac.geom_rate || 0;
            if (vs) {
                const arrow = vs > 0 ? '↑' : '↓';
                parts.push(Math.abs(vs) + ' ' + arrow);
            }
        }
        if (settings.showTrack && ac.track !== null && ac.track !== undefined) {
            parts.push(ac.track + '°');
        }
        if (settings.showSquawk && ac.squawk) {
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
        const url = 'data/routes/' + callsign + '.geojson';
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

    // Poll feed.php for live data
    function pollFeed() {
        // Determine radius: use the largest range ring if available, else default 250 NM
        const radius = settings.ringDistances.length ? Math.max.apply(null, settings.ringDistances) : 250;
        const url = 'feed.php?lat=' + encodeURIComponent(settings.airportLat) + '&lon=' + encodeURIComponent(settings.airportLon) + '&radius=' + encodeURIComponent(radius);
        fetch(url)
            .then(resp => resp.json())
            .then(data => {
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
                showNotification('Feed error – check console');
            });
    }

    // Initial poll and subsequent interval
    pollFeed();
    setInterval(pollFeed, 5000);

    // Settings panel toggling
    const settingsToggle = document.getElementById('settingsToggle');
    const settingsPanel = document.getElementById('settingsPanel');
    settingsToggle.addEventListener('click', () => {
        settingsPanel.style.display = settingsPanel.style.display === 'none' ? 'block' : 'none';
        settingsToggle.textContent = settingsPanel.style.display === 'none' ? 'Open Settings' : 'Close Settings';
        // populate inputs with current settings
        document.getElementById('airportInput').value = settings.airportIcao;
        document.getElementById('airportLatInput').value = settings.airportLat;
        document.getElementById('airportLonInput').value = settings.airportLon;
        document.getElementById('ringDistances').value = settings.ringDistances.join(',');
        document.getElementById('ringColour').value = settings.ringColour;
        document.getElementById('ringStyle').value = settings.ringStyle;
        document.getElementById('labelFontSize').value = settings.labelSize;
        document.getElementById('labelColour').value = settings.labelColour;
        document.getElementById('showAltitude').checked = settings.showAltitude;
        document.getElementById('showSpeed').checked = settings.showSpeed;
        document.getElementById('showVerticalSpeed').checked = settings.showVerticalSpeed;
        document.getElementById('showTrack').checked = settings.showTrack;
        document.getElementById('showSquawk').checked = settings.showSquawk;
    });
    // Apply settings on button click
    document.getElementById('applySettings').addEventListener('click', () => {
        settings.airportIcao = document.getElementById('airportInput').value.trim().toUpperCase();
        settings.airportLat = parseFloat(document.getElementById('airportLatInput').value);
        settings.airportLon = parseFloat(document.getElementById('airportLonInput').value);
        // parse ring distances
        const rd = document.getElementById('ringDistances').value.split(',').map(x => parseFloat(x));
        settings.ringDistances = rd.filter(x => !isNaN(x) && x > 0);
        settings.ringColour = document.getElementById('ringColour').value;
        settings.ringStyle = document.getElementById('ringStyle').value;
        settings.labelSize = parseInt(document.getElementById('labelFontSize').value, 10) || settings.labelSize;
        settings.labelColour = document.getElementById('labelColour').value;
        settings.showAltitude = document.getElementById('showAltitude').checked;
        settings.showSpeed = document.getElementById('showSpeed').checked;
        settings.showVerticalSpeed = document.getElementById('showVerticalSpeed').checked;
        settings.showTrack = document.getElementById('showTrack').checked;
        settings.showSquawk = document.getElementById('showSquawk').checked;
        applySettings();
        settingsPanel.style.display = 'none';
        settingsToggle.textContent = 'Open Settings';
    });
    </script>
</body>
</html>