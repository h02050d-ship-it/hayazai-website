<?php
// =====================================================
// AI・自動アクセスの判定（k.php の受付時 / api.php・週報の集計時 で共用）
// hz.js はJSを実行する訪問だけを数えるので、AIのクローラーの大半は最初から入らない。
// ここで落とすのは「本物のブラウザを使う自動操作」の残り。
// =====================================================

// 受付時に捨てるUA（"bot" を含まないAIエージェント・自動化ブラウザ）
const HZ_AI_UA_RE = '/ChatGPT|OAI-|GPTBot|Perplexity|Claude|anthropic|Google-Extended|Google-InspectionTool|Bytespider|Amazonbot|CCBot|meta-external|DuckAssist|cohere|YouBot|MistralAI|Diffbot|ImagesiftBot|Timpi|AI2Bot|PetalBot|Applebot|HeadlessChrome|Electron|Playwright|Puppeteer|Selenium/i';

// 集計時に除外するセッションIDの集合を返す（過去データにも効く）
//  ①同じIPから2分以内に4セッション以上が始まった → ページを並列で一気に開く自動巡回
//  ②フォーム送信まで30秒未満、または一度もスクロールせずに送信 → 自動入力（AI営業文など）
function hzBotSids_(array $events): array {
    $first = []; $ip = []; $dur = []; $sd = []; $form = [];
    foreach ($events as $r) {
        $sid = (string)($r['sid'] ?? 'na');
        $e = $r['e'] ?? '';
        if ($e === 'pv') {
            if (!isset($first[$sid])) { $first[$sid] = (int)$r['t']; $ip[$sid] = (string)($r['ip'] ?? ''); }
        } elseif ($e === 'lv') {
            $dur[$sid] = ($dur[$sid] ?? 0) + (int)($r['dur'] ?? 0);
            $sd[$sid] = max($sd[$sid] ?? 0, (int)($r['sd'] ?? 0));
        } elseif ($e === 'ev' && strpos((string)($r['x'] ?? ''), 'form_') === 0) {
            $form[$sid] = 1;
        }
    }
    $bot = [];

    $byIp = [];
    foreach ($first as $sid => $t) { if ($ip[$sid] !== '') $byIp[$ip[$sid]][] = [$t, $sid]; }
    foreach ($byIp as $list) {
        if (count($list) < 4) continue;
        sort($list);
        $n = count($list);
        for ($i = 0, $j = 0; $i < $n; $i++) {
            while ($list[$i][0] - $list[$j][0] > 120) $j++;
            if ($i - $j + 1 >= 4) { for ($k = $j; $k <= $i; $k++) $bot[$list[$k][1]] = 1; }
        }
    }

    foreach (array_keys($form) as $sid) {
        if (($dur[$sid] ?? 0) < 30 || ($sd[$sid] ?? 0) === 0) $bot[$sid] = 1;
    }
    return $bot;
}
