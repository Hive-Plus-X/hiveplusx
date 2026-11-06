<?php
// 「作った・使ったレポート」の写真を出す。report_img.php?id=r_0123456789&n=1&s=s (s=s なら一覧用の小さい写真)
//   サイトに置いて (www/hiveplusx/report_img.php)、アプリ (hpx/) の config.php・hpx_report.php を使う。写真は hpx/data/reports/photos/ にある。
//   公開したレポートの写真は誰でも見られる。確認待ち・非公開の写真は、管理ページ (report_admin.php) にログインした人だけ見られる。
declare(strict_types=1);

$APP = __DIR__ . '/hpx';
require $APP . '/config.php';
require_once $APP . '/hpx_report.php';

$id = (string)($_GET['id'] ?? '');
$n = (int)($_GET['n'] ?? 1);
$size = ($_GET['s'] ?? '') === 's' ? 's' : 'l';
$r = r_get($id);
$file = ($r && $n >= 1 && $n <= (int)($r['photos'] ?? 0)) ? r_photo_path($id, $n, $size) : '';

$public = $r && ($r['status'] ?? '') === 'public';
if ($r && !$public) {
    $ok = r_admin_ok();
    session_write_close();
    if (!$ok) $file = '';
}
if ($file === '' || !is_file($file)) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($file));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: ' . ($public ? 'public, max-age=86400' : 'private, no-store'));
readfile($file);
