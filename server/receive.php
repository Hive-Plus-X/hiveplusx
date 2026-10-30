<?php
// HPX 受信ページ: 機器からのレポート(JSON)を受け取り、保存する。
//   POST /hpx/receive.php   Authorization: Bearer <トークン>   (または ?k=<トークン>)
//   トークンはアカウントごとの機器用トークン(設定ページに出る)。届いた記録は、そのアカウントの hubs/<HPX-CoreのID>/ に保存する。
//   初期設定(login.php)の前だけは、config.php の $HPX_TOKEN で前の場所(データフォルダの <ID>/)に保存する。
declare(strict_types=1);
require __DIR__ . '/hpx_auth.php';
header('Content-Type: application/json; charset=utf-8');

function fail(int $code, string $msg): void
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') fail(405, 'POST only');

// --- 認証 ---
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$tok = '';
if (preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) $tok = trim($m[1]);
elseif (isset($_GET['k'])) $tok = (string)$_GET['k'];
// 初期設定でデータを移している間は待つ(共有ロック。移す側は排他ロック)
$migLock = migrate_lock(LOCK_SH);
if (users_exist()) {
    $uid = token_uid($tok);
    if ($uid === null) fail(401, 'unauthorized');
    // デモ用アカウントのセンサの記録は見本の値で作るので、届いた記録は保存しない (機器には受け取ったと返す)
    if ($uid === demo_uid() && demo_dummy_on()) { echo json_encode(['ok' => true, 'saved' => 0]); exit; }
    $hubsDir = account_hubs_dir($uid);
    $notifyTo = account_notify($uid);
} else {
    if ($HPX_TOKEN === '' || $HPX_TOKEN === 'CHANGE-ME-to-a-long-random-string') fail(500, 'token not configured');
    if (!hash_equals($HPX_TOKEN, $tok)) fail(401, 'unauthorized');
    $uid = '';
    $hubsDir = data_dir();
    $notifyTo = null;   // config.php の $HPX_MAIL_TO
}

// --- 本文 ---
$raw = file_get_contents('php://input', false, null, 0, 65537);
if ($raw === false || $raw === '') fail(400, 'empty body');
if (strlen($raw) > 65536) fail(413, 'too large');
$d = json_decode($raw, true);
if (!is_array($d)) fail(400, 'invalid json');

$id = strtoupper((string)($d['id'] ?? ''));
if (!preg_match('/^[0-9A-F]{12}$/', $id)) fail(400, 'invalid id');
$name = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($d['name'] ?? '')));

$dir = $hubsDir . '/' . $id;
if (!is_dir($dir)) {
    // 1つのアカウントにつなげる HPX-Core の台数の上限(config.php の $HPX_MAX_HUBS。0 なら無制限。サイト管理者は無制限)
    $max = (int)($HPX_MAX_HUBS ?? 0);
    $active = array_filter(glob($hubsDir . '/*/state.json') ?: [], fn($p) => !is_file(dirname($p) . '/.retired'));   // 外したハブは数えない
    if ($uid !== '' && $max > 0 && empty(user_get($uid)['admin']) && count($active) >= $max) fail(403, 'hub limit');
    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) fail(500, 'cannot create data dir');
}
@unlink($dir . '/.retired');   // 外したハブからまた届いたら、元に戻す

$now = time();
$saved = 0;

