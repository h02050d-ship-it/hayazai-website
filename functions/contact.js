// =============================================================
// 林材木店 お問い合わせ受付（Cloudflare Pages Functions）
// 旧 contact.php 互換
// MailChannels（無料・Workers経由）を使ってメール送信
// =============================================================

const SHOP_EMAIL = 'info@hayazai.com';
const SHOP_NAME = '林材木店';
const FROM_DOMAIN = 'hayazai.com';

const TYPE_LABELS = {
  sample: '無料サンプル請求',
  quote: '見積もり依頼',
  product: '商品について',
  construction: '施工方法について',
  delivery: '納期・配送について',
  other: 'その他',
};

const GRADE_LABELS = {
  fushi_ari: '節有',
  ko_fushi: '小節',
  toku_ko: '特上小',
  mushi: '無節',
};

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function isEmail(s) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(s).trim());
}

async function parseBody(request) {
  const contentType = request.headers.get('content-type') || '';
  if (contentType.includes('application/json')) {
    return await request.json();
  }
  // application/x-www-form-urlencoded または multipart/form-data
  const form = await request.formData();
  const data = {};
  for (const key of form.keys()) {
    const all = form.getAll(key);
    if (key.endsWith('[]') || all.length > 1) {
      data[key.replace(/\[\]$/, '')] = all.map(String);
    } else {
      data[key] = String(all[0]);
    }
  }
  return data;
}

async function sendMail({ to, fromName, fromEmail, replyTo, subject, text }) {
  const body = {
    personalizations: [
      {
        to: [{ email: to }],
      },
    ],
    from: { email: fromEmail, name: fromName },
    subject,
    content: [{ type: 'text/plain', value: text }],
  };
  if (replyTo) body.reply_to = { email: replyTo };

  const res = await fetch('https://api.mailchannels.net/tx/v1/send', {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify(body),
  });
  if (!res.ok) {
    const detail = await res.text();
    throw new Error(`MailChannels error ${res.status}: ${detail}`);
  }
}

export async function onRequestPost({ request }) {
  let data;
  try {
    data = await parseBody(request);
  } catch (e) {
    return new Response('Bad Request', { status: 400 });
  }

  const name = String(data.name || '').trim();
  const company = String(data.company || '').trim();
  const email = String(data.email || '').trim();
  const tel = String(data.tel || '').trim();
  const type = String(data.type || '').trim();
  const message = String(data.message || '').trim();

  const sample_zip = String(data.sample_zip || '').trim();
  const sample_address = String(data.sample_address || '').trim();
  const sample_items = Array.isArray(data.sample_items) ? data.sample_items : (data.sample_items ? [data.sample_items] : []);
  const sample_grades = Array.isArray(data.sample_grades) ? data.sample_grades : (data.sample_grades ? [data.sample_grades] : []);

  const errors = [];
  if (!name) errors.push('お名前は必須です');
  if (!isEmail(email)) errors.push('メールアドレスが正しくありません');
  if (!type) errors.push('お問い合わせ種別を選択してください');
  if (!message) errors.push('お問い合わせ内容を入力してください');

  if (type === 'sample') {
    if (!sample_zip) errors.push('郵便番号を入力してください');
    if (!sample_address) errors.push('住所を入力してください');
    if (sample_items.length === 0) errors.push('サンプルの種類を1つ以上選択してください');
    if (sample_grades.length === 0) errors.push('グレードを1つ以上選択してください');
  }

  if (errors.length > 0) {
    const html = `<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>エラー | 林材木店</title></head><body>` +
      `<p style="color:red;padding:40px;">入力エラー：<br>${errors.map(escapeHtml).join('<br>')}</p>` +
      `<a href="javascript:history.back()">← 戻る</a></body></html>`;
    return new Response(html, { status: 400, headers: { 'content-type': 'text/html; charset=UTF-8' } });
  }

  const typeLabel = TYPE_LABELS[type] || type;
  const sampleItemsStr = sample_items.join('・');
  const sampleGradesStr = sample_grades.map((v) => GRADE_LABELS[v] || v).join('・');

  // お客様向け本文
  let customerBody = `${name} 様

お問い合わせいただきありがとうございます。
以下の内容で承りました。通常1〜3営業日以内にご返答いたします。

3営業日を過ぎてもご連絡がない場合は、メールが届いていない可能性がございます。
お手数ですがお電話にてご確認ください。
TEL：0538-58-2395（平日 9:00〜17:00）

■ お問い合わせ種別
  ${typeLabel}

■ お問い合わせ内容
  ${message}`;

  if (type === 'sample' && sample_address) {
    customerBody += `\n■ サンプル送付先\n  〒${sample_zip} ${sample_address}\n  ご希望品目：${sampleItemsStr}\n  ご希望グレード：${sampleGradesStr}`;
  }

  customerBody += `\n\n──────────────────────────
林材木店
TEL：0538-58-2395（平日9:00〜17:00）
Email：info@hayazai.com`;

  // 店舗向け本文
  let shopBody = `【お問い合わせ】${typeLabel}

お名前：${name}
会社名：${company}
Email：${email}
TEL：${tel}

種別：${typeLabel}
内容：
${message}`;

  if (type === 'sample') {
    shopBody += `\n\nサンプル送付先：〒${sample_zip} ${sample_address}\nご希望品目：${sampleItemsStr}\nご希望グレード：${sampleGradesStr}`;
  }

  try {
    await sendMail({
      to: email,
      fromName: SHOP_NAME,
      fromEmail: SHOP_EMAIL,
      subject: '[林材木店] お問い合わせを受け付けました',
      text: customerBody,
    });
    await sendMail({
      to: SHOP_EMAIL,
      fromName: '林材木店 サイトお問い合わせ',
      fromEmail: `contact-noreply@${FROM_DOMAIN}`,
      replyTo: email,
      subject: `【お問い合わせ】${typeLabel}／${name}様`,
      text: shopBody,
    });
  } catch (err) {
    console.error('mail send failed', err);
    return new Response(
      `<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"></head><body><p style="color:red;padding:40px;">メール送信に失敗しました。お手数ですがお電話（0538-58-2395）でご連絡ください。</p></body></html>`,
      { status: 500, headers: { 'content-type': 'text/html; charset=UTF-8' } }
    );
  }

  return Response.redirect(new URL('/contact_complete.html', request.url).toString(), 303);
}

export async function onRequestGet({ request }) {
  return Response.redirect(new URL('/contact.html', request.url).toString(), 303);
}
