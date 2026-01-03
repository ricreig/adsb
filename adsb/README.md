# ADS-B ATC Display (Mexico)

## Requisitos
- PHP 8+ con SQLite habilitado.
- Git disponible en el servidor.
- No requiere Python.

## Ejecutar localmente
```bash
php -S 127.0.0.1:8000 -t /workspace/adsb/adsb
```

Visita `http://127.0.0.1:8000/`.

## Settings (persistencia)
- Endpoint: `GET /api/settings.php` devuelve settings y defaults.
- Endpoint: `POST /api/settings.php` guarda settings validados en SQLite (`data/adsb.sqlite`).

## Diagnóstico rápido
Abre `http://127.0.0.1:8000/health.php` (o tu ruta equivalente en producción) para verificar:
- `app_base` y `api_base` detectados por PHP.
- Disponibilidad de `sqlite3`.
- Permisos de escritura para `data/`, `data/cache/` y `data/adsb.sqlite` en `writable`.
- Hora del servidor (`now`).

Si aparece “L undefined” en móviles, revisa el overlay superior: ahora intenta cargar Leaflet desde CDN y usa fallback local en `assets/vendor/leaflet/` si el CDN falla.

### Feed robusto (cache + rate limit)
El feed (`feed.php`) aplica cache de archivo en `data/cache/` con TTL de 1.5s y rate limit global de 1 req/s al upstream (`airplanes.live`). Campos clave del JSON:
- `cache_hit`: `true` si la respuesta vino de cache fresca.
- `cache_stale`: `true` si se devolvió cache vieja por rate limit o error upstream.
- `age_ms`: edad del cache en ms.
- `upstream_http`: código HTTP del upstream (si aplica).

Para probar desde producción:
```bash
curl -I https://ctareig.com/adsb/adsb/
curl https://ctareig.com/adsb/adsb/health.php
curl https://ctareig.com/adsb/adsb/data/tma.geojson
curl "https://ctareig.com/adsb/adsb/feed.php?lat=32.541&lon=-116.97&radius=250"
curl https://ctareig.com/adsb/adsb/api/settings.php
```

### Filtro FIR + frontera norte
El feed filtra aeronaves usando `data/fir-limits.geojson` (point-in-polygon) y aplica una banda de 10NM al norte:
- Si existe `data/mex-border.geojson`, se conserva tráfico dentro de México o hasta 10NM fuera del borde.
- Si no existe, se usa `border_lat` + `north_buffer_nm` (config.php).

Para validar el filtro, compara el conteo de `total` en `feed.php` con y sin los GeoJSON presentes.

## AIRAC / VATMEX
Configura en `config.php`:
```php
'vatmex_dir' => '/absolute/path/to/vatmex',
'airac_update_enabled' => true,
```

Endpoint:
- `POST /api/admin/airac_update.php`

Esto ejecuta (en servidor):
1. `git -C VATMEX_DIR pull --ff-only`
2. `php /path/to/adsb/update_airspace.php VATMEX_DIR`
3. `php /path/to/adsb/scripts/validate_geojson.php`

> **Nota:** protege el directorio `/adsb/` con acceso restringido (uso personal). Este PR no incluye autenticación.

## Validación GeoJSON
```bash
php update_airspace.php /ruta/al/vatmex-mmfr-sector
php scripts/validate_geojson.php
```
En el servidor no hay Python, así que siempre se usa `scripts/validate_geojson.php`.

## Fuentes de datos geográficos
- `data/mex-border.geojson`: contorno simplificado de México basado en Natural Earth (1:50m Admin 0 Countries, dominio público).
