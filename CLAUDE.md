# 林材木店HP プロジェクト設定

このディレクトリ（`/Users/hayashidaiki/hayazai_website/`）がローカル開発環境。

---

## プロジェクト概要

- **サイト名:** 林材木店（ハヤシザイモクテン）
- **URL:** https://hayazai.com/
- **サーバー:** Xserver
- **公開フォルダ:** `~/hayazai.com/public_html/`
- **FTP接続情報:** `~/.claude/projects/-Users-hayashidaiki-receipt-downloader/memory/project_hayazai_deploy.md` 参照

## ローカルプレビュー

```bash
python3 -m http.server 3456
# → http://localhost:3456
```

## デプロイ（FTP情報取得後）

`~/.claude/projects/-Users-hayashidaiki-receipt-downloader/memory/project_hayazai_deploy.md` のコマンドを使用

## 技術構成

- 静的HTML/CSS/JS + PHP
- カート: localStorage
- 決済: 銀行振込（遠州信用金庫 普通 ***REDACTED*** ***REDACTED***）
- 施工事例: `gallery/` フォルダ → gallery.php が自動表示
- 商品データ: `data/products.js`
- 画像生成: ナノバナナ（Google Gemini）のみ使用、Unsplash禁止

## ファイル構成

| ファイル | 役割 |
|--------|------|
| `index.html` | トップページ |
| `products.html` | 商品一覧（価格表形式） |
| `product.html` | 商品詳細 |
| `cart.html` | カート |
| `order.html` + `order.php` | 注文フォーム・メール送信 |
| `contact.html` + `contact.php` | お問い合わせ・サンプル請求 |
| `gallery.php` | 施工事例（gallery/フォルダ自動読込） |
| `faq.html` | よくある質問 |
| `data/products.js` | 全商品データ |
| `css/style.css` | 共通スタイル |
| `js/cart.js` | カート機能 |
| `js/components.js` | ヘッダー・フッター共通部品 |
