# -*- coding: utf-8 -*-
"""グレード見本の継ぎ目を「回転補正だけ」で垂直にする（GPT不使用・木目は不変）。

縦の継ぎ目（板の合わせ目＝暗い溝）が垂直になる回転角を自動探索し、
その角度だけ回転 → 余白を避けて中央を縦3:4でクロップ → webp出力。
入力: images/grade_src/IMG_38xx.jpeg
出力: images/grade/grade_<en>.webp
"""
import sys
from pathlib import Path
import numpy as np
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


def best_angle(gray_img):
    """継ぎ目(板の合わせ目)は最も強い縦エッジ。各角度で回転し、列ごとの
    横微分合計の上位ピーク(=継ぎ目)が最も立つ角度を選ぶ。杢目に強い。"""
    W = 600
    w, h = gray_img.size
    small = gray_img.resize((W, int(h * W / w)), Image.BILINEAR)
    best_a, best_s = 0.0, -1.0
    for a10 in range(-40, 41):                 # -4.0〜+4.0度 0.1刻み
        a = a10 / 10.0
        r = small.rotate(a, resample=Image.BILINEAR, expand=False, fillcolor=128)
        arr = np.asarray(r, dtype=np.float32)
        H, Wd = arr.shape
        c = arr[int(H * 0.15):int(H * 0.85), int(Wd * 0.1):int(Wd * 0.9)]
        gx = np.abs(np.diff(c, axis=1)).sum(axis=0)
        base = np.convolve(np.pad(gx, 20, mode="edge"), np.ones(41) / 41, "valid")[:len(gx)]
        peaks = np.sort(gx - base)[-3:].sum()  # 継ぎ目＋板端の上位3ピーク
        if peaks > best_s:
            best_s, best_a = peaks, a
    return best_a


def process(stem, en):
    img = Image.open(SRC / f"{stem}.jpeg").convert("RGB")
    gray = img.convert("L")
    angle = best_angle(gray)
    rot = img.rotate(angle, resample=Image.BICUBIC, expand=False)
    w, h = rot.size
    # 回転で生じる縁を避けるため、まず内側92%を取ってから縦3:4にクロップ
    mx = int(w * 0.04); my = int(h * 0.04)
    rot = rot.crop((mx, my, w - mx, h - my))
    w, h = rot.size
    tr = 3 / 4
    if w / h > tr:
        nw = int(h * tr); left = (w - nw) // 2
        rot = rot.crop((left, 0, left + nw, h))
    else:
        nh = int(w / tr); top = (h - nh) // 2
        rot = rot.crop((0, top, w, top + nh))
    rot = rot.resize((1080, 1440), Image.LANCZOS)
    rot.save(OUT / f"grade_{en}.webp", "WEBP", quality=90, method=6)
    print(f"[OK] {stem} angle={angle:+.1f}deg -> grade_{en}.webp")


def main():
    only = set(sys.argv[1:])
    for stem, en in MAP:
        if only and en not in only and stem not in only:
            continue
        process(stem, en)
    print("DONE")


if __name__ == "__main__":
    main()
