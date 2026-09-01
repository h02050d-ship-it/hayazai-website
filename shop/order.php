<?php
// =====================================================
// ひのき魂 オンラインストア 注文受付API
// 呼び出し元: https://h02050d-ship-it.github.io/hinokidamashii/
// 価格はクライアントを信用せず data/products.js から再計算する
// （ヤフー実売 × 0.95 → 10円未満切捨て）
// =====================================================

define('SHOP_EMAIL', 'info@hayazai.com');
define('SHOP_NAME',  'ひのき魂');
define('ALLOW_ORIGIN', 'https://h02050d-ship-it.github.io');

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: ' . ALLOW_ORIGIN);
header('X-Robots-Tag: noindex, nofollow', true);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'errors' => ['POST only']]);
    exit;
}

function h($str) {
    return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
}

// ボット対策（ハニーポット）: 埋まっていたら成功を装って捨てる
if (!empty($_POST['website'])) {
    echo json_encode(['ok' => true, 'orderNo' => 'HD' . date('Ymd') . '-OK']);
    exit;
}

$type       = h($_POST['customer_type'] ?? '個人');
$name       = h($_POST['name']       ?? '');
$company    = h($_POST['company']    ?? '');
$email      = h($_POST['email']      ?? '');
$tel        = h($_POST['tel']        ?? '');
$zip        = h($_POST['zip']        ?? '');
$prefecture = h($_POST['prefecture'] ?? '');
$address1   = h($_POST['address1']   ?? '');
$address2   = h($_POST['address2']   ?? '');
$note       = h($_POST['note']       ?? '');
$cart       = json_decode($_POST['cart_json'] ?? '[]', true) ?: [];

$errors = [];
if (!$name)       $errors[] = 'お名前は必須です';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'メールアドレスが正しくありません';
if (!$tel)        $errors[] = '電話番号は必須です';
if (!$prefecture) $errors[] = '都道府県は必須です';
if (!$address1)   $errors[] = '住所は必須です';
if (empty($cart)) $errors[] = '商品が選択されていません';

// ---- 商品マスタ読込（data/products.js をパース）----
$catalog = [];
$src = @file_get_contents(__DIR__ . '/../data/products.js');
if ($src && preg_match_all(
    "/\{\s*id:'(?<id>[^']+)',\s*cat:'(?<cat>[^']*)',\s*grade:'(?<grade>[^']*)',\s*quality:'(?<quality>[^']*)',\s*thick:(?<thick>\d+),\s*width:(?<width>\d+),\s*length:(?<length>\d+),\s*qty:(?<qty>\d+),\s*price:(?<price>\d+),.*?name:'(?<name>[^']+)'/u",
    $src, $m, PREG_SET_ORDER)) {
    foreach ($m as $row) {
        if (!in_array($row['cat'], ['flooring15', 'flooring12', 'panel'])) continue;
        if ((int)$row['qty'] <= 0 || (int)$row['price'] <= 0) continue;
        $catalog[$row['id']] = $row;
    }
}
if (empty($catalog)) $errors[] = '商品マスタを読み込めませんでした。時間をおいて再度お試しください';

// ---- 金額再計算 ----
$lines = [];
$total = 0;
if (empty($errors)) {
    foreach ($cart as $item) {
        $id  = (string)($item['id'] ?? '');
        $qty = max(1, min(999, (int)($item['qty'] ?? 0)));
        if (!isset($catalog[$id])) { $errors[] = "商品が見つかりません: {$id}"; continue; }
        $p = $catalog[$id];
        $unit = (int)(floor((int)$p['price'] * 0.95 / 10) * 10); // 5%引き・10円未満切捨て
        $sub  = $unit * $qty;
        $total += $sub;
        $lines[] = [
            'name' => "桧" . ($p['cat'] === 'panel' ? '羽目板' : 'フローリング')
                . " {$p['thick']}×{$p['width']}×{$p['length']}mm {$p['quality']}（{$p['grade']}級・{$p['qty']}枚入）",
            'unit' => $unit, 'qty' => $qty, 'sub' => $sub, 'mall' => (int)$p['price'],
        ];
    }
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    exit;
}

$orderNo = 'HD' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));

$itemText = '';
foreach ($lines as $l) {
    $itemText .= sprintf("  ・%s\n    単価 %s円 × %d = %s円\n",
        $l['name'], number_format($l['unit']), $l['qty'], number_format($l['sub']));
}
$totalText = number_format($total);

$customerBody = <<<EOT
{$name} 様

このたびは「ひのき魂」にご注文いただき、誠にありがとうございます。
以下の内容でご注文を受け付けました。

■ 受付番号
  {$orderNo}

■ ご注文内容
{$itemText}
  ──────────────────
  商品合計（税込）: {$totalText}円
  ※送料が別途かかります。

このあと在庫を確認のうえ、送料込みの合計金額とお振込先を
1営業日以内に担当よりメールでご案内いたします。
（お支払いはそのご案内の後です。まだお振込みは不要です）

■ お届け先
  〒{$zip} {$prefecture}{$address1} {$address2}

■ お客様情報
  区分：{$type}
  お名前：{$name}
  会社名：{$company}
  TEL：{$tel}
  Email：{$email}

■ 備考
  {$note}

ご不明な点はこのメールにそのまま返信ください。
──────────────────────────
無垢ひのき工場直販 ひのき魂
（運営：株式会社林材木店）
〒437-1203 静岡県磐田市福田5490-47
Email：info@hayazai.com
EOT;

$shopBody = <<<EOT
【ひのき魂 新規注文】 受付番号：{$orderNo}

■ お客様情報
  区分：{$type}
  お名前：{$name}
  会社名：{$company}
  TEL：{$tel}
  Email：{$email}

■ お届け先
  〒{$zip} {$prefecture}{$address1} {$address2}

■ ご注文内容（単価＝ヤフー実売×0.95・10円未満切捨て／サーバー側で再計算済み）
{$itemText}
  商品合計（税込）: {$totalText}円

※送料込みの合計金額とお振込先を返信してください（1営業日以内）。

■ 備考
  {$note}
EOT;

$headers_customer = "From: " . SHOP_NAME . " <" . SHOP_EMAIL . ">\r\n"
    . "Reply-To: " . SHOP_EMAIL . "\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";
$headers_shop = "From: " . SHOP_NAME . " <" . SHOP_EMAIL . ">\r\n"
    . "Reply-To: {$email}\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";
$envelope = '-f ' . SHOP_EMAIL;

mb_language('Japanese');
mb_internal_encoding('UTF-8');
$sent1 = mb_send_mail($email,     "[ひのき魂] ご注文を受け付けました（受付番号：{$orderNo}）", $customerBody, $headers_customer, $envelope);
$sent2 = mb_send_mail(SHOP_EMAIL, "【ひのき魂 注文】{$name} 様より {$totalText}円（{$orderNo}）",  $shopBody,     $headers_shop,     $envelope);

echo json_encode(['ok' => true, 'orderNo' => $orderNo, 'total' => $total], JSON_UNESCAPED_UNICODE);
