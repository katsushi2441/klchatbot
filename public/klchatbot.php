<?php
/**
 * Kurage Light ChatBot — 1ファイルのナレッジチャットAI。
 *
 * sources/ フォルダのMarkdownを「知識」として読み込み、その内容に基づいて
 * OpenAI互換API(既定: DeepSeek)が回答する。FAQボット・AI接客・社内ChatGPTを
 * この1ファイルで賄う。DBサーバー不要・レンタルサーバーのPHPで動く。
 *
 * 設計の要点:
 *  - 知識はシステムプロンプトの「固定プレフィックス」として毎回同一順序で注入する。
 *    DeepSeek等のコンテキストキャッシュが効き、2回目以降の入力単価が約1/30になる。
 *    (sources/の並びを不用意に変えるとキャッシュが切れる=遅く高くなる)
 *  - 検証エラーは4xxで返す(5xxで包むと外形監視が障害と誤判定する)。
 *  - レート制限・ログはファイル+flock。書き込みは同一ロック内で読み直してから行う。
 *
 * カスタマイズは klchatbot_config.php を編集(同梱のAI手引き docs/ 参照)。
 */
require_once __DIR__ . '/klchatbot_config.php';

/* ================= 内部ユーティリティ ================= */

function klc_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function klc_data_dir() {
    $d = defined('KLC_DATA_DIR') ? KLC_DATA_DIR : (__DIR__ . '/klc_data');
    if (!is_dir($d)) { @mkdir($d, 0755, true); }
    return $d;
}

function klc_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('KLCSESSID');
        session_start();
    }
}

function klc_json_out($code, $data) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function klc_demo() { return defined('KLC_DEMO') && KLC_DEMO; }

function klc_rate_limit_max() {
    $n = defined('KLC_RATE_PER_HOUR') ? (int)KLC_RATE_PER_HOUR : 30;
    if (klc_demo()) { $n = min($n, 10); }   // デモは常時控えめ(API費用の防波堤)
    return $n;
}

function klc_input_max() {
    $n = defined('KLC_INPUT_MAX') ? (int)KLC_INPUT_MAX : 800;
    if (klc_demo()) { $n = min($n, 300); }
    return $n;
}

/* ================= 認証(任意) ================= */

function klc_auth_mode() { return defined('KLC_AUTH') ? KLC_AUTH : 'public'; }

function klc_logged_in() {
    if (klc_auth_mode() !== 'password') { return true; }
    klc_session_start();
    return !empty($_SESSION['klc_ok']);
}

function klc_try_login($pw) {
    if (defined('KLC_PASSWORD_HASH') && KLC_PASSWORD_HASH !== '') {
        return password_verify($pw, KLC_PASSWORD_HASH);
    }
    if (defined('KLC_PASSWORD') && KLC_PASSWORD !== '') {
        return hash_equals(KLC_PASSWORD, $pw);
    }
    return false;
}

/* ================= レート制限(IP・1時間) ================= */

function klc_rate_ok($ip) {
    $f = klc_data_dir() . '/rate.json';
    $key = substr(hash('sha256', $ip . '|klc'), 0, 16);
    $now = time();
    $fp = fopen($f, 'c+');
    if (!$fp) { return true; }  // 記録不能時は通す(サービス継続優先)
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $all = $raw ? json_decode($raw, true) : array();
    if (!is_array($all)) { $all = array(); }
    $hits = isset($all[$key]) ? $all[$key] : array();
    $hits = array_values(array_filter($hits, function ($t) use ($now) { return $t > $now - 3600; }));
    $ok = count($hits) < klc_rate_limit_max();
    if ($ok) {
        $hits[] = $now;
        $all[$key] = $hits;
        // 古いIPエントリの掃除(肥大防止)
        foreach ($all as $k => $ts) {
            $ts = array_values(array_filter($ts, function ($t) use ($now) { return $t > $now - 3600; }));
            if ($ts) { $all[$k] = $ts; } else { unset($all[$k]); }
        }
        ftruncate($fp, 0); rewind($fp);
        fwrite($fp, json_encode($all));
    }
    flock($fp, LOCK_UN); fclose($fp);
    return $ok;
}

/* ================= 知識ベース ================= */

function klc_knowledge() {
    $dir = defined('KLC_SOURCES_DIR') ? KLC_SOURCES_DIR : (__DIR__ . '/sources');
    $cap = defined('KLC_KB_MAX_BYTES') ? (int)KLC_KB_MAX_BYTES : 200000;
    if (!is_dir($dir)) { return ''; }
    $files = glob(rtrim($dir, '/') . '/*.md');
    sort($files, SORT_STRING);   // 常に同一順序=キャッシュを効かせる要
    $out = '';
    foreach ($files as $f) {
        $body = (string)@file_get_contents($f);
        if ($body === '') { continue; }
        $chunk = "\n\n===== " . basename($f) . " =====\n" . trim($body) . "\n";
        if (strlen($out) + strlen($chunk) > $cap) { break; }
        $out .= $chunk;
    }
    return $out;
}

