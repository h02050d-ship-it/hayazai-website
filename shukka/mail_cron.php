<?php
// =====================================================
// 出荷通知 未送信チェック（1日1回・info@hayazai.com へメール）
//  - 加工予定表で「出荷」タグが付いている行（内藤・2/3/4m・出荷依頼LINE未送信）が
//    残っている時だけメールする。0件なら何も送らない。
//  - 対象判定は cron.php と同じ shkCandidates()＋shk_sent無し（画面の「出荷」タグと同条件）。
//  - 土日祝・年末年始は送らない（静的祝日表 = line/pending_lib.php の pendingHolidays）。
// crontab: 0 9 * * 1-5 /usr/bin/php8.3 ~/hayazai.com/public_html/shukka/mail_cron.php
// 実行例:  php mail_cron.php dryrun   （送らず本文を表示・曜日判定も外す）
// =====================================================
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('cli only'); }
mb_language('Japanese');
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');
require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/line/pending_lib.php';   // pendingIsBusinessDay（静的祝日表）

$DRY = in_array('dryrun', $argv, true);
$TO = 'info@hayazai.com';
$FROM = 'info@hayazai.com';

if (!$DRY && !pendingIsBusinessDay(time())) { mcLog('holiday skip'); exit(0); }

$cfg = require __DIR__ . '/config.php';
$fb = isset($cfg['firebase_secret']) ? $cfg['firebase_secret'] : '';
$items = shkFetchItems($fb);
if ($items === null) {
    // 読めなかった＝黙らせない（失敗も知らせる）
    mcLog('read failed');
    if (!$DRY) @mb_send_mail($TO, '【出荷通知チェック】加工予定表の読込に失敗しました', "Firebaseから加工予定表を読めませんでした。\n本日の未送信チェックはできていません。", 'From: ' . $FROM);
    exit(1);
}

$pend = array();
foreach (shkCandidates($items) as $k => $it) { if (empty($it['shk_sent'])) $pend[$k] = $it; }
if (count($pend) === 0) { mcLog('no pending'); echo "no pending\n"; exit(0); }

// 取引先ごとにまとめる（長さ昇順）
$g = array();
foreach ($pend as $it) { $c = trim((string)$it['customer']); $g[$c][] = $it; }
ksort($g);
$lines = array();
foreach ($g as $c => $arr) {
    usort($arr, function ($a, $b) { return (shkNum($a['length']) ?: 0) <=> (shkNum($b['length']) ?: 0); });
    $lines[] = '■' . $c;
    foreach ($arr as $it) {
        $due = isset($it['due']) && $it['due'] !== '' ? '　出荷日 ' . str_replace(',', ' or ', (string)$it['due']) : '';
        $lines[] = '　' . shkLenM($it['length']) . ' ' . (isset($it['grade']) ? $it['grade'] . ' ' : '')
                 . shkNumStr(shkBun($it)) . '束（' . (shkDone($it) ? '製造完了' : '製造中') . '）' . $due;
    }
}
$subject = '【出荷通知 未送信】' . count($pend) . '件（' . implode('・', array_keys($g)) . '）';
$body = "内藤運輸への出荷通知（出荷依頼LINE）がまだの注文があります。\n"
      . "加工予定表で「出荷」タグが付いている行です。\n\n"
      . implode("\n", $lines) . "\n\n"
      . "加工予定表の「🚚 出荷依頼」→「📤 業者へ送信」で送ると、このメールは届かなくなります。\n"
      . "https://h02050d-ship-it.github.io/kakou-yotei/\n";

if ($DRY) { echo "[DRYRUN] To: $TO\nSubject: $subject\n\n$body"; exit(0); }
$ok = @mb_send_mail($TO, $subject, $body, 'From: ' . $FROM);
mcLog('pending=' . count($pend) . ' mail=' . ($ok ? 'ok' : 'NG'));
echo ($ok ? 'sent' : 'mail failed') . "\n";

function mcLog($s){
    @mkdir(__DIR__ . '/state', 0775, true);
    @file_put_contents(__DIR__ . '/state/mail_cron.log', date('c') . ' ' . $s . "\n", FILE_APPEND);
}
