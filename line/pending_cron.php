<?php
// =====================================================================
//  公式LINE 未返信リマインド（サーバーcron・CLI専用）
//    crontab: */30 8-16 * * 1-5 /usr/bin/php8.3 ~/hayazai.com/public_html/line/pending_cron.php
//  仕様（pending_lib.php 冒頭も参照）
//    ・営業時間内（平日8〜17時・祝日除く）だけ動く
//    ・未返信が「受信から3時間以上」かつ「前回通知から3時間以上」→ info@ へ再通知メール
//    ・受信から20時間以上（＝翌営業日以降）→ 再通知のたびに社内LINE（大樹）へも
//      1日1回だけ通知（中継: ~/hayazai.com/line_state/notify_relay.json の url/key。
//      無ければLINEはスキップしメールだけ）
//    ・10営業日を超えたら最終通知して自動終了（放置事故の記録はメールに残る）
// =====================================================================
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('cli only'); }
mb_language('Japanese');
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');
require __DIR__ . '/pending_lib.php';

$CONFIG = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$SECRET = $CONFIG['channel_secret'] ?? 'no-secret';
$TO     = $CONFIG['staff_email'] ?? 'info@hayazai.com';
$FROM   = 'info@hayazai.com'; // 実在アドレス（noreply@ は迷惑メール扱いされた実績あり）

const REMIND_INTERVAL_SEC = 3 * 3600;   // 再通知の間隔
const FIRST_REMIND_SEC    = 3 * 3600;   // 受信からこの時間は待つ（初回通知メールは webhook が即時送っている）
const LINE_AFTER_SEC      = 20 * 3600;  // これ以降はLINEにも
const LINE_INTERVAL_SEC   = 20 * 3600;  // LINEは1日1回
const GIVEUP_DAYS         = 10;         // 営業日換算はせず暦日で判定（十分）

$force = in_array('--force', $argv ?? [], true); // 動作確認用（時間帯チェックを外す）
$now = time();
if (!$force && !pendingInBusinessHours($now)) exit(0);

$relay = null;
$relayPath = dirname(dirname(__DIR__)) . '/line_state/notify_relay.json';
if (is_file($relayPath)) {
    $r = json_decode((string)file_get_contents($relayPath), true);
    if (is_array($r) && !empty($r['url']) && !empty($r['key'])) $relay = $r;
}

function pushDaiki(?array $relay, string $text): bool {
    if (!$relay) return false;
    $ch = curl_init($relay['url']);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: text/plain;charset=utf-8'],
        CURLOPT_POSTFIELDS     => json_encode([
            'key' => $relay['key'], 'mode' => 'notify', 'ntype' => $relay['ntype'] ?? 'line_unreplied',
            'target' => $relay['target'] ?? 'daiki', 'text' => $text,
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    error_log('[line-pending] LINE push ' . $code . ' ' . mb_substr((string)$res, 0, 120));
    return $code === 200;
}

$sent = 0;
foreach (pendingList() as $rec) {
    $first = (int)$rec['first_at'];
    $age   = $now - $first;
    if (!$force && $age < FIRST_REMIND_SEC) continue;
    if (!$force && ($now - (int)($rec['last_remind_at'] ?? 0)) < REMIND_INTERVAL_SEC) continue;

    $giveup = $age > GIVEUP_DAYS * 86400;
    $subject = pendingSubject($rec, true);
    $body    = pendingMailBody($rec, $SECRET, true);
    if ($giveup) {
        $subject = '【公式LINE 未返信・通知終了】' . mb_substr($subject, 0, 60);
        $body = "受信から" . GIVEUP_DAYS . "日を超えたため、この件の再通知を終了します。\n"
              . "返信が必要ならチャットから直接ご対応ください。\n\n" . $body;
    }
    @mb_send_mail($TO, $subject, $body, 'From: ' . $FROM);
    $sent++;

    // 翌営業日以降は社内LINEにも（1日1回）
    if ($age >= LINE_AFTER_SEC && ($now - (int)($rec['last_line_at'] ?? 0)) >= LINE_INTERVAL_SEC) {
        $name = $rec['name'] ?: '（名前不明）';
        $txt  = "📩 公式LINE 未返信 " . pendingElapsedLabel($first, $now) . "\n"
              . $name . " さん（" . date('n/j H:i', $first) . " 受信）\n"
              . mb_strimwidth(preg_replace('/\s+/u', ' ', (string)($rec['texts'][0] ?? '')), 0, 80, '…') . "\n\n"
              . "返信: " . pendingChatUrl($rec['userId']) . "\n"
              . "返信したら: " . pendingDoneUrl($rec['userId'], $SECRET);
        if (pushDaiki($relay, $txt)) $rec['last_line_at'] = $now;
    }

    if ($giveup) {
        pendingDone($rec['userId']);
        continue;
    }
    $rec['last_remind_at'] = $now;
    $rec['remind_count']   = (int)($rec['remind_count'] ?? 0) + 1;
    pendingSave($rec);
}
echo "reminded: {$sent}\n";
