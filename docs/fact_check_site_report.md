# サイト全ページ ファクトチェック報告書（ブログ除く）

- 実施日: 2026-06-12
- 対象: ルート直下の全HTML、data/、js/components.js、sitemap.xml、robots.txt、.htaccess（blog/ 配下は別エージェント担当のため対象外。blog.html はリンク確認のみ）
- 照合資料: data/products.js（価格・規格の正）、data/stock.json、data/outlet.json、docs/blog_research_2026-06-12.md

---

## 1. 修正済み一覧（9件）

| # | ファイル | 内容 |
|---|---|---|
| 1 | contact.html / sample.html / business.html | **【最重要】フォーム action="/contact" → "contact.php" に修正。** 本番（Xserver）で `POST /contact` が .htaccess の `RewriteRule ^contact/?$ /contact.html [R=301]` に当たり、301リダイレクトで送信データが消失することを実機確認（curlでPOST→301を確認）。`/contact` は旧Cloudflare Pages Functions用のエンドポイントで、Xserver移行後は contact.php が正しい受け口（GET時302で稼働確認済み）。**お問い合わせ・サンプル請求・法人見積もりの全フォームが送信不能だった可能性が高い** |
| 2 | contact.php | business.html の法人見積もりフォーム固有フィールド（業種・希望商品・希望数量）が店舗宛メールに含まれず消失していたため、メール本文に「法人見積もり情報」として追記するよう拡張。quote種別でメッセージ未入力時のバリデーションエラーも回避 |
| 3 | index.html | 「桧に含まれる『ヒノキチオール』は天然の抗菌成分」→ ヒノキチオールはタイワンヒノキ・ヒバの成分で**国産桧にはほぼ含まれない**（docs/blog_research_2026-06-12.md 1-7節・かゆいところ#2）。「桧の精油に含まれるα-カジノールなどの成分には抗菌・防カビ作用があるとされ…」に修正 |
| 4 | index.html | 香り効果「ストレスを和らげると研究で**証明**されています」→「α-ピネン等」を明記し「**報告**されています」へ（断定回避・景表法配慮） |
| 5 | index.html | 「施工事例をすべて見る（**13件**）」→ gallery.html の実データは**29件**。件数のハードコードは陳腐化するため件数表記を削除 |
| 6 | business.html | フォームの placeholder「○○県へ**現場直送**希望」→「納品希望」（現場直送の明記禁止ルール。FAQの「原則支店止め」とも矛盾していた） |
| 7 | faq.html | 枚数計算ツールの単位表記「c㎡」→「㎠」 |
| 8 | sitemap.xml | robots.txt で Disallow 済みの cart.html を削除（矛盾解消）。未掲載だった outlet.html / business.html を追加 |
| 9 | shipping.html | **送料計算機のバグ修正。** ページ・静的表は「910mm=佐川急便」（東京10束=24,000円）なのに、計算機は910mmも西濃運賃で計算し約6,600円と表示していた。products.html 実装済みの佐川料金表（FR_SAGAWA_1M と同一データ）を移植し分岐を追加。修正後の動作をブラウザで検証済み（東京10束910mm→24,000円、1820mm→8,800円=静的表と一致） |

---

## 2. 要ユーザー判断（修正せずリストアップ）

