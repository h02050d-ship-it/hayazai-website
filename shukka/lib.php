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
    if (mb_strpos($x, 'ニッチ') !== false) return '※8月1日以降着希望';
    return '';
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
            $lines[] = $nw . shkLenM($it['length']) . ' ' . $bunStr . '（' . (shkDone($it) ? '完了' : '未完了') . '）';
        }
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
