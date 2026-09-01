<?php
// =====================================================
// 林材木店 アクセス分析API（ダッシュボード用・要認証）
// hp-analytics（GitHub Pages） → ここ → analytics/state/*.jsonl を集計して返す
// 認証情報は analytics/config.php（GitHub Actions Secretsから生成・リポジトリ非含有）
// =====================================================
date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

$ALLOW_ORIGIN = 'https://h02050d-ship-it.github.io';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === $ALLOW_ORIGIN) {
    header('Access-Control-Allow-Origin: ' . $ALLOW_ORIGIN);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method']); exit;
}

$cfg = @include __DIR__ . '/config.php';
$authEmail = is_array($cfg) ? strtolower(trim((string)($cfg['auth_email'] ?? ''))) : '';
$authPass  = is_array($cfg) ? trim((string)($cfg['auth_pass'] ?? '')) : '';
if ($authEmail === '' || $authPass === '') {
    http_response_code(503); echo json_encode(['ok' => false, 'error' => 'no_config']); exit;
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) { http_response_code(400); echo json_encode(['ok' => false, 'error' => 'bad_json']); exit; }
$email = strtolower(trim((string)($in['email'] ?? '')));
$pass  = (string)($in['pass'] ?? '');
if ($email !== $authEmail || !hash_equals($authPass, $pass)) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'auth']); exit;
}

$days = (int)($in['days'] ?? 28);
if (!in_array($days, [7, 28, 90], true)) $days = 28;

$to   = strtotime('tomorrow 00:00');           // 今日を含む
$from = $to - $days * 86400;
$prevFrom = $from - $days * 86400;             // 前期間（比較用）

// ---- イベント読み込み（対象月ファイルのみ）----
$stateDir = __DIR__ . '/state';
$events = []; $prevEvents = [];
$months = [];
for ($t = $prevFrom; $t < $to + 86400; $t += 86400) { $months[date('Ym', $t)] = 1; }
foreach (array_keys($months) as $ym) {
    $f = $stateDir . '/ev_' . $ym . '.jsonl';
    if (!is_file($f)) continue;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (!is_array($r)) continue;
        $tt = (int)($r['t'] ?? 0);
        if ($tt >= $from && $tt < $to) $events[] = $r;
        elseif ($tt >= $prevFrom && $tt < $from) $prevEvents[] = $r;
    }
}

// ---- セッション組み立て ----
// sess[sid] = ['pv'=>[{t,p}], 'vid'=>, 'dev'=>, 'ref'=>初回参照元]
$sess = []; $pvTotal = 0; $vids = []; $evc = []; $hours = array_fill(0, 24, 0);
$dur = []; // dur[sid][p] = ['dur'=>max, 'sd'=>max]
$dailyMap = [];
foreach ($events as $r) {
    $sid = $r['sid'] ?? 'na';
    if ($r['e'] === 'pv') {
        $pvTotal++;
        $vids[$r['vid'] ?? 'na'] = 1;
        $d = date('Y-m-d', $r['t']);
        if (!isset($dailyMap[$d])) $dailyMap[$d] = ['pv' => 0, 'ss' => [], 'vs' => []];
        $dailyMap[$d]['pv']++;
        $dailyMap[$d]['ss'][$sid] = 1;
        $dailyMap[$d]['vs'][$r['vid'] ?? 'na'] = 1;
        $hours[(int)date('G', $r['t'])]++;
        if (!isset($sess[$sid])) $sess[$sid] = ['pv' => [], 'vid' => $r['vid'] ?? 'na', 'dev' => $r['dev'] ?? 'p', 'ref' => (string)($r['r'] ?? '')];
        if ($sess[$sid]['ref'] === '' && ($r['r'] ?? '') !== '') $sess[$sid]['ref'] = (string)$r['r'];
        $sess[$sid]['pv'][] = ['t' => $r['t'], 'p' => $r['p']];
    } elseif ($r['e'] === 'lv') {
        $p = $r['p'];
        $cur = $dur[$sid][$p] ?? ['dur' => 0, 'sd' => 0];
        $dur[$sid][$p] = ['dur' => max($cur['dur'], (int)($r['dur'] ?? 0)), 'sd' => max($cur['sd'], (int)($r['sd'] ?? 0))];
    } elseif ($r['e'] === 'ev') {
        $evc[$r['x']] = ($evc[$r['x']] ?? 0) + 1;
    }
}

// ---- 日別 ----
$daily = [];
for ($t = $from; $t < $to; $t += 86400) {
    $d = date('Y-m-d', $t);
    $m = $dailyMap[$d] ?? null;
    $daily[] = ['d' => $d, 'pv' => $m['pv'] ?? 0, 'ss' => $m ? count($m['ss']) : 0, 'vs' => $m ? count($m['vs']) : 0];
}

