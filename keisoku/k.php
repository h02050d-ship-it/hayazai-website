<?php
// =====================================================
// 林材木店 自前アクセス計測 収集エンドポイント（keisoku=計測。analytics/track名は広告ブロッカー対策で不可）
// js/hz.js → ここ → keisoku/state/ev_YYYYMM.jsonl（1行1イベント）
// 個人情報は保存しない（IPはソルト付きハッシュ先頭8桁のみ）。
// おまけ: 週1回（月曜の朝・祝日なら最初の営業日）先週分の週間レポをLINEへ送る（2026-09-07 日次→週次）。
// =====================================================
date_default_timezone_set('Asia/Tokyo');
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

// 週間レポの発火口（GitHub Actions の平日7:05 cron が叩く。送るかは関数側が判断＝二重送信なし）
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && (string)($_GET['digest'] ?? '') === '1') {
    $stateDir = __DIR__ . '/state';
    if (!is_dir($stateDir)) { @mkdir($stateDir, 0755, true); }
    $out = ['ok' => true];
    try { $out['digest'] = maybeSendWeeklyDigest_($stateDir); } catch (Throwable $ex) { $out = ['ok' => false, 'err' => $ex->getMessage()]; }
    echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
}

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
    // ?f= 流入コード（hz.js v3が送る・api.phpが FAX/QR:xxx として集計。ここで落としていたので保存するように）
    $fc = (string)($in['f'] ?? '');
    if ($fc !== '' && preg_match('/^[a-zA-Z0-9_-]{1,24}$/', $fc)) $rec['f'] = $fc;
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

// ---- ここから先はレスポンス返却後の後処理（週間レポ）----
if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
try { maybeSendWeeklyDigest_($stateDir); } catch (Throwable $ex) { /* best-effort */ }

// =====================================================
// 週間レポ（2026-09-07 本人指示で日次→週1へ）
//  ・毎週「月曜の朝」に先週(月〜日)分を1通。月曜が祝日・長期休暇なら、その週の最初の営業日の朝に送る。
//  ・発火は2経路: ①GitHub Actions の平日7:05 cron が GET ?digest=1 を叩く（確実）
//                 ②アクセス時の遅延トリガー（cronが落ちた時の保険。7時以降）
//    どちらも「その週にまだ送っていなければ送る」なので二重送信しない（digest_last.txt に週キー）。
//  ・送信経路は shukka/send.php と同じGAS中継（notify_settingsゲート内蔵・ntype=hp_daily は種別キーとして据え置き）。
// =====================================================
function hzHolidaysJp_(string $stateDir): array {
    // 内閣府の祝日をJSON化した公開API（genba GASの土日祝判定と同じ出典）。7日キャッシュ・取得失敗時は古いキャッシュ。
    $f = $stateDir . '/holidays_jp.json';
    if (is_file($f) && (time() - (int)filemtime($f)) < 7 * 86400) {
        $j = json_decode((string)file_get_contents($f), true);
        if (is_array($j) && $j) return $j;
    }
    $ctx = stream_context_create(['http' => ['timeout' => 6]]);
    $raw = @file_get_contents('https://holidays-jp.github.io/api/v1/date.json', false, $ctx);
    $j = $raw ? json_decode($raw, true) : null;
    if (is_array($j) && $j) { @file_put_contents($f, json_encode($j), LOCK_EX); return $j; }
    if (is_file($f)) { $j = json_decode((string)file_get_contents($f), true); if (is_array($j)) return $j; }
    return [];
}

function hzIsBusinessDay_(int $ts, string $stateDir): bool {
    if ((int)date('N', $ts) >= 6) return false;                 // 土日
    // 会社の長期休暇（月日で指定・必要に応じて追加）: 年末年始
    $closed = [['12-29', '12-31'], ['01-01', '01-04']];
    $md = date('m-d', $ts);
    foreach ($closed as [$a, $b]) { if ($md >= $a && $md <= $b) return false; }
    $h = hzHolidaysJp_($stateDir);
    return !isset($h[date('Y-m-d', $ts)]);
}

