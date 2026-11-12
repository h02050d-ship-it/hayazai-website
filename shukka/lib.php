<?php
// =====================================================
// 出荷依頼の共通ロジック（cron.php / webhook.php で共有）
// 除外: 取引先が 楽天/ヤフー/ネット で始まる ／ 自社配送(滝川/ひかり/ナイス沼津) ／ 備考に「西濃」 ／ 長さが2m3m4m以外
//       ／ 取引先が未入力 ／ 束数(予定または実際)が未入力 …入力途中を送らない
// =====================================================
function shkSkip($c){ return (bool)preg_match('/^(ヤフ|楽天|ネット)/u', trim((string)$c)); }
// 取引先ごとの固定注記：出荷依頼テキストの当該ブロック最終行に毎回付ける（クライアントのshkCustNoteと一致させる）。
function shkCustNote($c){
    $x = preg_replace('/[\s　()（）]/u', '', (string)$c);
    // （ニッチ「※8月1日以降着希望」は期日到来のため削除・加工予定表側は2026-08-03に削除済み）
    return '';
}
// 個別案件の注記（2026-11-12 本人指示「ナイス長野みたいに個別の案件はその旨もLINEに」）。
// 備考に「個別」を含む品目があれば、その部分（例「長野市場個別」）を拾って「※個別案件（長野市場個別）」を付ける。
// 加工予定表の shkKobetsuNote と同じ規則。
// 着日の条件（2026-11-12 本人指摘「ナイスだけど着日何日以降って指定あるっしょ」）。
// ①⋯メニューの着日指定(chaku=ISO＋chaku_kbn) ②備考の日付つき条件（例「11/24以降の納品希望」「9/19着指定」）を品目の行末に付ける。
// 加工予定表の shkChakuNote と同じ規則。
function shkChakuNote($it){
    $out = array();
    $ck = isset($it['chaku']) ? trim((string)$it['chaku']) : '';
    if (preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $ck, $m)) {
        $kb = isset($it['chaku_kbn']) ? trim((string)$it['chaku_kbn']) : '';
        $out[] = (int)$m[1] . '/' . (int)$m[2] . '着指定' . ($kb !== '' && $kb !== '-' ? '（' . $kb . '）' : '');
    }
    $r = isset($it['remark']) ? (string)$it['remark'] : '';
    foreach (preg_split('/[、。,，・（）()\[\]【】\s　]+/u', $r) as $seg) {
        $seg = trim($seg);
        if ($seg === '' || !preg_match('/\d{1,2}\/\d{1,2}/', $seg)) continue;
        if (!preg_match('/以降|以前|まで|迄|着|納品|希望|指定/u', $seg)) continue;
        if ($out && mb_strpos($seg, '着指定') !== false && mb_strpos($out[0], '着指定') !== false) continue; // 着日指定と同じものは重ねない
        if (!in_array($seg, $out, true)) $out[] = $seg;
    }
    return implode('・', $out);
}
function shkKobetsuNote($arr){
    $seen = array();
    foreach ($arr as $it) {
        $r = isset($it['remark']) ? (string)$it['remark'] : '';
        if (mb_strpos($r, '個別') === false) continue;
        foreach (preg_split('/[、。,，・\/（）()\[\]【】\s　]+/u', $r) as $seg) {
            $seg = trim($seg);
            if ($seg !== '' && mb_strpos($seg, '個別') !== false) $seen[$seg] = true;
        }
    }
    if (!$seen) return '';
    $parts = array_keys($seen);
    $parts = array_values(array_filter($parts, function($p){ return $p !== '個別' && $p !== '個別案件'; }));
    return '※個別案件' . ($parts ? '（' . implode('・', $parts) . '）' : '');
}
// 自社配送が多く内藤運輸へは出さない取引先（クライアントの既定OFFと一致）→ 出荷通知の対象外。
// これらは shk_sent が付かなくても cron リマインドの「未送信」に数えない。
function shkSelfDeliver($c){
    $x = preg_replace('/[\s　()（）]/u', '', (string)$c);
    if (mb_strpos($x, '滝川') !== false) return true;
    if (mb_strpos($x, 'ひかり') !== false) return true;
    if (mb_strpos($x, 'ナイス') !== false && mb_strpos($x, '沼津') !== false) return true;
    return false;
}
// 配送欄（加工予定表のcarrier: -/内藤/西濃/佐川/自社/引取）を空白除去して返す。
function shkCarrierNorm($it){
    $c = isset($it['carrier']) ? (string)$it['carrier'] : '';
    return preg_replace('/[\s　]/u', '', $c);
}
function shkNum($v){ $v = str_replace(',', '', (string)$v); return is_numeric($v) ? (float)$v : null; }
function shkNumStr($n){ return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.'); }
function shkLenM($l){ $n = preg_replace('/[^0-9.]/', '', (string)$l); if ($n === '') return (string)$l; return shkNumStr(round(((float)$n) / 1000, 2)) . 'm'; }
function shkDone($it){ $o = isset($it['order']) ? (int)$it['order'] : 0; return $o >= 9; }
// 束数: 製造完了=実際／製造中=予定（実際は製造完了まで増えていくため確定値でない）
function shkBun($it){
    $a = shkNum(isset($it['actual']) ? $it['actual'] : '');
    $p = shkNum(isset($it['planned']) ? $it['planned'] : '');
    if (shkDone($it)) return $a !== null ? $a : $p;
    return $p !== null ? $p : $a;
}

function shkHttpGet($url){
    $ch = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20));
    $r = curl_exec($ch); curl_close($ch); return $r;
}
// Firebase items 取得（失敗時 null）
function shkFbBase(){ return 'https://kakou-yotei-default-rtdb.firebaseio.com'; }
function shkFetchItems($fb){
    $raw = shkHttpGet(shkFbBase() . '/items.json?auth=' . urlencode($fb));
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}
// Firebase PATCH（cron.php / send.php 共有）
function shkFbPatch($fb, $path, $data){
    $ch = curl_init(shkFbBase() . $path . '.json?auth=' . urlencode($fb));
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    ));
    curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return $code >= 200 && $code < 300;
}
// 指定キー（空なら現在の全候補）に送信済み shk_sent を付ける。戻り=新たにマークした件数
function shkMarkSent($fb, $items, $keys, $ts){
    if (!is_array($keys) || count($keys) === 0) { $keys = array_keys(shkCandidates($items)); }
    $n = 0;
    foreach ($keys as $k) {
        if (!is_string($k) || $k === '') continue;
        if (isset($items[$k]['shk_sent']) && !empty($items[$k]['shk_sent'])) continue; // 既に送信済みは触らない
        if (shkFbPatch($fb, '/items/' . rawurlencode($k), array('shk_sent' => $ts))) $n++;
    }
    return $n;
}
// 「今日もう送ったか」マーカー（手動・自動の二重送信防止用。値は 'Y-m-d'）
function shkGetLastSendDate($fb){
    $raw = shkHttpGet(shkFbBase() . '/shukka_meta/last_send_date.json?auth=' . urlencode($fb));
    $d = json_decode($raw, true);
    return is_string($d) ? $d : '';
}
function shkSetLastSendDate($fb, $date){
    return shkFbPatch($fb, '/shukka_meta', array('last_send_date' => $date));
}
// 通知対象の候補（key=>item, _key付き）
function shkCandidates($items){
    $out = array();
    foreach ($items as $key => $it) {
        if (!is_array($it)) continue;
        if (isset($it['archived']) && $it['archived'] === '1') continue;
        $l = isset($it['length']) ? trim((string)$it['length']) : '';
        if ($l === '' || $l === '-') continue;
        if (shkSkip(isset($it['customer']) ? $it['customer'] : '')) continue;
        if (shkSelfDeliver(isset($it['customer']) ? $it['customer'] : '')) continue; // 自社配送は対象外
        // 配送が「内藤」の品目だけが出荷依頼の対象（2026-08-19ユーザー指示・加工予定表の既定チェックと同条件）。
        // 引取・自社・西濃・佐川・未設定は内藤運輸へ頼まない＝未送信リマインドにも数えない。
        if (shkCarrierNorm($it) !== '内藤') continue;
        if (preg_match('/西濃/u', isset($it['remark']) ? (string)$it['remark'] : '')) continue;
        $ln = (int)preg_replace('/[^0-9]/', '', (string)$it['length']);
        if (!in_array($ln, array(2000, 3000, 4000), true)) continue;
        // 入力途中ガード: 取引先が空 or 束数(予定/実際)が空なら送らない
        if (trim((string)(isset($it['customer']) ? $it['customer'] : '')) === '') continue;
        if (shkBun($it) === null) continue;
        $it['_key'] = $key;
        $out[$key] = $it;
    }
    return $out;
}
// 出荷依頼テキスト生成（取引先ごと・日付（曜）見出し）
function shkBuildMsg($title, $list, $markNew){
    $g = array();
    foreach ($list as $it) {
        $c = trim((string)(isset($it['customer']) ? $it['customer'] : ''));
        if ($c === '') $c = '(取引先なし)';
        $g[$c][] = $it;
    }
    uksort($g, 'strcmp');
    $done = 0; $und = 0; $blocks = array();
    foreach ($g as $c => $arr) {
        usort($arr, function($a, $b){
            $da = shkDone($a) ? 1 : 0; $db = shkDone($b) ? 1 : 0;
            if ($da !== $db) return $db - $da;
            $la = shkNum($a['length']); $lb = shkNum($b['length']);
            return $la <=> $lb;
        });
        $lines = array();
        foreach ($arr as $it) {
            if (shkDone($it)) $done++; else $und++;
            $bun = shkBun($it);
            $nw  = ($markNew && empty($it['shk_sent'])) ? '🆕 ' : '';
            $bunStr = $bun !== null ? (shkNumStr($bun) . '束') : '?束';
            $ck = shkChakuNote($it);
            $lines[] = $nw . shkLenM($it['length']) . ' ' . $bunStr . '（' . (shkDone($it) ? '完了' : '未完了') . '）' . ($ck !== '' ? ' ※' . $ck : '');
        }
        $kb = shkKobetsuNote($arr); if ($kb !== '') $lines[] = $kb;  // 個別案件（ナイス長野 等）
        $note = shkCustNote($c); if ($note !== '') $lines[] = $note; // 取引先ごとの固定注記
        $blocks[] = '■ ' . $c . "\n" . implode("\n", $lines);
    }
    $wd = array('日','月','火','水','木','金','土');
    $head = date('Y/n/j') . '（' . $wd[(int)date('w')] . '）';
    $txt = ($title !== '' ? $title . ' ' : '') . $head . "\n\n" . implode("\n\n", $blocks);
    $txt .= "\n\n計" . count($list) . "件（完了" . $done . "／未完了" . $und . "）";
    $txt .= "\n\n※「現状」と送ると、最新の情報をお返しします。";
    return $txt;
}

