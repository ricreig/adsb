<?php
// Configuration settings for the ATC display application.
// Adjust these values to suit your environment.  All files and endpoints
// referenced here are relative to the root of the project directory.

return [
    // Geographic coordinates for the reference point of MMTJ (Tijuana).
    // These are used as the default centre for the display and feed queries.
    'airport' => [
        'icao' => 'MMTJ',
        // Latitude and longitude of the airport reference point (ARP) in decimal degrees.
        'lat' => 32.54100,
        'lon' => -116.97000,
    ],

    // Base map tile URL.  The default is a neutral background without labels
    // (the CartoDB "Voyager" tileset).  You can change this to any tile
    // provider that supports standard {z}/{x}/{y} URL patterns.
    'basemap' => 'https://basemaps.cartocdn.com/rastertiles/voyager_nolabels/{z}/{x}/{y}{r}.png',

    // Airplanes.live API endpoint.  This endpoint returns aircraft tracks
    // within a specified radius of a point.  Replace with your own provider
    // or mock endpoint as required.
    'adsb_feed_url' => 'https://api.airplanes.live/v2/point',

    // Radius of interest around the reference point in nautical miles.  The
    // Airplanes.live API accepts radius up to 250nm.  Use 250nm to provide
    // context but filter in feed.php to only show relevant aircraft.
    'adsb_radius' => 250,

    // Hard coded latitude of the US–Mexico border near Tijuana.  Aircraft
    // north of this latitude plus the `north_buffer_nm` (converted to
    // degrees) are hidden from the display.  This prevents clutter from
    // traffic well into US airspace while still showing coastal traffic.
    'border_lat' => 32.542,

    // Buffer north of the border in nautical miles.  Aircraft further north
    // than border_lat + (north_buffer_nm / 60) degrees will be filtered.
    'north_buffer_nm' => 10,

    // Directory where geo‑spatial layers reside.  Each GeoJSON file in this
    // directory will be exposed through the index page.  See the
    // update_airspace.php script for details on generating these files from
    // the vatmex dataset.
    'geojson_dir' => __DIR__ . '/data',

    // Optional API key for flight plan lookups.  Some services require a
    // token – configure it here and implement the lookup in get_flight_plan().
    'flight_plan_api_key' => null,
];