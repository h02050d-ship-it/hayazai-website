// =====================================================
// 共通ヘッダー・フッター生成
// =====================================================

function renderHeader() {
  const html = `
  <header class="site-header">
    <div class="header-inner">
      <a href="index.html" class="site-logo">
        林材木店
        <span>無垢桧フローリング・羽目板専門</span>
      </a>
      <nav class="header-nav">
        <a href="products.html">商品一覧</a>
        <a href="gallery.html">施工事例</a>
        <a href="faq.html">お悩み解決</a>
        <a href="blog.html">ブログ</a>
        <a href="contact.html">お問い合わせ</a>
        <a href="sample.html" class="nav-sample-link">🎁 無料サンプル</a>
      </nav>
      <div class="header-actions">
        <button class="hamburger" id="hamburger" aria-label="メニューを開く">
          <span></span><span></span><span></span>
        </button>
      </div>
    </div>
  </header>

  <div class="mobile-nav" id="mobile-nav">
    <div class="mobile-nav-inner">
      <button class="mobile-nav-close" id="mobile-close">✕</button>
      <a href="index.html">トップ</a>
      <a href="products.html">商品一覧</a>
      <a href="gallery.html">施工事例</a>
      <a href="faq.html">お悩み解決</a>
      <a href="blog.html">ブログ</a>
      <a href="contact.html">お問い合わせ</a>
      <a href="sample.html">🎁 無料サンプル請求</a>
    </div>
  </div>`;

  const el = document.getElementById('site-header');
  if (el) el.outerHTML = html;
  else document.body.insertAdjacentHTML('afterbegin', html);
}

function renderFooter() {
  const html = `
  <footer class="site-footer">
    <div class="footer-inner">
      <div class="footer-top">
        <div>
          <div class="footer-logo">林材木店</div>
          <p class="footer-desc">
            創業昭和43年。静岡県磐田市の自社工場で、天然乾燥・超仕上げにこだわった
            無垢桧フローリング・羽目板を製造し、全国へお届けしています。
          </p>
          <div class="footer-contact">
            <a href="tel:0538582395">📞 0538-58-2395（平日 9:00〜17:00）</a>
            <a href="mailto:info@hayazai.com">✉ info@hayazai.com</a>
            <a href="https://lin.ee/469dvgvz" target="_blank" rel="noopener">💬 LINE公式アカウント</a>
          </div>
        </div>
        <div class="footer-nav">
          <h4>商品・サービス</h4>
          <ul>
            <li><a href="products.html?cat=flooring15">桧フローリング 15mm</a></li>
            <li><a href="products.html?cat=flooring12">桧フローリング 12mm</a></li>
            <li><a href="products.html?cat=panel">桧羽目板</a></li>
            <li><a href="products.html?cat=sale">お買い得品</a></li>
            <li><a href="sample.html">🎁 無料サンプル請求</a></li>
          </ul>
        </div>
        <div class="footer-nav">
          <h4>情報・サポート</h4>
          <ul>
            <li><a href="gallery.html">施工事例</a></li>
            <li><a href="blog.html">ブログ・コラム</a></li>
            <li><a href="faq.html">よくある質問</a></li>
            <li><a href="contact.html">お問い合わせ</a></li>
          </ul>
        </div>
      </div>
      <div class="footer-bottom">
        <span>〒437-0224 静岡県磐田市大久保1234</span>
        <span>© ${new Date().getFullYear()} 林材木店 All Rights Reserved.</span>
      </div>
    </div>
  </footer>`;

  const el = document.getElementById('site-footer');
  if (el) el.outerHTML = html;
  else document.body.insertAdjacentHTML('beforeend', html);
}

function renderLineFloat() {
  const html = `
  <div class="line-float" id="line-float">
    <a href="https://lin.ee/469dvgvz" target="_blank" rel="noopener" class="line-float-btn" aria-label="LINEで相談する">
      <svg viewBox="0 0 40 40" width="26" height="26" xmlns="http://www.w3.org/2000/svg">
        <rect width="40" height="40" rx="10" fill="#06C755"/>
        <path d="M33 19.2C33 13.6 27.4 9 20.5 9S8 13.6 8 19.2c0 5 4.5 9.2 10.5 10-.4 1-.8 2.6-.9 3-.1.4.2.4.4.3.2-.1 2.6-1.7 3.7-2.4.3 0 .5.1.8.1C28.5 30.2 33 25.2 33 19.2z" fill="white"/>
        <path d="M16 21.2h-1.5v-4.4H16v4.4zm4.2 0h-1.4v-2.4l-1.6 2.4h-1.3v-4.4h1.4v2.4l1.6-2.4h1.3v4.4zm3.3.1c-1.5 0-2.5-.9-2.5-2.3s1-2.3 2.5-2.3c.7 0 1.2.2 1.6.5l-.7.9c-.2-.2-.5-.3-.9-.3-.6 0-1 .5-1 1.2s.4 1.2 1 1.2c.4 0 .7-.1.9-.3l.7.9c-.4.3-.9.5-1.6.5zm4.2 0h-2.9v-4.4h2.9v1.1h-1.5v.6h1.4v1.1h-1.4v.5h1.5v1.1z" fill="#06C755"/>
      </svg>
      <span>LINE相談</span>
    </a>
  </div>`;
  document.body.insertAdjacentHTML('beforeend', html);

  // スクロールで表示
  window.addEventListener('scroll', () => {
    const el = document.getElementById('line-float');
    if (el) el.classList.toggle('visible', window.scrollY > 300);
  });
}

document.addEventListener('DOMContentLoaded', () => {
  renderHeader();
  renderFooter();
  renderLineFloat();
});
