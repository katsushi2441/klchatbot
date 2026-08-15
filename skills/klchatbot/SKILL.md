---
name: klchatbot
description: Kurage Light ChatBot(1ファイルのナレッジチャットAI)の設置・知識追加・カスタマイズを安全に行う
---

# Kurage Light ChatBot 操作スキル

## このスキルを使うとき

klchatbot(klchatbot.php)の設置・設定変更・知識ベース更新・トラブル対応を頼まれたとき。

## 手順

1. まず `docs/02_customize.md` を読む(安全な変更方法の一覧)
2. 設定変更は `klchatbot_config.php` のみで完結させる(本体改変は最後の手段)
3. 知識更新は `sources/` に .md を**追加**する(既存ファイルのリネーム禁止=キャッシュ保護)
4. 変更後は必ず `php scripts/check_klchatbot.php` を実行し、全チェック通過を確認
5. デプロイはFTPで `klchatbot.php`・`klchatbot_config.php`・`sources/` を上げる

## 鉄則

- KLC_API_KEY を画面・ログ・コミットに出さない
- sources/ には公開してよい情報だけ(ボットは聞かれたら答える)
- 検証エラーは4xx。klc_data/ の書き込みはflock内で
