// 林材木店 AIチャットウィジェット（全ページ共通・components.jsから読込）
(function () {
  'use strict';

  var ENDPOINT = (location.pathname.indexOf('/blog/') === 0 ? '../' : '') + 'ai/chat.php';
  var DISCLAIMER = '※AIによる自動回答です。内容に誤りが含まれる場合があります。最終的なご判断はお客様ご自身の責任でお願いいたします。正確な情報・ご注文はお問い合わせフォームまたはLINEでご確認ください。';
  var GREETING = 'こんにちは！林材木店のAIアシスタントです🌲\n桧フローリングの選び方・DIYの張り方・お手入れなど、お気軽にご質問ください。\n\n' + DISCLAIMER;

  var css = [
    '.aichat-fab{position:fixed;left:18px;bottom:18px;z-index:9000;display:flex;align-items:center;gap:10px;background:#3d2b1f;color:#fff;border:none;border-radius:32px;padding:10px 22px 10px 12px;font-size:0.95rem;font-weight:700;cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.28);font-family:inherit;}',
    '.aichat-fab:hover{background:#5a4030;}',
    '.aichat-fab .aichat-ico{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:50%;background:#2e7d32;font-size:1.15rem;flex:0 0 auto;}',
    '.aichat-panel{position:fixed;left:16px;bottom:80px;z-index:9001;width:min(360px,calc(100vw - 32px));height:min(520px,calc(100vh - 120px));background:#fff;border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.3);display:none;flex-direction:column;overflow:hidden;font-family:inherit;}',
    '.aichat-panel.open{display:flex;}',
    '.aichat-head{background:#3d2b1f;color:#fff;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;}',
    '.aichat-head h3{margin:0;font-size:0.95rem;color:#fff;}',
    '.aichat-close{background:none;border:none;color:#fff;font-size:1.3rem;cursor:pointer;line-height:1;padding:4px;}',
    '.aichat-note{background:#fff8e1;color:#7a5c00;font-size:0.68rem;line-height:1.5;padding:8px 12px;border-bottom:1px solid #f0e0b0;}',
    '.aichat-log{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:10px;background:#faf6f0;}',
    '.aichat-msg{max-width:85%;padding:10px 13px;border-radius:12px;font-size:0.82rem;line-height:1.7;white-space:pre-wrap;word-break:break-word;}',
    '.aichat-msg.user{align-self:flex-end;background:#3d2b1f;color:#fff;border-bottom-right-radius:4px;}',
    '.aichat-msg.ai{align-self:flex-start;background:#fff;color:#3a3a3a;border:1px solid #e8ddd0;border-bottom-left-radius:4px;}',
    '.aichat-msg.ai a{color:#2e7d32;word-break:break-all;}',
    '.aichat-typing{align-self:flex-start;font-size:0.75rem;color:#999;padding:4px 8px;}',
    '.aichat-form{display:flex;gap:8px;padding:10px;border-top:1px solid #e8ddd0;background:#fff;}',
    '.aichat-input{flex:1;border:1px solid #d0c4b4;border-radius:8px;padding:9px 12px;font-size:0.85rem;font-family:inherit;resize:none;height:40px;}',
    '.aichat-send{background:#2e7d32;color:#fff;border:none;border-radius:8px;padding:0 16px;font-size:0.85rem;font-weight:700;cursor:pointer;font-family:inherit;}',
    '.aichat-send:disabled{background:#aaa;cursor:default;}',
    '.aichat-consent{position:absolute;inset:0;z-index:5;background:#fff;display:flex;flex-direction:column;justify-content:center;gap:14px;padding:24px 22px;text-align:left;}',
    '.aichat-consent h4{margin:0;font-size:0.95rem;color:#3d2b1f;}',
    '.aichat-consent p{margin:0;font-size:0.8rem;line-height:1.8;color:#5a4a3a;}',
    '.aichat-consent ul{margin:0;padding-left:18px;font-size:0.78rem;line-height:1.8;color:#5a4a3a;}',
    '.aichat-consent .agree{background:#2e7d32;color:#fff;border:none;border-radius:8px;padding:12px;font-size:0.88rem;font-weight:700;cursor:pointer;font-family:inherit;}',
    '.aichat-consent .agree:hover{background:#256528;}',
    '.aichat-panel.consented .aichat-consent{display:none;}',
    '@media(max-width:768px){.aichat-fab{bottom:14px;left:12px;padding:8px 18px 8px 10px;font-size:0.88rem;}.aichat-fab .aichat-ico{width:30px;height:30px;font-size:1.05rem;}.aichat-panel{left:8px;bottom:76px;}}'
  ].join('\n');

  var history = [];
  try { history = JSON.parse(sessionStorage.getItem('aichat_history') || '[]'); } catch (e) { history = []; }

  function escapeHtml(s) {
    return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function linkify(s) {
    return s.replace(/https:\/\/hayazai\.com\/[^\s<）)」、。]*/g, function (u) {
      return '<a href="' + u + '" target="_blank" rel="noopener">' + u + '</a>';
    });
  }

  function init() {
    var style = document.createElement('style');
    style.textContent = css;
    document.head.appendChild(style);

    var fab = document.createElement('button');
    fab.className = 'aichat-fab';
    fab.setAttribute('aria-label', 'AIチャットで質問する');
    fab.innerHTML = '<span class="aichat-ico">🤖</span><span>AIに質問</span>';
    document.body.appendChild(fab);

    var panel = document.createElement('div');
    panel.className = 'aichat-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'AIチャット');
    panel.innerHTML =
      '<div class="aichat-head"><h3>🤖 AIアシスタント</h3><button class="aichat-close" aria-label="閉じる">×</button></div>' +
      '<div class="aichat-note">' + escapeHtml(DISCLAIMER) + '</div>' +
      '<div class="aichat-log"></div>' +
      '<form class="aichat-form"><textarea class="aichat-input" placeholder="例：6畳に必要な枚数は？" maxlength="1000" rows="1"></textarea><button type="submit" class="aichat-send">送信</button></form>' +
      '<div class="aichat-consent">' +
        '<h4>ご利用前にご確認ください</h4>' +
        '<p>このチャットはAIが自動で回答します。便利な反面、<strong>内容に誤りが含まれることがあります</strong>。</p>' +
        '<ul>' +
          '<li>価格・在庫・納期などの最終確認は、お問い合わせフォームまたはLINEでお願いします。</li>' +
          '<li>ご回答内容にもとづく最終的なご判断は、お客様ご自身の責任でお願いいたします。</li>' +
        '</ul>' +
        '<button type="button" class="agree">了承して相談をはじめる</button>' +
      '</div>';
    document.body.appendChild(panel);

    // 了承ゲート（了承するまで入力不可。同意はこのブラウザに記憶）
    var consented = false;
    try { consented = localStorage.getItem('aichat_consent') === '1'; } catch (e) {}
    if (consented) panel.classList.add('consented');
    panel.querySelector('.aichat-consent .agree').addEventListener('click', function () {
      panel.classList.add('consented');
      try { localStorage.setItem('aichat_consent', '1'); } catch (e) {}
      if (window.gtag) gtag('event', 'ai_chat_consent', { page_path: location.pathname });
      var inp = panel.querySelector('.aichat-input');
      if (inp) inp.focus();
    });

    var log = panel.querySelector('.aichat-log');
    var form = panel.querySelector('.aichat-form');
    var input = panel.querySelector('.aichat-input');
    var send = panel.querySelector('.aichat-send');

    function addMsg(role, text) {
      var div = document.createElement('div');
      div.className = 'aichat-msg ' + (role === 'user' ? 'user' : 'ai');
      div.innerHTML = role === 'user' ? escapeHtml(text) : linkify(escapeHtml(text));
      log.appendChild(div);
      log.scrollTop = log.scrollHeight;
    }

    // 履歴復元 or 初回あいさつ
    if (history.length) {
      history.forEach(function (m) { addMsg(m.role, m.content); });
    } else {
      addMsg('ai', GREETING);
    }

    fab.addEventListener('click', function () {
      panel.classList.toggle('open');
      if (panel.classList.contains('open')) {
        input.focus();
        if (window.gtag) gtag('event', 'ai_chat_open', { page_path: location.pathname });
      }
    });
    panel.querySelector('.aichat-close').addEventListener('click', function () {
      panel.classList.remove('open');
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = input.value.trim();
      if (!text || send.disabled) return;
      input.value = '';
      addMsg('user', text);
      history.push({ role: 'user', content: text });
      send.disabled = true;
      var typing = document.createElement('div');
      typing.className = 'aichat-typing';
      typing.textContent = '回答を作成中…';
      log.appendChild(typing);
      log.scrollTop = log.scrollHeight;
      if (window.gtag) gtag('event', 'ai_chat_message', { page_path: location.pathname });

      fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ messages: history.slice(-12) })
      }).then(function (r) { return r.json(); }).then(function (data) {
        typing.remove();
        var reply = data.reply || data.error || '通信に失敗しました。お問い合わせフォームまたはLINEでお問い合わせください。';
        addMsg('ai', reply);
        if (data.reply) history.push({ role: 'assistant', content: data.reply });
        try { sessionStorage.setItem('aichat_history', JSON.stringify(history.slice(-12))); } catch (err) {}
      }).catch(function () {
        typing.remove();
        addMsg('ai', '通信に失敗しました。お問い合わせフォームまたはLINEでお問い合わせください。');
      }).finally(function () {
        send.disabled = false;
        input.focus();
      });
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
        e.preventDefault();
        form.dispatchEvent(new Event('submit'));
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