// ---- ページ別（PV・入口・離脱・直帰・平均滞在・平均読了）----
$pages = [];
$bounceSessions = 0;
$funnel = ['top' => 0, 'products' => 0, 'product' => 0, 'cart' => 0, 'order' => 0, 'complete' => 0];
function funnelStage_(string $p): ?string {
    if ($p === '/index.html') return 'top';
    if ($p === '/products.html' || $p === '/outlet.html') return 'products';
    if (strpos($p, '/product.html') === 0 || $p === '/sample.html') return 'product';
    if ($p === '/cart.html') return 'cart';
    if ($p === '/order.html') return 'order';
    if ($p === '/order_complete.html') return 'complete';
    return null;
}
foreach ($sess as $sid => $s) {
    usort($s['pv'], fn($a, $b) => $a['t'] <=> $b['t']);
    $n = count($s['pv']);
    if ($n === 0) continue;
    $stages = [];
    foreach ($s['pv'] as $i => $pv) {
        $p = $pv['p'];
        if (!isset($pages[$p])) $pages[$p] = ['pv' => 0, 'en' => 0, 'ex' => 0, 'bounce' => 0, 'durSum' => 0, 'durN' => 0, 'sdSum' => 0, 'sdN' => 0];
        $pages[$p]['pv']++;
        if ($i === 0) $pages[$p]['en']++;
        if ($i === $n - 1) $pages[$p]['ex']++;
        if ($n === 1) $pages[$p]['bounce']++;
        $st = funnelStage_($p);
        if ($st) $stages[$st] = 1;
    }
    if ($n === 1) $bounceSessions++;
    foreach ($stages as $st => $_) { $funnel[$st]++; }
    foreach ($dur[$sid] ?? [] as $p => $m) {
        if (!isset($pages[$p])) continue;
        if ($m['dur'] > 0) { $pages[$p]['durSum'] += $m['dur']; $pages[$p]['durN']++; }
        if ($m['sd'] > 0)  { $pages[$p]['sdSum'] += $m['sd']; $pages[$p]['sdN']++; }
    }
}
$pageList = [];
foreach ($pages as $p => $m) {
    $pageList[] = [
        'p' => $p, 'pv' => $m['pv'], 'en' => $m['en'], 'ex' => $m['ex'],
        'exRate' => $m['pv'] > 0 ? round($m['ex'] / $m['pv'] * 100) : 0,
        'bounce' => $m['en'] > 0 ? round($m['bounce'] / $m['en'] * 100) : 0,
        'dur' => $m['durN'] > 0 ? round($m['durSum'] / $m['durN']) : 0,
        'sd'  => $m['sdN'] > 0 ? round($m['sdSum'] / $m['sdN']) : 0,
    ];
}
usort($pageList, fn($a, $b) => $b['pv'] <=> $a['pv']);
$pageList = array_slice($pageList, 0, 40);

// ---- 流入元分類 ----
function refClass_(string $r): string {
    if ($r === '') return '直接';
    $h = strtolower(parse_url($r, PHP_URL_HOST) ?: $r);
    if (preg_match('/google\./', $h)) return 'Google検索';
    if (preg_match('/yahoo\./', $h)) return 'Yahoo!検索';
    if (preg_match('/bing\./', $h)) return 'Bing';
    if (preg_match('/chatgpt|openai|perplexity|claude|anthropic|gemini|copilot|felo|genspark/', $h)) return 'AI経由';
    if (preg_match('/instagram|facebook|twitter|^x\.com|t\.co|line\.me|lin\.ee|youtube/', $h)) return 'SNS';
    return $h ?: 'その他';
}
$refs = []; $devs = ['m' => 0, 'p' => 0];
foreach ($sess as $s) {
    $k = refClass_($s['ref']);
    $refs[$k] = ($refs[$k] ?? 0) + 1;
    $devs[$s['dev'] === 'm' ? 'm' : 'p']++;
}
arsort($refs);
$refList = [];
foreach ($refs as $k => $c) { $refList[] = ['k' => $k, 'c' => $c]; }
$refList = array_slice($refList, 0, 12);

// ---- 前期間の合計（比較用）----
$ppv = 0; $pvid = []; $psid = [];
foreach ($prevEvents as $r) {
    if ($r['e'] === 'pv') { $ppv++; $pvid[$r['vid'] ?? 'na'] = 1; $psid[$r['sid'] ?? 'na'] = 1; }
}

// ---- AIチャット Q&A ログ ----
$ai = [];
$chatLog = dirname(__DIR__) . '/ai/state/chat_log.jsonl';
if (is_file($chatLog)) {
    foreach (file($chatLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true);
        if (is_array($r) && ($r['t'] ?? 0) >= $from) $ai[] = ['t' => $r['t'], 'q' => (string)($r['q'] ?? ''), 'a' => (string)($r['a'] ?? '')];
    }
    $ai = array_slice(array_reverse($ai), 0, 100);
}

// ---- 平均滞在（サイト全体）----
$durAll = 0; $durAllN = 0;
foreach ($dur as $bySid) { foreach ($bySid as $m) { if ($m['dur'] > 0) { $durAll += $m['dur']; $durAllN++; } } }

echo json_encode([
    'ok' => true,
    'range' => ['from' => date('Y-m-d', $from), 'to' => date('Y-m-d', $to - 86400), 'days' => $days],
    'total' => [
        'pv' => $pvTotal, 'ss' => count($sess), 'vs' => count($vids),
        'bounce' => count($sess) > 0 ? round($bounceSessions / count($sess) * 100) : 0,
        'avgDur' => $durAllN > 0 ? round($durAll / $durAllN) : 0,
    ],
    'prev' => ['pv' => $ppv, 'ss' => count($psid), 'vs' => count($pvid)],
    'daily' => $daily,
    'pages' => $pageList,
    'refs' => $refList,
    'devs' => $devs,
    'hours' => $hours,
    'events' => $evc,
    'funnel' => $funnel,
    'ai' => $ai,
], JSON_UNESCAPED_UNICODE);
