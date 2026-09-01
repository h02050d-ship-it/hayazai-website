<?php
// =====================================================
// ひのき魂 無料サンプル請求 受付API
// 呼び出し元: https://h02050d-ship-it.github.io/hinokidamashii/sample.html
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
function h_list($arr) {
    if (!is_array($arr)) return '';
    return implode('・', array_map('h', array_slice($arr, 0, 10)));
}

// ハニーポット
if (!empty($_POST['website'])) {
    echo json_encode(['ok' => true, 'orderNo' => 'HS' . date('Ymd') . '-OK']);
    exit;
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
$kind       = h_list($_POST['kind']  ?? []);
$grade      = h_list($_POST['grade'] ?? []);

$errors = [];
if (!$name)       $errors[] = 'お名前は必須です';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'メールアドレスが正しくありません';
if (!$tel)        $errors[] = '電話番号は必須です';
if (!$prefecture) $errors[] = '都道府県は必須です';
if (!$address1)   $errors[] = '住所は必須です';
if (!$kind)       $errors[] = 'ご希望の商品を1つ以上お選びください';
if (!$grade)      $errors[] = '確かめたいグレードを1つ以上お選びください';

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'errors' => $errors], JSON_UNESCAPED_UNICODE);
    exit;
}

$no = 'HS' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));

$customerBody = <<<EOT
{$name} 様

このたびは「ひのき魂」の無料サンプルをご請求いただき、
誠にありがとうございます。以下の内容で承りました。

■ 受付番号
  {$no}

■ お送りするサンプル
  商品：{$kind}
  グレード：{$grade}
  ※ 約110×300mmの実物（天然乾燥・超仕上げ品）です。

通常2〜5営業日以内に発送いたします。
サンプル代・送料ともに無料です。

桧の香り、カンナ仕上げのなめらかな手触り、画面では伝わらない本物の色。
どうぞ実物でお確かめください。
触って「違うな」と思われたら、それで構いません。

■ お届け先
  〒{$zip} {$prefecture}{$address1} {$address2}

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
【ひのき魂 サンプル請求】 受付番号：{$no}

■ お客様情報
  お名前：{$name}
  会社名：{$company}
  TEL：{$tel}
  Email：{$email}

■ お届け先
  〒{$zip} {$prefecture}{$address1} {$address2}

■ ご希望
  商品：{$kind}
  グレード：{$grade}

■ 備考
  {$note}

※ 2〜5営業日以内にサンプルを発送してください（送料当店負担）。
EOT;

// mb_send_mail はXserverのmbstring設定でISO-2022-JP変換され本文が壊れるため
// mail()+base64 でUTF-8を明示する（shop/order.php と同方式）
function mime_utf8($str) {
    return '=?UTF-8?B?' . base64_encode($str) . '?=';
}
function send_utf8_mail($to, $subject, $body, $replyTo) {
    $headers = 'From: ' . mime_utf8(SHOP_NAME) . ' <' . SHOP_EMAIL . ">\r\n"
        . "Reply-To: {$replyTo}\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n";
    return mail($to, mime_utf8($subject), chunk_split(base64_encode($body)), $headers, '-f ' . SHOP_EMAIL);
}

send_utf8_mail($email,     "[ひのき魂] 無料サンプルのお申し込みを承りました（{$no}）", $customerBody, SHOP_EMAIL);
send_utf8_mail(SHOP_EMAIL, "【ひのき魂 サンプル請求】{$name} 様（{$no}）",             $shopBody,     $email);

echo json_encode(['ok' => true, 'orderNo' => $no], JSON_UNESCAPED_UNICODE);
