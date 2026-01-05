# Changelog

## Unreleased
- Added normalized comparison logic for aircraft deduplication using rounded coordinates and significant altitude deltas.
- Introduced cache cleanup for stale aircraft entries based on `seen_pos` age thresholds.
- Added FIR/north-border discard logging for filtered aircraft.
- Updated cache writing to omit redundant aircraft entries before persisting.
- Added automated tests covering deduplication, cleanup behavior, and filter discard logging.
