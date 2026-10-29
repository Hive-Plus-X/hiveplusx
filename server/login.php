<?php
// ログインページ。ログインしていないときに開くページはすべて、ここに集める。
//   ・ログイン(メールアドレスとパスワード)
//   ・パスワードを忘れたとき(再設定のメールを送る)
//   ・メールのリンク(?k=...): 招待されたアカウントのパスワードを決める / パスワードの再設定 / メールアドレスの変更の確認
//   ・初期設定: アカウントがまだ1つも無いとき。今の共通パスワード($HPX_VIEW_PASSWORD)で本人を確かめて、
//     サイト管理者のアカウントを作り、前の版のデータ(data/site/ と data/<HPX-coreのID>/)をそのアカウントへ移す
declare(strict_types=1);
require __DIR__ . '/hpx_auth.php';

const L_TITLE = BRAND_NAME;

function lp_head(string $title): void
{
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . '</title>' . brand_head() . '<style>'
        . 'body{font-family:system-ui,sans-serif;margin:0 auto;padding:12px 16px 24px;background:#f6f7f8;color:#222;max-width:480px}'
        . 'h1{font-size:1.3em;margin:.6em 0}.card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:12px 14px;margin:10px 0}'
        . 'label{display:block;color:#555;font-size:.92em;margin-top:.7em}input,button{font-size:1em;padding:8px 10px;box-sizing:border-box}'
        . 'input[type=email],input[type=password],input[type=text]{width:100%}button{margin-top:1em}'
        . 'label.show{display:inline-flex;align-items:center;gap:4px;color:#345;cursor:pointer;user-select:none}label.show input{width:auto;margin:0}.ok{color:#11734b;font-weight:bold}.ng{color:#c62828;font-weight:bold}.muted{color:#777;font-size:.9em}'
        . '.brand{text-align:center;margin:28px 0 6px}.brand img{width:200px;max-width:70%;height:auto}.brand p{margin:6px 0 0;color:#2F5D45;font-weight:bold;letter-spacing:.1em}'
        . brand_css() . '</style></head><body><div class="brand"><img src="img/hivex_main.svg" alt="' . BRAND_NAME . '" width="200" height="57"><p>蜂場管理</p></div>';
}
// パスワードの欄があるフォームに「パスワードを表示」を付ける(打ち間違いを確かめられるように。そのフォームのパスワード欄をまとめて切り替える)
function lp_end(): void
{
    echo '<script>document.querySelectorAll("form").forEach(function(f){'
        . 'var ps=f.querySelectorAll("input[type=password]");if(!ps.length)return;'
        . 'var l=document.createElement("label");l.className="show";var c=document.createElement("input");c.type="checkbox";'
        . 'c.onchange=function(){ps.forEach(function(p){p.type=c.checked?"text":"password";});};'
        . 'l.appendChild(c);l.appendChild(document.createTextNode(" パスワードを表示"));'
        . 'var b=f.querySelector("button");f.insertBefore(l,b);});</script></body></html>';
    exit;
}
function lp_msg(string $title, string $html): void
{
    lp_head($title);
    echo '<div class="card">' . $html . '</div>';
    lp_end();
}

auth_session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['csrf'];
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($post && !hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    exit('画面を開き直してから、もう一度送ってください。');
}
$csrfField = '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
$next = auth_next((string)($_GET['next'] ?? $_POST['next'] ?? ''));

// ====================================================================
// 初期設定(アカウントがまだ無いとき)
// ====================================================================

