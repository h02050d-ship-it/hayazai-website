<?php
// 公式LINE 未返信キュー「対応済みにする」（通知メール内のリンク先）
//   ?u=<userId>&k=<署名>      … その相手の再通知を止める
//   ?all=1&k=<署名(all)>       … 全件止める
//   ?list=1&k=<署名(list)>     … 未返信一覧をJSONで返す（社内ツール・朝の要約用）
mb_language('Japanese');
mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');
require __DIR__ . '/pending_lib.php';
$CONFIG = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$SECRET = $CONFIG['channel_secret'] ?? '';

$k = (string)($_GET['k'] ?? '');
$u = (string)($_GET['u'] ?? '');

function page(string $title, string $msg, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . htmlspecialchars($title) . '</title>'
       . '<body style="font-family:sans-serif;padding:2em;max-width:36em;margin:auto;line-height:1.7">'
       . '<h2 style="margin:0 0 .5em">' . htmlspecialchars($title) . '</h2><p>' . nl2br(htmlspecialchars($msg)) . '</p>'
       . '<p><a href="' . LINE_CHAT_BASE . '">チャット画面を開く</a></p></body>';
    exit;
}

if ($SECRET === '' || $k === '') page('無効なリンク', 'パラメータが足りません。', 400);

if (isset($_GET['list'])) {
    if (!hash_equals(pendingToken('list', $SECRET), $k)) page('無効なリンク', '署名が一致しません。', 403);
    header('Content-Type: application/json; charset=utf-8');
    $rows = array_map(fn($r) => [
        'userId' => $r['userId'], 'name' => $r['name'], 'first_at' => date('Y-m-d H:i', (int)$r['first_at']),
        'elapsed' => pendingElapsedLabel((int)$r['first_at'], time()), 'count' => $r['count'],
        'text' => mb_strimwidth((string)($r['texts'][0] ?? ''), 0, 120, '…'), 'chat' => pendingChatUrl($r['userId']),
    ], pendingList());
    echo json_encode(['ok' => true, 'pending' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}
if (isset($_GET['all'])) {
    if (!hash_equals(pendingToken('all', $SECRET), $k)) page('無効なリンク', '署名が一致しません。', 403);
    $n = 0;
    foreach (pendingList() as $r) { if (pendingDone($r['userId'])) $n++; }
    page('すべて対応済みにしました', $n . '件の再通知を止めました。');
}
if ($u === '' || !hash_equals(pendingToken($u, $SECRET), $k)) page('無効なリンク', '署名が一致しません。', 403);
$rec = pendingLoad($u);
if (!$rec) page('すでに対応済みです', 'この件の再通知はすでに止まっています。');
pendingDone($u);
page('対応済みにしました', ($rec['name'] ?: '（名前不明）') . " さんの件の再通知を止めました。\nありがとうございました。");
