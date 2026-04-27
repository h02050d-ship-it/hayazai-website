#!/usr/bin/env node
/**
 * Yahoo!ショッピング 価格自動同期スクリプト
 *
 * 機能:
 *  - Yahoo!ショッピング ItemSearch V3 API で店舗 hayazaimuku の全商品を取得
 *  - data/products.js 内の各SKUの price を最新値に書き換え
 *  - 新規SKUは末尾に // NEW コメント付きで追加
 *  - 削除されたSKUには // REMOVED? コメントを付与（自動削除はしない）
 *
 * 環境変数:
 *  - YAHOO_APP_ID: Yahoo Developer Client ID（必須）
 *
 * オプション:
 *  - --dry-run : 書き込みを行わず差分のみ表示
 *
 * 使用例:
 *  YAHOO_APP_ID=xxxxx node scripts/sync-yahoo-prices.js
 *  YAHOO_APP_ID=xxxxx node scripts/sync-yahoo-prices.js --dry-run
 */

'use strict';

const fs = require('fs');
const path = require('path');
const https = require('https');

// ===== 設定 =====
const SELLER_ID = 'hayazaimuku';
const API_BASE = 'https://shopping.yahooapis.jp/ShoppingWebService/V3/itemSearch';
const PAGE_SIZE = 50;            // Yahoo APIの1リクエスト最大件数
const MAX_PAGES = 40;            // 安全装置（最大2000商品まで）
const REQUEST_INTERVAL_MS = 250; // ページング間の待機（レート制限対策）
const PRODUCTS_FILE = path.join(__dirname, '..', 'data', 'products.js');

// ===== 引数解析 =====
const DRY_RUN = process.argv.includes('--dry-run');

// ===== ヘルパー =====
function log(...args) {
  console.log('[yahoo-sync]', ...args);
}
function err(...args) {
  console.error('[yahoo-sync]', ...args);
}
function sleep(ms) {
  return new Promise(r => setTimeout(r, ms));
}

/**
 * URL に対して GET し、JSON を返す
 */
function fetchJson(url) {
  return new Promise((resolve, reject) => {
    https.get(url, res => {
      let data = '';
      res.on('data', chunk => { data += chunk; });
      res.on('end', () => {
        if (res.statusCode < 200 || res.statusCode >= 300) {
          return reject(new Error(`HTTP ${res.statusCode}: ${data.slice(0, 300)}`));
        }
        try {
          resolve(JSON.parse(data));
        } catch (e) {
          reject(new Error(`JSON parse error: ${e.message}\n${data.slice(0, 300)}`));
        }
      });
    }).on('error', reject);
  });
}

/**
 * Yahoo!ショッピング 全商品取得（ページング）
 * 戻り値: Map<itemCode, { price: number, name: string, url: string }>
 */
async function fetchAllItems(appid) {
  const items = new Map();
  let totalResults = null;

  for (let page = 0; page < MAX_PAGES; page++) {
    const start = page * PAGE_SIZE + 1; // 1始まり
    const url = `${API_BASE}?appid=${encodeURIComponent(appid)}` +
                `&seller_id=${encodeURIComponent(SELLER_ID)}` +
                `&results=${PAGE_SIZE}` +
                `&start=${start}` +
                `&output=json`;

    log(`Fetching page ${page + 1} (start=${start}) ...`);
    const json = await fetchJson(url);

    if (totalResults === null) {
      totalResults = parseInt(json.totalResultsAvailable, 10) || 0;
      log(`Total available: ${totalResults}`);
    }

    const hits = Array.isArray(json.hits) ? json.hits : [];
    if (hits.length === 0) break;

    for (const h of hits) {
      // Yahoo V3 形式: code（商品コード）, price, name, url
      const code = h.code || (h.janCode ? String(h.janCode) : null);
      if (!code) continue;
      const priceRaw = h.price;
      const price = (typeof priceRaw === 'number') ? priceRaw : parseInt(priceRaw, 10);
      if (!Number.isFinite(price)) continue;
      items.set(code, {
        price,
        name: h.name || '',
        url: h.url || '',
      });
    }

    if (start + hits.length - 1 >= totalResults) break;
    await sleep(REQUEST_INTERVAL_MS);
  }

  return items;
}

/**
 * data/products.js から { id, price, lineNumber } のリストを抽出
 * 単純な正規表現ベース。1行1SKUの構造を前提。
 */
function parseProducts(source) {
  const lines = source.split('\n');
  const records = [];
  // id:'xxx' ... price:NUMBER パターン
  const re = /id\s*:\s*['"]([^'"]+)['"][\s\S]*?price\s*:\s*(\d+)/;
  for (let i = 0; i < lines.length; i++) {
    const line = lines[i];
    if (!line.includes('id:') || !line.includes('price:')) continue;
    const m = line.match(re);
    if (!m) continue;
    records.push({
      id: m[1],
      price: parseInt(m[2], 10),
      lineNumber: i,
      raw: line,
    });
  }
  return { lines, records };
}

/**
 * 行内の price:NUMBER を新しい値に置き換える
 */
function replacePriceInLine(line, newPrice) {
  return line.replace(/(price\s*:\s*)\d+/, `$1${newPrice}`);
}

/**
 * REMOVED? コメントが既に付いていれば true
 */
