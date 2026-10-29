<?php
// データ一括ダウンロード: 蜂場・巣箱・メモ・タスク・作業記録・センサの記録を、Excel で開ける CSV にして1つの ZIP で返す。
//   設定ページ(account.php)のボタンから POST で呼ぶ。見えるのは、蜂場ページなどで見られるものと同じ(共有された蜂場の分も入る)。
//   センサの記録は自分の HPX-core の分だけ(ble.csv の全期間。重量は較正があれば kg も付ける)。
//   写真は「写真も入れる」にしたときだけ(蜂場の写真と、メモの写真)。
//   CSV は UTF-8 (BOM付き)。ZIP はデータフォルダの tmp/ に作り、送ったら消す。
declare(strict_types=1);
require __DIR__ . '/hpx_site.php';

if (!is_post()) redirect('account.php#export');
@set_time_limit(300);

$site = site_load();
$memos = memos_load();
$works = works_load();
$team = team_on();

$tmpDir = data_dir() . '/tmp';
@mkdir($tmpDir, 0755, true);
foreach (glob($tmpDir . '/export_*') ?: [] as $old) if (filemtime($old) < $now - 3600) @unlink($old);   // 送りそこねた古いもの
$zipPath = $tmpDir . '/export_' . bin2hex(random_bytes(6)) . '.zip';
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) redirect('account.php#export', 'ダウンロードの準備ができませんでした。');

// CSV を1つ作って ZIP に入れる
function csv_add(ZipArchive $zip, string $name, array $head, array $rows): void
{
    $fh = fopen('php://temp', 'w+b');
    fwrite($fh, "\xEF\xBB\xBF");   // Excel で文字化けしないように(UTF-8 の印)
    fputcsv($fh, $head);
    foreach ($rows as $r) fputcsv($fh, array_map(fn($v) => is_array($v) ? implode('・', array_map('strval', $v)) : (string)$v, $r));
    rewind($fh);
    $zip->addFromString($name, (string)stream_get_contents($fh));
    fclose($fh);
}
$dt = fn($ep) => !empty($ep) ? date('Y-m-d H:i', (int)$ep) : '';
$who = fn($uid) => (string)$uid !== '' ? user_name((string)$uid) : '';
$aname = fn(string $aid) => $aid === '' ? 'すべて' : (string)($site['apiaries'][$aid]['name'] ?? '(削除した蜂場)');
$hname = fn(string $hid) => $hid === '' ? '' : (string)($site['hives'][$hid]['name'] ?? '(削除した巣箱)');
$hubs = hpx_hubs();
$sensors = hpx_sensors();
$sname = fn(string $k) => isset($sensors[$k]) ? $sensors[$k]['hubName'] . ' / ' . $sensors[$k]['name'] : (isset($hubs[$k]) ? $hubs[$k]['name'] : $k);

// ---- 蜂場 ----
$rows = [];
foreach ($site['apiaries'] as $aid => $a) {
    $rows[] = array_merge([$a['name'] ?? '', $a['address'] ?? '', $a['map_q'] ?? '', isset($a['hive_count']) ? (int)$a['hive_count'] : '', count(hives_of($site, (string)$aid)),
        $a['owner'] ?? '', $a['note'] ?? '', array_map($sname, (array)($a['hubs'] ?? [])), array_map($sname, (array)($a['fences'] ?? [])),
        $dt($a['created'] ?? 0), $dt($a['updated'] ?? 0)], $team ? [$who($a['_o'])] : [], [(string)$aid]);
}
csv_add($zip, '蜂場.csv', array_merge(['蜂場', '所在地住所', '緯度・経度', '巣箱数 (手入力)', '登録した巣箱', '地主・連絡先', '説明・備考', 'HPX-core', '電柵',
    '作成', '更新'], $team ? ['持ち主'] : [], ['蜂場ID']), $rows);

// ---- 巣箱 ----
$rows = [];
foreach ($site['hives'] as $hid => $x) {
    $al = hive_alert_conf($x);
    $rows[] = [$aname((string)($x['apiary'] ?? '')), $x['name'] ?? '', $x['state'] ?? '', $x['started'] ?? '', $x['queen'] ?? '', $x['box'] ?? '', $x['note'] ?? '',
        array_map($sname, (array)($x['sensors'] ?? [])),
        $al['w_up'] ?? '', $al['w_dn'] ?? '', $al['base'] ?? '', $al['t_hi'] ?? '', $al['t_lo'] ?? '',
        $dt($x['created'] ?? 0), $dt($x['updated'] ?? 0), (string)$hid];
}
csv_add($zip, '巣箱.csv', ['蜂場', '巣箱', '状態', '群を入れた日', '女王', '巣箱 (種類・段数など)', '備考', 'センサ',
    '警報 重量 上限 (+kg)', '警報 重量 下限 (-kg)', '警報 重量 基準日', '警報 温度 上限 (℃)', '警報 温度 下限 (℃)', '作成', '更新', '巣箱ID'], $rows);

