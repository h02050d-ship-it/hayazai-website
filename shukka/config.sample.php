<?php
// =====================================================
// 出荷依頼送信 設定サンプル
// 実ファイル shukka/config.php は Git にコミットしない（公開リポジトリのため）。
// 本番では GitHub Actions のデプロイ時に Secrets から config.php を生成して配置する。
//   - SHUKKA_CHANNEL_ACCESS_TOKEN（出荷専用LINE @961fvwwp の長期トークン）
//   - SHUKKA_SEND_PIN（送信ガード用PIN）
//   - SHUKKA_FIREBASE_SECRET（加工予定表 RTDB のレガシーDBシークレット。cron用）
// =====================================================
return [
    'channel_access_token' => 'YOUR_SHUKKA_CHANNEL_ACCESS_TOKEN',
    'send_pin'             => 'YOUR_PIN',
    'firebase_secret'      => 'YOUR_FIREBASE_DB_SECRET',
];