/** 知識ファイルの一覧 { "products.md": "見出し" }。画面の「参照した資料」に使う(存在するファイルだけを出すため) */
function klc_source_titles() {
    $dir = defined('KLC_SOURCES_DIR') ? KLC_SOURCES_DIR : (__DIR__ . '/sources');
    $out = array();
    if (!is_dir($dir)) { return $out; }
    $files = glob(rtrim($dir, '/') . '/*.md');
    sort($files, SORT_STRING);
    foreach ($files as $f) {
        $name = basename($f);
        if ($name === 'README.md') { continue; }
        $title = preg_replace('/\.md$/', '', $name);
        $fp = @fopen($f, 'r');
        if ($fp) {
            for ($i = 0; $i < 20 && ($line = fgets($fp)) !== false; $i++) {
                if (preg_match('/^#\s+(.+)$/u', trim($line), $m)) { $title = trim($m[1]); break; }
            }
            fclose($fp);
        }
        $out[$name] = mb_substr($title, 0, 60, 'UTF-8');
    }
    return $out;
}

function klc_cite_on() { return !defined('KLC_CITE') || KLC_CITE; }
function klc_ask_back_on() { return !defined('KLC_ASK_BACK') || KLC_ASK_BACK; }

function klc_system_prompt() {
    $sys = defined('KLC_SYSTEM_PROMPT') ? KLC_SYSTEM_PROMPT :
        'あなたは丁寧で簡潔な日本語アシスタントです。';
    // 答え方の決まり。**毎回同じ文**にしておく(知識の前に固定で置くのでキャッシュが切れない)
    $rules = array();
    if (klc_cite_on()) {
        $rules[] = '知識ベースを根拠に答えたときは、回答の最後の行に、根拠にしたファイル名を「参照: products.md, faq.md」の形で書く。'
                 . 'ファイル名は知識ベースの「===== ファイル名 =====」の名前をそのまま使う。知識ベースを使わなかったときは書かない。'
                 . 'ファイル名はこの最後の行にだけ書き、本文には書かない。';
    }
    if (klc_ask_back_on()) {
        $rules[] = '質問があいまいで、どう解釈するかで答えが大きく変わるときだけ、推測で答えずに短く聞き返す。'
                 . 'そのときは最後の行に「選択肢: 案A｜案B｜案C」の形で2〜4個の選択肢を書く(1つ20字以内)。はっきりした質問には聞き返さない。';
    }
    if (klc_tools()) {
        $rules[] = '道具(関数)で調べた結果は、そのまま根拠にしてよい。道具の結果に無いことを付け足さない。';
    }
    if ($rules) { $sys .= "\n\n# 答え方の決まり\n- " . implode("\n- ", $rules); }
    $kb = klc_knowledge();
    if ($kb !== '') {
        $sys .= "\n\n# 知識ベース(以下の内容だけを根拠に答える。価格・URL・数値は一字一句正確に引用する。知識に無いことは正直に「資料にありません」と答える)\n" . $kb;
    }
    return $sys;
}

/* ================= 1日の上限(トークン・回数) ================= */
// 公開のチャットは、誰かに大量に使われると料金が膨らむ(denial-of-wallet)。
// IPごとの回数制限に加えて、サイト全体で「1日にここまで」を決めておく。0 = 上限なし。

function klc_daily_tokens_max() { return defined('KLC_DAILY_TOKENS') ? (int)KLC_DAILY_TOKENS : 0; }
function klc_daily_requests_max() { return defined('KLC_DAILY_REQUESTS') ? (int)KLC_DAILY_REQUESTS : 0; }

/** $add が null なら読むだけ。配列 ['tokens'=>n,'requests'=>n] なら足して保存。返り値は今日の累計 */
function klc_budget($add = null) {
    $f = klc_data_dir() . '/budget.json';
    $today = date('Y-m-d');
    $fp = @fopen($f, 'c+');
    if (!$fp) { return array('date' => $today, 'tokens' => 0, 'requests' => 0); }
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $b = $raw ? json_decode($raw, true) : null;
    if (!is_array($b) || ($b['date'] ?? '') !== $today) { $b = array('date' => $today, 'tokens' => 0, 'requests' => 0); }
    if (is_array($add)) {
        $b['tokens'] += (int)($add['tokens'] ?? 0);
        $b['requests'] += (int)($add['requests'] ?? 0);
        ftruncate($fp, 0); rewind($fp);
        fwrite($fp, json_encode($b));
    }
    flock($fp, LOCK_UN); fclose($fp);
    return $b;
}

/** 上限に達していれば、利用者に見せる文。達していなければ '' */
function klc_budget_block() {
    $b = klc_budget();
    $tm = klc_daily_tokens_max(); $rm = klc_daily_requests_max();
    if (($tm > 0 && $b['tokens'] >= $tm) || ($rm > 0 && $b['requests'] >= $rm)) {
        return '本日のご利用が上限に達しました。明日あらためてお試しください。';
    }
    return '';
}

/* ================= 道具(ツール) ================= */
// 管理者が設定ファイル(KLC_TOOLS)に書いた HTTP GET だけを、AIが呼べる。送り先はAIが決めない。
//   array('name'=>'bousai', 'label'=>'防災情報', 'description'=>'…',
//         'url'=>'https://example.jp/api?q={address}', 'params'=>array('address'=>'住所'),
//         'pick'=>'answer.lines', 'max_chars'=>3000)

