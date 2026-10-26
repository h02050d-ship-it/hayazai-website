<?php
// =====================================================
// 林材木店 お問い合わせ受付処理
// =====================================================

define('SHOP_EMAIL', 'info@hayazai.com');
define('SHOP_NAME',  '林材木店');

mb_language('Japanese');
mb_internal_encoding('UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('X-Robots-Tag: noindex, nofollow', true);
    header('Location: /contact.html', true, 301);
    exit;
}

function h($str) {
    return htmlspecialchars(trim($str ?? ''), ENT_QUOTES, 'UTF-8');
}

// =====================================================
// スパム・営業ボット対策（顧客は取りこぼさない設計）
// =====================================================
// 1) ハニーポット：人間には見えない隠しフィールド。入力があれば自動投稿ボット
//    と判断し、送信成功を装って静かに破棄する（相手に気づかれない）。
if (!empty($_POST['website'])) {
    header('Location: contact_complete.html');
    exit;
}
// 2) JavaScriptが動いていたか（多くのスパムボットはJSを実行しない）。
//    これ単独ではブロックせず、後段の営業判定の弱い加点材料に使う。
$js_ok = (($_POST['hp_token'] ?? '') === 'ok');

$name    = h($_POST['name']);
$company = h($_POST['company']);
$email   = h($_POST['email']);
$tel     = h($_POST['tel']);
$type    = h($_POST['type']);
$message = h($_POST['message']);

// 法人見積もりフォーム（business.html）の追加フィールド
$industry_labels = [
    'builder'   => '工務店・住宅会社',
    'architect' => '設計事務所・建築士',
    'reform'    => 'リフォーム業者',
    'contractor'=> 'ゼネコン・建設会社',
    'diy_shop'  => 'DIY販売店・小売',
    'other'     => 'その他',
];
$product_type_labels = [
    'flooring15' => '桧フローリング 15mm',
    'flooring12' => '桧フローリング 12mm',
    'panel'      => '桧羽目板 12mm',
    'multiple'   => '複数',
];
$industry       = h($_POST['industry'] ?? '');
$product_type   = h($_POST['product_type'] ?? '');
$quantity_range = h($_POST['quantity_range'] ?? '');
$quote_lines = [];
if ($industry)       $quote_lines[] = '業種：' . ($industry_labels[$industry] ?? $industry);
if ($product_type)   $quote_lines[] = '希望商品：' . ($product_type_labels[$product_type] ?? $product_type);
if ($quantity_range) $quote_lines[] = '希望数量：' . $quantity_range . '束';
$quote_info = $quote_lines ? implode("\n", $quote_lines) : '';

// 法人見積もり（quote）でメッセージ未入力の場合はデフォルト文を補完
if ($type === 'quote' && !$message && $quote_info) {
    $message = '法人見積もりを依頼します。';
}

// サンプル請求フィールド
$sample_zip      = h($_POST['sample_zip'] ?? '');
$sample_address  = h($_POST['sample_address'] ?? '');
$sample_items    = $_POST['sample_items'] ?? [];
$sample_items_str = implode('・', array_map(fn($v) => $product_type_labels[h($v)] ?? h($v), $sample_items));
$sample_grades_raw = $_POST['sample_grades'] ?? [];
$grade_labels_map  = ['fushi_ari' => '節有', 'ko_fushi' => '小節', 'toku_ko' => '特上小', 'mushi' => '無節'];
$sample_grades_str = implode('・', array_map(fn($v) => $grade_labels_map[h($v)] ?? h($v), $sample_grades_raw));

$type_labels = [
    'sample'       => '無料サンプル請求',
    'quote'        => '見積もり依頼',
    'product'      => '商品について',
    'construction' => '施工方法について',
    'delivery'     => '納期・配送について',
    'other'        => 'その他',
    'sales'        => '営業・サービスのご案内',
];
$type_label = $type_labels[$type] ?? $type;

