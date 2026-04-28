# Cloudflare DNS 設定（移行後）— ペースト用

ネームサーバを Cloudflare へ切替えた直後、Cloudflare ダッシュボード
`hayazai.com` → `DNS` → `Records` で以下を登録してください。

---

## 1. 一括ペースト用テーブル（Cloudflare DNS 画面）

| Type  | Name                | Content / Target                                                                                                                          | Priority | TTL  | Proxy            |
|-------|---------------------|-------------------------------------------------------------------------------------------------------------------------------------------|----------|------|------------------|
| A     | `@`                 | `（Cloudflare PagesがCustom Domain追加時に表示するIP / 通常は不要・以下注記参照）`                                                          | -        | Auto | (Pages連携時自動) |
| CNAME | `www`               | `hayazai.com`                                                                                                                              | -        | Auto | Proxied (orange) |
| MX    | `@`                 | `mail.hayazai.com`                                                                                                                         | 0        | Auto | DNS only         |
| A     | `mail`              | `162.43.118.20`                                                                                                                            | -        | Auto | DNS only (gray)  |
| TXT   | `@`                 | `v=spf1 ip4:162.43.118.20 include:relay.mailchannels.net include:spf.sender.xserver.jp ~all`                                               | -        | Auto | -                |
| TXT   | `_mailchannels`     | `v=mc1 cfid=hayazai-website.pages.dev`                                                                                                     | -        | Auto | -                |
| TXT   | `_dmarc`            | `v=DMARC1; p=none; rua=mailto:info@hayazai.com; ruf=mailto:info@hayazai.com; fo=1; adkim=r; aspf=r; pct=100`                               | -        | Auto | -                |
| TXT   | `@`                 | `google-site-verification=nPMIqE6weRDATmNzOwKrdCbreEAQTDcP5OFDTYGPiE4`                                                                     | -        | Auto | -                |

> 注記:
> - **A `@`（ルート）**は、Cloudflare Pages の Custom Domain 機能で `hayazai.com` を紐付けた瞬間に「`hayazai-website.pages.dev` への CNAME flattening」が自動で作成されます。手動でAを追加する必要はありません（追加すると競合します）。
> - **CNAME `www`** はメインの Apex に流すため Proxied のままにします。
> - **A `mail`**（Xserver直結用）は **DNS only / gray cloud** にしてください。プロキシ経由だとSMTP/POP/IMAPが通りません。
> - **MX `@`** の `mail.hayazai.com` は新規に立てる A レコード（上の `mail` 行）を指します。これにより `MX = hayazai.com` の自己参照を避けつつ、Cloudflare のプロキシIPがメールを受けないようにします。

---

## 2. 各レコードの意味と入力例

### 2-1. ウェブ（A / CNAME）

```
Type:    （Cloudflare Pages の Custom Domain 機能で自動追加 → 手動では何もしない）
Name:    hayazai.com
Target:  hayazai-website.pages.dev
Proxy:   Proxied（自動）
```

```
Type:    CNAME
Name:    www
Target:  hayazai.com
Proxy:   Proxied
TTL:     Auto
```

### 2-2. メール受信（MX + A `mail`）

XserverのメールサーバでInfo@hayazai.com受信を継続するための設定。

```
Type:      MX
Name:      hayazai.com   （= @）
Mail server: mail.hayazai.com
Priority:  0
TTL:       Auto
```

```
Type:    A
Name:    mail
IPv4:    162.43.118.20    ← sv13339.xserver.jp の現IP
Proxy:   DNS only (gray cloud)
TTL:     Auto
```

### 2-3. SPF（送信元認証）

MailChannels（Cloudflare Pages Functions の送信経路）と Xserver の両方を許可。

```
Type:    TXT
Name:    hayazai.com   （= @）
Value:   v=spf1 ip4:162.43.118.20 include:relay.mailchannels.net include:spf.sender.xserver.jp ~all
TTL:     Auto
```

### 2-4. MailChannels ドメインロック（必須）

> 2024年6月以降 MailChannels はドメイン側で明示許可がないと送信を拒否します。

```
Type:    TXT
Name:    _mailchannels
Value:   v=mc1 cfid=hayazai-website.pages.dev
TTL:     Auto
```

`cfid=` の値は **Cloudflare Pages のプロジェクト識別子（= プロジェクト名 + `.pages.dev`）** を指定します。本サイトのプロジェクト名は `hayazai-website` の想定です。

### 2-5. DMARC（最低限のレポートポリシー）

```
Type:    TXT
Name:    _dmarc
Value:   v=DMARC1; p=none; rua=mailto:info@hayazai.com; ruf=mailto:info@hayazai.com; fo=1; adkim=r; aspf=r; pct=100
TTL:     Auto
```

`p=none` は「失敗しても受信側で何もしない（レポートだけ受ける）」モード。
1〜2週間運用してレポートに問題なければ `p=quarantine` に強化します。

### 2-6. Google Search Console 認証（既存維持）

```
Type:    TXT
Name:    hayazai.com   （= @）
Value:   google-site-verification=nPMIqE6weRDATmNzOwKrdCbreEAQTDcP5OFDTYGPiE4
TTL:     Auto
```

---

## 3. DKIM（オプション・後追いで設定）

到達率を高めたい場合のみ。MailChannelsはDKIM未設定でも送信可能です。

```bash
# 鍵ペア生成（ローカル）
openssl genrsa 2048 > priv.pem
openssl rsa -in priv.pem -pubout -outform der | openssl base64 -A
```

DNSへ登録（公開鍵）:
```
Type:    TXT
Name:    mailchannels._domainkey
Value:   v=DKIM1; k=rsa; p=（出力されたbase64公開鍵）
TTL:     Auto
```

Cloudflare Pages の Environment variables に追加（秘密鍵）:
```
DKIM_PRIVATE_KEY = （priv.pem の内容を1行に）
DKIM_DOMAIN      = hayazai.com
DKIM_SELECTOR    = mailchannels
```

その後 `functions/contact.js` / `functions/order.js` の MailChannels リクエストボディに `dkim_*` フィールドを追加（必要時に別途対応）。

---

## 4. 削除すべき旧レコード（移行後に Xserver 側に残しておかない）

Cloudflare DNS には **Xserver からのレコードコピーは自動でインポートされる** が、以下は不要・有害なので削除。

- ワイルドカード `*.hayazai.com` の A レコード（→ Cloudflare では作らない／削除）
- Xserver関連のサブドメインA（`www`, `pop`, `imap`, `smtp`, `autodiscover`, `autoconfig`, `ftp`, `webmail` など）
  - 必要なものだけを上の表に従って明示的に作る

---

## 5. 確認コマンド（切替後）

```bash
# NS切替の確認
nslookup -type=NS hayazai.com 8.8.8.8

# Aレコード（Cloudflareの匿名IPになるはず）
nslookup hayazai.com 8.8.8.8

# MX
nslookup -type=MX hayazai.com 8.8.8.8

# SPF
nslookup -type=TXT hayazai.com 8.8.8.8

# MailChannels ロック
nslookup -type=TXT _mailchannels.hayazai.com 8.8.8.8

# DMARC
nslookup -type=TXT _dmarc.hayazai.com 8.8.8.8
```

---

## 6. メール到達率テスト

```
1. https://www.mail-tester.com/ にアクセス
2. 表示されるテストアドレスを contact.html フォームで送信
3. SPF / DKIM / DMARC のスコアを確認
   目標: 9/10 以上
```
