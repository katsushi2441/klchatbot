# カスタマイズの手引き(AIエージェント向け)

このファイルは、Claude Code などのAIエージェントがこの製品を安全に
カスタマイズするための手引きです。人間の方は「AIにこのファイルを見せて頼む」だけでOKです。

## 構成

- `klchatbot.php` … 本体1ファイル。UI(HTML/CSS/JS)+API中継+レート制限+ログの全部
- `klchatbot_config.php` … 設定。**まずここを触る。本体の変更は最後の手段**
- `sources/*.md` … 知識ベース。名前順に読み込まれる

## よくある依頼と安全なやり方

| 依頼 | やること |
|---|---|
| 知識を増やす | `sources/` に新しい.mdを**追加**(既存のリネームはキャッシュが切れるので避ける) |
| 性格・口調を変える | `KLC_SYSTEM_PROMPT` を書き換え |
| 色・タイトル変更 | `KLC_BRAND_COLOR` / `KLC_TITLE` など設定のみ |
| 社内用に切替 | `KLC_AUTH='password'` + パスワード設定 |
| モデル変更 | `KLC_API_BASE` / `KLC_MODEL` / `KLC_API_KEY` (OpenAI互換ならどこでも) |
| 質問ログを見たい | `klc_data/log.jsonl` (JSONL。IPはハッシュ化済み) |

## 本体を改変するときの鉄則

1. **知識注入の順序を壊さない** … `klc_knowledge()` はファイル名昇順で固定。
   ここが揺れるとAPIのコンテキストキャッシュが切れ、応答が遅く・高くなる
2. **検証エラーは4xxで返す** … 5xxにすると外形監視が障害と誤判定する
3. **klc_data/への書き込みはflockの中で読み直してから** … 同時アクセスで壊さない
4. 変更後は `php scripts/check_klchatbot.php` で検証してからデプロイ
