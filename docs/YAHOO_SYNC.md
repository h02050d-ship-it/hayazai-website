# Yahoo!ショッピング 価格自動同期 セットアップガイド

`data/products.js` の価格を、Yahoo!ショッピング店舗 [hayazaimuku](https://store.shopping.yahoo.co.jp/hayazaimuku/) の最新価格と毎日自動同期する仕組みです。

## 全体像

```
[Yahoo Shopping API]
        │  (ItemSearch V3, seller_id=hayazaimuku)
        ▼
[GitHub Actions: 毎日 04:00 JST]
        │  scripts/sync-yahoo-prices.js
        ▼
[data/products.js を更新 → Pull Request 作成]
        │
        ▼
[人間レビュー & マージ] → ローカルで ./deploy.sh → Xサーバー反映
```

将来 Cloudflare Pages へ移行後は、main へのマージで自動デプロイになる想定です。

---

## 1. Yahoo Developer App ID の取得（5分・無料）

1. https://e.developer.yahoo.co.jp/dashboard/ にログイン（Yahoo! JAPAN ID）
2. 「新しいアプリケーションを開発」をクリック
3. アプリケーション種別: **サーバーサイド（クライアントID）** を選択
4. 必要事項を入力:
   - 利用者情報: 個人 / 事業者（事業者推奨）
   - アプリケーション名: 例) `hayazai-price-sync`
   - 利用するサービス: **ショッピング** にチェック
   - サイトURL: `https://hayazai.com/`
   - ガイドライン同意 にチェック
5. 「確認」→「登録」
6. 発行された **Client ID（アプリケーションID）** を控える（これが `YAHOO_APP_ID` になります）

公式ドキュメント: <https://developer.yahoo.co.jp/webapi/shopping/itemsearch.html>

レート制限: 1日5万リクエスト（本スクリプトは1日数回・各回数十件で十分余裕）

---

## 2. GitHub Secrets への登録

1. リポジトリページを開く: <https://github.com/h02050d-ship-it/hayazai-website>
2. **Settings** タブ → 左サイドバー **Secrets and variables** → **Actions**
3. **New repository secret** ボタンをクリック
4. 以下を入力:
   - Name: `YAHOO_APP_ID`
   - Secret: 上で取得した Client ID
5. **Add secret** で保存

---

## 3. 動作確認（手動実行）

### 3-A. GitHub上から手動実行

1. リポジトリの **Actions** タブを開く
2. 左メニュー **Yahoo Price Sync** を選択
3. 右上 **Run workflow** をクリック
4. Dry run のみ試したい場合は `dry_run=true` にしてから Run
5. 実行ログでAPI取得件数・差分・更新件数が確認できます
6. 差分があれば自動でPRが作成されます（dry_run=false 時）

### 3-B. ローカルで手動実行（macOS / Windows）

```bash
# macOS / Linux / Git Bash
export YAHOO_APP_ID=取得したClientID
node scripts/sync-yahoo-prices.js --dry-run    # まずdry-runで確認
node scripts/sync-yahoo-prices.js              # 実際に書き換え
git diff data/products.js                      # 差分確認
git add data/products.js && git commit -m "chore: sync yahoo prices"
git push origin main
./deploy.sh                                    # Xサーバーへデプロイ
```

PowerShell の場合:

```powershell
$env:YAHOO_APP_ID="取得したClientID"
node scripts/sync-yahoo-prices.js --dry-run
node scripts/sync-yahoo-prices.js
```

---

## 4. スクリプトの動作仕様

| 状況 | 動作 |
|------|------|
| Yahoo側に同じ `id`(=商品コード) があり価格が異なる | `price` を最新値に書き換え |
| Yahoo側に同じ `id` があり価格が同じ | 何もしない |
| Yahoo側に存在せずローカルに存在する商品 | 行末に `// REMOVED?` コメント追加（自動削除はしない） |
| Yahoo側に存在しローカルに無い新規商品 | 配列末尾に `// NEW` コメント付きで追加（要手動補完） |

**注意:** 新規SKUは `cat / grade / quality / thick / width / length / qty` フィールドが空のため、人間が後から補完してください。

---

## 5. スケジュール

- 自動実行: 毎日 04:00 JST（GitHub Actions cron `0 19 * * *` UTC）
- 手動実行: いつでも可（GitHub Actions の Run workflow）

---

## 6. トラブルシュート

| 症状 | 対処 |
|------|------|
| ワークフロー失敗「YAHOO_APP_ID が未設定」 | Secrets に `YAHOO_APP_ID` を登録（手順2） |
| HTTP 403 / Forbidden | App ID 無効。Yahoo Developer ダッシュボードで確認、ショッピングAPI利用許可になっているか確認 |
| `Total available: 0` | `seller_id=hayazaimuku` で商品が見つからない。店舗ID変更時はスクリプトの `SELLER_ID` を更新 |
| PRが作成されない | `data/products.js` に差分が無い（=価格変更が無かった）。正常 |
| `// NEW` が大量に出る | スクリプトのID抽出ロジックとYahoo側の `code` が食い違っている可能性。dry-run でログ確認 |
| 価格表示がずれた | HPは `price * 0.95` で表示（税抜→税込換算）。スクリプトはYahoo APIの `price`（税抜）をそのまま保存します |

---

## 7. ファイル一覧

| パス | 役割 |
|------|------|
| `scripts/sync-yahoo-prices.js` | 同期ロジック本体（Node.js） |
| `scripts/sync-yahoo-prices.sh` | ローカル実行ラッパー |
| `.github/workflows/yahoo-sync.yml` | GitHub Actions 定義 |
| `docs/YAHOO_SYNC.md` | 本書 |

---

## 8. 残タスク（ユーザー側）

1. ☐ Yahoo Developer で App ID を取得（手順1）
2. ☐ GitHub Secrets に `YAHOO_APP_ID` を登録（手順2）
3. ☐ Actions タブから `Yahoo Price Sync` を手動実行して動作確認（手順3-A）
4. ☐ 自動作成されたPRをレビュー・マージ
5. ☐ 必要に応じてローカルで `./deploy.sh` 実行（Xサーバー反映）
