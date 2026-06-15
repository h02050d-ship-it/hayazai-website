# -*- coding: utf-8 -*-
"""桧グレード見本の写真を gpt-image-1 で商品写真にお化粧する（image-to-image）。

入力: images/grade_src/ 内の写真（丸節〜無節などのグレード見本）
出力:
  images/grade/<name>.webp          … クリーン背景（明るいスタジオ風）＋影
  images/grade/<name>_nobg.png      … 背景なし（透過）

⚠️ グレード見本のため、木目・節の位置/数/色は変えず、背景と光・角度だけ整える方針を
   プロンプトで強く指示する（景表法・優良誤認の防止）。出力はユーザー確認前提。
"""
import io
import sys
import base64
from pathlib import Path

from openai import OpenAI
from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / "images" / "grade_src"
OUT = ROOT / "images" / "grade"
OUT.mkdir(parents=True, exist_ok=True)

PROMPT_CLEAN = (
    "This is a real photo of Japanese hinoki (cypress) solid flooring sample boards. "
    "Retouch it into a clean professional product photo in PORTRAIT (vertical) orientation. "
    "Arrange the three boards as vertical planks standing side by side, running from top to bottom of the tall frame (portrait flooring layout). "
    "IMPORTANT: keep the wood grain, the knots (number, position, size, color) and the board edges EXACTLY as in the original — do not invent, add, remove or move any knot or grain. "
    "Replace the cluttered dark scratched table background with a clean, soft, evenly-lit light studio background (very light warm gray / off-white), "
    "correct the white balance to neutral-warm, even out the lighting, straighten the boards, and add a soft natural drop shadow. "
    "High-end e-commerce product photography, sharp focus, no text, no watermark."
)

PROMPT_NOBG = (
    "This is a real photo of Japanese hinoki (cypress) solid flooring sample boards. "
    "Produce a PORTRAIT (vertical) cutout: arrange the three boards as vertical planks side by side running top to bottom, on a fully transparent background. "
    "IMPORTANT: keep the wood grain, knots (number, position, size, color) and edges EXACTLY as in the original — do not invent or alter any knot or grain. "
    "Only remove the background, correct white balance to neutral-warm, even the lighting, and straighten the boards. "
    "Clean cutout edges, no shadow, no text, no watermark."
)


def _downscaled_png(path, maxpx=1280):
    """入力をmaxpxに縮小したPNGバイト列にする（コスト/転送削減）。"""
    img = Image.open(path).convert("RGB")
    w, h = img.size
    if max(w, h) > maxpx:
        s = maxpx / max(w, h)
        img = img.resize((round(w * s), round(h * s)), Image.LANCZOS)
    buf = io.BytesIO()
    img.save(buf, "PNG")
    buf.seek(0)
    buf.name = "src.png"
    return buf


def edit_one(client, path, prompt, transparent):
    kwargs = dict(model="gpt-image-1", image=_downscaled_png(path),
                  prompt=prompt, size="1024x1536", quality="high")
    if transparent:
        kwargs["background"] = "transparent"
    resp = client.images.edit(**kwargs)
    return base64.b64decode(resp.data[0].b64_json)


def main():
    args = sys.argv[1:]
    files = sorted([p for p in SRC.iterdir()
                    if p.suffix.lower() in (".jpg", ".jpeg", ".png", ".webp")])
    if args:
        files = [p for p in files if p.stem in args]
    if not files:
        print(f"[!] {SRC} に画像がありません。写真を置いてから再実行してください。")
        sys.exit(1)

    client = OpenAI()
    for p in files:
        name = p.stem
        print(f"--- {p.name} ---")
        # クリーン背景版
        data = edit_one(client, p, PROMPT_CLEAN, transparent=False)
        Image.open(io.BytesIO(data)).convert("RGB").save(
            OUT / f"{name}.webp", "WEBP", quality=88, method=6)
        print(f"[OK] {name}.webp")
        # 透過版
        data = edit_one(client, p, PROMPT_NOBG, transparent=True)
        Image.open(io.BytesIO(data)).save(OUT / f"{name}_nobg.png", "PNG")
        print(f"[OK] {name}_nobg.png")
    print("\n[DONE]")


if __name__ == "__main__":
    main()