// 機器ごとに排他(同時に2通来ても壊れないように)
$lock = fopen($dir . '/.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX)) fail(500, 'lock failed');

// 状態(重複排除用)。温湿度計ごと(Bluetoothアドレスごと)に「最後に保存した時刻/通し番号」を持つ。
$statePath = $dir . '/state.json';
$state = is_file($statePath) ? (json_decode((string)file_get_contents($statePath), true) ?: []) : [];
$sens = is_array($state['sensors'] ?? null) ? $state['sensors'] : [];   // mac => [name,last_ts,last_seq,...]
if (!$sens && (isset($state['last_ts']) || isset($state['last_seq']))) {
    // v1.09以前(1台だけ)の状態を引き継ぐ。アドレス不明の機器は '' というキーで扱う
    $legacyKey = (string)($state['ble_mac'] ?? '');
    $sens[$legacyKey] = ['name' => '', 'last_ts' => (int)($state['last_ts'] ?? 0), 'last_seq' => (int)($state['last_seq'] ?? 0)];
}

// 機器が送ってきた「温湿度計の番号 → アドレス・名前」の対応表(v1.10以降)
$sidMap = [];
foreach (($d['sensors']['ble_sensors'] ?? []) as $e) {
    if (!is_array($e)) continue;
    $mac = strtoupper((string)($e['mac'] ?? ''));
    if (!preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $mac)) continue;
    $nm = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($e['name'] ?? '')) ?? '');
    $sidMap[(int)($e['s'] ?? 0)] = ['mac' => $mac, 'name' => mb_substr($nm, 0, 30)];
}
$legacyMac = strtoupper((string)($d['sensors']['ble_mac'] ?? ''));   // v1.09以前

// ble.csv の列: time, epoch, seq, temp_c, humidity_pct, battery_pct, rssi_dbm, ts_known, sensor, mac, kind, raw, spread, batt_v,
//               fence_state, fence_events, fence_blink_ago_s
//   kind: th=温湿度計 / w=重量センサ(temp_c はノード内部の温度、raw は重量の生の値、spread は読み取りのばらつき、batt_v は電池電圧)
//         fa=電柵アラート(temp_c はノード内部の温度、fence_state は 0=正常 1=電圧低下 2=常時点灯、
//            fence_events は電圧低下になった通算回数、fence_blink_ago_s は最後の点滅からの秒数、batt_v は電池電圧)
$csvHeader = ['time', 'epoch', 'seq', 'temp_c', 'humidity_pct', 'battery_pct', 'rssi_dbm', 'ts_known', 'sensor', 'mac', 'kind', 'raw', 'spread', 'batt_v',
              'fence_state', 'fence_events', 'fence_blink_ago_s'];

