<?php
// センサの記録 (ble.csv) を読む・CSV の形にする。センサページ (sensor.php) と データ一括ダウンロード (export.php) で使う。
//   hpx_auth.php の後に読む (th_temp_corr・th_hum_fix を使う)。重量の kg は、読む側の to_kg() を使う。
//
//   ダウンロードの CSV は2種類 (2026-11-16 TARO 要望):
//     定時記録データ (既定) … 保存間隔 (15分 / 30分 / 1時間) の区切りの時刻の記録だけを、区切りごとに必ず1行ずつ出す。
//                             記録が無い区切り (センサの不調・HPX-Core の停止など) は、日時だけ書いて値は空にする。
//                             HPX-Core を入れ直した時や、管理画面の「今すぐ記録」で取った、区切りでない時刻の記録は入れない。
//     全データ             … これまでどおり、記録のあるすべての時刻を1行ずつ (分単位でまとめる)。
//   HPX-Core (v1.09 から) は記録の時刻を区切り (15分なら 00・15・30・45分) にそろえる。区切りから5分以内に取れた記録も区切りの時刻になる。
//   それ以外の時刻 (入れ直した時・今すぐ記録) はその時刻のまま残るので、時刻が区切りちょうどかどうかで見分ける。
declare(strict_types=1);

const BLE_STEPS = [900 => '15分', 1800 => '30分', 3600 => '1時間'];   // HPX-Core の保存間隔 (v1.17 から 15/30/60分)

// ble.csv から $since 以降の記録を、種類ごと・センサーごとに古い順で返す
//   th: mac => [[epoch, 温度, 湿度, 電池%, 電池V, HPX-TH本体温度], ...]
//       温度は HPX-TH ならサーミスターの温度 (無いときは SHT40)。本体温度 (SHT40) はサーミスターがあるときだけ。電池V は HPX-TH だけ
//   w : mac => [[epoch, 生の値, ばらつき, ノード内温度, 電池V], ...]
//   fa: [[epoch, 名前, mac, 状態, 通算回数], ...]
//   first: いちばん古い記録の時刻(全期間のグラフの左端に使う)
function read_ble(string $csv, int $since): array
{
    $out = ['th' => [], 'w' => [], 'fa' => [], 'first' => 0];
    $fh = @fopen($csv, 'rb');
    if (!$fh) return $out;
    $hd = fgetcsv($fh);
    if (!is_array($hd)) { fclose($fh); return $out; }
    $col = array_flip($hd);
    foreach (['epoch', 'temp_c', 'humidity_pct', 'sensor', 'mac', 'kind'] as $k) {
        if (!isset($col[$k])) { fclose($fh); return $out; }
    }
    $g = fn(array $r, string $k) => isset($col[$k]) ? (string)($r[$col[$k]] ?? '') : '';
    $num = fn(string $v) => is_numeric($v) ? (float)$v : null;
    // いちばん古い記録の時刻は最初の行で見る。$since より前の記録は読み飛ばす (ble_seek、hpx_auth.php)
    $pos = ftell($fh);
    $r0 = fgetcsv($fh);
    if (is_array($r0) && (int)($r0[$col['epoch']] ?? 0) > 0) $out['first'] = (int)$r0[$col['epoch']];
    fseek($fh, $pos);
    ble_seek($fh, $col['epoch'], $since);
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) < count($hd)) continue;
        $ep = (int)$r[$col['epoch']];
        if ($ep <= 0) continue;
        if ($out['first'] === 0 || $ep < $out['first']) $out['first'] = $ep;
        if ($ep < $since) continue;
        $mac = $g($r, 'mac');
        $kind = $g($r, 'kind');
        if ($kind === 'w') {
            if (!is_numeric($g($r, 'raw'))) continue;
            $out['w'][$mac][] = [$ep, (int)$g($r, 'raw'), $num($g($r, 'spread')), $num($g($r, 'temp_c')), $num($g($r, 'batt_v')), $num($g($r, 'battery_pct'))];   // [5] 電池% (BroodMinder)
        } elseif ($kind === 'fa') {
            if ($g($r, 'fence_state') === '') continue;
            $out['fa'][] = [$ep, $g($r, 'sensor') !== '' ? $g($r, 'sensor') : $mac, $mac, (int)$g($r, 'fence_state'), (int)$g($r, 'fence_events')];
        } else {
            $sht = $num($g($r, 'temp_c'));
            $t2 = $num($g($r, 'temp2_c'));   // HPX-TH のサーミスター(巣箱の中の温度)
            $t = $t2 ?? $sht;
            if ($t === null) continue;
            $ink = $t2 === null && $num($g($r, 'batt_v')) === null;   // Inkbird の形(HPX-TH でない)
            $t += th_temp_corr(dirname($csv), $mac, $ink);   // 機種ごとの補正(Inkbird・HPX-TH)と温湿度計ごとの補正
            $out['th'][$mac][] = [$ep, $t, th_hum_fix($num($g($r, 'humidity_pct')), dirname($csv), $mac, $ink), $num($g($r, 'battery_pct')), $num($g($r, 'batt_v')), $t2 !== null ? $sht : null];
        }
    }
    fclose($fh);
    foreach (['th', 'w'] as $k) foreach ($out[$k] as &$rows) usort($rows, fn($a, $b) => $a[0] <=> $b[0]);
    unset($rows);
    usort($out['fa'], fn($a, $b) => $a[0] <=> $b[0]);
    return $out;
}


