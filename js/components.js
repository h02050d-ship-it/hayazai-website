// =====================================================
// Google Analytics 4（全ページ共通・components.js経由で一括導入）
// =====================================================
(function () {
  var s = document.createElement('script');
  s.async = true;
  s.src = 'https://www.googletagmanager.com/gtag/js?id=G-EQLK2295RN';
  document.head.appendChild(s);
  window.dataLayer = window.dataLayer || [];
  window.gtag = function () { dataLayer.push(arguments); };
  gtag('js', new Date());
  gtag('config', 'G-EQLK2295RN');

  // LINEリンククリック計測
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href*="line.me"], a[href*="lin.ee"]');
    if (a) gtag('event', 'line_click', { page_path: location.pathname });
  });

  // フォーム送信計測（type別: sample=サンプル請求 / quote=法人見積もり / その他=問い合わせ）
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || f.tagName !== 'FORM') return;
    var typeInput = f.querySelector('input[name="type"], select[name="type"]');
    var type = (typeInput && typeInput.value) || 'contact';
    var eventName = type === 'sample' ? 'sample_request'
                  : type === 'quote'  ? 'quote_request'
                  : 'contact_submit';
    gtag('event', eventName, { page_path: location.pathname });
  });
})();

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
        <a href="/markets.html">取扱店一覧</a>
        <a href="/gallery.html">施工事例</a>
        <a href="/faq.html">お悩み解決</a>
        <a href="/blog.html">ブログ</a>
        <a href="/order.html">お見積もり</a>
        <a href="/contact.html">お問い合わせ</a>
        <a href="https://line.me/R/ti/p/@352ngeni" target="_blank" rel="noopener" class="nav-line-link" aria-label="LINEで相談">
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
      <a href="/markets.html">取扱店一覧</a>
      <a href="/gallery.html">施工事例</a>
      <a href="/faq.html">お悩み解決</a>
      <a href="/blog.html">ブログ</a>
      <a href="/order.html">お見積もり</a>
      <a href="/contact.html">お問い合わせ</a>
      <a href="https://line.me/R/ti/p/@352ngeni" target="_blank" rel="noopener" class="mobile-line-link">💬 LINEで相談</a>
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
            <a href="https://line.me/R/ti/p/@352ngeni" target="_blank" rel="noopener">💬 LINEで相談（友だち追加）</a>
          </div>
        </div>
        <div class="footer-nav">
          <h4>商品・サービス</h4>
          <ul>
            <li><a href="/products.html?cat=flooring15">桧フローリング 15mm</a></li>
            <li><a href="/products.html?cat=flooring12">桧フローリング 12mm</a></li>
            <li><a href="/products.html?cat=panel">桧羽目板</a></li>
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
            <li><a href="/markets.html">取扱店一覧（市場・代理店）</a></li>
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
  <a href="https://line.me/R/ti/p/@352ngeni" target="_blank" rel="noopener"
     class="floating-line-banner" aria-label="LINEで友だち追加・相談">
    <span class="flb-badge">無料相談OK</span>
    <span class="flb-icon">
      <svg viewBox="0 0 36 36" fill="none" aria-hidden="true">
        <path fill="#fff" d="M18 6C11.4 6 6 10.3 6 15.6c0 4.75 4.3 8.73 10.1 9.48.39.08.92.26 1.06.6.12.3.08.78.04 1.09l-.17 1.03c-.05.3-.24 1.19 1.04.65 1.28-.54 6.9-4.06 9.41-6.96C29.2 19.6 30 17.7 30 15.6 30 10.3 24.6 6 18 6Z"/>
        <path fill="#06C755" d="M14.2 13.1h-1.05c-.16 0-.29.13-.29.29v4.52c0 .16.13.29.29.29h1.05c.16 0 .29-.13.29-.29v-4.52c0-.16-.13-.29-.29-.29Zm9.36 0h-1.05c-.16 0-.29.13-.29.29v2.69l-2.07-2.8a.3.3 0 0 0-.05-.06l-.03-.02h-.02l-.02-.01h-1.13c-.16 0-.29.13-.29.29v4.52c0 .16.13.29.29.29h1.05c.16 0 .29-.13.29-.29v-2.69l2.08 2.81c.01.02.03.04.05.05h.02l.02.01h1.13c.16 0 .29-.13.29-.29v-4.52c0-.16-.13-.29-.29-.29Zm-12.04 3.74H9.5v-3.45c0-.16-.13-.29-.29-.29H8.16c-.16 0-.29.13-.29.29v4.52c0 .08.03.15.08.2.05.05.12.08.2.08h2.91c.16 0 .29-.13.29-.29v-1.05c0-.16-.13-.29-.29-.29Zm6.34-2.4c.16 0 .29-.13.29-.29v-1.05c0-.16-.13-.29-.29-.29h-2.91c-.08 0-.15.03-.2.08a.29.29 0 0 0-.08.2v4.52c0 .08.03.15.08.2.05.05.12.08.2.08h2.91c.16 0 .29-.13.29-.29v-1.05c0-.16-.13-.29-.29-.29h-1.57v-.6h1.57c.16 0 .29-.13.29-.29v-1.05c0-.16-.13-.29-.29-.29h-1.57v-.6h1.57Z"/>
      </svg>
    </span>
    <span class="flb-copy">桧のこと<br>何でも<br>ご相談</span>
    <span class="flb-cta">友だち追加 ›</span>
    <button class="flb-close" aria-label="閉じる" onclick="event.preventDefault();event.stopPropagation();this.closest('.floating-line-banner').style.display='none';sessionStorage.setItem('flb_hidden','1');">×</button>
  </a>`;
  if (sessionStorage.getItem('flb_hidden') !== '1') {
    document.body.insertAdjacentHTML('beforeend', html);
  }
}

function loadAiChat() {
  const s = document.createElement('script');
  const base = location.pathname.indexOf('/blog/') === 0 ? '../' : '';
  s.src = base + 'js/ai-chat.js?v=7';
  s.defer = true;
  document.body.appendChild(s);
}

document.addEventListener('DOMContentLoaded', () => {
  renderHeader();
  renderFooter();
  renderFloatingLineButton();
  loadAiChat();
});