// --- BLE温湿度計の記録 ---
$ble = $d['sensors']['ble'] ?? [];
if (is_array($ble) && count($ble) > 0) {
    $csv = $dir . '/ble.csv';
    // 旧形式(8列・14列)のファイルは、列を足した形に書き直す
    if (is_file($csv)) {
        $fh = fopen($csv, 'rb');
        $hd = $fh ? fgetcsv($fh) : false;
        if ($fh) fclose($fh);
        if (is_array($hd) && count($hd) < count($csvHeader)) {
            $tmp = $csv . '.tmp';
            $in = fopen($csv, 'rb'); $out = fopen($tmp, 'wb');
            if ($in && $out) {
                fgetcsv($in);
                fputcsv($out, $csvHeader);
                while (($r = fgetcsv($in)) !== false) {
                    if (count($r) < 8) continue;
                    if (count($r) < 11) {   // 8列(温湿度計だけの頃)
                        $r = array_slice($r, 0, 10);
                        while (count($r) < 10) $r[] = '';
                        $r[] = 'th';
                    }
                    while (count($r) < count($csvHeader)) $r[] = '';
                    fputcsv($out, $r);
                }
                fclose($in); fclose($out);
                rename($tmp, $csv);
            }
        }
    }
    $isNew = !is_file($csv);
    $fp = fopen($csv, 'ab');
    if ($fp) {
        if ($isNew) fputcsv($fp, $csvHeader);
        usort($ble, fn($a, $b) => ((int)($a['seq'] ?? 0)) <=> ((int)($b['seq'] ?? 0)));
        foreach ($ble as $r) {
            if (!is_array($r) || !isset($r['t']) || !is_numeric($r['t'])) continue;
            $seq = (int)($r['seq'] ?? 0);
            $ts  = (int)($r['ts'] ?? 0);
            $known = $ts > 1000000000;   // 時刻が分かっている記録

            // どの温湿度計の記録か
            $sid = (int)($r['s'] ?? 0);
            if ($sid > 0 && isset($sidMap[$sid])) { $key = $sidMap[$sid]['mac']; $nm = $sidMap[$sid]['name']; }
            else { $key = $legacyMac; $nm = ''; }

            $isW = isset($r['raw']) && is_numeric($r['raw']);   // 重量センサの記録
            $isA = !$isW && isset($r['al']) && is_numeric($r['al']);   // 電柵アラートの記録
            $s = $sens[$key] ?? ['name' => '', 'last_ts' => 0, 'last_seq' => 0];
            if ($isW && isset($r['sp']) && (int)$r['sp'] >= 65535) {
                // ノードの測定停止の印(raw: 1=電池電圧が低い 2=HX711の読み取り失敗)。重量の記録には入れず、状態だけ残す(weight.php が表示)
                $s['kind'] = 'w';
                $s['w_fail'] = (int)$r['raw'];
                $s['w_fail_epoch'] = max((int)($s['w_fail_epoch'] ?? 0), $known ? $ts : $now);
                if ($nm !== '') $s['name'] = $nm;
                $sens[$key] = $s;
                continue;
            }
            // 既に保存済みの記録は飛ばす(再送・二重送信対策。温湿度計ごとに判定)
            if ($known ? ($ts <= (int)$s['last_ts']) : ($seq <= (int)$s['last_seq'])) continue;

            $epoch = $known ? $ts : $now;
            fputcsv($fp, [
                date('Y-m-d H:i:s', $epoch), $epoch, $seq,
                round((float)$r['t'], 2),
                isset($r['h']) && is_numeric($r['h']) ? round((float)$r['h'], 2) : '',
                isset($r['bat']) && is_numeric($r['bat']) ? (int)$r['bat'] : '',
                isset($r['rssi']) && is_numeric($r['rssi']) ? (int)$r['rssi'] : '',
                $known ? 1 : 0,
                $nm, $key,
                $isW ? 'w' : ($isA ? 'fa' : 'th'),
                $isW ? (int)$r['raw'] : '',
                $isW && isset($r['sp']) && is_numeric($r['sp']) ? (int)$r['sp'] : '',
                ($isW || $isA) && isset($r['bv']) && is_numeric($r['bv']) ? round((float)$r['bv'], 2) : '',
                $isA ? (int)$r['al'] : '',
                $isA && isset($r['ev']) && is_numeric($r['ev']) ? (int)$r['ev'] : '',
                $isA && isset($r['ago']) && is_numeric($r['ago']) ? (int)$r['ago'] : '',
            ]);
            if ($known) $s['last_ts'] = max((int)$s['last_ts'], $ts);
            else $s['last_seq'] = max((int)$s['last_seq'], $seq);
            if ($nm !== '') $s['name'] = $nm;
            $s['kind'] = $isW ? 'w' : ($isA ? 'fa' : 'th');
            if ($isA) hpx_alert_record($s, $r, $epoch, $name, $nm !== '' ? $nm : $key);
            $s['last_epoch'] = max((int)($s['last_epoch'] ?? 0), $epoch);
            $sens[$key] = $s;
            $saved++;
        }
        fclose($fp);
    }
}
// 名前だけ変わったとき(新しい記録がなくても)反映する
foreach ($sidMap as $e) {
    if (isset($sens[$e['mac']]) && $e['name'] !== '') $sens[$e['mac']]['name'] = $e['name'];
}

// HPX-A の記録が途切れていないか
hpx_alert_check_missing($sens, $now, $name);

