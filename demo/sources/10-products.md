# Kurage App Store 商品一覧（販売代理店向け）

Kurageの業務システムを販売するダウンロードストア。全商品ソース同梱・AIエージェントで改変拡張可。

## 商品

### 宣伝OK掲示板（kbbs）
- 価格: 55,000円(税込)
- 商品ページ(LP): https://kappstore.exbridge.jp/app.php?id=4bd9a6f3f99cdc05
- デモ: https://kurage.exbridge.jp/kbbs.php
- ライセンス: MIT
- 概要: 宣伝OK・リンクOKの1ファイルPHP掲示板。投稿には宣伝URLと同じドメインのメールアドレスが必須＝その会社の本人しか投稿できず、スパムにならない。投稿者には被リンク、設置者には見込み客リストが残る。DB不要・FTPで置くだけ・MIT。

### アプリランチャー（Kurage Capacitor Launcher）
- 価格: 無料
- 商品ページ(LP): https://kappstore.exbridge.jp/app.php?id=e8d0518e44c29a60
- デモ: https://kclauncher.exbridge.jp/
- ライセンス: MIT
- 概要: Kurageのカレンダー・動画・ゲーム・新着アプリを、スマホのホーム画面から1タップで開ける汎用ランチャーです。開くURLで中身が変わるので、自分のサーバーのカレンダー(kcaldav)を指定すれば、PCのThunderbird・iPhoneの標準カレンダー・Android(DAVx5経由でGoogleカレンダーアプリ)と、同じ予定を共有できます。iPhoneはSafariの「ホーム画面に追加」でアプリ不要(PWA)、AndroidはAPKを配布。無料・入手は公式サイトから。

### カレンダー同期（kcaldav）
- 価格: 55,000円(税込)
- 商品ページ(LP): https://kappstore.exbridge.jp/app.php?id=43950141618ddb02
- デモ: https://proto.exbridge.jp/kcaldav/kcaldav.php
- ライセンス: MIT
- 概要: 同じ予定表を、ブラウザ・スマホ・PCのどこからでも読み書きできる1ファイルのCalDAVサーバーです。付属のWEBカレンダー画面を使えば、アプリを入れなくてもスマホのブラウザから予定を追加・編集・削除できます。さらに、iPhoneの標準カレンダー（アプリ不要）、AndroidのKashCalやDAVx5、PCのThunderbirdといった標準のカレンダーアプリともCalDAVで同期でき、どこから足した予定も全部に反映されます。sabre/davのような巨大フレームワークは使わず、CalDAVで実際に必要な処理だけを素のPHPで実装（約600行）。保存はSQLite1ファイル、データベースサーバー不要。PHPが動くレンタルサーバーにFTPで置くだけ。ユーザー・カレンダー・パスワードは設定ファイルで宣言し、宣言外は触れません。設定変更や機能追加は同梱のClaude Code向け設計マニュアルでAIエージェントに頼めます。MITライセンス・買い切り・デモを触ってから購入できます。

### 予約・受付システム（kreserve）
- 価格: 55,000円(税込)
- 商品ページ(LP): https://kappstore.exbridge.jp/app.php?id=362c94ab4e1384f2
- デモ: https://proto.exbridge.jp/kreserve/
- ライセンス: MIT
- 概要: サロン・クリニック・士業向けの予約ページ＋管理画面。お客様はメニュー→日→時間→お名前の4ステップで予約でき、キャンセル用URLと確認メールを自動発行。お店側は日別一覧・検索・取消/復活・電話予約の手動追加・月別CSVが使えます。空き枠の判定と予約の書き込みを同じロック内で行うため、同時アクセスでもダブルブッキングが起きません。メニュー・営業時間・定休日・受付ルールは設定ファイルに集約し、Claude Code等のAIエージェントに頼んで安全に変更できる設計マニュアル同梱。PHPのみ・DB不要・レンタルサーバーにFTPで置くだけ。約800行の「全部読めるサイズ」。MIT License。

