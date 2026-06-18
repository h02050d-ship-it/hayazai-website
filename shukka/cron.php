<?php
// =====================================================
// 出荷自動通知 cron（平日 9/12/15 実行想定・PHP8.3 CLIで実行）
//  - 新規(未送信=shk_sentなし)があれば「出荷依頼」を送信し、送った分を送信済みにマーク
//  - 新規がなく、月/木の9時なら「現状報告」を送信
//  - dryrun 引数を付けると送信・マークせず、生成文面を表示するだけ
// 実行例: /usr/bin/php8.3 /path/to/shukka/cron.php          （本番）
//         /usr/bin/php8.3 /path/to/shukka/cron.php dryrun    （テスト）
// =====================================================
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('cli only'); }

date_default_timezone_set('Asia/Tokyo');
$DRY = in_array('dryrun', $argv, true);

$cfg   = require __DIR__ . '/config.php';
$token = isset($cfg['channel_access_token']) ? $cfg['channel_access_token'] : '';
$fb    = isset($cfg['firebase_secret']) ? $cfg['firebase_secret'] : '';
$DBURL = 'https://kakou-yotei-default-rtdb.firebaseio.com';

$now  = new DateTime('now');
$dow  = (int)$now->format('N'); // 1=Mon .. 7=Sun
$hour = (int)$now->format('G');

if ($dow >= 6) { logline("weekend skip"); echo "weekend skip\n"; exit; }

// --- Firebase 読み取り ---
$raw = httpGet($DBURL . '/items.json?auth=' . urlencode($fb));
$items = json_decode($raw, true);
if (!is_array($items)) { logline("read failed"); echo "read failed\n"; exit; }

// --- 候補（非アーカイブ・長さあり・楽天/ヤフ/ネット除外）---
$cands = array();
foreach ($items as $key => $it) {
    if (!is_array($it)) continue;
    if (isset($it['archived']) && $it['archived'] === '1') continue;
    $l = isset($it['length']) ? trim((string)$it['length']) : '';
    if ($l === '' || $l === '-') continue;
    if (shkSkip(isset($it['customer']) ? $it['customer'] : '')) continue;
    if (preg_match('/西濃/u', isset($it['remark']) ? (string)$it['remark'] : '')) continue;
    $it['_key'] = $key;
    $cands[$key] = $it;
}
// 新規＝未送信
$news = array();
foreach ($cands as $k => $it) { if (empty($it['shk_sent'])) $news[$k] = $it; }

$action = 'none';
if (count($news) > 0) {
    // 新規があれば、未出荷の全品目（🆕新規＋既送で未出荷の残り）を送る。
    // 送るのは候補(=非アーカイブ)全部。実際に出荷完了(archived)するまでリストに残る。
    $msg = buildMsg('【出荷依頼】', array_values($cands), true);
    if ($DRY) { echo "[DRYRUN 出荷依頼]\n$msg\n"; $action = 'dry_send:' . count($cands) . '(new ' . count($news) . ')'; }
    elseif (broadcast($token, $msg)) {
        // 新規だけを送信済みにマーク（次回は🆕が外れるが、未出荷ならリストには残る）
        $ts = (int)round(microtime(true) * 1000);
        foreach ($news as $k => $it) {
            fbPatch($DBURL . '/items/' . rawurlencode($k) . '.json?auth=' . urlencode($fb), array('shk_sent' => $ts));
        }
        $action = 'sent:' . count($cands) . '(new ' . count($news) . ')';
    } else { $action = 'send_failed'; }
} else {
    // 現状報告：月(1)/木(4) の 9時のみ
    if ($hour === 9 && ($dow === 1 || $dow === 4)) {
        if (count($cands) > 0) {
            $msg = buildMsg('【現状報告】', array_values($cands), false);
            if ($DRY) { echo "[DRYRUN 現状報告]\n$msg\n"; $action = 'dry_status:' . count($cands); }
            else { $action = broadcast($token, $msg) ? ('status_sent:' . count($cands)) : 'status_failed'; }
        } else { $action = 'status_no_items'; }
    } else { $action = 'no_new_no_status'; }
}

logline("dow=$dow hour=$hour new=" . count($news) . ($DRY ? " DRY" : "") . " -> $action");
echo "$action\n";

// ===== helpers =====
function shkSkip($c){ return (bool)preg_match('/^(ヤフ|楽天|ネット)/u', trim((string)$c)); }
function shkNum($v){ $v = str_replace(',', '', (string)$v); return is_numeric($v) ? (float)$v : null; }
function numStr($n){ return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.'); }
function shkLenM($l){ $n = preg_replace('/[^0-9.]/', '', (string)$l); if ($n === '') return (string)$l; return numStr(round(((float)$n) / 1000, 2)) . 'm'; }
function shkDone($it){ $o = isset($it['order']) ? (int)$it['order'] : 0; return $o >= 9; }
function shkBun($it){ $a = shkNum(isset($it['actual']) ? $it['actual'] : ''); return $a !== null ? $a : shkNum(isset($it['planned']) ? $it['planned'] : ''); }

function buildMsg($title, $list, $markNew){
    $g = array();
    foreach ($list as $it) {
        $c = trim((string)(isset($it['customer']) ? $it['customer'] : ''));
        if ($c === '') $c = '(取引先なし)';
        $g[$c][] = $it;
    }
    uksort($g, 'strcmp');
    $done = 0; $und = 0; $blocks = array();
    foreach ($g as $c => $arr) {
        usort($arr, function($a, $b){
            $da = shkDone($a) ? 1 : 0; $db = shkDone($b) ? 1 : 0;
            if ($da !== $db) return $db - $da;
            $la = shkNum($a['length']); $lb = shkNum($b['length']);
            return $la <=> $lb;
        });
        $lines = array();
        foreach ($arr as $it) {
            if (shkDone($it)) $done++; else $und++;
            $bun = shkBun($it);
            $nw  = ($markNew && empty($it['shk_sent'])) ? '🆕 ' : '';
            $bunStr = $bun !== null ? (numStr($bun) . '束') : '?束';
            $lines[] = $nw . shkLenM($it['length']) . ' ' . $bunStr . '（' . (shkDone($it) ? '製造完了' : '製造未完了') . '）';
        }
        $blocks[] = '■ ' . $c . "\n" . implode("\n", $lines);
    }
    $txt = $title . ' 林材木店  ' . date('Y/n/j') . "\n\n" . implode("\n\n", $blocks);
    $txt .= "\n\n計" . count($list) . "件（製造完了" . $done . "／製造未完了" . $und . "）";
    return $txt;
}

function httpGet($url){
    $ch = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20));
    $r = curl_exec($ch); curl_close($ch); return $r;
}
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
function logline($s){
    @mkdir(__DIR__ . '/state', 0775, true);
    @file_put_contents(__DIR__ . '/state/cron.log', date('c') . ' ' . $s . "\n", FILE_APPEND);
}
