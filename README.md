# 林材木店HP（hayazai.com）

静岡県磐田市の木材店「林材木店」の公式サイトリポジトリ。

---

## ⛔ デプロイは GitHub Actions のみ。手動デプロイは原則禁止。

`main` ブランチへ push すれば、GitHub Actions が自動的に Xserver へ rsync 配信します。

- ワークフロー: [`.github/workflows/deploy-xserver.yml`](.github/workflows/deploy-xserver.yml)
- 実行状況: <https://github.com/h02050d-ship-it/hayazai-website/actions>
- 詳細ドキュメント: [`docs/DEPLOY_AUTOMATION.md`](docs/DEPLOY_AUTOMATION.md)

### なぜ手動デプロイを禁止するのか

過去、別マシン・別作業ディレクトリの**古いローカルファイル**で本番サイトを上書きする事故が複数回発生しました。GitHub Actions に統一することで:

1. 常に `main` の最新状態のみがデプロイされる
2. 誰がいつ何をデプロイしたか Actions ログで追跡可能
3. SSH 秘密鍵を各マシンに配布する必要が無い

このため `deploy.sh` には強制ガードが入っており、`--i-know-what-im-doing` フラグを付けない限り即エラー終了します。

---

## 通常運用フロー

```bash
# 1. 編集
vim index.html

# 2. コミット & push
git add -A
git commit -m "fix: 何かを直した"
git push origin main

# 3. Actions が自動デプロイ
# → https://github.com/h02050d-ship-it/hayazai-website/actions
# → 数分後 https://hayazai.com/ に反映
```

## 緊急時の手動デプロイ（自動が壊れた時のみ）

```bash
# 必ず最新化してから
git pull origin main

# 環境変数を source（.env.local 等）
source .env.local

# --dry-run で確認 → 問題なければ本番
bash deploy.sh --i-know-what-im-doing --dry-run
bash deploy.sh --i-know-what-im-doing
```

詳細は [`docs/DEPLOY_AUTOMATION.md`](docs/DEPLOY_AUTOMATION.md) を参照。

---

## ローカルプレビュー

```bash
python3 -m http.server 3456
# → http://localhost:3456
```

## 技術構成

- 静的 HTML/CSS/JS + PHP（Xserver）
- カート: localStorage
- 決済: 銀行振込
- 施工事例: `gallery/` フォルダ → `gallery.php` が自動表示
- 商品データ: `data/products.js`

## ファイル構成

| ファイル | 役割 |
|--------|------|
| `index.html` | トップページ |
| `products.html` | 商品一覧（価格表形式） |
| `cart.html` | カート |
| `order.html` + `order.php` | 注文フォーム・メール送信 |
| `contact.html` + `contact.php` | お問い合わせ・サンプル請求 |
| `gallery.php` | 施工事例（gallery/フォルダ自動読込） |
| `faq.html` | よくある質問 |
| `data/products.js` | 全商品データ |
| `css/style.css` | 共通スタイル |
| `js/cart.js` | カート機能 |
| `js/components.js` | ヘッダー・フッター共通部品 |
| `.github/workflows/deploy-xserver.yml` | 自動デプロイ |
| `deploy.sh` | 緊急時専用・強制ガード付き |
