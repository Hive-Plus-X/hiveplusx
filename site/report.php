<?php
// hiveplusx.com の「作った・使ったレポート」。機器を作ったり、サーバーや養蜂管理アプリを使った人が、写真とひとことで報告するページ。
//   サイトに置いて (www/hiveplusx/report.php)、アプリ (hpx/) の config.php・hpx_report.php を使う。
//   ・投稿は「確認待ち」で保存し、管理者にメールで知らせる。管理者が report_admin.php (サイトの一番下の歯車) で「公開する」を押したものだけ出す
//   ・写真はサーバーで作り直して保存する (撮影場所などの写真の中の情報は消える。長辺 1200px に小さくする)
//     ブラウザでも送る前に小さくする (送る時間を短くし、サーバーの上限で失敗しないように)
//   ・迷惑な送信を減らす工夫は contact.php と同じ (見えない入力欄、開いてからの時間、回数の制限、リンクの数)
//   ・report.php?feed=1 は、公開した報告の新着を JSON で返す (トップページのコミュニティ欄が読む)
declare(strict_types=1);

$APP = __DIR__ . '/hpx';
require $APP . (is_file($APP . '/hpx_config.php') ? '/hpx_config.php' : '/config.php');   // config.php と、サーバーの設定画面で保存した値
require_once $APP . '/hpx_alert.php';
require_once $APP . '/hpx_report.php';

const R_TO = 'support@hiveplusx.com';
const R_PER_PAGE = 12;
const R_IMG = 'report_img.php';

if (!function_exists('h')) { function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); } }
function r_img_url(string $id, int $n, bool $small): string { return R_IMG . '?id=' . rawurlencode($id) . '&n=' . $n . ($small ? '&s=s' : ''); }

