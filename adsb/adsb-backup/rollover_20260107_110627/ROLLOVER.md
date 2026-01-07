# Rollover backup log

## 2026-01-07 07:40:09

Backup creado en:

- `adsb-backup/rollover_20260107_074009/`

### Cómo restaurar

Para restaurar todo el snapshot:

```bash
rsync -a adsb-backup/rollover_20260107_074009/ ./
```

Para restaurar archivos puntuales:

```bash
cp -a adsb-backup/rollover_20260107_074009/index.php ./index.php
cp -a adsb-backup/rollover_20260107_074009/config.php ./config.php
cp -a adsb-backup/rollover_20260107_074009/feed.php ./feed.php
cp -a adsb-backup/rollover_20260107_074009/health.php ./health.php
cp -a adsb-backup/rollover_20260107_074009/scripts/vatmex_to_geojson.php ./scripts/vatmex_to_geojson.php
cp -a adsb-backup/rollover_20260107_074009/vatmex/README.md ./vatmex/README.md
cp -a adsb-backup/rollover_20260107_074009/vatmex/airac/README.md ./vatmex/airac/README.md
```

### Archivos modificados y motivo

- `config.php`: activación del filtro de frontera mexicana para descartar vuelos fuera de México.
- `index.php`: robustez en la renderización de aeronaves/tiras y se muestra matrícula en detalle.
- `health.php`: verificación explícita de rutas/markers VATMEX (repo y AIRAC) y warnings más claros.
- `scripts/vatmex_to_geojson.php`: validación de coordenadas para evitar geometrías fuera de rango.
- `vatmex/README.md` y `vatmex/airac/README.md`: marcadores locales para que health valide rutas VATMEX.

## 2026-01-07 10:26:33 // [MXAIR2026-ROLL]

Backup creado en: // [MXAIR2026-ROLL]

- `adsb-backup/rollover_20260107_102633/` // [MXAIR2026-ROLL]

### Cómo restaurar // [MXAIR2026-ROLL]

Para restaurar todo el snapshot: // [MXAIR2026-ROLL]

```bash
rsync -a adsb-backup/rollover_20260107_102633/ ./
```

Para restaurar archivos puntuales: // [MXAIR2026-ROLL]

```bash
cp -a adsb-backup/rollover_20260107_102633/config.php ./config.php
cp -a adsb-backup/rollover_20260107_102633/feed.php ./feed.php
cp -a adsb-backup/rollover_20260107_102633/index.php ./index.php
cp -a adsb-backup/rollover_20260107_102633/ROLLOVER.md ./ROLLOVER.md
```

### Archivos modificados y motivo // [MXAIR2026-ROLL]

- `config.php`: nuevos centros nacionales y parámetros de caché/round-robin del feed. // [MXAIR2026-ROLL]
- `feed.php`: round-robin por centro, caché por centro, agregado persistente y backoff 429. // [MXAIR2026-ROLL]
- `index.php`: TTL local y marcado de objetivos stale sin parpadeo. // [MXAIR2026-ROLL]
- `ROLLOVER.md`: registro de rollback. // [MXAIR2026-ROLL]
