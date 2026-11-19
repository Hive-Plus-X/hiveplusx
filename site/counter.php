<?php
// hiveplusx.com トップページの訪問者カウンター。
//   サイトに置いて (www/hiveplusx/counter.php)、トップページ (index.html) の一番下から JavaScript で呼ぶ。
//   ・数はアプリ (hpx/) のデータフォルダの site_counter.json に保存する (data/.htaccess で外から開けない。
//     このファイルは GitHub には入れない)。書き込み権限は hpx/data がもう書ける状態なので、新しく設定はいらない
//   ・同じブラウザは 1日1回だけ数える (クッキー hpx_v。日本時間の日付が変わったらまた数える)
//   ・検索エンジンなどのロボットは数えない (ブラウザの名前で判断。完全ではない)
//   ・counter.php?view=1 は数えずに今の数だけ返す
//   返す形 (JSON): {"total": 1234, "today": 12}
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

$APP = __DIR__ . '/hpx';
if (is_file($APP . '/config.php')) { require $APP . (is_file($APP . '/hpx_config.php') ? '/hpx_config.php' : '/config.php'); }
$dir = rtrim((string)($HPX_DATA_DIR ?? ($APP . '/data')), '/');
$file = $dir . '/site_counter.json';

$today = date('Y-m-d');
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$isBot = $ua === '' || preg_match('/bot|crawl|spider|slurp|preview|monitor|curl|wget|python|headless|lighthouse/i', $ua);
$seen = ($_COOKIE['hpx_v'] ?? '') === $today;
$count = !isset($_GET['view']) && !$isBot && !$seen && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

$data = ['total' => 0, 'days' => []];
$fp = @fopen($file, 'c+');
if ($fp && flock($fp, $count ? LOCK_EX : LOCK_SH)) {
    $raw = stream_get_contents($fp);
    $d = json_decode((string)$raw, true);
    if (is_array($d)) { $data = array_merge($data, $d); }
    if ($count) {
        $data['total'] = (int)$data['total'] + 1;
        $data['days'][$today] = (int)($data['days'][$today] ?? 0) + 1;
        ksort($data['days']);
        $data['days'] = array_slice($data['days'], -400, null, true); // 日ごとの数は約1年分だけ残す
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fp);
        setcookie('hpx_v', $today, ['expires' => time() + 2 * 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
    }
    flock($fp, LOCK_UN);
}
if ($fp) { fclose($fp); }

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['total' => (int)$data['total'], 'today' => (int)($data['days'][$today] ?? 0)]);
