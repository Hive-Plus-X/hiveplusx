<?php
// 巣箱の警報: 巣箱ごとに決めた温度の上限・下限と、基準日からの重量の増減を見て、超えたらメールで知らせる。
//   設定は巣箱(site.json の hives の alert)に持つ: t_hi・t_lo(℃)、base(基準日 Y-m-d)、w_up・w_dn(kg)。空の項目は見ない。
//   重量は「基準日の同時刻」の値と今の値の差。例: 今が 14:20 の記録なら、基準日の 14:20 にいちばん近い記録(前後1時間半まで)と比べる。
//   hpx_cron.php(15分ごとなど)が check_hive_alerts() を呼ぶ。巣箱ページは hive_weight_base() で増減を表示する。
//   ログインしていなくても動くように、データはアカウントのフォルダから直接読む(hpx_auth.php だけを使う)。
declare(strict_types=1);
require_once __DIR__ . '/hpx_auth.php';

const HA_BASE_TOL = 5400;     // 基準日の同時刻の記録を探す幅(前後この秒数まで)
const HA_STALE_S = 7200;      // これより古い記録しかなければ警報を見ない(受信できていないことは別に知らせる)
const HA_T_HYST = 0.5;        // 温度が戻ったとみなす幅(上限 - 0.5℃ を下回ったら戻った、など)
const HA_W_HYST = 0.2;        // 重量の警報を解く幅(kg)

// 巣箱の警報の設定。数として正しいものだけを残す
function hive_alert_conf(array $hv): array
{
    $a = is_array($hv['alert'] ?? null) ? $hv['alert'] : [];
    $num = fn($k) => isset($a[$k]) && is_numeric($a[$k]) ? (float)$a[$k] : null;
    $base = (string)($a['base'] ?? '');
    return ['t_hi' => $num('t_hi'), 't_lo' => $num('t_lo'), 'base' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $base) ? $base : '',
        'w_up' => $num('w_up'), 'w_dn' => $num('w_dn')];
}

// 巣箱の重さ: 重量センサが1台なら巣箱の片側に置く使い方 (巣箱の半分の重さがかかる) なので、センサの値の2倍。
//   2台以上なら (巣箱の両側に1台ずつなど、全体の重さがセンサにかかる) センサの値の合計。どれかのセンサの値が無ければ null
const HW_PAIR_S = 600;        // 2台以上のとき、同じ時刻の記録とみなす幅(前後この秒数まで)
//   $full: その1台が巣箱全体を乗せる重量計 (BroodMinder-W3/W4。weight_cal.json の full_hive) なら2倍にしない
function hive_kg(array $kgs, bool $full = false): ?float
{
    if (!$kgs || in_array(null, $kgs, true)) return null;
    return count($kgs) === 1 ? ($full ? 1 : 2) * (float)reset($kgs) : (float)array_sum($kgs);
}
// 巣箱の重さの出し方の説明
function hive_weight_how(int $n, bool $full = false): string
{
    return $n <= 1 ? ($full ? '巣箱全体を量る重量センサ1台の値' : '重量センサ1台の値の2倍') : '重量センサ' . $n . '台の合計';
}
// 巣箱に紐づいた重量センサの較正の一覧から、「巣箱全体を量る1台だけ」か (2倍にしない) を返す
function hive_full_single(array $cals): bool
{
    return count($cals) === 1 && !empty(reset($cals)['full_hive']);
}
// センサごとの重さの記録 [[[epoch, kg], ...], ...] (それぞれ古い順) から、巣箱の重さの記録 [[epoch, kg], ...] を作る。
//   1台なら2倍。2台以上なら、1台目の記録の時刻に、ほかのセンサのいちばん近い記録 (前後 HW_PAIR_S 秒まで) を足す。どれかが無い時刻は出さない
function hive_kg_series(array $series, bool $full = false): array
{
    $series = array_values($series);
    if (!$series) return [];
    if (count($series) === 1) return array_map(fn($p) => [$p[0], ($full ? 1 : 2) * $p[1]], $series[0]);
    $out = [];
    $idx = array_fill(0, count($series), 0);
    foreach ($series[0] as $p) {
        $t = $p[0];
        $sum = $p[1];
        for ($s = 1; $s < count($series); $s++) {
            $r = $series[$s];
            $n = count($r);
            if ($n === 0) return [];
            $i = $idx[$s];
            while ($i + 1 < $n && abs($r[$i + 1][0] - $t) <= abs($r[$i][0] - $t)) $i++;
            $idx[$s] = $i;
            if (abs($r[$i][0] - $t) > HW_PAIR_S) continue 2;
            $sum += $r[$i][1];
        }
        $out[] = [$t, $sum];
    }
    return $out;
}

