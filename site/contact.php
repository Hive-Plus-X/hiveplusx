<?php
// hiveplusx.com のお問い合わせフォーム。サイトに置いて (www/hiveplusx/contact.php)、届いた内容をメールで管理者に送る。
//   メールアドレスをページに出さないための仕組み。送信はアプリ (hpx/) の config.php と hpx_smtp_send() を使う。
//     送り先: support@hiveplusx.com (config.php に $HPX_CONTACT_TO があれば、そちら)
//     送信: アプリの通知メールと同じ送信設定 (config.php の $HPX_SMTP_* と $HPX_MAIL_FROM) を使う
//   迷惑な送信を減らす工夫:
//     ・人には見えない入力欄 (ロボットだけが埋める) が埋まっていたら捨てる
//     ・ページを開いてから3秒未満、または2時間を過ぎた送信は受け付けない (署名付きの時刻で確かめる)
//     ・同じ接続元からは1時間に5通まで、全体では1日50通まで
//     ・本文にリンクが4つ以上あるものは受け付けない
//   届いた内容は、メールが送れなかったときのために hpx/data/contact/messages.jsonl にも残す (外からは開けない)。
declare(strict_types=1);

$APP = __DIR__ . '/hpx';
require $APP . (is_file($APP . '/hpx_config.php') ? '/hpx_config.php' : '/config.php');   // config.php と、サーバーの設定画面で保存した値
require_once $APP . '/hpx_alert.php';

const C_TO = 'support@hiveplusx.com';
const C_MIN_S = 3;
const C_MAX_S = 7200;
const C_PER_IP_HOUR = 5;
const C_PER_DAY = 50;
const C_KINDS = ['質問', '不具合の報告', '機器の作り方', '取材・講演の依頼', 'その他'];

$dir = rtrim((string)($HPX_DATA_DIR ?? ($APP . '/data')), '/') . '/contact';
if (!is_dir($dir)) @mkdir($dir, 0700, true);

if (!function_exists('h')) { function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); } }

// フォームの署名に使う秘密の値 (最初の1回だけ作って保存する)
function c_secret(string $dir): string
{
    $f = $dir . '/secret';
    $s = is_file($f) ? trim((string)file_get_contents($f)) : '';
    if (strlen($s) < 32) { $s = bin2hex(random_bytes(32)); @file_put_contents($f, $s, LOCK_EX); @chmod($f, 0600); }
    return $s;
}
function c_token(string $dir, int $t): string { return $t . '.' . hash_hmac('sha256', 'contact|' . $t, c_secret($dir)); }
function c_token_age(string $dir, string $tok): ?int
{
    if (!preg_match('/^(\d{9,11})\.([0-9a-f]{64})$/', $tok, $m)) return null;
    if (!hash_equals(hash_hmac('sha256', 'contact|' . $m[1], c_secret($dir)), $m[2])) return null;
    return time() - (int)$m[1];
}

// 送信回数の制限。超えていなければ数を1つ足して true
function c_rate_ok(string $dir): bool
{
    $f = $dir . '/rate.json';
    $fp = @fopen($f, 'c+');
    if (!$fp) return true;
    flock($fp, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fp), true) ?: [];
    $now = time();
    $ip = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));   // 接続元はそのまま残さない
    $d['ip'] = array_filter($d['ip'] ?? [], fn($v) => is_array($v) && $now - (int)($v[0] ?? 0) < 3600);
    $day = date('Ymd');
    if (($d['day'] ?? '') !== $day) { $d['day'] = $day; $d['n'] = 0; }
    $cnt = (int)($d['ip'][$ip][1] ?? 0);
    $ok = $cnt < C_PER_IP_HOUR && (int)$d['n'] < C_PER_DAY;
    if ($ok) {
        $d['ip'][$ip] = [$d['ip'][$ip][0] ?? $now, $cnt + 1];
        $d['n'] = (int)$d['n'] + 1;
        ftruncate($fp, 0); rewind($fp);
        fwrite($fp, json_encode($d));
    }
    flock($fp, LOCK_UN); fclose($fp);
    return $ok;
}

