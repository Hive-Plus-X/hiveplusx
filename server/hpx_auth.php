<?php
// HPX アカウントの共通部分。ログインの確認、アカウントの読み書き、機器用トークン、招待・再設定のリンク、メールの送信。
//   読み込むだけでは何もしない(関数の定義だけ)。ページは auth_require() でログインを確かめる。receive.php・hpx_cron.php も使う。
//   保存先(データフォルダ。data/.htaccess で外から開けない):
//     users.json     アカウント一覧 {"users": {"u_3f9a1c7e": {email, name, pw, pwv, admin, status, created, last_login}}}
//                      status: active=使える / invited=招待中(まだパスワードを決めていない) / stopped=停止中 / pending=登録の申請中(承認待ち)
//                      consent: データ利用に同意した日時 (epoch。登録のときにチェックする)
//     server.json    サーバーの設定 {"signup": "invite" | "apply" | "open"} (アカウントの登録方法。サーバー管理ページで切り替える)
//     tokens.json    機器用トークンの sha256 → アカウントID
//     ical_keys.json タスクのカレンダー配信の合言葉の sha256 → アカウントID
//     links.json     招待・パスワード再設定・メールアドレス変更のリンク(コードの sha256 → 内容。期限付き、1回だけ使える)
//     login_fail.json  ログインの失敗回数(メールアドレスごと。続けて間違えたらしばらく止める)
//     accounts/<アカウントID>/
//       account.json 通知先(notify)、機器用トークン(token)
//       site/        蜂場・巣箱・メモ・作業記録(前の data/site/ と同じ中身)
//       hubs/<HPX-CoreのID>/  HPX-Core 1台分の記録(前の data/<ID>/ と同じ中身)
//   アカウントIDは内部の名前で変わらない。メールアドレスを変えてもIDはそのままなので、記録の記入者(by)などはそのまま。
declare(strict_types=1);
require_once __DIR__ . '/hpx_config.php';   // config.php と、サーバーの設定画面で保存した値
require_once __DIR__ . '/hpx_alert.php';
require_once __DIR__ . '/hpx_team.php';

const A_SESSION_DAYS = 30;           // ログインを保つ日数
const A_LINK_HOURS = 72;             // 招待・再設定・メール変更のリンクの有効時間
const A_FAIL_MAX = 5;                // 同じメールアドレスでこの回数続けて間違えたら
const A_FAIL_LOCK_S = 900;           // この秒数ログインを止める
const A_PW_MIN = 8;                  // パスワードの最低文字数
const A_NOTIFY_MAX = 5;              // 通知先のアドレスの数の上限

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// ====================================================================
// ブランド (Hive Plus X / ロゴ表記 hive+x)。配色はロゴに合わせる (brand/final/README.md)
//   Charcoal #1F2328 = 文字、Honey #F2A900 = アクセント(選択中・巣箱)、Forest #2F5D45 = メニュー・ボタン・蜂場
//   ロゴは img/ に置く。メニュー(Forest 背景)には白+Honey の版、明るい背景には Charcoal+Honey の版を使う
// ====================================================================
const BRAND_NAME = 'Hive Plus X';

// <head> に入れるもの(アイコン)
function brand_head(): string
{
    // iPhone で「ホーム画面に追加」したときのアイコン(180px の PNG。角は iPhone が丸める)と名前
    return '<link rel="icon" href="img/favicon.svg" type="image/svg+xml"><link rel="apple-touch-icon" href="img/apple-touch-icon.png">'
        . '<meta name="apple-mobile-web-app-title" content="HPX-note"><meta name="theme-color" content="#2F5D45">'
        . '<link rel="manifest" href="manifest.json"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="mobile-web-app-capable" content="yes">';
}

// オフライン対応 (sw.js・js/hpx_offline.js)。ログインした後のページの <head> に入れる。
// hpx-u はアカウントごとの目印 (別のアカウントでログインしたら、この端末の控えを消すため)。hpx-csrf は送信待ちを送るときの合言葉
function offline_head(): string
{
    global $UID, $csrf;
    if (($UID ?? '') === '') return '';
    return '<meta name="hpx-u" content="' . h(substr(hash('sha256', 'hpx-u:' . $UID), 0, 16)) . '"><meta name="hpx-csrf" content="' . h((string)$csrf) . '">'
        . '<script src="js/hpx_offline.js?v=1" defer></script>';
}

// サーバー管理者が「サーバーの設定」で入れた組織名・団体名 (無ければ '')
function brand_org_name(): string
{
    return trim((string)($GLOBALS['HPX_ORG_NAME'] ?? ''));
}

// メニュー左端のロゴ「hive+x note」(蜂場ページへ戻る)。組織名があれば「ロゴ × 組織名」にする
function brand_nav_logo(): string
{
    $o = '<a href="apiary.php" class="logo" title="' . BRAND_NAME . '"><img src="img/hivex_note_reverse.svg" alt="' . BRAND_NAME . ' note" width="166" height="27"></a>';
    $org = brand_org_name();
    if ($org !== '') $o .= '<span class="org" title="' . h($org) . '"><span class="x" aria-hidden="true">×</span>' . h($org) . '</span>';
    return $o;
}

// 各ページの <style> の最後に足す、ロゴの配色に合わせた色の指定(前の色の指定を上書きする)
function brand_css(): string
{
    return 'body{background:#f7f7f4;color:#1F2328}a{color:#2F5D45}h2{border-bottom-color:#d5e0d9}th{background:#eef2ef}'
        . 'nav{background:#2F5D45;min-height:40px}nav a{color:#fff}nav a.on{border-bottom-color:#F2A900}'
        . 'nav .logo{display:flex;align-items:center;padding:4px 12px 4px 0;margin-right:2px;border:0}'
        . 'nav .logo img{width:166px;height:auto;display:block}@media(max-width:600px){nav .logo img{width:132px}}'
        // 組織名 (ロゴの右)。長い名前は「…」で切り、スマホでは歯車の左までに収める
        . 'nav .org{color:#fff;font-size:.95rem;font-weight:bold;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:16em;min-width:0;margin-left:-10px;margin-right:10px}nav .org .x{color:#F2A900;font-weight:normal;margin-right:.45em}'
        . '@media(max-width:600px){nav .org{flex:1 1 0;max-width:none;font-size:.85rem;margin-left:-8px;margin-right:6px}}'
        . 'nav .setm{margin-left:auto;position:relative}nav .setm>summary{list-style:none;cursor:pointer;color:#fff;display:flex;align-items:center;padding:4px 2px;border-bottom:2px solid transparent}'
        . 'nav .setm>summary::-webkit-details-marker{display:none}nav .setm>summary.on{border-bottom-color:#F2A900}nav .setm svg{background:none;border:0;width:22px;height:22px;display:block}nav .nl{display:flex;flex-wrap:wrap;gap:4px 14px;align-items:center}@media(max-width:600px){nav{flex-wrap:wrap!important;row-gap:0!important;padding-top:4px!important;padding-bottom:2px!important}nav .setm{flex-shrink:0}nav .logo img{width:120px}nav .nl{order:3;flex-basis:100%;gap:0 12px}nav .nl a{word-break:keep-all;white-space:nowrap}}nav .setm .sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)}'
        . 'nav .setmenu{position:absolute;right:0;top:calc(100% + 6px);background:#fff;border:1px solid #c5cec8;border-radius:6px;box-shadow:0 4px 14px rgba(0,0,0,.18);min-width:160px;padding:4px 0;z-index:20}'
        . 'nav .setmenu a{display:block;color:#1F2328;padding:8px 16px;border:0;font-size:.95rem;white-space:nowrap}nav .setmenu a:hover{background:#eef2ef}nav .setmenu a.on{font-weight:bold;background:#f4f1e4}'
        . 'nav .setmenu .who{padding:6px 16px 6px;color:#666;font-size:.8rem;border-bottom:1px solid #e3e7e4;margin-bottom:4px;white-space:nowrap}'
        . '.tabs a,.per a{color:#1F2328;border-color:#c5cec8}.tabs a.on,.per a.on{background:#2F5D45;border-color:#2F5D45;color:#fff}'
        . '.card.flash{background:#e8f0eb;border-color:#b8cdbf}'
        . '.hive{border-left-color:#F2A900}.apiary,.cols>.apiary{border-left-color:#2F5D45}'
        . '.hbtns a{border-color:#F2A900;color:#1F2328}.hbtns a.on{background:#F2A900;color:#1F2328}'
        . '.chip,.tagl span{background:#dbe8e0;color:#2F5D45}'
        . '.tagc input:checked+span{background:#F2A900;border-color:#F2A900;color:#1F2328}'
        . '#memo-form>summary{background:#fdf1cc;border-color:#F2A900;color:#1F2328}#memo-form>summary:hover,#memo-form[open]>summary{background:#fbe3a0}'
        . 'button{border:1px solid #b9c3bd;border-radius:6px;background:#fff;color:#1F2328;cursor:pointer}button:hover{background:#f1f4f2}'
        . 'button[type=submit]{background:#2F5D45;border-color:#2F5D45;color:#fff}button[type=submit]:hover{background:#264c39}'
        . 'button:focus-visible{outline:2px solid #F2A900;outline-offset:1px}'
        . 'button.link,button.link[type=submit]{background:none;border:none;color:#2F5D45;padding:0}';
}

