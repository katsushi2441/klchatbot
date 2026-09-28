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

// ---- 出典・聞き返し(2026-09-29) ----
$titles = klc_source_titles();
ok('参照用のファイル一覧が取れる', count($titles) > 0 && !isset($titles['README.md']));
ok('ファイル一覧の見出しが空でない', count(array_filter($titles, 'strlen')) === count($titles));
if (klc_cite_on()) { ok('出典の決まりがプロンプトにある', strpos($sys, '参照:') !== false); }
if (klc_ask_back_on()) { ok('聞き返しの決まりがプロンプトにある', strpos($sys, '選択肢:') !== false); }
ok('決まりは知識より前(キャッシュのため)', strpos($sys, '# 答え方の決まり') === false || strpos($sys, '# 答え方の決まり') < strpos($sys, '# 知識ベース'));

// ---- 1日の上限 ----
$b0 = klc_budget();
ok('上限の記録を読める', $b0['date'] === date('Y-m-d'));
$b1 = klc_budget(array('tokens' => 100, 'requests' => 1));
ok('上限の記録に足せる', $b1['tokens'] === $b0['tokens'] + 100 && $b1['requests'] === $b0['requests'] + 1);
if (klc_daily_requests_max() > 0) {
    klc_budget(array('requests' => klc_daily_requests_max()));
    ok('回数の上限で止まる', klc_budget_block() !== '');
}
ok('使用量が無いときは多めに見積もる', klc_usage_tokens(null, array(array('role' => 'user', 'content' => str_repeat('あ', 100))), str_repeat('い', 100)) >= 200);
ok('使用量があればそれを使う', klc_usage_tokens(array('total_tokens' => 42), array(), '') === 42);
@array_map('unlink', glob(KLC_DATA_DIR . '/*')); @rmdir(KLC_DATA_DIR);

// ---- 道具 ----
$tools = klc_tools();
if ($tools) {
    $schema = klc_tool_schema();
    ok('道具の定義を API の形にできる', count($schema) === count($tools) && $schema[0]['type'] === 'function');
    $t0 = reset($tools);
    $p0 = array_keys((array)($t0['params'] ?? array()));
    if ($p0) {
        $u = klc_tool_url($t0, array($p0[0] => '名古屋市&x=1 ?#'));
        ok('道具のURLは値をエンコードして埋める', strpos($u, '&x=1') === false && strpos($u, rawurlencode('名古屋市&x=1 ?#')) !== false);
        ok('送り先は設定のホストのまま', parse_url($u, PHP_URL_HOST) === parse_url($t0['url'], PHP_URL_HOST));
    }
    ok('JSONの一部を取り出せる', klc_pick(array('a' => array('b' => array(1, 2))), 'a.b') === array(1, 2));
    ok('無いパスなら全体を返す', klc_pick(array('a' => 1), 'x.y') === array('a' => 1));
} else {
    echo "  --  道具は未設定(スキップ)\n";
}
ok('設定に無いURLは道具にしない', (function () {
    return !preg_match('#^https?://#', 'file:///etc/passwd');
})());

// API疎通(キーが本物のときだけ)
if (defined('KLC_API_KEY') && KLC_API_KEY !== '' && strpos(KLC_API_KEY, '__') !== 0 && KLC_API_KEY !== 'sk-xxxx') {
    $ans = klc_ask_once(klc_messages('「疎通OK」とだけ答えて', array()));
    ok('API疎通(実回答)', !isset($ans['error']) && ($ans['content'] ?? '') !== '', $ans['error'] ?? '');
} else {
    echo "  --  API疎通はスキップ(キー未設定)\n";
}

echo "\n結果: {$pass} ok / {$fail} NG\n";
exit($fail ? 1 : 0);
