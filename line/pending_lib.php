<?php
// =====================================================================
//  公式LINE 未返信キュー（共通ライブラリ）
//
//  目的: お客様からのLINEメッセージに人が返信し忘れるのを防ぐ。
//    webhook.php が「人の返信が必要なメッセージ」を受けたら pending に登録し、
//    pending_cron.php（サーバーcron・平日8〜17時に30分ごと）が
//    返信されるまで info@ へ再通知メールを送り続ける（3時間ごと）。
//    翌営業日になっても未返信なら、社内LINE（大樹）にも1日1回通知する。
//
//  「返信した」はLINE側から検知できない（Official Account Managerのチャットで
//  送った返信はwebhookに来ない）ので、対応済みにする手段は
//    ① 通知メール内の「対応済みにする」リンク（pending_done.php）
//    ② お客様から「ありがとうございます」等の相づちが届いた（=返信済みとみなす）
//    ③ ウィザードの途中入力（見積もり・お問い合わせフローの進行中は登録しない）
//
//  保存先: ~/hayazai.com/line_state/pending/<userId>.json（public_htmlの外）
//  2026-09-09 作成（9/3木曜のJohnny様の返信を6日間見落としたことへの対策）
// =====================================================================

// LINE Official Account Manager のチャット画面（アカウント固定）
const LINE_CHAT_BASE = 'https://chat.line.biz/Ue67a2ffe5328b023c389eb4dfcf9465d/chat/';
const PENDING_SITE   = 'https://hayazai.com/line/';

function pendingDir(): string {
    $dir = dirname(dirname(__DIR__)) . '/line_state/pending';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}
function pendingSafeId(string $userId): string {
    return preg_replace('/[^A-Za-z0-9_-]/', '', $userId);
}
function pendingPath(string $userId): string {
    return pendingDir() . '/' . pendingSafeId($userId) . '.json';
}
function pendingLoad(string $userId): ?array {
    $p = pendingPath($userId);
    if (!is_file($p)) return null;
    $d = json_decode((string)file_get_contents($p), true);
    return is_array($d) ? $d : null;
}
function pendingSave(array $rec): void {
    @file_put_contents(pendingPath($rec['userId']), json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
/** 対応済みリンク用の署名（channel_secret を鍵にした HMAC） */
function pendingToken(string $userId, string $secret): string {
    return substr(hash_hmac('sha256', 'pending:' . $userId, $secret), 0, 24);
}
function pendingDoneUrl(string $userId, string $secret): string {
    return PENDING_SITE . 'pending_done.php?u=' . rawurlencode($userId) . '&k=' . pendingToken($userId, $secret);
}
function pendingChatUrl(string $userId): string {
    return LINE_CHAT_BASE . rawurlencode($userId);
}

/**
 * 未返信として登録（同じ相手が続けて送ってきたら本文を追記し、最初の受信時刻は保持）
 */
function pendingAdd(string $userId, string $name, string $text, string $kind = 'message'): array {
    $now = time();
    $rec = pendingLoad($userId);
    $text = mb_substr(trim($text), 0, 1500);
    if ($rec) {
        $rec['name']     = $name ?: ($rec['name'] ?? '');
        $rec['last_at']  = $now;
        $rec['count']    = (int)($rec['count'] ?? 1) + 1;
        $rec['texts']    = array_slice(array_merge($rec['texts'] ?? [], [$text]), -5);
    } else {
        $rec = [
            'userId'        => $userId,
            'name'          => $name,
            'kind'          => $kind,
            'first_at'      => $now,
            'last_at'       => $now,
            'count'         => 1,
            'texts'         => [$text],
            'remind_count'  => 0,
            'last_remind_at'=> 0,
            'last_line_at'  => 0,
        ];
    }
    pendingSave($rec);
    return $rec;
}
function pendingDone(string $userId): bool {
    $p = pendingPath($userId);
    if (!is_file($p)) return false;
    return @unlink($p);
}
/** @return array<int,array> 古い順 */
function pendingList(): array {
    $out = [];
    foreach (glob(pendingDir() . '/*.json') ?: [] as $f) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d) && !empty($d['userId'])) $out[] = $d;
    }
    usort($out, fn($a, $b) => ($a['first_at'] ?? 0) <=> ($b['first_at'] ?? 0));
    return $out;
}

// --- 営業日判定（会社ルール: 平日8:00〜17:00・土日祝・年末年始・お盆は休み）------
function pendingHolidays(): array {
    return [
        // 2026
        '2026-09-21', '2026-09-22', '2026-09-23', '2026-10-12', '2026-11-03', '2026-11-23',
        '2026-12-29', '2026-12-30', '2026-12-31',
        // 2027
        '2027-01-01', '2027-01-02', '2027-01-03', '2027-01-04', '2027-01-11', '2027-02-11', '2027-02-23',
        '2027-03-22', '2027-04-29', '2027-05-03', '2027-05-04', '2027-05-05', '2027-07-19',
        '2027-08-11', '2027-08-13', '2027-08-14', '2027-08-16', '2027-09-20', '2027-09-23',
        '2027-10-11', '2027-11-03', '2027-11-23', '2027-12-29', '2027-12-30', '2027-12-31',
    ];
}
function pendingIsBusinessDay(int $ts): bool {
    $w = (int)date('N', $ts);
    if ($w >= 6) return false;
    return !in_array(date('Y-m-d', $ts), pendingHolidays(), true);
}
function pendingInBusinessHours(int $ts): bool {
    if (!pendingIsBusinessDay($ts)) return false;
    $h = (int)date('G', $ts);
    return $h >= 8 && $h < 17;
}
/** 経過時間の日本語表記（例: 2時間 / 1日3時間） */
function pendingElapsedLabel(int $from, int $to): string {
    $sec = max(0, $to - $from);
    $d = intdiv($sec, 86400);
    $h = intdiv($sec % 86400, 3600);
    if ($d > 0) return $d . '日' . ($h > 0 ? $h . '時間' : '');
    if ($h > 0) return $h . '時間';
    return intdiv($sec, 60) . '分';
}

/**
 * 通知メール本文（初回通知・再通知で共通）
 */
function pendingMailBody(array $rec, string $secret, bool $isReminder): string {
    $name = $rec['name'] ?: '（名前不明）';
    $b = '';
    if ($isReminder) {
        $b .= "まだ返信されていません（" . pendingElapsedLabel((int)$rec['first_at'], time()) . "経過・"
            . date('n/j H:i', (int)$rec['first_at']) . " 受信）\n\n";
    }
    $b .= $name . " さん：\n" . implode("\n---\n", $rec['texts'] ?? []) . "\n\n";
    $b .= "▼返信はこちら（このお客様のチャット）\n" . pendingChatUrl($rec['userId']) . "\n\n";
    $b .= "▼返信したら押す（再通知が止まります）\n" . pendingDoneUrl($rec['userId'], $secret) . "\n";
    $b .= "\n※返信するまで平日8〜17時に3時間ごとに再通知します。翌営業日以降はLINEにも届きます。\n";
    return $b;
}
/** 件名（改行はヘッダ折返し事故になるので必ず1行化） */
function pendingSubject(array $rec, bool $isReminder): string {
    $name = $rec['name'] ?: '（名前不明）';
    $first = preg_replace('/\s+/u', ' ', (string)($rec['texts'][0] ?? ''));
    $head = $isReminder
        ? '【公式LINE 未返信 ' . pendingElapsedLabel((int)$rec['first_at'], time()) . '】'
        : '【公式LINE】';
    return $head . mb_strimwidth($name . ' さん：' . $first, 0, 40, '…');
}
