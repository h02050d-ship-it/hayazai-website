# -*- coding: utf-8 -*-
"""グレード見本の継ぎ目（本実の合わせ目）が斜めなのを、GPTで垂直に整える。
木目・節・色（ベース）は変えず、板の合わせ目だけ垂直・平行にする。
入力: images/grade_src/IMG_38xx.jpeg（高解像度オリジナル）
出力: images/grade/grade_<en>.webp（縦3:4・full-frame）
"""
import io
import sys
import base64
from pathlib import Path
from openai import OpenAI
from PIL import Image

REPO = Path(__file__).resolve().parent.parent
SRC = REPO / "images" / "grade_src"
OUT = REPO / "images" / "grade"

MAP = [
    ("IMG_3840", "setsuari"),
    ("IMG_3841", "kobushi"),
    ("IMG_3838", "tokujosho"),
    ("IMG_3839", "musetsu"),
]

PROMPT = (
    "This is a real photo of three Japanese hinoki (cypress) flooring boards laid side by side as vertical planks. "
    "The tongue-and-groove joint lines between the boards are slightly slanted because of the camera angle. "
    "Correct the perspective so that the vertical joint lines between the boards become perfectly vertical and parallel, "
    "and the boards look like a straight, flat-on flooring swatch. "
    "IMPORTANT: keep the wood grain, the knots (number, position, size, color) and the overall wood color EXACTLY the same — "
    "do not invent, add, remove, move or restyle any knot or grain. Only fix the geometry/straightness. "
    "Fill the whole frame with the wood (no background), keep even natural lighting, portrait orientation, "
    "sharp focus, no text, no watermark."
)


def _png(path, maxpx=1280):
    img = Image.open(path).convert("RGB")
    w, h = img.size
    if max(w, h) > maxpx:
        s = maxpx / max(w, h)
        img = img.resize((round(w * s), round(h * s)), Image.LANCZOS)
    buf = io.BytesIO(); img.save(buf, "PNG"); buf.seek(0); buf.name = "src.png"
    return buf


def main():
    only = set(sys.argv[1:])
    client = OpenAI()
    for stem, en in MAP:
        if only and en not in only and stem not in only:
            continue
        resp = client.images.edit(model="gpt-image-1", image=_png(SRC / f"{stem}.jpeg"),
                                   prompt=PROMPT, size="1024x1536", quality="high")
        data = base64.b64decode(resp.data[0].b64_json)
        Image.open(io.BytesIO(data)).convert("RGB").save(
            OUT / f"grade_{en}.webp", "WEBP", quality=88, method=6)
        print(f"[OK] {stem} -> grade_{en}.webp")
    print("DONE")


if __name__ == "__main__":
    main()
