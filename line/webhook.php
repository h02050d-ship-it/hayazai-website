<?php
// =====================================================================
//  林材木店 公式LINE Messaging API webhook
//  - ボタン選択ウィザードで見積もり条件を絞り込み
//  - 整形した依頼内容を info@hayazai.com へ通知 ＆ お客さんへ受付返信
//  公開リポジトリのため、シークレットは line/config.php（非コミット）に置く
// =====================================================================

mb_language('Japanese');
mb_internal_encoding('UTF-8');
header('Content-Type: application/json; charset=utf-8');

// --- 設定読み込み ---------------------------------------------------
$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    error_log('[line] config.php not found');
    echo json_encode(['error' => 'config missing']);
    exit;
}
$CONFIG = require $configPath;
$CHANNEL_SECRET = $CONFIG['channel_secret'] ?? '';
$ACCESS_TOKEN   = $CONFIG['channel_access_token'] ?? '';
$STAFF_EMAIL    = $CONFIG['staff_email'] ?? 'info@hayazai.com';
$FROM_EMAIL     = $CONFIG['from_email'] ?? 'info@hayazai.com';

// --- 署名検証 -------------------------------------------------------
$body      = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';
$expected  = base64_encode(hash_hmac('sha256', $body, $CHANNEL_SECRET, true));
if (!$signature || !hash_equals($expected, $signature)) {
    http_response_code(401);
    error_log('[line] invalid signature');
    echo json_encode(['error' => 'invalid signature']);
    exit;
}

$payload = json_decode($body, true);
if (!isset($payload['events']) || !is_array($payload['events'])) {
    echo json_encode(['ok' => true]);
    exit;
}

// --- マスタ定義 -----------------------------------------------------
const TRIGGERS = ['見積もり', '見積', 'お見積もり', '見積もり依頼'];

$PRODUCTS = [
    'flooring' => '無垢桧フローリング',
    'hameita'  => '桧 羽目板',
    'other'    => 'その他・相談',
];
$GRADES = [
    'mushi'     => '無節',
    'toku_ko'   => '特上小',
    'ko_fushi'  => '小節',
    'fushi_ari' => '節有',
    'osr'       => 'おまかせ・相談',
];
$DELIVERIES = [
    'asap'   => 'できるだけ早く',
    'm1'     => '1ヶ月以内',
    'm3'     => '2〜3ヶ月',
    'undec'  => '時期未定',
];

// --- 状態の保存（ユーザー単位の簡易セッション）----------------------
function statePath(string $userId): string {
    return __DIR__ . '/state/' . preg_replace('/[^A-Za-z0-9_-]/', '', $userId) . '.json';
}
function loadState(string $userId): array {
    $p = statePath($userId);
    if (is_file($p)) {
        $d = json_decode((string)file_get_contents($p), true);
        if (is_array($d)) return $d;
    }
    return [];
}
function saveState(string $userId, array $state): void {
    @file_put_contents(statePath($userId), json_encode($state, JSON_UNESCAPED_UNICODE));
}
function clearState(string $userId): void {
    $p = statePath($userId);
    if (is_file($p)) @unlink($p);
}

// --- LINE 返信 ------------------------------------------------------
function replyMessages(string $replyToken, array $messages, string $token): void {
    $ch = curl_init('https://api.line.me/v2/bot/message/reply');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => json_encode(['replyToken' => $replyToken, 'messages' => $messages], JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 10,
    ]);
    $res = curl_exec($ch);
    if ($res === false) error_log('[line] reply curl error: ' . curl_error($ch));
    curl_close($ch);
}
function textMsg(string $text, ?array $quickReply = null): array {
    $m = ['type' => 'text', 'text' => $text];
    if ($quickReply) $m['quickReply'] = ['items' => $quickReply];
    return $m;
}
// postback 形式のクイックリプライ項目
function qrPostback(string $label, string $data, string $displayText): array {
    return ['type' => 'action', 'action' => [
        'type' => 'postback', 'label' => mb_substr($label, 0, 20),
        'data' => $data, 'displayText' => $displayText,
    ]];
}

