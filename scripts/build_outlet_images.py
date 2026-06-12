# -*- coding: utf-8 -*-
"""アウトレット商品画像をGoogleドライブの元画像（2048px）から生成する。

- data/outlet.json の各商品IDに対し、Drive内の <ID大文字>.jpg を検索
- 800x800 JPEG q85 に変換して images/outlet/<id>.jpg へ保存
- outlet.json の img をローカルパスに書き換え（BOM・インデント形式は維持）
再実行可（既存画像は上書き、JSONは冪等）。
"""
import json
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
DRIVE = Path(r"G:\マイドライブ\グーグルドライブ\8.画像")
OUT = ROOT / "images" / "outlet"
OUT.mkdir(parents=True, exist_ok=True)
JSON_PATH = ROOT / "data" / "outlet.json"

# Drive内の全jpgを一度だけ走査して {小文字ファイル名: パス} の索引を作る
index = {}
for p in DRIVE.rglob("*.jpg"):
    index.setdefault(p.name.lower(), p)

items = json.loads(JSON_PATH.read_text(encoding="utf-8-sig"))

ok, miss = [], []
for it in items:
    pid = it["id"].lower()
    src = index.get(f"{pid}.jpg")
    if not src:
        miss.append(pid)
        continue
    img = Image.open(src).convert("RGB")
    img.thumbnail((800, 800), Image.LANCZOS)
    dest = OUT / f"{pid}.jpg"
    img.save(dest, "JPEG", quality=85, optimize=True)
    it["img"] = f"images/outlet/{pid}.jpg"
    ok.append(pid)

# PowerShell由来のBOM付きを維持して保存（インデント4・非ASCIIはそのまま）
text = json.dumps(items, ensure_ascii=False, indent=4)
JSON_PATH.write_text(text, encoding="utf-8-sig")

print(f"[OK] {len(ok)} images: " + " ".join(ok))
if miss:
    print(f"[MISS] {len(miss)}: " + " ".join(miss))