if (!users_exist()) {
    $common = (string)($HPX_VIEW_PASSWORD ?? '');
    if (strlen($common) < 8) {
        lp_msg('初期設定', '<p class="ng">初期設定には config.php の $HPX_VIEW_PASSWORD (8文字以上) が必要です。</p>'
            . '<p class="muted">本人の確認に使います。初期設定が済んだら使わなくなります。</p>');
    }
    $err = '';
    $email = email_norm((string)($_POST['email'] ?? ''));
    $name = trim(mb_substr((string)($_POST['name'] ?? ''), 0, 40));
    if ($post) {
        $pw = (string)($_POST['pw'] ?? '');
        if (!hash_equals($common, (string)($_POST['common'] ?? ''))) { sleep(2); $err = '今のパスワード ($HPX_VIEW_PASSWORD) が違います。'; }
        elseif (!email_ok($email)) $err = 'メールアドレスを正しく入れてください。';
        elseif ($name === '') $err = '表示名を入れてください。';
        else $err = pw_check_new($pw, (string)($_POST['pw2'] ?? ''));
        if ($err === '') {
            $lock = migrate_lock(LOCK_EX);
            $uid = users_exist() ? '' : user_create($email, $name, true, password_hash($pw, PASSWORD_DEFAULT));
            $moved = $uid !== '' ? migrate_legacy($uid) : [];
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
            if ($uid === '') lp_msg('初期設定', '<p class="ng">もう初期設定が済んでいます。</p><p><a href="login.php">ログイン</a></p>');
            auth_login(user_get($uid));
            lp_msg('初期設定', '<p class="ok">初期設定が済みました。</p><ul><li>' . implode('</li><li>', array_map('h', $moved)) . '</li></ul>'
                . '<p>これからは、このメールアドレスと今決めたパスワードでログインします。</p><p><a href="apiary.php">蜂場ページへ</a></p>');
        }
    }
    lp_head('初期設定');
    echo '<div class="card"><p><b>初期設定</b></p><p class="muted">アカウントでログインする仕組みに変わりました。最初に、サイト管理者 (あなた) のアカウントを作ります。'
        . 'これまでの蜂場・巣箱・メモ・作業記録・センサの記録は、すべてこのアカウントに移ります。</p>'
        . ($err !== '' ? '<p class="ng">' . h($err) . '</p>' : '')
        . '<form method="post">' . $csrfField
        . '<label>今のパスワード (これまで閲覧ページに入れていたもの)</label><input type="password" name="common" required>'
        . '<label>メールアドレス (ログインに使います。通知メールもここへ届きます)</label><input type="email" name="email" value="' . h($email) . '" required>'
        . '<label>表示名</label><input type="text" name="name" maxlength="40" value="' . h($name) . '" required>'
        . '<label>新しいパスワード (' . A_PW_MIN . '文字以上)</label><input type="password" name="pw" autocomplete="new-password" required>'
        . '<label>新しいパスワード (確認)</label><input type="password" name="pw2" autocomplete="new-password" required>'
        . '<button type="submit">アカウントを作って、データを移す</button></form></div>';
    lp_end();
}

// 前の版のデータを、初めのアカウントへ移す。移したものの説明を返す
function migrate_legacy(string $uid): array
{
    global $HPX_TOKEN, $HPX_MAIL_TO;
    $out = [];
    $old = data_dir() . '/site';
    $new = account_site_dir($uid);
    if (is_dir($old)) {
        foreach (glob($new . '/{,.}*', GLOB_BRACE) ?: [] as $f) if (is_file($f)) @unlink($f);   // 作ったばかりの空のフォルダ
        @rmdir($new);
        $out[] = @rename($old, $new) ? '蜂場・巣箱・メモ・作業記録を移しました。' : '蜂場などのデータ (data/site/) を移せませんでした。手で ' . $new . ' へ移してください。';
    }
    foreach (glob(data_dir() . '/*/state.json') ?: [] as $p) {
        $dir = dirname($p);
        $id = basename($dir);
        if (!preg_match('/^[0-9A-F]{12}$/', $id)) continue;
        $st = json_decode((string)@file_get_contents($p), true);
        $nm = is_array($st) && ($st['name'] ?? '') !== '' ? (string)$st['name'] : $id;
        $lock = @fopen($dir . '/.lock', 'c');
        if ($lock) flock($lock, LOCK_EX);
        $ok = @rename($dir, account_hubs_dir($uid) . '/' . $id);
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        $out[] = $ok ? 'HPX-core「' . $nm . '」の記録を移しました。' : 'HPX-core「' . $nm . '」の記録 (data/' . $id . '/) を移せませんでした。';
    }
    $tok = (string)($HPX_TOKEN ?? '');
    if ($tok !== '' && $tok !== 'CHANGE-ME-to-a-long-random-string') {
        token_issue($uid, $tok);
        $out[] = 'config.php の $HPX_TOKEN を、このアカウントの機器用トークンにしました (HPX-core の設定はそのままで届きます)。';
    }
    $to = notify_clean((string)($HPX_MAIL_TO ?? ''));
    if ($to !== null && $to !== '' && $to !== 'you@example.com') {
        account_update($uid, function (array $a) use ($to) { $a['notify'] = $to; return $a; });
        $out[] = '通知メールの送り先を ' . $to . ' にしました (設定ページで変えられます)。';
    }
    if (!$out) $out[] = '移すデータはありませんでした。';
    return $out;
}

