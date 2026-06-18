<?php
// =====================================================
// 林材木店 市場・代理店専用 販促物申込 受付処理
// =====================================================

define('SHOP_EMAIL', 'info@hayazai.com');
define('SHOP_NAME',  '林材木店');

mb_language('Japanese');
mb_internal_encoding('UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: market_apply.html');
    exit;
}

function h($str) {
    return htmlspecialchars(trim($str ?? ''), ENT_QUOTES, 'UTF-8');
}

$company  = h($_POST['company'] ?? '');
$name     = h($_POST['name'] ?? '');
$email    = h($_POST['email'] ?? '');
$tel      = h($_POST['tel'] ?? '');
$zip      = h($_POST['zip'] ?? '');
$address  = h($_POST['address'] ?? '');
$quantity = h($_POST['quantity'] ?? '');
$note     = h($_POST['note'] ?? '');
$pdf_data = isset($_POST['pdf_data']) ? 'PDFデータも希望する' : '';

$item_labels = [
    'flyer_market'   => '販促チラシ（市場・代理店向け）',
    'flyer_standard' => '販促チラシ（標準）',
    'sample'         => '無料サンプル',
    'pricelist'      => '価格表',
    'other'          => 'その他',
];
$items_raw = $_POST['items'] ?? [];
$items_str = implode('・', array_map(fn($v) => $item_labels[h($v)] ?? h($v), $items_raw));

$errors = [];
if (!$company) $errors[] = '貴社名（市場・代理店名）は必須です';
if (!$name)    $errors[] = 'ご担当者名は必須です';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'メールアドレスが正しくありません';
if (!$address) $errors[] = '送付先住所は必須です';
if (empty($items_raw)) $errors[] = 'ご希望の販促物を1つ以上選択してください';

if (!empty($errors)) {
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>エラー | 林材木店</title></head><body>';
    echo '<p style="color:#c62828;padding:40px;line-height:1.9;">入力エラー：<br>' . nl2br(implode('<br>', array_map('h', $errors))) . '</p>';
    echo '<a href="javascript:history.back()" style="padding:0 40px;">← 戻って修正する</a></body></html>';
    exit;
}

// 申込者への控えメール
$customer_body = <<<EOT
{$company}
{$name} 様

このたびは販促物のお申し込みをいただき、誠にありがとうございます。
以下の内容で承りました。準備が整い次第、発送いたします。

■ ご希望の販促物
  {$items_str}
■ 必要部数
  {$quantity}
■ 送付先
  〒{$zip} {$address}
{$pdf_data}

ご不明点は info@hayazai.com / TEL 0538-58-2395 までお問い合わせください。

──────────────────────────
林材木店
静岡県磐田市｜国産無垢桧フローリング・羽目板 製造直販
TEL：0538-58-2395（平日9:00〜17:00）
Email：info@hayazai.com
EOT;

// 店舗への通知メール
$shop_body = <<<EOT
【市場・代理店 販促物申込】

貴社名：{$company}
ご担当者：{$name}
Email：{$email}
TEL：{$tel}

ご希望の販促物：{$items_str}
必要部数：{$quantity}
{$pdf_data}

送付先：〒{$zip} {$address}

備考・ご要望：
{$note}
EOT;

$headers_customer = "From: " . SHOP_NAME . " <" . SHOP_EMAIL . ">";
$headers_shop     = "From: market-noreply@hayazai.com\r\nReply-To: {$email}";

mb_send_mail($email,     "[林材木店] 販促物のお申し込みを受け付けました", $customer_body, $headers_customer);
mb_send_mail(SHOP_EMAIL, "【販促物申込】{$company}／{$name}様",            $shop_body,     $headers_shop);

header('Location: market_apply_complete.html');
exit;
