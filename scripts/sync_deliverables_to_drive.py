# -*- coding: utf-8 -*-
"""今セッションの画像成果物を Googleドライブ 4.大樹/04.画像/HP/ にトピック別で整理コピーする。
日本語ファイル名のため Python(UTF-8) で実行する（PowerShellは文字化けするため使わない）。
"""
import shutil
from pathlib import Path

REPO = Path(r"C:\Users\hayaz\hayazai_website")
HP = Path(r"G:\マイドライブ\グーグルドライブ\4.大樹\04.画像\HP")

# --- 1) グレード見本（加工済み・縦・スタジオ背景） ---
grade_dir = HP / "等級" / "加工済み_縦_スタジオ背景_2026-06-15"
grade_dir.mkdir(parents=True, exist_ok=True)
GRADE_NAMES = {
    "IMG_0376": "01_節あり（丸節）",
    "IMG_0379": "02_小節",
    "IMG_0377": "03_特上小",
    "IMG_0378": "04_無節",
}
n = 0
for stem, jp in GRADE_NAMES.items():
    src = REPO / "images" / "grade" / f"{stem}.webp"
    if src.exists():
        shutil.copy2(src, grade_dir / f"{jp}.webp"); n += 1
    src_nobg = REPO / "images" / "grade" / f"{stem}_nobg.png"
    if src_nobg.exists():
        shutil.copy2(src_nobg, grade_dir / f"{jp}_背景なし.png"); n += 1
print(f"[等級] {n} files -> {grade_dir}")

# --- 2) ヒーロースライドショー画像 ---
hero_dir = HP / "ヒーロー画像_2026-06-15"
hero_dir.mkdir(parents=True, exist_ok=True)
HERO_NAMES = {
    "hero1_living": "ヒーロー1_リビング",
    "hero2_life": "ヒーロー2_暮らし",
    "hero3_craft": "ヒーロー3_作り手",
}
n = 0
for stem, jp in HERO_NAMES.items():
    for suffix, tag in (("", ""), ("_mobile", "_モバイル")):
        src = REPO / "images" / "hero" / f"{stem}{suffix}.webp"
        if src.exists():
            shutil.copy2(src, hero_dir / f"{jp}{tag}.webp"); n += 1
print(f"[ヒーロー] {n} files -> {hero_dir}")

# --- 3) ブログ アイキャッチ22枚 ---
blog_dir = HP / "ブログアイキャッチ_2026-06-15"
blog_dir.mkdir(parents=True, exist_ok=True)
n = 0
for src in sorted((REPO / "images" / "blog").glob("*.jpg")):
    shutil.copy2(src, blog_dir / src.name); n += 1
print(f"[ブログ] {n} files -> {blog_dir}")

print("\n[DONE]")
