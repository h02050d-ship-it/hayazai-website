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

  // 自前アクセス計測（js/hz.js・gtagイベントもミラーする。adblock対策で名前は hz）
  var hz = document.createElement('script');
  hz.src = '/js/hz.js?v=3';
  document.head.appendChild(hz);

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
            <li><a href="/voice.html">お客様の声</a></li>
            <li><a href="/blog.html">ブログ・コラム</a></li>
            <li><a href="/faq.html">よくある質問</a></li>
            <li><a href="/shipping.html">送料のご案内</a></li>
            <li><a href="/markets.html">取扱店一覧（市場・代理店）</a></li>
            <li><a href="/download.html">取扱店様専用（販促チラシDL）</a></li>
            <li><a href="/dealer.html">取扱店募集（市場・販売店さま）</a></li>
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

// ブログ記事に「商品への導線」を入れる（記事は最後まで読まれるが商品ページへ進まないため）
// 記事中: 3つ目のh2の直前に参考価格＋サンプル/枚数計算/価格表。末尾: 既存CTAに枚数計算・取扱店ボタンを追加
function renderBlogCta() {
  if (location.pathname.indexOf('/blog/') !== 0) return;
  const body = document.querySelector('.article-body');
  if (!body || document.querySelector('.blog-cta-mid')) return;
  const css = document.createElement('style');
  css.textContent = [
    '.blog-cta-mid{background:linear-gradient(135deg,#f8f2ea,#edf5ed);border:2px solid var(--wood-mid,#a8865c);border-radius:12px;padding:20px 22px;margin:36px 0}',
    '.blog-cta-mid .bcm-h{font-weight:700;color:var(--wood-dark,#3d2b1f);font-size:1rem;margin:0 0 6px}',
    '.blog-cta-mid p{font-size:0.86rem;color:var(--text-mid,#5a4a3a);line-height:1.9;margin:0 0 14px}',
    '.blog-cta-mid .bcm-btns{display:flex;gap:10px;flex-wrap:wrap}',
    '.blog-cta-mid .bcm-btns a{display:inline-block;padding:11px 18px;border-radius:8px;font-size:0.88rem;font-weight:700;text-decoration:none;line-height:1.3}',
    '.blog-cta-mid .bcm-btns .p{background:var(--wood-dark,#3d2b1f);color:#fff}',
    '.blog-cta-mid .bcm-btns .s{background:#fff;color:var(--wood-dark,#3d2b1f);border:1.5px solid var(--wood-mid,#a8865c)}',
    '@media(max-width:600px){.blog-cta-mid .bcm-btns a{flex:1 1 100%;text-align:center}}'
  ].join('\n');
  document.head.appendChild(css);
  const html =
    '<aside class="blog-cta-mid">' +
      '<div class="bcm-h">📐 この記事の床材＝国産無垢 桧フローリング 15×108mm（自社工場・超仕上げ）</div>' +
      '<p>参考価格 節有 <strong>¥9,240／束（8枚・約1.6㎡）〜</strong> 税込（メーカー希望小売価格・取扱店では多くの場合これより割安）。<br>桧の質感・香りは写真では伝わりません。まずは無料サンプルで実物をご確認ください。</p>' +
      '<div class="bcm-btns">' +
        '<a class="p" href="../sample.html">無料サンプルを申し込む</a>' +
        '<a class="s" href="../simulator.html">必要枚数を10秒で計算</a>' +
        '<a class="s" href="../products.html">価格表を見る</a>' +
      '</div>' +
    '</aside>';
  const h2s = body.querySelectorAll('h2');
  const anchor = h2s.length >= 3 ? h2s[2] : (h2s.length >= 2 ? h2s[1] : null);
  if (anchor) anchor.insertAdjacentHTML('beforebegin', html);
  else body.insertAdjacentHTML('beforeend', html);
  // 末尾CTA: サンプル1本だけなら枚数計算・取扱店も並べる
  const endBtns = body.querySelector('.article-cta div');
  if (endBtns && endBtns.querySelectorAll('a').length === 1) {
    endBtns.insertAdjacentHTML('beforeend',
      '<a href="../simulator.html" class="btn btn-lg btn-outline" style="border-color:rgba(255,255,255,0.7);color:#fff;">必要枚数を計算する</a>' +
      '<a href="../markets.html" class="btn btn-lg btn-outline" style="border-color:rgba(255,255,255,0.7);color:#fff;">お近くの取扱店を探す</a>');
  }
}

