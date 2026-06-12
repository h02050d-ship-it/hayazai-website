# -*- coding: utf-8 -*-
"""images/blog/<slug>.jpg が存在する記事に画像を配線する。

- og:image / twitter:image を https://hayazai.com/images/blog/<slug>.jpg に変更
- 記事冒頭（.article-meta の直後）にアイキャッチ<img>を挿入（未挿入の場合のみ）
何度実行しても安全（冪等）。画像が無い記事はスキップ。
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
BLOG = ROOT / "blog"
IMG = ROOT / "images" / "blog"

changed, skipped = [], []
for html in sorted(BLOG.glob("*.html")):
    slug = html.stem
    img = IMG / f"{slug}.jpg"
    if not img.exists():
        skipped.append(slug)
        continue
    url = f"https://hayazai.com/images/blog/{slug}.jpg"
    text = html.read_text(encoding="utf-8")
    orig = text
    text = re.sub(
        r'(<meta property="og:image" content=")[^"]*(")',
        rf'\g<1>{url}\g<2>', text)
    text = re.sub(
        r'(<meta name="twitter:image" content=")[^"]*(")',
        rf'\g<1>{url}\g<2>', text)
    if "article-eyecatch" not in text:
        m = re.search(r'<div class="article-meta">.*?</div>', text, re.S)
        if m:
            eyecatch = (
                f'\n  <img class="article-eyecatch" src="../images/blog/{slug}.jpg" alt=""'
                ' loading="lazy" style="width:100%;border-radius:10px;margin-bottom:36px;">'
            )
            text = text[:m.end()] + eyecatch + text[m.end():]
        else:
            print(f"[WARN] {slug}: article-meta が見つからず本文画像は未挿入")
    if text != orig:
        html.write_text(text, encoding="utf-8")
        changed.append(slug)

print("[CHANGED] " + " ".join(changed) if changed else "[CHANGED] none")
print("[NO-IMAGE-SKIP] " + " ".join(skipped) if skipped else "")