// 保存間隔 (秒) を記録から推し量る。HPX-Core は保存間隔をサーバーへ送らないので、
// 最近の記録で、区切りちょうどの記録どうしの間がいちばん多いもの (15分・30分・1時間) にする。分からなければ 15分
function ble_guess_step(array $data): int
{
    $cnt = [900 => 0, 1800 => 0, 3600 => 0];
    foreach (['th', 'w'] as $k) {
        foreach ($data[$k] as $rows) {
            $prev = null;
            foreach (array_slice($rows, -300) as $r) {   // 台ごとに最近の300件 (15分なら約3日)
                $ep = (int)$r[0];
                if ($ep % 900 !== 0) continue;
                if ($prev !== null && isset($cnt[$ep - $prev])) $cnt[$ep - $prev]++;
                $prev = $ep;
            }
        }
    }
    $best = 900;
    foreach ($cnt as $s => $n) if ($n > $cnt[$best]) $best = $s;
    return $best;
}

// 日時ごとに1行の CSV にする。列は 温湿度計(温度・湿度)を台ごとに → 重量センサ(kg)を台ごとに → 電柵アラート(正常/異常)
//   $data: read_ble() の結果   $stSensors: state.json の sensors (列の順と名前)   $cal: 重量の較正 (mac => 較正)
//   $step: 0 = 全データ (記録のある時刻ごと、分単位)。900・1800・3600 = 定時記録データ (その間隔の区切りごとに必ず1行)
//   $end:  定時記録データの最後の区切り (この時刻まで空の行も出す)
//   $linkOf: mac => 紐づけ先の移り変わり (ble_link_names())。あれば見出しの次の行に「紐づけ先」として出し、
//            途中で付け替えたセンサは紐づけ先ごとに別の列にする (付け替えた日時は書かない)
//   返り値: [見出しの配列, [[日時, 値, ...], ...]]
function ble_wide_csv(array $data, array $stSensors, array $cal, int $step = 0, int $end = 0, array $linkOf = []): array
{
    // 列の順: 状態に登録されている順(=画面の表と同じ)、記録にだけあるセンサーは後ろに付ける
    $names = [];
    $order = ['th' => [], 'w' => []];
    foreach ($stSensors as $mac => $s) {
        if (!is_array($s)) continue;
        $names[(string)$mac] = (string)($s['name'] ?? '');
        $k = (string)($s['kind'] ?? 'th');
        if (isset($order[$k])) $order[$k][] = (string)$mac;
    }
    foreach (['th', 'w'] as $k) {
        foreach (array_keys($data[$k]) as $mac) if (!in_array((string)$mac, $order[$k], true)) $order[$k][] = (string)$mac;
        $order[$k] = array_values(array_filter($order[$k], fn($m) => isset($data[$k][$m])));
    }
    $nm = fn(string $mac) => ($names[$mac] ?? '') !== '' ? $names[$mac] : $mac;

    // 行の区切り。全データは分単位でまとめる。定時記録データは区切りちょうどの記録だけ (null = 入れない)
    $slot = $step > 0 ? (fn(int $ep) => ($ep % $step === 0 ? $ep : null)) : (fn(int $ep) => intdiv($ep, 60) * 60);
    // 紐づけ先: 記録の時刻のときの紐づけ先の名前。途中で付け替えたセンサは、紐づけ先ごとに別の列にする (2026-11-16 TARO)
    $linkAt = function (string $mac, int $ep) use ($linkOf): string {
        $nm = '';
        foreach ($linkOf[$mac] ?? [] as [$from, $name]) { if ($ep < $from) break; $nm = $name; }
        return $nm;
    };
    // センサの記録を紐づけ先ごとに分ける: [紐づけ先 => [記録, ...]] (出てきた順)
    $split = function (string $mac, array $recs) use ($linkAt): array {
        $g = [];
        foreach ($recs as $r) $g[$linkAt($mac, (int)$r[0])][] = $r;
        if (count($g) > 1 && isset($g[''])) { $x = $g['']; unset($g['']); $g[''] = $x; }   // 紐づけなしの間の記録 (ふつうは無い) は、いちばん後ろの名前の無い列に
        return $g;
    };
    $rows = [];
    $head = ['日時'];
    $lk = ['紐づけ先'];
    $col = 0;
    foreach ($order['th'] as $mac) {
        foreach ($split($mac, $data['th'][$mac]) as $ln => $recs) {
            $head[] = $nm($mac) . ' 温度(℃)';
            $head[] = $nm($mac) . ' 湿度(%)';
            array_push($lk, (string)$ln, (string)$ln);
            foreach ($recs as $r) {
                if (($t = $slot($r[0])) === null) continue;
                $rows[$t][$col] = number_format($r[1], 1, '.', '');
                $rows[$t][$col + 1] = $r[2] === null ? '' : number_format($r[2], 1, '.', '');
            }
            $col += 2;
        }
    }
    foreach ($order['w'] as $mac) {
        $c = $cal[$mac] ?? null;
        $calOk = $c && isset($c['per_kg']);
        foreach ($split($mac, $data['w'][$mac]) as $ln => $recs) {
            $head[] = $nm($mac) . ($calOk ? ' 重量(kg)' : ' 重量(生の値・未較正)');
            $lk[] = (string)$ln;
            foreach ($recs as $r) {
                if (($t = $slot($r[0])) === null) continue;
                $v = $calOk ? to_kg($r[1], $c) : null;
                $rows[$t][$col] = $calOk ? ($v === null ? '' : number_format($v, 2, '.', '')) : (string)$r[1];
            }
            $col++;
        }
    }
    $faCol = $col;
    if ($data['fa']) {
        $head[] = '電柵アラート';
        $fnames = [];   // 電柵アラートは1列にまとめるので、紐づけた蜂場を並べる
        foreach (array_unique(array_column($data['fa'], 2)) as $m) foreach ($linkOf[$m] ?? [] as [, $n]) if ($n !== '') $fnames[$n] = true;
        $lk[] = implode('、', array_keys($fnames));
        $multi = count(array_unique(array_column($data['fa'], 2))) > 1;
        foreach ($data['fa'] as $r) {
            [$ep, $fname, , $fst] = $r;
            if (($t = $slot($ep)) === null) continue;
            $cur = $rows[$t][$faCol] ?? '';
            if ($fst !== 0) {
                $add = $multi ? $fname : '';
                $rows[$t][$faCol] = $cur === '' || $cur === '正常' ? '異常' . ($add !== '' ? ' (' . $add . ')' : '')
                    : ($add !== '' ? rtrim($cur, ')') . '・' . $add . ')' : $cur);
            } elseif ($cur === '') {
                $rows[$t][$faCol] = '正常';
            }
        }
    }
    ksort($rows);

    $n = count($head) - 1;
    $line = function (int $t, array $vals) use ($n): array {
        $l = [date('Y-m-d H:i', $t)];
        for ($j = 0; $j < $n; $j++) $l[] = $vals[$j] ?? '';
        return $l;
    };
    $out = [];
    if ($linkOf && count(array_filter(array_slice($lk, 1))) > 0) $out[] = $lk;
    if ($step > 0) {
        // 最初の定時記録から $end まで、区切りごとに必ず1行。記録が無い区切りは日時だけ
        if (!$rows) return [$head, $out];
        $last = max((int)array_key_last($rows), intdiv($end, $step) * $step);
        for ($t = (int)array_key_first($rows); $t <= $last; $t += $step) $out[] = $line($t, $rows[$t] ?? []);
    } else {
        foreach ($rows as $t => $vals) $out[] = $line((int)$t, $vals);
    }
    return [$head, $out];
}

