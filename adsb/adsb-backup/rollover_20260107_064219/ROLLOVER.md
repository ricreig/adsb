# ROLLOVER

## Rollback commands

```bash
# From the repo root (/workspace/adsb/adsb)
cp -a adsb-backup/rollover_20260107_064219/index.php ./index.php
cp -a adsb-backup/rollover_20260107_064219/config.php ./config.php
cp -a adsb-backup/rollover_20260107_064219/health.php ./health.php
```

## Post-rollback verification checklist

- [ ] Load `/adsb/adsb/index.php` and confirm the map renders without console errors.
- [ ] Open `/adsb/adsb/health.php` and confirm status is `ok`.
- [ ] Confirm ADS-B feed UI loads and updates (even if rate-limited).
- [ ] Confirm AIRAC panel loads without VATMEX warnings.
