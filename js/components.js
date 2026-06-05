// =====================================================
// 共通ヘッダー・フッター生成
// =====================================================

function renderHeader() {
  const html = `
  <header class="site-header">
    <div class="header-inner">
      <a href="/" class="site-logo" aria-label="林材木店 トップへ">
        <span class="logo-name">林材木店</span>
        <span class="logo-bar" aria-hidden="true"></span>
        <span class="logo-tag">無垢桧フローリング・羽目板専門</span>
      </a>
      <nav class="header-nav">
        <a href="/products.html">商品一覧</a>
        <a href="/gallery.html">施工事例</a>
        <a href="/faq.html">お悩み解決</a>
        <a href="/blog.html">ブログ</a>
        <a href="/order.html">お見積もり</a>
        <a href="/markets.html">取引市場一覧</a>
        <a href="/contact.html">お問い合わせ</a>
        <a href="https://lin.ee/tGamtbg" target="_blank" rel="noopener" class="nav-line-link" aria-label="LINEで相談">
          <svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M12 3C6.48 3 2 6.76 2 11.4c0 2.93 1.97 5.5 4.94 6.96-.18 1.05-.71 3-.81 3.47-.12.59.22.59.46.43.19-.12 3.04-2.07 4.27-2.92.37.05.74.07 1.14.07 5.52 0 10-3.76 10-8.4S17.52 3 12 3z"/></svg>
          LINEで相談
        </a>
        <a href="/sample.html" class="nav-sample-link">無料サンプル</a>
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
      <a href="/">トップ</a>
      <a href="/products.html">商品一覧</a>
      <a href="/gallery.html">施工事例</a>
      <a href="/faq.html">お悩み解決</a>
      <a href="/blog.html">ブログ</a>
      <a href="/order.html">お見積もり</a>
      <a href="/markets.html">取引市場一覧</a>
      <a href="/contact.html">お問い合わせ</a>
      <a href="https://lin.ee/tGamtbg" target="_blank" rel="noopener" class="mobile-line-link">💬 LINEで相談</a>
      <a href="/sample.html">無料サンプル請求</a>
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
            創業昭和43年。自社工場で、天然乾燥・超仕上げにこだわった
            無垢桧フローリング・羽目板を製造し、全国へお届けしています。
          </p>
          <div class="footer-contact">
            <a href="tel:0538582395">📞 0538-58-2395（平日 9:00〜17:00）</a>
            <a href="mailto:info@hayazai.com">✉ info@hayazai.com</a>
            <a href="https://lin.ee/tGamtbg" target="_blank" rel="noopener">💬 LINEで相談（友だち追加）</a>
          </div>
        </div>
        <div class="footer-nav">
          <h4>商品・サービス</h4>
          <ul>
            <li><a href="/products.html?cat=flooring15">桧フローリング 15mm</a></li>
            <li><a href="/products.html?cat=flooring12">桧フローリング 12mm</a></li>
            <li><a href="/products.html?cat=panel">桧羽目板</a></li>
            <li><a href="/products.html?cat=sale">お買い得品</a></li>
            <li><a href="/sample.html">🎁 無料サンプル請求</a></li>
          </ul>
        </div>
        <div class="footer-nav">
          <h4>情報・サポート</h4>
          <ul>
            <li><a href="/gallery.html">施工事例</a></li>
            <li><a href="/blog.html">ブログ・コラム</a></li>
            <li><a href="/faq.html">よくある質問</a></li>
            <li><a href="/shipping.html">送料のご案内</a></li>
            <li><a href="/markets.html">木材市場一覧</a></li>
            <li><a href="/contact.html">お問い合わせ</a></li>
          </ul>
        </div>
        <div class="footer-nav">
          <h4>会社情報</h4>
          <ul>
            <li><a href="/company.html">会社概要</a></li>
            <li><a href="/tokushoho.html">特定商取引法に基づく表記</a></li>
            <li><a href="/privacy.html">プライバシーポリシー</a></li>
          </ul>
        </div>
      </div>
      <div class="footer-bottom">
        <span>〒437-1203 静岡県磐田市福田5490-47</span>
        <span>© ${new Date().getFullYear()} 株式会社林材木店 All Rights Reserved.</span>
      </div>
    </div>
  </footer>`;

  const el = document.getElementById('site-footer');
  if (el) el.outerHTML = html;
  else document.body.insertAdjacentHTML('beforeend', html);
}

function renderFloatingLineButton() {
  const html = `
  <a href="https://lin.ee/tGamtbg" target="_blank" rel="noopener"
     class="floating-line-btn" aria-label="LINEで友だち追加・相談">
    <svg viewBox="0 0 24 24" width="26" height="26" fill="#fff" aria-hidden="true">
      <path d="M12 3C6.48 3 2 6.76 2 11.4c0 2.93 1.97 5.5 4.94 6.96-.18 1.05-.71 3-.81 3.47-.12.59.22.59.46.43.19-.12 3.04-2.07 4.27-2.92.37.05.74.07 1.14.07 5.52 0 10-3.76 10-8.4S17.52 3 12 3z"/>
    </svg>
    <span class="floating-line-label">LINEで<br>相談</span>
  </a>`;
  document.body.insertAdjacentHTML('beforeend', html);
}

document.addEventListener('DOMContentLoaded', () => {
  renderHeader();
  renderFooter();
  renderFloatingLineButton();
});