function klc_tools() {
    if (!defined('KLC_TOOLS') || !is_array(KLC_TOOLS)) { return array(); }
    $out = array();
    foreach (KLC_TOOLS as $t) {
        if (!is_array($t) || empty($t['name']) || empty($t['url'])) { continue; }
        if (!preg_match('/^[a-zA-Z0-9_]{1,40}$/', $t['name'])) { continue; }
        if (!preg_match('#^https?://#', $t['url'])) { continue; }
        $out[$t['name']] = $t;
    }
    return $out;
}

/** OpenAI互換の tools 定義 */
function klc_tool_schema() {
    $defs = array();
    foreach (klc_tools() as $t) {
        $props = array(); $req = array();
        foreach ((array)($t['params'] ?? array()) as $p => $desc) {
            $props[$p] = array('type' => 'string', 'description' => (string)$desc);
            $req[] = $p;
        }
        $defs[] = array('type' => 'function', 'function' => array(
            'name' => $t['name'],
            'description' => (string)($t['description'] ?? ($t['label'] ?? $t['name'])),
            'parameters' => array('type' => 'object', 'properties' => $props ?: new stdClass(), 'required' => $req),
        ));
    }
    return $defs;
}

/** URL の {param} を、AIが渡した値で埋める(値は必ずURLエンコード・200字まで) */
function klc_tool_url($t, $args) {
    $url = $t['url'];
    foreach ((array)($t['params'] ?? array()) as $p => $desc) {
        $v = isset($args[$p]) ? mb_substr((string)$args[$p], 0, 200, 'UTF-8') : '';
        $url = str_replace('{' . $p . '}', rawurlencode($v), $url);
    }
    return $url;
}

/** JSON の a.b.c をたどる。無ければ全体 */
function klc_pick($data, $path) {
    if (!$path) { return $data; }
    $cur = $data;
    foreach (explode('.', $path) as $k) {
        if (is_array($cur) && array_key_exists($k, $cur)) { $cur = $cur[$k]; }
        else { return $data; }
    }
    return $cur;
}

/** 道具を実行して、AIに渡す文字列を返す(失敗も文字列で返す) */
function klc_tool_run($t, $args) {
    $ch = curl_init(klc_tool_url($t, $args));
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => array('Accept: application/json', 'User-Agent: klchatbot-tool/1.0'),
    ));
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code >= 400) { return '取得できませんでした(HTTP ' . $code . ')'; }
    $j = json_decode($res, true);
    $val = $j === null ? $res : klc_pick($j, $t['pick'] ?? '');
    $txt = is_string($val) ? $val : json_encode($val, JSON_UNESCAPED_UNICODE);
    $max = (int)($t['max_chars'] ?? 3000);
    return mb_substr($txt, 0, $max > 0 ? $max : 3000, 'UTF-8');
}

/* ================= LLM呼び出し(OpenAI互換) ================= */

function klc_messages($q, $history) {
    $msgs = array(array('role' => 'system', 'content' => klc_system_prompt()));
    $maxTurn = defined('KLC_HISTORY_MAX') ? (int)KLC_HISTORY_MAX : 6;
    $history = array_slice(is_array($history) ? $history : array(), -$maxTurn);
    foreach ($history as $t) {
        if (!is_array($t) || count($t) < 2) { continue; }
        $role = $t[0] === 'a' ? 'assistant' : 'user';
        $msgs[] = array('role' => $role, 'content' => mb_substr((string)$t[1], 0, 2000, 'UTF-8'));
    }
    $msgs[] = array('role' => 'user', 'content' => $q);
    return $msgs;
}

function klc_api_headers() {
    return array('Content-Type: application/json',
                 'Authorization: Bearer ' . (defined('KLC_API_KEY') ? KLC_API_KEY : ''));
}

function klc_api_url() {
    $base = defined('KLC_API_BASE') ? rtrim(KLC_API_BASE, '/') : 'https://api.deepseek.com';
    return $base . '/chat/completions';
}

function klc_payload($msgs, $stream, $tools = null, $tool_choice = null) {
    $p = array(
        'model'       => defined('KLC_MODEL') ? KLC_MODEL : 'deepseek-chat',
        'messages'    => $msgs,
        'stream'      => (bool)$stream,
        'temperature' => defined('KLC_TEMPERATURE') ? (float)KLC_TEMPERATURE : 0.5,
        'max_tokens'  => defined('KLC_MAX_TOKENS') ? (int)KLC_MAX_TOKENS : 1200,
    );
    // 逐次表示でも、最後に使ったトークン数を返してもらう(1日の上限の計算に使う)
    if ($stream) { $p['stream_options'] = array('include_usage' => true); }
    if ($tools) { $p['tools'] = $tools; if ($tool_choice) { $p['tool_choice'] = $tool_choice; } }
    return json_encode($p, JSON_UNESCAPED_UNICODE);
}

/** 使ったトークン数。API が usage を返さないときは文字数から見積もる(多めに) */
function klc_usage_tokens($usage, $msgs, $answer) {
    if (is_array($usage) && isset($usage['total_tokens'])) { return (int)$usage['total_tokens']; }
    $in = 0;
    foreach ($msgs as $m) { $in += mb_strlen(is_string($m['content'] ?? '') ? $m['content'] : json_encode($m['content'] ?? ''), 'UTF-8'); }
    return (int)ceil(($in + mb_strlen((string)$answer, 'UTF-8')) * 1.2);
}

