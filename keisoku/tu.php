<?php
// =====================================================
// 社内ツール 利用計測（tu=tool usage）収集＋読出し口
// tool-portal/tu.js（各社内ツールに1行で読込）→ ここ → keisoku/state/tu_YYYYMM.jsonl
// 記録するのは「どのツールの・どの画面を・何秒見て・どのボタンを何回押したか」だけ。
// キー入力の中身・マウス座標・入力値・個人名は一切保存しない（role=owner/staff の2値のみ）。
// 読出しは api.php と同じ config.php の email/pass 認証（週次ツール改善ループが使う）。
// =====================================================
date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

$ALLOW = ['https://h02050d-ship-it.github.io', 'https://hayazai.com', 'https://www.hayazai.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $ALLOW, true)) { header('Access-Control-Allow-Origin: ' . $origin); header('Vary: Origin'); }
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method']); exit; }

$raw = file_get_contents('php://input');
if (strlen($raw) > 16384) { http_response_code(413); echo json_encode(['ok' => false]); exit; }
$in = json_decode($raw, true);
if (!is_array($in)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'bad_json']); exit; }

$stateDir = __DIR__ . '/state';
if (!is_dir($stateDir)) { @mkdir($stateDir, 0755, true); }

// ---------- 読出し（要認証） ----------
if (!empty($in['read'])) {
    $cfg = @include __DIR__ . '/config.php';
    $authEmail = is_array($cfg) ? strtolower(trim((string)($cfg['auth_email'] ?? ''))) : '';
    $authPass  = is_array($cfg) ? trim((string)($cfg['auth_pass'] ?? '')) : '';
    if ($authEmail === '' || $authPass === '') { http_response_code(503); echo json_encode(['ok' => false, 'error' => 'no_config']); exit; }
    $email = strtolower(trim((string)($in['email'] ?? '')));
    $pass  = (string)($in['pass'] ?? '');
    if ($email !== $authEmail || !hash_equals($authPass, $pass)) { http_response_code(403); echo json_encode(['ok' => false, 'error' => 'auth']); exit; }
    $days = max(1, min(120, (int)($in['days'] ?? 7)));
    $from = time() - $days * 86400;
    $rows = [];
    // 期間にかかる月ファイルだけ読む
    for ($m = 0; $m <= intdiv($days, 28) + 1; $m++) {
        $f = $stateDir . '/tu_' . date('Ym', strtotime("-{$m} month")) . '.jsonl';
        if (!is_file($f)) continue;
        $fh = fopen($f, 'r');
        while (($line = fgets($fh)) !== false) {
            $r = json_decode($line, true);
            if (is_array($r) && ($r['t'] ?? 0) >= $from) $rows[] = $r;
            if (count($rows) >= 200000) break;
        }
        fclose($fh);
    }
    echo json_encode(['ok' => true, 'days' => $days, 'from' => $from, 'n' => count($rows), 'rows' => $rows], JSON_UNESCAPED_UNICODE); exit;
}

// ---------- 収集（同一オリジン群のみ） ----------
if ($origin !== '' && !in_array($origin, $ALLOW, true)) { http_response_code(403); echo json_encode(['ok' => false]); exit; }
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if ($ua === '' || preg_match('/bot|crawl|spider|headless|lighthouse|monitor|curl|wget|python/i', $ua)) { echo json_encode(['ok' => true, 'skip' => 'bot']); exit; }

$idOk = fn($v) => preg_match('/^[a-z0-9]{6,32}$/', (string)$v) ? (string)$v : 'na';
$slug = fn($v, $n) => mb_substr(preg_replace('/[^\p{L}\p{N}\/_\-.?=]/u', '', (string)$v), 0, $n);
$tool = $slug($in['tool'] ?? '', 40); if ($tool === '') $tool = 'unknown';
$page = $slug($in['page'] ?? '', 80); if ($page === '') $page = 'index.html';
$role = (($in['role'] ?? '') === 'owner') ? 'owner' : 'staff';
$sid  = $idOk($in['sid'] ?? '');
$dev  = preg_match('/Mobile|Android|iPhone|iPad/i', $ua) ? 'm' : 'p';
$items = is_array($in['items'] ?? null) ? array_slice($in['items'], 0, 200) : [];
$out = '';
foreach ($items as $it) {
    if (!is_array($it)) continue;
    $e = (string)($it['e'] ?? '');
    if (!in_array($e, ['open', 'click', 'commit', 'undo', 'leave'], true)) continue;
    $rec = ['t' => time(), 'tool' => $tool, 'page' => $page, 'role' => $role, 'sid' => $sid, 'dev' => $dev, 'e' => $e];
    $l = trim(preg_replace('/\s+/u', ' ', (string)($it['l'] ?? '')));
    if ($l !== '') $rec['l'] = mb_substr($l, 0, 30);           // ボタンの表示文字だけ（入力値ではない）
    if (isset($it['v'])) $rec['v'] = max(0, min(86400, (int)$it['v']));   // 秒
    if (isset($it['n'])) $rec['n'] = max(0, min(100000, (int)$it['n'])); // 回数
    if (isset($it['c'])) $rec['c'] = max(0, min(100000, (int)$it['c']));
    $out .= json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n";
}
if ($out !== '') @file_put_contents($stateDir . '/tu_' . date('Ym') . '.jsonl', $out, FILE_APPEND | LOCK_EX);
echo json_encode(['ok' => true]);
