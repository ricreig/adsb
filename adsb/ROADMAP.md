# ROADMAP.md

## Objetivo
Entregar un radar ATC web end-to-end funcional siguiendo el orden de PRs acordado y los criterios de aceptación.

## PRs (orden estricto)

### PR1 — ISO 6709 + validate_geojson + catalog.json (P0)
- Implementar parser ISO 6709 robusto en `update_airspace.php`.
- Regenerar GeoJSON y generar `data/catalog.json` con bbox y conteos.
- `scripts/validate_geojson.*` debe pasar con `out_of_range = 0`.

**Dependencias:** ninguna.

### PR2 — Basemap + Settings funcional + persistencia (P0)
- Basemap sin labels.
- Panel Settings funcional con persistencia (SQLite o JSON).
- Cambios en vivo + persistencia al reload.

**Dependencias:** PR1 (capas correctas).

### PR3 — Feed robusto (cache/rate limit) + FIR filter + frontera (P0)
- Cache TTL 1–2s, rate limit 1 req/s al upstream.
- Filtro FIR (point-in-polygon) + frontera norte.
- Normalización y uppercase.

**Dependencias:** PR1.

### PR4 — Persistencia estados + strips (P0)
- SQLite para estados, orden y notas.
- Endpoints API /api/state y /api/strips.
- UI de strips con detalle inferior.

**Dependencias:** PR2 (settings persistencia) y PR3 (feed estable).

### PR5 — BRL amarillo (P1)
- BRL track↔punto / track↔track con BRG/RNG/ETA.
- Render amarillo + caja ATC.

**Dependencias:** PR3.

### PR6 — Plan de vuelo + ruta magenta (P1)
- Resolver FP con caching.
- Ventana FP + “Route ON/OFF”.
- Auto-resolve con backoff.

**Dependencias:** PR3.

### PR7+ — Capas nacionales completas + performance (P2)
- Aerovías, navaids, fixes, MVA, R/D/P, SID/STAR/APP.
- Estrategia de rendimiento (bbox/tiles).

**Dependencias:** PR1.

## Definición de terminado (DoD)
- Cada PR incluye:
  - Evidencia (capturas/logs).
  - Cómo probar.
  - Checklist del PR template marcada.
- No se toca producción directamente; todo va por PRs.
