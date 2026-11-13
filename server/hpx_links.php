<?php
// 機器と巣箱・蜂場のつながりの履歴 (AI の教師データ用、2026-11-13)。
//   site.json や cam.json に書いてあるのは「今」のつながりだけなので、センサやカメラを別の巣箱へ付け替えると、
//   前の記録がどの巣箱のものだったか分からなくなる。そこで、つながりが変わるたびに site/links.json に書き足す。
//   site/links.json: {"cur": {キー: 今のつながり先}, "log": [{t, time, key, to, to_name, first?}, ...] 古い順}
//     キー  hv:<巣箱id>          … 巣箱がどの蜂場にあるか       (to = 蜂場id)
//           s:<HPX-CoreのID>/<MAC> … 温湿度・重量センサの巣箱     (to = 巣箱id)
//           f:<HPX-CoreのID>/<MAC> … 電柵アラートセンサの蜂場     (to = 蜂場id)
//           core:<HPX-CoreのID>    … HPX-Core を置いた蜂場       (to = 蜂場id。2つ以上なら「,」でつなぐ)
//           cam:<カメラID>         … HPX-CAM の巣箱 (巣箱が無ければ蜂場) (to = 巣箱id か a:蜂場id)
//     to が '' なら、つながりが外れた (巣箱の削除など)。to_name は読みやすいように付ける名前 (蜂場 / 巣箱)。
//     first: 1 は、この履歴を始めたときに既にあったつながり (いつからかは分からない。少なくともこの時刻から)。
//   蜂場・巣箱を保存したとき (site_update_of の 'site')、カメラの設定を保存したとき、hpx_cron.php の毎回の実行で link_log_sync() を呼ぶ。
//   アカウントのフォルダの site/ にあるので、バックアップ・全データのダウンロードにそのまま入る。
declare(strict_types=1);
require_once __DIR__ . '/hpx_auth.php';
require_once __DIR__ . '/hpx_cam.php';

// 今のつながり: キー => [つながり先, 名前]
function link_state(string $owner): array
{
    $sj = json_load(account_site_dir($owner) . '/site.json');
    $aps = is_array($sj['apiaries'] ?? null) ? $sj['apiaries'] : [];
    $hvs = is_array($sj['hives'] ?? null) ? $sj['hives'] : [];
    $apName = fn(string $a) => (string)($aps[$a]['name'] ?? '');
    $hvName = fn(string $h) => isset($hvs[$h]) ? trim($apName((string)($hvs[$h]['apiary'] ?? '')) . ' / ' . (string)($hvs[$h]['name'] ?? '')) : '';
    $out = [];
    $cores = [];
    foreach ($aps as $aid => $a) {
        if (!is_array($a)) continue;
        $aid = (string)$aid;
        foreach ((array)($a['fences'] ?? []) as $v) $out['f:' . $v] = [$aid, $apName($aid)];
        foreach ((array)($a['hubs'] ?? []) as $v) $cores[(string)$v][] = $aid;
    }
    foreach ($cores as $hub => $ids) {
        sort($ids);
        $out['core:' . $hub] = [implode(',', $ids), implode(', ', array_map($apName, $ids))];
    }
    foreach ($hvs as $hid => $h) {
        if (!is_array($h)) continue;
        $hid = (string)$hid;
        $aid = (string)($h['apiary'] ?? '');
        $out['hv:' . $hid] = [$aid, $apName($aid)];
        foreach ((array)($h['sensors'] ?? []) as $v) $out['s:' . $v] = [$hid, $hvName($hid)];
    }
    foreach (glob(cams_dir($owner) . '/*/cam.json') ?: [] as $p) {
        $c = json_load($p);
        $id = basename(dirname($p));
        $h = (string)($c['hive'] ?? '');
        $a = (string)($c['apiary'] ?? '');
        if ($h !== '' && isset($hvs[$h])) $out['cam:' . $id] = [$h, $hvName($h)];
        elseif ($a !== '') $out['cam:' . $id] = ['a:' . $a, $apName($a)];
        else $out['cam:' . $id] = ['', ''];
    }
    return $out;
}

// 今のつながりと前に書いたつながりを見くらべて、変わったところを履歴に書き足す
function link_log_sync(string $owner): void
{
    if (!is_uid($owner) || is_demo(user_get($owner))) return;   // デモ用アカウントは見本なので残さない
    if (!is_file(account_site_dir($owner) . '/site.json') && !is_dir(cams_dir($owner))) return;
    $now = time();
    $state = link_state($owner);
    // 変わっていなければ書かない (hpx_cron.php から15分ごとに呼ぶので)
    $old = json_load(account_site_dir($owner) . '/links.json');
    if (isset($old['cur']) && is_array($old['cur'])) {
        $want = array_filter(array_map(fn($x) => $x[0], $state), fn($t) => $t !== '');
        $have = array_filter($old['cur'], fn($t) => $t !== '');
        ksort($want); ksort($have);
        if ($want === $have) return;
    }
    json_update(account_site_dir($owner) . '/links.json', function (array $v) use ($state, $now) {
        $first = !isset($v['cur']);
        $cur = is_array($v['cur'] ?? null) ? $v['cur'] : [];
        $log = is_array($v['log'] ?? null) ? $v['log'] : [];
        $add = function (string $key, string $to, string $name) use (&$log, $now, $first) {
            $log[] = ['t' => $now, 'time' => date('Y-m-d H:i:s', $now), 'key' => $key, 'to' => $to, 'to_name' => $name] + ($first ? ['first' => 1] : []);
        };
        foreach ($state as $key => [$to, $name]) {
            if (($cur[$key] ?? null) === $to) continue;
            if ($to === '' && !isset($cur[$key])) continue;
            $add((string)$key, $to, $name);
            $cur[$key] = $to;
        }
        foreach ($cur as $key => $to) {
            if (!isset($state[$key]) && $to !== '') {   // 外れた (巣箱やセンサの紐づけを消した)
                $add((string)$key, '', '');
                $cur[$key] = '';
            }
        }
        return ['cur' => $cur, 'log' => $log];
    });
}
