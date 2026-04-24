"""
Hero image processor for hayazai.com
Source: C:/Users/hayaz/Downloads/ChatGPT Image 2026年4月24日 14_04_08.png
Generates:
  - images/hero_baby_2026.jpg (1920w, q85)
  - images/hero_baby_2026.webp (1920w, q80)
  - images/hero_baby_2026_mobile.jpg (720w)
  - images/hero_baby_2026_mobile.webp (720w)
  - images/og/og-image.jpg (1200x630, center crop, q85)
"""
from PIL import Image
from pathlib import Path

SRC = Path("C:/Users/hayaz/Downloads/ChatGPT Image 2026年4月24日 14_04_08.png")
OUT = Path("C:/Users/hayaz/AppData/Local/Temp/hayazai_fix/hayazai-website/images")

def resize_w(img, target_w):
    ratio = target_w / img.width
    return img.resize((target_w, int(img.height * ratio)), Image.LANCZOS)

def center_crop(img, tw, th):
    iw, ih = img.size
    src_ratio = iw / ih
    tgt_ratio = tw / th
    if src_ratio > tgt_ratio:
        new_w = int(ih * tgt_ratio)
        x0 = (iw - new_w) // 2
        img = img.crop((x0, 0, x0 + new_w, ih))
    else:
        new_h = int(iw / tgt_ratio)
        y0 = (ih - new_h) // 2
        img = img.crop((0, y0, iw, y0 + new_h))
    return img.resize((tw, th), Image.LANCZOS)

def main():
    im = Image.open(SRC).convert("RGB")
    print(f"Source: {im.size}")

    # Desktop 1920w
    big = resize_w(im, 1920)
    big.save(OUT / "hero_baby_2026.jpg", "JPEG", quality=85, optimize=True, progressive=True)
    big.save(OUT / "hero_baby_2026.webp", "WEBP", quality=80, method=6)
    print(f"1920w jpg: {(OUT / 'hero_baby_2026.jpg').stat().st_size // 1024} KB")
    print(f"1920w webp: {(OUT / 'hero_baby_2026.webp').stat().st_size // 1024} KB")

    # Mobile 720w
    small = resize_w(im, 720)
    small.save(OUT / "hero_baby_2026_mobile.jpg", "JPEG", quality=85, optimize=True, progressive=True)
    small.save(OUT / "hero_baby_2026_mobile.webp", "WEBP", quality=80, method=6)
    print(f"720w jpg: {(OUT / 'hero_baby_2026_mobile.jpg').stat().st_size // 1024} KB")
    print(f"720w webp: {(OUT / 'hero_baby_2026_mobile.webp').stat().st_size // 1024} KB")

    # OGP 1200x630
    ogp = center_crop(im, 1200, 630)
    (OUT / "og").mkdir(parents=True, exist_ok=True)
    ogp.save(OUT / "og" / "og-image.jpg", "JPEG", quality=85, optimize=True, progressive=True)
    print(f"og-image jpg: {(OUT / 'og' / 'og-image.jpg').stat().st_size // 1024} KB")

if __name__ == "__main__":
    main()
