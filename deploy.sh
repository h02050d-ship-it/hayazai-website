#!/bin/bash
# =============================================================================
#  林材木店HP 自動デプロイスクリプト（Claude PostToolUse hook 用 / 緊急時手動用）
# =============================================================================
#
#  ⚠️  通常運用は GitHub Actions による自動デプロイです。
#  ⚠️  main へ push すれば自動的に Xserver へ配信されます。
#  ⚠️  詳細: docs/DEPLOY_AUTOMATION.md
#
#  本スクリプトは:
#    1. Claude PostToolUse hook から stdin 経由で呼ばれる用途
#    2. Actions が壊れている等の緊急時の手動デプロイ用途
#  のために残しています。
#
#  ⚠️  手動で叩く前には必ず `git pull origin main` を実行してください。
#  ⚠️  古い手元ファイルでサーバ側を上書きする事故を防ぐためです。
#
# =============================================================================
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
    --exclude '.github/' \
    --exclude '*.md' \
    --exclude 'CLAUDE.md' \
    --exclude 'deploy.sh' \
    --exclude '.env*' \
    -e "ssh -i ${SSH_KEY} -p ${SSH_PORT} -o StrictHostKeyChecking=no" \
    "${LOCAL_DIR}" \
    "${SSH_USER}@${SSH_HOST}:${REMOTE_DIR}" 2>&1
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] hayazai.com アップロード完了" >> /tmp/hayazai_deploy.log
fi
