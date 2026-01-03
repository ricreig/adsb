#!/usr/bin/env python3
import json
import sys
from pathlib import Path

def iter_coords(obj):
    if isinstance(obj, list):
        if len(obj) == 2 and all(isinstance(v, (int, float)) for v in obj):
            yield obj
        else:
            for item in obj:
                yield from iter_coords(item)
    elif isinstance(obj, dict):
        for v in obj.values():
            yield from iter_coords(v)

def validate_file(path: Path):
    with path.open() as fh:
        data = json.load(fh)
    features = data.get('features', [])
    out_of_range = 0
    not_closed = 0
    total_coords = 0
    for feat in features:
        geom = feat.get('geometry', {})
        coords = geom.get('coordinates')
        gtype = geom.get('type')
        for lon, lat in iter_coords(coords):
            total_coords += 1
            if not (-90 <= lat <= 90 and -180 <= lon <= 180):
                out_of_range += 1
        if gtype == 'Polygon' and coords:
            for ring in coords:
                if ring and ring[0] != ring[-1]:
                    not_closed += 1
    return {
        "file": path.name,
        "features": len(features),
        "coords": total_coords,
        "out_of_range": out_of_range,
        "not_closed_rings": not_closed,
    }

def main():
    base = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(__file__).resolve().parents[1] / 'data'
    files = sorted(base.glob('*.geojson'))
    if not files:
        print(f"No .geojson files found in {base}")
        return 1
    results = [validate_file(path) for path in files]
    print("file,features,coords,out_of_range,not_closed_rings")
    for row in results:
        print(",".join(str(row[k]) for k in ["file","features","coords","out_of_range","not_closed_rings"]))
    if any(r["out_of_range"] > 0 or r["not_closed_rings"] > 0 for r in results):
        return 2
    return 0

if __name__ == '__main__':
    raise SystemExit(main())