/**
 * 非ストリーミング: array('content'=>..., 'tool_calls'=>..., 'tokens'=>n) or array('error'=>...)
 */
function klc_ask_once($msgs, $tools = null, $tool_choice = null) {
    $ch = curl_init(klc_api_url());
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => klc_api_headers(),
        CURLOPT_POSTFIELDS => klc_payload($msgs, false, $tools, $tool_choice),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ));
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) { return array('error' => 'AIへの接続に失敗しました: ' . $err); }
    $j = json_decode($res, true);
    $m = $j['choices'][0]['message'] ?? null;
    if ($code !== 200 || !is_array($m) || (!isset($m['content']) && empty($m['tool_calls']))) {
        $msg = isset($j['error']['message']) ? $j['error']['message'] : ('HTTP ' . $code);
        return array('error' => 'AIがエラーを返しました: ' . $msg);
    }
    $content = (string)($m['content'] ?? '');
    return array('content' => $content, 'tool_calls' => $m['tool_calls'] ?? array(),
                 'tokens' => klc_usage_tokens($j['usage'] ?? null, $msgs, $content));
}

/**
 * ストリーミング: 逐次echoしつつ array('full'=>全文, 'tokens'=>n, 'aborted'=>bool) を返す。失敗時はfalse(エラーはecho済み)
 * 利用者がページを閉じたら、そこで生成を止める(残りの生成に料金を払わない)。
 */
function klc_ask_stream($msgs, $tools = null, $tool_choice = null) {
    $state = array('buf' => '', 'full' => '', 'status' => 0, 'usage' => null, 'aborted' => false);
    $ch = curl_init(klc_api_url());
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => klc_api_headers(),
        CURLOPT_POSTFIELDS => klc_payload($msgs, true, $tools, $tool_choice),
        CURLOPT_TIMEOUT => 180,
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$state) {
            if (!$state['status']) { $state['status'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); }
            $state['buf'] .= $chunk;
            while (($p = strpos($state['buf'], "\n")) !== false) {
                $line = trim(substr($state['buf'], 0, $p));
                $state['buf'] = substr($state['buf'], $p + 1);
                if (strpos($line, 'data:') !== 0) { continue; }
                $data = trim(substr($line, 5));
                if ($data === '[DONE]') { continue; }
                $j = json_decode($data, true);
                if (isset($j['usage']) && is_array($j['usage'])) { $state['usage'] = $j['usage']; }
                if (isset($j['choices'][0]['delta']['content'])) {
                    $piece = $j['choices'][0]['delta']['content'];
                    $state['full'] .= $piece;
                    echo $piece;
                    @ob_flush(); @flush();
                    if (connection_aborted()) { $state['aborted'] = true; return 0; }   // 0を返すと curl が転送を止める
                }
            }
            return strlen($chunk);
        },
    ));
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$state['aborted'] && ($ok === false || ($code && $code !== 200)) && $state['full'] === '') {
        echo "申し訳ありません。AIへの接続でエラーが発生しました。少し時間を置いてお試しください。";
        @ob_flush(); @flush();
        return false;
    }
    if ($state['full'] === '' && !$state['aborted']) {
        echo "申し訳ありません。AIへの接続でエラーが発生しました。少し時間を置いてお試しください。";
        @ob_flush(); @flush();
        return false;
    }
    return array('full' => $state['full'], 'aborted' => $state['aborted'],
                 'tokens' => klc_usage_tokens($state['usage'], $msgs, $state['full']));
}

/** 画面への知らせ(道具の進み具合)。本文と混ざらないよう、区切り文字 \x1e で囲んで流す */
function klc_emit($ev) {
    echo "\x1e" . json_encode($ev, JSON_UNESCAPED_UNICODE) . "\x1e";
    @ob_flush(); @flush();
}

/**
 * 道具を使う回。1回目は逐次表示せずに「道具を使うか」をAIに決めさせ、使うなら実行して結果を渡す。
 * 返り値: 道具の結果を足したメッセージ列と、そこまでに使ったトークン数。
 * 道具を使わなかったときは 'answer' に1回目の答えが入る(もう一度AIを呼ばない)。
 */
