<?php
// hiveplusx.com の「作った・使ったレポート」の管理ページ (www/hiveplusx/report_admin.php)。
//   届いたレポートを確かめて、公開する・非公開にする・削除する。管理者からのひとこと (お礼など) も付けられる。
//   HPX-note のアカウントとは別に、config.php の $HPX_REPORT_ADMIN_PW のパスワードで入る。
//   サイトの一番下の歯車マークから開く。続けて5回間違えると、15分ログインできない。
declare(strict_types=1);

$APP = __DIR__ . '/hpx';
require $APP . '/config.php';
require_once $APP . '/hpx_report.php';

if (!function_exists('h')) { function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); } }
function nlh(string $s): string { return nl2br(h($s), false); }
function go(string $url, string $flash = ''): void { if ($flash !== '') $_SESSION['flash'] = $flash; header('Location: ' . $url, true, 303); exit; }

r_admin_session();
$ok = r_admin_ok();
$msg = '';
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($isPost && !hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    exit('画面を開き直してから、もう一度送ってください。');
}
if (isset($_GET['logout'])) { r_admin_logout(); header('Location: report_admin.php', true, 303); exit; }

if (!$ok && $isPost && ($_POST['act'] ?? '') === 'login') {
    $wait = r_admin_locked();
    if ($wait > 0) $msg = '続けて間違えたため、しばらくログインできません。あと' . (int)ceil($wait / 60) . '分ほど待ってください。';
    elseif (r_admin_login((string)($_POST['pw'] ?? ''))) go('report_admin.php');
    else $msg = 'パスワードが違います。';
}