// ====================================================================
// ファイル
// ====================================================================

function data_dir(): string
{
    global $HPX_DATA_DIR;
    return rtrim((string)$HPX_DATA_DIR, '/');
}

function json_load(string $path): array
{
    $v = json_decode((string)@file_get_contents($path), true);
    return is_array($v) ? $v : [];
}

function json_save(string $path, array $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $tmp = $path . '.tmp';
    return @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false && @rename($tmp, $path);
}

// 読んで、直して、書く(ロックを取る)。$fn は配列を受け取って直した配列を返す
function json_update(string $path, callable $fn): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $lock = @fopen($path . '.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    $ok = json_save($path, $fn(json_load($path)));
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    return $ok;
}

// ====================================================================
// アカウント
// ====================================================================

function is_uid(string $uid): bool { return (bool)preg_match('/^u_[0-9a-f]{8}$/', $uid); }
function email_norm(string $e): string { return strtolower(trim($e)); }
function email_ok(string $e): bool { return strlen($e) <= 200 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false; }

function users_all(): array
{
    $u = json_load(data_dir() . '/users.json')['users'] ?? [];
    return is_array($u) ? $u : [];
}

// アカウントが1つも無い = 初期設定の前
function users_exist(): bool { return users_all() !== []; }

// $fn はアカウント一覧(id => 内容)を受け取って直した一覧を返す
function users_update(callable $fn): bool
{
    return json_update(data_dir() . '/users.json', function (array $d) use ($fn) {
        $d['users'] = $fn(is_array($d['users'] ?? null) ? $d['users'] : []);
        return $d;
    });
}

function user_get(string $uid): ?array
{
    $u = users_all()[$uid] ?? null;
    return is_array($u) ? ['id' => $uid] + $u : null;
}

function user_by_email(string $email): ?array
{
    $email = email_norm($email);
    foreach (users_all() as $uid => $u) if (($u['email'] ?? '') === $email) return ['id' => (string)$uid] + $u;
    return null;
}

function user_set(string $uid, array $fields): bool
{
    return users_update(function (array $us) use ($uid, $fields) {
        if (isset($us[$uid])) $us[$uid] = array_merge($us[$uid], $fields);
        return $us;
    });
}

// 表示名(チーム機能で記入者・担当者を出すときにも使う)。消したアカウントは「(削除したアカウント)」
function user_name(string $uid): string
{
    static $cache = null;
    $cache ??= users_all();
    if (isset(DEMO_MEMBERS[$uid]) && !isset($cache[$uid])) return DEMO_MEMBERS[$uid];
    return isset($cache[$uid]) ? (string)$cache[$uid]['name'] : ($uid === '' ? '' : '(削除したアカウント)');
}

function account_dir(string $uid): string
{
    if (!is_uid($uid)) throw new InvalidArgumentException('bad uid');
    return data_dir() . '/accounts/' . $uid;
}
function account_site_dir(string $uid): string { return account_dir($uid) . '/site'; }
function account_hubs_dir(string $uid): string { return account_dir($uid) . '/hubs'; }
function account_load(string $uid): array { return json_load(account_dir($uid) . '/account.json'); }
function account_update(string $uid, callable $fn): bool { return json_update(account_dir($uid) . '/account.json', $fn); }

// お知らせメールの種類。マイアカウントで種類ごとにオン/オフできる(account.json の mail_off に、止めた種類を入れる)
//   招待・パスワード再設定・メールアドレスの確認・チームに追加された、などの手続きのメールは止められない
const A_MAIL_KINDS = [
    'device' => ['機器の警報', '電柵の電圧低下、HPX-Core やセンサから届かない、電池の交換'],
    'hive' => ['巣箱の警報', '重量・温度が設定した範囲を超えた'],
    'task' => ['タスクのお知らせ', '期限の前日・当日・期限切れのタスク (毎朝1通)'],
    'camera' => ['カメラの変化', 'HPX-CAM の写真がそれまでと大きく変わった (巣箱が倒れた、人が来た、など)'],
];
function mail_on(string $uid, string $kind): bool
{
    return !in_array($kind, (array)(account_load($uid)['mail_off'] ?? []), true);
}

// 通知の送り先(カンマ区切りの文字列)。設定が無ければアカウントのメールアドレス
//   $kind(A_MAIL_KINDS)を渡すと、その種類のメールを止めていれば '' を返す
function account_notify(string $uid, string $kind = ''): string
{
    if ($kind !== '' && !mail_on($uid, $kind)) return '';
    $n = trim((string)(account_load($uid)['notify'] ?? ''));
    return $n !== '' ? $n : (string)(user_get($uid)['email'] ?? '');
}

// アカウントを作る。$pwHash が空なら「招待中」(リンクからパスワードを決めるまでログインできない)
function user_create(string $email, string $name, bool $admin, string $pwHash = ''): string
{
    $email = email_norm($email);
    $uid = '';
    users_update(function (array $us) use ($email, $name, $admin, $pwHash, &$uid) {
        foreach ($us as $u) if (($u['email'] ?? '') === $email) return $us;   // 同じアドレスはもうある
        do { $uid = 'u_' . bin2hex(random_bytes(4)); } while (isset($us[$uid]));
        $us[$uid] = ['email' => $email, 'name' => $name, 'pw' => $pwHash, 'pwv' => 1, 'admin' => $admin,
            'status' => $pwHash !== '' ? 'active' : 'invited', 'created' => time(), 'last_login' => 0];
        return $us;
    });
    if ($uid === '') return '';
    @mkdir(account_site_dir($uid), 0755, true);
    @mkdir(account_hubs_dir($uid), 0755, true);
    account_update($uid, fn(array $a) => $a + ['notify' => '']);
    token_issue($uid);
    return $uid;
}

// ====================================================================
// 機器用トークン(HPX-Core の「送信先のトークン」に入れる)。アカウントごとに1つ
// ====================================================================

// 新しいトークンを作る(前のトークンは使えなくなる)。$token を渡したらそれを使う(初期設定で config.php の $HPX_TOKEN を引き継ぐとき)
function token_issue(string $uid, ?string $token = null): string
{
    $token ??= 'hpx' . bin2hex(random_bytes(20));
    json_update(data_dir() . '/tokens.json', function (array $t) use ($uid, $token) {
        $t = array_filter($t, fn($v) => $v !== $uid);
        $t[hash('sha256', $token)] = $uid;
        return $t;
    });
    account_update($uid, function (array $a) use ($token) { $a['token'] = $token; $a['token_at'] = time(); return $a; });
    return $token;
}

// トークンからアカウントIDを引く。使えるアカウント(停止中でない)のときだけ
function token_uid(string $token): ?string
{
    if ($token === '') return null;
    $uid = (string)(json_load(data_dir() . '/tokens.json')[hash('sha256', $token)] ?? '');
    if (!is_uid($uid)) return null;
    $u = user_get($uid);
    return $u && !in_array($u['status'] ?? '', ['stopped', 'pending'], true) ? $uid : null;
}

