# AUDIT_REPORT.md

## 1) Estado actual (qué funciona / qué no)

### 1.1 Auditoría funcional end-to-end (local)

| Ítem | Resultado | Evidencia |
| --- | --- | --- |
| Mapa base “no labels” visible | **NO VERIFICADO** | No se pudo abrir un navegador dentro del entorno para inspección visual de la UI. Solo se pudo validar disponibilidad HTTP con `curl`. |
| GeoJSON se cargan/dibujan | **PARCIAL** | `GET /data/tma.geojson` respondió **200 OK** (ver “Evidencia técnica”). |
| Settings abre/cierra y aplica cambios | **NO VERIFICADO** | Requiere interacción en UI. |
| Feed ADS‑B consultado y tracks renderizados | **FAILED (P0)** | `GET /feed.php?lat=19.4&lon=-99.1&radius=50` respondió **502** (`{"error":"Unable to retrieve ADS‑B data"}`), lo que indica fallo al upstream o configuración. |
| Consola JS sin errores | **NO VERIFICADO** | Requiere navegador. |

**Evidencia técnica (comandos ejecutados):**
- Levantar servidor y pruebas HTTP locales:
  - `php -S 127.0.0.1:8000 -t /workspace/adsb/adsb > /tmp/adsb-php.log 2>&1 &`
  - `curl -sS -D - http://127.0.0.1:8000/ -o /tmp/adsb_index.html`
  - `curl -sS -D - http://127.0.0.1:8000/data/tma.geojson -o /tmp/adsb_tma.geojson`
  - `curl -sS -D - "http://127.0.0.1:8000/feed.php?lat=19.4&lon=-99.1&radius=50" -o /tmp/adsb_feed.json`

**Resultados (headers y salida relevantes):**
- `GET /` → **200 OK**
- `GET /data/tma.geojson` → **200 OK**
- `GET /feed.php?...` → **502 Bad Gateway**
  - Body: `{"error":"Unable to retrieve ADS‑B data"}`

### 1.2 Auditoría de datos (GeoJSON generado) — P0 bloqueante

Se ejecutó una validación local de rango de coordenadas y geometrías no vacías sobre los GeoJSON en `adsb/data/`.

**Resultado:** **FAILED (P0)**
- Se detectaron coordenadas fuera de rango en múltiples capas (latitudes fuera de [-90, 90]).
- Esto indica que **la normalización ISO 6709 sigue siendo incorrecta** o que existen tokens que no se están parseando con la lógica correcta (longitud variable y/o formatos sin separador decimal).

**Resumen por archivo (validación automática):**
```
file,features,coords,out_of_range,not_closed_rings
atz.geojson,9,1089,1089,0
ctr.geojson,6,509,432,0
fir-limits.geojson,31,594,594,0
nav-points.geojson,2,2,0,0
restricted-areas.geojson,5,338,338,0
tma.geojson,398,13646,12892,0
```

**Script de validación (entregable):** `scripts/validate_geojson.py`

### 1.3 Auditoría de backend (revisión estática)

#### feed.php
- **Parámetros lat/lon/radius:** Sí, acepta `lat`, `lon`, `radius` por query.
- **Límite 250 NM:** Sí, valida `radius > 250` y vuelve al default.
- **Caching/rate limit:** **NO** (P0). No hay cache server-side ni limitador de llamadas al proveedor.
- **Normalización JSON (tipos, uppercase, nulls):** **Parcial** (P1). Se normalizan algunos campos, pero no hay estandarización completa ni uppercase en todas las etiquetas.
- **Filtro FIR MMFR y frontera +10 NM:** **Parcial** (P1). Solo filtra por latitud norte; no hay bounding FIR completo.

#### update_airspace.php
- **Recorrido completo del repo VATMEX:** **Parcial** (P1). Usa un mapeo de sufijos `_TMA/_CTR/_ATZ/_ACC/_MVA/_MRA` pero no detecta otras categorías (CTA, P/R/D por tipo, etc.).
- **Soporte categorías solicitadas:** **Parcial** (P1). Faltan aerovías, SID/STAR/APP, CTA, corredores VFR, etc.
- **Export por capa y por entidad:** **NO** (P2). Solo exporta por tipo general (tma/ctr/etc.).

#### Persistencia de estado y notas
- **Estado assumed/released y notas:** **NO** (P0). No existe endpoint ni almacenamiento persistente.

## 2) Hallazgos priorizados (P0 / P1 / P2)

### P0 (bloqueantes)
1. **GeoJSON con coordenadas inválidas**: múltiples capas con latitudes fuera de rango (ver validación).
2. **Sin persistencia de estado/notas** (assume/release/OPMET).
3. **Sin cache/rate limit del feed ADS‑B**.
4. **Feed ADS‑B falla localmente (502)** al intentar resolver datos upstream.

### P1 (alto)
1. **Filtro FIR incompleto**: solo latitud norte, sin polígono FIR.
2. **Normalización/estandarización de labels** incompleta.
3. **Parser de VATMEX incompleto** (categorías faltantes).

### P2 (medio)
1. **Export por entidad (TMA/CTR por nombre)** no implementado.
2. **Capas SID/STAR/APP, aerovías, VFR corridors** no implementadas.

## 3) Plan de PRs incrementales (orden obligatorio)

1. **PR1 (P0): ISO 6709 + validate_geojson + catalog.json**
2. **PR2 (P0): Basemap + Settings funcional + persistencia**
3. **PR3 (P0): Feed robusto (cache/rate limit) + FIR filter + frontera**
4. **PR4 (P0): SQLite states + strips + notas persistentes**
5. **PR5 (P1): BRL amarillo**
6. **PR6 (P1): Flight plan + ruta magenta + ventana FP + auto-resolve**
7. **PR7+ (P2): capas nacionales + performance + SID/STAR/APP si viable**

## 4) Evidencia técnica (comandos locales)

- Validación GeoJSON:
  - `python /workspace/adsb/adsb/scripts/validate_geojson.py`
- Smoke test HTTP local:
  - `php -S 127.0.0.1:8000 -t /workspace/adsb/adsb > /tmp/adsb-php.log 2>&1 &`
  - `curl -sS -D - http://127.0.0.1:8000/ -o /tmp/adsb_index.html`
  - `curl -sS -D - http://127.0.0.1:8000/data/tma.geojson -o /tmp/adsb_tma.geojson`
  - `curl -sS -D - "http://127.0.0.1:8000/feed.php?lat=19.4&lon=-99.1&radius=50" -o /tmp/adsb_feed.json`