// ---- メモ ----
$rows = [];
$ms = $memos;
usort($ms, fn($x, $y) => [$x['date'] ?? '', $x['created'] ?? 0] <=> [$y['date'] ?? '', $y['created'] ?? 0]);
foreach ($ms as $m) {
    $iv = (array)($m['inspect'] ?? []);
    $mt = (array)($m['mite'] ?? []);
    $r = [$m['date'] ?? '', $aname((string)($m['apiary'] ?? '')), $hname((string)($m['hive'] ?? '')), $m['type'] ?? ''];
    foreach (array_keys(M_INSPECT) as $k) $r[] = $iv[$k] ?? '';
    $r[] = $mt['method'] ?? '';
    $r[] = $mt['bees'] ?? '';
    $r[] = $mt['mites'] ?? '';
    $r[] = isset($mt['bees'], $mt['mites']) && $mt['bees'] > 0 ? number_format($mt['mites'] / $mt['bees'] * 100, 1, '.', '') : '';
    array_push($r, (array)($m['tags'] ?? []), $m['text'] ?? '', count((array)($m['photos'] ?? [])), $who($m['by'] ?? ''), $dt($m['created'] ?? 0), $dt($m['updated'] ?? 0), (string)($m['id'] ?? ''));
    $rows[] = $r;
}
csv_add($zip, 'メモ.csv', array_merge(['日付', '蜂場', '巣箱', '内容'], array_map(fn($it) => $it[0], array_values(M_INSPECT)),
    ['ダニ調査 方法', 'ダニ調査 検査ミツバチ数', 'ダニ調査 ダニ数', 'ダニ寄生率 (%)', 'タグ', 'メモ', '写真の枚数', '記入者', '作成', '更新', 'メモID']), $rows);

// ---- タスク ----
$rows = [];
foreach (todo_sort($works['todos']) as $t) {
    $rows[] = [$t['due'] ?? '', $aname((string)($t['apiary'] ?? '')), $hname((string)($t['hive'] ?? '')), (array)($t['tags'] ?? []), $t['title'] ?? '', $t['note'] ?? '',
        !empty($t['done']) ? '完了' : '未完了', $dt($t['done_at'] ?? 0), !empty($t['notify']) ? '知らせる' : '', implode('・', array_map('user_name', todo_assignees($t))), $who($t['by'] ?? ''),
        $dt($t['created'] ?? 0), (string)($t['id'] ?? '')];
}
csv_add($zip, 'タスク.csv', ['期限', '蜂場', '巣箱', 'タグ', 'やること', 'メモ', '状態', '完了日時', 'メール', '担当者', '記入者', '作成', 'タスクID'], $rows);

// ---- 作業記録 ----
$rows = [];
$ls = $works['logs'];
usort($ls, fn($x, $y) => [$x['date'] ?? '', $x['start'] ?? ''] <=> [$y['date'] ?? '', $y['start'] ?? '']);
foreach ($ls as $l) {
    $rows[] = [$l['date'] ?? '', $aname((string)($l['apiary'] ?? '')), $hname((string)($l['hive'] ?? '')), $l['type'] ?? '', $l['start'] ?? '', $l['end'] ?? '',
        (int)($l['minutes'] ?? 0), (int)($l['people'] ?? 1), (int)($l['minutes'] ?? 0) * (int)($l['people'] ?? 1), implode('・', array_map('user_name', log_workers($l))), $l['note'] ?? '', $who($l['by'] ?? ''), (string)($l['id'] ?? '')];
}
csv_add($zip, '作業記録.csv', ['日付', '蜂場', '巣箱', '作業', '開始', '終了', '時間 (分)', '人数', 'のべ時間 (分)', '担当者', 'メモ', '記入者', '作業記録ID'], $rows);

// ---- センサの記録(自分の HPX-core ごとに、ble.csv の全期間。見出しを日本語にし、重量は kg も付ける) ----
$label = ['time' => '日時', 'epoch' => 'epoch', 'seq' => 'seq', 'temp_c' => '温度 (℃)', 'humidity_pct' => '湿度 (%)', 'battery_pct' => '電池 (%)', 'rssi_dbm' => '電波 (dBm)',
    'ts_known' => '時刻確定', 'sensor' => 'センサ名', 'mac' => 'MAC', 'kind' => '種類', 'raw' => '重量 (生の値)', 'spread' => '重量のばらつき', 'batt_v' => '電池 (V)',
    'fence_state' => '電柵', 'fence_events' => '電柵 検出の通算回数', 'fence_ago' => '電柵 検出からの秒数'];
