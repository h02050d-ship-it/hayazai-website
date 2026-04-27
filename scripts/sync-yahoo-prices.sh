#!/usr/bin/env bash
# Yahoo!ショッピング 価格同期スクリプト ローカル実行ラッパー
#
# 使用例:
#   YAHOO_APP_ID=xxxxxxxx ./scripts/sync-yahoo-prices.sh
#   YAHOO_APP_ID=xxxxxxxx ./scripts/sync-yahoo-prices.sh --dry-run

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

if [ -z "${YAHOO_APP_ID:-}" ]; then
  echo "[ERROR] 環境変数 YAHOO_APP_ID が未設定です。" >&2
  echo "        例: YAHOO_APP_ID=xxxx ./scripts/sync-yahoo-prices.sh" >&2
  exit 1
fi

cd "${ROOT_DIR}"
exec node "${SCRIPT_DIR}/sync-yahoo-prices.js" "$@"