// ====================================================================
// メールのリンク
// ====================================================================

if (isset($_GET['k'])) {
    $code = (string)$_GET['k'];
    $ln = link_get($code);
    if (!$ln) lp_msg('リンク', '<p class="ng">このリンクは使えません (期限切れか、もう使われています)。</p>'
        . '<p class="muted">招待のリンクはサイト管理者に送り直してもらってください。パスワードの再設定は <a href="login.php?forgot=1">もう一度送れます</a>。</p>');
    $u = user_get((string)$ln['uid']);
    if (($u['status'] ?? '') === 'stopped') lp_msg('リンク', '<p class="ng">このアカウントは停止中です。</p>');

    if ($ln['kind'] === 'email') {
        // メールアドレスの変更: リンクを開いた時点で切り替える
        $newEmail = (string)($ln['email'] ?? '');
        $other = user_by_email($newEmail);
        if ($other && $other['id'] !== $u['id']) { link_get($code, true); lp_msg('メールアドレスの変更', '<p class="ng">' . h($newEmail) . ' はほかのアカウントで使われているため、変更できませんでした。</p>'); }
        link_get($code, true);
        user_set((string)$u['id'], ['email' => $newEmail]);
        // 通知先が前のアドレスだけだったら、新しいアドレスに合わせる
        account_update((string)$u['id'], function (array $a) use ($u) { if (email_norm((string)($a['notify'] ?? '')) === (string)$u['email']) $a['notify'] = ''; return $a; });
        lp_msg('メールアドレスの変更', '<p class="ok">メールアドレスを ' . h($newEmail) . ' に変えました。</p><p>次からはこのアドレスでログインします。</p><p><a href="apiary.php">蜂場ページへ</a></p>');
    }

    // 招待・再設定: パスワードを決める
    $invite = $ln['kind'] === 'invite';
    $err = '';
    $name = trim(mb_substr((string)($_POST['name'] ?? $u['name']), 0, 40));
    if ($post) {
        $pw = (string)($_POST['pw'] ?? '');
        $err = $invite && $name === '' ? '表示名を入れてください。' : pw_check_new($pw, (string)($_POST['pw2'] ?? ''));
        if ($err === '' && link_get($code, true)) {
            $set = ['pw' => password_hash($pw, PASSWORD_DEFAULT), 'pwv' => (int)($u['pwv'] ?? 1) + 1, 'status' => 'active'];
            if ($invite) $set['name'] = $name;
            user_set((string)$u['id'], $set);
            login_fail((string)$u['email'], false);
            auth_login(user_get((string)$u['id']));
            header('Location: apiary.php');
            exit;
        }
    }
    lp_head($invite ? 'アカウントの登録' : 'パスワードの再設定');
    echo '<div class="card"><p><b>' . ($invite ? 'アカウントの登録' : 'パスワードの再設定') . '</b></p>'
        . '<p class="muted">' . h((string)$u['email']) . ' のパスワードを決めてください。</p>'
        . ($err !== '' ? '<p class="ng">' . h($err) . '</p>' : '')
        . '<form method="post">' . $csrfField
        . ($invite ? '<label>表示名</label><input type="text" name="name" maxlength="40" value="' . h($name) . '" required>' : '')
        . '<label>パスワード (' . A_PW_MIN . '文字以上)</label><input type="password" name="pw" autocomplete="new-password" required autofocus>'
        . '<label>パスワード (確認)</label><input type="password" name="pw2" autocomplete="new-password" required>'
        . '<button type="submit">決める</button></form></div>';
    lp_end();
}

// ====================================================================
// パスワードを忘れたとき
// ====================================================================

