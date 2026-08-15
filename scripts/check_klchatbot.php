<?php
/**
 * klchatbot の検証。デプロイ前に実行する:  php scripts/check_klchatbot.php [設定ファイル]
 * 引数省略時は demo/klchatbot_config.php を使う(リポジトリ内で完結して検証できるように)。
 */
error_reporting(E_ALL);
$root = dirname(__DIR__);
$cfg = isset($argv[1]) ? $argv[1] : $root . '/demo/klchatbot_config.php';

$pass = 0; $fail = 0;
function ok($name, $cond, $note = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  $name\n"; }
    else { $fail++; echo "  NG  $name" . ($note ? " ($note)" : '') . "\n"; }
}

echo "== klchatbot check ==\n";
echo "config: $cfg\n";

ok('本体 klchatbot.php が存在', is_file($root . '/public/klchatbot.php'));
ok('本体のPHP構文', shell_exec('php -l ' . escapeshellarg($root . '/public/klchatbot.php') . ' 2>&1 && echo OKOK') !== null
    && strpos((string)shell_exec('php -l ' . escapeshellarg($root . '/public/klchatbot.php') . ' 2>&1'), 'No syntax errors') !== false);
ok('設定ファイルが存在', is_file($cfg));

require $cfg;
// 本体の関数だけ読み込む(画面は実行しない)
define('KLC_CHECK_MODE', 1);
$src = file_get_contents($root . '/public/klchatbot.php');
$funcs = substr($src, 0, strpos($src, '/* ================= API: 質問'));
$funcs = str_replace("require_once __DIR__ . '/klchatbot_config.php';", '', $funcs);
eval('?>' . $funcs);

ok('KLC_TITLE 定義', defined('KLC_TITLE'));
ok('KLC_API_BASE 定義', defined('KLC_API_BASE'));
ok('KLC_MODEL 定義', defined('KLC_MODEL'));
ok('KLC_SYSTEM_PROMPT 定義', defined('KLC_SYSTEM_PROMPT'));
ok('KLC_SOURCES_DIR が実在', defined('KLC_SOURCES_DIR') && is_dir(KLC_SOURCES_DIR));

$kb = klc_knowledge();
ok('知識ベースが読める', $kb !== '', 'sources/に.mdを置く');
ok('知識ベースが上限内', strlen($kb) <= (defined('KLC_KB_MAX_BYTES') ? KLC_KB_MAX_BYTES : 200000));
$sys = klc_system_prompt();
ok('システムプロンプト組立', strlen($sys) > strlen($kb));
$kb2 = klc_knowledge();
ok('知識の順序が安定(キャッシュ前提)', $kb === $kb2);

$msgs = klc_messages('テスト質問', array(array('u', 'こんにちは'), array('a', 'どうも')));
ok('メッセージ組立(system+履歴+質問)', count($msgs) === 4 && $msgs[0]['role'] === 'system'
    && $msgs[3]['content'] === 'テスト質問');

ok('payload生成', strpos(klc_payload($msgs, false), '"model"') !== false);
ok('レート上限が正の数', klc_rate_limit_max() > 0);
ok('入力上限が正の数', klc_input_max() > 0);
ok('klc_data/.htaccess(直接閲覧の遮断)', is_file($root . '/public/klc_data/.htaccess'));
ok('sources/.htaccess(直接閲覧の遮断)', is_file($root . '/public/sources/.htaccess'));

// レート制限の実挙動(テンポラリで)
if (!defined('KLC_DATA_DIR')) { define('KLC_DATA_DIR', sys_get_temp_dir() . '/klc_check_' . getmypid()); }
$ip = '203.0.113.9';
$first = klc_rate_ok($ip);
$okAll = true;
for ($i = 0; $i < klc_rate_limit_max() + 2; $i++) { $last = klc_rate_ok($ip); }
ok('レート制限が発動する', $first === true && $last === false);
@array_map('unlink', glob(KLC_DATA_DIR . '/*')); @rmdir(KLC_DATA_DIR);

// API疎通(キーが本物のときだけ)
if (defined('KLC_API_KEY') && KLC_API_KEY !== '' && strpos(KLC_API_KEY, '__') !== 0 && KLC_API_KEY !== 'sk-xxxx') {
    $ans = klc_ask_once(klc_messages('「疎通OK」とだけ答えて', array()));
    ok('API疎通(実回答)', is_string($ans) && $ans !== '', is_array($ans) ? $ans['error'] : '');
} else {
    echo "  --  API疎通はスキップ(キー未設定)\n";
}

echo "\n結果: {$pass} ok / {$fail} NG\n";
exit($fail ? 1 : 0);
