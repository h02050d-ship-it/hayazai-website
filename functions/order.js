// =============================================================
// 林材木店 注文受付（Cloudflare Pages Functions）
// 旧 order.php 互換
// MailChannels で送信
// =============================================================

const SHOP_EMAIL = 'info@hayazai.com';
const SHOP_NAME = '林材木店';
const FROM_DOMAIN = 'hayazai.com';
const BANK_INFO = `遠州信用金庫
普通預金 口座番号：0131004660
口座名義：カ）ハヤシザイモクテン`;

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function isEmail(s) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(s).trim());
}

function fmtNumber(n) {
  return Number(n).toLocaleString('en-US');
}

async function parseBody(request) {
  const contentType = request.headers.get('content-type') || '';
  if (contentType.includes('application/json')) return await request.json();
  const form = await request.formData();
  const data = {};
  for (const key of form.keys()) data[key] = String(form.get(key));
  return data;
}

function generateOrderNo() {
  const d = new Date();
  const y = d.getUTCFullYear();
  const m = String(d.getUTCMonth() + 1).padStart(2, '0');
  const day = String(d.getUTCDate()).padStart(2, '0');
  const rand = Array.from(crypto.getRandomValues(new Uint8Array(3)))
    .map((v) => v.toString(16).padStart(2, '0'))
    .join('')
    .toUpperCase();
  return `${y}${m}${day}-${rand}`;
}

async function sendMail({ to, fromName, fromEmail, replyTo, subject, text }) {
  const body = {
    personalizations: [{ to: [{ email: to }] }],
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
  const zip = String(data.zip || '').trim();
  const prefecture = String(data.prefecture || '').trim();
  const address1 = String(data.address1 || '').trim();
  const address2 = String(data.address2 || '').trim();
  const note = String(data.note || '').trim();
  const cartJson = String(data.cart_json || '[]');

  const errors = [];
  if (!name) errors.push('お名前は必須です');
  if (!isEmail(email)) errors.push('メールアドレスが正しくありません');
  if (!tel) errors.push('電話番号は必須です');
  if (!prefecture) errors.push('都道府県は必須です');
  if (!address1) errors.push('住所は必須です');

  let cart = [];
  try {
    cart = JSON.parse(cartJson);
    if (!Array.isArray(cart)) cart = [];
  } catch (e) {
    cart = [];
  }
  if (cart.length === 0) errors.push('カートが空です');

  if (errors.length > 0) {
    const html = `<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>エラー | 林材木店</title></head><body>` +
      `<p style="color:red;padding:40px;">入力エラー：<br>${errors.map(escapeHtml).join('<br>')}</p>` +
      `<a href="javascript:history.back()">← 戻る</a></body></html>`;
    return new Response(html, { status: 400, headers: { 'content-type': 'text/html; charset=UTF-8' } });
  }

  let total = 0;
  let itemText = '';
  for (const item of cart) {
    const price = parseInt(item.price || 0, 10) || 0;
    const qty = parseInt(item.qty || 1, 10) || 1;
    const subtotal = price * qty;
    total += subtotal;
    itemText += `  ・${item.name || '不明'}\n    単価：¥${fmtNumber(price)} × ${qty}個 = ¥${fmtNumber(subtotal)}\n`;
  }

  const orderNo = generateOrderNo();
  const totalFmt = fmtNumber(total);

  const customerBody = `${name} 様

このたびは林材木店へご注文いただきありがとうございます。
以下の内容でご注文を承りました。

■ 注文番号
  ${orderNo}

■ ご注文内容
${itemText}  ──────────────────
  商品合計（税込）：¥${totalFmt}
  送　料：別途ご案内
  ※送料はお届け先・荷量により異なります。

■ お支払い方法（銀行振込）
  ${BANK_INFO}
  振込確認後、順次発送いたします。
  振込手数料はお客様負担となります。

■ お届け先
  〒${zip} ${prefecture}
  ${address1} ${address2}

■ お客様情報
  お名前：${name}
  会社名：${company}
  TEL：${tel}
  Email：${email}

■ 備考
  ${note}

ご不明な点はお気軽にご連絡ください。
──────────────────────────
林材木店（ハヤシザイモクテン）
〒437-0224 静岡県磐田市
TEL：0538-58-2395（平日9:00〜17:00）
Email：info@hayazai.com`;

  const shopBody = `【新規注文】 注文番号：${orderNo}

■ お客様情報
  お名前：${name}
  会社名：${company}
  TEL：${tel}
  Email：${email}

■ お届け先
  〒${zip} ${prefecture}
  ${address1} ${address2}

■ ご注文内容
${itemText}  商品合計：¥${totalFmt}

■ 備考
  ${note}`;

  try {
    await sendMail({
      to: email,
      fromName: SHOP_NAME,
      fromEmail: SHOP_EMAIL,
      subject: `[林材木店] ご注文ありがとうございます（注文番号：${orderNo}）`,
      text: customerBody,
    });
    await sendMail({
      to: SHOP_EMAIL,
      fromName: '林材木店 注文通知',
      fromEmail: `order-noreply@${FROM_DOMAIN}`,
      replyTo: email,
      subject: `【新規注文】${name} 様より（${orderNo}）`,
      text: shopBody,
    });
  } catch (err) {
    console.error('mail send failed', err);
    return new Response(
      `<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"></head><body><p style="color:red;padding:40px;">注文の確定処理中にエラーが発生しました。お手数ですがお電話（0538-58-2395）でご連絡ください。</p></body></html>`,
      { status: 500, headers: { 'content-type': 'text/html; charset=UTF-8' } }
    );
  }

  return Response.redirect(new URL(`/order_complete.html?order=${orderNo}`, request.url).toString(), 303);
}

export async function onRequestGet({ request }) {
  return Response.redirect(new URL('/cart.html', request.url).toString(), 303);
}
