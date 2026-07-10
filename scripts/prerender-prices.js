#!/usr/bin/env node
/**
 * 価格表プリレンダ（SEO用静的化）スクリプト
 *
 * 目的:
 *  products.html の価格表（#tbl-flooring / #tbl-panel）は実行時に
 *  JavaScript（products.html 内 renderPriceTables）で innerHTML 生成されるため、
 *  静的HTMLには価格数値が一切含まれない＝検索エンジン/JS無効環境に価格が伝わらない。
 *  本スクリプトは data/products.js から同じ計算ロジックで価格行を生成し、
 *  products.html の <tbody> 内へ静的に埋め込む。
 *
 *  ※ 実行時の JS（renderPriceTables）は DOMContentLoaded で同じ tbody を
 *    上書きするため、ブラウザ表示・在庫切れ表示の挙動は一切変わらない。
 *    本プリレンダは純粋にクローラー/初期HTML向けのSEO施策。
 *
 * 使い方:
 *   node scripts/prerender-prices.js          # 書き込み
 *   node scripts/prerender-prices.js --check   # 差分があれば exit 1（CI用・書き込まない）
 *
 * 価格ロジックは products.html 内の hpPriceIncTax / exclTax / perPiece と一致させること。
 */

'use strict';

// 【廃止・実行禁止】2026-07 価格改定でHP掲載はメーカー希望小売価格（products.html内 HP_PRICE_EX）に
// 切り替わったため、Yahoo価格ベースの本スクリプトを実行すると静的価格表が誤った価格で上書きされる。
console.error('【停止】このスクリプトは2026-07の定価化により廃止されました。実行すると静的価格表がYahoo価格で上書きされるため中断します。');
process.exit(1);

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..');
const PRODUCTS_FILE = path.join(ROOT, 'data', 'products.js');
const HTML_FILE = path.join(ROOT, 'products.html');

const CHECK_ONLY = process.argv.includes('--check');

// ===== products.html と一致させる定数・ロジック =====
const LENGTHS = [910, 1820, 3000, 4000];
const COLS = [
  { grade: 'A', quality: '節有' },
  { grade: 'A', quality: '小節' },
  { grade: 'A', quality: '特上小' },
  { grade: 'A', quality: '無節' },
];

// Yahoo税込 → HP税込（×0.95、100円単位四捨五入）
function hpPriceIncTax(yahooIncTax) {
  return Math.round(yahooIncTax * 0.95 / 100) * 100;
}
// 税込 → 税抜（÷1.10、100円単位四捨五入）
function exclTax(incTax) {
  return Math.round(incTax / 1.10 / 100) * 100;
}
// 1枚あたり単価（10円単位四捨五入）
function perPiece(p, qty) {
  return Math.round(p / qty / 10) * 10;
}
// 3桁カンマ区切り（toLocaleStringのロケール差を避け固定実装）
function fmt(n) {
  return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

// ===== products.js から PRODUCTS を安全に読み出す =====
function loadProducts() {
  const code = fs.readFileSync(PRODUCTS_FILE, 'utf8');
  const sandbox = {};
  vm.createContext(sandbox);
  // const PRODUCTS と同一スクリプトスコープで globalThis へ橋渡し
  vm.runInContext(code + '\n;globalThis.__PRODUCTS__ = PRODUCTS;', sandbox, {
    filename: 'products.js',
  });
  const list = sandbox.__PRODUCTS__;
  if (!Array.isArray(list)) {
    throw new Error('products.js から PRODUCTS 配列を取得できませんでした。');
  }
  return list;
}

function lookup(products, cats, grade, quality, length) {
  return products.find(p =>
    cats.includes(p.cat) &&
    p.grade === grade &&
    p.quality === quality &&
    p.length === length
  );
}

// 1テーブル分の <tbody> 中身（行群）を生成。
// 在庫状態は実行時JSが付与するため、ここでは常に在庫あり想定で価格を出す。
function buildRows(products, cats) {
  const rows = [];
  LENGTHS.forEach(len => {
    let row = `        <tr><td class="td-length">${fmt(len)}mm</td>`;
    COLS.forEach(col => {
      const p = lookup(products, cats, col.grade, col.quality, len);
      if (!p) {
        row += '<td class="td-none">—</td>';
      } else {
        const inc = hpPriceIncTax(p.price);
        const ex = exclTax(inc);
        const perInc = perPiece(inc, p.qty);
        const perEx = perPiece(ex, p.qty);
        row += `<td class="td-price">¥${fmt(inc)}<small>（税抜¥${fmt(ex)}）<br>${p.qty}枚入<br>1枚 ¥${fmt(perInc)}（税抜¥${fmt(perEx)}）</small></td>`;
      }
    });
    row += '</tr>';
    rows.push(row);
  });
  return rows.join('\n');
}

function injectTbody(html, tbodyId, rowsHtml) {
  const re = new RegExp(`(<tbody id="${tbodyId}">)[\\s\\S]*?(</tbody>)`);
  if (!re.test(html)) {
    throw new Error(`products.html に <tbody id="${tbodyId}"> が見つかりません。`);
  }
  return html.replace(re, `$1\n${rowsHtml}\n      $2`);
}

// ===== メイン =====
(function main() {
  const products = loadProducts();
  let html = fs.readFileSync(HTML_FILE, 'utf8');
  const before = html;

  const flooringRows = buildRows(products, ['flooring15', 'flooring12']);
  const panelRows = buildRows(products, ['panel']);

  html = injectTbody(html, 'tbl-flooring', flooringRows);
  html = injectTbody(html, 'tbl-panel', panelRows);

  if (html === before) {
    console.log('[prerender-prices] 変更なし（価格表は最新です）');
    return;
  }

  if (CHECK_ONLY) {
    console.error('[prerender-prices] 価格表が古くなっています。`node scripts/prerender-prices.js` を実行してください。');
    process.exit(1);
  }

  fs.writeFileSync(HTML_FILE, html, 'utf8');
  console.log('[prerender-prices] products.html の価格表を更新しました。');
})();
