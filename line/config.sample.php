<?php
// =====================================================
// 林材木店 LINE bot 設定サンプル
// =====================================================
// 実ファイル `line/config.php` は Git にコミットしない（公開リポジトリのため）。
// 本番では GitHub Actions のデプロイ時に Secrets から config.php を生成して配置する。
// ローカル検証時のみ、このファイルをコピーして config.php を作り値を入れる。
return [
    // LINE Developers のチャネル基本設定 → チャネルシークレット
    'channel_secret'       => 'YOUR_CHANNEL_SECRET',
    // Messaging API → チャネルアクセストークン（長期）
    'channel_access_token' => 'YOUR_CHANNEL_ACCESS_TOKEN',
    // 見積もり依頼の通知先（スタッフ）
    'staff_email'          => 'info@hayazai.com',
    // 送信元メール
    'from_email'           => 'info@hayazai.com',
];