$errors = [];
if (!$name)  $errors[] = 'お名前は必須です';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'メールアドレスが正しくありません';
if (!$type)  $errors[] = 'お問い合わせ種別を選択してください';
if (!$message) $errors[] = 'お問い合わせ内容を入力してください';

// サンプル請求の場合の追加バリデーション
if ($type === 'sample') {
    if (!$tel)           $errors[] = '電話番号を入力してください（配送伝票の記載に必要です）';
    if (!$sample_zip)    $errors[] = '郵便番号を入力してください';
    if (!$sample_address) $errors[] = '住所を入力してください';
    if (empty($sample_items)) $errors[] = 'サンプルの種類を1つ以上選択してください';
    if (empty($sample_grades_raw)) $errors[] = 'グレードを1つ以上選択してください';
}

if (!empty($errors)) {
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><title>エラー | 林材木店</title></head><body>';
    echo '<p style="color:red;padding:40px;">入力エラー：<br>' . nl2br(implode('<br>', array_map('h', $errors))) . '</p>';
    echo '<a href="javascript:history.back()">← 戻る</a></body></html>';
    exit;
}

// =====================================================
// 営業・勧誘メールの判定（ブロックせず、件名に印を付けてGmailで自動仕分け）
// =====================================================
// 判定ロジックは contact_sales_check.php（サーバー上で過去メールを流して検証できるよう分離）
require_once __DIR__ . '/contact_sales_check.php';

// サンプル請求・見積もり依頼で品目が選ばれている問い合わせは確実に本物→除外
// ただしJS未実行（＝ボット疑い）の場合はこの「本物」扱いを適用しない。
// 自動投稿ボットは全項目を埋めるため、品目選択だけを根拠にすると素通りしてしまう。
$is_genuine = in_array($type, ['sample','quote'], true) && ($sample_items_str || $quote_info) && $js_ok;

$sales_result = hayazai_sales_check([
    'company' => $company, 'message' => $message, 'email' => $email,
    'type' => $type, 'js_ok' => $js_ok, 'is_genuine' => $is_genuine,
]);
$is_sales = $sales_result['is_sales'];

// 3) 自動投稿ボットの確定判定（ハニーポットをすり抜けた分をここで止める）
//    例：お名前が「edkvmxgjhp」のようなランダム英字だけ、本文にも日本語が一切ない。
//    いずれも「JavaScriptが動いていない（＝人間のブラウザではない）」場合に限って適用するため、
//    JSが動く通常のお客様は絶対にブロックされない。
//    ここで通してしまうとGmailが「この差出人＝迷惑メール」と学習し、本物の問い合わせまで
//    迷惑メールに落ちてしまうため、送信自体を行わず静かに破棄する。
$name_is_gibberish = (bool)preg_match('/^[a-z]{8,24}$/', $name);
$has_japanese      = (bool)preg_match('/[ぁ-んァ-ヶ一-龥]/u', $name . ' ' . $company . ' ' . $message . ' ' . $sample_address);

$is_bot = false;
if (!$js_ok && ($name_is_gibberish || !$has_japanese)) {
    // JavaScriptが動いていない＝人間のブラウザではない
    $is_bot = true;
} elseif ($name_is_gibberish && !$has_japanese) {
    // hp_tokenを偽装してくるボット対策。お名前がランダム英字で日本語が一文字も無い
    // 投稿は、国内向けの当社への問い合わせとしては実質あり得ない。
    $is_bot = true;
}

