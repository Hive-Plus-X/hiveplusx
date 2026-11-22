<?php
// HPX-CAM の写真の受け口。カメラが直接 (Wi-Fi・有線LANの蜂場のルーター経由) でも、HPX-Core が中継 (LTE-M の蜂場) しても同じ形で送る。
//   POST /hpx/cam_upload.php?id=<カメラのID 12けた16進>&t=<撮影時刻 epoch秒>[&core=<中継した HPX-Core のID>][&bv=<電池V>][&rssi=<dBm>][&name=<名前>][&tc=<ケースの温度 ℃>]
//   Authorization: Bearer <トークン>  (HPX-Core と同じ、アカウントの機器用トークン。マイアカウントに出る)
//   Content-Type: image/jpeg、本文は JPEG そのもの (1枚 2MB まで)
//   返事 (JSON): {"ok":true, "saved":1, "iv":10, "h0":5, "h1":19, "now":<サーバーの時刻>}
//     iv = 撮影の間隔 (分)、h0〜h1 = 撮影する時間帯 (時)。カメラのページで変えられ、次の撮影から使う。now は時計合わせ用
//     sz = 写真の大きさ (0=SVGA 1=XGA 2=UXGA 3=QXGA)。カメラのページで選んだときだけ付ける。無ければカメラの管理画面の設定のまま
//   同じカメラ・同じ撮影時刻の写真がもうあれば、保存せずに ok を返す (送り直しで二重にならない)
declare(strict_types=1);
require __DIR__ . '/hpx_cam.php';
require __DIR__ . '/hpx_cam_watch.php';
require_once __DIR__ . '/hpx_alert.php';
header('Content-Type: application/json; charset=utf-8');

function fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail(405, 'POST only');

// --- 認証 (receive.php と同じトークン) ---
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$tok = '';
if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) $tok = trim($m[1]);
elseif (isset($_GET['k'])) $tok = (string)$_GET['k'];
if (!users_exist()) fail(503, 'account setup required');   // カメラはアカウントを作ってから (初期設定の前は受けない)
$uid = token_uid($tok);
if ($uid === null) fail(401, 'unauthorized');

$id = strtoupper((string)($_GET['id'] ?? ''));
if (!cam_id_ok($id)) fail(400, 'invalid id');
$core = strtoupper((string)($_GET['core'] ?? ''));
if ($core !== '' && !cam_id_ok($core)) $core = '';

$now = time();
$dir = cam_dir($uid, $id);
$c = is_file($dir . '/cam.json') ? cam_load($uid, $id) : [];
$reply = function (int $saved) use (&$c, $now): string {
    [$h0, $h1] = cam_hours($c);
    $r = ['ok' => true, 'saved' => $saved, 'iv' => cam_iv($c), 'h0' => $h0, 'h1' => $h1, 'now' => $now];
    if (cam_sz($c) >= 0) $r['sz'] = cam_sz($c);   // 写真の大きさ (HPX-note で選んだときだけ。HPX-CAM v1.04 から使う)
    return (string)json_encode($r);
};

// デモ用アカウントには保存しない (カメラには受け取ったと返す)
if ($uid === demo_uid()) { echo $reply(0); exit; }

// --- 本文 (JPEG) ---
$raw = file_get_contents('php://input', false, null, 0, CAM_MAX_BYTES + 1);
if ($raw === false || $raw === '') fail(400, 'empty body');
if (strlen($raw) > CAM_MAX_BYTES) fail(413, 'too large');
if (substr($raw, 0, 3) !== "\xFF\xD8\xFF") fail(415, 'not jpeg');
$info = @getimagesizefromstring($raw);
if (!$info || ($info[2] ?? 0) !== IMAGETYPE_JPEG) fail(415, 'not jpeg');

// 撮影時刻。カメラの時計がおかしい (7日より前・5分より先・無い) ときは、届いた時刻にする
$t = (int)($_GET['t'] ?? 0);
if ($t < $now - 7 * 86400 || $t > $now + 300) $t = $now;

if (!is_dir($dir)) {
    // 1つのアカウントにつなげるカメラの台数の上限 (config.php の $HPX_MAX_CAMS。0 なら無制限。サーバー管理者は無制限)
    $max = (int)($HPX_MAX_CAMS ?? 5);
    if ($max > 0 && empty(user_get($uid)['admin']) && count(glob(cams_dir($uid) . '/*/cam.json') ?: []) >= $max) fail(403, 'camera limit');
    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) fail(500, 'cannot create dir');
}
$lock = fopen($dir . '/.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) fail(500, 'lock failed');

$day = date('Ymd', $t);
$file = $dir . '/' . $day . '/' . date('His', $t) . '.jpg';
$saved = 0;
if (!is_file($file)) {
    if (count(glob($dir . '/' . $day . '/*.jpg') ?: []) >= CAM_MAX_PER_DAY * 2) fail(429, 'too many today');   // 小さい版も数に入るので2倍
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0700, true);
    if (@file_put_contents($file . '.tmp', $raw) === false || !@rename($file . '.tmp', $file)) fail(500, 'cannot save');
    cam_thumb($file, substr($file, 0, -4) . '_t.jpg');
    $saved = 1;
}

