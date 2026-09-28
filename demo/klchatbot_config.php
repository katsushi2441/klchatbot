<?php
/**
 * デモ環境(https://proto.exbridge.jp/klchatbot/)用の設定。
 * APIキーはデプロイ時に scripts/deploy_demo.sh が注入する(このリポジトリには置かない)。
 */
define('KLC_TITLE',       'Kurage Light ChatBot デモ');
define('KLC_SUBTITLE',    'Kurageの製品・サービスを学習したAIが答えます(この画面が製品そのものです)');
define('KLC_WELCOME',     "こんにちは。Kurage Light ChatBot のデモです。\nこのボットにはKurageの商品カタログ・代理店制度が知識として入っています。\n「予約システムはいくら？」「代理店の手数料は？」など、お試しください。");
define('KLC_BRAND_COLOR', '#0a8f8f');
define('KLC_EXAMPLES', array(
    '予約システムはいくらで買える？',
    '代理店の手数料は何%？',
    'AIチャットボットを自社サイトに置きたい',
    '名古屋市中川区は今、避難が必要？',
));

// デモの AI は gemma4（当社サーバー）。**デモで DeepSeek を使わない**（有料サービス専用）。
// heteml から社内の Ollama に届かないので、当社サーバーの共通の gemma4 中継（kaima/relay・:18343）を通す。
// 製品ごとに中継のポートを増やさない。キーは中継の合言葉で、deploy_demo.sh が kaima/.env から入れる（リポジトリに置かない）。
define('KLC_API_BASE', 'http://exbridge.ddns.net:18343/v1');
define('KLC_API_KEY',  '__KLC_API_KEY__');
define('KLC_MODEL',    'gemma4:12b-it-qat');
define('KLC_TEMPERATURE', 0.5);
define('KLC_MAX_TOKENS',  900);

define('KLC_SYSTEM_PROMPT',
    'あなたは株式会社エクスブリッジの「Kurage」シリーズの案内係です。丁寧で簡潔な日本語で答えます。' .
    '知識ベースの価格・URL・手数料率は一字一句正確に引用します。' .
    '購入はKurage App Store、自社仕様の開発はバイブプロトタイピング(110,000円税込)、' .
    '無料相談は https://kurage.exbridge.jp/chat.php を案内します。' .
    '知識に無いことは正直に「資料にありません」と答え、https://kurage.exbridge.jp/ を案内します。');

define('KLC_SOURCES_DIR',  __DIR__ . '/sources');
define('KLC_KB_MAX_BYTES', 200000);

define('KLC_AUTH', 'public');
define('KLC_PASSWORD', '');
define('KLC_PASSWORD_HASH', '');

define('KLC_RATE_PER_HOUR', 10);
define('KLC_INPUT_MAX', 300);
define('KLC_HISTORY_MAX', 4);
define('KLC_STREAM', true);
define('KLC_LOG', true);
define('KLC_DEMO', true);

// ---- 答え方・上限・道具（2026-09-29 追加） ----
define('KLC_CITE', true);
define('KLC_ASK_BACK', true);
define('KLC_DAILY_TOKENS', 1500000);   // デモ全体で1日150万トークンまで（GPU を占有しすぎない）
define('KLC_DAILY_REQUESTS', 300);
// 道具の例：当社の防災AIチャットの API を呼ぶ（別のシステムの API を、チャットから使えることを見せる）
define('KLC_TOOLS', array(
    array(
        'name'        => 'bousai',
        'label'       => '防災情報',
        'description' => '日本の住所について、いまの警報・キキクル・台風・避難情報を調べ、避難が必要かを規則で判定した結果を返す。'
                       . '防災・避難・台風・大雨・川・津波・土砂の質問のときだけ使う。',
        'url'         => 'https://kurage.exbridge.jp/kbousai.php/api/ask?q={address}&msg={question}',
        'params'      => array('address' => '住所（都道府県から。例: 愛知県名古屋市中川区）', 'question' => '利用者の質問そのまま'),
        'pick'        => 'answer.lines',
        'max_chars'   => 2000,
    ),
));
define('KLC_TOOL_MAX_CALLS', 2);