function klc_run_tools($msgs, $emit) {
    $tools = klc_tools();
    $schema = klc_tool_schema();
    $tokens = 0;
    $max = defined('KLC_TOOL_MAX_CALLS') ? max(1, (int)KLC_TOOL_MAX_CALLS) : 3;
    $r = klc_ask_once($msgs, $schema, 'auto');
    if (isset($r['error'])) { return $r; }
    $tokens += $r['tokens'];
    if (empty($r['tool_calls'])) { return array('msgs' => $msgs, 'tokens' => $tokens, 'answer' => $r['content']); }
    $msgs[] = array('role' => 'assistant', 'content' => $r['content'] !== '' ? $r['content'] : null, 'tool_calls' => $r['tool_calls']);
    foreach (array_slice($r['tool_calls'], 0, $max) as $call) {
        $name = $call['function']['name'] ?? '';
        $args = json_decode($call['function']['arguments'] ?? '{}', true);
        $t = $tools[$name] ?? null;
        $label = $t ? (string)($t['label'] ?? $name) : $name;
        if ($emit) { $emit(array('t' => 'tool', 'id' => $call['id'] ?? $name, 'label' => $label, 'state' => 'start')); }
        $out = $t ? klc_tool_run($t, is_array($args) ? $args : array()) : 'その道具はありません';
        $bad = strpos($out, '取得できませんでした') === 0 || !$t;
        if ($emit) { $emit(array('t' => 'tool', 'id' => $call['id'] ?? $name, 'label' => $label, 'state' => $bad ? 'error' : 'done')); }
        $msgs[] = array('role' => 'tool', 'tool_call_id' => $call['id'] ?? $name, 'content' => $out);
    }
    // 上限より多く呼ばれた分にも、tool メッセージで答えておく(API が対応を求めるため)
    foreach (array_slice($r['tool_calls'], $max) as $call) {
        $msgs[] = array('role' => 'tool', 'tool_call_id' => $call['id'] ?? '', 'content' => '呼び出し回数の上限のため実行しませんでした');
    }
    return array('msgs' => $msgs, 'tokens' => $tokens, 'answer' => null);
}

/* ================= 利用ログ(任意) ================= */

function klc_log($ip, $q, $alen) {
    if (!defined('KLC_LOG') || !KLC_LOG) { return; }
    $f = klc_data_dir() . '/log.jsonl';
    $row = json_encode(array(
        'ts' => date('Y-m-d H:i:s'),
        'ip' => substr(hash('sha256', $ip . '|klc'), 0, 12),
        'q'  => mb_substr($q, 0, 500, 'UTF-8'),
        'alen' => (int)$alen,
    ), JSON_UNESCAPED_UNICODE);
    $fp = fopen($f, 'a');
    if ($fp) { flock($fp, LOCK_EX); fwrite($fp, $row . "\n"); flock($fp, LOCK_UN); fclose($fp); }
}

/* ================= API: 質問 ================= */

if (isset($_GET['api']) && $_GET['api'] === 'ask') {
    // 利用者がページを閉じても、使った分を1日の上限に記録し終えるまでは止めない。
    // (既定だと PHP は次の出力で丸ごと終了し、払ったトークンが数えられずに上限をすり抜ける。2026-09-29 実測)
    // 生成そのものは klc_ask_stream が接続切れを見て止める。
    ignore_user_abort(true);
    klc_session_start();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { klc_json_out(405, array('error' => 'POSTで送信してください')); }
    if (!klc_logged_in()) { klc_json_out(401, array('error' => 'ログインが必要です')); }
    $tok = isset($_SERVER['HTTP_X_KLC_TOKEN']) ? $_SERVER['HTTP_X_KLC_TOKEN'] : '';
    if (empty($_SESSION['klc_token']) || !hash_equals($_SESSION['klc_token'], $tok)) {
        klc_json_out(403, array('error' => 'ページを再読み込みしてください'));
    }
    $body = json_decode((string)file_get_contents('php://input'), true);
    $q = isset($body['q']) ? trim((string)$body['q']) : '';
    if ($q === '') { klc_json_out(400, array('error' => '質問を入力してください')); }
    if (mb_strlen($q, 'UTF-8') > klc_input_max()) {
        klc_json_out(400, array('error' => '質問は' . klc_input_max() . '文字以内でお願いします'));
    }
    if (!defined('KLC_API_KEY') || KLC_API_KEY === '' || KLC_API_KEY === 'sk-xxxx') {
        klc_json_out(503, array('error' => 'APIキーが未設定です(klchatbot_config.php)'));
    }
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    $blocked = klc_budget_block();
    if ($blocked !== '') { klc_json_out(429, array('error' => $blocked)); }
    if (!klc_rate_ok($ip)) {
        klc_json_out(429, array('error' => 'ご利用が集中しています。1時間ほど空けてお試しください' . (klc_demo() ? '(デモは1時間' . klc_rate_limit_max() . '回まで)' : '')));
    }
    $msgs = klc_messages($q, isset($body['history']) ? $body['history'] : array());
    $use_tools = (bool)klc_tools();

    $stream = !defined('KLC_STREAM') || KLC_STREAM;
    if ($stream) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        while (ob_get_level()) { @ob_end_flush(); }
        $tokens = 0;
        if ($use_tools) {
            $tr = klc_run_tools($msgs, 'klc_emit');
            if (isset($tr['error'])) {
                echo "申し訳ありません。AIへの接続でエラーが発生しました。少し時間を置いてお試しください。";
                klc_budget(array('requests' => 1));
                exit;
            }
            $tokens += $tr['tokens'];
            if ($tr['answer'] !== null) {           // 道具を使わなかった: 1回目の答えをそのまま出す
                echo $tr['answer'];
                klc_budget(array('tokens' => $tokens, 'requests' => 1));
                klc_log($ip, $q, mb_strlen($tr['answer'], 'UTF-8'));
                exit;
            }
            $msgs = $tr['msgs'];
        }
        $r = klc_ask_stream($msgs, $use_tools ? klc_tool_schema() : null, $use_tools ? 'none' : null);
        klc_budget(array('tokens' => $tokens + ($r !== false ? $r['tokens'] : 0), 'requests' => 1));
        if ($r !== false) { klc_log($ip, $q, mb_strlen($r['full'], 'UTF-8')); }
        exit;
    }
    $tokens = 0;
    if ($use_tools) {
        $tr = klc_run_tools($msgs, null);
        if (isset($tr['error'])) { klc_budget(array('requests' => 1)); klc_json_out(502, $tr); }
        $tokens += $tr['tokens'];
        if ($tr['answer'] !== null) {
            klc_budget(array('tokens' => $tokens, 'requests' => 1));
            klc_log($ip, $q, mb_strlen($tr['answer'], 'UTF-8'));
            klc_json_out(200, array('answer' => $tr['answer']));
        }
        $msgs = $tr['msgs'];
    }
    $ans = klc_ask_once($msgs, $use_tools ? klc_tool_schema() : null, $use_tools ? 'none' : null);
    if (isset($ans['error'])) { klc_budget(array('requests' => 1)); klc_json_out(502, $ans); }
    klc_budget(array('tokens' => $tokens + $ans['tokens'], 'requests' => 1));
    klc_log($ip, $q, mb_strlen($ans['content'], 'UTF-8'));
    klc_json_out(200, array('answer' => $ans['content']));
}

