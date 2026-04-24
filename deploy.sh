#!/bin/bash
# 林材木店HP 自動デプロイスクリプト
# Claude PostToolUse hookから呼ばれる（stdin にツール入力JSON）
#
# 必要な環境変数（ローカルの .env.local で管理し、source しておくこと）:
#   SSH_HOST     Xserver のホスト名
#   SSH_USER     Xserver のユーザー名
#   SSH_PORT     SSHポート（既定: 10022）
#   SSH_KEY      秘密鍵パス（既定: ~/.ssh/xserver_hayazai）
#   LOCAL_DIR    ローカル公開元ディレクトリ
#   REMOTE_DIR   リモート公開先ディレクトリ

INPUT=$(cat)
if echo "$INPUT" | grep -q "hayazai_website"; then
  : "${SSH_HOST:?SSH_HOST が未設定です}"
  : "${SSH_USER:?SSH_USER が未設定です}"
  : "${SSH_PORT:=10022}"
  : "${SSH_KEY:=$HOME/.ssh/xserver_hayazai}"
  : "${LOCAL_DIR:=/Users/hayashidaiki/hayazai_website/}"
  : "${REMOTE_DIR:=~/hayazai.com/public_html/}"

  rsync -az --delete \
    --exclude '.DS_Store' \
    --exclude '.git/' \
    --exclude '*.md' \
    --exclude 'CLAUDE.md' \
    --exclude 'deploy.sh' \
    --exclude '.env*' \
    -e "ssh -i ${SSH_KEY} -p ${SSH_PORT} -o StrictHostKeyChecking=no" \
    "${LOCAL_DIR}" \
    "${SSH_USER}@${SSH_HOST}:${REMOTE_DIR}" 2>&1
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] hayazai.com アップロード完了" >> /tmp/hayazai_deploy.log
fi
