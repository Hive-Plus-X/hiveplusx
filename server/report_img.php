<?php
// 「作りました報告」の写真を出す。report_img.php?id=r_0123456789&n=1&s=s (s=s なら一覧用の小さい写真)
//   公開した報告の写真は誰でも見られる。確認待ち・非公開の写真は、ログインしたサイト管理者だけ見られる。
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/hpx_report.php';

$id = (string)($_GET['id'] ?? '');
$n = (int)($_GET['n'] ?? 1);
$size = ($_GET['s'] ?? '') === 's' ? 's' : 'l';
$r = r_get($id);
$file = ($r && $n >= 1 && $n <= (int)($r['photos'] ?? 0)) ? r_photo_path($id, $n, $size) : '';

$public = $r && ($r['status'] ?? '') === 'public';
if ($r && !$public) {
    require_once __DIR__ . '/hpx_auth.php';
    auth_session_start();
    $u = user_get((string)($_SESSION['uid'] ?? ''));
    session_write_close();
    if (!$u || empty($u['admin']) || ($u['status'] ?? '') !== 'active' || is_demo($u)) $file = '';
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
