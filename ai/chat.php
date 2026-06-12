<?php
// 林材木店 AIチャット回答エンドポイント
// フロント(js/ai-chat.js) → ここ → OpenAI Chat Completions
// APIキーは ai/config.php（GitHub Actionsデプロイ時にSecretsから自動生成・リポジトリ非含有）

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

// 同一サイトからの利用のみ（簡易チェック）
$origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
if ($origin !== '' && strpos($origin, 'hayazai.com') === false && strpos($origin, 'localhost') === false) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$config = @include __DIR__ . '/config.php';
if (!$config || empty($config['api_key'])) {
    http_response_code(503);
    echo json_encode(['error' => 'AIチャットは現在準備中です。お電話（0538-58-2395）またはLINEでお問い合わせください。']);
    exit;
}

// ---- 簡易レート制限（IPごと 20回/時） ----
$stateDir = __DIR__ . '/state';
if (!is_dir($stateDir)) { @mkdir($stateDir, 0755, true); }
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$bucket = $stateDir . '/rate_' . md5($ip) . '.json';
$now = time();
$hits = [];
if (is_file($bucket)) {
    $hits = json_decode((string)file_get_contents($bucket), true) ?: [];
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - 3600));
}
if (count($hits) >= 20) {
    http_response_code(429);
    echo json_encode(['error' => 'ご利用が集中しています。しばらく時間をおいてお試しいただくか、お電話（0538-58-2395）・LINEでお問い合わせください。']);
    exit;
}
$hits[] = $now;
@file_put_contents($bucket, json_encode($hits), LOCK_EX);

// ---- 入力検証 ----
$body = json_decode((string)file_get_contents('php://input'), true);
$messages = $body['messages'] ?? null;
if (!is_array($messages) || count($messages) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'bad request']);
    exit;
}
$messages = array_slice($messages, -12); // 直近12発言まで
$clean = [];
foreach ($messages as $m) {
    $role = ($m['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $content = trim((string)($m['content'] ?? ''));
    if ($content === '' || mb_strlen($content) > 1000) {
        http_response_code(400);
        echo json_encode(['error' => '1,000文字以内でご入力ください。']);
        exit;
    }
    $clean[] = ['role' => $role, 'content' => $content];
}

// ---- システムプロンプト ----
$knowledge = @file_get_contents(__DIR__ . '/knowledge.txt') ?: '';
$system = <<<SYS
あなたは静岡県磐田市の桧専門材木店「林材木店」（hayazai.com）公式サイトのAIアシスタントです。

## 回答ルール
- 日本語で、丁寧かつ簡潔に（目安350字以内。必要なら箇条書き）。
- 下の【サイト知識】に書かれた事実と、桧・無垢フローリングの一般知識の範囲で答える。
- 具体的な価格・在庫の有無・納期は絶対に断定しない。「商品一覧ページ（https://hayazai.com/products.html）でご確認ください」や「お電話（0538-58-2395）・LINEでご確認ください」へ誘導する。
- DIY施工・お手入れの一般的な方法はブログの内容に沿って説明してよい。ただし床暖房・下地の腐食・マンションの規約・構造に関わる判断は専門業者や管理組合への確認を必ず勧める。
- わからないこと・知識にないことは正直に「わかりかねます」と言い、お問い合わせ（電話0538-58-2395／LINE／フォーム https://hayazai.com/contact.html）へ誘導する。推測で答えない。
- 健康・医療効果の断定（「アトピーに効く」等）はしない。「〜とされています」の範囲まで。
- 他社・他製品の誹謗はしない。値引き交渉・取り置き・注文の確定はできない旨を伝える。
- 回答の最後に、関連するサイト内ページのURLを1〜2個まで添えてよい（https://hayazai.com/... 形式）。
- あなたはAIであり、回答には誤りが含まれる可能性があることを問われたら認め、重要な判断の前には電話等での確認を勧める。

【サイト知識】
{$knowledge}
SYS;

// ---- OpenAI API 呼び出し ----
$payload = json_encode([
    'model' => $config['model'] ?? 'gpt-4o-mini',
    'messages' => array_merge([['role' => 'system', 'content' => $system]], $clean),
    'max_tokens' => 600,
    'temperature' => 0.4,
], JSON_UNESCAPED_UNICODE);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $config['api_key'],
    ],
    CURLOPT_TIMEOUT => 30,
]);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($res === false || $code >= 400) {
    http_response_code(502);
    $out = ['error' => 'AIの応答に失敗しました。お急ぎの場合はお電話（0538-58-2395）・LINEでお問い合わせください。'];
    if (!empty($body['debug'])) {
        $out['debug_code'] = $code;
        $out['debug_body'] = mb_substr((string)$res, 0, 300);
        $out['debug_keylen'] = strlen((string)$config['api_key']);
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($res, true);
$reply = trim($data['choices'][0]['message']['content'] ?? '');
if ($reply === '') {
    http_response_code(502);
    echo json_encode(['error' => 'AIの応答に失敗しました。お電話（0538-58-2395）・LINEでお問い合わせください。']);
    exit;
}

echo json_encode(['reply' => $reply], JSON_UNESCAPED_UNICODE);
