<?php
// =====================================================
// 内藤運輸への出荷LINE「承認してから送信」（2026-11-12 開始・11/13 本人指示で自動送信をやめ承認制に）
//  1) 加工予定表に内藤便の新しい品目（出荷タグ＝shk_sent無し）が入ったら、
//     既定の文面（出荷依頼モーダルの既定と同じ＝未出荷の内藤便すべて・着日条件/個別案件つき）を作り、
//     info@hayazai.com へ「OKで送ります」とメールする（確認メール）。★時間が来ても自動では送らない。
//  2) 予告メールへの返信を tenryu GAS（info@ の Gmail）で毎分確認する。
//       ・「OK」       → いまの文をすぐ送信
//       ・「送らない」 → 送らない（品目は未送信のまま＝従来のリマインドに戻る）
//       ・それ以外     → 「指示」として扱う。★返信の文をそのまま送ることはしない（11/12 誤送信事故・本人指示）。
//                        会社PCのコマンド版Claude（shukka_rw/rw_worker.py が rw.php 経由で取りに来る）が
//                        「いまの文＋指示＋品目データ」から送信文を作り直す → 新しい確認メールで見せて、OKを待つ。
//                        作り直せない時（PC停止・ログイン切れ・指示が不明）は送らずに止めて（保留）メールで聞く。
//       ・何もしない   → 送らない。その日の18:30で打ち切り（品目は未送信のまま・次の営業日の朝にもう一度確認メール）
//     OKの時点で加工予定表の内容がメールと変わっていたら、送らずに最新の内容で確認メールを出し直す。
//     送信後30分以内の指示は【訂正】文を同じ手順（作り直し→確認→OK）で送る。
//  3) 加工予定表から手動で送った場合（shk_sent が付いた）は確認待ちを取りやめる。
//  土日祝・年末年始は動かない（pendingIsBusinessDay）。新しい案は 8:00〜17:44 だけ作る。
// crontab: * 8-18 * * 1-5 /usr/bin/php8.3 ~/hayazai.com/public_html/shukka/auto_cron.php
// 実行例:  php auto_cron.php dryrun   （メール・LINE・状態保存なし）／ php auto_cron.php status
// =====================================================
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('cli only'); }
mb_language('Japanese');
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');
require __DIR__ . '/lib.php';
require dirname(__DIR__) . '/line/pending_lib.php';   // pendingIsBusinessDay（静的祝日表）

const AQ_WAIT_END   = 1830;  // 確認待ちはその日の18:30で打ち切り（送らない）
const AQ_SETTLE     = 120;   // 品目が入ってから2分待って案を作る（入力途中の束数を拾わない・まとめて1通にする）
const AQ_FIX_WINDOW = 1800;  // 送信後30分は訂正の指示を受け付ける
const AQ_RW_TIMEOUT = 480;   // AIの作り直しを8分待つ（来なければ保留）
const AQ_HOLD_MAX   = 3600;  // 保留は60分で打ち切り（送らない）
const AQ_TO         = 'info@hayazai.com';
const AQ_FROM       = 'info@hayazai.com';
const AQ_TENRYU     = 'https://script.google.com/macros/s/AKfycbxvEayV4zinadVIbAC1Xegdqeg8I8yZrrHBm9xiY3p_U0MYLiisYxeftBmZ7xlmSAyh/exec';
const AQ_URL        = 'https://h02050d-ship-it.github.io/kakou-yotei/';

$DRY = in_array('dryrun', $argv, true);
$STATUS = in_array('status', $argv, true);
$now = time();

$qf = __DIR__ . '/state/auto_queue.json';
$rwDir = __DIR__ . '/state/rw';
@mkdir($rwDir, 0775, true);
if ($STATUS) { echo is_file($qf) ? file_get_contents($qf) : "(empty)\n"; exit(0); }
if (!$DRY) {
    if (!pendingIsBusinessDay($now)) exit(0);
    $h = (int)date('G', $now);
    if ($h < 8 || $h >= 19) exit(0);
}
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
$newDrafts = array();