1. **order.html（見積もりシミュレーション）の910mm送料も西濃計算のまま。** 複数商品の混載前提ロジックのため機械的修正を見送った。910mm単独の見積もりでは佐川料金（東京10束24,000円）より大幅に安い金額が出る。注記も「※ 西濃運輸・税込」のみで佐川に言及なし。
2. **カート機能が注文フローに接続していない。** cart.html「注文手続きへ進む」→ order.html だが、現在の order.html は見積もりシミュレーターでカート内容を読み込まない（js/cart.js の renderOrderSummary が参照する #order-items が存在しない）。order_complete.html・order.php は旧フローの残骸。カート機能の存続/撤去はビジネス判断。
3. **index.html JSON-LD（LocalBusiness）の aggregateRating（4.97・600件）。** Yahoo/楽天/メルカリの外部モールのレビューを自社サイトの構造化データとして掲載するのは、Googleの「自己申告レビュー（self-serving reviews）」ポリシーに抵触するリスクがあり、リッチリザルト無効化や手動対応の可能性。本文中の「4.97以上/600件以上」表記自体は出典明記ありで問題薄。
4. **business.html ロット割引の注記「※ 表示価格はすべて税抜です」。** 商品ページは税込をメイン表示しており、割引の基準価格が税抜・税込どちらか曖昧。文言の明確化推奨。
5. **佐川・西濃の運賃データそのものの最新性**（2026年改定運賃か）は社内資料がなく未検証。
6. **blog.html: 旧14記事のサムネイル画像（images/blog/*.jpg）が存在しない。** onerror で非表示になるため表示崩れはないが、新8記事のみ画像ありで見た目が不均一（blog.html は編集対象外のため報告のみ）。
7. **privacy.html に Google Analytics（GA4）利用の明記がない。** js/components.js で全ページに GA4（G-EQLK2295RN）を導入済み。第6条のCookie一般記述のみでは不十分の可能性。
8. **company.html に未記入プレースホルダーが残存**（`<!-- REQUIRES USER INPUT -->`: 主要取引先欄が空、定休日の年末年始補記）。
9. **product.html の在庫切れ表示が機能していない。** `p.outOfStock` を参照するが products.js にこのフィールドはなく、stock.json も読み込まないため常に「在庫あり」扱い。products.html は stock.json 連動済みで、stock.json には false（在庫切れ）の商品が8件あるため、商品詳細ページとの不整合が起こり得る。
10. **残骸ファイル**: blog_post_sample.html（どこからもリンクされていないテンプレ。旧ヒノキチオール記述を含むがnoindex等なし）、js/components.js.bak、blog/shizuoka-hinoki-shop.html.bak、_redirects（Cloudflare用・Xserverでは無効）、functions/（同）。

---

## 3. 問題なし確認済み

### 会社情報の整合（index / company / tokushoho / contact / privacy / フッター(components.js) / 各JSON-LD）
- 社名: 株式会社林材木店（ハヤシザイモクテン）／代表者: 林雅久 — 一致
- 住所: 〒437-1203 静岡県磐田市福田5490-47 — 全ページ一致
- 電話: 0538-58-2395、メール: info@hayazai.com — 一致
- 営業時間: 平日 9:00〜17:00 — 一致（JSON-LD の openingHours も Mo-Fr 09:00-17:00）
- 創業: 1968年＝昭和43年 — 一致（foundingDate "1968"）

### 支払い・配送・返品（order / faq / tokushoho / product / index / business）
- 銀行振込（前払い）のみ、振込手数料客負担、振込期限7日以内 — 一致
- 振込先: 遠州信用金庫 普通 0131004660 カ）ハヤシザイモクテン — company/tokushoho/faq/product/order_complete で一致
- 法人のみ掛売り可（継続取引後・要審査と条件明記）— tokushohoと矛盾なし
- 発送: 入金確認後2〜5営業日 — index/faq/tokushoho/business/sample一致
- 返品: 初期不良・配送事故のみ7日以内、客都合不可 — tokushoho内で一貫
- 送料の説明（910mm=佐川、1,820mm以上=西濃、個人は支店止め）— shipping/products一致（計算機バグは修正済み、order.htmlのみ要判断#1）

### 価格・規格（data/products.js を正として）
- products.html / order.html / product.html / cart.js は全て products.js から動的生成（HP税込=Yahoo税込×0.95ルールで統一）。ハードコード価格の食い違いなし
- 「12mm・15mm同価格」表記は products.js の実データと一致（全長さ・全等級で同額を検算）
- **12mm規格の矛盾記載なし**: products.js には flooring12 が現存しており、12mmに言及する全ページ（products/order/sample/contact/company/faq）はデータと整合。「15mmに統合」を前提とした記載・古い12mm単独価格の残存はなし
- グレード定義（節有/小節/特上小/無節、小節=埋木20mm以下2〜3個等）— index/faq/sample/products.jsで一貫
- 在庫表示: products.html・outlet.html とも stock.json / outlet.json 連動を確認

### キャンペーン・LINE・サンプル
- photo.html 謝礼: **Amazonギフトカード300円分で統一**。500円等の旧記載なし。Amazon商標注記・スポンサー否定文・noindex 設定あり
- LINEリンク: 全11箇所（ヘッダー/フッター/フローティング/各CTA）すべて https://line.me/R/ti/p/@352ngeni。旧 lin.ee 系の残存ゼロ
- 無料サンプル: sample / contact / faq で内容一致（約110×300mm・3種×4グレード選択・無料・送料無料）。faq からの contact.html#sample アンカーも実在・JS連動確認

### 表現コンプライアンス
- 「林材木店様」「日本一」「最安値」「業界最安」等の根拠なき最上級表現 — なし
- 「カスタム加工」— なし。「現場直送」1件のみ → 修正済み
- 薬機法リスク（「アトピーに効く」等の効能断定）— なし。抗菌・調湿・リラックスは「〜とされる」「報告されている」の範囲（index修正後）
- 桧の主張（調湿・耐久性・社寺建築の実績・飴色への経年変化・α-ピネン）— docs/blog_research_2026-06-12.md と整合

### 技術検証
- 内部リンク・画像: 対象全ページで href/src の実在確認 — 切れなし（blog.html のjpg欠落のみ→要判断#6）
- blog.html → blog/ 配下22記事へのリンク全て実在。index.html のブログカード3件も実在
- canonical / og:url: 全ページでファイル名と一致。title/description の重複なし
- sitemap.xml: 記載URL全て実在（blog22本含む）。cart.html矛盾は修正済み
- robots.txt: 適切（photo.htmlはmetaタグでnoindex）
- markets.html: 取扱店50件 = JSON-LD numberOfItems 50 で一致
- 修正後の全インラインJSを構文チェック（node）し、shipping.html はブラウザで実動作検証済み

---

## 4. 検証メモ

- 本番環境は Xserver（nginx応答を確認）。Cloudflare Pages 用の _redirects / functions/ は無効化状態
- フォーム修正（#1）は本番反映（git push）が必要。push は今回のセッションでは未実施（git操作禁止の指示のため）
