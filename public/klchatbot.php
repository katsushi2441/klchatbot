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

function klc_system_prompt() {
    $sys = defined('KLC_SYSTEM_PROMPT') ? KLC_SYSTEM_PROMPT :
        'あなたは丁寧で簡潔な日本語アシスタントです。';
    $kb = klc_knowledge();
    if ($kb !== '') {
        $sys .= "\n\n# 知識ベース(以下の内容だけを根拠に答える。価格・URL・数値は一字一句正確に引用する。知識に無いことは正直に「資料にありません」と答える)\n" . $kb;
    }
    return $sys;
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

function klc_payload($msgs, $stream) {
    return json_encode(array(
        'model'       => defined('KLC_MODEL') ? KLC_MODEL : 'deepseek-chat',
        'messages'    => $msgs,
        'stream'      => (bool)$stream,
        'temperature' => defined('KLC_TEMPERATURE') ? (float)KLC_TEMPERATURE : 0.5,
        'max_tokens'  => defined('KLC_MAX_TOKENS') ? (int)KLC_MAX_TOKENS : 1200,
    ), JSON_UNESCAPED_UNICODE);
}

/** 非ストリーミング: 回答文字列 or array('error'=>...) */
function klc_ask_once($msgs) {
    $ch = curl_init(klc_api_url());
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => klc_api_headers(),
        CURLOPT_POSTFIELDS => klc_payload($msgs, false),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
    ));
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) { return array('error' => 'AIへの接続に失敗しました: ' . $err); }
    $j = json_decode($res, true);
    if ($code !== 200 || !isset($j['choices'][0]['message']['content'])) {
        $msg = isset($j['error']['message']) ? $j['error']['message'] : ('HTTP ' . $code);
        return array('error' => 'AIがエラーを返しました: ' . $msg);
    }
    return (string)$j['choices'][0]['message']['content'];
}

/** ストリーミング: 逐次echoしつつ全文を返す。失敗時はfalse(エラーはecho済み) */
function klc_ask_stream($msgs) {
    $state = array('buf' => '', 'full' => '', 'status' => 0);
    $ch = curl_init(klc_api_url());
    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => klc_api_headers(),
        CURLOPT_POSTFIELDS => klc_payload($msgs, true),
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
                if (isset($j['choices'][0]['delta']['content'])) {
                    $piece = $j['choices'][0]['delta']['content'];
                    $state['full'] .= $piece;
                    echo $piece;
                    @ob_flush(); @flush();
                }
            }
            return strlen($chunk);
        },
    ));
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($ok === false || ($code && $code !== 200) || $state['full'] === '') {
        if ($state['full'] === '') {
            echo "申し訳ありません。AIへの接続でエラーが発生しました。少し時間を置いてお試しください。";
            @ob_flush(); @flush();
            return false;
        }
    }
    return $state['full'];
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
    if (!klc_rate_ok($ip)) {
        klc_json_out(429, array('error' => 'ご利用が集中しています。1時間ほど空けてお試しください' . (klc_demo() ? '(デモは1時間' . klc_rate_limit_max() . '回まで)' : '')));
    }
    $msgs = klc_messages($q, isset($body['history']) ? $body['history'] : array());

    $stream = !defined('KLC_STREAM') || KLC_STREAM;
    if ($stream) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        while (ob_get_level()) { @ob_end_flush(); }
        $full = klc_ask_stream($msgs);
        if ($full !== false) { klc_log($ip, $q, mb_strlen($full, 'UTF-8')); }
        exit;
    }
    $ans = klc_ask_once($msgs);
    if (is_array($ans)) { klc_json_out(502, $ans); }
    klc_log($ip, $q, mb_strlen($ans, 'UTF-8'));
    klc_json_out(200, array('answer' => $ans));
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
  function save() { try { sessionStorage.setItem('klc_hist', JSON.stringify(hist.slice(-12))); } catch (e) {} }

  if (hist.length) { hist.forEach(function(t){ add(t[0] === 'a' ? 'a' : 'u', t[1]); }); }
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
      if (ct.indexOf('application/json') >= 0) {
        var j2 = await res.json();
        out.textContent = j2.answer || '';
      } else {
        out.textContent = '';
        var reader = res.body.getReader();
        var dec = new TextDecoder();
        while (true) {
          var r = await reader.read();
          if (r.done) break;
          out.textContent += dec.decode(r.value, {stream: true});
          chat.scrollTop = chat.scrollHeight;
        }
      }
      hist.push(['a', out.textContent]); save();
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
</body></html>
