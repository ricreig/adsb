# ROLLOVER rollback instructions (MXAIR2026)

## Restore modified files
```sh
cp -a adsb-backup/rollover_20260107_070830/index.php ./index.php
cp -a adsb-backup/rollover_20260107_070830/config.php ./config.php
cp -a adsb-backup/rollover_20260107_070830/feed.php ./feed.php
cp -a adsb-backup/rollover_20260107_070830/health.php ./health.php
```

## Remove new files added in this rollout
```sh
rm -f ./.htaccess
rm -f ./api/geojson_manifest.php
```