function token_forget(string $uid): void
{
    json_update(data_dir() . '/tokens.json', fn(array $t) => array_filter($t, fn($v) => $v !== $uid));
}

// ====================================================================
// タスクのカレンダー配信の合言葉(ical.php?k=…)。アカウントごとに1つ。Googleカレンダーなどに URL ごと登録する
//   ical_keys.json に sha256 → アカウントID、account.json の ical に合言葉そのもの(ページに URL を出すため)
// ====================================================================

// 新しい合言葉を作る(前の URL は使えなくなる)
function ical_issue(string $uid): string
{
    $key = bin2hex(random_bytes(20));
    json_update(data_dir() . '/ical_keys.json', function (array $t) use ($uid, $key) {
        $t = array_filter($t, fn($v) => $v !== $uid);
        $t[hash('sha256', $key)] = $uid;
        return $t;
    });
    account_update($uid, function (array $a) use ($key) { $a['ical'] = $key; $a['ical_at'] = time(); return $a; });
    return $key;
}

// 合言葉からアカウントIDを引く。使えるアカウントのときだけ
function ical_uid(string $key): ?string
{
    if (!preg_match('/^[0-9a-f]{40}$/', $key)) return null;
    $uid = (string)(json_load(data_dir() . '/ical_keys.json')[hash('sha256', $key)] ?? '');
    if (!is_uid($uid)) return null;
    $u = user_get($uid);
    return $u && ($u['status'] ?? '') === 'active' ? $uid : null;
}

// 配信をやめる
function ical_forget(string $uid): void
{
    json_update(data_dir() . '/ical_keys.json', fn(array $t) => array_filter($t, fn($v) => $v !== $uid));
    account_update($uid, function (array $a) { unset($a['ical'], $a['ical_at']); return $a; });
}

// ====================================================================
// 招待・パスワード再設定・メールアドレス変更のリンク
//   kind: invite / reset / email(email に新しいアドレス)
// ====================================================================

function link_issue(string $kind, string $uid, array $extra = []): string
{
    $code = bin2hex(random_bytes(16));
    $now = time();
    json_update(data_dir() . '/links.json', function (array $l) use ($code, $kind, $uid, $extra, $now) {
        // 期限切れと、同じアカウントの同じ種類の古いリンクを消す(新しいリンクだけ使える)
        $l = array_filter($l, fn($x) => (int)($x['exp'] ?? 0) > $now && !(($x['uid'] ?? '') === $uid && ($x['kind'] ?? '') === $kind));
        $l[hash('sha256', $code)] = ['kind' => $kind, 'uid' => $uid, 'exp' => $now + A_LINK_HOURS * 3600] + $extra;
        return $l;
    });
    return $code;
}

// リンクの内容を返す(無い・期限切れなら null)。$use = true なら使ったことにして消す
function link_get(string $code, bool $use = false): ?array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $code)) return null;
    $key = hash('sha256', $code);
    $x = json_load(data_dir() . '/links.json')[$key] ?? null;
    if (!is_array($x) || (int)($x['exp'] ?? 0) <= time() || !user_get((string)($x['uid'] ?? ''))) return null;
    if ($use) json_update(data_dir() . '/links.json', function (array $l) use ($key) { unset($l[$key]); return $l; });
    return $x;
}

// リンクの URL(メールに書く)。config.php の $HPX_SITE_URL があればそれを使う
function site_url(string $page): string
{
    global $HPX_SITE_URL;
    $base = rtrim((string)($HPX_SITE_URL ?? ''), '/');
    if ($base === '') {
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
        $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        $base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
    }
    return $base . '/' . $page;
}

function link_url(string $code): string { return site_url('login.php?k=' . $code); }

// アカウントのメール(招待・再設定など)を1通送る。[成功したか, 詳しく]。結果は data/account_mail.log に残す
function account_mail(string $to, string $subject, string $body): array
{
    global $HPX_MAIL_FROM, $HPX_SMTP_HOST;
    mb_language('Japanese');
    mb_internal_encoding('UTF-8');
    $from = (string)($HPX_MAIL_FROM ?? '');
    if (demo_email() !== '' && email_norm($to) === demo_email()) $r = [false, 'デモ用アカウントにはメールを送りません'];
    elseif (!email_ok($to) || !email_ok($from)) $r = [false, '送り先か送り元 ($HPX_MAIL_FROM) のアドレスが正しくありません'];
    elseif ((string)($HPX_SMTP_HOST ?? '') === '') $r = [false, 'config.php の $HPX_SMTP_HOST などが設定されていません'];
    else $r = hpx_smtp_send([$to], $from, $subject, $body . "\n-- \nHive Plus X\n");
    @file_put_contents(data_dir() . '/account_mail.log', date('Y-m-d H:i:s') . ' ' . ($r[0] ? 'sent' : 'FAILED') . ' ' . $to . ' ' . $subject
        . ($r[0] ? '' : ' | ' . mb_substr(trim(preg_replace('/\s+/', ' ', (string)$r[1]) ?? ''), 0, 200)) . "\n", FILE_APPEND);
    return $r;
}

// ====================================================================
// アカウントの登録方法 (サーバー管理ページで切り替える。data/server.json の signup)
//   invite: サーバー管理者が招待する (前からのやり方。初期値)
//   apply : 本人が登録を申請し、サーバー管理者が承認すると招待メールが届く
//   open  : 本人がメールアドレスを入れると招待メールが届き、すぐ使える (自由登録)
// ====================================================================

const A_SIGNUP_MODES = ['invite' => 'サーバー管理者が招待する', 'apply' => '登録の申請 → サーバー管理者が承認', 'open' => '自由登録 (だれでも登録できる)'];
const A_SIGNUP_PER_HOUR = 5;   // 同じ接続元 (IPアドレス) から1時間に受け付ける登録・申請の数

function server_conf(): array { return json_load(data_dir() . '/server.json'); }
function server_conf_set(array $fields): bool { return json_update(data_dir() . '/server.json', fn(array $c) => array_merge($c, $fields)); }
function signup_mode(): string
{
    $m = (string)(server_conf()['signup'] ?? 'invite');
    return isset(A_SIGNUP_MODES[$m]) ? $m : 'invite';
}

// ====================================================================
// データ利用の同意 (登録のときにチェックする。サーバー管理者が全アカウントのデータをダウンロードする前提)
//   文は config.php の $HPX_CONSENT_TEXT で変えられる。$HPX_TERMS_URL があれば「利用規約」のリンクを付ける
// ====================================================================

const A_CONSENT_DEFAULT = 'このサーバーの管理者が、私のデータ (蜂場・巣箱・メモ・作業記録・センサの記録など) をダウンロードし、研究や養蜂の改善などに利用することに同意します。';
function consent_text(): string
{
    global $HPX_CONSENT_TEXT;
    $t = trim((string)($HPX_CONSENT_TEXT ?? ''));
    return $t !== '' ? $t : A_CONSENT_DEFAULT;
}
// 同意のチェック欄 (name="consent")。$required ならチェックしないと送れない
function consent_field(bool $required = true): string
{
    global $HPX_TERMS_URL;
    $url = trim((string)($HPX_TERMS_URL ?? ''));
    return '<label style="display:flex;gap:6px;align-items:flex-start;color:#222;cursor:pointer"><input type="checkbox" name="consent" value="1"' . ($required ? ' required' : '')
        . ' style="width:auto;margin:.25em 0 0"><span>' . h(consent_text())
        . ($url !== '' && preg_match('#^https?://#', $url) ? ' (<a href="' . h($url) . '" target="_blank" rel="noopener">利用規約</a>)' : '') . '</span></label>';
}
function consent_given(array $u): bool { return (int)($u['consent'] ?? 0) > 0; }

// 登録・申請を受け付けすぎないように、接続元ごとに数える。受け付けてよければ true (数える)
function signup_rate_ok(): bool
{
    $ip = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $now = time();
    $ok = false;
    json_update(data_dir() . '/signup_rate.json', function (array $r) use ($ip, $now, &$ok) {
        $r = array_filter(array_map(fn($ts) => array_values(array_filter((array)$ts, fn($t) => (int)$t > $now - 3600)), $r));
        $ok = count($r[$ip] ?? []) < A_SIGNUP_PER_HOUR;
        if ($ok) $r[$ip][] = $now;
        return $r;
    });
    return $ok;
}

