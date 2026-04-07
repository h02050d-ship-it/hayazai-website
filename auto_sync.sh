#!/bin/bash
# hayazai-website 自動sync スクリプト

cd /Users/hayashidaiki/hayazai_website

# 変更があれば自動コミット＆プッシュ
if [[ -n $(git status --porcelain) ]]; then
  git add -A
  git commit -m "auto sync: $(date '+%Y-%m-%d %H:%M')"
  git push origin main
fi