function maybeSendWeeklyDigest_(string $stateDir): array {
    $now = time();
    if ((int)date('G', $now) < 7) return ['skip' => 'before7'];
    if (!hzIsBusinessDay_($now, $stateDir)) return ['skip' => 'restday'];

    $monday = strtotime('monday this week 00:00', $now);       // 今週の月曜（週は月曜始まり）
    $weekKey = date('o-\WW', $monday);
    $markFile = $stateDir . '/digest_last.txt';
    $last = is_file($markFile) ? trim((string)file_get_contents($markFile)) : '';
    if ($last === $weekKey) return ['skip' => 'sent', 'week' => $weekKey];
    @file_put_contents($markFile, $weekKey, LOCK_EX);           // 先にマークして二重送信を防ぐ

    $cfg = @include __DIR__ . '/config.php';
    $relayKey = is_array($cfg) ? trim((string)($cfg['relay_key'] ?? '')) : '';
    if ($relayKey === '') return ['skip' => 'no_relay_key'];

    $w0 = $monday - 7 * 86400; $w1 = $monday;                  // 先週 月00:00〜今週月00:00
    $p0 = $w0 - 7 * 86400;                                      // 前々週（比較用）
    $wd = ['Sun'=>'日','Mon'=>'月','Tue'=>'火','Wed'=>'水','Thu'=>'木','Fri'=>'金','Sat'=>'土'];
    $fmt = function (int $ts) use ($wd): string { return date('n/j', $ts) . '(' . $wd[date('D', $ts)] . ')'; };
    $label = $fmt($w0) . '〜' . $fmt($w1 - 86400);

    // イベント読み込み（月またぎ対応で該当月のファイルを全部見る）
    $months = [];
    for ($t = $p0; $t < $w1 + 86400; $t += 86400) { $months[date('Ym', $t)] = 1; }
    $cur = ['pv' => 0, 'vid' => [], 'sid' => [], 'pages' => [], 'ev' => [], 'fax' => [], 'daily' => []];
    $prev = ['pv' => 0, 'vid' => [], 'ev' => []];
    foreach (array_keys($months) as $ym) {
        $f = $stateDir . '/ev_' . $ym . '.jsonl';
        if (!is_file($f)) continue;
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $r = json_decode($line, true);
            if (!is_array($r)) continue;
            $t = (int)($r['t'] ?? 0);
            if ($t >= $w0 && $t < $w1) {
                if (($r['e'] ?? '') === 'pv') {
                    $cur['pv']++; $cur['vid'][$r['vid'] ?? 'na'] = 1; $cur['sid'][$r['sid'] ?? 'na'] = 1;
                    $p = (string)($r['p'] ?? '/'); $cur['pages'][$p] = ($cur['pages'][$p] ?? 0) + 1;
                    $d = date('N', $t); $cur['daily'][$d] = ($cur['daily'][$d] ?? 0) + 1;
                    if (($r['f'] ?? '') !== '') $cur['fax'][$r['sid'] ?? 'na'] = (string)$r['f'];
                } elseif (($r['e'] ?? '') === 'ev') {
                    $x = (string)($r['x'] ?? ''); $cur['ev'][$x] = ($cur['ev'][$x] ?? 0) + 1;
                }
            } elseif ($t >= $p0 && $t < $w0) {
                if (($r['e'] ?? '') === 'pv') { $prev['pv']++; $prev['vid'][$r['vid'] ?? 'na'] = 1; }
                elseif (($r['e'] ?? '') === 'ev') { $x = (string)($r['x'] ?? ''); $prev['ev'][$x] = ($prev['ev'][$x] ?? 0) + 1; }
            }
        }
    }
    arsort($cur['pages']);

    // 先週のAIチャット質問
    $aiQs = [];
    $chatLog = dirname(__DIR__) . '/ai/state/chat_log.jsonl';
    if (is_file($chatLog)) {
        foreach (file($chatLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $r = json_decode($line, true);
            if (is_array($r) && ($r['t'] ?? 0) >= $w0 && ($r['t'] ?? 0) < $w1 && ($r['q'] ?? '') !== '' && !preg_match('/\btest\b/i', (string)$r['q'])) $aiQs[] = $r['q'];
        }
    }

    $vis = count($cur['vid']); $pvis = count($prev['vid']);
    // ---- 判定（2026-09-09 本人指示「良くなったかどうかを素人にも分かる表現で」）----
    // 主＝問い合わせにつながった数（問合せ/サンプル/見積/電話/LINE）、従＝来た人の数
    $CTA = ['form_contact' => '問い合わせ', 'form_sample' => 'サンプル請求', 'form_quote' => '見積依頼', 'tel' => '電話', 'line' => 'LINE'];
    $cNow = 0; $cPrev = 0; $cParts = []; $pParts = [];
    foreach ($CTA as $k => $lab) {
        $n = (int)($cur['ev'][$k] ?? 0); $m = (int)($prev['ev'][$k] ?? 0);
        $cNow += $n; $cPrev += $m;
        if ($n) $cParts[] = $lab . $n . '件';
        if ($m) $pParts[] = $lab . $m . '件';
    }
    $vr = $pvis > 0 ? ($vis - $pvis) / $pvis : 0;
    $vTxt = $pvis === 0 ? '先週の記録なし' : ($vr >= 0.1 ? '増えた' : ($vr <= -0.1 ? '減った' : 'ほぼ同じ'));
    if ($cNow > $cPrev)      { $verdict = '先週より良くなった'; $why = "問い合わせが{$cPrev}件→{$cNow}件に増えた"; }
    elseif ($cNow < $cPrev)  { $verdict = '先週より悪くなった'; $why = "問い合わせが{$cPrev}件→{$cNow}件に減った"; }
    elseif ($vr >= 0.1)      { $verdict = '少し良くなった'; $why = "問い合わせは同じ（{$cNow}件）・来た人が増えた"; }
    elseif ($vr <= -0.1)     { $verdict = '少し悪くなった'; $why = "問い合わせは同じ（{$cNow}件）・来た人が減った"; }
    else                     { $verdict = '変わらず'; $why = "問い合わせ{$cNow}件・来た人もほぼ同じ"; }
    if ($pvis === 0) { $verdict = 'まだ比べられない'; $why = '先週の記録がない'; }

    $text = "📊 HPの1週間 {$label}\n";
    $text .= "判定: {$verdict}（{$why}）\n\n";
    $text .= "・来た人 {$vis}人（先週{$pvis}人・{$vTxt}）\n";
    $text .= '・見られた回数 ' . $cur['pv'] . '回（先週' . $prev['pv'] . "回）\n";
    $text .= '・問い合わせにつながった数 ' . $cNow . '件' . ($cParts ? '＝' . implode('・', $cParts) : '') . '（先週' . $cPrev . '件' . ($pParts ? '＝' . implode('・', $pParts) : '') . "）\n";
    if ($cur['fax']) {
        $fc = [];
        foreach ($cur['fax'] as $sid => $code) { $fc[$code] = ($fc[$code] ?? 0) + 1; }
        $fl = [];
        foreach ($fc as $code => $c) { $fl[] = $code . ' ' . $c . '人'; }
        $text .= '・FAX・チラシのQRから来た人: ' . implode(' / ', $fl) . "\n";
    } else {
        $text .= "・FAX・チラシのQRから来た人: 0人\n";
    }
    if ($aiQs) {
        $text .= "\n🤖 AIに聞かれたこと " . count($aiQs) . "件:\n";
        foreach (array_slice($aiQs, 0, 5) as $q) { $text .= '・' . mb_substr($q, 0, 40) . "\n"; }
        if (count($aiQs) > 5) { $text .= '…ほか' . (count($aiQs) - 5) . "件\n"; }
    }
    $top = array_slice($cur['pages'], 0, 3, true);
    if ($top) {
        $pn = @include __DIR__ . '/pagenames.php';
        $pnPages = is_array($pn) ? ($pn['pages'] ?? []) : [];
        $pnProds = is_array($pn) ? ($pn['products'] ?? []) : [];
        $text .= "\n👀 よく見られたページ:\n";
        foreach ($top as $pg => $c) {
            $lab = $pnPages[$pg] ?? $pg;
            if (!isset($pnPages[$pg]) && preg_match('#^/product\.html\?id=([\w\-]+)#', $pg, $m)) {
                $lab = '商品: ' . ($pnProds[$m[1]] ?? $m[1]);
            }
            $text .= '・' . $lab . '（' . $c . "回）\n";
        }
    }
    // だから何（1行）
    if ($cNow > 0)         { $next = "→ 問い合わせ{$cNow}件に返事が済んでいるか確認"; }
    elseif ($vr <= -0.2 && $pvis > 0) { $next = '→ 来た人が2割以上減った。火曜のHP改善ループで原因を見ます'; }
    else                   { $next = '→ 特にやることなし（火曜のHP改善ループが小さな手直しを続けます）'; }
    $text .= "\n" . $next . "\n";
    $text .= "\n詳細 → https://h02050d-ship-it.github.io/hp-analytics/";

    $url = 'https://script.google.com/macros/s/AKfycbxcvQZVi497obS-nRm4MdN0tYtsaTb03n7FLFWy7oZN2vkItKm7oQO9_85WdJYxGjgaiA/exec';
    $body = json_encode([
        'key'    => $relayKey,
        'target' => 'daiki',
        'mode'   => 'notify',
        'ntype'  => 'hp_daily',
        'by'     => 'HPアクセス計測(週報)',
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
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['sent' => true, 'week' => $weekKey, 'label' => $label, 'pv' => $cur['pv'], 'vis' => $vis, 'relay' => $code, 'res' => is_string($res) ? mb_substr($res, 0, 120) : null];
}
