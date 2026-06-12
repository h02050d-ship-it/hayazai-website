# サイト全ページ ファクトチェック報告書【第2回】（ブログ除く）

- 実施日: 2026-06-12（本日2回目・1回目報告書 fact_check_site_report.md 以降の大量変更を重点検証）
- 対象: ルート直下の全HTML、order.php / contact.php、ai/（chat.php・knowledge.txt）、data/、js/、.htaccess、sitemap.xml（blog/ 配下は別エージェント担当のため対象外）
- 重点: リピート割引への制度変更、天竜桧の扱い、注文フロー修復、AIチャット、アウトレット

---

## 1. 今回の修正一覧（3件）

| # | ファイル | 内容 |
|---|---|---|
| 1 | faq.html（JSON-LD） | **旧・数量ベース割引の残存を発見・修正。** FAQPage 構造化データの「工務店・業者向けの卸価格はありますか？」の回答が「**まとまった数量のご注文には卸価格でのご提供が可能**」のままで、新リピート割引（回数ベース）と矛盾していた。「ご注文回数に応じたリピート割引（6回目以降5%、最大15%OFF）と掛売りのご相談に応じています」へ更新。JSON構文の妥当性検証済み |
| 2 | faq.html（本文） | 同FAQの画面表示回答も「大量注文・継続取引をお考えの〜ご相談に応じます」と曖昧だったため、リピート割引の概要（6回目以降5%、最大15%OFF）＋ business.html へのリンクを明記。ブラウザで表示・リンク・アコーディオン動作を確認済み |
| 3 | .htaccess | AIチャットのレート制限状態ファイル（ai/state/rate_*.json）が外部から閲覧可能だったため、`RewriteRule ^ai/state/ - [F,L]` を追加しディレクトリごと403に。中身はタイムスタンプのみで機微情報はないが、内部状態の露出を遮断 |

---

## 2. 要ユーザー判断（修正せずリストアップ）

1. **沖縄県が全リストから除外されているが、その旨の記載がどこにもない。** 佐川・西濃の料金表（order/products/shipping）と注文フォーム・各計算機の都道府県リストはすべて46都道府県（沖縄なし）で統一されているが、サイト上に「沖縄・離島は配送不可（または要相談）」の明記が一切ない。意図的な除外なら shipping.html・tokushoho.html への明記を推奨。
2. **knowledge.txt の「数枚単位の少量・補修用途の注文は受けていない」はサイト上に対応記載がない。** 矛盾ではないが、AIチャットだけが案内する情報になっている。事実であれば FAQ への追記を推奨（現状は束単位販売のため実害は薄い）。
3. **chat.php の同一サイトチェックは部分一致。** `strpos($origin,'hayazai.com')` のため、Origin/Referer 無しのリクエストや「hayazai.com.evil.com」のような偽装で通過可能。レート制限（20回/時/IP）・最大600トークンのため実害は限定的だが、厳格化するならホスト名の完全一致判定を推奨。
4. **faq.html「まとめて注文すると送料が割安になりますか？」の回答が混載に未対応（軽微）。** 910mm（佐川）と1,820mm以上（西濃）の混載は別便・送料合算（order.html の注記どおり）のため、「同じ長さでまとめると割安」がより正確。誤りとまでは言えないため現状維持。
5. **outlet.html「強度は正規品と同等」の表現。** B級品の内容（反り・割れ・節抜け）を併記しており knowledge.txt とも一致しているが、「同等」の断定は商品実態の確認を推奨。
6. **data/outlet.json・data/products.json に UTF-8 BOM が付いている。** ブラウザの fetch().json() は BOM を許容するため現状実害なし。ただし PHP や外部ツールで読む場合は要注意（json_decode は BOM で失敗する）。
7. **佐川・西濃運賃データの最新性**（2026年改定運賃か）は引き続き社内資料がなく未検証（1回目報告 #5 と同じ）。

---

## 3. 問題なし確認済み

### 重点1: リピート割引（旧ロット割引の廃止）
- **business.html（表・カード・比較表・FAQ・注記）/ index.html（法人バナー）/ ai/knowledge.txt の3箇所で完全一致**: 1〜5回目通常価格／6〜10回目5%OFF／11〜15回目10%OFF／16回目以降15%OFF、直接取引（HP見積もり・電話・LINE・FAX）の通算回数、当店管理・見積もり時案内、Yahoo!・楽天経由は対象外。
- 旧・束数ベースのロット割引の残存は **faq.html の JSON-LD 1件のみ**（→修正済み）。全HTML・PHP・JS・JSONを「ロット割引／束以上／大量割引／まとめ買い」等で横断grepし、他に残存なし。
- business.html の「50束以上」は見積もりフォームの希望数量選択肢であり、割引制度とは無関係（問題なし）。
- %・回数の食い違い（5/10/15%、6/11/16回目）も全ページゼロ。

### 重点2: 天竜桧の扱い
- ルート配下の全ページ（meta・JSON-LD含む）に「天竜桧＝自社商品」と誤認させる表現なし。products.js の商品名にも「天竜」なし。
- ai/knowledge.txt に「当店の桧は国産桧。『天竜桧』のブランド材ではない（質問されたら正直にそう答える）」と明記済み。
- blog.html のカード文言「天竜美林の麓で…」はブログ記事タイトルの転載で、地域ストーリーの範囲内（可）。blog/ 配下は対象外だが、参照した限り「商品＝天竜桧」とする記述はない（fact_log_writer1.md にも同方針の記録あり）。