function ha_kg(int $raw, ?array $c): ?float
{
    if (!$c || !isset($c['zero'], $c['per_kg']) || (float)$c['per_kg'] == 0.0) return null;
    return ($raw - (float)$c['zero']) / (float)$c['per_kg'] - (float)($c['offset_kg'] ?? 0);
}

// ble.csv を1回読んで、mac ごとの最新の記録([epoch, 値]。温湿度計は温度、重量は生の値)と、
// $at に挙げた [mac, epoch] にいちばん近い重量の記録を返す
function ha_scan(string $hubDir, array $at = []): array
{
    $out = ['latest' => [], 'at' => []];
    $fh = @fopen($hubDir . '/ble.csv', 'rb');
    if (!$fh) return $out;
    $hd = fgetcsv($fh);
    $col = is_array($hd) ? array_flip($hd) : [];
    if (!isset($col['epoch'], $col['mac'], $col['kind'])) { fclose($fh); return $out; }
    $best = [];
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) < count($hd)) continue;
        $ep = (int)$r[$col['epoch']];
        $mac = (string)$r[$col['mac']];
        $kind = (string)$r[$col['kind']];
        if ($ep <= 0 || $kind === 'fa') continue;
        $v = $kind === 'w' ? ($r[$col['raw'] ?? -1] ?? '') : ($r[$col['temp_c'] ?? -1] ?? '');
        if ($kind !== 'w' && is_numeric($r[$col['temp2_c'] ?? -1] ?? '')) $v = $r[$col['temp2_c']];   // HPX-TH はサーミスターの温度で見る
        if (!is_numeric($v)) continue;
        if ($kind !== 'w') $v = (float)$v + th_temp_corr($hubDir, $mac, !is_numeric($r[$col['temp2_c'] ?? -1] ?? '') && !is_numeric($r[$col['batt_v'] ?? -1] ?? ''));   // 機種ごとの補正(Inkbird・HPX-TH)と温湿度計ごとの補正
        if (!isset($out['latest'][$mac]) || $ep >= $out['latest'][$mac][0]) $out['latest'][$mac] = [$ep, $kind === 'w' ? (int)$v : (float)$v];
        if ($kind !== 'w') continue;
        foreach ($at as $key => [$m, $t]) {
            if ($m !== $mac || abs($ep - $t) > HA_BASE_TOL) continue;
            if (!isset($best[$key]) || abs($ep - $t) < abs($best[$key][0] - $t)) $best[$key] = [$ep, (int)$v];
        }
    }
    fclose($fh);
    $out['at'] = $best;
    return $out;
}

