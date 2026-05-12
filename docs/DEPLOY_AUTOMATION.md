# 林材木店HP デプロイ自動化ガイド

## 運用ポリシー（重要）

- **main ブランチへ push したら、GitHub Actions が自動で Xserver へデプロイします。**
- **手動デプロイは原則禁止**です（古いファイルでの上書き事故を防ぐため）。
- 緊急時のみ `deploy.sh` を使ってよいですが、**実行前に必ず `git pull origin main` を実行**してください。

## 仕組み

| 項目 | 内容 |
|------|------|
| ワークフロー | `.github/workflows/deploy-xserver.yml` |
| トリガー | `push` to `main` / `workflow_dispatch`（手動実行） |
| 転送方法 | `rsync -avz --delete` over SSH |
| デプロイ先 | `xs095198@sv13339.xserver.jp:~/hayazai.com/public_html/` |
| ポート | `10022` |

## デプロイされないファイル（rsync exclude）

サイト本体に不要なファイルは公開ディレクトリに送りません:

- `.git/`, `.github/`, `.claude/` … バージョン管理・CIメタデータ
- `node_modules/`, `scripts/`, `docs/`, `functions/` … 開発用
- `CLAUDE.md`, `README.md`, `CHANGES.md` … ドキュメント
- `*.bak`, `*.tmp`, `*.draft`, `data/products.js.NEW*.draft`
- `.env`, `.env.*` … 機密情報（誤ってもデプロイされない安全策）
- `deploy.sh`, `.gitignore`, `.DS_Store`, `Thumbs.db`

`--delete` オプションにより、Xserver 側にあって repo 側に無いファイルは削除されます（古い遺物の蓄積を防ぐ）。

## GitHub Secrets 登録手順（初回のみ・手作業必須）

GitHub Actions secrets はリポジトリ管理者しか登録できないため、以下はユーザー側で手作業で行ってください。

### 1. リポジトリの Secrets 画面を開く

ブラウザで以下にアクセス:

```
https://github.com/h02050d-ship-it/hayazai-website/settings/secrets/actions
```

または GitHub 上で Repo → **Settings** → **Secrets and variables** → **Actions**。

### 2. 「New repository secret」を押して以下の 5 件を登録

| Name | Value |
|------|-------|
| `XSERVER_HOST` | `sv13339.xserver.jp` |
| `XSERVER_USER` | `xs095198` |
| `XSERVER_PORT` | `10022` |
| `XSERVER_PATH` | `~/hayazai.com/public_html/` |
| `XSERVER_SSH_KEY` | `~/.ssh/xserver_hayazai` の **秘密鍵全文（PEM形式）** |

### 3. `XSERVER_SSH_KEY` の値を取得して貼り付ける手順

Windows PowerShell で以下を実行:

```powershell
Get-Content $env:USERPROFILE\.ssh\xserver_hayazai | Set-Clipboard
```

または Git Bash で:

```bash
cat ~/.ssh/xserver_hayazai | clip
```

これで秘密鍵全文がクリップボードにコピーされます。GitHub の「New repository secret」画面で:

- **Name**: `XSERVER_SSH_KEY`
- **Secret**: クリップボードの内容を貼り付け（`-----BEGIN OPENSSH PRIVATE KEY-----` から `-----END OPENSSH PRIVATE KEY-----` まで全行含めること）

**注意点**:

- 末尾改行を必ず1行残してください。
- BEGIN / END 行を欠かさないこと。
- 余計なインデント・先頭スペースを付けない。

## 動作確認手順

### 自動デプロイの確認

1. 何か 1 行修正して `git push origin main`。
2. ブラウザで以下を開いて緑のチェックを確認:
   ```
   https://github.com/h02050d-ship-it/hayazai-website/actions
   ```
3. 数分後 `https://hayazai.com/` をリロードして反映を確認。

### 手動実行（workflow_dispatch）

1. https://github.com/h02050d-ship-it/hayazai-website/actions/workflows/deploy-xserver.yml を開く
2. 右上の **「Run workflow」** を押す
3. Branch: `main` を選び **Run workflow** をクリック
4. 数分後にジョブが完了。緑なら成功。

### コマンドラインからの確認

```bash
curl -sI https://hayazai.com/ | head -1
# 期待: HTTP/2 200
```

## フォールバック手順（自動デプロイが壊れた時）

### 1. ワークフローの失敗ログを見る

```
https://github.com/h02050d-ship-it/hayazai-website/actions
```

失敗したジョブをクリック → 各 step を展開してエラー内容を確認。

### 2. よくある原因と対処

| 症状 | 原因 | 対処 |
|------|------|------|
| `Permission denied (publickey)` | `XSERVER_SSH_KEY` が壊れている／公開鍵が Xserver 側に登録されていない | Secrets を再登録、Xserver の `~/.ssh/authorized_keys` を確認 |
| `Host key verification failed` | `ssh-keyscan` 失敗 | Xserver の SSH 設定（ポート/ホスト名）を再確認、Secrets `XSERVER_HOST` `XSERVER_PORT` を確認 |
| `rsync: connection unexpectedly closed` | Xserver メンテ中 / ネットワーク一時障害 | しばらく待って再実行（workflow_dispatch） |
| `403 Forbidden` がトップで返る | `.htaccess` または `index.html` がデプロイされていない | exclude パターンを確認 |

### 3. 緊急時のみ：手動デプロイ

`deploy.sh` を使う場合は **必ず最新の main を pull してから**:

```bash
cd /c/Users/hayaz/AppData/Local/Temp/hayazai_fix/hayazai-website
git pull origin main
bash deploy.sh
```

手動デプロイ後は **Actions の最新の自動デプロイ結果と整合しているか確認**してください。古い手元ファイルで自動デプロイ後の状態を上書きしないこと。

## セキュリティ

- 秘密鍵は GitHub Secrets に暗号化保存され、ログには `***` で表示されます。
- ワークフロー YAML には秘密情報は一切ハードコードしていません（`${{ secrets.* }}` 参照のみ）。
- ジョブ終了時に `~/.ssh/id_rsa` を削除しています（`Cleanup SSH key` ステップ）。

## 関連ファイル

- `.github/workflows/deploy-xserver.yml` … ワークフロー本体
- `deploy.sh` … 緊急時用の手動デプロイスクリプト
- `docs/DEPLOY_AUTOMATION.md` … 本ドキュメント
