<?php
declare(strict_types=1);

function isValidLatValue($value): bool
{
    if (!is_numeric($value)) {
        return false;
    }
    $value = (float)$value;
    return is_finite($value) && $value >= -90.0 && $value <= 90.0;
}

function isValidLonValue($value): bool
{
    if (!is_numeric($value)) {
        return false;
    }
    $value = (float)$value;
    return is_finite($value) && $value >= -180.0 && $value <= 180.0;
}

function isMexicoCoordValue(float $lat, float $lon): bool
{
    return $lat >= 10.0 && $lat <= 40.0 && $lon >= -120.0 && $lon <= -80.0;
}

function normalizeLonLatPair(array $coord): array
{
    if (count($coord) < 2) {
        return $coord;
    }
    $lon = $coord[0];
    $lat = $coord[1];
    if (!is_numeric($lon) || !is_numeric($lat)) {
        return $coord;
    }
    $lon = (float)$lon;
    $lat = (float)$lat;

    if (!isValidLonValue($lon) && isValidLonValue($lat) && isValidLatValue($lon)) {
        return [$lat, $lon];
    }
    if (!isValidLatValue($lat) && isValidLatValue($lon) && isValidLonValue($lat)) {
        return [$lat, $lon];
    }
    if (isMexicoCoordValue($lat, $lon)) {
        return [$lon, $lat];
    }
    if (isMexicoCoordValue($lon, $lat)) {
        return [$lat, $lon];
    }
    if (isValidLatValue($lon) && isValidLonValue($lat) && !isValidLonValue($lon)) {
        return [$lat, $lon];
    }
    return [$lon, $lat];
}

function normalizeCoordsDeep($coords)
{
    if (!is_array($coords)) {
        return $coords;
    }
    if ($coords && is_numeric($coords[0] ?? null) && is_numeric($coords[1] ?? null)) {
        return normalizeLonLatPair($coords);
    }
    $normalized = [];
    foreach ($coords as $item) {
        $normalized[] = normalizeCoordsDeep($item);
    }
    return $normalized;
}

function normalizeGeojson(array $data, array $options = []): array
{
    $forcePolygon = !empty($options['forcePolygon']);

    $closeRing = static function (array $ring): array {
        if (count($ring) < 3) {
            return $ring;
        }
        $first = $ring[0];
        $last = $ring[count($ring) - 1];
        if ($first !== $last) {
            $ring[] = $first;
        }
        return $ring;
    };

    $normalizeGeometry = static function (array $geometry) use ($forcePolygon, $closeRing): array {
        $coords = normalizeCoordsDeep($geometry['coordinates'] ?? null);
        $type = $geometry['type'] ?? '';
        if ($type === 'LineString' && $forcePolygon) {
            if (is_array($coords) && count($coords) >= 3) {
                return ['type' => 'Polygon', 'coordinates' => [$closeRing($coords)]];
            }
        }
        if ($type === 'Polygon') {
            $rings = [];
            foreach ($coords as $ring) {
                $rings[] = $closeRing($ring);
            }
            return ['type' => 'Polygon', 'coordinates' => $rings];
        }
        if ($type === 'MultiPolygon') {
            $polys = [];
            foreach ($coords as $poly) {
                $rings = [];
                foreach ($poly as $ring) {
                    $rings[] = $closeRing($ring);
                }
                $polys[] = $rings;
            }
            return ['type' => 'MultiPolygon', 'coordinates' => $polys];
        }
        return ['type' => $type, 'coordinates' => $coords];
    };

    if (($data['type'] ?? '') === 'FeatureCollection') {
        $features = [];
        foreach ($data['features'] ?? [] as $feature) {
            if (!is_array($feature)) {
                continue;
            }
            if (isset($feature['geometry']) && is_array($feature['geometry'])) {
                $feature['geometry'] = $normalizeGeometry($feature['geometry']);
            }
            $features[] = $feature;
        }
        $data['features'] = $features;
        return $data;
    }

    if (($data['type'] ?? '') === 'Feature' && isset($data['geometry']) && is_array($data['geometry'])) {
        $data['geometry'] = $normalizeGeometry($data['geometry']);
        return $data;
    }

    if (isset($data['coordinates'])) {
        $data['coordinates'] = normalizeCoordsDeep($data['coordinates']);
    }

    return $data;
}