// ---------- 1. 案を処理 ----------
foreach ($q['drafts'] as $id => &$d) {
    $st = $d['status'] ?? '';
    $fix = !empty($d['fix']);

    if ($st === 'waiting' || $st === 'hold') {
        if (!$fix) {
            $left = array_values(array_filter($d['keys'], function ($k) use ($pend) { return isset($pend[$k]); }));
            if (!$left) { $d['status'] = 'superseded'; $d['done_at'] = $now; $changed = true; $out[] = "$id superseded(手動送信済み)"; continue; }
        }
        if ($st === 'waiting' && (date('Ymd', $d['created']) !== date('Ymd', $now) || (int)date('Gi', $now) >= AQ_WAIT_END)) {
            $d['status'] = 'expired'; $d['done_at'] = $now; $changed = true; $out[] = "$id waiting expired(no OK)";
            aqMail($DRY, '【内藤運輸LINE】OKが無かったので送っていません（受付 ' . aqShort($id) . '）',
                "確認メール（件名に $id）に「OK」の返信が無かったため、内藤運輸へは送っていません。\n"
              . ($fix ? '' : "品目は未送信のままです。次の営業日の朝にもう一度確認メールを出します。\n")
              . "今日送るときは加工予定表の「🚚 出荷依頼」から送ってください。\n" . AQ_URL . "\n");
            continue;
        }
        if ($st === 'hold' && $now - ($d['hold_at'] ?? $now) > AQ_HOLD_MAX) {
            $d['status'] = 'expired'; $d['done_at'] = $now; $changed = true; $out[] = "$id hold expired";
            aqMail($DRY, '【内藤運輸LINE】送らずに終わりました（受付 ' . aqShort($id) . '）',
                "保留のまま60分たったため、内藤運輸へは送らずに終わりました。\n品目は未送信のままです。送るときは加工予定表の「🚚 出荷依頼」から送ってください。\n" . AQ_URL . "\n");
            continue;
        }
        $rep = aqCheckReply($tenryuTok, $d, $DRY);
        if ($rep === null) $d['check_fail'] = ($d['check_fail'] ?? 0) + 1; else $d['check_fail'] = 0;
        if (is_array($rep)) {
            $d['reply_ids'][] = $rep['id']; $changed = true;
            if ($rep['kind'] === 'cancel') {
                $d['status'] = 'cancelled'; $d['done_at'] = $now;
                aqMail($DRY, '【内藤運輸LINE】送信を取りやめました（受付 ' . aqShort($id) . '）',
                    "返信「" . $rep['text'] . "」を受け取りました。内藤運輸へは送っていません。\n\n"
                  . ($fix ? '' : "品目は未送信のまま残っています（加工予定表のタグ「出荷」・従来の未送信リマインドが出ます）。\n")
                  . "あとで送るときは加工予定表の「🚚 出荷依頼」から送ってください。\n" . AQ_URL . "\n");
                $out[] = "$id cancelled by reply";
                continue;
            }
            if ($rep['kind'] === 'ok') {
                $text = !empty($d['custom']) ? $d['text'] : aqDefaultText($cands);
                if (empty($d['custom']) && aqBody($text) !== aqBody($d['text'])) {
                    // 確認メールの後に加工予定表の内容が変わった → 見せていない内容は送らない。最新で確認し直す
                    $nid = aqNewId($q['drafts'], $newDrafts);
                    $newDrafts[$nid] = array('id' => $nid, 'created' => $now, 'keys' => $d['keys'], 'text' => $text,
                        'status' => 'waiting', 'reply_ids' => array(), 'check_fail' => 0, 'parent' => $id);
                    $d['status'] = 'rewritten'; $d['done_at'] = $now; $d['child'] = $nid;
                    aqMail($DRY, '【内藤運輸LINE】内容が変わったのでもう一度確認します・OKで送ります ' . $nid,
                        "「OK」を受け取りましたが、確認メールの後に加工予定表の内容が変わっていたため、まだ送っていません。\n"
                      . "最新の内容は下のとおりです。\n\n▼ 送信する内容\n────────\n" . $text . "\n────────\n\n" . aqHowTo() . "※前のメールではなく、このメールに返信してください。\n");
                    $out[] = "$id ok but changed -> reconfirm $nid";
                    continue;
                }
                aqDoSend($d, $text, 'ok', $fix ? array() : array_keys($cands), $fix ? '出荷依頼(訂正・メールでOK)' : '出荷依頼(メールでOK)');
                $out[] = "$id sent(ok)";
                continue;
            }
            // 指示 → AIで作り直し
            aqRequestRewrite($d, $rep['text'], $fix);
            $out[] = "$id rewrite requested";
            continue;
        }
        if ($rep === null) $changed = true;   // 時間が来ても送らない（OKの返信だけで送る・11/13 本人指示）

    } elseif ($st === 'rewriting') {
        $doneF = $rwDir . '/' . $id . '.done.json';
        if (is_file($doneF)) {
            $r = json_decode((string)file_get_contents($doneF), true);
            $res = trim((string)($r['result'] ?? ''));
            @unlink($doneF); @unlink($rwDir . '/' . $id . '.req.json');
            $changed = true;
            if (strpos($res, '##CANCEL##') === 0) {
                $d['status'] = 'cancelled'; $d['done_at'] = $now;
                aqMail($DRY, '【内藤運輸LINE】送信を取りやめました（受付 ' . aqShort($id) . '）',
                    "指示「" . $d['instr'] . "」を「送らない」と判断しました。内藤運輸へは送っていません。\n送るときは加工予定表の「🚚 出荷依頼」から送ってください。\n" . AQ_URL . "\n");
                $out[] = "$id rewrite=cancel";
            } elseif ($res === '' || strpos($res, '##') === 0 || mb_strpos($res, '■') === false || mb_strpos($res, '束') === false) {
                // ##UNCLEAR## / ##ERROR## / 形式が崩れた → 送らずに保留して聞く
                $why = preg_replace('/^##(UNCLEAR|ERROR)##\s*/u', '', $res);
                aqHold($d, ($res === '' ? 'AIから空の返事が返りました。' : (strpos($res, '##ERROR##') === 0 ? '会社PCのClaudeで作り直せませんでした（' . $why . '）。' : 'AIが指示の意味を読み取れませんでした（' . mb_substr($why, 0, 200) . '）。')));
                $out[] = "$id rewrite unclear/error -> hold";
            } else {
                // AIが先頭の日付行を落とした時は元の文の1行目を戻す（訂正文は【訂正】で始まるので対象外）
                if (!$fix && !preg_match('/^\d{4}\/\d{1,2}\/\d{1,2}/u', $res)) $res = strtok($d['text'], "\n") . "\n\n" . $res;
                $nid = aqNewId($q['drafts'], $newDrafts);
                $nd = array('id' => $nid, 'created' => $now, 'keys' => $d['keys'], 'text' => $res,
                    'custom' => true, 'fix' => $fix, 'parent' => $id, 'status' => 'waiting', 'reply_ids' => array(), 'check_fail' => 0);
                $newDrafts[$nid] = $nd;
                $d['status'] = 'rewritten'; $d['done_at'] = $now; $d['child'] = $nid;
                aqMail($DRY, '【内藤運輸LINE】指示どおり作り直しました・OKで送ります ' . $nid,
                    "指示「" . $d['instr'] . "」をもとに、AIが文章を作り直しました。\n"
                  . "下の内容でよければ「OK」と返信してください。内藤運輸（出荷LINE）へ" . ($fix ? '訂正として' : '') . "送信します。\n\n"
                  . "▼ 送信する内容\n────────\n" . $res . "\n────────\n\n" . aqHowTo() . "※前のメールではなく、このメールに返信してください。\n");
                $out[] = "$id rewritten -> $nid";
            }
        } elseif ($now - ($d['rw_at'] ?? $now) > AQ_RW_TIMEOUT) {
            @unlink($rwDir . '/' . $id . '.req.json');
            aqHold($d, '会社PCのClaudeから8分たっても返事がありません（PCの電源・コマンド版Claudeのログイン切れの可能性）。');
            $changed = true; $out[] = "$id rewrite timeout -> hold";
        }

    } elseif ($st === 'sent' && empty($d['fix_child']) && $now - ($d['sent_at'] ?? 0) < AQ_FIX_WINDOW) {
        // 送信後30分は訂正の指示を見る（2分おき）
        if ((int)date('i', $now) % 2) continue;
        $rep = aqCheckReply($tenryuTok, $d, $DRY);
        if (!is_array($rep)) continue;
        $d['reply_ids'][] = $rep['id']; $changed = true;
        if ($rep['kind'] === 'cancel') {
            aqMail($DRY, '【内藤運輸LINE】すでに送信済みでした（受付 ' . aqShort($id) . '）',
                "「送らない」の返信を受け取りましたが、" . date('H:i', $d['sent_at']) . " に送信済みでした。\n取り消しが必要なら、訂正の内容を返信で指示してください（例「ナイス長野の分は取り消しと伝えて」）。\n");
            $out[] = "$id cancel-after-sent notified";
        } elseif ($rep['kind'] === 'text') {
            $nid = aqNewId($q['drafts'], $newDrafts);
            $fd = array('id' => $nid, 'created' => $now, 'keys' => array(), 'text' => $d['sent_text'],
                'custom' => true, 'fix' => true, 'parent' => $id, 'reply_tok' => $id, 'status' => 'waiting', 'reply_ids' => array($rep['id']), 'check_fail' => 0);
            aqRequestRewrite($fd, $rep['text'], true);
            $newDrafts[$nid] = $fd;
            $d['fix_child'] = $nid;
            $out[] = "$id fix rewrite requested -> $nid";
        }
    }
}
unset($d);
foreach ($newDrafts as $nid => $nd) $q['drafts'][$nid] = $nd;

