<?php
// =====================================================
// 林材木店 お問い合わせ受付処理
// =====================================================

define('SHOP_EMAIL', 'info@hayazai.com');
define('SHOP_NAME',  '林材木店');

mb_language('Japanese');
mb_internal_encoding('UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: contact.html');
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
$sample_items_str = implode('・', array_map('h', $sample_items));
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
];
$type_label = $type_labels[$type] ?? $type;

$errors = [];
if (!$name)  $errors[] = 'お名前は必須です';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'メールアドレスが正しくありません';
if (!$type)  $errors[] = 'お問い合わせ種別を選択してください';
if (!$message) $errors[] = 'お問い合わせ内容を入力してください';

// サンプル請求の場合の追加バリデーション
if ($type === 'sample') {
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
// 林材木店への本物の問い合わせは「桧・フローリング・羽目板・サンプル・見積・施工・
// 納期・数量」などが中心。営業は下記のような語が複数並ぶ傾向がある。
$sales_keywords = [
    // Web集客・制作系
    'SEO','seo','上位表示','検索順位','アクセスアップ','集客','広告運用','リスティング',
    'MEO','ホームページ制作','サイト制作','Web制作','ＷＥＢ制作','LP制作','制作いたします',
    // 提携・代理店・補助金系
    '助成金','補助金','業務提携','提携のご','代理店','販売代理','OEM','フランチャイズ','加盟店',
    // 物販・副業・投資系
    '副業','物販','せどり','転売','仕入れ','無在庫','EC事業','EC・物販','EC強化','収益','月収',
    '不労所得','投資','資産運用','FX','暗号資産','仮想通貨','ハイブランド','正規品',
    // コスト・金融系
    'コスト削減','電気代','電力会社','リース','融資','ファクタリング','資金調達',
    // 人材・営業代行系
    '人材紹介','求人広告','採用代行','営業代行','テレアポ','リード獲得','商談',
    // 勧誘の常套句
    'オンライン面談','無料面談','セミナー','ウェビナー','無料プレゼント','今だけ','限定公開',
    'ご提案させて','新規事業','収益の柱','収益機会','貴社の事業拡大','弊社サービス','弊社では',
    'ご案内です','AI導入','業務効率化のご',
];
$haystack = $company . ' ' . $message;
$sales_hits = 0;
foreach ($sales_keywords as $kw) {
    if (mb_stripos($haystack, $kw) !== false) { $sales_hits++; }
}
// 本文にURLが含まれる（顧客の問い合わせでは稀、営業は誘導URLを貼りがち）
$has_url = (bool)preg_match('#https?://#i', $message);
// フリーメール（gmail等）かつ会社名ありは営業の弱いシグナル
$is_freemail = (bool)preg_match('#@(gmail|yahoo|outlook|hotmail|icloud|aol|gmx|proton)\.#i', $email);

// サンプル請求・見積もり依頼で品目が選ばれている問い合わせは確実に本物→除外
$is_genuine = in_array($type, ['sample','quote'], true) && ($sample_items_str || $quote_info);

$is_sales = false;
if (!$is_genuine) {
    if      ($sales_hits >= 2)                       $is_sales = true; // 営業語が2つ以上
    elseif  ($sales_hits >= 1 && $has_url)           $is_sales = true; // 営業語＋誘導URL
    elseif  ($sales_hits >= 1 && !$js_ok)            $is_sales = true; // 営業語＋ボット疑い
    elseif  (!$js_ok && $has_url && $is_freemail)    $is_sales = true; // ボット＋URL＋フリメ
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

$headers_customer = "From: " . SHOP_NAME . " <" . SHOP_EMAIL . ">";
$headers_shop     = "From: contact-noreply@hayazai.com\r\nReply-To: {$email}";

// 営業判定なら件名に印を付け、Gmailの自動仕分け（フィルタ）で受信トレイから外せるようにする
$shop_subject = ($is_sales ? '【営業の可能性】' : '') . "【お問い合わせ】{$type_label}／{$name}様";
if ($is_sales) {
    $shop_body = "※自動判定：このメールは営業・勧誘の可能性が高いと判定されました（営業語{$sales_hits}件" . ($has_url ? '・誘導URLあり' : '') . "）。\n\n" . $shop_body;
}

// 営業判定の場合は相手（スパマー）への自動返信は送らない。本物の問い合わせにのみ自動返信。
if (!$is_sales) {
    mb_send_mail($email, "[林材木店] お問い合わせを受け付けました", $customer_body, $headers_customer);
}
mb_send_mail(SHOP_EMAIL, $shop_subject, $shop_body, $headers_shop);

header('Location: contact_complete.html');
exit;