### 重点3: 注文フロー（order.html / order.php / product.html）
- **フォーム⇔PHPの突合**: order.html の name 属性10項目（cart_json/name/company/email/tel/zip/prefecture/address1/address2/note）＝ order.php の $_POST 受信フィールドと完全一致。
- **ブラウザ実動作検証**: カートに商品を入れて order.html を開く→注文セクション表示・明細1行・合計¥9,600（¥4,800×2で正確）・cart_json 自動充填・action="order.php"・都道府県47option（=プレースホルダー+46県）を確認。カートを空にして再読込→注文セクション非表示・シミュレーターのみ表示・cart.html への強制リダイレクトなし（initCartOrderSection が空カート時に renderOrderSummary を呼ばないガード設計を確認）。
- order.php: $total_fmt・$bank がヒアドキュメントより先に定義済み（1回目の修正が維持）。署名住所〒437-1203 磐田市福田5490-47・振込先 遠州信用金庫 0131004660 とも正しい。バリデーション・サニタイズあり。
- **送料データの機械突合（全テーブル完全一致）**: FR_SAGAWA_1M（order.html ↔ products.html）、SAGAWA_1M（shipping.html、同値）、SEINO_DISTANCES／SEINO_PREF_MAP／PREF_TO_DIST／SEINO_WEIGHTS／SEINO_FARES／DANBALL（order.html ↔ shipping.html）。「910mm=佐川急便、1,820mm以上=西濃運輸、個人は西濃支店止め」の文言も order/shipping/products/business/knowledge.txt で一貫。
- product.html: stock.json をフェッチし `STOCK[p.id]===false` のみ在庫切れ判定（products.html と同方式）を確認。stock.json の全IDは products.js または outlet.json に実在（独立IDは 'sample' のみ＝サンプル請求用で問題なし）。
- products.html「12mm・15mm同価格」: A級品同士の全長さ×全等級（4×4）で同額を検算し正確。12mmのB級品4点は別価格だが、当該表はA級品のみを表示するため矛盾なし。

### 重点4: AIチャット（ai/chat.php / ai/knowledge.txt）
- chat.php セキュリティ: POST限定・入力検証（1発言1,000字制限・直近12発言・roleをuser/assistantに強制）・IP別レート制限（20回/時）・タイムアウト30秒・APIキーはサーバー側 ai/config.php（GitHub Actions Secretsから生成、リポジトリ非含有。config.sample.php はダミー値のみ）・エラー応答にキーや内部情報の露出なし。
- knowledge.txt の事実突合: 会社情報（社名・住所・電話・営業時間・創業1968年）／商品規格（厚15・12mm、幅108mm、長さ910/1820/3000/4000mm、4グレード）／無料サンプル約110×300mm／支払い（銀行振込前払い・手数料客負担・7日以内）／発送（入金確認後2〜5営業日）／配送（910=佐川・1820以上=西濃支店止め）／返品（初期不良等のみ7日以内）／リピート割引／「天竜桧ではない」／ヒノキチオール非含有（α-カジノール表記）——すべてサイト本文・products.js と一致。
- knowledge.txt 記載の全URL（ブログ22本＋products/outlet/sample/shipping/business/gallery/faq/contact）が全て実ファイルとして存在することを確認。
- フロント（js/ai-chat.js）: エンドポイント解決（ルート/blog/双方）正常、AI免責文・電話番号も正確。components.js から全ページ読込。

### 重点5: アウトレット
- data/outlet.json 全29商品の img（images/outlet/*.jpg）が全て実在。inStock フィールドあり。outlet.html の JSON-LD（BreadcrumbList）構文OK。

### 定番横断チェック
- 電話 0538-58-2395／〒437-1203 磐田市福田5490-47／平日9:00〜17:00／遠州信用金庫 普通 0131004660 — 全ページ一致（contact/sample の「090-0000-0000」「430-0000」はフォームの placeholder であり実情報ではない）。
- NG表現: 「現場直送」「カスタム加工」「林材木店様」「日本一」「最安値」「業界最安」「No.1」系 — 全ページゼロ。
- 内部リンク・画像: href/src の実在を全ページ機械チェック — 切れゼロ（JSテンプレート文字列 `${...}` の誤検知のみ）。
- canonical / og:url: 全ページでファイル名と一致（photo.html のみ og:url 無しだが noindex ページのため問題なし）。og:image（images/og/og-image.jpg）実在。
- JSON-LD: 全ページの全ブロックを JSON.parse で検証 — 構文エラーゼロ。index.html の LocalBusiness から aggregateRating が削除済みであることを確認（本文の「4.97以上/600件以上」は出典明記つきテキストとして残置＝1回目の対応どおり）。
- インラインJS: 全ページの全ブロックを構文チェック（node）— エラーゼロ。
- sitemap.xml: 38 URL すべて実ファイルあり。noindex ページ（photo.html）の混入なし。robots.txt と矛盾なし。
- 会社・工場の説明（製材は協力先／原板厳選・天然乾燥・超仕上げ・検品・出荷は自社）— index/company/knowledge.txt で一貫。

---

## 4. 検証メモ

- 検証方法: 全データテーブルは正規表現抽出→正規化比較で機械突合。注文フローはローカルプレビュー（localhost:3456）でカートあり/なし両系統の実動作を確認。
- 今回の修正（faq.html・.htaccess）は本番反映に git push が必要（git操作は指示により未実施）。
- blog/ 配下は別エージェント担当のため未編集（参照のみ）。