// サーバー管理者 (停止中でない) のメールアドレス。登録の申請を知らせるのに使う
function server_admin_emails(): array
{
    $o = [];
    foreach (users_all() as $u) if (!empty($u['admin']) && ($u['status'] ?? '') === 'active' && !is_demo($u)) $o[] = (string)$u['email'];
    return $o;
}

// ====================================================================
// 蜂場を見てよいか・蜂場での役割
//   持ち主なら 'owner'。チームに共有された蜂場なら 'admin' / 'member'(hpx_team.php)。見られなければ null
//   $ownerUid: その蜂場を持っているアカウント(蜂場は持ち主のアカウントの site/site.json にある)
// ====================================================================

function apiary_role(string $ownerUid, string $apiaryId, string $uid): ?string
{
    if ($uid === '' || !is_uid($ownerUid)) return null;
    if ($ownerUid === $uid) return 'owner';
    return team_apiary_role($ownerUid, $apiaryId, $uid);
}

function can_access_apiary(string $ownerUid, string $apiaryId, string $uid): bool
{
    return apiary_role($ownerUid, $apiaryId, $uid) !== null;
}

// ====================================================================
// ログイン(セッション)
// ====================================================================

function auth_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('hpxfence');
    session_set_cookie_params(['lifetime' => A_SESSION_DAYS * 86400, 'httponly' => true, 'samesite' => 'Lax',
        'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')]);
    ini_set('session.gc_maxlifetime', (string)(A_SESSION_DAYS * 86400));
    session_start();
}

// ログインした状態にする(パスワードを変えると pwv が増え、ほかの端末のログインは切れる)
function auth_login(array $user): void
{
    auth_session_start();
    session_regenerate_id(true);
    $_SESSION = ['uid' => (string)$user['id'], 'pwv' => (int)($user['pwv'] ?? 1)];
    user_set((string)$user['id'], ['last_login' => time()]);
}

function auth_logout(): void
{
    auth_session_start();
    $_SESSION = [];
    session_destroy();
}

// ページの最初に呼ぶ。ログインしていなければログインページへ移す。
// ログインしていれば、そのアカウントを返し、グローバルの $ME(アカウント)・$UID(アカウントID)・$csrf を決める
function auth_require(string $selfPage, bool $adminOnly = false): array
{
    global $ME, $UID, $csrf;
    auth_session_start();
    if (isset($_GET['logout'])) {
        auth_logout();
        header('Location: login.php');
        exit;
    }
    $u = user_get((string)($_SESSION['uid'] ?? ''));
    if (!$u || ($u['status'] ?? '') !== 'active' || (int)($u['pwv'] ?? 1) !== (int)($_SESSION['pwv'] ?? 0)) {
        $_SESSION = [];
        $back = basename($selfPage) . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
        header('Location: login.php' . (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' ? '?next=' . rawurlencode($back) : ''));
        exit;
    }
    if (is_demo($u)) {
        $u['admin'] = false;   // デモ用アカウントはサーバー管理者にしない
        demo_guard(basename($selfPage));
        demo_sensor_fill();
    }
    // サーバー管理者には、ページが途中で止まったときのエラーの内容を画面の下に出す (原因を調べるため)
    if (!empty($u['admin'])) register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
            echo '<div style="margin:1em;padding:.8em;border:2px solid #c62828;background:#fff;color:#c62828;white-space:pre-wrap;font-size:13px">'
                . 'エラーで止まりました (サーバー管理者だけに表示しています):' . "\n" . htmlspecialchars($e['message'] . "\n" . basename((string)$e['file']) . ' ' . $e['line'], ENT_QUOTES) . '</div>';
        }
    });
    if ($adminOnly && empty($u['admin'])) {
        http_response_code(403);
        exit('このページはサーバー管理者だけが開けます。');
    }
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    $csrf = (string)$_SESSION['csrf'];
    // オフライン用にまとめて保存するときの読み込み (js/hpx_offline.js)。ログインの情報 (セッション) をすぐ手放し、
    // 同時に開いたページを待たせない。保存後のお知らせ (flash) も、この読み込みでは消さない (手放した後の変更は残らない)
    if (($_SERVER['HTTP_X_HPX_CRAWL'] ?? '') === '1' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') session_write_close();
    $ME = $u;
    $UID = (string)$u['id'];
    return $u;
}

// ====================================================================
// デモ用アカウント (config.php の $HPX_DEMO_EMAIL)
//   パスワードを公開して誰でも試せるようにするためのアカウント。
//   設定・チームの変更、写真の追加、メールの送信はできない。$HPX_DEMO_READONLY = true なら何も書き込めない。
//   管理ページで保存した初期状態 (data/demo_seed/) に、毎日 $HPX_DEMO_RESET_HOUR 時に戻す (hpx_cron.php)
// ====================================================================

function demo_email(): string
{
    global $HPX_DEMO_EMAIL;
    return email_norm((string)($HPX_DEMO_EMAIL ?? ''));
}
function is_demo(?array $u): bool
{
    return $u && demo_email() !== '' && email_norm((string)($u['email'] ?? '')) === demo_email();
}
function demo_uid(): string
{
    $u = demo_email() !== '' ? user_by_email(demo_email()) : null;
    return $u ? (string)$u['id'] : '';
}
function demo_seed_dir(): string { return data_dir() . '/demo_seed/site'; }
// 見本の準備中 (管理ページで切り替え)。この間はデモ用アカウントで写真も追加でき、毎日の初期化もしない
function demo_editing(): bool { return is_file(data_dir() . '/.demo_editing'); }
function demo_set_editing(bool $on): void
{
    $on ? @file_put_contents(data_dir() . '/.demo_editing', date('Y-m-d H:i:s')) : @unlink(data_dir() . '/.demo_editing');
}

// デモ用アカウントの書き込みを止める。写真は黙って外す (ほかの入力は保存する)
function demo_guard(string $page): void
{
    global $HPX_DEMO_READONLY;
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $page === 'export.php') return;
    if ((!empty($HPX_DEMO_READONLY) && !demo_editing()) || in_array($page, ['account.php', 'team.php', 'admin.php', 'settings.php', 'sensor.php', 'camera.php'], true)) {
        $_SESSION['flash'] = 'デモ用アカウントでは、この変更はできません。';
        header('Location: ' . $page);
        exit;
    }
    if (!demo_editing()) $_FILES = [];
}