// 巣箱の重さ (hive_kg) の、基準日からの増減。[[key, 出し方の説明, kg(今), base_kg, diff, epoch(今の記録), base_epoch]] (1つだけ)
//   基準日の記録が無いときは base_kg・diff が null。基準日が無い、または今の重さを出せない (較正していないセンサがあるなど) ときは空
function hive_weight_base(string $owner, array $hv): array
{
    $conf = hive_alert_conf($hv);
    if ($conf['base'] === '') return [];
    $byHub = [];
    foreach ((array)($hv['sensors'] ?? []) as $k) {
        [$hub, $mac] = array_pad(explode('/', (string)$k, 2), 2, '');
        if ($hub !== '' && $mac !== '') $byHub[$hub][] = $mac;
    }
    $kgs = []; $bkgs = []; $eps = []; $beps = []; $cals = [];
    foreach ($byHub as $hub => $macs) {
        $dir = account_hubs_dir($owner) . '/' . $hub;
        $st = json_load($dir . '/state.json');
        $macs = array_values(array_filter($macs, fn($m) => ($st['sensors'][$m]['kind'] ?? '') === 'w' && empty($st['sensors'][$m]['removed'])));   // 削除したセンサは数えない
        if (!$macs) continue;
        $cal = json_load($dir . '/weight_cal.json');
        $now = ha_scan($dir);   // まず今の記録を見て、その時刻から基準日の同時刻を決める
        $at = [];
        foreach ($macs as $m) {
            if (!isset($now['latest'][$m])) continue;
            $at[$m] = [$m, (int)strtotime($conf['base'] . ' ' . date('H:i:s', $now['latest'][$m][0]))];
        }
        $base = $at ? ha_scan($dir, $at)['at'] : [];
        foreach ($macs as $m) {
            $l = $now['latest'][$m] ?? null;
            $cals[] = is_array($cal[$m] ?? null) ? $cal[$m] : [];
            $kgs[] = $l ? ha_kg((int)$l[1], $cal[$m] ?? null) : null;
            $b = $base[$m] ?? null;
            $bkgs[] = $b ? ha_kg($b[1], $cal[$m] ?? null) : null;
            $eps[] = $l ? (int)$l[0] : 0;
            $beps[] = $b ? (int)$b[0] : (int)($at[$m][1] ?? 0);
        }
    }
    $full = hive_full_single($cals);
    $kg = hive_kg($kgs, $full);
    if ($kg === null) return [];
    $bkg = hive_kg($bkgs, $full);
    return [['hive', hive_weight_how(count($kgs), $full), $kg, $bkg, $bkg !== null ? $kg - $bkg : null, min($eps), min($beps)]];
}