// センサごとの紐づけ先 (巣箱・蜂場) の移り変わり。mac => [[この時刻から, 名前], ...] 古い順 (最初の時刻は PHP_INT_MIN)
//   名前は「蜂場 / 巣箱」、蜂場の温湿度センサは「蜂場 / 蜂場」、電柵アラートは蜂場名。紐づけなしは ''
//   site/links.json の履歴 (2026-11-13 から) を使う。履歴の始まりより前は、始まりの時の紐づけ先とみなす。履歴が無ければ今の紐づけ
//   $owner: HPX-Core の持ち主のアカウント  $macs: mac => 'th'|'w'|'fa'
function ble_link_names(string $owner, string $hubId, array $macs): array
{
    $sj = json_load(account_site_dir($owner) . '/site.json');
    $aps = is_array($sj['apiaries'] ?? null) ? $sj['apiaries'] : [];
    $hvs = is_array($sj['hives'] ?? null) ? $sj['hives'] : [];
    $apName = fn(string $a) => (string)($aps[$a]['name'] ?? '');
    $hvName = fn(string $h) => isset($hvs[$h]) ? trim($apName((string)($hvs[$h]['apiary'] ?? '')) . ' / ' . (string)($hvs[$h]['name'] ?? '')) : '';
    // 今の紐づけ (履歴が無いときに使う)
    $cur = [];
    foreach ($aps as $aid => $a) {
        if (!is_array($a)) continue;
        foreach ((array)($a['fences'] ?? []) as $v) $cur['f:' . $v] = $apName((string)$aid);
        foreach ((array)($a['ths'] ?? []) as $v) $cur['s:' . $v] = $apName((string)$aid) . ' / 蜂場';
    }
    foreach ($hvs as $hid => $h) if (is_array($h)) foreach ((array)($h['sensors'] ?? []) as $v) $cur['s:' . $v] = $hvName((string)$hid);
    // 履歴の名前: 今もある巣箱・蜂場は今の名前 (名前を変えても同じ列にする)、消したものは履歴に書いた名前
    $name = function (array $e) use ($aps, $hvs, $apName, $hvName): string {
        $to = (string)($e['to'] ?? '');
        if ($to === '') return '';
        if (str_starts_with($to, 'a:')) return isset($aps[substr($to, 2)]) ? $apName(substr($to, 2)) . ' / 蜂場' : preg_replace('/ \(蜂場の気温・湿度\)$/u', '', (string)($e['to_name'] ?? '')) . ' / 蜂場';
        if (str_starts_with((string)$e['key'], 'f:')) return isset($aps[$to]) ? $apName($to) : (string)($e['to_name'] ?? '');
        return isset($hvs[$to]) ? $hvName($to) : (string)($e['to_name'] ?? '');
    };
    $log = json_load(account_site_dir($owner) . '/links.json')['log'] ?? [];
    $byKey = [];
    foreach (is_array($log) ? $log : [] as $e) if (is_array($e) && isset($e['key'], $e['t'])) $byKey[(string)$e['key']][] = $e;
    $out = [];
    foreach ($macs as $mac => $kind) {
        $key = ($kind === 'fa' ? 'f:' : 's:') . $hubId . '/' . $mac;
        $es = $byKey[$key] ?? [];
        if (!$es) { $out[(string)$mac] = [[PHP_INT_MIN, $cur[$key] ?? '']]; continue; }
        usort($es, fn($a, $b) => (int)$a['t'] <=> (int)$b['t']);
        $seg = [];
        foreach ($es as $i => $e) $seg[] = [$i === 0 ? PHP_INT_MIN : (int)$e['t'], $name($e)];
        $out[(string)$mac] = $seg;
    }
    return $out;
}

// read_ble() の結果にあるセンサの mac => 種類
function ble_macs(array $data): array
{
    $m = [];
    foreach (['th', 'w'] as $k) foreach (array_keys($data[$k]) as $mac) $m[(string)$mac] = $k;
    foreach ($data['fa'] as $r) $m[(string)$r[2]] = 'fa';
    return $m;
}
