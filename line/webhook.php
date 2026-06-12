<?php
// =====================================================================
//  林材木店 公式LINE Messaging API webhook
//  ① 見積もりウィザード（樹種→グレード→数量→納期→お届け先）
//  ② 施工写真キャンペーン受付（名前→メール→店舗→注文番号→施工箇所
//     →写真→ご感想→利用許諾同意）→ photo_uploads へ保存＋メール通知
//  ※「レビュー」という語は一切使わない（規約配慮：自社HP掲載用の写真募集）
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
const CAMPAIGN_FROM = 'noreply@hayazai.com';

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
const QUOTE_TRIGGERS = ['見積もり', '見積', 'お見積もり', '見積もり依頼'];
const PHOTO_TRIGGERS = ['施工写真', '施工写真を送る', '写真を送る', '写真提供', '施工事例'];

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
// お問い合わせカテゴリ
$INQ_CATS = [
    'product'  => '商品について',
    'sample'   => '無料サンプル請求',
    'shipping' => '配送・納期',
    'payment'  => '注文・お支払い',
    'other'    => 'その他',
];

// 施工写真キャンペーン：購入店舗（photos.php と表記を揃える）
$PC_STORES = [
    'rakuten' => '楽天市場店',
    'yahoo'   => 'Yahoo!ショッピング店',
    'own'     => '公式サイト',
    'other'   => 'その他',
];
const PC_MAX_PHOTOS = 10;

