<?php
// =============================================================
// 施工写真ご提供 受付API（photo.html から fetch で呼ばれる）
// - 写真は public_html の外（~/hayazai.com/photo_uploads/）に保存
//   ※ GitHub Actions の rsync --delete は public_html 内のみのため消えない
// - 受付内容は info@hayazai.com へメール通知＋log.csv に追記
// =============================================================

header('Content-Type: application/json; charset=UTF-8');
mb_language('ja');
mb_internal_encoding('UTF-8');

const NOTIFY_TO   = 'info@hayazai.com';
const FROM_EMAIL  = 'noreply@hayazai.com';
const MAX_PHOTOS  = 10;
const MAX_SIZE    = 15728640; // 15MB/枚

function respond($ok, $error = null, $code = 200) {
  http_response_code($code);
  echo json_encode(['ok' => $ok, 'error' => $error], JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  respond(false, 'method_not_allowed', 405);
}

// 送信元チェック（hayazai.com 以外からの直接POSTを拒否。ヘッダ欠落時は通す）
$src = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
if ($src !== '' && stripos($src, 'hayazai.com') === false) {
  respond(false, 'bad_origin', 403);
}

// ハニーポット（bot は無音で成功扱い）
if (!empty($_POST['website'])) {
  respond(true);
}

// 簡易レート制限（同一IPの連投のみ抑止。ファイル操作失敗時は通す＝誤ブロックしない）
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '') {
  $rateDir = dirname(__DIR__) . '/photo_uploads/rate';
  if (!is_dir($rateDir)) @mkdir($rateDir, 0705, true);
  $rateFile = $rateDir . '/' . substr(sha1($ip), 0, 16) . '.txt';
  $now = time();
  $window = 900;  // 15分
  $limit  = 5;    // 15分あたり5件まで
  $times = [];
  if (is_file($rateFile)) {
    $raw = @file_get_contents($rateFile);
    if ($raw !== false) {
      foreach (explode(',', trim($raw)) as $t) {
        $t = (int)$t;
        if ($t > 0 && ($now - $t) < $window) $times[] = $t;
      }
    }
  }
  if (count($times) >= $limit) respond(false, 'rate_limited', 429);
  $times[] = $now;
  @file_put_contents($rateFile, implode(',', $times), LOCK_EX);
}

$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$channel = $_POST['channel'] ?? '';
$order   = trim($_POST['order_no'] ?? '');
$shop    = trim($_POST['shop_name'] ?? '');
$place   = trim($_POST['place'] ?? '');
$comment = trim($_POST['comment'] ?? '');
$reward  = trim($_POST['reward'] ?? '');
$consent = $_POST['consent'] ?? '';

$channels = [
  'rakuten' => '楽天市場店',
  'yahoo'   => 'Yahoo!ショッピング店',
  'own'     => '公式サイト',
  'market'  => '市場・販売店・工務店',
];

// バリデーション
if ($name === '' || mb_strlen($name) > 100)            respond(false, 'name');
if (!filter_var($email, FILTER_VALIDATE_EMAIL))         respond(false, 'email');
if (!isset($channels[$channel]))                        respond(false, 'channel');
// 注文番号・販売店名は任意（性善説運用。確認は人の目＋注意書きで担保）
if ($channel === 'market') {
  if (mb_strlen($shop) > 200) $shop = mb_substr($shop, 0, 200);
  $order = '';
} else {
  if (mb_strlen($order) > 100) $order = mb_substr($order, 0, 100);
  $shop = '';
}
if ($consent !== '1')                                   respond(false, 'consent');
// 購入元の表示用（注文番号 or 販売店名）
$purchaseRef = $channel === 'market'
  ? ('販売店：' . ($shop !== '' ? $shop : '（未記入）'))
  : ('注文番号：' . ($order !== '' ? $order : '（未記入）'));
if (mb_strlen($place) > 300)   $place   = mb_substr($place, 0, 300);
if (mb_strlen($comment) > 3000) $comment = mb_substr($comment, 0, 3000);

// 謝礼は全チャネル共通でAmazonギフトカード（2026-06-12 Amazon統一決定）
$reward = 'Amazonギフトカード';

// 写真チェック
if (empty($_FILES['photos']) || !is_array($_FILES['photos']['name'])) {
  respond(false, 'no_photos');
}
$count = count($_FILES['photos']['name']);
if ($count < 1 || $count > MAX_PHOTOS) respond(false, 'photo_count');

