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
        <a href="order.html">お見積もり</a>
        <a href="markets.html">取引市場一覧</a>
        <a href="contact.html">お問い合わせ</a>
        <a href="sample.html" class="nav-sample-link">無料サンプル</a>
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
      <a href="order.html">お見積もり</a>
      <a href="markets.html">取引市場一覧</a>
      <a href="contact.html">お問い合わせ</a>
      <a href="sample.html">無料サンプル請求</a>
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
            <li><a href="shipping.html">送料のご案内</a></li>
            <li><a href="markets.html">木材市場一覧</a></li>
            <li><a href="contact.html">お問い合わせ</a></li>
          </ul>
        </div>
        <div class="footer-nav">
          <h4>会社情報</h4>
          <ul>
            <li><a href="company.html">会社概要</a></li>
            <li><a href="tokushoho.html">特定商取引法に基づく表記</a></li>
            <li><a href="privacy.html">プライバシーポリシー</a></li>
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

document.addEventListener('DOMContentLoaded', () => {
  renderHeader();
  renderFooter();
});