// カメラの情報
$name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($_GET['name'] ?? '')) ?? '');
$c = [];
$hotMsg = '';   // ケースの温度のお知らせ ('hot' = 高くなった、'ok' = 戻った)
$tc = isset($_GET['tc']) && is_numeric($_GET['tc']) ? round((float)$_GET['tc'], 1) : null;
if ($tc !== null && ($tc < -40 || $tc > 125)) $tc = null;   // チップの温度として変な値は使わない
cam_update($uid, $id, function (array $x) use (&$c, &$hotMsg, $now, $t, $core, $saved, $name, $info, $dir, $tc) {
    $x['last'] = $now;
    $x['shot'] = max((int)($x['shot'] ?? 0), $t);
    $x['n'] = (int)($x['n'] ?? 0) + $saved;
    $x['via'] = $core;
    $x['w'] = (int)$info[0]; $x['h'] = (int)$info[1];   // 写真の大きさ (px)
    if (isset($_GET['bv']) && is_numeric($_GET['bv'])) $x['bv'] = round((float)$_GET['bv'], 2); else unset($x['bv']);
    if (isset($_GET['rssi']) && is_numeric($_GET['rssi'])) $x['rssi'] = (int)$_GET['rssi']; else unset($x['rssi']);
    // ケースの温度 (HPX-CAM v1.05 から)。設定の温度以上になったら1回だけお知らせし、CAM_HOT_BACK ℃ 下がったら「戻りました」
    if ($tc !== null) {
        $x['tc'] = $tc; $x['tct'] = $now;
        $lim = cam_hot($x);
        if ($lim > 0 && empty($x['hotq']) && $tc >= $lim) { $x['hotq'] = $now; $hotMsg = 'hot'; }
        elseif (!empty($x['hotq']) && ($lim === 0 || $tc < $lim - CAM_HOT_BACK)) { unset($x['hotq']); if ($lim > 0) $hotMsg = 'ok'; }
    } else unset($x['tc'], $x['tct']);
    if (($x['name'] ?? '') === '' && $name !== '') $x['name'] = mb_substr($name, 0, 30);   // 名前はページで付けたものを優先
    if (!isset($x['created'])) $x['created'] = $now;
    // 古い写真の整理は1日1回
    if (($x['gc'] ?? '') !== date('Ymd', $now)) { cam_gc($dir, $now); $x['gc'] = date('Ymd', $now); }
    $c = $x;
    return $x;
});
flock($lock, LOCK_UN);
fclose($lock);

// カメラへの返事を先に返して接続を切り (カメラを待たせない)、そのあと前の写真と見くらべる (大きく変わったらお知らせ、hpx_cam_watch.php)
$out = $reply($saved);
if (!$saved && $hotMsg === '') { echo $out; exit; }
ignore_user_abort(true);
@set_time_limit(120);
header('Content-Length: ' . strlen($out));
header('Connection: close');
echo $out;
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
else { while (ob_get_level() > 0) @ob_end_flush(); flush(); }
if ($hotMsg !== '') {
    $nm = cam_name($c, $id);
    $aid = (string)($c['apiary'] ?? '');
    $where = $nm;
    if ($aid !== '' && ($ap = (array)(owner_site($uid)['apiaries'][$aid] ?? [])) && ($ap['name'] ?? '') !== '') $where = $ap['name'] . ' / ' . $nm;
    $url = site_url('camera.php?' . http_build_query(['c' => $id]));
    if ($hotMsg === 'hot')
        hpx_alert_queue('【HPX】カメラのケースが熱くなっています (' . $where . ')',
            "HPX-CAM のケースの温度が " . $tc . "℃ になりました (知らせる温度 " . cam_hot($c) . "℃ 以上)。\n"
            . "日よけ (ルーフ) や置き場所を確かめてください。温度は起きた直後のカメラのチップの温度で、正確ではない目安です。\n\n"
            . "場所: $where\n時刻: " . date('Y-m-d H:i', $now) . "\nカメラ: " . $url . "\n");
    else
        hpx_alert_queue('【HPX】カメラのケースの温度が戻りました (' . $where . ')',
            "HPX-CAM のケースの温度が " . $tc . "℃ に下がりました。\n\n場所: $where\n時刻: " . date('Y-m-d H:i', $now) . "\nカメラ: " . $url . "\n");
    hpx_alert_flush(account_site_dir($uid), apiary_alert_to($uid, $aid !== '' ? [$aid] : [], 'device'));
}
if ($saved) cam_watch($uid, $id, $dir, $file, $t);