// デモ用アカウントのセンサの記録は、実際のセンサの値を使わずに、見本の値 (ダミー) で作る ($HPX_DEMO_DUMMY = false で止める)
//   巣箱ごとに変化のパターンを変える (DEMO_PATTERNS)。1つめの巣箱は 温度 34℃±1℃・湿度 昼60%/夜90%・重さ 30kgから1日0.3kg増 (昼は0.5kg軽い)
//   温湿度計・重量センサの無い巣箱には、見本の HPX-Core (hpx-demo-2) のセンサを作って紐づける (demo_link_hives)
//   電柵アラート: ずっと正常
//   デモのページを開いたとき (5分に1回まで) と hpx_cron.php で、デモ用アカウントの HPX-Core ごとに
//   ble.csv を30日分作り直し、state.json の受信時刻を今にする。デモ用アカウントのトークンで届いた記録は保存しない (receive.php)
//   作業記録も、架空のメンバー (Aさん・Bさん・Cさん) の分を12か月分足して見せる (hpx_site.php の demo_work_logs)
// デモの架空のメンバー (チーム機能を使っていなくても、作業記録の担当者として出す。hpx_site.php の demo_work_logs)
const DEMO_MEMBERS = ['u_de00000a' => 'Aさん', 'u_de00000b' => 'Bさん', 'u_de00000c' => 'Cさん'];
function demo_members_on(): bool
{
    return demo_dummy_on() && ($GLOBALS['UID'] ?? '') === demo_uid();
}
const DEMO_DAYS = 30;
const DEMO_STEP_S = 600;   // 10分ごとの記録
function demo_dummy_on(): bool
{
    global $HPX_DEMO_DUMMY;
    return ($HPX_DEMO_DUMMY ?? true) !== false && demo_uid() !== '';
}
// センサと時刻ごとに決まる -1〜1 のゆらぎ (作り直しても同じ値になるように)
function demo_noise(string $key, int $t): float { return (crc32($key . '@' . $t) % 2001) / 1000 - 1; }
// 巣箱ごとの変化のパターン (巣箱の並び順に割り当てる。巣箱が多ければ初めから繰り返す)
//   kg: 30日前の重さ  gain: 1日の増減  day: 昼に軽くなる量  temp: [中心, ゆれ幅] (null は外気に合わせて上下する弱い群)
//   hum: [平均, 昼夜の差の半分]  harvest: 何日前に採蜜で減ったか (kg)  feed: 何日ごとに給餌で増えるか (kg)
const DEMO_PATTERNS = [
    ['label' => '標準',     'kg' => 30, 'gain' => 0.3,   'day' => 0.5, 'temp' => [34, 0.5],   'hum' => [75, 15]],
    ['label' => '強群',     'kg' => 36, 'gain' => 0.5,   'day' => 0.9, 'temp' => [34.8, 0.3], 'hum' => [68, 12]],
    ['label' => '採蜜あり', 'kg' => 40, 'gain' => 0.5,   'day' => 0.6, 'temp' => [34.2, 0.4], 'hum' => [72, 14], 'harvest' => [8, 10]],
    ['label' => '弱群',     'kg' => 22, 'gain' => -0.05, 'day' => 0.2, 'temp' => null,        'hum' => [80, 12]],
    ['label' => '給餌中',   'kg' => 25, 'gain' => -0.1,  'day' => 0.3, 'temp' => [33.8, 0.6], 'hum' => [74, 13], 'feed' => [7, 1.8]],
    ['label' => '減少',     'kg' => 34, 'gain' => -0.15, 'day' => 0.4, 'temp' => [33.5, 1.0], 'hum' => [70, 16]],
];
// 見本の値: 温湿度計 [温度, 湿度]、重量センサ kg
function demo_value(array $pt, string $kind, string $mac, int $t, int $start)
{
    $hr = (int)date('G', $t) + (int)date('i', $t) / 60;   // その日の時刻 (時)
    $days = ($t - $start) / 86400;
    if ($kind === 'w') {
        $kg = $pt['kg'] + $pt['gain'] * $days - $pt['day'] / 2 - $pt['day'] / 2 * cos(2 * M_PI * ($hr - 13) / 24) + 0.02 * demo_noise($mac, $t);
        if (isset($pt['harvest']) && $t >= $start + (DEMO_DAYS - $pt['harvest'][0]) * 86400) $kg -= $pt['harvest'][1];
        if (isset($pt['feed'])) $kg += $pt['feed'][1] * floor($days / $pt['feed'][0]);
        return $kg;
    }
    if ($pt['temp'] === null) {   // 弱い群: 巣の中の温度を保てず、外気と一緒に上下する
        $temp = 27 + 4 * sin(2 * M_PI * ($hr - 9) / 24) + 0.3 * demo_noise($mac . 't', $t);
    } else {
        $temp = $pt['temp'][0] + $pt['temp'][1] * sin(2 * M_PI * ($days / 3 + (crc32($mac) % 100) / 100)) + 0.1 * demo_noise($mac . 't', $t);
    }
    $hum = $pt['hum'][0] - $pt['hum'][1] * cos(2 * M_PI * ($hr - 14) / 24) + 2 * demo_noise($mac . 'h', $t);
    return [$temp, max(40, min(98, $hum))];
}
// 温湿度計・重量センサの無い巣箱に、見本の HPX-Core (DE000000DE02) のセンサを作って紐づける。
// デモの site.json と初期状態 (demo_seed) の両方に書く。戻り値: "ハブid/mac" => パターンの番号
//   $wDiv: 重量センサの "ハブid/mac" => 巣箱の重さを何で割るとそのセンサの値になるか (1台なら2、2台なら2、3台以上はその台数)
function demo_link_hives(string $hubsDir, array &$wDiv = []): array
{
    $vHub = 'DE000000DE02';
    $vDir = $hubsDir . '/' . $vHub;
    $vSt = json_decode((string)@file_get_contents($vDir . '/state.json'), true);
    $vSens = is_array($vSt['sensors'] ?? null) ? $vSt['sensors'] : [];
    $kinds = [];   // "ハブid/mac" => th / w
    foreach (glob($hubsDir . '/*/state.json') ?: [] as $p) {
        $st = json_decode((string)@file_get_contents($p), true);
        foreach ((is_array($st['sensors'] ?? null) ? $st['sensors'] : []) as $mac => $sn) $kinds[basename(dirname($p)) . '/' . $mac] = (string)($sn['kind'] ?? 'th');
    }
    $pattern = [];
    $vChanged = false;
    foreach ([account_site_dir(demo_uid()), demo_seed_dir()] as $n => $siteDir) {
        $path = $siteDir . '/site.json';
        if (!is_file($path)) continue;
        $site = json_decode((string)@file_get_contents($path), true);
        if (!is_array($site['hives'] ?? null)) continue;
        $changed = false;
        $i = 0;
        foreach ($site['hives'] as $hid => &$x) {
            if (!is_array($x)) continue;
            $sens = array_values((array)($x['sensors'] ?? []));
            $has = array_map(fn($k) => $kinds[$k] ?? '', $sens);
            foreach (['th' => '温湿度', 'w' => '重量'] as $kind => $label) {
                if (in_array($kind, $has, true)) continue;
                $mac = 'DE:02:' . implode(':', str_split(sprintf('%08X', crc32($hid . $kind)), 2));
                $vSens[$mac] = ['name' => $label . ' ' . (string)($x['name'] ?? ''), 'kind' => $kind] + (is_array($vSens[$mac] ?? null) ? $vSens[$mac] : []);
                $kinds[$vHub . '/' . $mac] = $kind;
                $sens[] = $vHub . '/' . $mac;
                $changed = $vChanged = true;
            }
            $x['sensors'] = $sens;
            if ($n === 0) {
                $ws = array_values(array_filter($sens, fn($k) => ($kinds[$k] ?? '') === 'w'));
                foreach ($sens as $k) $pattern[$k] = $i % count(DEMO_PATTERNS);
                foreach ($ws as $k) $wDiv[$k] = max(2, count($ws));
            }
            $i++;
        }
        unset($x);
        if ($changed && @file_put_contents($path . '.tmp', json_encode($site, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false) {
            @rename($path . '.tmp', $path);
            if ($n === 0) owner_site(demo_uid(), true);
        }
    }
    if ($vChanged) {
        @mkdir($vDir, 0700, true);
        file_put_contents($vDir . '/state.json', json_encode(['name' => 'hpx-demo-2', 'sensors' => $vSens] + (is_array($vSt) ? $vSt : []), JSON_UNESCAPED_UNICODE));
    }
    return $pattern;
}
function demo_sensor_fill(bool $force = false): void
{
    if (!demo_dummy_on()) return;
    $hubsDir = account_hubs_dir(demo_uid());
    $flag = $hubsDir . '/.demo_filled';
    $now = time();
    if (!$force && $now - (int)@filemtime($flag) < 300) return;
    @mkdir($hubsDir, 0700, true);
    @touch($flag);
    if (!glob($hubsDir . '/*/state.json')) {
        // HPX-Core がまだ無ければ、見本の1台 (温湿度計2台・重量センサ1台) を作る
        $dir = $hubsDir . '/DE000000DE01';
        @mkdir($dir, 0700, true);
        file_put_contents($dir . '/state.json', json_encode(['name' => 'hpx-demo', 'sensors' => [
            'DE:00:00:00:00:01' => ['name' => '温湿度センサ1', 'kind' => 'th'],
            'DE:00:00:00:00:02' => ['name' => '温湿度センサ2', 'kind' => 'th'],
            'DE:00:00:00:00:03' => ['name' => '重量センサ1', 'kind' => 'w'],
        ]], JSON_UNESCAPED_UNICODE));
    }
    $wDiv = [];
    $pattern = demo_link_hives($hubsDir, $wDiv);
    $end = intdiv($now, DEMO_STEP_S) * DEMO_STEP_S;
    $start = $end - DEMO_DAYS * 86400;
    foreach (glob($hubsDir . '/*/state.json') ?: [] as $statePath) {
        $dir = dirname($statePath);
        $hubId = basename($dir);
        $lock = fopen($dir . '/.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) continue;
        $st = json_decode((string)@file_get_contents($statePath), true);
        $sens = is_array($st['sensors'] ?? null) ? $st['sensors'] : [];
        // 重量: 較正 (生の値 ↔ kg) が無いセンサには見本の較正を入れる
        $cal = wcal_with_inherit($dir);
        $calAdd = false;
        foreach ($sens as $mac => $s) {
            if (is_array($s) && ($s['kind'] ?? '') === 'w' && !isset($cal[$mac]['per_kg'])) {
                $cal[$mac] = ['zero' => 100000, 'per_kg' => 20000.0, 'src' => 'demo', 'updated' => date('Y-m-d H:i:s', $now)];
                $calAdd = true;
            }
        }
        if ($calAdd) file_put_contents($dir . '/weight_cal.json', json_encode($cal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        // 初めて見本の値にするときは、それまでの実際の記録を ble_real_backup.csv に残す
        if (is_file($dir . '/ble.csv') && !is_file($dir . '/.demo_dummy')) @copy($dir . '/ble.csv', $dir . '/ble_real_backup.csv');
        @touch($dir . '/.demo_dummy');
        $tmp = $dir . '/ble.csv.tmp';
        $fp = fopen($tmp, 'wb');
        if (!$fp) { flock($lock, LOCK_UN); fclose($lock); continue; }
        fputcsv($fp, ['time', 'epoch', 'seq', 'temp_c', 'humidity_pct', 'battery_pct', 'rssi_dbm', 'ts_known', 'sensor', 'mac', 'kind', 'raw', 'spread', 'batt_v',
                      'fence_state', 'fence_events', 'fence_blink_ago_s', 'temp2_c']);
        $seq = 0;
        for ($t = $start; $t <= $end; $t += DEMO_STEP_S) {
            $amb = 15 + 6 * sin(2 * M_PI * ((int)date('G', $t) + (int)date('i', $t) / 60 - 9) / 24);   // まわりの気温 (ノード内部の温度に使う)
            foreach ($sens as $mac => $s) {
                if (!is_array($s)) continue;
                $mac = (string)$mac;
                $kind = (string)($s['kind'] ?? 'th');
                $nm = (string)($s['name'] ?? '');
                $pt = DEMO_PATTERNS[$pattern[$hubId . '/' . $mac] ?? 0];
                $rssi = -60 + (int)round(demo_noise($mac . 'r', $t) * 5);
                $seq++;
                if ($kind === 'w') {
                    $c = $cal[$mac];
                    // 見本の値は巣箱の重さ。センサ1台なら片側なので半分、2台なら2台で分ける (巣箱ページで2倍・合計にして戻る)
                    $kgS = demo_value($pt, 'w', $mac, $t, $start) / ($wDiv[$hubId . '/' . $mac] ?? 2);
                    $raw = (int)round(($kgS + (float)($c['offset_kg'] ?? 0)) * (float)$c['per_kg'] + (float)$c['zero']);
                    fputcsv($fp, [date('Y-m-d H:i:s', $t), $t, $seq, round($amb, 2), '', '', $rssi, 1, $nm, $mac, 'w', $raw, 40, 3.3, '', '', '', '']);
                } elseif ($kind === 'fa') {
                    fputcsv($fp, [date('Y-m-d H:i:s', $t), $t, $seq, round($amb, 2), '', '', $rssi, 1, $nm, $mac, 'fa', '', '', 3.3, 0, 0, '', '']);
                } else {
                    [$temp, $hum] = demo_value($pt, 'th', $mac, $t, $start);
                    fputcsv($fp, [date('Y-m-d H:i:s', $t), $t, $seq, round($temp, 2), round($hum, 2), 100, $rssi, 1, $nm, $mac, 'th', '', '', '', '', '', '', '']);
                }
            }
        }
        fclose($fp);
        rename($tmp, $dir . '/ble.csv');
        foreach ($sens as $mac => &$s) {
            if (!is_array($s)) continue;
            $s['last_ts'] = $s['last_epoch'] = $end;
            unset($s['w_fail'], $s['w_fail_epoch']);
            if (($s['kind'] ?? '') === 'fa') { $s['fence_state'] = 0; $s['fence_events'] = 0; $s['fence_bv'] = 3.3; }
        }
        unset($s);
        $st = ['name' => (string)($st['name'] ?? '') === 'hpx-demo-2' ? 'hpx-demo-2' : 'hpx-demo', 'last_rx' => $now,
               'fw' => (string)($st['fw'] ?? '') !== '' ? (string)$st['fw'] : 'HPX-Core.1.14',
               'build' => (int)($st['build'] ?? 0), 'vbat' => null, 'rssi' => -58, 'sensors' => $sens];
        file_put_contents($statePath, json_encode($st, JSON_UNESCAPED_UNICODE));
        @unlink($dir . '/.hub_missing');
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// デモの初期状態を保存する / 初期状態に戻す
function dir_remove(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        is_dir($p) && !is_link($p) ? dir_remove($p) : @unlink($p);
    }
    @rmdir($dir);
}
function dir_copy(string $from, string $to): void
{
    @mkdir($to, 0755, true);
    foreach (scandir($from) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        is_dir("$from/$f") ? dir_copy("$from/$f", "$to/$f") : @copy("$from/$f", "$to/$f");
    }
}
function demo_seed_save(): bool
{
    $uid = demo_uid();
    if ($uid === '' || !is_dir(account_site_dir($uid))) return false;
    dir_remove(demo_seed_dir());
    dir_copy(account_site_dir($uid), demo_seed_dir());
    return true;
}
function demo_reset(): bool
{
    $uid = demo_uid();
    if ($uid === '' || !is_dir(demo_seed_dir())) return false;
    dir_remove(account_site_dir($uid));
    dir_copy(demo_seed_dir(), account_site_dir($uid));
    return true;
}

// ====================================================================
// 重量センサの較正の引き継ぎ
//   較正はセンサそのもの(MAC)の値なので、HPX-Core を別のアカウントに付け替えたり、センサを別の HPX-Core に移したりしても使える。
//   そのハブの weight_cal.json に無い重量センサは、ほかのハブ(どのアカウントでも)の weight_cal.json から
//   同じ MAC の一番新しい較正を写して保存する(inherited に写した日時)。差し引く重さ(offset_kg)も一緒に写す
// ====================================================================

// ---- 温湿度計の温度の補正 ----
// Inkbird (IBS-TH2) は実際より高く出るので、一律で TH_INKBIRD_OFFSET_C を足す(2026-11 TARO の実測で平均 +0.9℃)。
// さらに温湿度計ごとの補正(センサページで入れる)をハブのフォルダの th_cal.json { "<mac>": { offset_c } } から足す。
// 記録(ble.csv)は測った値のまま残し、読むときに補正する。
// Inkbird かどうかは、HPX-Core v1.18 以降が送る機種(state.json の sensors[mac].model)で見分ける。
//   機種がまだ届いていない温湿度計は記録の形で見る: kind=th でサーミスター(temp2_c)も電池電圧(batt_v)も無いもの
//   (この形だと SwitchBot も Inkbird と同じ扱いになる)。サーミスターか電池電圧がある記録は HPX-TH(機種は送られてこない)。
const TH_INKBIRD_OFFSET_C = -0.9;
// HPX-TH は温度・湿度とも一律で下げる(2026-11-05 TARO 指定)。温度はサーミスター(無いときは SHT40)に足す
const TH_HPXTH_OFFSET_C = -0.5;
const TH_MODEL_OFFSET_C = ['inkbird' => TH_INKBIRD_OFFSET_C, 'hpx_th' => TH_HPXTH_OFFSET_C];
// 湿度(%)の機種ごとの補正(2026-11 TARO 指定)。温湿度計ごとの補正(th_cal.json の offset_h)をさらに足す
const TH_MODEL_OFFSET_H = ['switchbot' => -2.5, 'switchbot_outdoor' => 3.0, 'hpx_th' => -0.5];
const TH_MODEL_NAMES = ['inkbird' => 'Inkbird', 'switchbot' => 'SwitchBot 温湿度計', 'switchbot_outdoor' => 'SwitchBot 防水温湿度計', 'hpx_th' => 'HPX-TH', 'broodminder' => 'BroodMinder'];

// ble.csv (古い順に書き足していくファイル) で、$since より前の記録を読み飛ばす位置を探す (2026-11-17、ページを速く出すため)。
//   ファイルの途中を二分探索して、epoch が ($since - $margin) より前の行の次の行頭に移る。記録が少し前後して届くことがあるので、
//   余裕 ($margin、初期 3日) をとって手前から読む (読んだ後は呼び出し側で $since より前を飛ばす)。
//   $fh は見出しの行を読み終えた位置で渡す。返り値: 読み始める位置 (fseek 済み)。1MB より小さいファイルは先頭から
function ble_seek($fh, int $epochCol, int $since, int $margin = 3 * 86400): int
{
    $start = (int)ftell($fh);
    $target = $since - $margin;
    $size = (int)(fstat($fh)['size'] ?? 0);
    if ($since <= 0 || $size - $start < 1048576) return $start;
    $lo = $start; $hi = $size;
    while ($hi - $lo > 65536) {
        $mid = intdiv($lo + $hi, 2);
        fseek($fh, $mid);
        fgets($fh);   // 途中の行の残りを飛ばす
        $r = fgetcsv($fh);
        $ep = is_array($r) ? (int)($r[$epochCol] ?? 0) : 0;
        if ($ep > 0 && $ep < $target) $lo = $mid; else $hi = $mid;
    }
    fseek($fh, $lo);
    if ($lo > $start) fgets($fh);
    return (int)ftell($fh);
}

function th_cal_load(string $dir): array
{
    static $cache = [];
    if (!isset($cache[$dir])) {
        $c = json_decode((string)@file_get_contents($dir . '/th_cal.json'), true);
        $cache[$dir] = is_array($c) ? $c : [];
    }
    return $cache[$dir];
}

// 温湿度計の機種(state.json の sensors[mac].model)。まだ届いていなければ ''
function th_model(string $dir, string $mac): string
{
    static $models = [];
    if (!isset($models[$dir])) {
        $st = json_decode((string)@file_get_contents($dir . '/state.json'), true);
        $models[$dir] = [];
        foreach ((array)($st['sensors'] ?? []) as $m => $e) if (is_array($e) && isset($e['model'])) $models[$dir][(string)$m] = (string)$e['model'];
    }
    return $models[$dir][$mac] ?? '';
}

// 機種。届いていなければ記録の形で決める($inkbirdShape: サーミスターも電池電圧も無い記録か。false なら HPX-TH)
function th_model_or_shape(string $dir, string $mac, bool $inkbirdShape): string
{
    $model = th_model($dir, $mac);
    return $model !== '' ? $model : ($inkbirdShape ? 'inkbird' : 'hpx_th');
}

// その温湿度計の記録に足す湿度(%)。機種ごとの補正 + 温湿度計ごとの補正
function th_hum_corr(string $dir, string $mac, bool $inkbirdShape): float
{
    return (float)(TH_MODEL_OFFSET_H[th_model_or_shape($dir, $mac, $inkbirdShape)] ?? 0) + (float)(th_cal_load($dir)[$mac]['offset_h'] ?? 0);
}

// 湿度に補正を足して 0〜100 に収める(記録が無ければ null)
function th_hum_fix(?float $h, string $dir, string $mac, bool $inkbirdShape): ?float
{
    return $h === null ? null : max(0.0, min(100.0, $h + th_hum_corr($dir, $mac, $inkbirdShape)));
}

// その温湿度計の記録に足す温度(℃)。$inkbirdShape: その記録が Inkbird の形か(機種が分からないときに使う)
function th_temp_corr(string $dir, string $mac, bool $inkbirdShape): float
{
    return (float)(TH_MODEL_OFFSET_C[th_model_or_shape($dir, $mac, $inkbirdShape)] ?? 0) + (float)(th_cal_load($dir)[$mac]['offset_c'] ?? 0);
}

function wcal_with_inherit(string $dir): array
{
    static $all = null;
    $path = $dir . '/weight_cal.json';
    $cal = json_decode((string)@file_get_contents($path), true);
    if (!is_array($cal)) $cal = [];
    $st = json_decode((string)@file_get_contents($dir . '/state.json'), true);
    $need = [];
    foreach ((is_array($st['sensors'] ?? null) ? $st['sensors'] : []) as $mac => $s) {
        if (is_array($s) && ($s['kind'] ?? '') === 'w' && !isset($cal[$mac]['per_kg'])) $need[] = (string)$mac;
    }
    if (!$need) return $cal;
    if ($all === null) {
        $all = [];
        $demoHubs = demo_dummy_on() ? account_hubs_dir(demo_uid()) . '/' : '';   // デモの見本の較正は、ほかのアカウントに引き継がない
        foreach (glob(data_dir() . '/accounts/*/hubs/*/weight_cal.json') ?: [] as $p) {
            if ($demoHubs !== '' && str_starts_with($p, $demoHubs)) continue;
            $c = json_decode((string)@file_get_contents($p), true);
            foreach (is_array($c) ? $c : [] as $mac => $v) {
                if (!is_array($v) || !isset($v['per_kg']) || (float)$v['per_kg'] == 0.0) continue;
                if (!isset($all[$mac]) || strcmp((string)($v['updated'] ?? ''), (string)($all[$mac]['updated'] ?? '')) > 0) $all[$mac] = $v;
            }
        }
    }
    $added = false;
    foreach ($need as $mac) {
        if (!isset($all[$mac])) continue;
        $cal[$mac] = ['inherited' => date('Y-m-d H:i:s')] + $all[$mac];
        $added = true;
    }
    if ($added && @file_put_contents($path . '.tmp', json_encode($cal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false) @rename($path . '.tmp', $path);
    return $cal;
}

// ログイン後に戻るページ(自分のページだけ許す)
function auth_next(string $next): string
{
    return preg_match('/^(apiary|hive|memo|task|work|sensor|camera|team|account|admin|offline)\.php(\?[^\s<>"]*)?$/', $next) ? $next : 'apiary.php';
}

// 上部メニューの右端の設定マーク。押すとセンサ・チーム・アカウント・ログアウト・サーバー管理(サーバー管理者だけ)が出る。蜂場ページとセンサページで共通
function auth_nav_right(string $active): string
{
    global $ME;
    $items = ['sensor.php' => 'センサ', 'camera.php' => 'カメラ', 'team.php' => 'チーム', 'account.php' => 'マイアカウント', 'offline.php' => 'オフライン'];
    $o = '<details class="setm r"><summary title="設定"' . (isset($items[$active]) || $active === 'admin.php' || $active === 'settings.php' ? ' class="on"' : '') . '>'
        . '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M19.14 12.94a7.6 7.6 0 0 0 0-1.88l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.3 7.3 0 0 0-1.63-.94l-.36-2.54a.5.5 0 0 0-.5-.42h-3.84a.5.5 0 0 0-.5.42l-.36 2.54c-.59.24-1.13.56-1.63.94l-2.39-.96a.5.5 0 0 0-.6.22L2.71 8.84a.5.5 0 0 0 .12.64l2.03 1.58a7.6 7.6 0 0 0 0 1.88l-2.03 1.58a.5.5 0 0 0-.12.64l1.92 3.32c.13.22.39.3.6.22l2.39-.96c.5.38 1.04.7 1.63.94l.36 2.54c.04.24.25.42.5.42h3.84c.25 0 .46-.18.5-.42l.36-2.54c.59-.24 1.13-.56 1.63-.94l2.39.96c.22.08.47 0 .6-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58zM12 15.6A3.6 3.6 0 1 1 12 8.4a3.6 3.6 0 0 1 0 7.2z"/></svg>'
        . '<span class="sr">設定</span></summary><div class="setmenu"><div class="who" title="' . h((string)($ME['email'] ?? '')) . '">' . h((string)($ME['name'] ?? '')) . '</div>';
    foreach ($items as $f => $lb) $o .= '<a href="' . $f . '"' . ($active === $f ? ' class="on"' : '') . '>' . h($lb) . '</a>';
    $o .= '<a href="' . h($active !== '' ? $active : 'apiary.php') . '?logout=1">ログアウト</a>';
    if (!empty($ME['admin'])) $o .= '<a href="admin.php"' . ($active === 'admin.php' ? ' class="on"' : '') . '>サーバー管理</a>';
    if (!empty($ME['admin'])) $o .= '<a href="settings.php"' . ($active === 'settings.php' ? ' class="on"' : '') . '>サーバーの設定</a>';
    // 外を押したら閉じる
    return $o . '</div></details><script>document.addEventListener("click",function(e){document.querySelectorAll("details.setm[open]").forEach(function(d){if(!d.contains(e.target))d.open=false;});});</script>';
}

// タスクの担当者(アカウントidの配列)。複数選べる assignees と、前の版の1人だけの assignee の両方を読む
function todo_assignees(array $t): array
{
    if (isset($t['assignees']) && is_array($t['assignees'])) return array_values(array_filter(array_map('strval', $t['assignees']), 'is_uid'));
    $as = (string)($t['assignee'] ?? '');
    return is_uid($as) ? [$as] : [];
}

// ログインの失敗を数える。止めている間なら残り秒数を返す
function login_locked(string $email): int
{
    $f = json_load(data_dir() . '/login_fail.json')[$email] ?? null;
    return is_array($f) && (int)($f['until'] ?? 0) > time() ? (int)$f['until'] - time() : 0;
}
function login_fail(string $email, bool $failed): void
{
    if (demo_email() !== '' && email_norm($email) === demo_email()) return;   // パスワードを公開しているので、間違いが続いても止めない
    json_update(data_dir() . '/login_fail.json', function (array $all) use ($email, $failed) {
        $now = time();
        $all = array_filter($all, fn($f) => is_array($f) && max((int)($f['until'] ?? 0), (int)($f['last'] ?? 0) + 86400) > $now);
        if (!$failed) { unset($all[$email]); return $all; }
        $f = $all[$email] ?? ['n' => 0];
        $f['n'] = (int)$f['n'] + 1;
        $f['last'] = $now;
        if ($f['n'] >= A_FAIL_MAX) { $f['until'] = $now + A_FAIL_LOCK_S; $f['n'] = 0; }
        $all[$email] = $f;
        return $all;
    });
}

function pw_check_new(string $pw, string $pw2): string
{
    if (mb_strlen($pw) < A_PW_MIN) return 'パスワードは ' . A_PW_MIN . ' 文字以上にしてください。';
    if (strlen($pw) > 200) return 'パスワードが長すぎます。';
    if ($pw !== $pw2) return '確認のパスワードが違います。';
    return '';
}

// 通知先の入力(カンマ・空白・改行区切り)を確かめて「a@x, b@y」にする。正しくないアドレスがあれば null
function notify_clean(string $v): ?string
{
    $list = array_values(array_unique(array_filter(array_map('email_norm', preg_split('/[\s,、]+/u', $v) ?: []), fn($x) => $x !== '')));
    foreach ($list as $e) if (!email_ok($e)) return null;
    return count($list) > A_NOTIFY_MAX ? null : implode(', ', $list);
}

// 初期設定の途中で receive.php が古い場所に書かないよう、移す間は全体のロックを取る(receive.php は共有ロック)
function migrate_lock(int $mode)
{
    $f = @fopen(data_dir() . '/.migrate.lock', 'c');
    if ($f) flock($f, $mode);
    return $f;
}

// chart_dual($tip 付き、hpx_site.php)・センサページの chart_svg のグラフに、マウスを乗せる・タッチすると縦線と一番近い記録の日時と値を出す
function chart_tip_js(): string
{
    return <<<'JS'
<style>.tipw{position:relative;touch-action:pan-y}.tip{display:none;position:absolute;top:4px;z-index:5;background:rgba(255,255,255,.95);border:1px solid #aab;border-radius:4px;padding:3px 7px;font-size:.8em;line-height:1.35;white-space:nowrap;pointer-events:none;box-shadow:0 1px 3px rgba(0,0,0,.15)}.tip .tt{font-weight:bold;color:#333}</style>
<script>
(function(){
  var NS = 'http://www.w3.org/2000/svg';
  function near(p, e){ var lo = 0, hi = p.length - 1; while (hi - lo > 1){ var m = (lo + hi) >> 1; if (p[m][0] < e) lo = m; else hi = m; } return Math.abs(p[lo][0] - e) <= Math.abs(p[hi][0] - e) ? p[lo] : p[hi]; }
  function two(n){ return (n < 10 ? '0' : '') + n; }
  function esc(t){ return String(t).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
  document.querySelectorAll('.tipw').forEach(function(w){
    var d = JSON.parse(w.getAttribute('data-tip')); if (!d.s.length) return;
    var svg = w.querySelector('svg'), g = document.createElementNS(NS, 'g'), box = document.createElement('div');
    box.className = 'tip'; w.appendChild(box); svg.appendChild(g); g.style.display = 'none';
    var ln = document.createElementNS(NS, 'line'); ln.setAttribute('y1', d.y0); ln.setAttribute('y2', d.y1); ln.setAttribute('stroke', '#888'); ln.setAttribute('stroke-dasharray', '3 3'); g.appendChild(ln);
    var dots = d.s.map(function(s){ var c = document.createElementNS(NS, 'circle'); c.setAttribute('r', d.W < 400 ? 3.5 : 4); c.setAttribute('fill', s.c); c.setAttribute('stroke', '#fff'); c.setAttribute('stroke-width', '1.5'); g.appendChild(c); return c; });
    var gap = (d.t - d.f) / 25;   // これより離れた記録しかないところ(記録の途切れ)では値を出さない
    function show(ev){
      var r = svg.getBoundingClientRect(), k = d.W / r.width, vx = (ev.clientX - r.left) * k;
      if (vx < d.x0 || vx > d.x1){ hide(); return; }
      var e = d.f + (vx - d.x0) / (d.x1 - d.x0) * (d.t - d.f), best = null, rows = '';
      var ps = d.s.map(function(s){ var p = near(s.p, e); if (Math.abs(p[0] - e) <= gap && (!best || Math.abs(p[0] - e) < Math.abs(best[0] - e))) best = p; return p; });
      d.s.forEach(function(s, i){
        var p = ps[i];
        // 日付だけのグラフ(d.day)は、一番近い記録と同じ日の記録だけを出す
        if (Math.abs(p[0] - e) > gap || (d.day && Math.abs(p[0] - best[0]) >= 43200)){ dots[i].style.display = 'none'; return; }
        dots[i].style.display = ''; dots[i].setAttribute('cx', d.x0 + (p[0] - d.f) / (d.t - d.f) * (d.x1 - d.x0)); dots[i].setAttribute('cy', p[2]);
        rows += '<div><span style="color:' + s.c + '">●</span> ' + esc(s.n) + ' <b>' + p[1].toFixed(s.d) + '</b> ' + esc(s.u) + '</div>';
      });
      if (!best){ hide(); return; }
      var bx = d.x0 + (best[0] - d.f) / (d.t - d.f) * (d.x1 - d.x0), t = new Date(best[0] * 1000);
      ln.setAttribute('x1', bx); ln.setAttribute('x2', bx); g.style.display = '';
      box.innerHTML = '<div class="tt">' + (t.getMonth() + 1) + '/' + t.getDate() + (d.day ? '' : ' ' + two(t.getHours()) + ':' + two(t.getMinutes())) + '</div>' + rows;
      box.style.display = 'block';
      var px = bx / k, bw = box.offsetWidth;   // 線の右に出す。右端に近いときは左に出す
      box.style.left = (px + 10 + bw > r.width ? Math.max(0, px - 10 - bw) : px + 10) + 'px';
    }
    function hide(){ g.style.display = 'none'; box.style.display = 'none'; }
    w.addEventListener('pointermove', show);
    w.addEventListener('pointerdown', show);
    w.addEventListener('pointerleave', function(ev){ if (ev.pointerType === 'mouse') hide(); });
    document.addEventListener('pointerdown', function(ev){ if (!w.contains(ev.target)) hide(); });
  });
})();
</script>
JS;
}
