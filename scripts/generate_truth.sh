# scripts/generate_truth.sh
set -euo pipefail
ROOT="${1:-test-images}"
OUT="${2:-fixtures}"

mkdir -p "$OUT"
VER=$(exiftool -ver || true)

# Dateiliste (alle Bilder/HEIC/MOV etc.)
mapfile -t FILES < <(find "$ROOT" -type f \( -iname "*.jpg" -o -iname "*.jpeg" -o -iname "*.png" -o -iname "*.tif" -o -iname "*.tiff" -o -iname "*.heic" -o -iname "*.mp4" -o -iname "*.mov" \) | sort)

for f in "${FILES[@]}"; do
  base=$(basename "$f")
  sha=$(sha256sum "$f" | awk '{print $1}')
  # -n = Rohwerte; -struct = Arrays/Objekte strukturiert; -G1 = Tag-Gruppe; -a/-u = alle/unknown
  exiftool -api largefilesupport=1 -G1 -a -u -n -s -struct -json "$f" > "$OUT/$base.exiftool.json"
  # kleine Begleitdatei mit Version/Hash
  cat > "$OUT/$base.meta.json" <<EOF
{"file":"$base","sha256":"$sha","exiftool_version":"$VER"}
EOF
done

echo "Ground truth in $OUT/*.exiftool.json"