// ---------- 2. 新しい品目 → 案を作ってメール ----------
$inQueue = array();
foreach ($q['drafts'] as $d) {
    if (!empty($d['fix']) || !in_array($d['status'] ?? '', array('waiting', 'hold', 'rewriting', 'cancelled', 'expired', 'cancelled_manual'), true)) continue;
    if (($d['status'] ?? '') === 'expired' && date('Ymd', $d['done_at'] ?? 0) !== date('Ymd', $now)) continue;   // 前の日にOKが無く打ち切った分は、次の営業日にもう一度確認
    foreach ($d['keys'] as $k) $inQueue[$k] = true;
}
$hasWaiting = false;
foreach ($q['drafts'] as $d) if (empty($d['fix']) && in_array($d['status'] ?? '', array('waiting', 'hold', 'rewriting'), true)) $hasWaiting = true;
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
        $id = aqNewId($q['drafts'], array());
        $text = aqDefaultText($cands);
        $newList = array();
        foreach ($news as $it) $newList[] = trim((string)$it['customer']) . ' ' . shkLenM($it['length']) . ' ' . trim((string)($it['grade'] ?? '')) . ' ' . shkNumStr(shkBun($it)) . '束';
        $custs = array_values(array_unique(array_map(function ($it) { return trim((string)$it['customer']); }, $news)));
        $subject = '【内藤運輸LINE】送信の確認・OKで送ります（' . implode('・', $custs) . '） ' . $id;
        $body = "加工予定表に内藤運輸便の品目が追加されました。\n"
              . "下の内容でよければ「OK」と返信してください。内藤運輸（出荷LINE）へ送信します。\n"
              . "★OKの返信が無いかぎり送りません。\n\n"
              . "▼ 送信する内容\n────────\n" . $text . "\n────────\n\n"
              . aqHowTo()
              . "※加工予定表から手動で送信した場合、この確認は取りやめます。\n\n"
              . "今回新しく入った品目:\n・" . implode("\n・", $newList) . "\n";
        $q['drafts'][$id] = array('id' => $id, 'created' => $now, 'keys' => array_keys($news),
            'text' => $text, 'status' => 'waiting', 'reply_ids' => array(), 'check_fail' => 0);
        $ok = aqMail($DRY, $subject, $body);
        if (!$ok && !$DRY) { $q['drafts'][$id]['status'] = 'failed'; $q['drafts'][$id]['err'] = 'mail'; }
        $changed = true;
        $out[] = "$id created keys=" . count($news) . " mail=" . ($ok ? 1 : 0);
        if ($DRY) echo "[DRYRUN mail body]\n$body\n";
    } else {
        $out[] = 'new=' . count($news) . ' settling';
    }
}

