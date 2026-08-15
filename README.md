# Kurage Light ChatBot（klchatbot）

**1ファイルのナレッジチャットAI。** `sources/` フォルダにMarkdownを置くだけで、
その内容に基づいて答えるチャットボットがレンタルサーバーで動きます。

- **FAQボット / AI接客** … お客様の質問に24時間答える(`KLC_AUTH='public'`)
- **社内ChatGPT** … 社内マニュアルを学習した合言葉制の窓口(`KLC_AUTH='password'`)

デモ: https://proto.exbridge.jp/klchatbot/ （Kurageの商品知識を積んだ実物）

## 特徴

- **本体は `klchatbot.php` 1ファイル**(約550行)。DBサーバー・Composer・npm不要
- PHPが動くレンタルサーバーにFTPで上げるだけ
- LLMは**OpenAI互換APIなら何でも**(既定はDeepSeek。1回答1円前後の実費)
- 知識は固定順序でプロンプト先頭に注入=**プロバイダのコンテキストキャッシュが効く**設計
- ストリーミング表示・IPレート制限・質問ログ(IPハッシュ化)・質問例チップ
- MIT License。改変・商用利用・再販自由

## 使い方(3手順)

1. `klchatbot_config.php.example` を `klchatbot_config.php` にコピーし、APIキーとお店の情報を書く
2. `sources/` にFAQ・商品一覧などのMarkdownを置く
3. `klchatbot.php` ごとサーバーにアップロード → ブラウザで開く

詳しくは `docs/01_setup.md`。カスタマイズは `docs/02_customize.md`
(Claude Code などのAIエージェントに頼む前提の手引きです。`skills/` に同梱のスキルもあります)。

## 検証

```bash
php scripts/check_klchatbot.php
```

## ライセンス

MIT License。© EXBRIDGE, Inc.
