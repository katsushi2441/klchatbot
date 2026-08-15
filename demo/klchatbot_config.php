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
));

define('KLC_API_BASE', 'https://api.deepseek.com');
define('KLC_API_KEY',  '__KLC_API_KEY__');
define('KLC_MODEL',    'deepseek-chat');
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
