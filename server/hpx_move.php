<?php
// HPX-Core の引っ越し (2026-11-28 TARO): 古い HPX-Core を新しい HPX-Core に入れ替えたとき、
//   古い Core の記録・センサの名前・風袋引き・温湿度の補正・蜂場と巣箱の紐づけを、新しい Core へそっくり引き継ぐ。
//   サーバーは HPX-Core をチップの番号 (12桁、hubs/<番号>/) で見分け、紐づけも「番号/センサのMAC」で覚えているので、
//   入れ替えると別の機器として扱われてしまう。そこで、センサページの「HPX-Core の引っ越し」で古い → 新しいを選ぶと:
//     1. 古い Core の記録 (ble.csv) と新しい Core の記録を時刻順に1つにまとめて、新しい Core のフォルダに入れる
//     2. state.json のセンサごとの情報、weight_cal.json (風袋引きなど)、th_cal.json (温湿度の補正) も古いものを引き継ぐ
//        (画面で入れた値は古い Core のものを使う。新しい Core にしかないセンサはそのまま)
//     3. site.json (蜂場・巣箱の紐づけ) と links.json (つながりの履歴) の古い番号を新しい番号に書き換える
//     4. 古い Core のフォルダは hubs_moved/<番号>_<日時>/ へ移す (消さない。何かあったときに戻せるように)
//     5. hubs/moves.json に「古い番号 → 新しい番号」を書く。このあと古い Core から届いた記録は保存しない (同じセンサの記録が二重にならないように)。
//        古い Core を別の蜂場でまた使うときは、センサページの「引っ越した HPX-Core」で「また使う」を押す (印を消すと、新しい機器として届く)
//   新しい HPX-Core の設定は、HPX-Core v1.43 の管理画面「設定の書き出し・読み込み」で古い Core から写せる。
declare(strict_types=1);
require_once __DIR__ . '/hpx_auth.php';

function core_moves_path(string $hubsDir): string { return $hubsDir . '/moves.json'; }

// 引っ越した古い Core の一覧: 古い番号 => ['to' => 新しい番号, 'time' => 日時, 'name' => 古い名前]
function core_moves(string $hubsDir): array
{
    $v = json_load(core_moves_path($hubsDir));
    return is_array($v['moved'] ?? null) ? $v['moved'] : [];
}

// 配列の中の文字列 (キーも) にある古い番号を新しい番号に置き換える
function core_move_rewrite($v, string $old, string $new)
{
    if (is_string($v)) return str_replace($old, $new, $v);
    if (!is_array($v)) return $v;
    $out = [];
    foreach ($v as $k => $x) $out[is_string($k) ? str_replace($old, $new, $k) : $k] = core_move_rewrite($x, $old, $new);
    return $out;
}

// 2つの ble.csv を時刻 (epoch) 順にまとめる。列は見出しの名前で合わせる (新しい方の見出しを使う)
function core_move_merge_csv(string $oldCsv, string $newCsv, string $outCsv): bool
{
    $read = function (string $p): array {
        if (!is_file($p)) return [[], []];
        $fh = fopen($p, 'rb');
        if (!$fh) return [[], []];
        $hd = fgetcsv($fh);
        $rows = [];
        while (($r = fgetcsv($fh)) !== false) if ($r !== [null]) $rows[] = $r;
        fclose($fh);
        return [is_array($hd) ? $hd : [], $rows];
    };
    [$hdO, $rowsO] = $read($oldCsv);
    [$hdN, $rowsN] = $read($newCsv);
    $head = count($hdN) >= count($hdO) ? $hdN : $hdO;
    if (!$head) return true;   // どちらにも記録が無い
    $map = function (array $hd, array $rows) use ($head): array {
        $col = array_flip($hd);
        $out = [];
        foreach ($rows as $r) {
            $x = [];
            foreach ($head as $name) $x[] = isset($col[$name]) ? (string)($r[$col[$name]] ?? '') : '';
            $out[] = $x;
        }
        return $out;
    };
    $all = array_merge($map($hdO, $rowsO), $map($hdN, $rowsN));
    $ei = array_search('epoch', $head, true);
    if ($ei !== false) {
        // 同じ時刻なら古い Core の行を先に (並べ替えても順番が崩れないように番号も使う)
        $idx = array_keys($all);
        usort($idx, fn($a, $b) => ((int)$all[$a][$ei] <=> (int)$all[$b][$ei]) ?: ($a <=> $b));
        $all = array_map(fn($i) => $all[$i], $idx);
    }
    $tmp = $outCsv . '.tmp';
    $fh = fopen($tmp, 'wb');
    if (!$fh) return false;
    fputcsv($fh, $head);
    foreach ($all as $r) fputcsv($fh, $r);
    fclose($fh);
    return @rename($tmp, $outCsv);
}

