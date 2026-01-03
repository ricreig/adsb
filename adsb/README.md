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
Abre `http://127.0.0.1:8000/api/health.php` (o tu ruta equivalente en producción) para verificar:
- `base_path` detectado por PHP.
- Disponibilidad de extensiones (`sqlite3`, `apcu`).
- Hora del servidor (`now`).

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