// --- ユーザープロフィール取得（任意）--------------------------------
function fetchDisplayName(string $userId, string $token): string {
    $ch = curl_init('https://api.line.me/v2/bot/profile/' . rawurlencode($userId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 8,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $d = json_decode((string)$res, true);
    return $d['displayName'] ?? '(不明)';
}

// --- スタッフ通知メール ---------------------------------------------
function notifyStaff(array $state, string $displayName, string $userId, string $staffEmail, string $fromEmail): void {
    $b  = "LINEから見積もり依頼が届きました。\n\n";
    $b .= "■ LINE表示名: {$displayName}\n";
    $b .= "■ 商品: " . ($state['product_label'] ?? '-') . "\n";
    $b .= "■ グレード: " . ($state['grade_label'] ?? '-') . "\n";
    $b .= "■ 数量・面積: " . ($state['qty'] ?? '-') . "\n";
    $b .= "■ 希望納期: " . ($state['delivery_label'] ?? '-') . "\n";
    $b .= "■ お届け先: " . ($state['address'] ?? '-') . "\n";
    $b .= "\n※ このお客様へは LINE トーク（{$userId}）から返信してください。\n";
    $subject = '【LINE見積もり依頼】' . ($state['product_label'] ?? '');
    $headers = 'From: ' . $fromEmail;
    @mb_send_mail($staffEmail, $subject, $b, $headers);
}

// --- ウィザード本体 -------------------------------------------------
function startWizard(string $replyToken, string $userId, string $token, array $PRODUCTS): void {
    saveState($userId, ['step' => 'product']);
    $items = [];
    foreach ($PRODUCTS as $k => $label) {
        $items[] = qrPostback($label, 'step=product&v=' . $k, $label);
    }
    replyMessages($replyToken, [
        textMsg("お見積もりありがとうございます！🌲\nまず、ご希望の商品をお選びください。", $items)
    ], $token);
}

// =====================================================================
//  イベント処理
// =====================================================================
foreach ($payload['events'] as $ev) {
    $type      = $ev['type'] ?? '';
    $replyToken = $ev['replyToken'] ?? '';
    $userId    = $ev['source']['userId'] ?? '';
    if (!$userId || !$replyToken) continue;

    // ---- フォロー（友だち追加）----
    // あいさつは LINE公式アカウントの「あいさつメッセージ」機能に任せる（二重送信防止）。
    // ここでは何も返信しない。
    if ($type === 'follow') {
        continue;
    }

    // ---- postback（ボタン選択）----
    if ($type === 'postback') {
        parse_str($ev['postback']['data'] ?? '', $pb);
        $step = $pb['step'] ?? '';
        $val  = $pb['v'] ?? '';
        $state = loadState($userId);

        if ($step === 'restart') { startWizard($replyToken, $userId, $ACCESS_TOKEN, $PRODUCTS); continue; }

        if ($step === 'product' && isset($PRODUCTS[$val])) {
            $state['step'] = 'grade';
            $state['product'] = $val;
            $state['product_label'] = $PRODUCTS[$val];
            saveState($userId, $state);
            if ($val === 'other') {
                // その他は自由記述へ
                $state['step'] = 'qty';
                $state['grade_label'] = '-';
                saveState($userId, $state);
                replyMessages($replyToken, [textMsg("ご相談内容（樹種・寸法・用途など）を自由にご記入ください。")], $ACCESS_TOKEN);
            } else {
                $items = [];
                foreach ($GRADES as $k => $label) $items[] = qrPostback($label, 'step=grade&v=' . $k, $label);
                replyMessages($replyToken, [textMsg("グレード（節の有無）をお選びください。", $items)], $ACCESS_TOKEN);
            }
            continue;
        }

        if ($step === 'grade' && isset($GRADES[$val])) {
            $state['step'] = 'qty';
            $state['grade'] = $val;
            $state['grade_label'] = $GRADES[$val];
            saveState($userId, $state);
            replyMessages($replyToken, [textMsg("数量または面積をご記入ください。\n例）30㎡ / 200枚 など")], $ACCESS_TOKEN);
            continue;
        }

        if ($step === 'delivery' && isset($DELIVERIES[$val])) {
            $state['step'] = 'address';
            $state['delivery'] = $val;
            $state['delivery_label'] = $DELIVERIES[$val];
            saveState($userId, $state);
            replyMessages($replyToken, [textMsg("お届け先（都道府県・市区町村）をご記入ください。\n例）静岡県浜松市")], $ACCESS_TOKEN);
            continue;
        }

        if ($step === 'confirm' && $val === 'send') {
            $name = fetchDisplayName($userId, $ACCESS_TOKEN);
            notifyStaff($state, $name, $userId, $STAFF_EMAIL, $FROM_EMAIL);
            clearState($userId);
            replyMessages($replyToken, [textMsg(
                "お見積もり依頼を受け付けました！🌲\n内容を確認し、担当者より概算をご連絡します。\n\n営業時間 平日8:00〜17:00\nお急ぎの場合はお電話（0538-58-2395）もどうぞ。"
            )], $ACCESS_TOKEN);
            continue;
        }
        continue;
    }

    // ---- テキストメッセージ ----
    if ($type === 'message' && ($ev['message']['type'] ?? '') === 'text') {
        $text  = trim($ev['message']['text'] ?? '');
        $state = loadState($userId);
        $step  = $state['step'] ?? '';

        // 見積もりトリガー（いつでもウィザード開始）
        if (in_array($text, TRIGGERS, true)) {
            startWizard($replyToken, $userId, $ACCESS_TOKEN, $PRODUCTS);
            continue;
        }
        // キャンセル
        if (in_array($text, ['キャンセル', 'やめる', '最初から'], true)) {
            clearState($userId);
            replyMessages($replyToken, [textMsg("見積もりの入力をリセットしました。下のメニュー「見積もり依頼」からいつでも再開できます。")], $ACCESS_TOKEN);
            continue;
        }

        // ウィザードの自由記述ステップ
        if ($step === 'qty' && $text !== '') {
            $state['qty'] = $text;
            $state['step'] = 'delivery';
            saveState($userId, $state);
            $items = [];
            foreach ($DELIVERIES as $k => $label) $items[] = qrPostback($label, 'step=delivery&v=' . $k, $label);
            replyMessages($replyToken, [textMsg("希望納期をお選びください。", $items)], $ACCESS_TOKEN);
            continue;
        }
        if ($step === 'address' && $text !== '') {
            $state['address'] = $text;
            $state['step'] = 'confirm';
            saveState($userId, $state);
            $summary = "ご入力ありがとうございます。以下の内容でよろしいですか？\n\n"
                . "商品: " . ($state['product_label'] ?? '-') . "\n"
                . "グレード: " . ($state['grade_label'] ?? '-') . "\n"
                . "数量・面積: " . ($state['qty'] ?? '-') . "\n"
                . "希望納期: " . ($state['delivery_label'] ?? '-') . "\n"
                . "お届け先: " . ($state['address'] ?? '-');
            $items = [
                qrPostback('この内容で送信', 'step=confirm&v=send', 'この内容で送信'),
                qrPostback('最初からやり直す', 'step=restart', '最初からやり直す'),
            ];
            replyMessages($replyToken, [textMsg($summary, $items)], $ACCESS_TOKEN);
            continue;
        }

        // それ以外（お問い合わせ等）→ 案内
        replyMessages($replyToken, [textMsg(
            "メッセージありがとうございます！🌲\nお見積もりは下のメニュー「見積もり依頼」から、\nその他のお問い合わせはこのままご記入ください。担当者よりご返信します。\n\n営業時間 平日8:00〜17:00"
        )], $ACCESS_TOKEN);
        continue;
    }
}

echo json_encode(['ok' => true]);