function hasRemovedMark(line) {
  return /\/\/\s*REMOVED\?/.test(line);
}
function appendRemovedMark(line) {
  if (hasRemovedMark(line)) return line;
  // 末尾に追記（行末コメントを保持）
  return line.replace(/\s*$/, ' // REMOVED?');
}

/**
 * 新規SKU用の products.js 行を組み立てる
 * フィールドが揃わないため最低限のスケルトンを生成（人間レビュー想定）
 */
function buildNewProductLine(code, info) {
  const price = info.price;
  const url = info.url || `https://store.shopping.yahoo.co.jp/${SELLER_ID}/${code}.html`;
  const img = `https://item-shopping.c.yimg.jp/i/j/${SELLER_ID}_${code}`;
  const name = (info.name || '').replace(/'/g, "\\'");
  return `  { id:'${code}', cat:'', grade:'', quality:'', thick:0, width:0, length:0, qty:0, price:${price}, yahooUrl:'${url}', img:'${img}', name:'${name}' }, // NEW`;
}

// ===== メイン =====
(async () => {
  const appid = process.env.YAHOO_APP_ID;
  if (!appid) {
    err('ERROR: 環境変数 YAHOO_APP_ID が設定されていません。');
    err('       例: YAHOO_APP_ID=xxxxx node scripts/sync-yahoo-prices.js');
    process.exit(1);
  }
  if (DRY_RUN) log('--- DRY RUN モード（書き込みなし）---');

  // 1. ローカルproducts.js読込
  if (!fs.existsSync(PRODUCTS_FILE)) {
    err(`ERROR: ${PRODUCTS_FILE} が見つかりません。`);
    process.exit(1);
  }
  const source = fs.readFileSync(PRODUCTS_FILE, 'utf8');
  const { lines, records } = parseProducts(source);
  log(`ローカル商品数: ${records.length}`);

  // 2. Yahoo APIから全商品取得
  let yahooItems;
  try {
    yahooItems = await fetchAllItems(appid);
  } catch (e) {
    err('Yahoo API取得失敗:', e.message);
    process.exit(1);
  }
  log(`Yahooで取得した商品数: ${yahooItems.size}`);

  // 3. 価格差分を検出して反映
  const localIds = new Set(records.map(r => r.id));
  const yahooCodes = new Set(yahooItems.keys());

  let updateCount = 0;
  let unchangedCount = 0;
  let removedCount = 0;
  let newCount = 0;
  const diffs = [];

  // 価格更新 + REMOVED? マーク
  for (const rec of records) {
    if (yahooItems.has(rec.id)) {
      const newPrice = yahooItems.get(rec.id).price;
      if (newPrice !== rec.price) {
        diffs.push(`UPDATE ${rec.id}: ${rec.price} -> ${newPrice}`);
        lines[rec.lineNumber] = replacePriceInLine(lines[rec.lineNumber], newPrice);
        updateCount++;
      } else {
        unchangedCount++;
      }
      // REMOVED? が付いていたら復活したので外す（簡易: コメント削除）
      if (hasRemovedMark(lines[rec.lineNumber])) {
        lines[rec.lineNumber] = lines[rec.lineNumber].replace(/\s*\/\/\s*REMOVED\?\s*$/, '');
      }
    } else {
      // Yahooに無い → REMOVED? マーク（既に付いていればスキップ）
      if (!hasRemovedMark(lines[rec.lineNumber])) {
        diffs.push(`REMOVED? ${rec.id} (price=${rec.price})`);
        lines[rec.lineNumber] = appendRemovedMark(lines[rec.lineNumber]);
        removedCount++;
      }
    }
  }

  // 4. 新規SKUを末尾追加
  const newCodes = [...yahooCodes].filter(c => !localIds.has(c));
  if (newCodes.length > 0) {
    // 配列の閉じ `];` を見つけて、その直前に追加
    const closingIndex = lines.findIndex(l => /^\s*\];/.test(l));
    if (closingIndex === -1) {
      err('警告: products.js 配列の終端 `];` が見つかりません。新規SKUは追加しません。');
    } else {
      const insert = [];
      insert.push('');
      insert.push(`  // ===== NEW (${new Date().toISOString().slice(0, 10)}) ※要レビュー =====`);
      for (const code of newCodes) {
        const info = yahooItems.get(code);
        insert.push(buildNewProductLine(code, info));
        diffs.push(`NEW ${code}: price=${info.price} name=${info.name}`);
        newCount++;
      }
      lines.splice(closingIndex, 0, ...insert);
    }
  }

  // 5. 結果出力
  log('--- 集計 ---');
  log(`  価格更新: ${updateCount}`);
  log(`  変更なし: ${unchangedCount}`);
  log(`  REMOVED?: ${removedCount}`);
  log(`  新規NEW : ${newCount}`);
  if (diffs.length > 0) {
    log('--- 差分 ---');
    diffs.forEach(d => log('  ' + d));
  } else {
    log('差分なし');
  }

  if (updateCount === 0 && removedCount === 0 && newCount === 0) {
    log('変更なしのため終了します。');
    return;
  }

  // 6. 書き込み
  if (DRY_RUN) {
    log('--dry-run のため書き込みをスキップしました。');
    return;
  }
  const out = lines.join('\n');
  fs.writeFileSync(PRODUCTS_FILE, out, 'utf8');
  log(`${PRODUCTS_FILE} を更新しました。`);
})().catch(e => {
  err('予期せぬエラー:', e);
  process.exit(1);
});