/* ================= 画面 ================= */

klc_session_start();
if (empty($_SESSION['klc_token'])) { $_SESSION['klc_token'] = bin2hex(random_bytes(16)); }

// パスワード認証(設定時のみ)
$login_error = '';
if (klc_auth_mode() === 'password' && isset($_POST['klc_pw'])) {
    if (klc_try_login((string)$_POST['klc_pw'])) { $_SESSION['klc_ok'] = 1; }
    else { $login_error = 'パスワードが違います'; }
}
if (isset($_GET['logout'])) { unset($_SESSION['klc_ok']); header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit; }

$title    = defined('KLC_TITLE') ? KLC_TITLE : 'Kurage Light ChatBot';
$subtitle = defined('KLC_SUBTITLE') ? KLC_SUBTITLE : '';
$welcome  = defined('KLC_WELCOME') ? KLC_WELCOME : 'こんにちは。ご質問をどうぞ。';
$color    = defined('KLC_BRAND_COLOR') ? KLC_BRAND_COLOR : '#0a8f8f';
$examples = defined('KLC_EXAMPLES') ? KLC_EXAMPLES : array();
?><!doctype html>
<html lang="ja"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo klc_h($title); ?></title>
<meta name="robots" content="<?php echo klc_demo() ? 'noindex' : 'index, follow'; ?>">
<style>
:root { --brand: <?php echo klc_h($color); ?>; --ink:#20303a; --line:#dbe5e9; --paper:#f4f8f9; }
* { box-sizing:border-box; }
body { margin:0; background:var(--paper); color:var(--ink);
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","Noto Sans JP",sans-serif; }
.wrap { max-width:760px; margin:0 auto; min-height:100vh; display:flex; flex-direction:column; }
header { padding:14px 18px 10px; background:#fff; border-bottom:2px solid var(--brand); }
header h1 { margin:0; font-size:18px; color:var(--brand); }
header p { margin:2px 0 0; font-size:12px; color:#5a6c76; }
.demo-badge { display:inline-block; background:#fff3d8; border:1px solid #e8c87a; color:#7a5b12;
  font-size:11px; font-weight:700; border-radius:6px; padding:2px 8px; margin-left:8px; vertical-align:middle; }
#chat { flex:1; overflow-y:auto; padding:18px; display:flex; flex-direction:column; gap:12px; }
.msg { max-width:86%; padding:10px 14px; border-radius:14px; font-size:14.5px; line-height:1.75;
  white-space:pre-wrap; overflow-wrap:anywhere; }
.msg.u { align-self:flex-end; background:var(--brand); color:#fff; border-bottom-right-radius:4px; }
.msg.a { align-self:flex-start; background:#fff; border:1px solid var(--line); border-bottom-left-radius:4px; }
.msg.a a { color:var(--brand); }
.msg.err { align-self:center; background:#fdeaea; border:1px solid #eab6b6; color:#8a2f2f; font-size:13px; }
.chips { display:flex; flex-wrap:wrap; gap:8px; padding:0 18px 6px; }
.chips button { background:#fff; border:1px solid var(--line); border-radius:999px; padding:6px 12px;
  font-size:12.5px; color:#3c525e; cursor:pointer; }
.chips button:hover { border-color:var(--brand); color:var(--brand); }
form.ask { display:flex; gap:8px; padding:12px 18px 16px; background:#fff; border-top:1px solid var(--line); }
form.ask textarea { flex:1; resize:none; border:1.5px solid var(--line); border-radius:10px;
  padding:10px 12px; font-size:15px; font-family:inherit; height:48px; }
form.ask textarea:focus { outline:none; border-color:var(--brand); }
form.ask button { background:var(--brand); color:#fff; border:none; border-radius:10px;
  padding:0 22px; font-size:15px; font-weight:700; cursor:pointer; }
form.ask button:disabled { opacity:.5; cursor:default; }
.foot { text-align:center; font-size:11px; color:#8a99a1; padding:0 0 10px; }
.msg.a .cite { margin-top:8px; padding-top:6px; border-top:1px dashed var(--line); font-size:12px; color:#5a6c76; white-space:normal; }
.msg.a .cite b { font-weight:600; }
.choices { align-self:flex-start; display:flex; flex-wrap:wrap; gap:8px; max-width:86%; }
.choices button { background:#fff; border:1.5px solid var(--brand); color:var(--brand); border-radius:10px;
  padding:7px 12px; font-size:13.5px; cursor:pointer; font-family:inherit; }
.choices button:hover { background:var(--brand); color:#fff; }
.choices button:disabled { opacity:.45; cursor:default; background:#fff; color:var(--brand); }
.tools { font-size:12.5px; color:#5a6c76; margin-bottom:6px; white-space:normal; }
.tools div::before { content:"…"; display:inline-block; width:1.4em; color:var(--brand); }
.tools div.done::before { content:"✓"; }
.tools div.error::before { content:"!"; color:#a33; }
.gate { max-width:380px; margin:80px auto; background:#fff; border:1px solid var(--line);
  border-radius:12px; padding:28px; text-align:center; }
.gate input { width:100%; padding:10px; font-size:15px; border:1.5px solid var(--line); border-radius:8px; margin:12px 0; }
.gate button { width:100%; background:var(--brand); color:#fff; border:none; border-radius:8px; padding:11px; font-size:15px; font-weight:700; cursor:pointer; }
.gate .err { color:#a33; font-size:13px; }
</style></head>
<body>
<?php if (!klc_logged_in()): ?>
<div class="gate">
  <h1 style="font-size:18px;color:var(--brand);margin:0 0 4px"><?php echo klc_h($title); ?></h1>
  <p style="font-size:13px;color:#5a6c76">パスワードを入力してください</p>
  <?php if ($login_error): ?><p class="err"><?php echo klc_h($login_error); ?></p><?php endif; ?>
  <form method="post"><input type="password" name="klc_pw" autofocus><button>入室する</button></form>
</div>
<?php else: ?>
<div class="wrap">
<header>
  <h1><?php echo klc_h($title); ?><?php if (klc_demo()): ?><span class="demo-badge">デモ</span><?php endif; ?></h1>
  <?php if ($subtitle): ?><p><?php echo klc_h($subtitle); ?></p><?php endif; ?>
</header>
<div id="chat"></div>
<?php if ($examples): ?>
<div class="chips" id="chips">
<?php foreach ($examples as $ex): ?><button type="button"><?php echo klc_h($ex); ?></button><?php endforeach; ?>
</div>
<?php endif; ?>
<form class="ask" id="askform">
  <textarea id="q" placeholder="質問を入力(<?php echo klc_input_max(); ?>文字まで)" maxlength="<?php echo klc_input_max(); ?>"></textarea>
  <button id="send" type="submit">送信</button>
</form>
<div class="foot">Kurage Light ChatBot<?php if (klc_demo()): ?> — デモ環境(回答はAI生成・1時間<?php echo klc_rate_limit_max(); ?>回まで)<?php endif; ?></div>
</div>
<script>
(function(){
  var TOKEN = <?php echo json_encode($_SESSION['klc_token']); ?>;
  var WELCOME = <?php echo json_encode($welcome); ?>;
  var SOURCES = <?php echo json_encode((object)klc_source_titles(), JSON_UNESCAPED_UNICODE); ?>;
  var chat = document.getElementById('chat');
  var form = document.getElementById('askform');
  var q = document.getElementById('q');
  var send = document.getElementById('send');
  var hist = [];
  try { hist = JSON.parse(sessionStorage.getItem('klc_hist') || '[]'); } catch (e) {}

  function add(cls, text) {
    var d = document.createElement('div');
    d.className = 'msg ' + cls;
    d.textContent = text;
    chat.appendChild(d);
    chat.scrollTop = chat.scrollHeight;
    return d;
  }
  // 答えの最後の「参照: 〜」「選択肢: 〜」の行を取り出す。本文からは消す
  var META = /^\s*(参照|選択肢)\s*[:：]\s*(.*)$/;
  function splitMeta(raw) {
    var lines = String(raw).split('\n'), body = [], cites = [], choices = [];
    lines.forEach(function(l){
      var m = l.match(META);
      if (!m) { body.push(l); return; }
      var items = m[2].split(m[1] === '参照' ? /[,、，\s]+/ : /[｜|]/).map(function(x){ return x.trim(); }).filter(Boolean);
      if (m[1] === '参照') cites = cites.concat(items); else choices = choices.concat(items);
    });
    return {text: body.join('\n').replace(/\s+$/, ''), cites: cites, choices: choices};
  }
  // 参照は「実在するファイル」だけを出す（AIが作った名前は捨てる）
  function citeTitles(names) {
    var seen = {}, out = [];
    names.forEach(function(n){
      var k = /\.md$/.test(n) ? n : n + '.md';
      if (Object.prototype.hasOwnProperty.call(SOURCES, k) && !seen[k]) { seen[k] = 1; out.push(SOURCES[k]); }
    });
    return out;
  }
  function esc(t) { return t.replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  // 表示するのは太字とURLだけ。先にエスケープするので、AIの出力にタグが混ざっても実行されない
  function md(t) {
    return esc(t)
      .replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>')
      .replace(/(https?:\/\/[^\s<>()（）「」、。]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
  }
  function renderAnswer(el, raw, live) {
    var s = splitMeta(raw);
    var tools = el.querySelector('.tools');
    el.innerHTML = s.text ? md(s.text) : (live ? '…' : '');
    if (tools) el.insertBefore(tools, el.firstChild);
    if (live) return;
    var titles = citeTitles(s.cites);
    if (titles.length) {
      var c = document.createElement('div'); c.className = 'cite';
      var b = document.createElement('b'); b.textContent = '参照した資料: ';
      c.appendChild(b); c.appendChild(document.createTextNode(titles.join('、')));
      el.appendChild(c);
    }
    if (s.choices.length) {
      var box = document.createElement('div'); box.className = 'choices';
      s.choices.slice(0, 4).forEach(function(ch){
        var bt = document.createElement('button'); bt.type = 'button'; bt.textContent = ch.slice(0, 40);
        bt.addEventListener('click', function(){
          box.querySelectorAll('button').forEach(function(x){ x.disabled = true; });
          ask(ch);
        });
        box.appendChild(bt);
      });
      el.parentNode.insertBefore(box, el.nextSibling);
    }
    chat.scrollTop = chat.scrollHeight;
  }
  function toolEvent(el, ev) {
    var box = el.querySelector('.tools');
    if (!box) { box = document.createElement('div'); box.className = 'tools'; el.insertBefore(box, el.firstChild); }
    var row = box.querySelector('[data-id="' + String(ev.id).replace(/"/g, '') + '"]');
    if (!row) { row = document.createElement('div'); row.setAttribute('data-id', ev.id); box.appendChild(row); }
    row.className = ev.state === 'start' ? '' : ev.state;
    row.textContent = ev.label + (ev.state === 'start' ? 'を確認しています' : ev.state === 'done' ? 'を確認しました' : 'を確認できませんでした');
  }
  function save() { try { sessionStorage.setItem('klc_hist', JSON.stringify(hist.slice(-12))); } catch (e) {} }

  if (hist.length) { hist.forEach(function(t){
    if (t[0] === 'a') { var d = add('a', ''); renderAnswer(d, t[1], false); } else { add('u', t[1]); }
  }); }
  else { add('a', WELCOME); }

  async function ask(text) {
    add('u', text);
    hist.push(['u', text]); save();
    q.value = ''; send.disabled = true;
    var out = add('a', '…');
    try {
      var res = await fetch(location.pathname + '?api=ask', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-KLC-TOKEN': TOKEN},
        body: JSON.stringify({q: text, history: hist.slice(-8, -1)})
      });
      if (!res.ok) {
        var j = await res.json().catch(function(){ return {}; });
        out.className = 'msg err';
        out.textContent = j.error || ('エラーが発生しました (HTTP ' + res.status + ')');
        send.disabled = false; return;
      }
      var ct = res.headers.get('Content-Type') || '';
      var full = '';
      if (ct.indexOf('application/json') >= 0) {
        var j2 = await res.json();
        full = j2.answer || '';
      } else {
        // 本文の中に \x1e で囲んだ知らせ（道具の進み具合）が混ざって届く
        var reader = res.body.getReader();
        var dec = new TextDecoder();
        var pend = '';
        while (true) {
          var r = await reader.read();
          if (r.done) break;
          pend += dec.decode(r.value, {stream: true});
          var i;
          while ((i = pend.indexOf('\x1e')) >= 0) {
            var j = pend.indexOf('\x1e', i + 1);
            if (j < 0) break;                       // 知らせの途中で切れている: 次を待つ
            full += pend.slice(0, i);
            try { toolEvent(out, JSON.parse(pend.slice(i + 1, j))); } catch (e) {}
            pend = pend.slice(j + 1);
          }
          if (pend.indexOf('\x1e') < 0) { full += pend; pend = ''; }
          renderAnswer(out, full, true);
          chat.scrollTop = chat.scrollHeight;
        }
        full += pend.replace(/\x1e[^\x1e]*$/, '');
      }
      renderAnswer(out, full, false);
      hist.push(['a', full]); save();
    } catch (e) {
      out.className = 'msg err';
      out.textContent = '通信エラーが発生しました。再度お試しください。';
    }
    send.disabled = false;
    q.focus();
  }

  form.addEventListener('submit', function(e){
    e.preventDefault();
    var t = q.value.trim();
    if (t) ask(t);
  });
  q.addEventListener('keydown', function(e){
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.dispatchEvent(new Event('submit')); }
  });
  var chips = document.getElementById('chips');
  if (chips) chips.addEventListener('click', function(e){
    if (e.target.tagName === 'BUTTON') ask(e.target.textContent);
  });
})();
</script>
<?php endif; ?>
<?php if (($_SERVER['HTTP_HOST'] ?? '') === 'proto.exbridge.jp'): ?><p style="text-align:center;font-size:13px;margin:14px 0;color:#5d6b7a">これはデモです。<a href="https://kappstore.exbridge.jp/app.php?id=224e141f77bd07a8&amp;ref=klchatbot" target="_blank" rel="noopener">この製品をオンプレミスで導入する（商品ページ）</a></p><?php endif; ?>
</body></html>
