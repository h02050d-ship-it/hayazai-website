<?php
// =====================================================
// 林材木店 施工事例ギャラリー
// /gallery/ フォルダ内の画像を自動読み込み
// =====================================================

$gallery_dir = __DIR__ . '/gallery/';
$allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

// 画像ファイル一覧取得
$images = [];
if (is_dir($gallery_dir)) {
    $files = scandir($gallery_dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext)) continue;

        // ファイル名からキャプション生成（拡張子を除いたファイル名をラベルに）
        $caption = pathinfo($file, PATHINFO_FILENAME);
        // アンダースコア・ハイフンをスペースに変換
        $caption = str_replace(['_', '-'], ' ', $caption);

        $images[] = [
            'file'    => $file,
            'caption' => $caption,
            'mtime'   => filemtime($gallery_dir . $file),
        ];
    }
    // 新しいファイル順
    usort($images, fn($a, $b) => $b['mtime'] - $a['mtime']);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>施工事例 | 林材木店 無垢桧フローリング・羽目板</title>
  <meta name="description" content="林材木店の桧フローリング・羽目板を使った施工事例をご紹介。住宅・店舗・公共施設など様々な施工実績。">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Serif+JP:wght@400;600;700&family=Noto+Sans+JP:wght@300;400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="css/style.css">
  <style>
    .gallery-layout {
      max-width: 1100px;
      margin: 0 auto;
      padding: 40px 40px 80px;
    }

    /* lightbox */
    .lightbox {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.88);
      z-index: 500;
      align-items: center;
      justify-content: center;
    }

    .lightbox.open {
      display: flex;
    }

    .lightbox img {
      max-width: 90vw;
      max-height: 85vh;
      border-radius: 4px;
      object-fit: contain;
    }

    .lightbox-caption {
      position: absolute;
      bottom: 24px;
      left: 50%;
      transform: translateX(-50%);
      color: rgba(255,255,255,0.85);
      font-size: 0.88rem;
      background: rgba(0,0,0,0.5);
      padding: 6px 16px;
      border-radius: 20px;
      white-space: nowrap;
    }

    .lightbox-close {
      position: absolute;
      top: 20px;
      right: 24px;
      color: #fff;
      font-size: 1.8rem;
      cursor: pointer;
      background: none;
      border: none;
      line-height: 1;
    }

    .lightbox-prev,
    .lightbox-next {
      position: absolute;
      top: 50%;
      transform: translateY(-50%);
      background: rgba(255,255,255,0.15);
      color: #fff;
      border: none;
      border-radius: 50%;
      width: 48px;
      height: 48px;
      font-size: 1.2rem;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background 0.2s;
    }

    .lightbox-prev { left: 16px; }
    .lightbox-next { right: 16px; }
    .lightbox-prev:hover,
    .lightbox-next:hover { background: rgba(255,255,255,0.3); }

    .upload-notice {
      background: var(--wood-pale);
      border: 2px dashed var(--wood-light);
      border-radius: 8px;
      padding: 48px;
      text-align: center;
      color: var(--text-mid);
    }

    .upload-notice p { font-size: 0.9rem; line-height: 1.9; }
    .upload-notice code {
      background: #fff;
      padding: 2px 8px;
      border-radius: 4px;
      font-family: monospace;
      font-size: 0.88rem;
      color: var(--wood-dark);
    }
  </style>
</head>
<body>

<div id="site-header"></div>

<div class="page-hero">
  <h1>施工事例</h1>
  <p>桧フローリング・羽目板の施工実績をご紹介します</p>
</div>

<nav class="breadcrumb">
  <a href="index.html">トップ</a>
  <span class="sep">›</span>
  <span>施工事例</span>
</nav>

<div class="gallery-layout">

