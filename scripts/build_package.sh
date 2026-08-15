#!/usr/bin/env bash
# kappstore で配布するzipを作る。設定の実物・APIキー・ログは入れない。
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p outputs
stamp=$(date +%Y%m%d)
zip="outputs/klchatbot-${stamp}.zip"
rm -f "$zip"
zip -r "$zip" \
  public/klchatbot.php public/klchatbot_config.php.example \
  public/klc_data/.htaccess public/sources/.htaccess public/sources/README.md \
  demo/sources \
  scripts/make_password_hash.php scripts/check_klchatbot.php \
  skills docs README.md LICENSE \
  -x '*.log' -x '*log.jsonl' >/dev/null
echo "built: $zip ($(du -h "$zip" | cut -f1))"
