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

// ハニーポット（bot は無音で成功扱い）
if (!empty($_POST['website'])) {
  respond(true);
}

$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$channel = $_POST['channel'] ?? '';
$order   = trim($_POST['order_no'] ?? '');
$place   = trim($_POST['place'] ?? '');
$comment = trim($_POST['comment'] ?? '');
$reward  = trim($_POST['reward'] ?? '');
$consent = $_POST['consent'] ?? '';

$channels = [
  'rakuten' => '楽天市場店',
  'yahoo'   => 'Yahoo!ショッピング店',
  'own'     => '公式サイト',
];

// バリデーション
if ($name === '' || mb_strlen($name) > 100)            respond(false, 'name');
if (!filter_var($email, FILTER_VALIDATE_EMAIL))         respond(false, 'email');
if (!isset($channels[$channel]))                        respond(false, 'channel');
if ($order === '' || mb_strlen($order) > 100)           respond(false, 'order_no');
if ($consent !== '1')                                   respond(false, 'consent');
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

// メタ情報保存
$meta = [
  '受付日時'   => date('Y-m-d H:i:s'),
  'お名前'     => $name,
  'メール'     => $email,
  '購入店舗'   => $channels[$channel],
  '注文番号'   => $order,
  '施工箇所'   => $place,
  '謝礼種別'   => $reward,
  '写真枚数'   => count($saved),
  'ご感想'     => $comment,
];
$metaText = '';
foreach ($meta as $k => $v) $metaText .= "■ {$k}\n{$v}\n\n";
file_put_contents($dir . '/meta.txt', $metaText);

// CSVログ追記
$logLine = [
  date('Y-m-d H:i:s'), $subDir, $name, $email, $channels[$channel],
  $order, $reward, count($saved), str_replace(["\r", "\n"], ' ', mb_substr($comment, 0, 200)),
];
$fp = fopen($baseDir . '/log.csv', 'a');
if ($fp) { fputcsv($fp, $logLine); fclose($fp); }

// 通知メール（自分宛て）
$subject = "【施工写真】{$name}様より受付（{$channels[$channel]}・" . count($saved) . '枚）';
$body = "施工写真のご提供を受け付けました。\n\n"
      . $metaText
      . "■ 保存先\n~/hayazai.com/photo_uploads/{$subDir}/\n\n"
      . "確認後、謝礼（{$reward} 300円分）の進呈をお願いします。";
@mb_send_mail(NOTIFY_TO, $subject, $body, 'From: ' . FROM_EMAIL);

// 応募者への受付確認メール
$ackSubject = '【林材木店】施工写真を受け付けました';
$ackBody = "{$name} 様\n\n"
         . "このたびは施工写真のご提供、誠にありがとうございます。\n"
         . "以下の内容で受け付けました。\n\n"
         . "■ ご購入店舗：{$channels[$channel]}\n"
         . "■ ご注文番号：{$order}\n"
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