if ($is_bot) {
    // 完全に捨てるのではなく、公開領域の外にログとして残す（誤判定の検証用）。
    // メールは送らない＝Gmailに「この差出人＝迷惑メール」と再学習させないため。
    $logDir = dirname(__DIR__) . '/blocked_contacts';
    if (!is_dir($logDir)) @mkdir($logDir, 0705, true);
    $logLine = str_repeat('-', 60) . "\n"
        . '日時：' . date('Y-m-d H:i:s') . "\n"
        . 'IP：' . ($_SERVER['REMOTE_ADDR'] ?? '') . "\n"
        . "種別：{$type_label}\n"
        . "お名前：{$name}\n会社名：{$company}\nEmail：{$email}\nTEL：{$tel}\n"
        . "内容：{$message}\n"
        . ($sample_address ? "送付先：〒{$sample_zip} {$sample_address}\n" : '')
        . 'JS実行：' . ($js_ok ? 'あり' : 'なし') . "\n";
    @file_put_contents($logDir . '/' . date('Y-m') . '.log', $logLine, FILE_APPEND | LOCK_EX);

    header('Location: contact_complete.html');
    exit;
}

// お客様へのメール
$customer_body = <<<EOT
{$name} 様

お問い合わせいただきありがとうございます。
以下の内容で承りました。通常1〜3営業日以内にご返答いたします。

3営業日を過ぎてもご連絡がない場合は、メールが届いていない可能性がございます。
お手数ですがお電話にてご確認ください。
TEL：0538-58-2395（平日 9:00〜17:00）

■ お問い合わせ種別
  {$type_label}

■ お問い合わせ内容
  {$message}
EOT;

if ($type === 'sample' && $sample_address) {
    $customer_body .= "\n■ サンプル送付先\n  〒{$sample_zip} {$sample_address}\n  ご希望品目：{$sample_items_str}\n  ご希望グレード：{$sample_grades_str}";
}

$customer_body .= <<<EOT


──────────────────────────
林材木店
TEL：0538-58-2395（平日9:00〜17:00）
Email：info@hayazai.com
EOT;

// 店舗へのメール
$shop_body = <<<EOT
【お問い合わせ】{$type_label}

お名前：{$name}
会社名：{$company}
Email：{$email}
TEL：{$tel}

種別：{$type_label}
内容：
{$message}
EOT;

if ($quote_info) {
    $shop_body .= "\n\n【法人見積もり情報】\n{$quote_info}";
}

if ($type === 'sample') {
    $shop_body .= "\n\nサンプル送付先：〒{$sample_zip} {$sample_address}\nご希望品目：{$sample_items_str}\nご希望グレード：{$sample_grades_str}";
}

// 差出人は実在する info@hayazai.com を使う（存在しない contact-noreply@ 名義は
// なりすまし扱いされGmailの迷惑メール判定を受けやすいため 2026-08 に変更）。
// お客様への返信は Reply-To で成立するので運用は変わらない。
$headers_customer = "From: " . mb_encode_mimeheader(SHOP_NAME, 'UTF-8', 'B') . " <" . SHOP_EMAIL . ">";
$headers_shop     = "From: " . mb_encode_mimeheader(SHOP_NAME, 'UTF-8', 'B') . " <" . SHOP_EMAIL . ">\r\nReply-To: {$email}";
// エンベロープ送信者もドメインを揃える（SPF/DMARCの整合を取り迷惑メール判定を避ける）
$envelope = '-f ' . SHOP_EMAIL;

// 営業判定なら件名に印を付け、Gmailの自動仕分け（フィルタ）で受信トレイから外せるようにする
$shop_subject = ($is_sales ? '【営業の可能性】' : '') . "【お問い合わせ】{$type_label}／{$name}様";
if ($is_sales) {
    $shop_body = "※自動判定：このメールは営業・勧誘の可能性が高いと判定されました（{$sales_result['reason']}）。\n"
               . "※お客様からの問い合わせだった場合は、件名の【営業の可能性】を気にせず通常どおり返信してください。\n\n" . $shop_body;
}

// 営業判定の場合は相手（スパマー）への自動返信は送らない。本物の問い合わせにのみ自動返信。
if (!$is_sales) {
    mb_send_mail($email, "[林材木店] お問い合わせを受け付けました", $customer_body, $headers_customer, $envelope);
}
mb_send_mail(SHOP_EMAIL, $shop_subject, $shop_body, $headers_shop, $envelope);

header('Location: contact_complete.html');
exit;
