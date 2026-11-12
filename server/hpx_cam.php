<?php
// HPX-CAM (蜂場カメラ) の共通部分。cam_upload.php (写真の受け口) と camera.php (写真を見るページ)・apiary.php から読み込む。
//   保存先: アカウントの cams/<カメラのID (12けたの16進)>/
//     cam.json            カメラの情報 {name, apiary(蜂場id), last(最後に届いた時刻), shot(最後の写真の撮影時刻), n(通算の枚数),
//                         bv(電池電圧), rssi, via(中継した HPX-Core のID。直接なら ''), iv(撮影の間隔・分), h0/h1(撮影する時間帯の始め・終わりの時), gc(古い写真を消した日)}
//     <YYYYMMDD>/<HHMMSS>.jpg    届いた写真 (そのまま保存)
//     <YYYYMMDD>/<HHMMSS>_t.jpg  一覧用の小さい版 (長い辺 CAM_THUMB_PX。GD が無ければ作らず、元の写真を使う)
//   写真はデータフォルダにあるので、ブラウザからは camera.php を通してだけ見られる (ログインして、そのカメラを見られる人だけ)
declare(strict_types=1);
require_once __DIR__ . '/hpx_auth.php';

const CAM_THUMB_PX = 320;
const CAM_MAX_BYTES = 2 * 1024 * 1024;   // 1枚の上限 (QXGA 2048×1536 でも 0.5MB くらい)
const CAM_MAX_PER_DAY = 300;             // 1台・1日の上限 (5分ごと24時間で288枚)
const CAM_IVS = [5, 10, 15, 30, 60];     // 撮影の間隔 (分) の選択肢
const CAM_IV_DEFAULT = 10;
const CAM_H0_DEFAULT = 5;                // 撮影する時間帯 (この時から)
const CAM_H1_DEFAULT = 19;               //               (この時まで。19 なら 18:59 まで)

function cam_id_ok(string $id): bool { return (bool)preg_match('/^[0-9A-F]{12}$/', $id); }
function cams_dir(string $uid): string { return account_dir($uid) . '/cams'; }
function cam_dir(string $uid, string $id): string { return cams_dir($uid) . '/' . $id; }
function cam_load(string $uid, string $id): array { return json_load(cam_dir($uid, $id) . '/cam.json'); }
function cam_update(string $uid, string $id, callable $fn): bool { return json_update(cam_dir($uid, $id) . '/cam.json', $fn); }
function cam_name(array $c, string $id): string { return (string)($c['name'] ?? '') !== '' ? (string)$c['name'] : 'HPX-CAM ' . substr($id, -4); }

// 撮影の間隔と時間帯 (カメラへの返事でも送る)
function cam_iv(array $c): int { return in_array((int)($c['iv'] ?? 0), CAM_IVS, true) ? (int)$c['iv'] : CAM_IV_DEFAULT; }
function cam_hours(array $c): array
{
    $h0 = isset($c['h0']) ? max(0, min(23, (int)$c['h0'])) : CAM_H0_DEFAULT;
    $h1 = isset($c['h1']) ? max(1, min(24, (int)$c['h1'])) : CAM_H1_DEFAULT;
    return $h1 > $h0 ? [$h0, $h1] : [0, 24];
}

// 写真の一覧 (その日の、古い順)。返すのは "HHMMSS" の配列
function cam_day_list(string $dir, string $ymd): array
{
    if (!preg_match('/^\d{8}$/', $ymd)) return [];
    $out = [];
    foreach (glob($dir . '/' . $ymd . '/*.jpg') ?: [] as $p) {
        if (preg_match('/^(\d{6})\.jpg$/', basename($p), $m)) $out[] = $m[1];
    }
    sort($out);
    return $out;
}

// 写真のある日の一覧 ("YYYYMMDD" の配列、古い順)。$prefix に "YYYYMM" を渡すとその月だけ
function cam_days(string $dir, string $prefix = ''): array
{
    $out = [];
    foreach (glob($dir . '/' . $prefix . '*', GLOB_ONLYDIR) ?: [] as $p) {
        if (preg_match('/^\d{8}$/', basename($p))) $out[] = basename($p);
    }
    sort($out);
    return $out;
}

// その日の、12時にいちばん近い写真 ("HHMMSS")。無ければ ''
function cam_noon(string $dir, string $ymd): string
{
    $best = ''; $bd = PHP_INT_MAX;
    foreach (cam_day_list($dir, $ymd) as $t) {
        $s = (int)substr($t, 0, 2) * 3600 + (int)substr($t, 2, 2) * 60 + (int)substr($t, 4, 2);
        if (abs($s - 43200) < $bd) { $bd = abs($s - 43200); $best = $t; }
    }
    return $best;
}

