# Rollover Backup

Timestamp: 2026-01-07T11:00:40Z

## Summary
- Backup created before MXAIR2026 feed pipeline updates.
- Files changed in this rollover:
  - feed.php
  - health.php
  - index.php

## Rollback Steps
1. Restore the previous versions of the files from Git history or deployment backup.
2. Clear cached feed data to force a fresh rebuild:
   - Remove files in `data/cache/` (or the configured feed cache directory).
3. Validate `/feed.php` and `/health.php` after rollback.