// =====================================================
// 出荷LINE（友だち全員＝内藤運輸）へ broadcast。戻り=[HTTPコード, 応答本文, curlエラー]
// send.php（手動）と auto_cron.php（10分後自動）で共有。
// =====================================================
function shkLineBroadcast($token, $text){
    if (mb_strlen($text) > 4900) $text = mb_substr($text, 0, 4900);
    $payload = json_encode(array('messages' => array(array('type' => 'text', 'text' => $text))), JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.line.me/v2/bot/message/broadcast');
    curl_setopt_array($ch, array(
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Authorization: Bearer ' . $token),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    ));
    $resp = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
    @mkdir(__DIR__ . '/state', 0775, true);
    @file_put_contents(__DIR__ . '/state/send.log', date('c') . " code=$code text=" . str_replace("\n", '/', $text) . "\n", FILE_APPEND);
    return array($code, $resp, $cerr);
}

// =====================================================
// 社内通知LINE（@811hkagd／大樹）へ出荷依頼の控えを転送する。
// 既存GAS中継（notify_settings ゲート内蔵）へ POST。best-effort：
// 失敗しても本体には一切影響させない。GASは302で応答を返す仕様のため CURLOPT_FOLLOWLOCATION 必須。
// =====================================================
function shkNotifyNaisya_($text, $relayKey, $by = '出荷依頼送信') {
    // 中継キーは公開リポジトリに置かない（config.php = GitHub Actions Secrets 生成）。未設定なら控え転送だけスキップ。
    if ($relayKey === '') return 0;
    $url  = 'https://script.google.com/macros/s/AKfycbxcvQZVi497obS-nRm4MdN0tYtsaTb03n7FLFWy7oZN2vkItKm7oQO9_85WdJYxGjgaiA/exec';
    $body = json_encode(array(
        'key' => $relayKey, 'target' => 'daiki', 'mode' => 'notify', 'ntype' => 'shukka', 'by' => $by,
        'text' => "📦 出荷依頼を送信しました\n\n" . $text,
    ), JSON_UNESCAPED_UNICODE);
    $ncode = 0;
    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 10,
        ));
        curl_exec($ch); $ncode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    } catch (\Throwable $e) { $ncode = 0; }
    @file_put_contents(__DIR__ . '/state/send.log', date('c') . " naisya=$ncode\n", FILE_APPEND);
    return $ncode;
}

// 自動送信キュー（auto_cron.php）に載っていて、まだ送っていない品目のキー。
// cron.php / mail_cron.php の「未送信」リマインドはこの間は数えない（10分以内に自動で送るため）。
function shkAutoWaitingKeys(){
    $f = __DIR__ . '/state/auto_queue.json';
    $q = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    $out = array();
    if (is_array($q) && isset($q['drafts']) && is_array($q['drafts'])) {
        foreach ($q['drafts'] as $d) { if (in_array($d['status'] ?? '', array('waiting', 'hold'), true)) foreach (($d['keys'] ?? array()) as $k) $out[$k] = true; }
    }
    return $out;
}