if ($ok && $isPost) {
    $id = (string)($_POST['id'] ?? '');
    $act = (string)($_POST['act'] ?? '');
    if (!r_get($id)) go('report_admin.php', 'そのレポートは見つかりませんでした。');
    if ($act === 'delete') { r_delete($id); go('report_admin.php', 'レポートを削除しました (写真も消しました)。'); }
    $st = ['publish' => 'public', 'hide' => 'hidden'][$act] ?? '';
    $reply = mb_substr(trim((string)($_POST['reply'] ?? '')), 0, R_REPLY_MAX);
    r_update(function (array $l) use ($id, $act, $st, $reply) {
        if (!isset($l[$id])) return $l;
        if ($st !== '') { $l[$id]['status'] = $st; $l[$id]['decided'] = time(); }
        if ($act === 'reply') $l[$id]['reply'] = $reply;
        return $l;
    });
    go('report_admin.php#' . $id, ['publish' => '公開しました。', 'hide' => '非公開にしました。', 'reply' => 'ひとことを保存しました。'][$act] ?? '');
}
$flash = (string)($_SESSION['flash'] ?? '');
unset($_SESSION['flash']);
$csrf = '<input type="hidden" name="csrf" value="' . h((string)$_SESSION['csrf']) . '">';
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>レポートの管理 - Hive Plus X</title>
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
         font-family:"Inter","Helvetica Neue",Arial,"Hiragino Kaku Gothic ProN","Hiragino Sans","Meiryo","Yu Gothic","Noto Sans JP",sans-serif; -webkit-font-smoothing:antialiased; }
  a { color:inherit; }
  .wrap { max-width:1080px; margin:0 auto; padding:0 20px; }
  .narrow { max-width:720px; }
  /* ヘッダー (ほかのページと同じメニュー) */
  header { background:var(--forest); color:var(--on-forest); position:sticky; top:0; z-index:10; }
  header .wrap { display:flex; align-items:center; justify-content:space-between; gap:16px; height:60px; }
  header .brand img { height:26px; display:block; }
  nav ul { list-style:none; display:flex; gap:22px; font-size:14px; }
  nav a { text-decoration:none; opacity:.9; padding:4px 0; border-bottom:2px solid transparent; }
  nav a:hover { opacity:1; border-bottom-color:var(--honey); }
  .menu-btn { display:none; background:none; border:0; color:inherit; font-size:24px; cursor:pointer; }
  @media (max-width:760px) {
    .menu-btn { display:block; }
    nav { display:none; position:absolute; top:60px; left:0; right:0; background:var(--forest); }
    nav.open { display:block; }
    nav ul { flex-direction:column; gap:0; padding:8px 20px 16px; }
    nav li a { display:block; padding:10px 0; border-bottom:1px solid rgba(255,255,255,.12); }
  }
  main { padding:80px 0 160px; }
  @media (max-width:760px) { main { padding:48px 0 120px; } }
  .eyebrow { font-size:14px; font-weight:700; letter-spacing:.24em; color:var(--honey); text-transform:uppercase; }
  h1 { font-size:clamp(24px, 3.2vw, 30px); margin-top:6px; line-height:1.4; }
  h2 { font-size:20px; margin-top:56px; }
  .lead { color:var(--sub); margin-top:8px; }
  .box { margin-top:20px; padding:14px 18px; border-radius:12px; background:var(--panel); border:1px solid var(--line); }
  .box.err { border-color:var(--err); color:var(--err); }
  .box.ok { background:var(--honey-soft); border-color:transparent; }
  .login { margin-top:28px; display:flex; gap:10px; flex-wrap:wrap; max-width:520px; }
  input[type=password], textarea { font:inherit; color:var(--text); background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:10px 14px; }
  input[type=password] { flex:1; min-width:200px; }
  textarea { width:100%; min-height:90px; margin-top:8px; }
  button { font:inherit; font-weight:600; font-size:14px; padding:9px 20px; border-radius:999px; border:1.5px solid var(--forest); background:var(--panel); color:var(--text); cursor:pointer; }
  button.main { background:var(--honey); border-color:var(--honey); color:#1F2328; }
  button.del { border-color:var(--err); color:var(--err); }
  .muted { color:var(--sub); font-size:13px; }
  .top { display:flex; justify-content:space-between; align-items:flex-end; gap:12px; flex-wrap:wrap; }
  .top a { font-size:14px; }
  .item { margin-top:16px; padding:18px 20px; border-radius:14px; background:var(--panel); border:1px solid var(--line); scroll-margin-top:80px; }
  .item p { margin-top:6px; }
  .item .c { white-space:pre-wrap; word-break:break-word; }
  .ph { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
  .ph img { height:140px; width:auto; max-width:100%; border-radius:8px; border:1px solid var(--line); display:block; }
  .ops { display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; }
  .ops form { margin:0; }
  details { margin-top:10px; }
  summary { cursor:pointer; font-size:14px; color:var(--sub); }
  footer { background:var(--forest); color:var(--on-forest); padding:36px 0; font-size:13px; }
  footer .wrap { display:flex; justify-content:space-between; gap:20px; flex-wrap:wrap; }
  footer ul { list-style:none; display:flex; gap:18px; flex-wrap:wrap; }
  footer a { opacity:.85; text-decoration:none; }
  footer a:hover { opacity:1; text-decoration:underline; }
  footer small { opacity:.7; display:block; }
</style>
</head>
<body>
<header>
  <div class="wrap">
    <a class="brand" href="./"><img src="img/hivex_reverse.svg" alt="Hive Plus X"></a>
    <button class="menu-btn" aria-label="メニュー" onclick="document.querySelector('nav').classList.toggle('open')">☰</button>
    <nav>
      <ul>
        <li><a href="app.html">HPX-note</a></li>
        <li><a href="devices.html">機器をつくる</a></li>
        <li><a href="install.html">設置ガイド</a></li>
        <li><a href="download.html">ダウンロード</a></li>
        <li><a href="index.html#community">コミュニティ</a></li>
      </ul>
    </nav>
  </div>
</header>
<main>
  <div class="wrap narrow">
    <p class="eyebrow">Admin</p>
<?php if (!$ok): ?>
    <h1>レポートの管理</h1>
<?php if (r_admin_pw() === ''): ?>
    <div class="box err">管理用のパスワードがまだ決まっていません。hpx/config.php に <code>$HPX_REPORT_ADMIN_PW = '決めたパスワード';</code> を書いてください (8文字以上)。</div>
<?php else: ?>
    <p class="lead">管理用のパスワードを入れてください。</p>
<?php if ($msg !== ''): ?><div class="box err"><?= h($msg) ?></div><?php endif; ?>
    <form class="login" method="post" action="report_admin.php"><?= $csrf ?><input type="hidden" name="act" value="login">
      <input type="password" name="pw" required autofocus autocomplete="current-password" aria-label="パスワード">
      <button class="main" type="submit">ログイン</button></form>
<?php endif; ?>
<?php else: ?>
    <div class="top"><h1>作った・使ったレポートの管理</h1><a href="report_admin.php?logout=1">ログアウト</a></div>
<?php if ($flash !== ''): ?><div class="box ok"><?= h($flash) ?></div><?php endif; ?>
    <p class="lead">「公開する」を押したレポートだけが、<a href="report.php">作った・使ったレポート</a>のページとトップページに出ます。顔・車のナンバー・蜂場の場所がわかるものが写っていないか確かめてから公開してください。メールアドレスは公開されません。</p>
<?php
    $groups = ['pending' => [], 'public' => [], 'hidden' => []];
    foreach (r_sorted() as $id => $r) $groups[$r['status'] ?? 'pending'][$id] = $r;
    foreach ($groups as $st => $rs):
?>
    <h2><?= h(R_STATUS[$st]) ?> (<?= count($rs) ?>)</h2>
<?php if (!$rs): ?><p class="muted" style="margin-top:8px">ありません。</p><?php endif; ?>
<?php foreach ($rs as $id => $r): ?>
    <div class="item" id="<?= h($id) ?>">
      <p><b><?= h((string)$r['nick']) ?></b> <span class="muted"><?= h(date('Y-m-d H:i', (int)$r['time'])) ?></span></p>
      <p class="muted"><?= h(implode('・', array_map('r_device_label', (array)($r['devices'] ?? [])))) ?></p>
      <p class="c"><?= h((string)$r['comment']) ?></p>
      <div class="ph"><?php for ($n = 1; $n <= (int)($r['photos'] ?? 0); $n++): $u = 'report_img.php?id=' . rawurlencode($id) . '&n=' . $n; ?><a href="<?= h($u) ?>" target="_blank"><img src="<?= h($u . '&s=s') ?>" alt="写真<?= $n ?>"></a><?php endfor; ?></div>
<?php if (($r['email'] ?? '') !== ''): ?>      <p class="muted">連絡先 (公開しない): <?= h((string)$r['email']) ?></p>
<?php endif; ?>
      <details><summary>管理者からのひとこと<?= ($r['reply'] ?? '') !== '' ? ' (あり)' : '' ?></summary>
        <form method="post" action="report_admin.php"><?= $csrf ?><input type="hidden" name="id" value="<?= h($id) ?>"><input type="hidden" name="act" value="reply">
          <textarea name="reply" maxlength="<?= R_REPLY_MAX ?>" placeholder="例: 報告ありがとうございます！"><?= h((string)($r['reply'] ?? '')) ?></textarea>
          <div class="ops"><button type="submit">ひとことを保存</button></div></form></details>
      <div class="ops">
<?php if ($st !== 'public'): ?>        <form method="post" action="report_admin.php"><?= $csrf ?><input type="hidden" name="id" value="<?= h($id) ?>"><input type="hidden" name="act" value="publish"><button class="main" type="submit">公開する</button></form>
<?php endif; ?>
<?php if ($st !== 'hidden'): ?>        <form method="post" action="report_admin.php"><?= $csrf ?><input type="hidden" name="id" value="<?= h($id) ?>"><input type="hidden" name="act" value="hide"><button type="submit">非公開にする</button></form>
<?php endif; ?>
        <form method="post" action="report_admin.php" onsubmit="return confirm('このレポートと写真を消します。元に戻せません。よろしいですか？')"><?= $csrf ?><input type="hidden" name="id" value="<?= h($id) ?>"><input type="hidden" name="act" value="delete"><button class="del" type="submit">削除</button></form>
      </div>
    </div>
<?php endforeach; endforeach; ?>
<?php endif; ?>
  </div>
</main>
<footer>
  <div class="wrap">
    <div>
      <img src="img/hivex_reverse.svg" alt="Hive Plus X" style="height:22px">
      <small style="margin-top:8px">&copy; 2026 Taro Maeda and Hive Plus X contributors</small>
    </div>
    <ul>
      <li><a href="https://github.com/Hive-Plus-X/hiveplusx">GitHub</a></li>
      <li><a href="https://github.com/Hive-Plus-X/hiveplusx/discussions">フォーラム</a></li>
      <li><a href="https://line.me/R/ti/p/@222xdxtk">LINE</a></li>
      <li><a href="policy.html">オープンソース方針</a></li>
      <li><a href="contact.php">お問い合わせ</a></li>
      <li><a href="report_admin.php" title="管理" aria-label="管理"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" style="vertical-align:-3px"><path fill="currentColor" d="M19.14 12.94a7.6 7.6 0 0 0 0-1.88l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.3 7.3 0 0 0-1.63-.94l-.36-2.54a.5.5 0 0 0-.5-.42h-3.84a.5.5 0 0 0-.5.42l-.36 2.54c-.59.24-1.13.56-1.63.94l-2.39-.96a.5.5 0 0 0-.6.22L2.71 8.84a.5.5 0 0 0 .12.64l2.03 1.58a7.6 7.6 0 0 0 0 1.88l-2.03 1.58a.5.5 0 0 0-.12.64l1.92 3.32c.13.22.39.3.6.22l2.39-.96c.5.38 1.04.7 1.63.94l.36 2.54c.04.24.25.42.5.42h3.84c.25 0 .46-.18.5-.42l.36-2.54c.59-.24 1.13-.56 1.63-.94l2.39.96c.22.08.47 0 .6-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58zM12 15.6A3.6 3.6 0 1 1 12 8.4a3.6 3.6 0 0 1 0 7.2z"/></svg></a></li>
    </ul>
  </div>
</footer>
</body>
</html>