foreach ($q['drafts'] as $id => $d) if ($now - ($d['created'] ?? 0) > 3 * 86400) { unset($q['drafts'][$id]); $changed = true; }

if ($changed && !$DRY) file_put_contents($qf, json_encode($q, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
if ($out) aqLog(($DRY ? 'DRY ' : '') . implode(' / ', $out));
if ($DRY) echo "pending=" . count($pend) . " news=" . count($news) . "\n" . implode("\n", $out) . "\n";

// ===== helpers =====
function aqHowTo(){
    return "■ 送るとき\n　「OK」と返信してください。上の内容を送信します。\n"
         . "■ 直したいとき\n"
         . "　このメールに返信して、直してほしい内容を「指示」として書いてください。\n"
         . "　（例「ナイス長野は11/24以降着と書いて」「丸宇京葉は今回いらない」）\n"
         . "　→ AIが指示どおりに文章を作り直し、もう一度このようなメールでお見せします（送るのはOKの後）。\n"
         . "　　返信に書いた文章をそのまま送ることはありません。\n"
         . "■ 送らないとき\n　「送らない」と返信するか、そのまま放っておいてください。\n\n"
         . "★何もしなければ送りません（今日の18:30で打ち切り・品目は未送信のまま）。\n"
         . "※送信後30分以内に指示を返信した場合は【訂正】文を作って、同じように確認してから送ります。\n";
}
function aqDefaultText($cands){ return shkBuildMsg('', array_values($cands), false); }
function aqBody($t){ return preg_replace('/^[^\n]*\n/u', '', (string)$t); }
function aqShort($id){ return preg_replace('/^SHK-/', '', $id); }
function aqLog($s){ @file_put_contents(__DIR__ . '/state/auto_cron.log', date('c') . ' ' . $s . "\n", FILE_APPEND); }
function aqNewId($a, $b){
    $base = 'SHK-' . date('md-Hi'); $id = $base; $n = 1;
    while (isset($a[$id]) || isset($b[$id])) { $id = $base . '-' . (++$n); }
    return $id;
}
function aqMail($dry, $subject, $body){
    if ($dry) { echo "[DRYRUN mail] $subject\n"; return true; }
    return @mb_send_mail(AQ_TO, $subject, $body, 'From: ' . AQ_FROM);
}
// 送信して結果を案に書く＋結果メール
function aqDoSend(&$d, $text, $kind, $keys, $by){
    global $DRY, $lineToken, $fb, $items, $relayKey, $pend, $now;
    $ok = aqSend($DRY, $lineToken, $fb, $items, $text, $keys, $relayKey, $by);
    $d['status'] = $ok ? 'sent' : 'failed'; $d['sent_at'] = $now; $d['sent_kind'] = $kind; $d['sent_text'] = $text;
    if ($ok && $keys) $pend = array();   // 送った品目はこの回で新しい案に載せ直さない（11/12 二重案の不具合）
    if (!$ok) {
        aqMail($DRY, '【内藤運輸LINE】⚠️送信に失敗しました（受付 ' . aqShort($d['id']) . '）',
            "LINEの送信に失敗しました。加工予定表の「🚚 出荷依頼」から手動で送ってください。\n" . AQ_URL . "\n\n────────\n" . $text . "\n────────\n");
    } else {
        aqMail($DRY, '【内藤運輸LINE】送信しました（受付 ' . aqShort($d['id']) . '）',
            "内藤運輸（出荷LINE）へ次の内容を送信しました。\n\n────────\n" . $text . "\n────────\n");
    }
    return $ok;
}
// 保留にして聞く
function aqHold(&$d, $why){
    global $DRY, $now;
    $d['status'] = 'hold'; $d['hold_at'] = $now;
    aqMail($DRY, '【内藤運輸LINE】送信を止めています（受付 ' . aqShort($d['id']) . '）',
        $why . "\n内藤運輸へは送っていません。\n\n"
      . (isset($d['instr']) ? "受け取った指示:\n「" . $d['instr'] . "」\n\n" : '')
      . "▼ 予告メール（件名に " . ($d['reply_tok'] ?? $d['id']) . "）に返信してください（60分以内）\n"
      . "・指示を書き直して返信 → もう一度AIが作り直します\n・「OK」→ 下のいまの内容で送信\n・「送らない」→ 送らない\n"
      . "60分たっても返信がなければ送らずに終わります。\n\n"
      . "いまの内容:\n────────\n" . $d['text'] . "\n────────\n");
}
// AI作り直しの依頼を置く（会社PCの rw_worker.py が rw.php で取りに来る）
function aqRequestRewrite(&$d, $instr, $fix){
    global $DRY, $now, $rwDir, $cands, $changed;
    $d['status'] = 'rewriting'; $d['rw_at'] = $now; $d['instr'] = $instr; $changed = true;
    $prompt = aqPrompt($d['text'], $instr, $fix, $cands);
    if ($DRY) { echo "[DRYRUN rewrite prompt]\n$prompt\n"; return; }
    file_put_contents($rwDir . '/' . $d['id'] . '.req.json', json_encode(array('id' => $d['id'], 'at' => $now, 'prompt' => $prompt), JSON_UNESCAPED_UNICODE));
}
function aqPrompt($base, $instr, $fix, $cands){
    $rows = array();
    foreach ($cands as $it) {
        $rows[] = '- ' . implode(' / ', array_filter(array(
            '取引先:' . trim((string)($it['customer'] ?? '')),
            '等級:' . trim((string)($it['grade'] ?? '')),
            '長さ:' . shkLenM($it['length'] ?? ''),
            '束数:' . (shkBun($it) !== null ? shkNumStr(shkBun($it)) . '束' : '?'),
            '状態:' . (shkDone($it) ? '製造完了' : '製造中'),
            !empty($it['shk_sent']) ? '内藤へ連絡済み' : '内藤へ未連絡',
            trim((string)($it['remark'] ?? '')) !== '' ? '備考:' . trim((string)$it['remark']) : '',
            trim((string)($it['chaku'] ?? '')) !== '' ? '着日指定:' . $it['chaku'] . ' ' . ($it['chaku_kbn'] ?? '') : '',
            trim((string)($it['due'] ?? '')) !== '' ? '出荷予定日:' . $it['due'] : '',
        )));
    }
    $wd = array('日','月','火','水','木','金','土');
    return "あなたは株式会社林材木店（静岡の桧の羽目板・フローリング製造）の出荷連絡係です。\n"
         . "運送会社「内藤運輸」へLINEで送る出荷連絡の文章を、社内スタッフの指示どおりに作り直してください。\n\n"
         . "# ルール\n"
         . "- 出力は内藤運輸へ送る文章だけ。前置き・説明・コードブロック・引用符は付けない。\n"
         . "- 元の文章の形式（先頭の日付行、「■ 取引先」ごとのブロック、「長さ 束数（完了/未完了）」の行、「計○件（完了○／未完了○）」、最後の「※「現状」と送ると…」の行）を保つ。品目を減らした・増やした時は「計」を数え直す。\n"
         . "- 指示された点だけを直す。指示に無い品目・数字・日付は変えない。品目データに無い数字や日付を作らない。\n"
         . "- 指示の文そのもの・社内向けの言い回し・くだけた口調は本文に入れない。内藤運輸向けの簡潔で丁寧な書き方にする（注記は「※」で始める）。\n"
         . "- 指示が「送らない・やめる」の意味なら、##CANCEL## とだけ出力する。\n"
         . "- 指示の意味が分からない、または品目データと食い違って作れないなら、##UNCLEAR## の後に理由を1行だけ書く。\n"
         . ($fix ? "- これは送信済みの文章への訂正。先頭を「【訂正】先ほどの連絡を以下のとおり訂正します。」の1行で始め、空行の後に訂正後の内容を全文で書く。\n" : '')
         . "\n# 今日\n" . date('Y/n/j') . '（' . $wd[(int)date('w')] . "）\n"
         . "\n# 元の文章\n" . $base . "\n"
         . "\n# 指示（社内スタッフからのメール返信）\n" . $instr . "\n"
         . "\n# 品目データ（加工予定表・内藤便の未出荷分）\n" . implode("\n", $rows) . "\n";
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
// kind: cancel / ok / text(＝指示。そのまま送らない)
function aqCheckReply($tok, $d, $dry){
    if ($tok === '') return null;
    $url = AQ_TENRYU . '?token=' . urlencode($tok) . '&act=shk_check&tok=' . urlencode($d['reply_tok'] ?? $d['id']) . '&after=' . ($d['created'] * 1000);
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
