<?php
// =====================================================
// 出荷LINE自動送信の「AI作り直し」受け渡し口（2026-11-12）
//  auto_cron.php が state/rw/<id>.req.json に依頼（プロンプト）を置く。
//  会社PCの rw_worker.py（コマンド版Claude）が
//    GET  rw.php?k=<key>&act=list         → 未処理の依頼一覧 [{id, prompt}]
//    POST rw.php?k=<key>&act=done  {id, result} → 結果を state/rw/<id>.done.json へ
//  auto_cron.php が次の分に結果を拾い、新しい予告メールを出す（送信はしない）。
//  鍵 = config.php の tenryu_token（Secrets: SHUKKA_TENRYU_TOKEN）。
// =====================================================
header('Content-Type: application/json; charset=utf-8');
$cfg = require __DIR__ . '/config.php';
$key = (string)($cfg['tenryu_token'] ?? '');
if ($key === '' || !hash_equals($key, (string)($_GET['k'] ?? ''))) { http_response_code(403); echo '{"ok":false}'; exit; }
$dir = __DIR__ . '/state/rw';
@mkdir($dir, 0775, true);
$act = (string)($_GET['act'] ?? '');

if ($act === 'list') {
    $list = array();
    foreach (glob($dir . '/*.req.json') ?: array() as $f) {
        $id = basename($f, '.req.json');
        if (is_file($dir . '/' . $id . '.done.json')) continue;
        $r = json_decode((string)file_get_contents($f), true);
        if (is_array($r)) $list[] = array('id' => $id, 'at' => $r['at'] ?? 0, 'prompt' => $r['prompt'] ?? '');
    }
    echo json_encode(array('ok' => true, 'list' => $list), JSON_UNESCAPED_UNICODE);
    exit;
}
if ($act === 'done' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $in = json_decode((string)file_get_contents('php://input'), true);
    $id = (string)($in['id'] ?? '');
    if (!preg_match('/^SHK-\d{4}-\d{4}(-\d+)?$/', $id) || !is_file($dir . '/' . $id . '.req.json')) { http_response_code(404); echo '{"ok":false,"error":"no_req"}'; exit; }
    $res = mb_substr((string)($in['result'] ?? ''), 0, 4800);
    file_put_contents($dir . '/' . $id . '.done.json', json_encode(array('id' => $id, 'at' => time(), 'result' => $res), JSON_UNESCAPED_UNICODE), LOCK_EX);
    @file_put_contents(__DIR__ . '/state/auto_cron.log', date('c') . " rw done $id len=" . mb_strlen($res) . "\n", FILE_APPEND);
    echo '{"ok":true}';
    exit;
}
http_response_code(400);
echo '{"ok":false,"error":"act"}';
