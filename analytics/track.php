<?php
// =====================================================
// 林材木店 自前アクセス計測 収集エンドポイント
// js/analytics.js → ここ → analytics/state/ev_YYYYMM.jsonl（1行1イベント）
// 個人情報は保存しない（IPはソルト付きハッシュ先頭8桁のみ）。
// おまけ: 朝7時以降の最初のアクセスで前日ダイジェストをLINEへ送る（遅延トリガー・cron不要）。
// =====================================================
date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405); echo json_encode(['ok' => false]); exit;
}

// 同一サイトからの利用のみ（簡易チェック・ai/chat.php と同方式）
$origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
if ($origin !== '' && strpos($origin, 'hayazai.com') === false && strpos($origin, 'localhost') === false) {
    http_response_code(403); echo json_encode(['ok' => false]); exit;
}

// ボットは保存しない
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|headless|lighthouse|monitor|preview|curl|wget|python|scrapy|phantom|scan|facebookexternal/i', $ua)) {
    echo json_encode(['ok' => true, 'skip' => 'bot']); exit;
}

$raw = file_get_contents('php://input');
if (strlen($raw) > 4096) { http_response_code(413); echo json_encode(['ok' => false]); exit; }
$in = json_decode($raw, true);
if (!is_array($in)) { http_response_code(400); echo json_encode(['ok' => false]); exit; }

$e = (string)($in['e'] ?? '');
if (!in_array($e, ['pv', 'lv', 'ev'], true)) { http_response_code(400); echo json_encode(['ok' => false]); exit; }

$idOk = fn($v) => preg_match('/^[a-z0-9]{6,32}$/', (string)$v) ? (string)$v : 'na';
$p = (string)($in['p'] ?? '');
if ($p === '' || $p[0] !== '/' || mb_strlen($p) > 160) $p = '/unknown';

$rec = [
    't'   => time(),
    'e'   => $e,
    'sid' => $idOk($in['sid'] ?? ''),
    'vid' => $idOk($in['vid'] ?? ''),
    'p'   => $p,
    'dev' => preg_match('/Mobile|Android|iPhone|iPad/i', $ua) ? 'm' : 'p',
    'ip'  => substr(md5(($_SERVER['REMOTE_ADDR'] ?? '') . 'hz_salt'), 0, 8),
];
if ($e === 'pv') {
    $rec['r']  = mb_substr((string)($in['r'] ?? ''), 0, 300);
    $rec['sw'] = max(0, min(9999, (int)($in['sw'] ?? 0)));
}
if ($e === 'lv') {
    $rec['dur'] = max(0, min(1800, (int)($in['dur'] ?? 0)));
    $rec['sd']  = max(0, min(100, (int)($in['sd'] ?? 0)));
}
if ($e === 'ev') {
    $x = (string)($in['x'] ?? '');
    if (!preg_match('/^[a-z0-9_]{2,40}$/', $x)) { echo json_encode(['ok' => true, 'skip' => 'x']); exit; }
    $rec['x'] = $x;
}

$stateDir = __DIR__ . '/state';
if (!is_dir($stateDir)) { @mkdir($stateDir, 0755, true); }
@file_put_contents(
    $stateDir . '/ev_' . date('Ym') . '.jsonl',
    json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n",
    FILE_APPEND | LOCK_EX
);

echo json_encode(['ok' => true]);

// ---- ここから先はレスポンス返却後の後処理（前日ダイジェスト）----
if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
try { maybeSendDailyDigest_($stateDir); } catch (Throwable $ex) { /* best-effort */ }