// すべてのアカウントの巣箱の警報を見て、超えた・戻ったときにメールを送る(hpx_cron.php から)
//   状態はアカウントの site/hive_alert_state.json に、巣箱と項目ごと (温度は温湿度計ごと) に持つ。設定を変えたらその巣箱の状態は初めから
function check_hive_alerts(int $now): void
{
    if (!users_exist()) return;
    foreach (users_all() as $uid => $u) {
        $uid = (string)$uid;
        if (($u['status'] ?? '') !== 'active' || !is_uid($uid)) continue;
        $siteDir = account_site_dir($uid);
        $site = json_load($siteDir . '/site.json');
        $statePath = $siteDir . '/hive_alert_state.json';
        $state = json_load($statePath);
        $newState = [];
        foreach ((is_array($site['hives'] ?? null) ? $site['hives'] : []) as $hid => $hv) {
            $hid = (string)$hid;
            if (!is_array($hv)) continue;
            $conf = hive_alert_conf($hv);
            if ($conf['t_hi'] === null && $conf['t_lo'] === null && ($conf['base'] === '' || ($conf['w_up'] === null && $conf['w_dn'] === null))) continue;
            $sig = md5(json_encode($conf));
            $old = ($state[$hid]['sig'] ?? '') === $sig ? (array)($state[$hid]['on'] ?? []) : [];
            $on = [];
            $where = (string)($site['apiaries'][$hv['apiary'] ?? '']['name'] ?? '') . ' / ' . (string)($hv['name'] ?? '');
            $url = site_url('hive.php?' . http_build_query(['a' => (string)($hv['apiary'] ?? ''), 'h' => $hid]));
            // 温度(温湿度計ごとの最新の記録)
            if ($conf['t_hi'] !== null || $conf['t_lo'] !== null) {
                $byHub = [];
                foreach ((array)($hv['sensors'] ?? []) as $k) {
                    [$hub, $mac] = array_pad(explode('/', (string)$k, 2), 2, '');
                    if ($hub !== '' && $mac !== '') $byHub[$hub][] = $mac;
                }
                foreach ($byHub as $hub => $macs) {
                    $dir = account_hubs_dir($uid) . '/' . $hub;
                    $st = json_load($dir . '/state.json');
                    $scan = null;
                    foreach ($macs as $m) {
                        if (($st['sensors'][$m]['kind'] ?? 'th') !== 'th' || !empty($st['sensors'][$m]['removed'])) continue;
                        $scan ??= ha_scan($dir);
                        $l = $scan['latest'][$m] ?? null;
                        $key = $hub . '/' . $m;
                        $nm = (string)(($st['sensors'][$m]['name'] ?? '') !== '' ? $st['sensors'][$m]['name'] : $m);
                        if (!$l || $now - $l[0] > HA_STALE_S) {   // 記録が古いときは今の状態を保つ
                            foreach (['t_hi', 't_lo'] as $t) if (!empty($old[$t . '|' . $key])) $on[$t . '|' . $key] = true;
                            continue;
                        }
                        $v = (float)$l[1];
                        $tx = number_format($v, 1) . '℃';
                        foreach ([['t_hi', '上限', fn($x, $h) => $x > $h, fn($x, $h) => $x < $h - HA_T_HYST],
                                  ['t_lo', '下限', fn($x, $h) => $x < $h, fn($x, $h) => $x > $h + HA_T_HYST]] as [$t, $lb, $over, $back]) {
                            if ($conf[$t] === null) continue;
                            $id = $t . '|' . $key;
                            $was = !empty($old[$id]);
                            if (!$was && $over($v, $conf[$t])) {
                                $on[$id] = true;
                                hpx_alert_queue("【HPX】$where の温度が{$lb}を" . ($t === 't_hi' ? '超えました' : '下回りました') . " ($tx)",
                                    "巣箱の温度が、設定した{$lb}温度 (" . $conf[$t] . "℃) を" . ($t === 't_hi' ? '超えました' : '下回りました') . "。\n\n"
                                    . "巣箱: $where\nセンサ: $nm\n温度: $tx (" . date('Y-m-d H:i', $l[0]) . ")\n\n$url\n");
                            } elseif ($was && $back($v, $conf[$t])) {
                                hpx_alert_queue("【HPX】$where の温度が戻りました ($tx)",
                                    "巣箱の温度が、設定した{$lb}温度 (" . $conf[$t] . "℃) の内側に戻りました。\n\n"
                                    . "巣箱: $where\nセンサ: $nm\n温度: $tx (" . date('Y-m-d H:i', $l[0]) . ")\n\n$url\n");
                            } elseif ($was) {
                                $on[$id] = true;
                            }
                        }
                    }
                }
            }
            // 重量(基準日の同時刻からの増減)。戻ったときのメールは出さず、内側に戻ったら次に超えたときにまた知らせる
            if ($conf['base'] !== '' && ($conf['w_up'] !== null || $conf['w_dn'] !== null)) {
                foreach (hive_weight_base($uid, $hv) as [$key, $nm, $kg, $bkg, $diff, $ep, $bep]) {
                    if ($diff === null || $now - $ep > HA_STALE_S) {
                        foreach (['w_up', 'w_dn'] as $t) if (!empty($old[$t . '|' . $key])) $on[$t . '|' . $key] = true;
                        continue;
                    }
                    foreach ([['w_up', '増えました', $conf['w_up'] !== null && $diff >= $conf['w_up'], $conf['w_up'] !== null && $diff < $conf['w_up'] - HA_W_HYST],
                              ['w_dn', '減りました', $conf['w_dn'] !== null && $diff <= -$conf['w_dn'], $conf['w_dn'] !== null && $diff > -$conf['w_dn'] + HA_W_HYST]] as [$t, $verb, $over, $back]) {
                        if ($conf[$t] === null) continue;
                        $id = $t . '|' . $key;
                        $was = !empty($old[$id]);
                        $d = ($diff >= 0 ? '+' : '') . number_format($diff, 2) . 'kg';
                        if (!$was && $over) {
                            $on[$id] = true;
                            hpx_alert_queue("【HPX】$where の重量が基準日から{$verb} ($d)",
                                "巣箱の重量が、基準日 (" . date('n/j', strtotime($conf['base'])) . ") から設定した値 ("
                                . ($t === 'w_up' ? '+' : '-') . $conf[$t] . "kg) を超えて{$verb}。\n\n"
                                . "巣箱: $where\n重量: $nm\n基準日の同時刻: " . number_format((float)$bkg, 2) . "kg (" . date('Y-m-d H:i', $bep) . ")\n"
                                . "今: " . number_format($kg, 2) . "kg (" . date('Y-m-d H:i', $ep) . ")\n増減: $d\n\n$url\n");
                        } elseif ($was && !$back) {
                            $on[$id] = true;
                        }
                    }
                }
            }
            $newState[$hid] = ['sig' => $sig, 'on' => $on];
            // 送り先は巣箱ごと: 持ち主と、その蜂場を見られるチームの人
            hpx_alert_flush($siteDir, apiary_alert_to($uid, [(string)($hv['apiary'] ?? '')], 'hive'));
        }
        json_save($statePath, $newState);
    }
}
