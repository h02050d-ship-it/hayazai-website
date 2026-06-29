<?php
// =====================================================
// 出荷依頼ブロードキャスト送信エンドポイント
// 加工予定表（GitHub Pages）から呼ばれ、出荷専用LINEの友だち全員へ
// テキストをブロードキャスト送信する。トークンはサーバー側 config.php のみに保管。
// PIN照合で送信をガード（PINはクライアントソースに出さない）。
// =====================================================
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/lib.php';
date_default_timezone_set('Asia/Tokyo');

// --- CORS: 加工予定表のオリジンのみ許可 ---
$ALLOW_ORIGIN = 'https://h02050d-ship-it.github.io';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === $ALLOW_ORIGIN) {
    header('Access-Control-Allow-Origin: ' . $ALLOW_ORIGIN);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method']); exit;
}

$cfgPath = __DIR__ . '/config.php';
if (!is_file($cfgPath)) { http_response_code(500); echo json_encode(['ok' => false, 'error' => 'no_config']); exit; }
$cfg = require $cfgPath;
$token = (string)($cfg['channel_access_token'] ?? '');
$pin   = (string)($cfg['send_pin'] ?? '');
$fb    = (string)($cfg['firebase_secret'] ?? '');

$raw = file_get_contents('php://input');
$in  = json_decode($raw, true);
if (!is_array($in)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'bad_json']); exit; }

$reqPin = (string)($in['pin'] ?? '');
$text   = trim((string)($in['text'] ?? ''));
$keys   = (isset($in['keys']) && is_array($in['keys'])) ? $in['keys'] : array();

if ($pin === '' || !hash_equals($pin, $reqPin)) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'pin']); exit;
}
if ($text === '') { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'empty']); exit; }
if (mb_strlen($text) > 4900) { $text = mb_substr($text, 0, 4900); }

// --- LINE broadcast ---
$payload = json_encode(['messages' => [['type' => 'text', 'text' => $text]]], JSON_UNESCAPED_UNICODE);
$ch = curl_init('https://api.line.me/v2/bot/message/broadcast');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
]);
$resp = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$cerr = curl_error($ch);
curl_close($ch);

// --- best-effort ログ ---
@mkdir(__DIR__ . '/state', 0775, true);
@file_put_contents(__DIR__ . '/state/send.log',
    date('c') . " code=$code text=" . str_replace("\n", '/', $text) . "\n", FILE_APPEND);

if ($code >= 200 && $code < 300) {
    // 手動送信したぶんをサーバー側で「送信済み(shk_sent)」にマーク＋本日送信済みを記録。
    // → 自動cronが同じ内容を1時間後などに再送しないようにする（手動優先・重複防止）。
    $marked = 0;
    if ($fb !== '') {
        $items = shkFetchItems($fb);
        if (is_array($items)) {
            $ts = (int)round(microtime(true) * 1000);
            $marked = shkMarkSent($fb, $items, $keys, $ts);
            shkSetLastSendDate($fb, date('Y-m-d'));
        }
    }
    echo json_encode(['ok' => true, 'marked' => $marked]);
} else {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'line', 'code' => $code, 'detail' => $resp, 'curl' => $cerr]);
}
