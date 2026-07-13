# 林材木店HP プロジェクト設定

このディレクトリ（`/Users/hayashidaiki/hayazai_website/`）がローカル開発環境。

---

## ⛔ デプロイポリシー（最重要・絶対遵守）

- **デプロイは GitHub Actions のみ。手動デプロイは原則禁止。**
- 編集したら `git add` → `git commit` → `git push origin main` のみ。
- `bash deploy.sh` を直接叩かない（強制ガードでエラー終了する）。
- 自動デプロイの実行状況: <https://github.com/h02050d-ship-it/hayazai-website/actions>
- 詳細: [`docs/DEPLOY_AUTOMATION.md`](docs/DEPLOY_AUTOMATION.md)

**理由:** 別マシン・別ディレクトリの古いローカルファイルで本番を上書きする事故を物理的に防ぐため。Claude 自身も、このプロジェクトの編集後は **push のみで完了**とし、rsync は呼ばないこと。

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

## デプロイ

**GitHub Actions による自動デプロイのみ。**

```bash
git add -A && git commit -m "..." && git push origin main
```

push 後 1〜3 分で `https://hayazai.com/` に反映される。手動デプロイは原則禁止（上の「デプロイポリシー」を参照）。

## 技術構成

- 静的HTML/CSS/JS + PHP
- カート: localStorage
- 決済: 銀行振込（振込先の口座情報はローカルの `.env.local` もしくは別途共有の安全なチャネルを参照）
- 施工事例: `gallery/` フォルダ → gallery.php が自動表示
- 商品データ: `data/products.js`
- 画像生成: GPT（OpenAI gpt-image-1 / ChatGPT）のみ使用、ストックフォト（Unsplash等）禁止

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

---

## AI分担（Claude Code / Codex CLI 併用）

- **設計・レビュー・タスク分解 = Claude Code**
- **実装・テスト実行 = Codex CLI**（ChatGPT Plus サブスク認証。API従量課金は使わない）

### コンテキスト同期について

- **CLAUDE.md が正本**。`AGENTS.md`（Codex 用）はそのコピーで、**コミット時に git pre-commit フックが自動同期**する（`.git/hooks/pre-commit`）。
- AGENTS.md を直接編集しないこと（コミット時に CLAUDE.md の内容で上書きされる）。
- 別マシンで clone した場合はフックが付いてこないので、同じ pre-commit フックを再設置するか、CLAUDE.md 編集後に手動で `cp CLAUDE.md AGENTS.md` すること。
- （経緯: 当初ハードリンク方式だったが、エディタの atomic 書き込みでリンクが切れるためコピー同期に変更）