$v = ['name' => '', 'email' => '', 'kind' => C_KINDS[0], 'body' => ''];
$err = [];
$done = false;
$fail = false;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? ''));
    $age = c_token_age($dir, (string)($_POST['t'] ?? ''));
    $bot = (string)($_POST['website'] ?? '') !== '';

    if ($bot || $age === null || $age < C_MIN_S) {
        $done = true;   // ロボットらしいものは、送ったふりをして捨てる
    } elseif ($age > C_MAX_S) {
        $err[] = '時間がたったため、送信できませんでした。お手数ですが、もう一度「送信」を押してください。';
    } else {
        if (mb_strlen($v['name']) > 60) $err[] = 'お名前は60文字までにしてください。';
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $v['email'])) $err[] = '返信先のメールアドレスを確かめてください。';
        if (!in_array($v['kind'], C_KINDS, true)) $v['kind'] = 'その他';
        $len = mb_strlen($v['body']);
        if ($len < 5) $err[] = 'お問い合わせの内容を書いてください。';
        if ($len > 4000) $err[] = '内容は4000文字までにしてください。';
        if (preg_match_all('#https?://#i', $v['body']) >= 4) $err[] = 'リンクが多すぎるため送信できません。リンクは3つまでにしてください。';
        if (!$err && !c_rate_ok($dir)) $err[] = '送信が集中しているため、しばらくたってからお試しください。';

        if (!$err) {
            $rec = ['time' => date('c'), 'name' => $v['name'], 'email' => $v['email'], 'kind' => $v['kind'], 'body' => $v['body']];
            @file_put_contents($dir . '/messages.jsonl', json_encode($rec, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
            $to = array_values(array_filter(array_map('trim', explode(',', (string)(($HPX_CONTACT_TO ?? '') !== '' ? $HPX_CONTACT_TO : C_TO)))));
            $subject = '【hiveplusx.com お問い合わせ】' . $v['kind'];
            $body = "hiveplusx.com のお問い合わせフォームから届きました。\n"
                . "返信するときは、下の返信先へ新しくメールを送ってください。\n\n"
                . "種類: {$v['kind']}\nお名前: " . ($v['name'] !== '' ? $v['name'] : '(未記入)') . "\n返信先: {$v['email']}\n"
                . '受信: ' . date('Y-m-d H:i') . "\n\n----\n{$v['body']}\n";
            [$ok, $res] = ($to && (string)($HPX_SMTP_HOST ?? '') !== '')
                ? hpx_smtp_send($to, (string)$HPX_MAIL_FROM, $subject, $body)
                : [false, '送り先かSMTPが設定されていません'];
            @file_put_contents($dir . '/mail.log', date('c') . ' ' . ($ok ? 'OK' : 'NG') . ' ' . preg_replace('/\s+/', ' ', (string)$res) . "\n", FILE_APPEND | LOCK_EX);
            if ($ok) { $done = true; } else { $fail = true; }
        }
    }
}
$tok = c_token($dir, time());
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>お問い合わせ - Hive Plus X</title>
<link rel="icon" href="img/favicon.svg" type="image/svg+xml">
<style>
  :root { --bg:#FAF8F3; --panel:#FFFFFF; --text:#1F2328; --sub:#5B6168; --honey:#F2A900; --honey-soft:#FDF1D2;
          --forest:#2F5D45; --line:rgba(31,35,40,.14); --on-forest:#FFFFFF; --err:#B42318; }
  @media (prefers-color-scheme: dark) {
    :root { --bg:#1E3B2C; --panel:#2F5D45; --text:#FFFFFF; --sub:rgba(255,255,255,.72); --honey-soft:rgba(242,169,0,.16);
            --line:rgba(255,255,255,.22); --forest:#16291F; --err:#FFB4A8; }
  }
  * { box-sizing:border-box; margin:0; padding:0; }
  body { background:var(--bg); color:var(--text); line-height:1.75;
         font-family:"Inter","Helvetica Neue",Arial,"Hiragino Sans","Noto Sans JP",sans-serif; -webkit-font-smoothing:antialiased; }
  .wrap { max-width:720px; margin:0 auto; padding:0 20px; }
  header { background:var(--forest); color:var(--on-forest); }
  header .wrap { max-width:1080px; display:flex; align-items:center; height:60px; }
  header img { height:26px; display:block; }
  main { padding:120px 0 160px; }
  @media (max-width:760px) { main { padding:64px 0 120px; } }
  .eyebrow { font-size:14px; font-weight:700; letter-spacing:.24em; color:var(--honey); text-transform:uppercase; }
  h1 { font-size:clamp(26px, 3.6vw, 34px); margin-top:6px; line-height:1.4; }
  .lead { color:var(--sub); margin-top:8px; }
  .lead a { color:inherit; }
  form { margin-top:36px; display:grid; gap:20px; }
  label { display:block; font-weight:700; font-size:14px; margin-bottom:6px; }
  label small { font-weight:400; color:var(--sub); margin-left:6px; }
  input[type=text], input[type=email], select, textarea {
    width:100%; font:inherit; color:var(--text); background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:10px 14px; }
  textarea { min-height:200px; resize:vertical; }
  input:focus, select:focus, textarea:focus { outline:2px solid var(--honey); outline-offset:1px; border-color:transparent; }
  .hp { position:absolute; left:-10000px; width:1px; height:1px; overflow:hidden; }
  button { justify-self:start; font:inherit; font-weight:600; font-size:15px; padding:12px 28px; border-radius:999px; border:0;
           background:var(--honey); color:#1F2328; cursor:pointer; }
  .note { font-size:13px; color:var(--sub); }
  .box { margin-top:28px; padding:18px 20px; border-radius:12px; background:var(--panel); border:1px solid var(--line); }
  .box.err { border-color:var(--err); color:var(--err); }
  .box ul { padding-left:1.2em; }
  .back { display:inline-block; margin-top:28px; font-weight:600; text-decoration:none; color:inherit; border-bottom:2px solid var(--honey); }
  footer { background:var(--forest); color:var(--on-forest); padding:28px 0; font-size:13px; }
  footer .wrap { max-width:1080px; opacity:.7; }
</style>
</head>
<body>
<header><div class="wrap"><a href="./"><img src="img/hivex_reverse.svg" alt="Hive Plus X"></a></div></header>
<main>
  <div class="wrap">
    <p class="eyebrow">Contact</p>
    <h1>お問い合わせ</h1>
<?php if ($done): ?>
    <div class="box">送信しました。お問い合わせありがとうございます。<br>内容を確認して、返信先のメールアドレスにご連絡します。</div>
    <a class="back" href="./">トップへ戻る</a>
<?php else: ?>
    <p class="lead">質問、不具合の報告、機器の作り方の相談などをお送りください。GitHub をお使いの方は、<a href="https://github.com/Hive-Plus-X/hiveplusx/discussions">フォーラム</a>で質問すると、ほかの方の参考にもなります。作り方や使い方は、<a href="help.php">AI ヘルプ</a>でもすぐにお答えします。</p>
<?php if ($fail): ?>
    <div class="box err">申し訳ありません。いま送信できませんでした。内容は受け付けてありますが、念のため時間をおいてもう一度お試しください。</div>
<?php elseif ($err): ?>
    <div class="box err"><ul><?php foreach ($err as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div>
<?php endif; ?>
    <form method="post" action="contact.php">
      <input type="hidden" name="t" value="<?= h($tok) ?>">
      <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
      <div><label for="kind">種類</label>
        <select id="kind" name="kind"><?php foreach (C_KINDS as $k) echo '<option' . ($k === $v['kind'] ? ' selected' : '') . '>' . h($k) . '</option>'; ?></select></div>
      <div><label for="name">お名前<small>任意</small></label>
        <input type="text" id="name" name="name" maxlength="60" value="<?= h($v['name']) ?>" autocomplete="name"></div>
      <div><label for="email">返信先のメールアドレス</label>
        <input type="email" id="email" name="email" required maxlength="200" value="<?= h($v['email']) ?>" autocomplete="email"></div>
      <div><label for="body">内容</label>
        <textarea id="body" name="body" required maxlength="4000"><?= h($v['body']) ?></textarea></div>
      <p class="note">いただいた内容とメールアドレスは、お返事のためだけに使います。</p>
      <button type="submit">送信する</button>
    </form>
<?php endif; ?>
  </div>
</main>
<footer><div class="wrap">&copy; 2026 Taro Maeda and Hive Plus X contributors</div></footer>
</body>
</html>