// --- 状態の保存（ユーザー単位の簡易セッション）----------------------
function safeId(string $userId): string {
    return preg_replace('/[^A-Za-z0-9_-]/', '', $userId);
}
function statePath(string $userId): string {
    return __DIR__ . '/state/' . safeId($userId) . '.json';
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

// =====================================================================
//  見積もりウィザード
// =====================================================================
function notifyQuote(array $state, string $displayName, string $userId, string $staffEmail, string $fromEmail): void {
    $b  = "LINEから見積もり依頼が届きました。\n\n";
    $b .= "■ LINE表示名: {$displayName}\n";
    $b .= "■ 商品: " . ($state['product_label'] ?? '-') . "\n";
    $b .= "■ グレード: " . ($state['grade_label'] ?? '-') . "\n";
    $b .= "■ 数量・面積: " . ($state['qty'] ?? '-') . "\n";
    $b .= "■ 希望納期: " . ($state['delivery_label'] ?? '-') . "\n";
    $b .= "■ お届け先: " . ($state['address'] ?? '-') . "\n";
    $b .= "\n※ このお客様へは LINE トーク（{$userId}）から返信してください。\n";
    @mb_send_mail($staffEmail, '【LINE見積もり依頼】' . ($state['product_label'] ?? ''), $b, 'From: ' . $fromEmail);
}
function startQuote(string $replyToken, string $userId, string $token, array $PRODUCTS): void {
    saveState($userId, ['flow' => 'quote', 'step' => 'product']);
    $items = [];
    foreach ($PRODUCTS as $k => $label) $items[] = qrPostback($label, 'q=product&v=' . $k, $label);
    replyMessages($replyToken, [textMsg("お見積もりありがとうございます！🌲\nまず、ご希望の商品をお選びください。", $items)], $token);
}

// =====================================================================
//  施工写真キャンペーン
// =====================================================================
function pcBaseDir(): string {
    // public_html/line/webhook.php → 2つ上が hayazai.com → /photo_uploads（public_html外・rsync対象外）
    return dirname(dirname(__DIR__)) . '/photo_uploads';
}
function pcPendingDir(string $userId): string {
    return pcBaseDir() . '/_pending_line/' . safeId($userId);
}
// LINEの画像コンテンツをダウンロードして保存。成功でファイルパス、失敗でnull
function pcDownloadImage(string $messageId, string $dir, int $idx, string $token): ?string {
    if (!is_dir($dir)) @mkdir($dir, 0705, true);
    $url = 'https://api-data.line.me/v2/bot/message/' . rawurlencode($messageId) . '/content';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 30,
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($data === false || $code !== 200 || !$data) { error_log('[line] image dl failed code=' . $code); return null; }
    $ext = 'jpg';
    if (stripos($ctype, 'png') !== false)  $ext = 'png';
    if (stripos($ctype, 'webp') !== false) $ext = 'webp';
    $path = sprintf('%s/photo%02d.%s', $dir, $idx, $ext);
    if (file_put_contents($path, $data) === false) return null;
    return $path;
}
function startPhoto(string $replyToken, string $userId, string $token): void {
    // 既存のpendingをクリーンにして開始
    $pd = pcPendingDir($userId);
    if (is_dir($pd)) { foreach (glob($pd . '/*') as $f) @unlink($f); }
    saveState($userId, ['flow' => 'photo', 'step' => 'pc_name', 'photos' => 0]);
    replyMessages($replyToken, [textMsg(
        "施工写真のご提供ありがとうございます！🌲\n" .
        "無垢桧フローリング・羽目板を使った施工写真を募集しています。確認後、謝礼としてAmazonギフトカード300円分をメールでお送りします。\n\n" .
        "お送りいただいた写真とご感想は、当店のHP（施工事例）・SNS・カタログなどでご紹介させていただきます（お名前は非公開）。最後に同意の確認があります。\n\n" .
        "まず、お名前を教えてください。\n\n" .
        "✏️ 入力のしかた：画面下の「メッセージを入力」欄に文字を入力して、送信ボタン（紙飛行機マーク）を押してください。\n" .
        "※メニュー画像で入力欄がかくれているときは、左下のキーボードのマーク（または「メニュー」の文字）をタップすると入力欄が出てきます。"
    )], $token);
}
function pcFinalize(array $state, string $userId, string $displayName, array $PC_STORES, string $staffEmail): bool {
    $pending = pcPendingDir($userId);
    $photos = is_dir($pending) ? array_values(array_filter(glob($pending . '/*'), 'is_file')) : [];
    if (count($photos) === 0) return false;

    $subDir = 'LINE_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $dir    = pcBaseDir() . '/' . $subDir;
    if (!is_dir($dir) && !@mkdir($dir, 0705, true)) { error_log('[line] pc mkdir failed'); return false; }
    $moved = 0;
    foreach ($photos as $i => $src) {
        $ext = pathinfo($src, PATHINFO_EXTENSION) ?: 'jpg';
        $dst = sprintf('%s/photo%02d.%s', $dir, $i + 1, $ext);
        if (@rename($src, $dst)) $moved++;
    }
    @rmdir($pending);
    if ($moved === 0) return false;

    $storeLabel = $PC_STORES[$state['store'] ?? ''] ?? ($state['store'] ?? '-');
    $meta = [
        '受付日時'   => date('Y-m-d H:i:s'),
        '経路'       => 'LINE',
        'LINE表示名' => $displayName,
        'お名前'     => $state['name'] ?? '-',
        'メール'     => $state['email'] ?? '-',
        '購入店舗'   => $storeLabel,
        '注文番号'   => $state['order'] ?? '-',
        '施工箇所'   => $state['place'] ?? '-',
        '謝礼種別'   => 'Amazonギフトカード',
        '写真枚数'   => $moved,
        'ご感想'     => $state['comment'] ?? '-',
        '利用許諾'   => '同意あり',
    ];
    $metaText = '';
    foreach ($meta as $k => $v) $metaText .= "■ {$k}\n{$v}\n\n";
    @file_put_contents($dir . '/meta.txt', $metaText);

    // 統一ログ（photos.php と同じ log.csv に追記。末尾に経路列を付与）
    $logLine = [
        date('Y-m-d H:i:s'), $subDir, ($state['name'] ?? ''), ($state['email'] ?? ''),
        $storeLabel, ($state['order'] ?? ''), 'Amazonギフトカード', $moved,
        str_replace(["\r", "\n"], ' ', mb_substr($state['comment'] ?? '', 0, 200)), 'LINE',
    ];
    $fp = @fopen(pcBaseDir() . '/log.csv', 'a');
    if ($fp) { fputcsv($fp, $logLine); fclose($fp); }

    // スタッフ通知
    $subject = "【施工写真・LINE】" . ($state['name'] ?? '') . "様より受付（{$storeLabel}・{$moved}枚）";
    $sbody = "LINEから施工写真のご提供を受け付けました。\n\n" . $metaText
           . "■ 保存先\n~/hayazai.com/photo_uploads/{$subDir}/\n\n"
           . "確認後、謝礼（Amazonギフトカード 300円分）をメールで進呈してください。\n"
           . "※このお客様へは LINEトーク（{$userId}）からも連絡できます。";
    @mb_send_mail($staffEmail, $subject, $sbody, 'From: ' . CAMPAIGN_FROM);

    // 応募者への受付確認メール（任意：メール宛）
    $email = $state['email'] ?? '';
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $ack = ($state['name'] ?? 'お客') . " 様\n\n"
             . "このたびは施工写真のご提供、誠にありがとうございます。\n以下の内容で受け付けました。\n\n"
             . "■ ご購入店舗：{$storeLabel}\n■ 写真：{$moved}枚\n■ 謝礼：Amazonギフトカード 300円分\n\n"
             . "内容を確認のうえ、通常3営業日以内に謝礼のご案内をメールでお送りします。\n"
             . "※ご感想は率直な内容で構いません。内容の良し悪しは謝礼の条件ではありません。\n\n"
             . "─────────────────\n株式会社林材木店\nTEL: 0538-58-2395（平日8:00〜17:00）\nhttps://hayazai.com/\n─────────────────";
        @mb_send_mail($email, '【林材木店】施工写真を受け付けました', $ack, 'From: ' . CAMPAIGN_FROM);
    }
    return true;
}

