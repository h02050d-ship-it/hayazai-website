# Cloudflare Pages 移行ガイド（hayazai.com）

XサーバーからCloudflare Pagesへ移行するための手順書。
本リポジトリにはコード側の準備が完了済み。以下はユーザー（手動操作）の手順のみ。

---

## 0. 現状サマリ（コード側で完了済み）

| 項目 | 状態 |
|------|------|
| `functions/contact.js` | 旧 `contact.php` を Pages Functions 化（MailChannels送信） |
| `functions/order.js` | 旧 `order.php` を Pages Functions 化（MailChannels送信） |
| `_redirects` | `/contact.php`→`/contact`、`/order.php`→`/order`、`/gallery.php`→`/gallery.html` |
| HTMLフォーム | `contact.html`, `sample.html` を `/contact` に修正済み |
| `scripts/build-gallery.js` | `images/gallery/` から `data/gallery.json` を生成 |
| `.github/workflows/deploy.yml` | mainプッシュで Cloudflare Pages へ自動デプロイ |

旧PHPファイルは互換のため当面残置（DNS切替後に削除可）。

---

## 1. Cloudflareアカウント作成（無料）

所要：5分

1. https://dash.cloudflare.com/sign-up でアカウント作成
2. メール認証を済ませる

---

## 2. Cloudflare Pages プロジェクト作成

所要：5分

1. ダッシュボード左メニュー「Workers & Pages」→「Create」→「Pages」タブ
2. 「Connect to Git」→ GitHub認証
3. リポジトリ `h02050d-ship-it/hayazai-website` を選択
4. ビルド設定：
   - **Project name**: `hayazai-website`
   - **Production branch**: `main`
   - **Framework preset**: `None`
   - **Build command**: `node scripts/build-gallery.js`
   - **Build output directory**: `/`（ルート）
5. 「Save and Deploy」

→ 数分後 `https://hayazai-website.pages.dev` で表示確認できる

---

## 3. GitHub Actions 用シークレット設定（必要な場合のみ）

所要：5分

`.github/workflows/deploy.yml` を使う場合（ステップ2の自動連携で十分なら不要）：

1. Cloudflare ダッシュボード → 右上アイコン → 「My Profile」→「API Tokens」
2. 「Create Token」→「Custom token」
   - Permissions: `Account` → `Cloudflare Pages` → `Edit`
   - Account Resources: 自分のアカウント
3. 発行されたトークンと、Cloudflare ダッシュボード右下の **Account ID** を控える
4. GitHub リポジトリ → Settings → Secrets and variables → Actions
   - `CLOUDFLARE_API_TOKEN` = 上記トークン
   - `CLOUDFLARE_ACCOUNT_ID` = 上記アカウントID

> **おすすめ**: Cloudflare PagesのGitHub連携で十分自動デプロイされるので、本ステップはスキップしてよい。

---

## 4. プレビュー動作確認

所要：10分

`https://hayazai-website.pages.dev` を開いて以下を確認：

- [ ] トップページ表示
- [ ] `/products.html` 価格表
- [ ] `/gallery.html` 施工事例（画像が表示される）
- [ ] `/contact.html` フォーム送信テスト（自分宛で1件送ってみる）
  - 注意：この時点では DKIM/SPF が hayazai.com 用に未設定なので **送信が失敗するか迷惑メール扱い** になる可能性が高い。本番DNS切替後に再テスト。

---

## 5. カスタムドメイン紐付け

所要：10分

Cloudflare Pages の `hayazai-website` プロジェクト →「Custom domains」→「Set up a custom domain」
1. `hayazai.com` を入力
2. `www.hayazai.com` も同様に追加
3. Cloudflare が必要なDNSレコードを表示

---

## 6. ネームサーバー切替（Xサーバー → Cloudflare）

所要：5分作業＋反映に数時間〜48時間

**現状（DNS調査結果）：**
- ドメイン: `hayazai.com`
- 現NS: `ns1.xserver.jp` 〜 `ns5.xserver.jp`（Xサーバー管理）
- 現A: `162.43.118.20`
- 現MX: `hayazai.com`（自ドメイン宛 → Xサーバーのメールサーバー）
- 現SPF: `v=spf1 +a:sv13339.xserver.jp +a:hayazai.com include:spf.sender.xserver.jp ~all`

**ドメインのレジストラ**は WHOIS が gTLDレジストリ経由で隠蔽されており未確認。
Xサーバー上で「ドメイン」契約していれば **Xサーバードメイン**（実体はGMOブランドの可能性）。

### おすすめ手順（NSのみCloudflareへ・ドメインはXサーバーに残す）

最小リスクでまず移行する方法。レジストラ移管は不要：

1. Cloudflare ダッシュボード → 「Add a site」→ `hayazai.com` 入力
2. プラン: Free 選択
3. Cloudflare が現NSレコードを自動取得 → 表示
4. Cloudflare が割り当てる新NS（例：`xxx.ns.cloudflare.com` 2本）をメモ
5. Xサーバー管理画面 → ドメイン → ネームサーバー設定
   - Xサーバー指定NSを **Cloudflare指定NS 2本** に変更
6. 反映を待つ（通常2〜24時間、最大48時間）

### 後日推奨：Cloudflare Registrar への移管

Cloudflare Registrar は原価更新（`.com` 年額 約 $10）。Xサーバードメインの維持費（年額 1,500円〜）と比べて安いことが多い。

1. Cloudflare ダッシュボード → 「Domain Registration」→「Transfer Domains」
2. WHOISメール認証 → 認証コード取得 → 移管申請
3. 5〜7日で完了

