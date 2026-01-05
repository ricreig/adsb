<?php
// Configuration settings for the ATC display application.
// Adjust these values to suit your environment.  All files and endpoints
// referenced here are relative to the root of the project directory.

return [
    // Primary airport reference (UI only).
    'airport' => [
        'icao' => 'MMZT',
        // Latitude and longitude of the reference point (decimal degrees).
        'lat' => 29.8839810,
        'lon' => -114.0747826,
    ],
    // Fixed ADS-B feed center for ACC MMZT coverage (do not change).
    'feed_center' => [
        'lat' => 29.8839810,
        'lon' => -114.0747826,
    ],
    // Fixed ADS-B feed radius (nautical miles).
    'feed_radius_nm' => 250,
    // UI center (range rings + BRL/distance calculations) defaults to MMTJ.
    'ui_center' => [
        'lat' => 32.541,
        'lon' => -116.97,
    ],
    // Legacy display center (deprecated: use ui_center).
    'display_center' => [
        'lat' => 32.541,
        'lon' => -116.97,
    ],

    // Base map tile URLs (no-labels). The primary is preferred; the fallback
    // is used automatically if the primary provider fails.
    // Dark map is the default for radar-style contrast.
    'basemap' => 'https://{s}.basemaps.cartocdn.com/dark_nolabels/{z}/{x}/{y}{r}.png',
    'basemap_fallback' => 'https://{s}.basemaps.cartocdn.com/light_nolabels/{z}/{x}/{y}{r}.png',
    'basemap_attribution' => '&copy; OpenStreetMap contributors, &copy; CARTO',
    'basemap_fallback_attribution' => '&copy; OpenStreetMap contributors, &copy; CARTO',
    // Optional explicit light/dark providers for UI switching (fallback to basemap if omitted).
    'basemap_dark' => 'https://{s}.basemaps.cartocdn.com/dark_nolabels/{z}/{x}/{y}{r}.png',
    'basemap_dark_fallback' => 'https://{s}.basemaps.cartocdn.com/light_nolabels/{z}/{x}/{y}{r}.png',
    'basemap_dark_attribution' => '&copy; OpenStreetMap contributors, &copy; CARTO',
    'basemap_light' => 'https://{s}.basemaps.cartocdn.com/light_nolabels/{z}/{x}/{y}{r}.png',
    'basemap_light_fallback' => 'https://tiles.stadiamaps.com/tiles/alidade_smooth_nolabels/{z}/{x}/{y}{r}.png',
    'basemap_light_attribution' => '&copy; OpenStreetMap contributors, &copy; CARTO',
    'basemap_light_fallback_attribution' => '&copy; OpenStreetMap contributors, &copy; Stadia Maps, &copy; OpenMapTiles',

    // Airplanes.live API endpoint.  This endpoint returns aircraft tracks
    // within a specified radius of a point.  Replace with your own provider
    // or mock endpoint as required.
    'adsb_feed_url' => 'https://api.airplanes.live/v2/point',
    // Optional API key for the ADS-B feed provider (sent as a header).
    'adsb_api_key' => null,
    'adsb_api_header' => 'X-API-Key',

    // Legacy ADS-B radius (deprecated: use feed_radius_nm).
    'adsb_radius' => 250,
    // Feed cache TTL (milliseconds) and upstream rate limit (seconds).
    'feed_cache_ttl_ms' => 1500,
    // Maximum staleness allowed when serving cached data during rate limiting (milliseconds).
    'feed_cache_max_stale_ms' => 5000,
    'feed_rate_limit_s' => 1.0,
    // Cache directory for feed responses and upstream rate limiting.
    'feed_cache_dir' => __DIR__ . '/data/cache',
    // Purge aircraft entries that have not updated seen_pos within this threshold (seconds).
    'cache_cleanup_threshold' => 300,
    // Normalization settings for deduplication comparisons.
    'coordinate_round_decimals' => 3,
    'altitude_change_threshold_ft' => 100,

    // Hard coded latitude of the US–Mexico border near Tijuana.  Aircraft
    // north of this latitude plus the `north_buffer_nm` (converted to
    // degrees) are hidden from the display. Set to 0 to disable this
    // filtering.
    'border_lat' => 0.0,

    // Toggle filtering based on the Mexico border GeoJSON. Keep disabled to
    // show all traffic.
    'mex_border_filter_enabled' => false,
    // Buffer outside Mexico (nautical miles) to keep when using
    // data/mex-border.geojson.
    'mex_border_buffer_nm' => 10,

    // Buffer north of the border in nautical miles.  Aircraft further north
    // than border_lat + (north_buffer_nm / 60) degrees will be filtered.
    'north_buffer_nm' => 0,

    // Directory where geo‑spatial layers reside.  Each GeoJSON file in this
    // directory will be exposed through the index page.  See the
    // update_airspace.php script for details on generating these files from
    // the vatmex dataset.
    'geojson_dir' => __DIR__ . '/data',

    // Settings persistence (SQLite).
    'settings_db' => __DIR__ . '/data/adsb.sqlite',
    // Polling interval for the frontend (milliseconds).
    'poll_interval_ms' => 1500,

    // VATMEX AIRAC update settings. The directory is configured on the server.
    'vatmex_dir' => null,
    'airac_update_enabled' => false,

    // Optional API key for flight plan lookups.  Some services require a
    // token – configure it here and implement the lookup in get_flight_plan().
    'flight_plan_api_key' => null,
    // Optional flight plan API base URL (expects ?callsign= in the query).
    'flight_plan_api_url' => null,
    // Flight plan cache TTL (seconds).
    'flight_plan_cache_ttl' => 900,

    // Optional authentication for the UI and APIs.
    'auth' => [
        'enabled' => false,
        'type' => 'basic', // basic or token
        'user' => 'atc',
        'pass' => 'change-me',
        'token' => null,
    ],
];
