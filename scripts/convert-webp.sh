#!/usr/bin/env bash
# =============================================================================
# convert-webp.sh
# -----------------------------------------------------------------------------
# images/gallery/ 配下のJPEG/PNGを、324w / 648w / 1296w の3サイズの
# WebP に一括変換して images/gallery/webp/ に出力する。
#
# 必要ツール:
#   - cwebp (Google の libwebp), または
#   - ImageMagick (magick convert コマンド)
#
# 実行例:
#   bash scripts/convert-webp.sh
#
# macOS: brew install webp imagemagick
# Ubuntu: sudo apt install webp imagemagick
# =============================================================================
set -euo pipefail

SRC_DIR="images/gallery"
DEST_DIR="images/gallery/webp"
SIZES=(324 648 1296)
QUALITY=82

mkdir -p "$DEST_DIR"

have_cwebp=0
if command -v cwebp >/dev/null 2>&1; then
  have_cwebp=1
fi

have_magick=0
if command -v magick >/dev/null 2>&1; then
  have_magick=1
elif command -v convert >/dev/null 2>&1; then
  have_magick=1
fi

if [[ "$have_cwebp" -eq 0 && "$have_magick" -eq 0 ]]; then
  echo "ERROR: cwebp も ImageMagick (magick/convert) も見つかりません。"
  echo "macOS: brew install webp imagemagick"
  echo "Ubuntu: sudo apt install webp imagemagick"
  exit 1
fi

count=0
for src in "$SRC_DIR"/*.{jpg,jpeg,JPG,JPEG,png,PNG}; do
  [[ -e "$src" ]] || continue
  filename=$(basename -- "$src")
  name="${filename%.*}"

  for w in "${SIZES[@]}"; do
    out="$DEST_DIR/${name}-${w}.webp"
    if [[ -e "$out" ]]; then
      continue
    fi

    if [[ "$have_cwebp" -eq 1 ]]; then
      # cwebp は単独でリサイズ可能
      cwebp -q "$QUALITY" -resize "$w" 0 "$src" -o "$out" >/dev/null 2>&1
    elif command -v magick >/dev/null 2>&1; then
      magick "$src" -resize "${w}x" -quality "$QUALITY" "$out"
    else
      convert "$src" -resize "${w}x" -quality "$QUALITY" "$out"
    fi
    count=$((count+1))
    echo "  + $out"
  done
done

echo ""
echo "Done. $count 件の WebP を生成しました。"
echo "生成先: $DEST_DIR/"