// =====================================================================
//  イベント処理
// =====================================================================
foreach ($payload['events'] as $ev) {
    $type       = $ev['type'] ?? '';
    $replyToken = $ev['replyToken'] ?? '';
    $userId     = $ev['source']['userId'] ?? '';
    if (!$userId || !$replyToken) continue;

    // ---- フォロー（友だち追加）：あいさつはOA機能に任せる ----
    if ($type === 'follow') { continue; }

    // ---- postback（ボタン選択）----
    if ($type === 'postback') {
        parse_str($ev['postback']['data'] ?? '', $pb);
        $state = loadState($userId);

        // 見積もりウィザード（q=...）
        if (isset($pb['q'])) {
            $step = $pb['q']; $val = $pb['v'] ?? '';
            if ($step === 'restart') { startQuote($replyToken, $userId, $ACCESS_TOKEN, $PRODUCTS); continue; }
            if ($step === 'product' && isset($PRODUCTS[$val])) {
                $state['product'] = $val; $state['product_label'] = $PRODUCTS[$val];
                if ($val === 'other') {
                    $state['step'] = 'qty'; $state['grade_label'] = '-'; saveState($userId, $state);
                    replyMessages($replyToken, [textMsg("ご相談内容（樹種・寸法・用途など）を自由にご記入ください。\n\n✏️ 入力のしかた：画面下の「メッセージを入力」欄に文字を入力して、送信ボタン（紙飛行機マーク）を押してください。\n※メニュー画像で入力欄がかくれているときは、左下のキーボードのマーク（または「メニュー」の文字）をタップすると入力欄が出てきます。")], $ACCESS_TOKEN);
                } else {
                    $state['step'] = 'grade'; saveState($userId, $state);
                    $items = []; foreach ($GRADES as $k => $label) $items[] = qrPostback($label, 'q=grade&v=' . $k, $label);
                    replyMessages($replyToken, [textMsg("グレード（節の有無）をお選びください。", $items)], $ACCESS_TOKEN);
                }
                continue;
            }
            if ($step === 'grade' && isset($GRADES[$val])) {
                $state['grade_label'] = $GRADES[$val]; $state['step'] = 'qty'; saveState($userId, $state);
                replyMessages($replyToken, [textMsg("数量または面積をご記入ください。\n例）30㎡ / 200枚 など\n\n✏️ 入力のしかた：画面下の「メッセージを入力」欄に文字を入力して、送信ボタン（紙飛行機マーク）を押してください。\n※メニュー画像で入力欄がかくれているときは、左下のキーボードのマーク（または「メニュー」の文字）をタップすると入力欄が出てきます。")], $ACCESS_TOKEN);
                continue;
            }
            if ($step === 'delivery' && isset($DELIVERIES[$val])) {
                $state['delivery_label'] = $DELIVERIES[$val]; $state['step'] = 'address'; saveState($userId, $state);
                replyMessages($replyToken, [textMsg("お届け先（都道府県・市区町村）をご記入ください。\n例）静岡県浜松市\n\n✏️ 画面下の入力欄に入力して送信してください。")], $ACCESS_TOKEN);
                continue;
            }
            if ($step === 'confirm' && $val === 'send') {
                $name = fetchDisplayName($userId, $ACCESS_TOKEN);
                notifyQuote($state, $name, $userId, $STAFF_EMAIL, $FROM_EMAIL);
                clearState($userId);
                replyMessages($replyToken, [textMsg("お見積もり依頼を受け付けました！🌲\n内容を確認し、担当者より概算をご連絡します。\n\n営業時間 平日8:00〜17:00\nお急ぎの場合はお電話（0538-58-2395）もどうぞ。")], $ACCESS_TOKEN);
                continue;
            }
            continue;
        }

        // お問い合わせ（i=...）
        if (isset($pb['i'])) {
            if ($pb['i'] === 'cat' && isset($INQ_CATS[$pb['v'] ?? ''])) {
                $cat = $pb['v'];
                $state = ['flow' => 'inquiry', 'step' => 'iq_detail', 'cat' => $cat, 'cat_label' => $INQ_CATS[$cat]];
                saveState($userId, $state);
                if ($cat === 'sample') {
                    $msg = "無料サンプルのご請求ですね！🌲\n以下をまとめてご記入ください。\n\n"
                         . "①お名前\n②お届け先のご住所（郵便番号も）\n③ご希望の商品・グレード\n例）桧フローリング 小節\n\n"
                         . "✏️ 画面下の「メッセージを入力」欄に入力して送信してください。\n"
                         . "※メニュー画像で入力欄がかくれているときは、左下のキーボードのマークをタップすると入力欄が出てきます。";
                } else {
                    $msg = "「" . $INQ_CATS[$cat] . "」についてですね。\nご質問・ご相談の内容をご記入ください。\n\n"
                         . "✏️ 画面下の「メッセージを入力」欄に入力して送信してください。\n"
                         . "※メニュー画像で入力欄がかくれているときは、左下のキーボードのマークをタップすると入力欄が出てきます。";
                }
                replyMessages($replyToken, [textMsg($msg)], $ACCESS_TOKEN);
                continue;
            }
            continue;
        }

        // 施工写真キャンペーン（p=...）
        if (isset($pb['p'])) {
            $step = $pb['p']; $val = $pb['v'] ?? '';
            if ($step === 'store' && isset($PC_STORES[$val])) {
                $state['store'] = $val; $state['step'] = 'pc_order'; saveState($userId, $state);
                $items = [qrPostback('番号がわからない', 'p=order_skip', '番号がわからない')];
                replyMessages($replyToken, [textMsg("ご注文番号を教えてください（確認用です）。分かる範囲でOK。\n\n✏️ 画面下の入力欄に入力して送信してください。\n分からない場合は下の「番号がわからない」ボタンを押せばそのまま進めます。", $items)], $ACCESS_TOKEN);
                continue;
            }
            if ($step === 'order_skip') {
                $state['order'] = '（不明）'; $state['step'] = 'pc_place'; saveState($userId, $state);
                replyMessages($replyToken, [textMsg("施工箇所を教えてください。\n例）リビングの床、寝室の壁、天井 など\n\n✏️ 画面下の入力欄に入力して送信してください。")], $ACCESS_TOKEN);
                continue;
            }
            if ($step === 'photo_done') {
                if (($state['photos'] ?? 0) < 1) {
                    $items = [qrPostback('写真を送り終えた', 'p=photo_done', '写真を送り終えた')];
                    replyMessages($replyToken, [textMsg("まだ写真が届いていません🙏\n施工写真を1枚以上送ってから「写真を送り終えた」を押してください。", $items)], $ACCESS_TOKEN);
                    continue;
                }
                $state['step'] = 'pc_comment'; saveState($userId, $state);
                $items = [qrPostback('感想は書かない', 'p=comment_skip', '感想は書かない')];
                replyMessages($replyToken, [textMsg("ありがとうございます！(" . $state['photos'] . "枚)\nよろしければ、ご感想を一言いただけますか？（任意）\n\n✏️ 画面下の入力欄に入力して送信してください。\n書かない場合は下の「感想は書かない」ボタンでそのまま進めます。", $items)], $ACCESS_TOKEN);
                continue;
            }
            if ($step === 'comment_skip') {
                $state['comment'] = '（なし）'; $state['step'] = 'pc_consent'; saveState($userId, $state);
                $items = [qrPostback('同意して送信', 'p=consent&v=yes', '同意して送信'), qrPostback('同意しない', 'p=consent&v=no', '同意しない')];
                replyMessages($replyToken, [textMsg(
                    "最後に、写真とご感想の利用についてご確認ください。\n\n" .
                    "「ご提供いただいた写真・ご感想を、林材木店のHP・SNS・カタログ等に無期限で掲載すること（お名前は非公開）に同意します」\n\n" .
                    "ご同意いただける場合は「同意して送信」を押してください。", $items)], $ACCESS_TOKEN);
                continue;
            }
            if ($step === 'consent') {
                if ($val === 'no') {
                    clearState($userId);
                    replyMessages($replyToken, [textMsg("承知しました。掲載への同意が必要なため、今回は受付を見送らせていただきます。\nご検討いただきありがとうございました🌲\nまた「施工写真」と送っていただければ、いつでも再開できます。")], $ACCESS_TOKEN);
                    continue;
                }
                // 同意 → 確定
                $name = fetchDisplayName($userId, $ACCESS_TOKEN);
                $ok = pcFinalize($state, $userId, $name, $PC_STORES, $STAFF_EMAIL);
                clearState($userId);
                if ($ok) {
                    replyMessages($replyToken, [textMsg("ありがとうございます！🌲\n内容を確認のうえ、3営業日以内にAmazonギフトカード300円分を、いただいたメールアドレスへお送りします。\n\nご不明点はこのままメッセージでお問い合わせください。")], $ACCESS_TOKEN);
                } else {
                    replyMessages($replyToken, [textMsg("申し訳ありません、写真の保存でエラーが発生しました🙏\nお手数ですが、もう一度「施工写真」と送ってやり直していただくか、HPフォーム（https://hayazai.com/photo.html）からもご応募いただけます。")], $ACCESS_TOKEN);
                }
                continue;
            }
            continue;
        }
        continue;
    }

    // ---- メッセージ ----
    if ($type === 'message') {
        $mtype = $ev['message']['type'] ?? '';
        $state = loadState($userId);
        $step  = $state['step'] ?? '';
        $flow  = $state['flow'] ?? '';

        // 画像メッセージ：施工写真フローの写真ステップなら取り込む
        if ($mtype === 'image') {
            if ($flow === 'photo' && $step === 'pc_photo') {
                $count = ($state['photos'] ?? 0);
                if ($count >= PC_MAX_PHOTOS) {
                    $items = [qrPostback('写真を送り終えた', 'p=photo_done', '写真を送り終えた')];
                    replyMessages($replyToken, [textMsg("写真は最大" . PC_MAX_PHOTOS . "枚までです。「写真を送り終えた」を押して次へお進みください。", $items)], $ACCESS_TOKEN);
                    continue;
                }
                $saved = pcDownloadImage($ev['message']['id'] ?? '', pcPendingDir($userId), $count + 1, $ACCESS_TOKEN);
                if ($saved) {
                    $state['photos'] = $count + 1; saveState($userId, $state);
                    $items = [qrPostback('写真を送り終えた', 'p=photo_done', '写真を送り終えた')];
                    replyMessages($replyToken, [textMsg($state['photos'] . "枚目を受け取りました📷\nまだあれば続けて送ってください。送り終えたら下のボタンを押してください。", $items)], $ACCESS_TOKEN);
                } else {
                    replyMessages($replyToken, [textMsg("写真の取り込みに失敗しました🙏 もう一度送っていただけますか？")], $ACCESS_TOKEN);
                }
                continue;
            }
            // それ以外の画像は案内
            replyMessages($replyToken, [textMsg("画像を受け取りました。施工写真のご応募は「施工写真」と送っていただくと受付を開始します🌲")], $ACCESS_TOKEN);
            continue;
        }

        // テキストメッセージ
        if ($mtype === 'text') {
            $text = trim($ev['message']['text'] ?? '');

            // トリガー（いつでも開始）
            if (in_array($text, QUOTE_TRIGGERS, true)) { startQuote($replyToken, $userId, $ACCESS_TOKEN, $PRODUCTS); continue; }
            if (in_array($text, PHOTO_TRIGGERS, true)) { startPhoto($replyToken, $userId, $ACCESS_TOKEN); continue; }
            // お問い合わせボタン（カテゴリ選択式・キャンペーン案内は出さない）
            if (in_array($text, ['お問い合わせ', 'お問合せ', '問い合わせ'], true)) {
                saveState($userId, ['flow' => 'inquiry', 'step' => 'iq_cat']);
                $items = [];
                foreach ($INQ_CATS as $k => $label) $items[] = qrPostback($label, 'i=cat&v=' . $k, $label);
                replyMessages($replyToken, [textMsg(
                    "お問い合わせありがとうございます！🌲\nご用件に近いものを下のボタンからお選びください。", $items)], $ACCESS_TOKEN);
                continue;
            }
            if (in_array($text, ['キャンセル', 'やめる', '最初から'], true)) {
                clearState($userId);
                replyMessages($replyToken, [textMsg("入力をリセットしました。\n・お見積もり →「見積もり」\n・施工写真のご提供 →「施工写真」\nと送るといつでも再開できます。")], $ACCESS_TOKEN);
                continue;
            }

            // お問い合わせの内容入力 → スタッフ通知＋受付返信
            if ($flow === 'inquiry' && $step === 'iq_detail' && $text !== '') {
                $name = fetchDisplayName($userId, $ACCESS_TOKEN);
                $catLabel = $state['cat_label'] ?? 'その他';
                $b  = "LINEからお問い合わせが届きました。\n\n";
                $b .= "■ カテゴリ: {$catLabel}\n";
                $b .= "■ LINE表示名: {$name}\n";
                $b .= "■ 内容:\n" . mb_substr($text, 0, 3000) . "\n";
                $b .= "\n※ このお客様へは LINEのチャット（{$userId}）から返信してください。\n";
                @mb_send_mail($STAFF_EMAIL, "【LINEお問い合わせ】{$catLabel}", $b, 'From: ' . $FROM_EMAIL);
                clearState($userId);
                replyMessages($replyToken, [textMsg(
                    "お問い合わせを受け付けました！🌲\nスタッフが営業時間内にこのトークでご返信します。\n\n" .
                    "▼営業時間\n平日 8:00〜17:00（土日祝休み）\n" .
                    "お急ぎの場合はお電話（0538-58-2395）もどうぞ。"
                )], $ACCESS_TOKEN);
                continue;
            }

            // 見積もりウィザードの自由記述
            if ($flow === 'quote' && $step === 'qty' && $text !== '') {
                $state['qty'] = $text; $state['step'] = 'delivery'; saveState($userId, $state);
                $items = []; foreach ($DELIVERIES as $k => $label) $items[] = qrPostback($label, 'q=delivery&v=' . $k, $label);
                replyMessages($replyToken, [textMsg("希望納期をお選びください。", $items)], $ACCESS_TOKEN);
                continue;
            }
            if ($flow === 'quote' && $step === 'address' && $text !== '') {
                $state['address'] = $text; $state['step'] = 'confirm'; saveState($userId, $state);
                $summary = "ご入力ありがとうございます。以下の内容でよろしいですか？\n\n"
                    . "商品: " . ($state['product_label'] ?? '-') . "\nグレード: " . ($state['grade_label'] ?? '-')
                    . "\n数量・面積: " . ($state['qty'] ?? '-') . "\n希望納期: " . ($state['delivery_label'] ?? '-')
                    . "\nお届け先: " . ($state['address'] ?? '-');
                $items = [qrPostback('この内容で送信', 'q=confirm&v=send', 'この内容で送信'), qrPostback('最初からやり直す', 'q=restart', '最初からやり直す')];
                replyMessages($replyToken, [textMsg($summary, $items)], $ACCESS_TOKEN);
                continue;
            }

            // 施工写真フローの自由記述
            if ($flow === 'photo' && $step === 'pc_name' && $text !== '') {
                $state['name'] = mb_substr($text, 0, 100); $state['step'] = 'pc_email'; saveState($userId, $state);
                replyMessages($replyToken, [textMsg("ありがとうございます、" . $state['name'] . "様。\nAmazonギフトカードの送付先となる【メールアドレス】を教えてください。\n\n✏️ 画面下の入力欄に入力して送信してください。")], $ACCESS_TOKEN);
                continue;
            }
            if ($flow === 'photo' && $step === 'pc_email' && $text !== '') {
                if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
                    replyMessages($replyToken, [textMsg("メールアドレスの形式が正しくないようです🙏\nもう一度、ギフトカード送付先のメールアドレスを教えてください。\n例）example@gmail.com")], $ACCESS_TOKEN);
                    continue;
                }
                $state['email'] = $text; $state['step'] = 'pc_store'; saveState($userId, $state);
                $items = []; foreach ($PC_STORES as $k => $label) $items[] = qrPostback($label, 'p=store&v=' . $k, $label);
                replyMessages($replyToken, [textMsg("ご購入店舗をお選びください。", $items)], $ACCESS_TOKEN);
                continue;
            }
            if ($flow === 'photo' && $step === 'pc_order' && $text !== '') {
                $state['order'] = mb_substr($text, 0, 100); $state['step'] = 'pc_place'; saveState($userId, $state);
                replyMessages($replyToken, [textMsg("施工箇所を教えてください。\n例）リビングの床、寝室の壁、天井 など\n\n✏️ 画面下の入力欄に入力して送信してください。")], $ACCESS_TOKEN);
                continue;
            }
            if ($flow === 'photo' && $step === 'pc_place' && $text !== '') {
                $state['place'] = mb_substr($text, 0, 300); $state['step'] = 'pc_photo'; saveState($userId, $state);
                $items = [qrPostback('写真を送り終えた', 'p=photo_done', '写真を送り終えた')];
                replyMessages($replyToken, [textMsg(
                    "では施工写真を送ってください📷\n" .
                    "（目安：リフォームは施工前3枚＋施工後3枚／新築はいろんな角度から5枚ほど。難しければ　できる範囲でOKです）\n\n" .
                    "📷 写真の送り方：\n" .
                    "画面下の入力欄の左にある【＋】か【写真マーク🖼】をタップ → アルバムから写真を選んで送信してください。複数まとめて選んでもOKです。\n" .
                    "※入力欄が見えないときは、左下のキーボードのマークをタップするとメニューが閉じて入力欄が出てきます。\n\n" .
                    "送り終えたら下の「写真を送り終えた」ボタンを押してください。", $items)], $ACCESS_TOKEN);
                continue;
            }
            if ($flow === 'photo' && $step === 'pc_photo') {
                // 写真ステップでテキストが来たら案内
                $items = [qrPostback('写真を送り終えた', 'p=photo_done', '写真を送り終えた')];
                replyMessages($replyToken, [textMsg("写真は画像として送ってください📷\n送り終えたら「写真を送り終えた」を押してください。", $items)], $ACCESS_TOKEN);
                continue;
            }
            if ($flow === 'photo' && $step === 'pc_comment' && $text !== '') {
                $state['comment'] = mb_substr($text, 0, 3000); $state['step'] = 'pc_consent'; saveState($userId, $state);
                $items = [qrPostback('同意して送信', 'p=consent&v=yes', '同意して送信'), qrPostback('同意しない', 'p=consent&v=no', '同意しない')];
                replyMessages($replyToken, [textMsg(
                    "ありがとうございます！最後に、写真とご感想の利用についてご確認ください。\n\n" .
                    "「ご提供いただいた写真・ご感想を、林材木店のHP・SNS・カタログ等に無期限で掲載すること（お名前は非公開）に同意します」\n\n" .
                    "ご同意いただける場合は「同意して送信」を押してください。", $items)], $ACCESS_TOKEN);
                continue;
            }

            // それ以外（お問い合わせ等）→ 案内
            replyMessages($replyToken, [textMsg(
                "メッセージありがとうございます！🌲\n" .
                "・お見積もり →「見積もり」と送信、または下メニューから\n" .
                "・施工写真のご提供（Amazonギフト券300円分）→「施工写真」と送信\n" .
                "・その他のお問い合わせはこのままご記入ください。担当者よりご返信します。\n\n" .
                "営業時間 平日8:00〜17:00"
            )], $ACCESS_TOKEN);
            continue;
        }
    }
}

echo json_encode(['ok' => true]);