### データベース管理ツール（kdbagent）
- 価格: 55,000円(税込)
- 商品ページ(LP): https://kappstore.exbridge.jp/app.php?id=719429354f079793
- デモ: https://proto.exbridge.jp/kdbagent/
- ライセンス: MIT
- 概要: 1つのPHPファイルで、データベースの中身を安全に参照・検索・編集できるツールです。phpMyAdminのような万能ツールとは逆に、設定で宣言した「表・列・操作」だけを触らせます。宣言していない表・列・削除などは、ブラウザからもコマンドからも実行できません。だからAIエージェント（Claude Code）に「顧客の電話番号を直しておいて」と任せても、範囲より外は壊せません。MySQL/SQLite両対応、依存ライブラリなし、レンタルサーバーに置くだけ。買い切り・ソースコード改変自由（MIT）。

### AIアクセス解析（ktrackgeo）＋ VWork教材
- 価格: 55,000円(税込)
- 商品ページ(LP): https://kappstore.exbridge.jp/app.php?id=48ca584977698dcc
- デモ: https://proto.exbridge.jp/ktrackgeo/
- ライセンス: MIT
- 概要: Google Analytics はボットを除外するため、GPTBot や ClaudeBot が自社サイトをどれだけ読んでいるかが見えません。ktrackgeo は「AIに読まれる状態か」を診断し、「実際にAIが来たか」を実測します。DB不要・外部API不要、PHPが動くレンタルサーバーにFTPで置くだけ。データは自社サーバーから出ません。

### 情報監視システム（kcheckit）＋ VWork教材
- 価格: 55,000円(税込)
- 商品ページ(LP): https://kappstore.exbridge.jp/app.php?id=232b3c1d11b6a730
- デモ: https://proto.exbridge.jp/kcheckit/
- ライセンス: MIT
- 概要: 自社サイトのURLと、見ておきたいサイトのURLを登録しておくだけ。官公庁の新着・補助金の締切・法令の改正・脆弱性情報を毎日拾い、自社の事業に関係しそうなものだけを理由つきでメールに知らせます。RSS・API・HTMLの差分・SSL証明書の期限の4方式に対応。データベース不要、FTPで上げるだけ。cronが無くてもボタンで動きます。MIT License。

### 請求書発行・集金システム（kbilling）＋ VWork教材
- 価格: 55,000円(税込)
- 商品ページ(LP): https://kappstore.exbridge.jp/app.php?id=15abb025dc2ee4f6
- デモ: https://proto.exbridge.jp/kbilling/
- ライセンス: MIT
- 概要: 請求書をPDFで発行し、専用URLをメールで送って、銀行振込・PayPalで支払ってもらうシステム。明細12行・インボイス対応。PayPalの入金はサーバー側で裏取りしてから記録します。DB不要・PHPのみ。AIに渡せば設置まで進む手順書つき。

### 商品注文・請求書・決済ページ（kpaylink）＋ VWork教材
- 価格: 55,000円(税込)
- 商品ページ(LP): https://kappstore.exbridge.jp/app.php?id=5b55bb2c808eead4
- デモ: https://proto.exbridge.jp/kpaylink/
- ライセンス: MIT
- 概要: 価格の決まった商品・サービスの注文ページを設定ファイル1枚で立ち上げます。お客様が注文すると請求書PDFが発行され、銀行振込・PayPalで支払えます。ログイン不要・DB不要・PHPのみ。実運用中の受注ページを製品化したものです。

### 領収書メール送信システム（kinvoice）＋ VWork教材
- 価格: 55,000円(税込)
- 商品ページ(LP): https://kappstore.exbridge.jp/app.php?id=61febea74f9c74b0
- デモ: https://proto.exbridge.jp/kinvoice/
- ライセンス: MIT
- 概要: 領収書をPDFで発行し、ダウンロードURLをメールで送ります。PDFは添付せず、宛先メールアドレスの確認を通った人だけが受け取れます。DB不要・PHPのみ。AIに渡せば設置まで進む手順書と、実際に踏んだ6つの罠の記録つき。
