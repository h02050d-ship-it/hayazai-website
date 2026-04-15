#!/bin/bash
# 林材木店HP 自動デプロイスクリプト
# Claude PostToolUse hookから呼ばれる
# hayazai_website のファイルが編集された場合のみrsyncを実行

if echo "${CLAUDE_TOOL_INPUT:-}" | grep -q "hayazai_website"; then
  rsync -az --delete \
    --exclude '.DS_Store' \
    --exclude '.git/' \
    --exclude '*.md' \
    --exclude 'CLAUDE.md' \
    --exclude 'deploy.sh' \
    -e "ssh -i ~/.ssh/xserver_hayazai -p 10022 -o StrictHostKeyChecking=no" \
    /Users/hayashidaiki/hayazai_website/ \
    ***REDACTED***@***REDACTED***:~/hayazai.com/public_html/ \
    2>&1
  echo "✅ hayazai.com にアップロード完了"
fi
