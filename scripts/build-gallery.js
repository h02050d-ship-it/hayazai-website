#!/usr/bin/env node
// =============================================================
// images/gallery/ 内の画像ファイルをスキャンして
// data/gallery.json を生成する。
// gallery.html は既存のキュレーション済みリストを使うため、
// このJSONは「未掲載画像があれば検出する」ための補助データ。
// =============================================================

const fs = require('fs');
const path = require('path');

const ROOT = path.resolve(__dirname, '..');
const GALLERY_DIR = path.join(ROOT, 'images', 'gallery');
const OUT_FILE = path.join(ROOT, 'data', 'gallery.json');

const ALLOWED_EXT = new Set(['.jpg', '.jpeg', '.png', '.webp', '.gif']);

function captionFromFilename(file) {
  const base = path.basename(file, path.extname(file));
  return base.replace(/[_-]/g, ' ');
}

function main() {
  if (!fs.existsSync(GALLERY_DIR)) {
    console.warn(`[build-gallery] gallery dir not found: ${GALLERY_DIR}`);
    fs.mkdirSync(path.dirname(OUT_FILE), { recursive: true });
    fs.writeFileSync(OUT_FILE, JSON.stringify({ generatedAt: new Date().toISOString(), images: [] }, null, 2));
    return;
  }

  const files = fs.readdirSync(GALLERY_DIR);
  const images = [];
  for (const file of files) {
    const ext = path.extname(file).toLowerCase();
    if (!ALLOWED_EXT.has(ext)) continue;
    const stat = fs.statSync(path.join(GALLERY_DIR, file));
    images.push({
      file,
      path: `images/gallery/${file}`,
      caption: captionFromFilename(file),
      mtime: stat.mtimeMs,
      size: stat.size,
    });
  }
  images.sort((a, b) => b.mtime - a.mtime);

  const out = {
    generatedAt: new Date().toISOString(),
    count: images.length,
    images,
  };

  fs.mkdirSync(path.dirname(OUT_FILE), { recursive: true });
  fs.writeFileSync(OUT_FILE, JSON.stringify(out, null, 2));
  console.log(`[build-gallery] wrote ${OUT_FILE} (${images.length} images)`);
}

main();
