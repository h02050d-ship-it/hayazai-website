<?php
// =====================================================
// 出荷自動通知 cron（平日 9/12/15 実行想定・PHP8.3 CLIで実行）
//  - 新規(未送信=shk_sentなし)があれば「出荷依頼」を送信し、送った分を送信済みにマーク
//  - 新規が無ければ何もしない（※月/木9時の現状報告は2026-06-29に廃止）
//  - dryrun 引数を付けると送信・マークせず、生成文面を表示するだけ
// 実行例: /usr/bin/php8.3 /path/to/shukka/cron.php          （本番）
//         /usr/bin/php8.3 /path/to/shukka/cron.php dryrun    （テスト）
// =====================================================
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('cli only'); }
require __DIR__ . '/lib.php';

date_default_timezone_set('Asia/Tokyo');
$DRY = in_array('dryrun', $argv, true);

$cfg   = require __DIR__ . '/config.php';
$token = isset($cfg['channel_access_token']) ? $cfg['channel_access_token'] : '';
$fb    = isset($cfg['firebase_secret']) ? $cfg['firebase_secret'] : '';
$DBURL = 'https://kakou-yotei-default-rtdb.firebaseio.com';

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
    // 新規(未送信)があれば、未出荷の全品目を送る（🆕表示はしない）。出荷完了(archived)するまで残る。
    $msg = shkBuildMsg('', array_values($cands), false);
    if ($DRY) { echo "[DRYRUN 出荷依頼]\n$msg\n"; $action = 'dry_send:' . count($cands) . '(new ' . count($news) . ')'; }
    elseif (broadcast($token, $msg)) {
        $ts = (int)round(microtime(true) * 1000);
        foreach ($news as $k => $it) {
            fbPatch($DBURL . '/items/' . rawurlencode($k) . '.json?auth=' . urlencode($fb), array('shk_sent' => $ts));
        }
        shkSetLastSendDate($fb, $now->format('Y-m-d'));
        $action = 'sent:' . count($cands) . '(new ' . count($news) . ')';
    } else { $action = 'send_failed'; }
} else {
    // 新規(未送信)なし → 何もしない。（月/木9時の現状報告は廃止：2026-06-29）
    $action = 'no_new';
}

cronLog("dow=$dow hour=$hour new=" . count($news) . ($DRY ? " DRY" : "") . " -> $action");
echo "$action\n";

// ===== cron専用 helpers =====
function fbPatch($url, $data){
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    ));
    $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code >= 200 && $code < 300;
}
function broadcast($token, $text){
    $payload = json_encode(array('messages' => array(array('type' => 'text', 'text' => $text))), JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.line.me/v2/bot/message/broadcast');
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
