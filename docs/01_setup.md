# 設置手順

## 必要なもの

- PHPが動くレンタルサーバー(PHP 7.4以上・curl有効。一般的な共用サーバーでOK)
- OpenAI互換APIのキー(推奨: DeepSeek https://platform.deepseek.com で無料登録→キー発行。
  クレジット課金・1回答1円前後の実費)

## 手順

1. `public/klchatbot_config.php.example` を `klchatbot_config.php` という名前でコピー
2. `klchatbot_config.php` を編集
   - `KLC_API_KEY` … あなたのAPIキー
   - `KLC_TITLE` / `KLC_WELCOME` / `KLC_SYSTEM_PROMPT` … お店・会社に合わせて
3. `sources/` フォルダにFAQ・商品一覧などのMarkdownを置く(サンプルはdemo/sources/参照)
4. `public/` の中身をサーバーの公開フォルダへFTPアップロード
   - `klchatbot.php` / `klchatbot_config.php` / `klc_data/` / `sources/`
5. ブラウザで `https://あなたのドメイン/klchatbot.php` を開く

## 社内ChatGPTとして使う場合

`KLC_AUTH` を `'password'` にし、`KLC_PASSWORD`(または `scripts/make_password_hash.php` で
作ったハッシュを `KLC_PASSWORD_HASH`)を設定してください。合言葉を知る社員だけが使えます。
`sources/` に社内マニュアルを置きます(**サーバー外に出したくない文書は置かない**判断も含めて)。

## うまく動かないとき

- 画面は出るが回答でエラー → `KLC_API_KEY` を確認。残高も確認
- 回答が一気に出る(逐次表示されない) → サーバーのバッファリング。`KLC_STREAM` を `false` に
- 「ご利用が集中しています」 → レート制限。`KLC_RATE_PER_HOUR` を調整
