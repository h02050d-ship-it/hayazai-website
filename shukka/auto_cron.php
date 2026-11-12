<?php
// =====================================================
// 内藤運輸への出荷LINE「10分後に自動送信」（2026-11-12 本人指示）
//  1) 加工予定表に内藤便の新しい品目（出荷タグ＝shk_sent無し）が入ったら、
//     既定の文面（出荷依頼モーダルの既定と同じ＝未出荷の内藤便すべて）を作り、
//     info@hayazai.com へ「HH:MM に自動送信します」とメールする。
//  2) そのメールへの返信を tenryu GAS（info@ の Gmail）で毎分確認する。
//       ・修正文を書いて返信 → その文をすぐ内藤運輸へ送信
//       ・「送らない」       → 送らない（品目は未送信のまま＝従来のリマインドに戻る）
//       ・「OK」             → 既定文をすぐ送信
//       ・何もしない         → 10分後に既定文を送信
//     送信後30分以内に修正文が届いたら【訂正】として追加送信する。
//  3) 加工予定表から手動で送った場合（shk_sent が付いた）は自動送信を取りやめる。
//  土日祝・年末年始は動かない（pendingIsBusinessDay）。新しい案は 8:00〜17:44 だけ作る。
// crontab: * 8-18 * * 1-5 /usr/bin/php8.3 ~/hayazai.com/public_html/shukka/auto_cron.php
// 実行例:  php auto_cron.php dryrun   （メール・LINE・状態保存なし。何をするかだけ表示）
//          php auto_cron.php status   （キューの中身を表示）
// =====================================================
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('cli only'); }
mb_language('Japanese');
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');
require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/line/pending_lib.php';   // pendingIsBusinessDay（静的祝日表）

const AQ_DELAY      = 600;   // 10分後に送信
const AQ_SETTLE     = 120;   // 品目が入ってから2分待って案を作る（入力途中の束数を拾わない・まとめて1通にする）
const AQ_FIX_WINDOW = 1800;  // 送信後30分は訂正の返信を受け付ける
const AQ_TO         = 'info@hayazai.com';
const AQ_FROM       = 'info@hayazai.com';
const AQ_TENRYU     = 'https://script.google.com/macros/s/AKfycbxvEayV4zinadVIbAC1Xegdqeg8I8yZrrHBm9xiY3p_U0MYLiisYxeftBmZ7xlmSAyh/exec';

$DRY = in_array('dryrun', $argv, true);
$STATUS = in_array('status', $argv, true);
$now = time();