// トップページ用の新着 (JSON)
if (isset($_GET['feed'])) {
    $max = max(1, min(12, (int)($_GET['n'] ?? 4)));
    $out = [];
    foreach (array_slice(r_sorted('public'), 0, $max) as $r) {
        $v = r_public_view($r);
        $v['img'] = $v['photos'] > 0 ? r_img_url($v['id'], 1, true) : '';
        $v['url'] = 'report.php#' . $v['id'];
        $out[] = $v;
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    echo json_encode(['reports' => $out, 'total' => count(r_sorted('public'))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$v = ['nick' => '', 'comment' => '', 'email' => '', 'devices' => []];
$err = [];
$done = false;
$fail = false;
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if (!$isPost && isset($_GET['sent'])) $done = true;

if ($isPost && !$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    // 送られてきた量がサーバーの上限を超えると、中身が空で届く
    $err[] = '写真が大きすぎて受け取れませんでした。写真の枚数を減らすか、小さくしてから送ってください。';
} elseif ($isPost) {
    foreach (['nick', 'comment', 'email'] as $k) $v[$k] = trim((string)($_POST[$k] ?? ''));
    $v['devices'] = array_values(array_intersect(array_keys(R_DEVICES), (array)($_POST['devices'] ?? [])));
    $age = r_token_age((string)($_POST['t'] ?? ''));
    $bot = (string)($_POST['website'] ?? '') !== '';

    // 送られてきた写真 (空の欄は数えない)
    $files = [];
    $up = $_FILES['photos'] ?? null;
    if (is_array($up) && is_array($up['name'] ?? null)) {
        foreach ($up['name'] as $i => $_) {
            $e = (int)($up['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($e === UPLOAD_ERR_NO_FILE) continue;
            if ($e === UPLOAD_ERR_INI_SIZE || $e === UPLOAD_ERR_FORM_SIZE || (int)($up['size'][$i] ?? 0) > R_MAX_BYTES) { $err[] = '大きすぎる写真がありました。'; continue; }
            if ($e !== UPLOAD_ERR_OK || !is_uploaded_file((string)$up['tmp_name'][$i])) { $err[] = '写真を受け取れませんでした。もう一度お試しください。'; continue; }
            $files[] = (string)$up['tmp_name'][$i];
        }
    }

    if ($bot || $age === null || $age < R_MIN_S) {
        $done = true;   // ロボットらしいものは、送ったふりをして捨てる
    } elseif ($age > R_MAX_S) {
        $err[] = '時間がたったため、送信できませんでした。お手数ですが、写真を選び直して、もう一度「送信する」を押してください。';
    } else {
        if ($v['nick'] === '') $err[] = 'ニックネームを書いてください。';
        if (mb_strlen($v['nick']) > R_NICK_MAX) $err[] = 'ニックネームは' . R_NICK_MAX . '文字までにしてください。';
        if (!$v['devices']) $err[] = '作った機器・使ったものを選んでください。';
        $len = mb_strlen($v['comment']);
        if ($len < 5) $err[] = 'ひとことを書いてください (5文字以上)。';
        if ($len > R_COMMENT_MAX) $err[] = 'ひとことは' . R_COMMENT_MAX . '文字までにしてください。';
        if (preg_match('#https?://|www\.#i', $v['comment'] . $v['nick'])) $err[] = 'リンク (URL) は書けません。';
        if ($v['email'] !== '' && (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $v['email']))) $err[] = 'メールアドレスを確かめてください。';
        if (!$files && !$err) $err[] = '写真を1枚以上選んでください。';
        if (count($files) > R_MAX_PHOTOS) $err[] = '写真は' . R_MAX_PHOTOS . '枚までにしてください。';
        if (empty($_POST['agree'])) $err[] = '「サイトに載せることに同意します」にチェックを入れてください。';
        // 同じフォームからの2回目 (送ったあとの再読み込み・戻る) は、もう受け付けたものとして保存しない
        if (!$err && !r_token_use((string)$_POST['t'])) $done = true;
        elseif (!$err && !r_rate_ok()) $err[] = '送信が集中しているため、しばらくたってからお試しください。';

        if (!$err && !$done) {
            $id = 'r_' . bin2hex(random_bytes(5));
            $n = 0;
            foreach ($files as $tmp) {
                $why = r_save_photo($tmp, $id, $n + 1);
                if ($why !== '') { $err[] = $why; break; }
                $n++;
            }
            if ($err) {
                foreach (glob(r_dir() . '/photos/' . $id . '_*.jpg') ?: [] as $f) @unlink($f);
            } else {
                $rec = ['id' => $id, 'time' => time(), 'status' => 'pending', 'nick' => $v['nick'],
                        'devices' => $v['devices'], 'comment' => $v['comment'], 'email' => $v['email'], 'photos' => $n, 'reply' => ''];
                if (!r_update(function (array $l) use ($id, $rec) { $l[$id] = $rec; return $l; })) {
                    $fail = true;
                } else {
                    $done = true;
                    // 管理者に知らせる (送れなくても報告は受け付けてある。管理ページで見られる)
                    $to = array_values(array_filter(array_map('trim', explode(',', (string)(($HPX_REPORT_TO ?? '') !== '' ? $HPX_REPORT_TO
                        : (($HPX_CONTACT_TO ?? '') !== '' ? $HPX_CONTACT_TO : R_TO))))));
                    $body = "hiveplusx.com に「作った・使ったレポート」が届きました。まだ公開していません。\n"
                        . "次のページで写真を確かめて、「公開する」を押してください (管理用のパスワードが要ります)。\n"
                        . r_admin_url() . '#' . $id . "\n\n"
                        . "ニックネーム: {$v['nick']}\n"
                        . '作った機器・サーバー・養蜂管理アプリ: ' . implode('・', array_map('r_device_label', $v['devices'])) . "\n写真: {$n}枚\n\n----\n{$v['comment']}\n";
                    [$ok, $res] = ($to && (string)($HPX_SMTP_HOST ?? '') !== '')
                        ? hpx_smtp_send($to, (string)$HPX_MAIL_FROM, '【hiveplusx.com】作った・使ったレポートが届きました', $body)
                        : [false, '送り先かSMTPが設定されていません'];
                    @file_put_contents(r_dir() . '/mail.log', date('c') . ' ' . ($ok ? 'OK' : 'NG') . ' ' . $id . ' ' . preg_replace('/\s+/', ' ', (string)$res) . "\n", FILE_APPEND | LOCK_EX);
                    // 送信したあとは「送信しました」のページへ移す (再読み込みしても、もう一度送らないように)
                    header('Location: report.php?sent=1#post', true, 303);
                    exit;
                }
            }
        }
    }
}
$tok = r_token(time());

$pub = r_sorted('public');
$total = count($pub);
$pages = max(1, (int)ceil($total / R_PER_PAGE));
$page = max(1, min($pages, (int)($_GET['p'] ?? 1)));
$shown = array_slice($pub, ($page - 1) * R_PER_PAGE, R_PER_PAGE);
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>作った・使ったレポート - Hive Plus X</title>
<meta name="description" content="Hive Plus X の機器を作ったり、サーバーや養蜂管理アプリを使った方からの報告です。">
<?php if ($isPost): ?><meta name="robots" content="noindex"><?php endif; ?>
<link rel="icon" href="img/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
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
  .rep { scroll-margin-top:80px; }
  .backs { display:flex; gap:12px; flex-wrap:wrap; margin-top:24px; }
  .backs a { margin-top:0; }
  .btn.ghost { background:none; border:1.5px solid var(--forest); color:var(--text); }
  @media (prefers-color-scheme: dark) { .btn.ghost { border-color:rgba(255,255,255,.5); } }
  main { padding:120px 0 200px; }
  #post { padding-top:200px; }
  @media (max-width:760px) { main { padding:64px 0 140px; } #post { padding-top:140px; } }
  .eyebrow { font-size:14px; font-weight:700; letter-spacing:.24em; color:var(--honey); text-transform:uppercase; }
  h1, h2 { font-size:clamp(26px, 3.6vw, 34px); margin-top:6px; line-height:1.4; }
  .lead { color:var(--sub); margin-top:8px; max-width:720px; }
  .btn { display:inline-flex; align-items:center; padding:12px 26px; border-radius:999px; text-decoration:none; font-weight:600; font-size:15px;
         background:var(--honey); color:#1F2328; border:0; cursor:pointer; font-family:inherit; margin-top:24px; }

  /* 報告の一覧 */
  .grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:20px; margin-top:40px; }
  @media (max-width:900px) { .grid { grid-template-columns:repeat(2, 1fr); } }
  @media (max-width:560px) { .grid { grid-template-columns:1fr; } }
  .rep { background:var(--panel); border:1px solid var(--line); border-radius:14px; overflow:hidden; display:flex; flex-direction:column; scroll-margin-top:24px; }
  .rep .ph { display:grid; grid-template-columns:1fr; gap:2px; aspect-ratio:4/3; background:var(--honey-soft); }
  .rep .ph.n2 { grid-template-columns:1fr 1fr; }
  .rep .ph.n3 { grid-template-columns:2fr 1fr; grid-template-rows:1fr 1fr; }
  .rep .ph.n3 button:first-child { grid-row:1 / 3; }
  .rep .ph button { border:0; padding:0; background:none; cursor:zoom-in; overflow:hidden; min-height:0; }
  .rep .ph img { width:100%; height:100%; object-fit:cover; display:block; }
  .rep .body { padding:16px 18px 18px; flex:1; }
  .rep .who { font-weight:700; }
  .rep .who small { font-weight:400; color:var(--sub); margin-left:6px; }
  .tags { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
  .tag { font-size:12px; font-weight:700; color:var(--forest); background:var(--honey-soft); padding:2px 10px; border-radius:999px; }
  @media (prefers-color-scheme: dark) { .tag { color:var(--honey); } }
  .rep p.c { margin-top:10px; font-size:15px; white-space:pre-wrap; word-break:break-word; }
  .rep .reply { margin-top:12px; padding:10px 12px; border-radius:10px; background:var(--honey-soft); font-size:14px; white-space:pre-wrap; }
  .rep .reply b { display:block; font-size:12px; color:var(--sub); }
  .rep .date { margin-top:10px; font-size:12px; color:var(--sub); }
  .empty { margin-top:40px; padding:40px 20px; text-align:center; color:var(--sub); border:1px dashed var(--line); border-radius:14px; }
  .pager { display:flex; justify-content:center; gap:8px; margin-top:36px; }
  .pager a, .pager span { min-width:40px; padding:6px 12px; text-align:center; border-radius:999px; text-decoration:none; border:1px solid var(--line); }
  .pager span { background:var(--forest); color:#fff; border-color:var(--forest); }

  /* 写真を大きく見る */
  .lb { display:none; position:fixed; inset:0; z-index:50; background:rgba(20,24,28,.9); align-items:center; justify-content:center; }
  .lb.on { display:flex; }
  .lb img { max-width:94vw; max-height:90vh; border-radius:6px; }
  .lb button { position:absolute; top:12px; right:12px; width:44px; height:44px; border-radius:50%; border:0; background:rgba(0,0,0,.4); color:#fff; font-size:28px; cursor:pointer; }

  /* 投稿フォーム */
  form { margin-top:36px; display:grid; gap:22px; }
  label, .lbl { display:block; font-weight:700; font-size:14px; margin-bottom:6px; }
  label small, .lbl small { font-weight:400; color:var(--sub); margin-left:6px; }
  input[type=text], input[type=email], textarea {
    width:100%; font:inherit; color:var(--text); background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:10px 14px; }
  textarea { min-height:140px; resize:vertical; }
  input:focus, textarea:focus { outline:2px solid var(--honey); outline-offset:1px; border-color:transparent; }
  .checks { display:flex; flex-wrap:wrap; gap:8px; }
  .checks label { margin:0; font-weight:600; }
  .checks input { position:absolute; opacity:0; pointer-events:none; }
  .checks span { display:inline-block; padding:6px 16px; border-radius:999px; border:1px solid var(--line); background:var(--panel); cursor:pointer; }
  .checks input:checked + span { background:var(--honey); border-color:var(--honey); color:#1F2328; }
  .checks input:focus-visible + span { outline:2px solid var(--honey); outline-offset:2px; }
  .file { background:var(--panel); border:1px dashed var(--line); border-radius:12px; padding:16px; }
  .file input { font:inherit; font-size:14px; max-width:100%; }
  .previews { display:flex; gap:8px; flex-wrap:wrap; margin-top:10px; }
  .previews .pv { position:relative; }
  .previews img { width:96px; height:96px; object-fit:cover; border-radius:8px; border:1px solid var(--line); display:block; }
  .previews .x { position:absolute; top:-8px; right:-8px; width:26px; height:26px; border-radius:50%; border:2px solid var(--panel);
                 background:#1F2328; color:#fff; font-size:15px; line-height:1; cursor:pointer; display:flex; align-items:center; justify-content:center; padding:0; }
  .previews .x:hover { background:var(--err); }
  .file .count { font-size:13px; color:var(--sub); margin-top:8px; }
  .agree { display:flex; gap:10px; align-items:flex-start; font-weight:400; font-size:15px; }
  .agree input { margin-top:6px; width:18px; height:18px; flex:none; }
  .hp { position:absolute; left:-10000px; width:1px; height:1px; overflow:hidden; }
  .note { font-size:13px; color:var(--sub); }
  .note li { margin-left:1.2em; }
  .box { margin-top:28px; padding:18px 20px; border-radius:12px; background:var(--panel); border:1px solid var(--line); }
  .box.err { border-color:var(--err); color:var(--err); }
  .box ul { padding-left:1.2em; }
  form .btn { justify-self:start; margin-top:0; }
  form .btn[disabled] { opacity:.6; cursor:wait; }
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
  <section class="wrap" id="list">
    <p class="eyebrow">Reports</p>
    <h1>作った・使ったレポート</h1>
    <p class="lead">機器を作ったり、サーバーや養蜂管理アプリを使った方からの報告です。</p>
    <a class="btn" href="#post">報告をする</a>
<?php if (!$shown): ?>
    <div class="empty">まだ報告はありません。最初の報告をお待ちしています。</div>
<?php else: ?>
    <div class="grid">
<?php foreach ($shown as $r): $p = r_public_view($r); ?>
      <article class="rep" id="<?= h($p['id']) ?>">
<?php if ($p['photos'] > 0): ?>
        <div class="ph n<?= min(3, $p['photos']) ?>">
<?php for ($n = 1; $n <= min(3, $p['photos']); $n++): ?>
          <button type="button" data-big="<?= h(r_img_url($p['id'], $n, false)) ?>" aria-label="写真<?= $n ?>を大きく見る"><img src="<?= h(r_img_url($p['id'], $n, true)) ?>" alt="<?= h($p['nick']) ?> さんの写真<?= $n ?>" loading="lazy"></button>
<?php endfor; ?>
        </div>
<?php endif; ?>
        <div class="body">
          <div class="who"><?= h($p['nick']) ?> さん</div>
          <div class="tags"><?php foreach ($p['devices'] as $d) echo '<span class="tag">' . h($d) . '</span>'; ?></div>
          <p class="c"><?= h($p['comment']) ?></p>
<?php if ($p['reply'] !== ''): ?>
          <div class="reply"><b>Hive Plus X より</b><?= h($p['reply']) ?></div>
<?php endif; ?>
          <div class="date"><?= h($p['date']) ?></div>
        </div>
      </article>
<?php endforeach; ?>
    </div>
<?php if ($pages > 1): ?>
    <div class="pager"><?php for ($i = 1; $i <= $pages; $i++) echo $i === $page ? '<span>' . $i . '</span>' : '<a href="report.php?p=' . $i . '#list">' . $i . '</a>'; ?></div>
<?php endif; ?>
<?php endif; ?>
  </section>

  <section class="wrap narrow" id="post">
    <p class="eyebrow">Post</p>
    <h2>報告をする</h2>
<?php if ($done): ?>
    <div class="box">送信しました。報告ありがとうございます！<br>内容を確認してから、このページに載せます。載るまで数日かかることがあります。</div>
    <div class="backs"><a class="btn" href="./">トップページへ戻る</a><a class="btn ghost" href="report.php">レポートの一覧を見る</a></div>
<?php else: ?>
    <p class="lead">写真1〜<?= R_MAX_PHOTOS ?>枚と、ひとことを送ってください。届いた報告は、確認してからこのページとトップページに載せます。</p>
<?php if ($fail): ?>
    <div class="box err">申し訳ありません。いま保存できませんでした。時間をおいて、もう一度お試しください。</div>
<?php elseif ($err): ?>
    <div class="box err"><ul><?php foreach (array_unique($err) as $e) echo '<li>' . h($e) . '</li>'; ?></ul></div>
<?php endif; ?>
    <form method="post" action="report.php#post" enctype="multipart/form-data" id="rform">
      <input type="hidden" name="t" value="<?= h($tok) ?>">
      <div class="hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
      <div>
        <span class="lbl">写真<small>1〜<?= R_MAX_PHOTOS ?>枚</small></span>
        <div class="file">
          <input type="file" id="photos" name="photos[]" accept="image/jpeg,image/png,image/webp,image/*" multiple required>
          <div class="previews" id="previews"></div>
          <p class="count" id="pcount">1枚ずつ選ぶと、下に足されていきます。✕で外せます。</p>
        </div>
      </div>
      <div><label for="nick">ニックネーム<small>サイトに載ります</small></label>
        <input type="text" id="nick" name="nick" required maxlength="<?= R_NICK_MAX ?>" value="<?= h($v['nick']) ?>" placeholder="例: はちみつ太郎"></div>
      <div>
        <span class="lbl">作った機器・サーバー・養蜂管理アプリ<small>いくつでも</small></span>
        <div class="checks"><?php foreach (array_keys(R_DEVICES) as $d) echo '<label><input type="checkbox" name="devices[]" value="' . h($d) . '"' . (in_array($d, $v['devices'], true) ? ' checked' : '') . '><span>' . h(r_device_label($d)) . '</span></label>'; ?></div>
      </div>
      <div><label for="comment">ひとこと<small><?= R_COMMENT_MAX ?>文字まで</small></label>
        <textarea id="comment" name="comment" required maxlength="<?= R_COMMENT_MAX ?>" placeholder="例: HPX-W を2台作りました。はんだ付けは初めてでしたが、配線図のとおりにやったら動きました！"><?= h($v['comment']) ?></textarea></div>
      <div><label for="email">メールアドレス<small>任意。サイトには載りません</small></label>
        <input type="email" id="email" name="email" maxlength="200" value="<?= h($v['email']) ?>" autocomplete="email">
        <p class="note" style="margin-top:6px">報告の内容について、こちらからお聞きしたいことがあるときだけ使います。</p></div>
      <ul class="note">
        <li>人の顔、車のナンバー、蜂場の場所がわかる景色などは、写さないようにしてください。</li>
        <li>写真の中に入っている撮影場所 (位置情報) などは、こちらで自動的に消してから保存します。</li>
        <li>載せた報告を消したいときは、<a href="contact.php">お問い合わせ</a>からお知らせください。</li>
      </ul>
      <label class="agree"><input type="checkbox" name="agree" value="1" required>写真とひとこと、ニックネームを、Hive Plus X のサイトに載せることに同意します。</label>
      <button class="btn" type="submit" id="send">送信する</button>
    </form>
<?php endif; ?>
  </section>
</main>
<div class="lb" id="lb"><button type="button" aria-label="閉じる">&times;</button><img alt=""></div>
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
      <li><a href="faq.html">よくある質問</a></li>
      <li><a href="contact.php">お問い合わせ</a></li>
      <li><a href="report_admin.php" title="管理" aria-label="管理"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" style="vertical-align:-3px"><path fill="currentColor" d="M19.14 12.94a7.6 7.6 0 0 0 0-1.88l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.3 7.3 0 0 0-1.63-.94l-.36-2.54a.5.5 0 0 0-.5-.42h-3.84a.5.5 0 0 0-.5.42l-.36 2.54c-.59.24-1.13.56-1.63.94l-2.39-.96a.5.5 0 0 0-.6.22L2.71 8.84a.5.5 0 0 0 .12.64l2.03 1.58a7.6 7.6 0 0 0 0 1.88l-2.03 1.58a.5.5 0 0 0-.12.64l1.92 3.32c.13.22.39.3.6.22l2.39-.96c.5.38 1.04.7 1.63.94l.36 2.54c.04.24.25.42.5.42h3.84c.25 0 .46-.18.5-.42l.36-2.54c.59-.24 1.13-.56 1.63-.94l2.39.96c.22.08.47 0 .6-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58zM12 15.6A3.6 3.6 0 1 1 12 8.4a3.6 3.6 0 0 1 0 7.2z"/></svg></a></li>
    </ul>
  </div>
</footer>
<script>
(function () {
  // 写真を大きく見る
  var lb = document.getElementById('lb'), lbImg = lb.querySelector('img');
  document.querySelectorAll('[data-big]').forEach(function (b) {
    b.addEventListener('click', function () { lbImg.src = b.getAttribute('data-big'); lbImg.alt = b.querySelector('img').alt; lb.classList.add('on'); });
  });
  lb.addEventListener('click', function () { lb.classList.remove('on'); lbImg.removeAttribute('src'); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') lb.click(); });

  var form = document.getElementById('rform');
  if (!form) return;
  var input = document.getElementById('photos'), prev = document.getElementById('previews'), send = document.getElementById('send');
  var MAX = <?= R_MAX_PHOTOS ?>, LONG = 1600;

  // 選んだ写真をためておき、選ぶたびに足していく。✕で外せる
  //   ファイル選択の欄の中身は、ためた写真に合わせて入れ直す (送信のときはこれが送られる)
  var pcount = document.getElementById('pcount'), picked = [];
  var canKeep = typeof DataTransfer !== 'undefined';
  function sync() {
    if (canKeep) { try { var dt = new DataTransfer(); picked.forEach(function (f) { dt.items.add(f); }); input.files = dt.files; } catch (x) { canKeep = false; } }
    prev.innerHTML = '';
    picked.forEach(function (f, i) {
      var box = document.createElement('div'); box.className = 'pv';
      var img = document.createElement('img'); img.src = URL.createObjectURL(f); img.alt = '選んだ写真' + (i + 1);
      var x = document.createElement('button'); x.type = 'button'; x.className = 'x'; x.textContent = '✕'; x.setAttribute('aria-label', '写真' + (i + 1) + 'を外す');
      x.addEventListener('click', function () { picked.splice(i, 1); sync(); });
      box.appendChild(img); box.appendChild(x); prev.appendChild(box);
    });
    pcount.textContent = picked.length
      ? picked.length + ' / ' + MAX + '枚' + (picked.length < MAX ? '。続けて選ぶと足されます。' : '。いっぱいです (✕で外すと、ほかの写真を選べます)。')
      : '1枚ずつ選ぶと、下に足されていきます。✕で外せます。';
    input.disabled = canKeep && picked.length >= MAX;
  }
  input.addEventListener('change', function () {
    var add = Array.prototype.slice.call(input.files);
    if (!canKeep) { picked = add.slice(0, MAX); if (add.length > MAX) alert('写真は' + MAX + '枚までです。'); sync(); return; }
    var room = MAX - picked.length;
    if (add.length > room) alert('写真は' + MAX + '枚までです。' + (room > 0 ? '最初の' + room + '枚だけ足しました。' : ''));
    picked = picked.concat(add.slice(0, Math.max(0, room)));
    sync();
  });

  // 送る前に、ブラウザで写真を小さくする (できないときは、そのまま送る。サーバーでも小さくする)
  function shrink(file) {
    return new Promise(function (ok) {
      if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.createImageBitmap) return ok(file);
      createImageBitmap(file).then(function (bmp) {
        var k = Math.min(1, LONG / Math.max(bmp.width, bmp.height));
        var c = document.createElement('canvas');
        c.width = Math.round(bmp.width * k); c.height = Math.round(bmp.height * k);
        var g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
        g.drawImage(bmp, 0, 0, c.width, c.height);
        c.toBlob(function (b) { ok(b ? new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file); }, 'image/jpeg', 0.85);
      }).catch(function () { ok(file); });
    });
  }
  var ready = false;
  form.addEventListener('submit', function (e) {
    input.disabled = false;   // いっぱいで止めていた欄も、送るときは戻す (止めたままだと写真が送られない)
    if (canKeep && !picked.length) { e.preventDefault(); alert('写真を1枚以上選んでください。'); return; }
    if (ready || !input.files.length || typeof DataTransfer === 'undefined') { send.disabled = true; send.textContent = '送信しています…'; return; }
    e.preventDefault();
    send.disabled = true; send.textContent = '写真を準備しています…';
    Promise.all(Array.prototype.map.call(input.files, shrink)).then(function (files) {
      try { var dt = new DataTransfer(); files.forEach(function (f) { dt.items.add(f); }); input.files = dt.files; } catch (x) {}
      ready = true; send.textContent = '送信しています…'; form.submit();
    });
  });
})();
</script>
</body>
</html>
