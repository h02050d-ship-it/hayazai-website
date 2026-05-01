#!/usr/bin/env python3
"""
images/gallery/ 配下の画像を最適化するスクリプト。
- 元ファイル(.jpg/.jpeg/.png)を入力
- 各画像から WebP 3サイズ + JPEG large(上書き) + JPEG thumb を生成

出力命名:
  <name>.webp       長辺1600px / quality80
  <name>_md.webp    長辺900px  / quality75
  <name>_th.webp    長辺400px  / quality70
  <name>.jpg        長辺1600px / quality80 progressive (元上書き)
  <name>_th.jpg     長辺400px  / quality75
"""
from __future__ import annotations
import os
import sys
import time
from concurrent.futures import ProcessPoolExecutor, as_completed
from pathlib import Path
from PIL import Image, ImageOps, UnidentifiedImageError

GALLERY_DIR = Path(__file__).resolve().parent.parent / "images" / "gallery"

VARIANTS = [
    # (suffix_without_dot_with_underscore, max_long_edge, quality, fmt)
    # suffix '' = 元ファイル名そのまま (.webp / .jpg)
    ("",    1600, 80, "webp"),
    ("_md", 900,  75, "webp"),
    ("_th", 400,  70, "webp"),
    ("",    1600, 80, "jpg"),   # 元jpgを上書き
    ("_th", 400,  75, "jpg"),
]

VALID_INPUT_EXT = {".jpg", ".jpeg", ".png"}


def resize_long_edge(im: Image.Image, max_edge: int) -> Image.Image:
    w, h = im.size
    long_edge = max(w, h)
    if long_edge <= max_edge:
        return im
    scale = max_edge / long_edge
    new_size = (max(1, int(round(w * scale))), max(1, int(round(h * scale))))
    return im.resize(new_size, Image.LANCZOS)


def process_one(src_path_str: str) -> tuple[str, bool, str]:
    src_path = Path(src_path_str)
    name_no_ext = src_path.stem
    parent = src_path.parent
    try:
        with Image.open(src_path) as im:
            im = ImageOps.exif_transpose(im)
            if im.mode in ("RGBA", "LA"):
                # WebPは透過OK、JPEGは白背景に貼り付け
                rgba = im.convert("RGBA")
            else:
                rgba = im.convert("RGB")

            for suffix, max_edge, quality, fmt in VARIANTS:
                if fmt == "webp":
                    base = rgba
                    out_path = parent / f"{name_no_ext}{suffix}.webp"
                else:  # jpg
                    if rgba.mode == "RGBA":
                        bg = Image.new("RGB", rgba.size, (255, 255, 255))
                        bg.paste(rgba, mask=rgba.split()[-1])
                        base = bg
                    else:
                        base = rgba.convert("RGB")
                    out_path = parent / f"{name_no_ext}{suffix}.jpg"

                resized = resize_long_edge(base, max_edge)
                save_kwargs = {}
                if fmt == "webp":
                    save_kwargs = {"quality": quality, "method": 6}
                    resized.save(out_path, "WEBP", **save_kwargs)
                else:
                    save_kwargs = {
                        "quality": quality,
                        "optimize": True,
                        "progressive": True,
                    }
                    resized.save(out_path, "JPEG", **save_kwargs)

        return (src_path.name, True, "")
    except (UnidentifiedImageError, OSError, ValueError) as e:
        return (src_path.name, False, f"{type(e).__name__}: {e}")
    except Exception as e:  # safety
        return (src_path.name, False, f"{type(e).__name__}: {e}")


def main() -> int:
    if not GALLERY_DIR.exists():
        print(f"NOT FOUND: {GALLERY_DIR}", file=sys.stderr)
        return 2

    sources = []
    for p in sorted(GALLERY_DIR.iterdir()):
        if not p.is_file():
            continue
        # スキップ: 既に派生 (_md / _th)
        if p.stem.endswith("_md") or p.stem.endswith("_th"):
            continue
        # スキップ: WebPは入力としては扱わない（webpが派生で出てくるため）
        if p.suffix.lower() == ".webp":
            continue
        if p.suffix.lower() not in VALID_INPUT_EXT:
            continue
        sources.append(str(p))

    print(f"[optimize-gallery] target dir: {GALLERY_DIR}")
    print(f"[optimize-gallery] {len(sources)} source images")
    if not sources:
        return 0

    workers = min(8, os.cpu_count() or 4)
    print(f"[optimize-gallery] using {workers} workers")
    start = time.time()

    successes: list[str] = []
    failures: list[tuple[str, str]] = []
    with ProcessPoolExecutor(max_workers=workers) as ex:
        futs = {ex.submit(process_one, s): s for s in sources}
        done_count = 0
        for fut in as_completed(futs):
            name, ok, err = fut.result()
            done_count += 1
            if ok:
                successes.append(name)
                print(f"  [{done_count}/{len(sources)}] OK   {name}")
            else:
                failures.append((name, err))
                print(f"  [{done_count}/{len(sources)}] FAIL {name}  -> {err}")
            sys.stdout.flush()

    elapsed = time.time() - start
    print()
    print(f"[optimize-gallery] done in {elapsed:.1f}s")
    print(f"  success: {len(successes)} / {len(sources)}")
    if failures:
        print(f"  failed : {len(failures)}")
        for name, err in failures:
            print(f"    - {name}: {err}")
    return 0 if not failures else 1


if __name__ == "__main__":
    sys.exit(main())