$allowedMime = [
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/webp' => 'webp',
  'image/heic' => 'heic',
  'image/heif' => 'heif',
];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$valid = [];
for ($i = 0; $i < $count; $i++) {
  if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) respond(false, 'upload_error');
  if ($_FILES['photos']['size'][$i] > MAX_SIZE)          respond(false, 'file_too_large');
  $mime = finfo_file($finfo, $_FILES['photos']['tmp_name'][$i]);
  if (!isset($allowedMime[$mime]))                       respond(false, 'file_type');
  $valid[] = ['tmp' => $_FILES['photos']['tmp_name'][$i], 'ext' => $allowedMime[$mime]];
}
finfo_close($finfo);

// 保存先: public_html の1つ上 /photo_uploads/YYYYMMDD_HHMMSS_xxxx/
$baseDir = dirname(__DIR__) . '/photo_uploads';
$subDir  = date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
$dir     = $baseDir . '/' . $subDir;
if (!is_dir($dir) && !mkdir($dir, 0705, true)) {
  respond(false, 'storage', 500);
}

$saved = [];
foreach ($valid as $i => $f) {
  $fname = sprintf('photo%02d.%s', $i + 1, $f['ext']);
  if (move_uploaded_file($f['tmp'], $dir . '/' . $fname)) {
    $saved[] = $fname;
  }
}
if (count($saved) === 0) respond(false, 'storage', 500);

// 重複チェック（過去ログに同じ注文番号 or 販売店名 or メールがあれば通知にフラグ）
$dupFlag = '';
$logFile = $baseDir . '/log.csv';
if (is_file($logFile)) {
  $existing = file_get_contents($logFile);
  if ($order !== '' && mb_strpos($existing, $order) !== false) {
    $dupFlag = '⚠️ 重複の可能性（同じ注文番号が過去にあり）';
  } elseif ($email !== '' && mb_strpos($existing, $email) !== false) {
    $dupFlag = '⚠️ 重複の可能性（同じメールが過去にあり）';
  }
}

// メタ情報保存
$meta = [
  '受付日時'   => date('Y-m-d H:i:s'),
  'お名前'     => $name,
  'メール'     => $email,
  '購入店舗'   => $channels[$channel],
  '購入の確認' => $purchaseRef,
  '施工箇所'   => $place,
  '謝礼種別'   => $reward,
  '写真枚数'   => count($saved),
  'ご感想'     => $comment,
];
if ($dupFlag !== '') $meta = ['⚠️注意' => $dupFlag] + $meta;
$metaText = '';
foreach ($meta as $k => $v) $metaText .= "■ {$k}\n{$v}\n\n";
file_put_contents($dir . '/meta.txt', $metaText);

// CSVログ追記
$logLine = [
  date('Y-m-d H:i:s'), $subDir, $name, $email, $channels[$channel],
  ($channel === 'market' ? $shop : $order), $reward, count($saved),
  str_replace(["\r", "\n"], ' ', mb_substr($comment, 0, 200)),
];
$fp = fopen($baseDir . '/log.csv', 'a');
if ($fp) { fputcsv($fp, $logLine); fclose($fp); }

// 通知メール（自分宛て）
$flagPrefix = $dupFlag !== '' ? '【要確認】' : '';
$subject = "{$flagPrefix}【施工写真】{$name}様より受付（{$channels[$channel]}・" . count($saved) . '枚）';
$body = ($dupFlag !== '' ? "{$dupFlag}\n\n" : '')
      . "施工写真のご提供を受け付けました。\n\n"
      . $metaText
      . "■ 保存先\n~/hayazai.com/photo_uploads/{$subDir}/\n\n"
      . "確認後、謝礼（{$reward} 300円分）の進呈をお願いします。\n"
      . "※ 注文番号／販売店・ご購入が確認できない、または重複と判断される場合は、応募条件に基づき対象外とできます。";
@mb_send_mail(NOTIFY_TO, $subject, $body, 'From: ' . FROM_EMAIL);

// 応募者への受付確認メール
$ackSubject = '【林材木店】施工写真を受け付けました';
$ackBody = "{$name} 様\n\n"
         . "このたびは施工写真のご提供、誠にありがとうございます。\n"
         . "以下の内容で受け付けました。\n\n"
         . "■ ご購入店舗：{$channels[$channel]}\n"
         . "■ {$purchaseRef}\n"
         . "■ 写真：" . count($saved) . "枚\n"
         . "■ 謝礼：{$reward} 300円分\n\n"
         . "内容を確認のうえ、通常3営業日以内に謝礼のご案内をお送りします。\n"
         . "※ご感想は率直な内容で構いません。内容の良し悪しは謝礼の条件ではありません。\n\n"
         . "─────────────────\n"
         . "株式会社林材木店\n"
         . "TEL: 0538-58-2395（平日9:00〜17:00）\n"
         . "https://hayazai.com/\n"
         . "─────────────────";
@mb_send_mail($email, $ackSubject, $ackBody, 'From: ' . FROM_EMAIL);

respond(true);
