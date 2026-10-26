<?php
// =====================================================
// 営業・勧誘メールの判定（contact.php から読み込む）
// =====================================================
// ブロックはせず、件名に【営業の可能性】を付けてGmailのフィルタで受信トレイから外す。
// 本物のお客様を取りこぼさないことを最優先にし、迷うものは「営業ではない」に倒す。
//
// 2026-10-26 強化：AIで本文を自動生成する営業（「突然のご連絡失礼いたします」
// 「〜と申します」「今後のご連絡が不要な場合」など）が営業語リストに引っかからず
// 8〜10月に6件すり抜けたため、「営業でしか使わない言い回し」の判定を追加。

if (!function_exists('hayazai_sales_check')) {

function hayazai_count_hits($haystack, array $words) {
    $hits = [];
    foreach ($words as $w) {
        if (mb_stripos($haystack, $w) !== false) { $hits[] = $w; }
    }
    return $hits;
}

/**
 * @param array $f  name, company, email, type, message, js_ok, is_genuine
 * @return array    is_sales(bool), reason(string)
 */
function hayazai_sales_check(array $f) {
    $company = $f['company'] ?? '';
    $message = $f['message'] ?? '';
    $email   = $f['email'] ?? '';
    $type    = $f['type'] ?? '';
    $js_ok   = !empty($f['js_ok']);

    // フォームで「営業・サービスのご案内」を選んだ人は本人申告どおり営業扱い
    if ($type === 'sales') {
        return ['is_sales' => true, 'reason' => '種別「営業・サービスのご案内」を選択'];
    }
    // サンプル請求・見積もりで品目が選ばれている本物は判定しない
    if (!empty($f['is_genuine'])) {
        return ['is_sales' => false, 'reason' => ''];
    }

    $haystack = $company . ' ' . $message;

    // ① 営業語（売り込む商材の名前）
    $sales_keywords = [
        // Web集客・制作系
        'SEO','上位表示','検索順位','アクセスアップ','集客','広告運用','リスティング',
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

    // ② 営業でしか使わない言い回し（お客様の問い合わせにはまず出てこない）
    $pitch_strong = [
        // 配信停止の案内
        '不要でしたら','不要な場合','今後のご連絡','今後の送信','配信停止','送信をお断り','お断りの場合',
        '再送はしません','1回限り','一度限り','optout','オプトアウト',
        // 料金・キャンペーン
        '月額','初期費用','無料プラン','無料診断','活用診断','無料で始め','乗り換え','先着','限定のご案内',
        '貴社限定','貴社特典','プレゼントいたします','無償で提供','無償でご提供',
        // 売り込みの中身
        '導入事例','導入実績','導入企業','運用代行','紹介メディア','営業リスト','新規開拓','初回アプローチ',
        'アップセル','追加受注','売上アップ','売上向上',
        // 日程調整ツールへの誘導
        '日程はこちら','日程調整はこちら','timerex','calendly','jicoo','spirinc',
        // 自社サービスの紹介
        'を運営しております','を運営する','サービスを提供','ご案内したく','ご紹介したく','ご提案したく',
        'ご提案の機会','営業責任者','代表者様','代表者さま','経営者様','経営者さま',
    ];

    // ③ 初めての相手に送る挨拶（お客様も書くことがあるので単独では営業扱いしない）
    $pitch_weak = [
        '突然の','はじめてご連絡','初めてご連絡','初めてメール','と申します',
        'ご担当者様','ご担当者さま','ご担当者各位','貴社','御社','弊社',
        'ご提案','ご案内','ご紹介','サービス','システム','ツール','拝見し','拝読し',
    ];

    // ④ 買う人・使う人の言葉（これが多いほどお客様の可能性が高い）
    $customer_words = [
        '見積','サンプル','購入','注文','在庫','納期','送料','施工','坪','㎡','平米','m2','束',
        '価格','値段','単価','厚み','mm','ミリ','張り','貼り','床','壁','天井','新築','リフォーム',
        'DIY','無節','上小','小節','節有','何枚','何束',
    ];

    $kw     = hayazai_count_hits($haystack, $sales_keywords);
    $strong = hayazai_count_hits($haystack, $pitch_strong);
    $weak   = hayazai_count_hits($haystack, $pitch_weak);
    $cust   = hayazai_count_hits($message, $customer_words);

    // 本文にURLが含まれる（顧客の問い合わせでは稀、営業は誘導URLを貼りがち）
    $has_url = (bool)preg_match('#https?://#i', $message);
    // フリーメール（gmail等）
    $is_freemail = (bool)preg_match('#@(gmail|yahoo|outlook|hotmail|icloud|aol|gmx|proton)\.#i', $email);

    $nk = count($kw); $ns = count($strong); $nw = count($weak); $nc = count($cust);
    // お客様の言葉が3つ以上ある＝商品について具体的に書いている。弱い根拠だけでは営業にしない
    $looks_customer = ($nc >= 3);

    $why = null;
    if      ($nk >= 2)                                   $why = '営業語2件以上';
    elseif  ($nk >= 1 && $has_url)                       $why = '営業語＋誘導URL';
    elseif  ($nk >= 1 && !$js_ok)                        $why = '営業語＋ボット疑い';
    elseif  (!$js_ok && $has_url && $is_freemail)        $why = 'ボット＋URL＋フリーメール';
    elseif  ($ns >= 2)                                   $why = '営業の言い回し2件以上';
    elseif  ($ns >= 1 && ($nw >= 2 || $has_url) && !$looks_customer) $why = '営業の言い回し＋挨拶/URL';
    elseif  ($nw >= 3 && $has_url && $nc === 0)          $why = '初対面の挨拶＋誘導URL';
    elseif  ($nw >= 4 && $nc === 0)                      $why = '初対面の挨拶のみで商品の話がない';

    if ($why === null) return ['is_sales' => false, 'reason' => ''];

    $found = array_slice(array_merge($kw, $strong, $weak), 0, 8);
    $reason = $why . '（' . implode('・', $found) . ($has_url ? '・URLあり' : '') . '）';
    return ['is_sales' => true, 'reason' => $reason];
}

}
