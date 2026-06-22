<?php
// =====================================================
// 出荷依頼の共通ロジック（cron.php / webhook.php で共有）
// 除外: 取引先が 楽天/ヤフー/ネット で始まる ／ 備考に「西濃」 ／ 長さが2m3m4m以外
// =====================================================
function shkSkip($c){ return (bool)preg_match('/^(ヤフ|楽天|ネット)/u', trim((string)$c)); }
function shkNum($v){ $v = str_replace(',', '', (string)$v); return is_numeric($v) ? (float)$v : null; }
function shkNumStr($n){ return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.'); }
function shkLenM($l){ $n = preg_replace('/[^0-9.]/', '', (string)$l); if ($n === '') return (string)$l; return shkNumStr(round(((float)$n) / 1000, 2)) . 'm'; }
function shkDone($it){ $o = isset($it['order']) ? (int)$it['order'] : 0; return $o >= 9; }
function shkBun($it){ $a = shkNum(isset($it['actual']) ? $it['actual'] : ''); return $a !== null ? $a : shkNum(isset($it['planned']) ? $it['planned'] : ''); }

function shkHttpGet($url){
    $ch = curl_init($url);
    curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20));
    $r = curl_exec($ch); curl_close($ch); return $r;
}
// Firebase items 取得（失敗時 null）
function shkFetchItems($fb){
    $raw = shkHttpGet('https://kakou-yotei-default-rtdb.firebaseio.com/items.json?auth=' . urlencode($fb));
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
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
        if (preg_match('/西濃/u', isset($it['remark']) ? (string)$it['remark'] : '')) continue;
        $ln = (int)preg_replace('/[^0-9]/', '', (string)$it['length']);
        if (!in_array($ln, array(2000, 3000, 4000), true)) continue;
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
        $blocks[] = '■ ' . $c . "\n" . implode("\n", $lines);
    }
    $wd = array('日','月','火','水','木','金','土');
    $head = date('Y/n/j') . '（' . $wd[(int)date('w')] . '）';
    $txt = ($title !== '' ? $title . ' ' : '') . $head . "\n\n" . implode("\n\n", $blocks);
    $txt .= "\n\n計" . count($list) . "件（完了" . $done . "／未完了" . $und . "）";
    return $txt;
}
