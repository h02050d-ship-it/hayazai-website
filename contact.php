<?php
// =====================================================
// 林材木店 お問い合わせ受付処理
// =====================================================

define('SHOP_EMAIL', 'info@hayazai.com');
define('SHOP_NAME',  '林材木店');

mb_language('Japanese');
mb_internal_encoding('UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contact.html');
    exit;
}

function h($str) {
    return htmlspecialchars(trim($str ?? ''), ENT_QUOTES, 'UTF-8');
}

$name    = h($_POST['name']);
$company = h($_POST['company']);
$email   = h($_POST['email']);
$tel     = h($_POST['tel']);
$type    = h($_POST['type']);
$message = h($_POST['message']);

// サンプル請求フィールド
$sample_zip     = h($_POST['sample_zip'] ?? '');
$sample_address = h($_POST['sample_address'] ?? '');
$sample_items   = $_POST['sample_items'] ?? [];
$sample_items_str = implode('・', array_map('h', $sample_items));

$type_labels = [
    'sample'       => '無料サンプル請求',
    'quote'        => '見積もり依頼',
    'product'      => '商品について',
    'construction' => '施工方法について',
    'delivery'     => '納期・配送について',
    'other'        => 'その他',
];
$type_label = $type_labels[$type] ?? $type;

$errors = [];
if (!$name)  $errors[] = 'お名前は必須です';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'メールアドレスが正しくありません';
if (!$type)  $errors[] = 'お問い合わせ種別を選択してください';
if (!$message) $errors[] = 'お問い合わせ内容を入力してください';

// サンプル請求の場合の追加バリデーション
if ($type === 'sample') {
    if (!$sample_zip)     $errors[] = '郵便番号を入力してください';
    if (!$sample_address) $errors[] = '住所を入力してください';
    if (empty($sample_items))  $errors[] = 'サンプルの種類を1つ以上選択してください';
    if (empty($_POST['sample_grades'] ?? [])) $errors[] = 'サンプルの等級を1つ以上選択してください';
}

if (!empty($errors)) {
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>エラー | 林材木店</title></head><body>';
    echo '<p style="color:red;padding:40px;">入力エラー：<br>' . nl2br(implode('<br>', array_map('h', $errors))) . '</p>';
    echo '<a href="javascript:history.back()">← 戻る</a></body></html>';
    exit;
}

// お客様へのメール
$customer_body = <<<EOT
{$name} 様

お問い合わせいただきありがとうございます。
以下の内容で承りました。通常1〜3営業日以内にご返答いたします。

3営業日を過ぎてもご連絡がない場合は、メールが届いていない可能性がございます。
お手数ですがお電話にてご確認ください。
TEL：0538-58-2395（平日 9:00〜17:00）

■ お問い合わせ種別
  {$type_label}

■ お問い合わせ内容
  {$message}
EOT;

if ($type === 'sample' && $sample_address) {
    $customer_body .= "\n■ サンプル送付先\n  〒{$sample_zip} {$sample_address}\n  ご希望：{$sample_items_str}";
}

$customer_body .= <<<EOT


──────────────────────────
林材木店
TEL：0538-58-2395（平日9:00〜17:00）
Email：info@hayazai.com
EOT;

// 店舗へのメール
$shop_body = <<<EOT
【お問い合わせ】{$type_label}

お名前：{$name}
会社名：{$company}
Email：{$email}
TEL：{$tel}

種別：{$type_label}
内容：
{$message}
EOT;

if ($type === 'sample') {
    $shop_body .= "\n\nサンプル送付先：〒{$sample_zip} {$sample_address}\nご希望品目：{$sample_items_str}";
}

$headers_customer = "From: " . SHOP_NAME . " <" . SHOP_EMAIL . ">";
$headers_shop     = "From: contact-noreply@hayazai.com\r\nReply-To: {$email}";

mb_send_mail($email,      "[林材木店] お問い合わせを受け付けました", $customer_body, $headers_customer);
mb_send_mail(SHOP_EMAIL,  "【お問い合わせ】{$type_label}／{$name}様", $shop_body,     $headers_shop);

header('Location: contact_complete.html');
exit;