// --- 重量センサの較正(HPX-Core v1.14 以降の管理画面で較正したもの) ---
//   "wcal": [{mac, zero, per_kg, cal_raw?, cal_g?, common(0/1), ts}] を weight_cal.json に書く。
//   差し引く重さ(offset_kg)など、サーバーで入れた項目はそのまま残す。
if (isset($d['wcal']) && is_array($d['wcal'])) {
    $calPath = $dir . '/weight_cal.json';
    $cal = json_decode((string)@file_get_contents($calPath), true);
    if (!is_array($cal)) $cal = [];
    $changed = false;
    foreach ($d['wcal'] as $w) {
        if (!is_array($w)) continue;
        $mac = strtoupper((string)($w['mac'] ?? ''));
        if (!preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $mac)) continue;
        if (!is_numeric($w['zero'] ?? null) || !is_numeric($w['per_kg'] ?? null) || (float)$w['per_kg'] == 0.0) continue;
        $ts = is_numeric($w['ts'] ?? null) && (int)$w['ts'] > 1000000000 ? (int)$w['ts'] : $now;
        $c = is_array($cal[$mac] ?? null) ? $cal[$mac] : [];
        if ((int)($c['zero_reset'] ?? 0) <= $ts) {   // サーバーでゼロリセットしたあとなら、前の較正のゼロ点で戻さない
            $c['zero'] = (int)round((float)$w['zero']);
            unset($c['zero_reset']);
        }
        $c['per_kg'] = (float)$w['per_kg'];
        if (!empty($w['common'])) {
            unset($c['cal_raw'], $c['cal_g']);
            $c['common'] = 1;
        } else {
            if (is_numeric($w['cal_raw'] ?? null)) $c['cal_raw'] = (int)$w['cal_raw'];
            if (is_numeric($w['cal_g'] ?? null)) $c['cal_g'] = (float)$w['cal_g'];
            unset($c['common']);
        }
        $c['src'] = 'HPX-core';
        $c['updated'] = date('Y-m-d H:i:s', $ts);
        $cal[$mac] = $c;
        $changed = true;
    }
    if ($changed) {
        $tmp = $calPath . '.tmp';
        if (@file_put_contents($tmp, json_encode($cal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false) @rename($tmp, $calPath);
    }
}

// --- レポート本体(機器の状態)を月ごとのファイルに追記 ---
$rep = $d;
unset($rep['sensors']['ble']);   // 記録本体はble.csvにあるので、状態の履歴には入れない
$line = json_encode(['rx' => date('c', $now)] + $rep, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@file_put_contents($dir . '/reports-' . date('Y-m', $now) . '.jsonl', $line . "\n", FILE_APPEND);

// --- 状態を更新 ---
$state = [
    'name' => $name,
    'last_rx' => $now,
    'fw' => (string)($d['fw'] ?? ''),
    'build' => (int)($d['build'] ?? 0),
    'vbat' => isset($d['vbat']) ? (float)$d['vbat'] : null,
    'rssi' => isset($d['rssi']) ? (int)$d['rssi'] : null,
    'sensors' => $sens,
];
file_put_contents($statePath, json_encode($state, JSON_UNESCAPED_UNICODE));

flock($lock, LOCK_UN);
fclose($lock);
if ($migLock) { flock($migLock, LOCK_UN); fclose($migLock); }

// 先に機器へ返事をしてから、電柵アラートのメール(あれば)を送る。
// メールの送信に時間がかかっても、機器の待ち時間(8秒)を超えて「送信失敗」にならないようにするため。
$resp = json_encode(['ok' => true, 'saved' => $saved]);
ignore_user_abort(true);
header('Content-Length: ' . strlen($resp));
header('Connection: close');
echo $resp;
while (ob_get_level() > 0) ob_end_flush();
flush();
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

// 送り先: アカウントの通知先と、このハブ・電柵を紐づけた蜂場を見られるチームの人
hpx_alert_flush($dir, $uid !== '' ? apiary_alert_to($uid, hub_apiaries($uid, $id)) : $notifyTo);
