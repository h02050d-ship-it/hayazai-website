// =====================================================
// 林材木店 自前アクセス計測（第一者・Cookie不使用）
// 収集: ページビュー / 滞在時間 / スクロール到達 / 離脱 / CTAイベント
// 送信先: /keisoku/k.php → keisoku/state/*.jsonl（サーバー専用領域）
// ※ファイル名に analytics/track を使うと広告ブロッカーに遮断されるため hz/keisoku 表記
// GA4と併走。components.js が発火する gtag イベントもラップして取り込む。
// 閲覧用ダッシュボード: https://h02050d-ship-it.github.io/hp-analytics/
// =====================================================
(function () {
  'use strict';
  if (navigator.webdriver) return; // 自動化ブラウザは計測しない
  try { if (localStorage.getItem('hz_optout') === '1') return; } catch (e) {} // 自分除外（/keisoku/me.html でON/OFF）
  var EP = '/keisoku/k.php';

  function rnd() { return Math.random().toString(36).slice(2, 10) + Date.now().toString(36); }

  var vid = 'na', sid = 'na';
  try {
    vid = localStorage.getItem('hz_vid');
    if (!vid) { vid = rnd(); localStorage.setItem('hz_vid', vid); }
  } catch (e) {}
  try {
    sid = sessionStorage.getItem('hz_sid');
    if (!sid) { sid = rnd(); sessionStorage.setItem('hz_sid', sid); }
  } catch (e) {}

  // パス正規化（product.html は ?id= だけ残す）
  var path = location.pathname;
  if (path === '' || path === '/') path = '/index.html';
  if (path.slice(-1) === '/') path += 'index.html';
  try {
    var pid = new URLSearchParams(location.search).get('id');
    if (pid && path.indexOf('product.html') >= 0) path += '?id=' + pid.slice(0, 40);
  } catch (e) {}

  // 流入コード（FAX/QR/チラシ用 ?f=xxx）。着地ページで受け取り、セッション中は保持する
  var fcode = '';
  try {
    fcode = (new URLSearchParams(location.search).get('f') || '').replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 24);
    if (fcode) sessionStorage.setItem('hz_f', fcode);
    else fcode = sessionStorage.getItem('hz_f') || '';
  } catch (e) {}

  function send(obj) {
    obj.sid = sid; obj.vid = vid; obj.p = path;
    var body = JSON.stringify(obj);
    try {
      if (navigator.sendBeacon && navigator.sendBeacon(EP, new Blob([body], { type: 'application/json' }))) return;
    } catch (e) {}
    try { fetch(EP, { method: 'POST', body: body, keepalive: true }); } catch (e) {}
  }

  // ---- ページビュー ----
  var ref = document.referrer || '';
  if (ref.indexOf('//' + location.host) >= 0) ref = ''; // サイト内遷移は流入元にしない
  send({ e: 'pv', r: ref.slice(0, 300), sw: (screen && screen.width) || 0, f: fcode });

  // ---- スクロール到達率（最大値）----
  var sd = 0;
  function calcSd() {
    var h = document.documentElement;
    var total = h.scrollHeight - window.innerHeight;
    var d = total <= 0 ? 100 : Math.round(Math.min(100, ((window.scrollY || h.scrollTop) / total) * 100));
    if (d > sd) sd = d;
  }
  window.addEventListener('scroll', calcSd, { passive: true });
  window.addEventListener('load', calcSd);

  // ---- 実滞在時間（タブが見えている間だけ加算）----
  var accum = 0, visStart = Date.now();
  function flushLeave() {
    var d = Math.min(Math.round(accum / 1000), 1800);
    send({ e: 'lv', dur: d, sd: sd });
  }
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { accum += Date.now() - visStart; flushLeave(); }
    else { visStart = Date.now(); }
  });
  window.addEventListener('pagehide', function () {
    if (!document.hidden) { accum += Date.now() - visStart; visStart = Date.now(); }
    flushLeave();
  });

  // ---- CTAイベント ----
  window.hzTrack = function (name) { send({ e: 'ev', x: String(name || '').slice(0, 40) }); };

  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href^="tel:"]');
    if (a) send({ e: 'ev', x: 'tel' });
  });

  // gtag ラップ: components.js 発のGA4イベントをこちらにもミラーする
  var EVENT_MAP = {
    line_click: 'line',
    ai_chat_open: 'ai_open',
    ai_chat_message: 'ai_q',
    ai_chat_consent: 'ai_consent',
    contact_submit: 'form_contact',
    sample_request: 'form_sample',
    quote_request: 'form_quote',
    add_to_cart: 'cart_add'
  };
  (function wrapGtag(tries) {
    var og = window.gtag;
    if (typeof og !== 'function') { if (tries < 20) setTimeout(function () { wrapGtag(tries + 1); }, 500); return; }
    if (og.__hz) return;
    var w = function () {
      try {
        if (arguments[0] === 'event' && EVENT_MAP[arguments[1]]) send({ e: 'ev', x: EVENT_MAP[arguments[1]] });
      } catch (e) {}
      return og.apply(this, arguments);
    };
    w.__hz = true;
    window.gtag = w;
  })(0);
})();
