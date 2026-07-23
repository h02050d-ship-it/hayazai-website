<?php
// =====================================================
// 林材木店 新規取扱店（市場・販売店）ご相談 受付処理
// =====================================================

define('SHOP_EMAIL', 'info@hayazai.com');
define('SHOP_NAME',  '林材木店');

mb_language('Japanese');
mb_internal_encoding('UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dealer.html');
    exit;
}

function h($str) {
    return htmlspecialchars(trim($str ?? ''), ENT_QUOTES, 'UTF-8');
}

$company = h($_POST['company'] ?? '');
$biztype = h($_POST['biztype'] ?? '');
$name    = h($_POST['name'] ?? '');
$email   = h($_POST['email'] ?? '');
$tel     = h($_POST['tel'] ?? '');
$address = h($_POST['address'] ?? '');
$note    = h($_POST['note'] ?? '');

$wish_labels = [
    'atsukai' => '取り扱いを検討したい',
    'shiryo'  => '資料・サンプルがほしい',
    'joken'   => '取引条件・価格を聞きたい',
    'other'   => 'その他',
];
$wishes_raw = $_POST['wishes'] ?? [];
$wishes_str = implode('・', array_map(fn($v) => $wish_labels[h($v)] ?? h($v), $wishes_raw));

$errors = [];
if (!$company) $errors[] = '貴社名は必須です';
if (!$biztype) $errors[] = '業種を選択してください';
if (!$name)    $errors[] = 'ご担当者名は必須です';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'メールアドレスが正しくありません';
if (!$address) $errors[] = '所在地は必須です';
if (empty($wishes_raw)) $errors[] = 'ご希望を1つ以上選択してください';

if (!empty($errors)) {
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>エラー | 林材木店</title></head><body>';
    echo '<p style="color:#c62828;padding:40px;line-height:1.9;">入力エラー：<br>' . nl2br(implode('<br>', array_map('h', $errors))) . '</p>';
    echo '<a href="javascript:history.back()" style="padding:0 40px;">← 戻って修正する</a></body></html>';
    exit;
}

// ご相談者への控えメール
$customer_body = <<<EOT
{$company}
{$name} 様

このたびは当社製品のお取り扱いについてご相談いただき、誠にありがとうございます。
以下の内容で受け付けました。担当より折り返しご連絡いたします。

■ 業種
  {$biztype}
■ ご希望
  {$wishes_str}
■ 所在地
  {$address}

ご不明点は info@hayazai.com / TEL 0538-58-2395 までお問い合わせください。

──────────────────────────
林材木店
静岡県磐田市｜国産無垢桧フローリング・羽目板 製造
TEL：0538-58-2395（平日9:00〜17:00）
Email：info@hayazai.com
EOT;

// 店舗への通知メール
$shop_body = <<<EOT
【新規取扱店のご相談】

貴社名：{$company}
業種：{$biztype}
ご担当者：{$name}
Email：{$email}
TEL：{$tel}
所在地：{$address}

ご希望：{$wishes_str}

備考・ご質問：
{$note}
EOT;

$headers_customer = "From: " . SHOP_NAME . " <" . SHOP_EMAIL . ">";
$headers_shop     = "From: dealer-noreply@hayazai.com\r\nReply-To: {$email}";

mb_send_mail($email,     "[林材木店] お取り扱いのご相談を受け付けました", $customer_body, $headers_customer);
mb_send_mail(SHOP_EMAIL, "【新規取扱店のご相談】{$company}／{$name}様",       $shop_body,     $headers_shop);

header('Location: dealer_apply_complete.html');
exit;
