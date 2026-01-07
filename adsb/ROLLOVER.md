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
