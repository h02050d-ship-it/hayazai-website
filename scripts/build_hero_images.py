# -*- coding: utf-8 -*-
"""ヒーロースライドショー用の写真風画像を gpt-image-1 で生成する。

- スクショで好評だった「明るい和モダン・桧の床・大きな窓・自然光」の写真風
- 文字は一切入れない（HTML側でテキストを重ねるため）
- 横長 1536x1024 high品質 → デスクトップ用 webp(1600px) と モバイル用 webp(900px) を出力
出力: images/hero/<name>.webp, images/hero/<name>_mobile.webp
"""
import io
import os
import sys
import base64
import time
from pathlib import Path

from openai import OpenAI
from PIL import Image

OUT = Path(__file__).resolve().parent.parent / "images" / "hero"
OUT.mkdir(parents=True, exist_ok=True)

COMMON = (
    "Photorealistic interior photograph, warm natural afternoon sunlight, "
    "light-colored Japanese hinoki (cypress) solid wood flooring with visible natural grain as the main feature, "
    "Japanese modern minimalist style, airy and calm, soft shadows, high-end real-estate photography, "
    "shot on a full-frame camera, shallow depth of field, no text, no letters, no watermark, no logo, no people faces. "
    "Composition leaves a calmer, less busy area on the left and top so text can be overlaid later. Landscape orientation."
)

SLIDES = {
    "hero1_living": "A bright spacious modern Japanese living room. A beige fabric sofa, a low solid-wood coffee table, "
                    "large floor-to-ceiling windows with a green garden visible outside, a potted plant. "
                    "The light hinoki wood floor glows in the sunlight.",
    "hero2_life":   "A serene Japanese-style bedroom with light hinoki wood flooring, a low wooden bed or neatly folded futon, "
                    "shoji paper screens diffusing soft morning light, a small plant, very calm and cozy atmosphere. "
                    "Bare feet feeling of comfort, family home warmth.",
    "hero3_craft":  "A close-up in a wood workshop: freshly planed light hinoki cypress floorboards neatly stacked, "
                    "fine wood shavings, a hand plane (kanna) resting on a board, warm workshop light. "
                    "Craftsmanship and made-in-factory feeling, no faces.",
}


def main():
    only = set(sys.argv[1:])
    client = OpenAI()
    ok, ng = [], []
    for name, scene in SLIDES.items():
        if only and name not in only:
            continue
        prompt = scene + " " + COMMON
        for attempt in (1, 2):
            try:
                resp = client.images.generate(
                    model="gpt-image-1", prompt=prompt,
                    size="1536x1024", n=1, quality="high",
                )
                data = base64.b64decode(resp.data[0].b64_json)
                img = Image.open(io.BytesIO(data)).convert("RGB")
                # デスクトップ用 1600px幅
                w, h = img.size
                desk = img.resize((1600, round(1600 * h / w)), Image.LANCZOS)
                desk.save(OUT / f"{name}.webp", "WEBP", quality=82, method=6)
                # モバイル用 900px幅（縦長寄りに中央クロップ）
                mob = img.resize((900, round(900 * h / w)), Image.LANCZOS)
                mob.save(OUT / f"{name}_mobile.webp", "WEBP", quality=80, method=6)
                print(f"[OK] {name} -> {name}.webp / {name}_mobile.webp")
                ok.append(name)
                break
            except Exception as e:
                print(f"[ERR] {name} attempt {attempt}: {e}")
                if attempt == 2:
                    ng.append(name)
                else:
                    time.sleep(5)
    print(f"\n[DONE] success={len(ok)} failed={len(ng)}")
    if ng:
        print("failed: " + " ".join(ng))
        sys.exit(1)


if __name__ == "__main__":
    main()
