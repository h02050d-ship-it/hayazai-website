# Cloudflare Pages 移行チェックリスト（hayazai.com）

このチェックリストを上から順に進めれば、Xserver から Cloudflare Pages への完全移行が完了します。
所要時間：合計 約2時間（うちネームサーバ反映待ちが最大24時間）

---

## ステップ 1〜4：Cloudflare Pages の準備（30分）

```
[ ] 1. Cloudflareアカウント作成（5分）
       https://dash.cloudflare.com/sign-up
       → メール認証まで完了させる

[ ] 2. Pages プロジェクト作成（5分）
       「Workers & Pages」→「Create」→「Pages」タブ →「Connect to Git」
       GitHub認証 → リポジトリ「h02050d-ship-it/hayazai-website」を選択

[ ] 3. ビルド設定（保存ボタンを押すだけ）
         Project name             : hayazai-website
         Production branch        : main
         Framework preset         : None
         Build command            : node scripts/build-gallery.js
         Build output directory   : /
       「Save and Deploy」を押す

[ ] 4. プレビュー動作確認（5〜10分・ビルド完了後）
       https://hayazai-website.pages.dev/ を開いて以下を確認
         [ ] トップページ（index.html）が表示される
         [ ] /products.html の価格表が表示される
         [ ] /shipping.html（または送料表ページ）が表示される
         [ ] /gallery.html の施工事例画像が表示される
         [ ] /contact.html フォームが表示される（送信は後でOK）
         [ ] hero画像（hero_baby_2026.webp）が読み込まれる
```

---

## ステップ 5〜8：ネームサーバ切替（10分作業＋反映待ち最大24時間）

```
[ ] 5. ドメイン追加（Cloudflareダッシュボード）
       「Add a site」→「hayazai.com」入力 →「Free」プラン選択
       Cloudflareが既存DNSレコードをインポートしてくれる
       → インポート結果を確認し、不要なワイルドカード *.hayazai.com の A レコードは削除

[ ] 6. 割当てられた Cloudflare ネームサーバをメモ
       例：xxxx.ns.cloudflare.com / yyyy.ns.cloudflare.com（2本）

[ ] 7. Custom Domain 紐付け（Pages → hayazai-website → Custom domains）
       「Set up a custom domain」→ hayazai.com 追加
       「Set up a custom domain」→ www.hayazai.com 追加
       → Cloudflare が必要な CNAME を自動作成

[ ] 8. ⚠️ Xserver側のネームサーバ変更（重要）
       Xserverアカウントパネル → ドメイン → 「ネームサーバー設定」
       現状  : ns1.xserver.jp 〜 ns5.xserver.jp（5本）
       変更後 : Cloudflareが指定した2本だけに変更
       → 反映待ち：通常2〜6時間、最大24〜48時間
       → whatsmydns.net で各国NSの伝播を確認できる
```

---

## ステップ 9〜10：DNS レコード登録とメールテスト（30分）

```
[ ] 9. Cloudflare DNS 画面で docs/DNS_AFTER.md の内容を登録
       「DNS」→「Records」→ Add record
         [ ] CNAME www → hayazai.com（Proxied）
         [ ] A     mail → 162.43.118.20（DNS only / gray cloud）★必ずgrayにする
         [ ] MX    @ → mail.hayazai.com（priority 0）
         [ ] TXT   @ → v=spf1 ip4:162.43.118.20 include:relay.mailchannels.net include:spf.sender.xserver.jp ~all
         [ ] TXT   _mailchannels → v=mc1 cfid=hayazai-website.pages.dev
         [ ] TXT   _dmarc → v=DMARC1; p=none; rua=mailto:info@hayazai.com; ruf=mailto:info@hayazai.com; fo=1; adkim=r; aspf=r; pct=100
         [ ] TXT   @ → google-site-verification=nPMIqE6weRDATmNzOwKrdCbreEAQTDcP5OFDTYGPiE4
       → ルートのAレコードはPagesのCustom Domain機能が自動でフラット化するため手動追加しない

[ ] 10. メール送信テスト（DNS反映完了後）
        [ ] https://hayazai.com/contact.html を開く
        [ ] 自分のメールアドレスを入力して送信
        [ ] 約30秒以内に2通届けば成功
              ・お客様向け自動返信（差出人 info@hayazai.com）
              ・店舗向け通知（info@hayazai.com 宛）
        [ ] 念のため https://www.mail-tester.com/ で総合スコアを確認（目安9/10以上）
```

---

## ステップ 11：旧サーバ撤去（移行後 1〜2 週間）

```
[ ] 11. 1〜2週間安定稼働を確認したら Xserver 解約
        [ ] フォーム送信が問題なく届き続けている
        [ ] 受信メール（info@hayazai.com）に普段通り届いている
        [ ] Cloudflare Analytics でアクセスエラーが出ていない
        [ ] DMARC レポート（rua）に大きな問題がない
        → Xserver会員パネルで「サーバー契約 → 解約申請」
        ※ ドメイン契約はXserverドメイン（GMO系）に残るので別途管理
        ※ 後日 Cloudflare Registrar への移管も検討（年$10、約1,500円）
```

---

## トラブル時のロールバック

```
ネームサーバを Xserver に戻すだけで完全に元の構成に戻ります。
  Xserverパネル → ドメイン → ネームサーバー設定
  ns1.xserver.jp / ns2.xserver.jp / ns3.xserver.jp / ns4.xserver.jp / ns5.xserver.jp
  に戻して保存 → 数時間で復帰

旧 PHP（contact.php / order.php / gallery.php）はリポジトリに残してあるため、
Xserverへ rsync デプロイすれば即座に動作します。
```

---

## 参考：関連ドキュメント

- `docs/CLOUDFLARE_MIGRATION.md` ：移行の全体ガイド（背景・コスト比較・DKIM等）
- `docs/DNS_BEFORE.txt` ：移行前のDNSレコード全件
- `docs/DNS_AFTER.md` ：Cloudflare に登録すべきレコード一覧