// 小さい版を作る (GD)。作れなければ false (そのときは元の写真を出す)
function cam_thumb(string $src, string $dst): bool
{
    if (!function_exists('imagecreatefromjpeg')) return false;
    $im = @imagecreatefromjpeg($src);
    if (!$im) return false;
    $w = imagesx($im); $hh = imagesy($im); $k = min(1, CAM_THUMB_PX / max($w, $hh));
    $tw = max(1, (int)round($w * $k)); $th = max(1, (int)round($hh * $k));
    $t = imagecreatetruecolor($tw, $th);
    imagecopyresampled($t, $im, 0, 0, 0, 0, $tw, $th, $w, $hh);
    $ok = imagejpeg($t, $dst . '.tmp', 75) && rename($dst . '.tmp', $dst);
    imagedestroy($im); imagedestroy($t);
    return $ok;
}

// 古い写真を消す (config.php の $HPX_CAM_KEEP_DAYS より前の日のフォルダ。0 なら消さない)
function cam_gc(string $dir, int $now): void
{
    $keep = (int)($GLOBALS['HPX_CAM_KEEP_DAYS'] ?? 365);
    if ($keep <= 0) return;
    $limit = date('Ymd', $now - $keep * 86400);
    foreach (cam_days($dir) as $d) {
        if ($d >= $limit) break;
        dir_remove($dir . '/' . $d);
    }
}

// 見られるカメラ: id => [o(持ち主), dir, c(cam.json), own(自分のか)]
//   自分のカメラはすべて。ほかの人のカメラは、共有された蜂場に紐づいているものだけ (site_load() を使うので hpx_site.php を読んだページから呼ぶ)
function cams_visible(): array
{
    global $UID;
    static $out = null;
    if ($out !== null) return $out;
    $out = [];
    if ($UID === demo_uid()) demo_cam_fill();
    $aps = site_load()['apiaries'];
    foreach (array_merge([$UID], shared_owners($UID)) as $o) {
        foreach (glob(cams_dir((string)$o) . '/*/cam.json') ?: [] as $p) {
            $id = basename(dirname($p));
            if (!cam_id_ok($id) || isset($out[$id])) continue;
            $c = json_load($p);
            $aid = (string)($c['apiary'] ?? '');
            if ($o !== $UID && !(isset($aps[$aid]) && $aps[$aid]['_o'] === $o)) continue;
            $out[$id] = ['o' => (string)$o, 'dir' => dirname($p), 'c' => $c, 'own' => $o === $UID];
        }
    }
    uasort($out, fn($a, $b) => strcmp(cam_name($a['c'], ''), cam_name($b['c'], '')));
    return $out;
}

// デモ用アカウントの見本の写真: img/demo_cam/d1〜d3/<HHMMSS>.jpg を、3日前・2日前・きのうの写真として見本のカメラに入れる。
//   日付が変わったら入れ直す (いつも「直近の3日分」が見える)。写真が無い・$HPX_DEMO_DUMMY = false なら何もしない
const DEMO_CAM_ID = 'DE000000CA01';
function demo_cam_fill(): void
{
    $src = __DIR__ . '/img/demo_cam';
    $uid = demo_uid();
    if ($uid === '' || !demo_dummy_on() || !is_dir($src)) return;
    $dir = cam_dir($uid, DEMO_CAM_ID);
    $now = time();
    $today = date('Ymd', $now);
    $flag = $dir . '/.demo_day';
    if ((string)@file_get_contents($flag) === $today) return;
    @mkdir($dir, 0700, true);
    $lock = fopen($dir . '/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) return;
    if ((string)@file_get_contents($flag) !== $today) {
        foreach (cam_days($dir) as $d) dir_remove($dir . '/' . $d);
        $shot = 0; $n = 0;
        for ($k = 1; $k <= 3; $k++) {
            $day = date('Ymd', strtotime('-' . (4 - $k) . ' day', strtotime($today)));
            foreach (glob($src . '/d' . $k . '/*.jpg') ?: [] as $p) {
                if (!preg_match('/^(\d{6})\.jpg$/', basename($p), $m)) continue;
                $to = $dir . '/' . $day . '/' . $m[1] . '.jpg';
                @mkdir(dirname($to), 0700, true);
                if (!@copy($p, $to)) continue;
                cam_thumb($to, substr($to, 0, -4) . '_t.jpg');
                $shot = max($shot, (int)strtotime($day . ' ' . $m[1]));
                $n++;
            }
        }
        // 置いている蜂場は、デモ用アカウントの最初の蜂場 (ページの設定で変えていなければ)
        $aps = json_load(account_site_dir($uid) . '/site.json')['apiaries'] ?? [];
        cam_update($uid, DEMO_CAM_ID, function (array $x) use ($shot, $n, $aps) {
            $x['name'] = (string)($x['name'] ?? '') !== '' ? $x['name'] : '巣門カメラ (見本)';
            if (!isset($aps[(string)($x['apiary'] ?? '')])) $x['apiary'] = is_array($aps) && $aps ? (string)array_key_first($aps) : '';
            $x['last'] = time(); $x['shot'] = $shot; $x['n'] = $n; $x['via'] = ''; $x['bv'] = 4.62; $x['rssi'] = -64;
            $x['created'] = $x['created'] ?? time();
            return $x;
        });
        file_put_contents($flag, $today);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}