if (isset($_GET['forgot'])) {
    if ($post) {
        $email = email_norm((string)($_POST['email'] ?? ''));
        $u = email_ok($email) ? user_by_email($email) : null;
        // 送りすぎないよう、同じアカウントへは5分に1通まで。アカウントがあるかどうかは画面に出さない
        if ($u && ($u['status'] ?? '') === 'active' && time() - (int)($u['reset_sent'] ?? 0) >= 300) {
            user_set((string)$u['id'], ['reset_sent' => time()]);
            account_mail($email, '【HPX】パスワードの再設定',
                "パスワードの再設定を受け付けました。次のリンクを開いて、新しいパスワードを決めてください (" . A_LINK_HOURS . "時間有効)。\n\n"
                . link_url(link_issue('reset', (string)$u['id'])) . "\n\n心当たりが無いときは、このメールは捨ててください。パスワードは変わりません。\n");
        }
        lp_msg('パスワードの再設定', '<p>そのメールアドレスのアカウントがあれば、再設定のメールを送りました。届いたメールのリンクを開いてください。</p>'
            . '<p class="muted">届かないときは、迷惑メールのフォルダも見てください。サイト管理者に再設定のリンクを出してもらうこともできます。</p><p><a href="login.php">ログインへ戻る</a></p>');
    }
    lp_head('パスワードの再設定');
    echo '<div class="card"><p><b>パスワードを忘れたとき</b></p><p class="muted">登録したメールアドレスに、再設定のリンクを送ります。</p>'
        . '<form method="post" action="login.php?forgot=1">' . $csrfField
        . '<label>メールアドレス</label><input type="email" name="email" required autofocus>'
        . '<button type="submit">再設定のメールを送る</button></form><p><a href="login.php">ログインへ戻る</a></p></div>';
    lp_end();
}

// ====================================================================
// デモ (login.php?demo=1): メールアドレスとパスワードを入れずに、デモ用アカウントで開く
//   ほかのアカウントでログイン中なら、入れ替わる前に確かめる
// ====================================================================

if (isset($_GET['demo'])) {
    $du = demo_uid() !== '' ? user_get(demo_uid()) : null;
    if (!$du || ($du['status'] ?? '') !== 'active') lp_msg('デモ', '<p>今はデモを開けません。</p><p><a href="login.php">ログインへ</a></p>');
    $cur = user_get((string)($_SESSION['uid'] ?? ''));
    if ($cur && !is_demo($cur) && !($post && isset($_POST['demo_go']))) {
        lp_msg('デモ', '<p>' . h((string)$cur['name']) . ' さんのアカウントでログインしています。デモを開くと、このアカウントからはログアウトします。</p>'
            . '<form method="post" action="login.php?demo=1">' . $csrfField . '<button type="submit" name="demo_go" value="1">ログアウトしてデモを開く</button></form>'
            . '<p><a href="apiary.php">やめて戻る</a></p>');
    }
    if (!$cur || !is_demo($cur)) auth_login($du);
    header('Location: apiary.php');
    exit;
}

// ====================================================================
// ログイン
// ====================================================================

$err = '';
$email = email_norm((string)($_POST['email'] ?? ''));
if ($post) {
    $wait = login_locked($email);
    $u = $wait ? null : user_by_email($email);
    if ($wait) {
        $err = '続けて間違えたため、ログインを止めています。' . (int)ceil($wait / 60) . ' 分ほど待ってから試してください。';
    } elseif ($u && ($u['status'] ?? '') === 'active' && password_verify((string)($_POST['pw'] ?? ''), (string)$u['pw'])) {
        login_fail($email, false);
        if (password_needs_rehash((string)$u['pw'], PASSWORD_DEFAULT)) user_set((string)$u['id'], ['pw' => password_hash((string)$_POST['pw'], PASSWORD_DEFAULT)]);
        auth_login($u);
        header('Location: ' . $next);
        exit;
    } else {
        sleep(2);   // 総当たり対策
        if ($email !== '') login_fail($email, true);
        $err = ($u['status'] ?? '') === 'stopped' ? 'このアカウントは停止中です。サイト管理者に問い合わせてください。'
            : (($u['status'] ?? '') === 'invited' ? 'まだ登録が済んでいません。招待メールのリンクからパスワードを決めてください。'
            : 'メールアドレスかパスワードが違います。');
    }
}
lp_head(L_TITLE . ' アプリ ログイン');
echo '<div class="card"><form method="post" action="login.php">' . $csrfField . '<input type="hidden" name="next" value="' . h($next) . '">'
    . ($err !== '' ? '<p class="ng">' . h($err) . '</p>' : '')
    . '<label>メールアドレス</label><input type="email" name="email" value="' . h($email) . '" autocomplete="username" required autofocus>'
    . '<label>パスワード</label><input type="password" name="pw" autocomplete="current-password" required>'
    . '<button type="submit">ログイン</button></form>'
    . '<p class="muted"><a href="login.php?forgot=1">パスワードを忘れたとき</a></p>'
    . '<p class="muted">アカウントはサイト管理者が発行します。</p></div>';
if (demo_uid() !== '') echo '<div class="card"><p><b>お試し</b>: ログインせずに、見本の蜂場で使い方を試せます。</p><p><a href="login.php?demo=1">デモを開く</a></p></div>';
lp_end();