// =====================================================
// 前日ダイジェスト: 7:00以降の最初のイベントで1日1回だけ送信。
// 送信経路は shukka/send.php と同じGAS中継（notify_settingsゲート内蔵・ntype=hp_daily）。
// 中継キーは analytics/config.php（GitHub Actions Secretsから生成・リポジトリ非含有）。
// =====================================================
function maybeSendDailyDigest_(string $stateDir): void {
    if ((int)date('G') < 7) return;
    $today = date('Y-m-d');
    $markFile = $stateDir . '/digest_last.txt';
    $last = is_file($markFile) ? trim((string)file_get_contents($markFile)) : '';
    if ($last === $today) return;
    @file_put_contents($markFile, $today, LOCK_EX); // 先にマークして二重送信を防ぐ

    $cfg = @include __DIR__ . '/config.php';
    $relayKey = is_array($cfg) ? trim((string)($cfg['relay_key'] ?? '')) : '';
    if ($relayKey === '') return;

    $y0 = strtotime('yesterday 00:00');
    $y1 = strtotime('today 00:00');
    $ymd = date('n/j(D)', $y0);
    $wd = ['Sun'=>'日','Mon'=>'月','Tue'=>'火','Wed'=>'水','Thu'=>'木','Fri'=>'金','Sat'=>'土'];
    foreach ($wd as $en => $ja) { $ymd = str_replace($en, $ja, $ymd); }

    // 前日イベント読み込み（月またぎ対応で2ファイル見る）
    $events = [];
    foreach (array_unique([date('Ym', $y0), date('Ym', $y1)]) as $ym) {
        $f = $stateDir . '/ev_' . $ym . '.jsonl';
        if (!is_file($f)) continue;
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $r = json_decode($line, true);
            if (is_array($r) && ($r['t'] ?? 0) >= $y0 && ($r['t'] ?? 0) < $y1) $events[] = $r;
        }
    }

    $pv = 0; $vids = []; $sids = []; $pages = []; $evc = [];
    foreach ($events as $r) {
        if ($r['e'] === 'pv') {
            $pv++; $vids[$r['vid']] = 1; $sids[$r['sid']] = 1;
            $pages[$r['p']] = ($pages[$r['p']] ?? 0) + 1;
        } elseif ($r['e'] === 'ev') {
            $evc[$r['x']] = ($evc[$r['x']] ?? 0) + 1;
        }
    }
    arsort($pages);

    // 前日のAIチャット質問
    $aiQs = [];
    $chatLog = dirname(__DIR__) . '/ai/state/chat_log.jsonl';
    if (is_file($chatLog)) {
        foreach (file($chatLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $r = json_decode($line, true);
            if (is_array($r) && ($r['t'] ?? 0) >= $y0 && ($r['t'] ?? 0) < $y1 && ($r['q'] ?? '') !== '') $aiQs[] = $r['q'];
        }
    }

    $text = "📊 HP昨日レポ {$ymd}\n";
    $text .= '訪問 ' . count($vids) . '人・PV ' . $pv . "\n";
    if ($aiQs) {
        $text .= "\n🤖 AIへの質問 " . count($aiQs) . "件:\n";
        foreach (array_slice($aiQs, 0, 5) as $q) { $text .= '・' . mb_substr($q, 0, 40) . "\n"; }
        if (count($aiQs) > 5) { $text .= '…ほか' . (count($aiQs) - 5) . "件\n"; }
    }
    $cta = [];
    foreach (['line' => 'LINE', 'form_contact' => '問合せ', 'form_sample' => 'サンプル', 'form_quote' => '見積', 'cart_add' => 'カート', 'tel' => '電話'] as $k => $lab) {
        if (!empty($evc[$k])) $cta[] = $lab . $evc[$k];
    }
    if ($cta) $text .= "\n🎯 CTA: " . implode(' / ', $cta) . "\n";
    $top = array_slice($pages, 0, 3, true);
    if ($top) {
        $text .= "\n👀 よく見られたページ:\n";
        foreach ($top as $pg => $c) { $text .= '・' . $pg . '（' . $c . "PV）\n"; }
    }
    $text .= "\n詳細 → https://h02050d-ship-it.github.io/hp-analytics/";

    $url = 'https://script.google.com/macros/s/AKfycbxcvQZVi497obS-nRm4MdN0tYtsaTb03n7FLFWy7oZN2vkItKm7oQO9_85WdJYxGjgaiA/exec';
    $body = json_encode([
        'key'    => $relayKey,
        'target' => 'daiki',
        'mode'   => 'notify',
        'ntype'  => 'hp_daily',
        'by'     => 'HPアクセス計測',
        'text'   => $text,
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true, // GASは302で応答を返す
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}
