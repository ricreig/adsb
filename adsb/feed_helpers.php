<?php

declare(strict_types=1);

function normalizeCoordinate(float $value, int $decimals = 3): float
{
    return round($value, $decimals);
}

function seenPosValue($value): float
{
    return is_numeric($value) ? (float)$value : INF;
}

function hasSignificantGeoChange(array $existing, array $incoming, int $altThreshold = 100, int $coordDecimals = 3): bool
{
    $existingLat = isset($existing['lat']) ? (float)$existing['lat'] : null;
    $existingLon = isset($existing['lon']) ? (float)$existing['lon'] : null;
    $incomingLat = isset($incoming['lat']) ? (float)$incoming['lat'] : null;
    $incomingLon = isset($incoming['lon']) ? (float)$incoming['lon'] : null;

    $latChanged = false;
    $lonChanged = false;
    if ($existingLat !== null && $incomingLat !== null) {
        $latChanged = normalizeCoordinate($existingLat, $coordDecimals) !== normalizeCoordinate($incomingLat, $coordDecimals);
    }
    if ($existingLon !== null && $incomingLon !== null) {
        $lonChanged = normalizeCoordinate($existingLon, $coordDecimals) !== normalizeCoordinate($incomingLon, $coordDecimals);
    }

    $existingAlt = isset($existing['alt']) && is_numeric($existing['alt']) ? (int)$existing['alt'] : null;
    $incomingAlt = isset($incoming['alt']) && is_numeric($incoming['alt']) ? (int)$incoming['alt'] : null;
    $altChanged = false;
    if ($existingAlt !== null && $incomingAlt !== null) {
        $altChanged = abs($incomingAlt - $existingAlt) >= $altThreshold;
    }

    return $latChanged || $lonChanged || $altChanged;
}

function shouldReplaceEntry(array $existing, array $incoming, int $altThreshold = 100, int $coordDecimals = 3): bool
{
    $existingSeen = seenPosValue($existing['seen_pos'] ?? null);
    $incomingSeen = seenPosValue($incoming['seen_pos'] ?? null);
    if ($incomingSeen >= $existingSeen) {
        return false;
    }

    return hasSignificantGeoChange($existing, $incoming, $altThreshold, $coordDecimals);
}

function cleanupStaleEntries(array &$byHex, float $thresholdSeconds): int
{
    if ($thresholdSeconds <= 0) {
        return 0;
    }
    $removed = 0;
    foreach ($byHex as $hex => $entry) {
        $seenPos = $entry['seen_pos'] ?? null;
        if (!is_numeric($seenPos) || (float)$seenPos > $thresholdSeconds) {
            unset($byHex[$hex]);
            $removed++;
        }
    }
    return $removed;
}

function dedupeEntries(array $entries, int $altThreshold = 100, int $coordDecimals = 3): array
{
    $byHex = [];
    foreach ($entries as $entry) {
        $hex = strtoupper(trim((string)($entry['hex'] ?? '')));
        if ($hex === '') {
            continue;
        }
        if (!isset($byHex[$hex])) {
            $byHex[$hex] = $entry;
            continue;
        }
        if (shouldReplaceEntry($byHex[$hex], $entry, $altThreshold, $coordDecimals)) {
            $byHex[$hex] = $entry;
        }
    }

    return array_values($byHex);
}

function logFilterDiscard(callable $logger, array $entry, string $reason): void
{
    $message = sprintf(
        'filter_discard reason=%s hex=%s flight=%s lat=%s lon=%s alt=%s seen_pos=%s',
        $reason,
        $entry['hex'] ?? 'UNKNOWN',
        $entry['flight'] ?? 'UNKNOWN',
        isset($entry['lat']) ? number_format((float)$entry['lat'], 6, '.', '') : 'UNKNOWN',
        isset($entry['lon']) ? number_format((float)$entry['lon'], 6, '.', '') : 'UNKNOWN',
        $entry['alt'] ?? 'UNKNOWN',
        $entry['seen_pos'] ?? 'UNKNOWN'
    );
    $logger($message);
}
