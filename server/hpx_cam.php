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

// いちばん新しい写真 [YYYYMMDD, HHMMSS]。無ければ null
function cam_latest(string $dir): ?array
{
    $days = cam_days($dir);
    for ($i = count($days) - 1; $i >= 0; $i--) {
        $l = cam_day_list($dir, $days[$i]);
        if ($l) return [$days[$i], end($l)];
    }
    return null;
}

// 巣箱ページに出すカメラへのリンク (いちばん新しい写真の小さい版つき)
function cam_link_html(string $id, array $cm): string
{
    $lt = cam_latest($cm['dir']);
    $url = 'camera.php?' . http_build_query(['c' => $id] + ($lt ? ['d' => date('Y-m-d', strtotime($lt[0])), 'f' => $lt[1]] : []));
    return '<a class="camlnk" href="' . h($url) . '">'
        . ($lt ? '<img src="' . h('camera.php?' . http_build_query(['c' => $id, 'img' => $id, 'd' => $lt[0], 'f' => $lt[1], 't' => 1])) . '" alt="" loading="lazy">' : '')
        . '<span>📷 ' . h(cam_name($cm['c'], $id)) . '<br><small>' . ($lt ? '最新 ' . h(date('n/j H:i', (int)strtotime($lt[0] . ' ' . $lt[1]))) : 'まだ写真がありません') . '</small></span></a>';
}
const CAM_LINK_CSS = '<style>.camlnk{display:inline-flex;align-items:center;gap:8px;margin:.3em 8px .4em 0;padding:4px 10px 4px 4px;border:1px solid #cfd6cf;border-radius:6px;background:#fff;color:#1F2328;text-decoration:none;vertical-align:top}.camlnk:hover{background:#f4f8f4}.camlnk img{width:80px;height:60px;object-fit:cover;border-radius:4px;display:block}.camlnk small{color:#666}</style>';

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

// デモ用アカウントの見本の写真: img/demo_cam/ の写真 (何枚でも) を順に使い、3日前・2日前・きのうの 5:00〜18:50 に10分ごとの写真として
//   見本のカメラに入れる (朝夕は少し暗く、日ごとに少しずつ明るさを変える)。日付が変わったら入れ直す (いつも直近の3日分)。
//   写真が無い・GD が無い・$HPX_DEMO_DUMMY = false なら何もしない
//   img/demo_cam/<番号>/ ごとに1台 (DEMO_CAMS の名前、ID は DE000000CA<番号2けた>)
const DEMO_CAMS = [1 => '山の巣箱１', 2 => '山の巣箱２', 3 => '山の巣箱３'];
function demo_cam_fill(): void
{
    $uid = demo_uid();
    if ($uid === '' || !demo_dummy_on() || !function_exists('imagecreatefromjpeg')) return;
    foreach (DEMO_CAMS as $no => $name) {
        $srcs = glob(__DIR__ . '/img/demo_cam/' . $no . '/*.jpg') ?: [];
        if ($srcs) demo_cam_fill_one(sprintf('DE000000CA%02d', $no), $name, $srcs);
    }
}
function demo_cam_fill_one(string $camId, string $camName, array $srcs): void
{
    $uid = demo_uid();
    sort($srcs);
    $dir = cam_dir($uid, $camId);
    $today = date('Ymd');
    $flag = $dir . '/.demo_day';
    $mark = $today . '|v2';   // 作り方を変えたら v の数を上げる (その日のうちでも作り直す)
    if ((string)@file_get_contents($flag) === $mark) return;
    @mkdir($dir, 0700, true);
    $lock = fopen($dir . '/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) return;
    if ((string)@file_get_contents($flag) !== $mark) {
        @set_time_limit(120);
        foreach (cam_days($dir) as $d) dir_remove($dir . '/' . $d);
        $ims = array_values(array_filter(array_map(fn($p) => @imagecreatefromjpeg($p), $srcs)));
        $shot = 0; $n = 0; $i = 0;
        for ($k = 3; $k >= 1 && $ims; $k--) {
            $day = date('Ymd', strtotime('-' . $k . ' day', strtotime($today)));
            @mkdir($dir . '/' . $day, 0700, true);
            for ($m = 5 * 60; $m < 19 * 60; $m += 10) {
                $f = sprintf('%02d%02d00', intdiv($m, 60), $m % 60);
                $src = $ims[intdiv($i++, 3) % count($ims)];   // 3枚ずつ同じ写真
                $im = imagecreatetruecolor(imagesx($src), imagesy($src));
                imagecopy($im, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
                // 明るさ: 12時がいちばん明るく、朝夕は暗い。日ごとにも少し変える
                $b = (int)round(-70 * (abs($m - 720) / 420) ** 2 + ($k - 2) * 8 + (crc32($day . $f) % 7) - 3);
                if ($b !== 0) imagefilter($im, IMG_FILTER_BRIGHTNESS, $b);
                $to = $dir . '/' . $day . '/' . $f . '.jpg';
                if (imagejpeg($im, $to, 80)) {
                    cam_thumb($to, substr($to, 0, -4) . '_t.jpg');
                    $shot = max($shot, (int)strtotime($day . ' ' . $f));
                    $n++;
                }
                imagedestroy($im);
            }
        }
        foreach ($ims as $im) imagedestroy($im);
        // 置き場所: 巣箱のあるいちばん上の蜂場。1台目は蜂場全体、2台目からはその蜂場の巣箱に順に (デモでは設定を変えられないので毎回決め直す)
        $sj = json_load(account_site_dir($uid) . '/site.json');
        $aps = is_array($sj['apiaries'] ?? null) ? $sj['apiaries'] : [];
        $hv = is_array($sj['hives'] ?? null) ? $sj['hives'] : [];
        $byOrder = fn($a, $b) => ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0)) ?: strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        uasort($aps, $byOrder); uasort($hv, $byOrder);
        $ord = is_array($sj['apiary_order'] ?? null) ? array_map('strval', $sj['apiary_order']) : [];
        $apIds = array_values(array_unique(array_merge(array_values(array_filter($ord, fn($k) => isset($aps[$k]))), array_map('strval', array_keys($aps)))));
        $apSel = $apIds[0] ?? ''; $hvIds = [];
        foreach ($apIds as $k) {
            $ids = array_map('strval', array_keys(array_filter($hv, fn($h) => is_array($h) && (string)($h['apiary'] ?? '') === $k)));
            if ($ids) { $apSel = $k; $hvIds = $ids; break; }
        }
        $camNo = (int)substr($camId, -2);
        cam_update($uid, $camId, function (array $x) use ($shot, $n, $apSel, $hvIds, $camNo, $camName) {
            $x['name'] = $camName;
            $x['apiary'] = $apSel;
            $x['hive'] = $camNo >= 2 ? (string)($hvIds[$camNo - 2] ?? '') : '';
            $x['last'] = time(); $x['shot'] = $shot; $x['n'] = $n; $x['via'] = ''; $x['bv'] = 4.62; $x['rssi'] = -64;
            $x['w'] = 640; $x['h'] = 480; $x['iv'] = 10; $x['h0'] = 5; $x['h1'] = 19;
            $x['created'] = $x['created'] ?? time();
            return $x;
        });
        file_put_contents($flag, $mark);
    }
    flock($lock, LOCK_UN);
    fclose($lock);
}
