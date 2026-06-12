<?php
// AIチャット設定のサンプル。本番の ai/config.php は GitHub Actions が
// Secrets（OPENAI_API_KEY）から自動生成する（リポジトリには含めない）。
return [
    'api_key' => 'sk-xxxxx',
    'model'   => 'gpt-4o-mini',
];
