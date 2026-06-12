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

// 法人見積もりフォーム（business.html）の追加フィールド
$industry_labels = [
    'builder'   => '工務店・住宅会社',
    'architect' => '設計事務所・建築士',
    'reform'    => 'リフォーム業者',
    'contractor'=> 'ゼネコン・建設会社',
    'diy_shop'  => 'DIY販売店・小売',
    'other'     => 'その他',
];
$product_type_labels = [
    'flooring15' => '桧フローリング 15mm',
    'flooring12' => '桧フローリング 12mm',
    'panel'      => '桧羽目板 12mm',
    'multiple'   => '複数',
];
$industry       = h($_POST['industry'] ?? '');
$product_type   = h($_POST['product_type'] ?? '');
$quantity_range = h($_POST['quantity_range'] ?? '');
$quote_lines = [];
if ($industry)       $quote_lines[] = '業種：' . ($industry_labels[$industry] ?? $industry);
if ($product_type)   $quote_lines[] = '希望商品：' . ($product_type_labels[$product_type] ?? $product_type);
if ($quantity_range) $quote_lines[] = '希望数量：' . $quantity_range . '束';
$quote_info = $quote_lines ? implode("\n", $quote_lines) : '';

// 法人見積もり（quote）でメッセージ未入力の場合はデフォルト文を補完
if ($type === 'quote' && !$message && $quote_info) {
    $message = '法人見積もりを依頼します。';
}

// サンプル請求フィールド
$sample_zip      = h($_POST['sample_zip'] ?? '');
$sample_address  = h($_POST['sample_address'] ?? '');
$sample_items    = $_POST['sample_items'] ?? [];
$sample_items_str = implode('・', array_map('h', $sample_items));
$sample_grades_raw = $_POST['sample_grades'] ?? [];
$grade_labels_map  = ['fushi_ari' => '節有', 'ko_fushi' => '小節', 'toku_ko' => '特上小', 'mushi' => '無節'];
$sample_grades_str = implode('・', array_map(fn($v) => $grade_labels_map[h($v)] ?? h($v), $sample_grades_raw));

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
    if (!$sample_zip)    $errors[] = '郵便番号を入力してください';
    if (!$sample_address) $errors[] = '住所を入力してください';
    if (empty($sample_items)) $errors[] = 'サンプルの種類を1つ以上選択してください';
    if (empty($sample_grades_raw)) $errors[] = 'グレードを1つ以上選択してください';
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
    $customer_body .= "\n■ サンプル送付先\n  〒{$sample_zip} {$sample_address}\n  ご希望品目：{$sample_items_str}\n  ご希望グレード：{$sample_grades_str}";
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

if ($quote_info) {
    $shop_body .= "\n\n【法人見積もり情報】\n{$quote_info}";
}

if ($type === 'sample') {
    $shop_body .= "\n\nサンプル送付先：〒{$sample_zip} {$sample_address}\nご希望品目：{$sample_items_str}\nご希望グレード：{$sample_grades_str}";
}

$headers_customer = "From: " . SHOP_NAME . " <" . SHOP_EMAIL . ">";
$headers_shop     = "From: contact-noreply@hayazai.com\r\nReply-To: {$email}";

mb_send_mail($email,      "[林材木店] お問い合わせを受け付けました", $customer_body, $headers_customer);
mb_send_mail(SHOP_EMAIL,  "【お問い合わせ】{$type_label}／{$name}様", $shop_body,     $headers_shop);

header('Location: contact_complete.html');
exit;