$qf = __DIR__ . '/state/auto_queue.json';
@mkdir(__DIR__ . '/state', 0775, true);
if ($STATUS) { echo is_file($qf) ? file_get_contents($qf) : "(empty)\n"; exit(0); }
if (!$DRY) {
    if (!pendingIsBusinessDay($now)) exit(0);
    $h = (int)date('G', $now);
    if ($h < 8 || $h >= 19) exit(0);
}
// 多重起動防止（GAS確認が遅いと次の分と重なるため）
$lock = fopen(__DIR__ . '/state/auto_cron.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { aqLog('locked skip'); exit(0); }

$cfg = require __DIR__ . '/config.php';
$fb = (string)($cfg['firebase_secret'] ?? '');
$lineToken = (string)($cfg['channel_access_token'] ?? '');
$tenryuTok = (string)($cfg['tenryu_token'] ?? '');
$relayKey = (string)($cfg['naisya_relay_key'] ?? '');

$q = is_file($qf) ? json_decode((string)file_get_contents($qf), true) : null;
if (!is_array($q)) $q = array();
$q += array('drafts' => array(), 'seen' => array());

$items = shkFetchItems($fb);
if ($items === null) { aqLog('read failed'); exit(1); }
$cands = shkCandidates($items);                       // 内藤便・未出荷（送信済みも含む＝既定文の中身）
$pend = array();
foreach ($cands as $k => $it) if (empty($it['shk_sent'])) $pend[$k] = $it;

$changed = false;
$out = array();

// ---------- 1. 待機中・送信直後の案を処理 ----------
foreach ($q['drafts'] as $id => &$d) {
    $st = $d['status'] ?? '';
    if ($st === 'waiting') {
        // 手動で送られた（全部 shk_sent が付いた／出荷完了・削除された）→ 取りやめ
        $left = array_values(array_filter($d['keys'], function ($k) use ($pend) { return isset($pend[$k]); }));
        if (!$left) { $d['status'] = 'superseded'; $d['done_at'] = $now; $changed = true; $out[] = "$id superseded(手動送信済み)"; continue; }

        $rep = aqCheckReply($tenryuTok, $d, $DRY);
        if ($rep === null) $d['check_fail'] = ($d['check_fail'] ?? 0) + 1; else $d['check_fail'] = 0;
        if (is_array($rep)) {
            $d['reply_ids'][] = $rep['id']; $changed = true;
            if ($rep['kind'] === 'cancel') {
                $d['status'] = 'cancelled'; $d['done_at'] = $now;
                aqMail($DRY, '【内藤運輸LINE】送信を取りやめました（受付 ' . aqShort($id) . '）',
                    "返信「" . $rep['text'] . "」を受け取りました。内藤運輸へは送っていません。\n\n"
                  . "品目は未送信のまま残っています（加工予定表のタグ「出荷」・従来の未送信リマインドが出ます）。\n"
                  . "あとで送るときは加工予定表の「🚚 出荷依頼」から送ってください。\nhttps://h02050d-ship-it.github.io/kakou-yotei/\n");
                $out[] = "$id cancelled by reply";
                continue;
            }
            $text = $rep['kind'] === 'ok' ? aqDefaultText($cands) : $rep['text'];
            $kind = $rep['kind'] === 'ok' ? 'ok' : 'reply';
            $ok = aqSend($DRY, $lineToken, $fb, $items, $text, array_keys($cands), $relayKey, $kind === 'reply' ? '出荷依頼(修正文・メール返信)' : '出荷依頼(メールでOK)');
            $d['status'] = $ok ? 'sent' : 'failed'; $d['sent_at'] = $now; $d['sent_kind'] = $kind; $d['sent_text'] = $text;
            aqMail($DRY, '【内藤運輸LINE】' . ($ok ? ($kind === 'reply' ? '修正文で送信しました' : '送信しました') : '⚠️送信に失敗しました') . '（受付 ' . aqShort($id) . '）',
                ($ok ? "内藤運輸（出荷LINE）へ次の内容を送信しました。\n\n" : "LINEの送信に失敗しました。加工予定表の「🚚 出荷依頼」から手動で送ってください。\n\n")
              . "────────\n" . $text . "\n────────\n");
            $out[] = "$id sent($kind) ok=" . ($ok ? 1 : 0);
            continue;
        }
        // 返信なし → 時間が来たら既定文で送る（GAS確認が3回続けて失敗した時は、それ以上待たずに送る）
        if ($now >= $d['due']) {
            if ($rep === null && ($d['check_fail'] ?? 0) < 3) { $changed = true; $out[] = "$id due but reply-check failed (" . $d['check_fail'] . ") wait"; continue; }
            $text = aqDefaultText($cands);   // 10分の間に束数などが変わっていれば最新で送る
            $ok = aqSend($DRY, $lineToken, $fb, $items, $text, array_keys($cands), $relayKey, '出荷依頼(10分後自動)');
            $d['status'] = $ok ? 'sent' : 'failed'; $d['sent_at'] = $now; $d['sent_kind'] = 'default'; $d['sent_text'] = $text; $changed = true;
            if (!$ok) {
                aqMail($DRY, '【内藤運輸LINE】⚠️自動送信に失敗しました（受付 ' . aqShort($id) . '）',
                    "LINEの送信に失敗しました。加工予定表の「🚚 出荷依頼」から手動で送ってください。\nhttps://h02050d-ship-it.github.io/kakou-yotei/\n");
            } elseif (aqBody($text) !== aqBody($d['text'])) {
                aqMail($DRY, '【内藤運輸LINE】送信しました（10分の間に内容が変わったため最新で送信・受付 ' . aqShort($id) . '）',
                    "メールでお知らせした後に加工予定表の内容が変わったため、最新の内容で送信しました。\n\n────────\n" . $text . "\n────────\n");
            }
            $out[] = "$id sent(default) ok=" . ($ok ? 1 : 0);
        } elseif ($rep === null) { $changed = true; }
    } elseif ($st === 'sent' && in_array($d['sent_kind'] ?? '', array('default', 'ok'), true) && $now - $d['sent_at'] < AQ_FIX_WINDOW) {
        // 送信後30分は訂正の返信を見る（2分おき）
        if ((int)date('i', $now) % 2) continue;
        $rep = aqCheckReply($tenryuTok, $d, $DRY);
        if (!is_array($rep)) continue;
        $d['reply_ids'][] = $rep['id']; $changed = true;
        if ($rep['kind'] === 'text') {
            $text = "【訂正】先ほどの連絡を以下のとおり訂正します。\n\n" . $rep['text'];
            $ok = aqSend($DRY, $lineToken, $fb, $items, $text, array(), $relayKey, '出荷依頼(訂正・メール返信)');
            $d['sent_kind'] = 'corrected'; $d['fix_text'] = $text; $d['fix_at'] = $now;
            aqMail($DRY, '【内藤運輸LINE】' . ($ok ? '訂正を送信しました' : '⚠️訂正の送信に失敗しました') . '（受付 ' . aqShort($id) . '）',
                ($ok ? "既定の内容は送信済みだったため、訂正として次の内容を追加で送りました。\n\n" : "LINEの送信に失敗しました。内藤運輸へ直接連絡してください。\n\n")
              . "────────\n" . $text . "\n────────\n");
            $out[] = "$id corrected ok=" . ($ok ? 1 : 0);
        } elseif ($rep['kind'] === 'cancel') {
            aqMail($DRY, '【内藤運輸LINE】すでに送信済みでした（受付 ' . aqShort($id) . '）',
                "「送らない」の返信を受け取りましたが、" . date('H:i', $d['sent_at']) . " に送信済みでした。\n取り消しが必要なら内藤運輸へ直接連絡してください。\n");
            $out[] = "$id cancel-after-sent notified";
        }
    }
}
unset($d);

// ---------- 2. 新しい品目 → 案を作ってメール ----------
$inQueue = array();
foreach ($q['drafts'] as $d) if (in_array($d['status'] ?? '', array('waiting', 'cancelled'), true)) foreach ($d['keys'] as $k) $inQueue[$k] = true;
$hasWaiting = false;
foreach ($q['drafts'] as $d) if (($d['status'] ?? '') === 'waiting') $hasWaiting = true;
$news = array();
foreach ($pend as $k => $it) {
    if (isset($inQueue[$k])) continue;
    if (!isset($q['seen'][$k])) { $q['seen'][$k] = $now; $changed = true; }
    $news[$k] = $it;
}
foreach (array_keys($q['seen']) as $k) if (!isset($pend[$k])) { unset($q['seen'][$k]); $changed = true; }

$hm = (int)date('Gi', $now);
if ($news && !$hasWaiting && $hm >= 800 && $hm < 1745) {
    $newest = max(array_map(function ($k) use ($q) { return $q['seen'][$k]; }, array_keys($news)));
    if ($now - $newest >= AQ_SETTLE) {
        $id = 'SHK-' . date('md-Hi', $now);
        $due = $now + AQ_DELAY;
        $text = aqDefaultText($cands);
        $newList = array();
        foreach ($news as $it) $newList[] = trim((string)$it['customer']) . ' ' . shkLenM($it['length']) . ' ' . trim((string)($it['grade'] ?? '')) . ' ' . shkNumStr(shkBun($it)) . '束';
        $custs = array_values(array_unique(array_map(function ($it) { return trim((string)$it['customer']); }, $news)));
        $subject = '【内藤運輸LINE】' . date('H:i', $due) . 'に自動送信します（' . implode('・', $custs) . '） ' . $id;
        $body = "加工予定表に内藤運輸便の品目が追加されました。\n"
              . "下の内容で " . date('H:i', $due) . " に内藤運輸（出荷LINE）へ自動送信します。\n\n"
              . "▼ 送信する内容\n────────\n" . $text . "\n────────\n\n"
              . "■ 修正したいとき\n"
              . "　このメールに返信して、本文に「修正後の文章」を全文で書いてください。\n"
              . "　→ 書いた文章をそのまま内藤運輸へ送信します（受け取りしだい送信・" . date('H:i', $due) . "を待ちません）。\n"
              . "■ 送らないとき\n　「送らない」とだけ書いて返信してください。\n"
              . "■ すぐ送りたいとき\n　「OK」と返信すると、その場で上の内容を送信します。\n\n"
              . "何もしなければ " . date('H:i', $due) . " に上の内容で送信します。\n"
              . "※送信後30分以内に修正文を返信した場合は【訂正】として追加で送ります。\n"
              . "※加工予定表から手動で送信した場合、自動送信は取りやめます。\n\n"
              . "今回新しく入った品目:\n・" . implode("\n・", $newList) . "\n";
        $q['drafts'][$id] = array('id' => $id, 'created' => $now, 'due' => $due, 'keys' => array_keys($news),
            'text' => $text, 'status' => 'waiting', 'reply_ids' => array(), 'check_fail' => 0);
        $ok = aqMail($DRY, $subject, $body);
        if (!$ok && !$DRY) { $q['drafts'][$id]['status'] = 'failed'; $q['drafts'][$id]['err'] = 'mail'; }
        $changed = true;
        $out[] = "$id created keys=" . count($news) . " mail=" . ($ok ? 1 : 0);
        if ($DRY) echo "[DRYRUN mail] $subject\n\n$body\n";
    } else {
        $out[] = 'new=' . count($news) . ' settling';
    }
}

// 古い案は3日で片付ける
foreach ($q['drafts'] as $id => $d) if ($now - ($d['created'] ?? 0) > 3 * 86400) { unset($q['drafts'][$id]); $changed = true; }

if ($changed && !$DRY) file_put_contents($qf, json_encode($q, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
if ($out) aqLog(($DRY ? 'DRY ' : '') . implode(' / ', $out));
if ($DRY) echo "pending=" . count($pend) . " news=" . count($news) . "\n" . implode("\n", $out) . "\n";

// ===== helpers =====
function aqDefaultText($cands){ return shkBuildMsg('', array_values($cands), false); }
// 見出しの日付を除いた本文（日付が変わっただけで「内容が変わった」と判定しないため）
function aqBody($t){ return preg_replace('/^[^\n]*\n/u', '', (string)$t); }
function aqShort($id){ return preg_replace('/^SHK-/', '', $id); }
function aqLog($s){ @file_put_contents(__DIR__ . '/state/auto_cron.log', date('c') . ' ' . $s . "\n", FILE_APPEND); }
function aqMail($dry, $subject, $body){
    if ($dry) { echo "[DRYRUN mail] $subject\n"; return true; }
    return @mb_send_mail(AQ_TO, $subject, $body, 'From: ' . AQ_FROM);
}
// LINE送信＋送信済みマーク＋社内通知の控え。$keys が空なら印は付けない（訂正送信）
function aqSend($dry, $token, $fb, $items, $text, $keys, $relayKey, $by){
    if ($dry) { echo "[DRYRUN LINE] ($by)\n$text\n"; return true; }
    list($code) = shkLineBroadcast($token, $text);
    if ($code < 200 || $code >= 300) return false;
    if ($keys) {
        $ts = (int)round(microtime(true) * 1000);
        shkMarkSent($fb, $items, $keys, $ts);
        shkSetLastSendDate($fb, date('Y-m-d'));
    }
    shkNotifyNaisya_($text, $relayKey, $by);
    return true;
}
// tenryu GAS で info@ の返信を確認。戻り: null=確認失敗 / false=新しい返信なし / array(id,kind,text)
function aqCheckReply($tok, $d, $dry){
    if ($tok === '') return null;
    $url = AQ_TENRYU . '?token=' . urlencode($tok) . '&act=shk_check&tok=' . urlencode($d['id']) . '&after=' . ($d['created'] * 1000);
    $ch = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 25));
    $r = curl_exec($ch); curl_close($ch);
    $j = json_decode((string)$r, true);
    if (!is_array($j) || empty($j['ok'])) { aqLog($d['id'] . ' check failed: ' . mb_substr((string)$r, 0, 120)); return null; }
    $done = $d['reply_ids'] ?? array();
    $cand = null;
    foreach (($j['replies'] ?? array()) as $rp) {
        if (in_array($rp['id'], $done, true)) continue;
        if ($cand === null || $rp['date'] > $cand['date']) $cand = $rp;   // 一番新しい返信を採用
    }
    if ($cand === null) return false;
    $t = trim((string)$cand['text']);
    if ($t === '') return false;
    $one = preg_replace('/[\s　。．.！!]+/u', '', $t);
    if (preg_match('/^(送らない|送信しない|送らないで(ください)?|中止|キャンセル|取り?消し|ストップ|やめて?|止めて?)$/u', $one)) $kind = 'cancel';
    elseif (preg_match('/^(ok|ｏｋ|おけ|オッケー|そのまま|そのままで|そのままでOK|了解|送って|送信して|お願いします|はい)$/iu', $one)) $kind = 'ok';
    else $kind = 'text';
    if ($dry) echo "[DRYRUN reply] {$cand['id']} kind=$kind\n$t\n";
    return array('id' => $cand['id'], 'kind' => $kind, 'text' => $t);
}
