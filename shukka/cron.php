<?php
// =====================================================
// 出荷リマインド cron（平日 9/12/15 実行想定・PHP8.3 CLIで実行）
//  - ★vendor（内藤運輸）への自動出荷依頼は廃止（2026-06-30）。送信はスタッフ手動のみ。
//  - 未送信(shk_sentなし)の出荷候補が残っていれば、社内通知LINE(@811hkagd)で
//    スタッフ(大樹)へ「出荷通知が出ていません」とリマインドpushするだけ。
//  - 手動送信(send.php)が走ると shk_sent が付くのでリマインドは止まる。
//  - 新規が無ければ何もしない。dryrun で送信せず文面表示。
// 実行例: /usr/bin/php8.3 /path/to/shukka/cron.php          （本番）
//         /usr/bin/php8.3 /path/to/shukka/cron.php dryrun    （テスト）
// =====================================================
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('cli only'); }
require __DIR__ . '/lib.php';

date_default_timezone_set('Asia/Tokyo');
$DRY = in_array('dryrun', $argv, true);

$cfg     = require __DIR__ . '/config.php';
$fb      = isset($cfg['firebase_secret']) ? $cfg['firebase_secret'] : '';
$ntoken  = isset($cfg['naisya_token']) ? $cfg['naisya_token'] : '';        // 社内通知LINEのトークン
$nidsRaw = isset($cfg['notify_user_ids']) ? $cfg['notify_user_ids'] : '';   // カンマ区切りのuserId
$nids    = array_values(array_filter(array_map('trim', explode(',', $nidsRaw))));

$now  = new DateTime('now');
$dow  = (int)$now->format('N'); // 1=Mon .. 7=Sun
$hour = (int)$now->format('G');

if ($dow >= 6) { cronLog("weekend skip"); echo "weekend skip\n"; exit; }

$items = shkFetchItems($fb);
if ($items === null) { cronLog("read failed"); echo "read failed\n"; exit; }

$cands = shkCandidates($items);
$news = array();
foreach ($cands as $k => $it) { if (empty($it['shk_sent'])) $news[$k] = $it; }

$action = 'none';
if (count($news) > 0) {
    // 未送信の出荷候補が残っている → スタッフへ「出荷通知が出ていません」リマインド。
    $msg = shkReminderMsg($news);
    if ($DRY) {
        echo "[DRYRUN リマインド→社内通知LINE]\n$msg\n";
        $action = 'dry_remind:' . count($news) . ' to ' . count($nids);
    } elseif ($ntoken === '' || count($nids) === 0) {
        $action = 'remind_skip_no_config';
    } else {
        $ok = 0;
        foreach ($nids as $uid) { if (pushMessage($ntoken, $uid, $msg)) $ok++; }
        $action = 'reminded:' . count($news) . ' to ' . $ok . '/' . count($nids);
    }
} else {
    // 未送信なし（＝既に手動送信済み or 候補なし）→ 何もしない。
    $action = 'no_pending';
}

cronLog("dow=$dow hour=$hour pending=" . count($news) . ($DRY ? " DRY" : "") . " -> $action");
echo "$action\n";

// ===== cron専用 helpers =====
// スタッフ向けリマインド文（vendorには出さない）
function shkReminderMsg($news){
    $custs = array();
    foreach ($news as $it) {
        $c = trim((string)(isset($it['customer']) ? $it['customer'] : ''));
        if ($c === '') $c = '(取引先なし)';
        if (!isset($custs[$c])) $custs[$c] = 0;
        $custs[$c] += 1;
    }
    $lines = array();
    foreach ($custs as $c => $n) { $lines[] = '・' . $c . ' ' . $n . '件'; }
    return "⚠️ 出荷通知が出ていません\n"
         . "未送信 " . count($news) . "件\n"
         . implode("\n", $lines) . "\n\n"
         . "加工予定表から「📤 業者へ送信」してください。";
}
// 社内通知LINEへ個別push（vendorへのbroadcastではない）
function pushMessage($token, $userId, $text){
    $payload = json_encode(array('to' => $userId, 'messages' => array(array('type' => 'text', 'text' => $text))), JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.line.me/v2/bot/message/push');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: Bearer ' . $token),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    ));
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code >= 200 && $code < 300;
}
function cronLog($s){
    @mkdir(__DIR__ . '/state', 0775, true);
    @file_put_contents(__DIR__ . '/state/cron.log', date('c') . ' ' . $s . "\n", FILE_APPEND);
}