$nSensorFiles = 0;
$sensorTmp = [];
foreach ($hubs as $hubId => $hb) {
    if ($hb['o'] !== $UID || !is_file($hb['dir'] . '/ble.csv')) continue;
    $in = @fopen($hb['dir'] . '/ble.csv', 'rb');
    $hd = $in ? fgetcsv($in) : false;
    if (!is_array($hd)) { if ($in) fclose($in); continue; }
    $col = array_flip($hd);
    $cal = cal_load((string)$hubId);
    $tmp = $tmpDir . '/export_' . bin2hex(random_bytes(6)) . '.csv';
    $out = fopen($tmp, 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    $head = [];
    foreach ($hd as $k) {
        $head[] = $label[$k] ?? $k;
        if ($k === 'raw') $head[] = '重量 (kg)';
    }
    fputcsv($out, $head);
    while (($r = fgetcsv($in)) !== false) {
        if (count($r) < count($hd)) continue;
        $line = [];
        foreach ($hd as $i => $k) {
            $v = (string)$r[$i];
            if ($k === 'kind') $v = ['th' => '温湿度', 'w' => '重量', 'fa' => '電柵アラート'][$v] ?? $v;
            if ($k === 'fence_state' && $v !== '') $v = (int)$v === 0 ? '正常' : '異常';
            $line[] = $v;
            if ($k === 'raw') {
                $kg = is_numeric($v) ? to_kg((int)$v, $cal[(string)($r[$col['mac'] ?? -1] ?? '')] ?? null) : null;
                $line[] = $kg === null ? '' : number_format($kg, 2, '.', '');
            }
        }
        fputcsv($out, $line);
    }
    fclose($in);
    fclose($out);
    $zip->addFile($tmp, 'センサ_' . (preg_replace('#[\\\\/:*?"<>|]#u', '_', $hb['name']) ?: $hubId) . '.csv');
    $sensorTmp[] = $tmp;
    $nSensorFiles++;
}

// ---- 写真(選んだときだけ) ----
if (!empty($_POST['photos'])) {
    foreach ($site['apiaries'] as $aid => $a) {
        $ext = (string)($a['photo'] ?? '');
        $p = site_dir_of((string)$a['_o']) . '/photos/' . $aid . '.' . $ext;
        if ($ext !== '' && preg_match('/^[a-z]+$/', $ext) && is_file($p)) $zip->addFile($p, '写真/蜂場_' . preg_replace('#[\\\\/:*?"<>|]#u', '_', (string)$a['name']) . '_' . $aid . '.' . $ext);
    }
    foreach ($memos as $m) {
        foreach (array_values((array)($m['photos'] ?? [])) as $i => $nm) {
            $p = site_dir_of((string)$m['_o']) . '/memo_photos/' . $nm . '.jpg';
            if (preg_match('/^[0-9a-f]+_[0-9a-f]+$/', (string)$nm) && is_file($p)) $zip->addFile($p, '写真/メモ_' . ($m['date'] ?? '') . '_' . ($m['id'] ?? '') . '_' . ($i + 1) . '.jpg');
        }
    }
}

$zip->addFromString('はじめにお読みください.txt', "\xEF\xBB\xBF" . BRAND_NAME . " データ一括ダウンロード\r\n"
    . '作成: ' . date('Y-m-d H:i', $now) . ' / ' . (string)($ME['email'] ?? '') . "\r\n\r\n"
    . "・CSV は Excel でそのまま開けます (UTF-8)。\r\n"
    . "・蜂場・巣箱・メモ・タスク・作業記録は、蜂場ページなどで見られるもの (チームで共有された蜂場の分も含む) です。\r\n"
    . "・センサ_<HPX-core名>.csv は、自分の HPX-core の全期間の記録です。重量 (kg) は較正を入れたセンサだけ出ます。\r\n"
    . (!empty($_POST['photos']) ? "・写真フォルダに、蜂場の写真とメモの写真が入っています。\r\n" : ''));
$zip->close();
foreach ($sensorTmp as $t) @unlink($t);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="hpx_data_' . date('Ymd_Hi', $now) . '.zip"');
header('Content-Length: ' . filesize($zipPath));
header('Cache-Control: no-store');
readfile($zipPath);
@unlink($zipPath);
