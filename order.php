<?php
// =====================================================
// 林材木店 注文受付処理
// Xサーバー PHP対応
// =====================================================

// メール設定
define('SHOP_EMAIL', 'info@hayazai.com');
define('SHOP_NAME',  '林材木店');
define('BANK_INFO',  "遠州信用金庫\n普通預金 口座番号：0131004660\n口座名義：カ）ハヤシザイモクテン");

// POST以外はリダイレクト
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cart.html');
    exit;
}

// 入力値取得・サニタイズ
function h($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

$name       = h($_POST['name']       ?? '');
$company    = h($_POST['company']    ?? '');
$email      = h($_POST['email']      ?? '');
$tel        = h($_POST['tel']        ?? '');
$zip        = h($_POST['zip']        ?? '');
$prefecture = h($_POST['prefecture'] ?? '');
$address1   = h($_POST['address1']   ?? '');
$address2   = h($_POST['address2']   ?? '');
$note       = h($_POST['note']       ?? '');
$cart_json  = $_POST['cart_json'] ?? '[]';

// バリデーション
$errors = [];
if (!$name)       $errors[] = 'お名前は必須です';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'メールアドレスが正しくありません';
if (!$tel)        $errors[] = '電話番号は必須です';
if (!$prefecture) $errors[] = '都道府県は必須です';
if (!$address1)   $errors[] = '住所は必須です';

// カートデータ
$cart = json_decode($cart_json, true) ?: [];
if (empty($cart)) $errors[] = 'カートが空です';

if (!empty($errors)) {
    $msg = implode("\n", $errors);
    // エラー時は戻る
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>エラー | 林材木店</title></head><body>';
    echo '<p style="color:red;padding:40px;">入力エラー：<br>' . nl2br(implode('<br>', $errors)) . '</p>';
    echo '<a href="javascript:history.back()">← 戻る</a></body></html>';
    exit;
}

// 合計計算
$total = 0;
$itemText = '';
foreach ($cart as $item) {
    $subtotal = (int)($item['price'] ?? 0) * (int)($item['qty'] ?? 1);
    $total += $subtotal;
    $itemText .= sprintf(
        "  ・%s\n    単価：¥%s × %d個 = ¥%s\n",
        $item['name'] ?? '不明',
        number_format((int)($item['price'] ?? 0)),
        (int)($item['qty'] ?? 1),
        number_format($subtotal)
    );
}

// 注文番号
$orderNo = date('Ymd') . '-' . strtoupper(substr(md5(uniqid()), 0, 6));

// お客様へのメール本文
$customerBody = <<<EOT
{$name} 様

このたびは林材木店へご注文いただきありがとうございます。
以下の内容でご注文を承りました。

■ 注文番号
  {$orderNo}

■ ご注文内容
{$itemText}
  ──────────────────
  商品合計（税込）：¥{$total_fmt}
  送　料：別途ご案内
  ※送料はお届け先・荷量により異なります。

■ お支払い方法（銀行振込）
  {$bank}
  振込確認後、順次発送いたします。
  振込手数料はお客様負担となります。

■ お届け先
  〒{$zip} {$prefecture}
  {$address1} {$address2}

■ お客様情報
  お名前：{$name}
  会社名：{$company}
  TEL：{$tel}
  Email：{$email}

■ 備考
  {$note}

ご不明な点はお気軽にご連絡ください。
──────────────────────────
林材木店（ハヤシザイモクテン）
〒437-0224 静岡県磐田市
TEL：0538-58-2395（平日9:00〜17:00）
Email：info@hayazai.com
EOT;

// 店舗へのメール本文
$shopBody = <<<EOT
【新規注文】 注文番号：{$orderNo}

■ お客様情報
  お名前：{$name}
  会社名：{$company}
  TEL：{$tel}
  Email：{$email}

■ お届け先
  〒{$zip} {$prefecture}
  {$address1} {$address2}

■ ご注文内容
{$itemText}
  商品合計：¥{$total_fmt}

■ 備考
  {$note}
EOT;

// 変数埋め込み
$total_fmt = number_format($total);
$bank = BANK_INFO;

// mb_send_mail 設定
$headers_customer = "From: " . SHOP_NAME . " <" . SHOP_EMAIL . ">\r\n"
    . "Reply-To: " . SHOP_EMAIL . "\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";

$headers_shop = "From: order-noreply@hayazai.com\r\n"
    . "Reply-To: {$email}\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";

$subject_customer = "[林材木店] ご注文ありがとうございます（注文番号：{$orderNo}）";
$subject_shop     = "【新規注文】{$name} 様より（{$orderNo}）";

// メール送信（mb_send_mail）
$sent1 = mb_send_mail($email,      $subject_customer, $customerBody, $headers_customer);
$sent2 = mb_send_mail(SHOP_EMAIL,  $subject_shop,     $shopBody,     $headers_shop);

// 完了ページへリダイレクト
header("Location: order_complete.html?order={$orderNo}");
exit;