// 引っ越し。うまくいけば ''、だめならわけ
function core_move(string $uid, string $old, string $new): string
{
    $hubsDir = account_hubs_dir($uid);
    if (!preg_match('/^[0-9A-F]{12}$/', $old) || !preg_match('/^[0-9A-F]{12}$/', $new)) return '機器の番号が正しくありません。';
    if ($old === $new) return '古い HPX-Core と新しい HPX-Core に同じものを選んでいます。';
    $od = $hubsDir . '/' . $old;
    $nd = $hubsDir . '/' . $new;
    if (!is_file($od . '/state.json')) return '古い HPX-Core が見つかりません。';
    if (!is_file($nd . '/state.json')) return '新しい HPX-Core からまだ届いていません。新しい HPX-Core をネットにつないで、一度サーバーへ送ってから選んでください。';

    // receive.php の書き込みと重ならないように、両方のフォルダのロックを取る
    $lo = @fopen($od . '/.lock', 'c');
    $ln = @fopen($nd . '/.lock', 'c');
    if (!$lo || !$ln || !flock($lo, LOCK_EX) || !flock($ln, LOCK_EX)) return 'ロックを取れませんでした。少したってからもう一度試してください。';
    $err = '';
    $stamp = date('Ymd-His');
    try {
        // 新しい Core の今のファイルを控える (うまくいかなかったときに戻すため)
        $bak = $nd . '/before_move_' . $stamp;
        if (!@mkdir($bak, 0700, true)) throw new RuntimeException('控えのフォルダを作れませんでした。');
        foreach (['ble.csv', 'state.json', 'weight_cal.json', 'th_cal.json'] as $f)
            if (is_file($nd . '/' . $f) && !@copy($nd . '/' . $f, $bak . '/' . $f)) throw new RuntimeException($f . ' を控えられませんでした。');

        // 1. 記録
        if (!core_move_merge_csv($od . '/ble.csv', $nd . '/ble.csv', $nd . '/ble.csv')) throw new RuntimeException('記録をまとめられませんでした。');

        // 2. センサごとの情報。新しい Core から届いた値 (名前・機種・最後の時刻など) を上に重ねるが、
        //    最後に保存した時刻は大きい方、HPX-Core で削除した印は新しい Core のものに合わせる
        $so = json_load($od . '/state.json');
        $sn = json_load($nd . '/state.json');
        $sens = is_array($so['sensors'] ?? null) ? $so['sensors'] : [];
        foreach ((is_array($sn['sensors'] ?? null) ? $sn['sensors'] : []) as $mac => $e) {
            if (!is_array($e)) continue;
            $o = is_array($sens[$mac] ?? null) ? $sens[$mac] : [];
            $m = array_merge($o, $e);
            $m['last_ts'] = max((int)($o['last_ts'] ?? 0), (int)($e['last_ts'] ?? 0));
            if (empty($e['removed'])) unset($m['removed']);
            $sens[$mac] = $m;
        }
        // 新しい Core に登録していない (古い Core にだけあった) センサは、削除した扱いにする (記録は残る)
        $reg = $sn['ble_sensors'] ?? null;
        if (is_array($reg)) {
            $now = array_flip(array_map(fn($x) => strtoupper((string)($x['mac'] ?? '')), array_filter($reg, 'is_array')));
            foreach ($sens as $mac => $e) if (is_array($e) && $mac !== '' && !isset($now[$mac]) && !isset($sn['sensors'][$mac])) $sens[$mac]['removed'] = $sens[$mac]['removed'] ?? date('Y-m-d H:i:s');
        }
        $sn['sensors'] = $sens;
        $sn['moved_from'] = array_values(array_unique(array_merge((array)($sn['moved_from'] ?? []), (array)($so['moved_from'] ?? []), [$old])));
        if (!json_save($nd . '/state.json', $sn)) throw new RuntimeException('state.json を保存できませんでした。');

        // 画面で入れた値 (風袋引き・差し引く重さ・温湿度の補正) は古い Core のものを使う。新しい Core にしかないセンサはそのまま
        foreach (['weight_cal.json', 'th_cal.json'] as $f) {
            $a = json_load($od . '/' . $f);
            if (!$a) continue;
            $b = json_load($nd . '/' . $f);
            $m = $b;
            foreach ($a as $mac => $e) {
                if (!is_array($e)) { $m[$mac] = $e; continue; }
                // 1kgあたりの値 (per_kg など、HPX-Core から届く較正) は新しい Core から届いたものがあればそれを使う
                $m[$mac] = is_array($b[$mac] ?? null) ? array_merge($e, array_intersect_key($b[$mac], array_flip(['per_kg', 'cal_raw', 'cal_g', 'wcal_ts']))) : $e;
            }
            if (!json_save($nd . '/' . $f, $m)) throw new RuntimeException($f . ' を保存できませんでした。');
        }
        // メール送信履歴は古い Core のものをつなげる
        if (is_file($od . '/mail.log')) @file_put_contents($nd . '/mail.log', (string)@file_get_contents($od . '/mail.log') . (string)@file_get_contents($nd . '/mail.log'));
        // 古い Core が「外した」になっていても、新しい Core は外さない
        @unlink($nd . '/.retired');
    } catch (RuntimeException $e) {
        $err = $e->getMessage();
        // 新しい Core のファイルを控えから戻す
        foreach (glob(($bak ?? '') . '/*') ?: [] as $f) @copy($f, $nd . '/' . basename($f));
    }
    if ($err === '') {
        // 4. 古い Core のフォルダを移す (hpx_hubs() などの一覧に出なくなる)
        $mvDir = dirname($hubsDir) . '/hubs_moved';
        if (!is_dir($mvDir)) @mkdir($mvDir, 0700, true);
        flock($lo, LOCK_UN); fclose($lo); $lo = null;
        if (!@rename($od, $mvDir . '/' . $old . '_' . $stamp)) $err = '古い HPX-Core のフォルダを移せませんでした (記録は新しい HPX-Core にまとめ終わっています。もう一度押すと続きをします)。';
    }
    if ($lo) { flock($lo, LOCK_UN); fclose($lo); }
    flock($ln, LOCK_UN); fclose($ln);
    if ($err !== '') return $err;

    // 3. 蜂場・巣箱の紐づけと、つながりの履歴
    foreach (['site.json', 'links.json'] as $f) {
        $p = account_site_dir($uid) . '/' . $f;
        if (is_file($p)) json_update($p, fn(array $v) => core_move_rewrite($v, $old, $new));
    }
    // 5. 古い Core から届いたものは保存しない印
    $oldName = (string)($so['name'] ?? '');
    json_update(core_moves_path($hubsDir), function (array $v) use ($old, $new, $oldName) {
        $m = is_array($v['moved'] ?? null) ? $v['moved'] : [];
        // 前に引っ越した Core が、この古い Core に引っ越していたなら、行き先を新しい Core に付け替える
        foreach ($m as $k => $e) if (($e['to'] ?? '') === $old) $m[$k]['to'] = $new;
        unset($m[$new]);
        $m[$old] = ['to' => $new, 'time' => date('Y-m-d H:i:s'), 'name' => $oldName];
        $v['moved'] = $m;
        return $v;
    });
    return '';
}

// 引っ越した古い Core を、また使えるようにする (印を消すだけ。次に届いたら新しい機器として出る)
function core_move_release(string $uid, string $old): bool
{
    return json_update(core_moves_path(account_hubs_dir($uid)), function (array $v) use ($old) {
        if (isset($v['moved'][$old])) unset($v['moved'][$old]);
        return $v;
    });
}
