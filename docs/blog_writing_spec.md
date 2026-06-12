# 林材木店ブログ執筆仕様書（2026-06-12 リニューアル）

全執筆エージェント共通ルール。**この仕様と `docs/blog_research_2026-06-12.md`（調査ファイル）に従うこと。**

## 最重要：正確性

- 事実・数値・手順・可否判断（例: 壁際クリアランス5〜10mm、ロス率5〜10%、水拭きロボ掃除機NG）は**調査ファイルに記載があるものだけ**を基本とする。
- 記事の根幹となる重要な数値・断定は、調査ファイル記載の情報源URLをWebFetchで実際に確認し、**2ソース以上で裏が取れたもののみ断定形**で書く。裏が取れない・サイト間で見解が割れるものは「〜とされています」「両方の考え方があります」と幅を持たせる。
- 捏造禁止: 統計値・実験データ・お客様の声・施工事例数などをでっち上げない。
- 自社（林材木店）に関する事実は `data/products.js`・既存HTML（index.html等）に書かれていることのみ。自社価格・商品仕様を創作しない。
- ヒノキチオールに言及する場合は「国産桧にはほぼ含まれない（タイワンヒノキ・ヒバの成分）。国産桧の抗菌成分はα-カジノール等」という正確な記述にすること（調査ファイル1-7参照）。

## 表現ルール（過去の決定事項）

- 「現場直送」「カスタム加工対応」は書かない。
- 価格を主役にしない（安さ訴求一辺倒にしない。品質・専門性が主役）。
- サンプル案内は「できます」トーン（押し売りしない）。
- 自社名は「林材木店」（「林材木店様」と書かない）。
- 電話番号: 0538-58-2395。無料サンプル: `../sample.html`。お問い合わせ: `../contact.html`。商品一覧: `../products.html`。
- 読者へのDIY説明はOK。ただし「プロに相談すべきケース」（床暖房・著しい下地不良・マンション防音規定）への誘導を必ず入れる。
- ストック写真・Unsplash禁止。画像は使わず、既存記事と同様のSVG・CSSボックス表現でよい。

## 技術仕様

- **ファイル書き込みは必ずWriteツール**（PowerShellでの日本語書き込みは文字化けするため絶対禁止）。文字コードUTF-8。
- テンプレート: `blog/diy-flooring-tips.html` の構造を踏襲（head: title/description/canonical/OGP/Twitter/フォント/`../css/style.css?v=5`/記事用style/JSON-LD Article、body: `#site-header`→breadcrumb→`article.article-wrap`→CTA→`#site-footer`→scripts 3本 `../data/products.js` `../js/cart.js` `../js/components.js?v=6`）。
- canonical/OGP URLは `https://hayazai.com/blog/＜ファイル名＞`。
- JSON-LD: 新規記事は `"datePublished":"2026-06-12"`。既存記事の改善は元のdatePublishedを残し `"dateModified":"2026-06-12"` を追加。本文メタの日付表示も同様（既存は「2026年6月12日更新」を追記）。
- 文字量目安: 本文2,500〜4,500字。h2を4〜6個、必要に応じてstep-card/warning-box/比較テーブルを使う。読了目安を article-meta に記載。
- 比較表は `<table>` にインラインstyleでよい（既存CSSにテーブル専用クラスなし）。スマホで崩れないよう `overflow-x:auto` のラッパを付ける。
- **blog.html と sitemap.xml は編集しない**（統合担当が後でまとめて更新する）。
- 自分の担当ファイル以外は編集しない。

## カテゴリ（記事内 .article-cat とbreadcrumb末尾に使用）

| カテゴリ名 | 対象 |
|---|---|
| 購入前ガイド | デメリット・後悔、無垢vs複合、樹種比較 |
| 商品知識・規格 | グレード、UNI/OPC/乱尺、厚み、塗装、羽目板 |
| 計算・見積もり | 必要枚数、費用 |
| DIY・施工 | 工法、道具、重ね張り、和室改装、マンション |
| お手入れ・メンテナンス | 掃除、オイル、家電 |
| トラブル対処 | 傷、隙間、シミ・カビ |
| 桧の魅力・産地 | 桧の特長、天竜桧、磐田直販 |

## 全記事スラッグ一覧（内部リンク用・確定）

新規:
- blog/hinoki-demerit.html — 桧フローリングのデメリット7つを工場が正直に解説（購入前ガイド）
- blog/muku-vs-fukugou.html — 無垢と複合フローリングどっちを選ぶ？（購入前ガイド）
- blog/sugi-vs-hinoki.html — 杉と桧のフローリング徹底比較（購入前ガイド）
- blog/uni-opc-ranjaku.html — UNI・OPC・乱尺とは？規格と価格差（商品知識・規格）
- blog/thickness-15-30.html — 15mm厚と30mm厚の違い・根太レス30mm（商品知識・規格）
- blog/finish-mutosou-oil-urethane.html — 無塗装・オイル・ウレタンの選び方（商品知識・規格）
- blog/diy-ng-10.html — DIYでやってはいけない10のこと（DIY・施工）
- blog/kasanebari-diy.html — 既存床に重ね張りDIY・ドア干渉対策（DIY・施工）
- blog/tatami-to-flooring.html — 畳の和室を桧フローリングに（DIY・施工）
- blog/kugi-screw-bond.html — フロア釘・ビス・ボンドの使い分け（DIY・施工）
- blog/mansion-ll45.html — マンションLL45を無垢でクリアする方法（DIY・施工）
- blog/hekomi-iron-repair.html — 凹み傷のアイロン補修手順（トラブル対処）
- blog/sukima-sori-tsukiage.html — 隙間・反り・突き上げの原因と「1年待つ」（トラブル対処）
- blog/shimi-kabi-cleaning.html — シミ・カビ・黒ずみの落とし方【塗装別】（トラブル対処）
- blog/robot-cleaner-kaden.html — ロボット掃除機・水拭き・加湿器はOK？（お手入れ・メンテナンス）

既存（改善対象）:
- blog/diy-flooring-tips.html（DIY・施工）
- blog/how-to-choose-grade.html（商品知識・規格）
- blog/hinoki-flooring-care.html（お手入れ・メンテナンス）
- blog/flooring-vs-panel.html（商品知識・規格）
- blog/hinoki-benefits.html（桧の魅力・産地）
- blog/how-many-sheets.html（計算・見積もり）
- blog/shizuoka-hinoki-shop.html（桧の魅力・産地）

## 内部リンク

- 各記事の本文末尾（CTAの直前）に「あわせて読みたい」として関連記事3〜4本をリンク（`<ul>`でよい）。リンク先は上記スラッグ一覧から、内容的に関連が強いものを選ぶ。
- 調査ファイルの戦略メモ（デメリット記事→比較→商品知識→計算→商品ページの導線）を意識する。
- 商品への自然な導線: 桧の話では `../products.html`、購入検討文脈では `../sample.html`。

## 担当外のこと（やらない）

- blog.html・sitemap.xml の更新（統合担当）
- git commit / push（統合担当）
- 画像生成