// 関連記事（22記事すべてに自動挿入・記事末尾CTAの下）
// ブログ入口が全入口の約7割ありながら次の行き先が無く離脱しているため
const HZ_POSTS = [
  ['diy-flooring-tips','DIY・施工','DIYで桧フローリングを張る方法'],
  ['diy-ng-10','DIY・施工','DIYでやってはいけない10のこと'],
  ['tatami-to-flooring','DIY・施工','畳の和室を桧フローリングに。下地の作り方'],
  ['kasanebari-diy','DIY・施工','既存床に重ね張りDIY。床高15mmの対処法'],
  ['kugi-screw-bond','DIY・施工','フロア釘・ビス・ボンドの使い分け'],
  ['mansion-ll45','DIY・施工','マンションで無垢床は可能？LL45遮音規定'],
  ['finish-mutosou-oil-urethane','商品知識・規格','無塗装・オイル・ウレタン塗装の選び方'],
  ['flooring-vs-panel','商品知識・規格','フローリングと羽目板の違い'],
  ['how-to-choose-grade','商品知識・規格','節有・小節・無節の違いは？グレードの選び方'],
  ['thickness-15-30','商品知識・規格','15mm厚と30mm厚の違い'],
  ['uni-opc-ranjaku','商品知識・規格','UNI・OPC・乱尺とは？規格と価格差'],
  ['hekomi-iron-repair','トラブル対処','凹み傷はアイロンで直せる'],
  ['shimi-kabi-cleaning','トラブル対処','シミ・カビ・黒ずみの落とし方【塗装別】'],
  ['sukima-sori-tsukiage','トラブル対処','無垢床の隙間・反り・突き上げの原因'],
  ['hinoki-flooring-care','お手入れ・メンテナンス','桧フローリングのお手入れ完全ガイド'],
  ['robot-cleaner-kaden','お手入れ・メンテナンス','ロボット掃除機・水拭き・加湿器はOK？'],
  ['hinoki-benefits','桧の魅力・産地','桧フローリングが選ばれる5つの理由'],
  ['shizuoka-hinoki-shop','桧の魅力・産地','静岡県磐田市の桧フローリング工場'],
  ['hinoki-demerit','購入前ガイド','桧フローリングのデメリット7つ'],
  ['muku-vs-fukugou','購入前ガイド','無垢と複合フローリングどっちを選ぶ？'],
  ['sugi-vs-hinoki','購入前ガイド','杉と桧のフローリング徹底比較'],
  ['how-many-sheets','計算・見積もり','フローリングは何枚必要？計算方法とロス率']
];
// 記事ごとの「次に読むべき1本」（同カテゴリより優先）
const HZ_REL_PICK = {
  'tatami-to-flooring': ['how-many-sheets', 'kugi-screw-bond'],
  'shimi-kabi-cleaning': ['hinoki-flooring-care', 'finish-mutosou-oil-urethane'],
  'hinoki-flooring-care': ['shimi-kabi-cleaning', 'finish-mutosou-oil-urethane'],
  'how-many-sheets': ['how-to-choose-grade', 'kugi-screw-bond'],
  'kasanebari-diy': ['thickness-15-30', 'kugi-screw-bond'],
  'kugi-screw-bond': ['diy-ng-10', 'how-many-sheets'],
  'how-to-choose-grade': ['how-many-sheets', 'hinoki-demerit'],
  'sugi-vs-hinoki': ['hinoki-benefits', 'hinoki-demerit'],
  'hinoki-demerit': ['sukima-sori-tsukiage', 'hinoki-flooring-care'],
  'flooring-vs-panel': ['thickness-15-30', 'how-many-sheets']
};
const HZ_REL_FALLBACK = ['hinoki-benefits','how-many-sheets','hinoki-demerit','sugi-vs-hinoki','hinoki-flooring-care','how-to-choose-grade'];

function renderRelatedPosts() {
  if (location.pathname.indexOf('/blog/') !== 0) return;
  if (document.querySelector('.blog-related')) return;
  const slug = location.pathname.split('/').pop().replace('.html', '');
  const byId = {};
  HZ_POSTS.forEach(p => { byId[p[0]] = p; });
  const self = byId[slug];
  if (!self) return;

  const picked = [];
  const add = id => {
    if (id && id !== slug && byId[id] && picked.indexOf(id) === -1 && picked.length < 3) picked.push(id);
  };
  (HZ_REL_PICK[slug] || []).forEach(add);
  HZ_POSTS.forEach(p => { if (p[1] === self[1]) add(p[0]); });
  HZ_REL_FALLBACK.forEach(add);
  if (picked.length === 0) return;

  const css = document.createElement('style');
  css.textContent = [
    '.blog-related{margin:40px 0 0}',
    '.blog-related h3{font-size:1rem;font-weight:700;color:var(--wood-dark,#3d2b1f);margin:0 0 14px;padding-bottom:9px;border-bottom:2px solid var(--wood-mid,#a8865c)}',
    '.blog-related ul{list-style:none;margin:0;padding:0;display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr))}',
    '.blog-related li a{display:block;height:100%;background:#fbf8f3;border:1px solid #e6dccc;border-radius:10px;padding:14px 16px;text-decoration:none;transition:border-color .2s,transform .2s}',
    '.blog-related li a:hover{border-color:var(--wood-mid,#a8865c);transform:translateY(-2px)}',
    '.blog-related .rc-cat{display:block;font-size:0.7rem;color:#8a7a66;margin-bottom:5px}',
    '.blog-related .rc-ttl{display:block;font-size:0.88rem;font-weight:700;color:var(--wood-dark,#3d2b1f);line-height:1.65}',
    '.blog-related .rc-all{display:block;margin-top:14px;font-size:0.82rem;color:#5a4a3a;text-decoration:none}',
    '.blog-related .rc-all:hover{text-decoration:underline}'
  ].join('\n');
  document.head.appendChild(css);

  const cards = picked.map(id => {
    const p = byId[id];
    return '<li><a href="' + p[0] + '.html"><span class="rc-cat">' + p[1] + '</span>' +
           '<span class="rc-ttl">' + p[2] + '</span></a></li>';
  }).join('');
  const html = '<section class="blog-related"><h3>あわせて読みたい</h3><ul>' + cards + '</ul>' +
    '<a class="rc-all" href="../blog.html">▸ ブログ記事の一覧をすべて見る</a></section>';

  const cta = document.querySelector('.article-cta');
  if (cta) cta.insertAdjacentHTML('afterend', html);
  else {
    const body = document.querySelector('.article-body');
    if (body) body.insertAdjacentHTML('beforeend', html);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  renderHeader();
  renderFooter();
  renderFloatingLineButton();
  renderBlogCta();
  renderRelatedPosts();
  loadAiChat();
});