<?php if (empty($images)): ?>
  <div class="upload-notice">
    <div style="width:72px;height:72px;border-radius:50%;background:#f0e6d4;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
      <svg width="36" height="36" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M23 19C23 19.5304 22.7893 20.0391 22.4142 20.4142C22.0391 20.7893 21.5304 21 21 21H3C2.46957 21 1.96086 20.7893 1.58579 20.4142C1.21071 20.0391 1 19.5304 1 19V8C1 7.46957 1.21071 6.96086 1.58579 6.58579C1.96086 6.21071 2.46957 6 3 6H7L9 3H15L17 6H21C21.5304 6 22.0391 6.21071 22.4142 6.58579C22.7893 6.96086 23 7.46957 23 8V19Z" stroke="#7a5c3e" stroke-width="1.8" stroke-linejoin="round"/>
        <circle cx="12" cy="13" r="4" stroke="#7a5c3e" stroke-width="1.8"/>
      </svg>
    </div>
    <h2 style="font-size:1.1rem;color:var(--wood-dark);margin-bottom:12px;">施工写真を追加してください</h2>
    <p>
      サーバーの <code>/gallery/</code> フォルダに<br>
      JPG・PNG・WebP形式の画像を入れると自動的にここに表示されます。<br><br>
      ファイル名がキャプションになります（例：<code>リビング_磐田市S様邸.jpg</code>）
    </p>
    <a href="contact.html" class="btn btn-primary" style="margin-top:24px;">お問い合わせ</a>
  </div>

<?php else: ?>

  <p style="color:var(--text-light);font-size:0.85rem;margin-bottom:28px;">
    <?= count($images) ?>件の施工事例
  </p>

  <div class="gallery-grid" id="gallery-grid">
    <?php foreach ($images as $i => $img): ?>
    <div class="gallery-item" style="cursor:pointer;" onclick="openLightbox(<?= $i ?>)">
      <img
        src="gallery/<?= htmlspecialchars($img['file']) ?>"
        alt="<?= htmlspecialchars($img['caption']) ?>"
        loading="lazy">
      <div class="gallery-caption"><?= htmlspecialchars($img['caption']) ?></div>
    </div>
    <?php endforeach; ?>
  </div>

<?php endif; ?>

  <div style="margin-top:60px;padding-top:48px;border-top:1px solid #e8ddd0;text-align:center;">
    <h2 style="font-family:'Noto Serif JP',serif;font-size:1.3rem;color:var(--wood-dark);margin-bottom:12px;">
      施工事例を提供してください
    </h2>
    <p style="color:var(--text-mid);font-size:0.88rem;margin-bottom:24px;">
      弊社商品をご使用いただいた施工写真をお送りいただけますと、<br>こちらのページにて掲載させていただきます。
    </p>
    <a href="contact.html" class="btn btn-primary">写真を送る・お問い合わせ</a>
  </div>
</div>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" onclick="closeLightbox(event)">
  <button class="lightbox-close" onclick="closeLightbox()">✕</button>
  <button class="lightbox-prev" onclick="event.stopPropagation();moveLightbox(-1)">‹</button>
  <img id="lightbox-img" src="" alt="">
  <button class="lightbox-next" onclick="event.stopPropagation();moveLightbox(1)">›</button>
  <div class="lightbox-caption" id="lightbox-caption"></div>
</div>

<div id="site-footer"></div>

<script src="data/products.js"></script>
<script src="js/cart.js"></script>
<script src="js/components.js"></script>
<script>
const galleryData = <?= json_encode(array_values($images)) ?>;
let currentIndex = 0;

function openLightbox(index) {
  currentIndex = index;
  updateLightbox();
  document.getElementById('lightbox').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeLightbox(e) {
  if (e && e.target !== document.getElementById('lightbox')) return;
  document.getElementById('lightbox').classList.remove('open');
  document.body.style.overflow = '';
}

function moveLightbox(delta) {
  currentIndex = (currentIndex + delta + galleryData.length) % galleryData.length;
  updateLightbox();
}

function updateLightbox() {
  const item = galleryData[currentIndex];
  if (!item) return;
  document.getElementById('lightbox-img').src = 'gallery/' + item.file;
  document.getElementById('lightbox-img').alt = item.caption;
  document.getElementById('lightbox-caption').textContent = item.caption;
}

// キーボード操作
document.addEventListener('keydown', e => {
  if (!document.getElementById('lightbox').classList.contains('open')) return;
  if (e.key === 'ArrowLeft')  moveLightbox(-1);
  if (e.key === 'ArrowRight') moveLightbox(1);
  if (e.key === 'Escape')     document.getElementById('lightbox').classList.remove('open');
});
</script>
</body>
</html>
