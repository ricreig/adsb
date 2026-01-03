# AUDIT_REPORT.md

## 1) Estado actual (qué funciona / qué no)

### 1.1 Auditoría funcional end-to-end (producción y local)

| Ítem | Producción (https://ctareig.com/adsb/) | Local | Evidencia |
| --- | --- | --- | --- |
| Mapa base “no labels” visible | **NO VERIFICADO** | **NO VERIFICADO** | Intento de acceso con Playwright falló: `ports_to_forward is required` al usar la herramienta de navegador del entorno. Intento con `curl` falló con **403 Forbidden** y `CONNECT tunnel failed` (ver comando en “Evidencia técnica”). |
| GeoJSON se cargan/dibujan | **NO VERIFICADO** | **NO VERIFICADO** | No fue posible cargar la UI en navegador debido a las limitaciones anteriores. |
| Settings abre/cierra y aplica cambios | **NO VERIFICADO** | **NO VERIFICADO** | No fue posible validar en UI. |
| Feed ADS‑B consultado y tracks renderizados | **NO VERIFICADO** | **NO VERIFICADO** | No fue posible validar en UI. |
| Consola JS sin errores | **NO VERIFICADO** | **NO VERIFICADO** | No fue posible validar en UI. |

**Evidencia técnica (comandos ejecutados):**
- `curl -sS -D - https://ctareig.com/adsb/ -o /tmp/adsb_prod.html`
  - Resultado: `HTTP/1.1 403 Forbidden` y `CONNECT tunnel failed`.

**Nota:** Necesitamos acceso real por navegador para completar esta sección. En el entorno actual, Playwright requiere `ports_to_forward` (orientado a servicios locales) y el proxy bloqueó el acceso directo con `curl`.

### 1.2 Auditoría de datos (GeoJSON generado)

Se ejecutó una validación local básica (rango de coordenadas y cierre de polígonos) sobre los GeoJSON en `adsb/data/`.

**Resultado:** **FAILED (P0)**
- Se detectaron coordenadas fuera de rango en múltiples capas. Ejemplo:
  - `tma.geojson`: latitud `260.4004447222222` (fuera de [-90, 90]) con lon `-97.81561611111111`.
- Esto indica que **la normalización ISO 6709 sigue siendo incorrecta** o que existen tokens que no se están parseando con la lógica correcta (posible longitud variable para grados y/o formatos sin separador decimal).

**Resumen por archivo (validación automática):**
- `atz.geojson`: 9 features, **1089 coords fuera de rango**
- `ctr.geojson`: 6 features, **432 coords fuera de rango**
- `fir-limits.geojson`: 31 features, **594 coords fuera de rango**
- `nav-points.geojson`: 2 features, 0 coords fuera de rango
- `restricted-areas.geojson`: 5 features, **338 coords fuera de rango**
- `tma.geojson`: 398 features, **12892 coords fuera de rango**

**Salida completa del script:**

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
1. **GeoJSON con coordenadas inválidas**: múltiples capas con latitudes fuera de rango.
2. **Sin persistencia de estado/notas** (assume/release/OPMET).
3. **Sin cache/rate limit del feed ADS‑B**.
4. **Auditoría UI no completada por bloqueo de acceso en entorno actual** (se requiere verificación manual).

### P1 (alto)
1. **Filtro FIR incompleto**: solo latitud norte, sin polígono FIR.
2. **Normalización/estandarización de labels** incompleta.
3. **Parser de VATMEX incompleto** (categorías faltantes).

### P2 (medio)
1. **Export por entidad (TMA/CTR por nombre)** no implementado.
2. **Capas SID/STAR/APP, aerovías, VFR corridors** no implementadas.

## 3) Plan de PRs incrementales (propuesta + tiempos)

> Estimaciones en días hábiles, suponiendo acceso a entorno local con PHP y dataset VATMEX actualizado.

1. **PR#1 (P0): Validación GeoJSON + fix parseCoordinate** (1–2 días)
   - Corregir parser ISO 6709 hasta eliminar coords fuera de rango.
   - Agregar tests/validación automática y CI básico.

2. **PR#2 (P0): Cache y rate limit en feed.php** (1 día)
   - Cache con TTL + límite 1 req/seg por IP/servidor.

3. **PR#3 (P0): Persistencia de estados y notas** (2–3 días)
   - SQLite + endpoints `/api/state`, `/api/strips`.

4. **PR#4 (P1): FIR polygon + filtro preciso** (1–2 días)
   - Usar FIR limits para filtrar tracks por polígono.

5. **PR#5 (P1): BRL + ruta magenta + ventana de FP** (3–4 días)

6. **PR#6 (P2): Aerovías, SID/STAR/APP** (4–6 días)

7. **PR#7 (P2): README completo + pipeline AIRAC** (1–2 días)

## 4) APIs seleccionadas para Flight Plan (propuesta)

- **Primaria:** OpenSky Network (API pública con límites).
  - Ventajas: abierta, documentada, usable con `callsign`/`icao24`.
  - Limitaciones: rate limits estrictos, datos históricos limitados.
- **Fallback:** ADSB.lol / adsb.fi (si permiten uso con caching y sin keys en frontend).
  - Requiere validación legal/licencia antes de integrar.

## 5) Plan AIRAC y automatización

- Script propuesto: `scripts/airac_update.sh`
  1. `git pull` en `vatmex-mmfr-sector` (fuera del repo runtime).
  2. Ejecutar `php update_airspace.php /ruta/vatmex-mmfr-sector`.
  3. Ejecutar `python scripts/validate_geojson.py`.
  4. Generar `data/catalog.json` con metadata (AIRAC, commit hash, timestamp).

## Evidencia técnica (comandos locales)

- Validación GeoJSON:
  - `python scripts/validate_geojson.py`
- Muestra de coordenada inválida:
  - `python - <<'PY' ...` (ver historial de comandos del entorno).