---

## 7. メール送信用 DNSレコード設定（最重要）

所要：15分（Cloudflare 上で設定）

Cloudflare へ移行後、Cloudflare DNS 画面で以下を追加：

### 7-1. SPF（既存を更新）

```
Type:  TXT
Name:  @  （hayazai.com）
Value: v=spf1 include:relay.mailchannels.net include:spf.sender.xserver.jp ~all
```

> Xサーバー側でメール受信機能（`info@hayazai.com`）を引き続き使うなら `spf.sender.xserver.jp` も残す。
> 廃止するなら `v=spf1 include:relay.mailchannels.net ~all`。

### 7-2. MailChannels ドメインロック（必須）

MailChannels が hayazai.com からのメール送信を許可する設定（なりすまし防止のため必須）。

```
Type:  TXT
Name:  _mailchannels  （つまり _mailchannels.hayazai.com）
Value: v=mc1 cfid=YOUR_CLOUDFLARE_ACCOUNT_ID.workers.dev
```

`YOUR_CLOUDFLARE_ACCOUNT_ID` は Cloudflare Account ID（Step 3 で控えた値）。
正確な書式は https://support.mailchannels.com/hc/en-us/articles/16918954360845 を参照。

### 7-3. DKIM（推奨：到達率向上）

Cloudflare Workers で DKIM 署名を有効化：

1. ローカル or オンラインで RSAキーペアを生成
   ```bash
   openssl genrsa 2048 | tee priv.pem | openssl rsa -pubout -outform der | openssl base64 -A
   ```
2. 公開鍵を DNS に登録
   ```
   Type:  TXT
   Name:  mailchannels._domainkey
   Value: v=DKIM1; k=rsa; p=（出力されたbase64公開鍵）
   ```
3. 秘密鍵を Cloudflare Pages の環境変数に設定
   - Pages プロジェクト → Settings → Environment variables
   - `DKIM_PRIVATE_KEY` = （priv.pem の内容、PKCS#8 base64）
   - `DKIM_DOMAIN` = `hayazai.com`
   - `DKIM_SELECTOR` = `mailchannels`
4. `functions/contact.js` / `functions/order.js` の MailChannels リクエストボディに `dkim_*` フィールドを追加（必要に応じて別途依頼）

> **MVPはSPF + ドメインロックだけでも届く。** DKIMは到達率改善の追加施策。

### 7-4. MX（メール受信）

メール受信は当面 Xサーバーで継続：

```
Type:     MX
Name:     @
Priority: 0
Value:    hayazai.com
```

```
Type:  A
Name:  @ （メール用Aレコード／実体はXサーバーIP）
Value: 162.43.118.20
Proxy: DNS only（オレンジ雲OFF）
```

> Cloudflare のオレンジ雲（プロキシ）をONにするとメール用Aレコードが正しく解決されない可能性があるため、A/MX関連はDNS Onlyにする。
> Web 用 A/CNAME（Cloudflare Pages の `hayazai-website.pages.dev` を指すCNAME）は自動で proxied になる。

### 7-5. Google Site Verification（既存維持）

```
Type:  TXT
Name:  @
Value: google-site-verification=nPMIqE6weRDATmNzOwKrdCbreEAQTDcP5OFDTYGPiE4
```

---

## 8. 切替後の動作確認

所要：30分

1. `https://hayazai.com/` 表示確認（CloudflareのSSL証明書が自動発行されている）
2. `https://hayazai.com/contact` POST テスト → `info@hayazai.com` に届くか確認
3. `https://hayazai.com/order` POST テスト → 届くか確認
4. `https://hayazai.com/gallery.php` → `/gallery.html` にリダイレクトされるか確認
5. `https://hayazai.com/contact.php` → `/contact` にリダイレクトされるか確認

> メール到達率は https://www.mail-tester.com/ にテスト送信するとSPF/DKIM/DMARCがスコア化される。

---

## 9. Xサーバー解約

所要：5分（解約申請のみ）

切替後 1〜2週間程度動作確認したのち、Xサーバー側を解約：

1. Xサーバー会員パネル → サーバー契約 → 解約申請
2. 自動更新を OFF にしてから解約推奨

> ドメインを Xサーバー（GMO系）で取得している場合、ドメイン契約は別なので注意。
> Cloudflare Registrar に移管していなければ、Xサーバー解約時にドメイン契約も忘れず更新すること。

---

## 10. コスト比較

| 項目 | Xサーバー | Cloudflare Pages + Registrar |
|------|----------|------------------------------|
| サーバー | 月額 1,100円〜（年13,200円〜） | **無料**（無制限帯域） |
| ドメイン | 年 1,500円程度 | 年 約 $10（≒1,500円・原価） |
| メール送信 | サーバー込み | MailChannels 無料 |
| メール受信 | Xメール込み | 別途要検討（Cloudflare Email Routing 無料 or Google Workspace） |
| SSL | Let's Encrypt 自動 | Cloudflare 自動 |
| **年合計** | **約14,700円〜** | **約1,500円**（実質ドメイン代のみ） |

→ **年間 約13,000円の削減**

---

## 11. ロールバック手順（万一トラブル時）

ネームサーバーをXサーバーに戻すだけ：

1. Cloudflare 設定をそのまま残し、Xサーバーのドメイン管理画面でNSを `ns1.xserver.jp〜ns5.xserver.jp` に戻す
2. 数時間で旧構成に復帰

旧PHPファイル（`order.php`, `contact.php`, `gallery.php`）は本リポジトリに残してあるため、Xサーバーへの再rsyncで完全復元可能。
