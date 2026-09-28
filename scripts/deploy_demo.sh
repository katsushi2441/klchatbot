#!/usr/bin/env bash
# デモの公開。https://proto.exbridge.jp/klchatbot/
# デモの AI は gemma4。当社サーバーの共通の gemma4 中継(kaima/relay・:18343)の合言葉を kaima/.env から注入する(リポジトリに置かない)。
# **デモで DeepSeek を使わない**(有料サービス専用。2026-09-29 まで DeepSeek のキーを入れていて叱責された)。
set -euo pipefail
cd "$(dirname "$0")/.."
set -a; . /home/kojima/work/aixec/.env; set +a
KEY=$(grep -m1 '^RELAY_CLIENT_KLCHATBOT=' /home/kojima/work/kaima/.env | cut -d= -f2)
[ -n "$KEY" ] || { echo "中継の合言葉が見つからない(kaima/.env の RELAY_CLIENT_KLCHATBOT)" >&2; exit 1; }
grep -q "api.deepseek.com" demo/klchatbot_config.php && { echo "デモの設定が DeepSeek を向いている。止める" >&2; exit 1; }
curl -s -m 10 http://127.0.0.1:18343/healthz | grep -q klchatbot || { echo "共通の gemma4 中継(18343)が動いていない、または klchatbot が登録されていない" >&2; exit 1; }
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
