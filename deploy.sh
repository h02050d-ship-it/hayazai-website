#!/bin/bash
# =============================================================================
#  林材木店HP 緊急時専用 手動デプロイスクリプト
# =============================================================================
#
#  ⛔ 通常運用は GitHub Actions による自動デプロイです。
#  ⛔ main へ push すれば自動的に Xserver へ配信されます。
#  ⛔ このスクリプトを直接叩く必要は **ありません**。
#
#  詳細: docs/DEPLOY_AUTOMATION.md
#  Actions: https://github.com/h02050d-ship-it/hayazai-website/actions
#
# =============================================================================
set -euo pipefail

# -----------------------------------------------------------------------------
# 0. 安全ガード: 明示フラグなしで叩かれたら即エラー終了
# -----------------------------------------------------------------------------
if [[ "${1:-}" != "--i-know-what-im-doing" ]]; then
  cat <<'EOF'
=====================================================
⛔ 手動デプロイは原則禁止です
=====================================================
通常運用は GitHub Actions による自動デプロイです:
  https://github.com/h02050d-ship-it/hayazai-website/actions

main へ push すれば自動的に Xserver に配信されます。
手動デプロイは古いローカルファイルでサーバを上書きする
事故の最大要因です。

緊急時のみ、リスクを理解した上で実行する場合:
  bash deploy.sh --i-know-what-im-doing

実行前に必ず以下を確認してください:
  1. git pull origin main で最新化済みであること
  2. ローカルに未コミットの変更が無いこと
  3. GitHub Actions が壊れているか、Secrets が未登録で
     一時的に自動デプロイが使えない状況であること
=====================================================
EOF
  exit 1
fi

# -----------------------------------------------------------------------------
# 1. リポジトリの最新化を強制（古いローカルでの上書き事故防止）
# -----------------------------------------------------------------------------
echo "[guard] git fetch & pull origin main を実行します..."
if ! git fetch origin main; then
  echo "❌ git fetch origin main に失敗しました。ネットワーク・認証を確認してください。"
  exit 1
fi
if ! git pull --ff-only origin main; then
  echo "❌ git pull --ff-only origin main に失敗しました。"
  echo "   ローカルが main から分岐している可能性があります。先に rebase/merge してください。"
  exit 1
fi

# -----------------------------------------------------------------------------
# 2. ローカルに未コミットの変更があれば拒否
# -----------------------------------------------------------------------------
if ! git diff --quiet HEAD --; then
  echo "❌ 未コミットの変更が検出されました。"
  echo "   先に commit & push して GitHub Actions 経由でデプロイしてください。"
  git status --short
  exit 1
fi
if ! git diff --cached --quiet; then
  echo "❌ ステージ済みで未コミットの変更があります。"
  git status --short
  exit 1
fi

# -----------------------------------------------------------------------------
# 3. ローカルが origin/main と一致しているか確認
# -----------------------------------------------------------------------------
LOCAL_SHA=$(git rev-parse HEAD)
REMOTE_SHA=$(git rev-parse origin/main)
if [[ "$LOCAL_SHA" != "$REMOTE_SHA" ]]; then
  echo "❌ ローカル HEAD ($LOCAL_SHA) が origin/main ($REMOTE_SHA) と一致しません。"
  echo "   先に push して GitHub Actions に任せてください。"
  exit 1
fi

echo "[guard] 全チェック通過。HEAD = $LOCAL_SHA"

# -----------------------------------------------------------------------------
# 4. 必須環境変数（.env.local を source しておくこと）
# -----------------------------------------------------------------------------
: "${SSH_HOST:?SSH_HOST が未設定です（例: sv13339.xserver.jp）}"
: "${SSH_USER:?SSH_USER が未設定です（例: xs095198）}"
: "${SSH_PORT:=10022}"
: "${SSH_KEY:=$HOME/.ssh/xserver_hayazai}"
: "${LOCAL_DIR:=$(pwd)/}"
: "${REMOTE_DIR:=~/hayazai.com/public_html/}"

# -----------------------------------------------------------------------------
# 5. --dry-run 対応（2 つ目の引数で渡せる）
# -----------------------------------------------------------------------------
RSYNC_DRY=""
if [[ "${2:-}" == "--dry-run" ]]; then
  RSYNC_DRY="--dry-run"
  echo "[mode] --dry-run: 実際の転送は行いません"
fi

# -----------------------------------------------------------------------------
# 6. rsync 実行
# -----------------------------------------------------------------------------
rsync -avz --delete ${RSYNC_DRY} \
  --exclude '.DS_Store' \
  --exclude '.git/' \
  --exclude '.github/' \
  --exclude '.claude/' \
  --exclude 'node_modules/' \
  --exclude 'scripts/' \
  --exclude 'docs/' \
  --exclude 'functions/' \
  --exclude '*.md' \
  --exclude 'CLAUDE.md' \
  --exclude 'README.md' \
  --exclude 'CHANGES.md' \
  --exclude '*.bak' \
  --exclude '*.tmp' \
  --exclude 'deploy.sh' \
  --exclude '.env*' \
  --exclude '.gitignore' \
  --exclude 'data/products.js.NEW*.draft' \
  --exclude 'data/*.draft' \
  -e "ssh -i ${SSH_KEY} -p ${SSH_PORT} -o StrictHostKeyChecking=no" \
  "${LOCAL_DIR}" \
  "${SSH_USER}@${SSH_HOST}:${REMOTE_DIR}"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] hayazai.com 手動デプロイ完了 (sha=$LOCAL_SHA)" | tee -a /tmp/hayazai_deploy.log
echo ""
echo "✅ 手動デプロイ完了。GitHub Actions の状態と整合しているか必ず確認してください:"
echo "   https://github.com/h02050d-ship-it/hayazai-website/actions"
