<?php
// =====================================================
// 出荷専用LINE Webhook
//  - 「現状」等のキーワード → 最新の出荷依頼を自動返信
//  - それ以外のメッセージ   → info@hayazai.com へメール通知（送信者・本文）
// チャンネル: @961fvwwp。署名検証は channel_secret で実施。
// =====================================================
require __DIR__ . '/lib.php';
date_default_timezone_set('Asia/Tokyo');
mb_internal_encoding('UTF-8');
mb_language('uni');

$cfg    = require __DIR__ . '/config.php';
$token  = isset($cfg['channel_access_token']) ? $cfg['channel_access_token'] : '';
$secret = isset($cfg['channel_secret']) ? $cfg['channel_secret'] : '';
$fb     = isset($cfg['firebase_secret']) ? $cfg['firebase_secret'] : '';

$body = file_get_contents('php://input');
$sig  = isset($_SERVER['HTTP_X_LINE_SIGNATURE']) ? $_SERVER['HTTP_X_LINE_SIGNATURE'] : '';

// 署名検証
if ($secret === '' || $sig === '' || !hash_equals(base64_encode(hash_hmac('sha256', $body, $secret, true)), $sig)) {
    http_response_code(403); exit;
}
http_response_code(200); // LINEへは即200を返す

$data = json_decode($body, true);
if (!is_array($data) || empty($data['events'])) { exit; }

$KEYWORDS = array('現状', '状況', '一覧', '出荷状況');

foreach ($data['events'] as $ev) {
    if (!isset($ev['type']) || $ev['type'] !== 'message') continue;
    $m = isset($ev['message']) ? $ev['message'] : array();
    if (!isset($m['type']) || $m['type'] !== 'text') continue;

    $text       = trim((string)(isset($m['text']) ? $m['text'] : ''));
    $replyToken = isset($ev['replyToken']) ? $ev['replyToken'] : '';
    $userId     = isset($ev['source']['userId']) ? $ev['source']['userId'] : '';

    $isKeyword = false;
    foreach ($KEYWORDS as $kw) { if (mb_strpos($text, $kw) !== false) { $isKeyword = true; break; } }

    if ($isKeyword) {
        // 現状を自動返信
        $items = shkFetchItems($fb);
        if ($items === null) {
            $msg = '現状を取得できませんでした。お手数ですがしばらくして再度お試しください。';
        } else {
            $cands = shkCandidates($items);
            $msg = count($cands) > 0
                ? shkBuildMsg('', array_values($cands), false)
                : '現在、出荷予定の品目はありません。';
        }
        replyText($token, $replyToken, $msg);
        whLog("keyword reply text=" . str_replace("\n", "/", $text));
    } else {
        // info@ へメール通知
        $name = getDisplayName($token, $userId);
        notifyEmail($name, $text);
        whLog("mail from=$name text=" . str_replace("\n", "/", $text));
    }
}

function replyText($token, $replyToken, $text){
    if (!$replyToken) return;
    $payload = json_encode(array('replyToken' => $replyToken, 'messages' => array(array('type' => 'text', 'text' => $text))), JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.line.me/v2/bot/message/reply');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: Bearer ' . $token),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    ));
    curl_exec($ch); curl_close($ch);
}
function getDisplayName($token, $userId){
    if (!$userId) return '(不明)';
    $ch = curl_init('https://api.line.me/v2/bot/profile/' . rawurlencode($userId));
    curl_setopt_array($ch, array(
        CURLOPT_HTTPHEADER => array('Authorization: Bearer ' . $token),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
    ));
    $r = curl_exec($ch); curl_close($ch);
    $p = json_decode($r, true);
    return (is_array($p) && isset($p['displayName']) && $p['displayName'] !== '') ? $p['displayName'] : '(不明)';
}
function notifyEmail($name, $text){
    $to = 'info@hayazai.com';
    $subject = '【出荷LINE】' . $name . ' さんからメッセージ';
    $bodyMsg = "出荷専用LINEにメッセージが届きました。\n\n"
             . "送信者: " . $name . "\n"
             . "内容:\n" . $text . "\n\n"
             . "--\nLINE公式アカウント Manager（または出荷専用LINEアプリ）で返信してください。";
    $headers = "From: info@hayazai.com\r\n";
    @mb_send_mail($to, $subject, $bodyMsg, $headers);
}
function whLog($s){
    @mkdir(__DIR__ . '/state', 0775, true);
    @file_put_contents(__DIR__ . '/state/webhook.log', date('c') . ' ' . $s . "\n", FILE_APPEND);
}
