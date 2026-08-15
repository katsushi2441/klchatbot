#!/usr/bin/env bash
# デモの公開。https://proto.exbridge.jp/klchatbot/
# APIキーはこのスクリプトが kcbrain/.env から注入する(リポジトリに置かない)。
set -euo pipefail
cd "$(dirname "$0")/.."
set -a; . /home/kojima/work/aixec/.env; set +a
KEY=$(grep -m1 '^KCBRAIN_DEEPSEEK_API_KEY=' /home/kojima/work/kcbrain/.env | cut -d= -f2)
[ -n "$KEY" ] || { echo "DeepSeekキーが見つからない" >&2; exit 1; }
remote="/web/proto_exbridge_jp/klchatbot"
up() { curl --fail --silent --show-error --ftp-create-dirs -T "$1" \
  "ftp://${FTP_USER}:${FTP_PASS}@${FTP_HOST}${remote}/${2}"; echo "up: $2"; }
tmp=$(mktemp)
sed "s/__KLC_API_KEY__/${KEY}/" demo/klchatbot_config.php > "$tmp"
up public/klchatbot.php klchatbot.php
up "$tmp" klchatbot_config.php
rm -f "$tmp"
up demo/index.php index.php
up demo/.htaccess .htaccess
up public/klc_data/.htaccess klc_data/.htaccess
up public/sources/.htaccess sources/.htaccess
for f in demo/sources/*.md; do up "$f" "sources/$(basename "$f")"; done
echo "published: https://proto.exbridge.jp/klchatbot/"
