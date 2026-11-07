<?php
// 巣箱ページ: 上の蜂場のボタンで蜂場を、その下の四角い巣箱のボタンで巣箱を切り替えて、1つの巣箱を表示する。
//   巣箱ごとに温度・湿度・重量のグラフを出す(巣箱を切り替えてもグラフの位置は変わらない)。
//   HPX-Core と電柵アラートの状態は蜂場ページの「蜂場詳細」に出す。
//   温湿度センサと重量センサは巣箱に紐づける(1台のセンサは1つの巣箱にだけ)。
//   チームに共有された蜂場の巣箱も出す。巣箱の追加・変更・削除は、蜂場の持ち主とチーム管理者だけ。
declare(strict_types=1);
require __DIR__ . '/hpx_site.php';

$site = site_load();
$sensors = hpx_sensors();
$hubs = hpx_hubs();

$aid = (string)($_GET['a'] ?? $_POST['a'] ?? '');
// QRコードから開いたとき (hive.php?h=巣箱id だけ) は、その巣箱の蜂場を選ぶ
if ($aid === '' && isset($site['hives'][(string)($_GET['h'] ?? '')])) $aid = (string)$site['hives'][(string)$_GET['h']]['apiary'];
if (!isset($site['apiaries'][$aid])) $aid = (string)(array_key_first($site['apiaries']) ?? '');
$per = (int)($_GET['p'] ?? S_PERIOD_DEFAULT);
if (!array_key_exists($per, S_PERIODS)) $per = S_PERIOD_DEFAULT;
// 表示する巣箱(h)。'new' は巣箱の追加。指定が無いか違う蜂場のものなら、その蜂場の最初の巣箱
$hives = $aid !== '' ? hives_of($site, $aid) : [];
$hsel = (string)($_GET['h'] ?? '');
if ($hsel !== 'new' && !isset($hives[$hsel])) $hsel = (string)(array_key_first($hives) ?? 'new');
$selfUrl = fn(array $ch = [], string $anchor = '') => 'hive.php?' . http_build_query(array_merge(['a' => $aid, 'h' => $hsel, 'p' => $per], $ch)) . ($anchor !== '' ? '#' . $anchor : '');

if (is_post()) {
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'hive_save') {
        $id = (string)($_POST['id'] ?? '');
        $name = str_in('name', 40);
        $to = (string)($_POST['apiary'] ?? '');
        if ($name === '' || !isset($site['apiaries'][$to])) redirect($selfUrl(), '巣箱の名前と蜂場を入れてください。');
        if ($id !== '' && !isset($site['hives'][$id])) redirect($selfUrl());
        // 巣箱を変えられるのは蜂場の持ち主とチーム管理者。ほかの人の蜂場へは移せない(巣箱は蜂場の持ち主のフォルダにある)
        $owner = apiary_owner($site, $to);
        if (!apiary_can_edit($site, $to) || ($id !== '' && (!apiary_can_edit($site, (string)$site['hives'][$id]['apiary']) || $site['hives'][$id]['_o'] !== $owner))) {
            redirect($selfUrl(), '巣箱を変えられるのは、蜂場の持ち主とチーム管理者だけです (ほかの人の蜂場へは移せません)。');
        }
        site_use($owner);
        $sel = array_values(array_filter((array)($_POST['sensors'] ?? []), fn($v) => in_array($sensors[(string)$v]['kind'] ?? '', ['th', 'w'], true) && $sensors[(string)$v]['o'] === $owner));
        $state = (string)($_POST['state'] ?? '');
        if (!in_array($state, S_HIVE_STATES, true) && $state !== (string)($site['hives'][$id]['state'] ?? '')) $state = '';   // 外した状態も、前から付いていればそのまま
        $newId = $id !== '' ? $id : new_id();
        site_update('site', function (array $s) use ($newId, $name, $to, $sel, $state) {
            $s['hives'] = is_array($s['hives'] ?? null) ? $s['hives'] : [];
            foreach ($s['hives'] as $hid => &$x) {
                if ($hid !== $newId) $x['sensors'] = array_values(array_diff((array)($x['sensors'] ?? []), $sel));
            }
            unset($x);
            $old = $s['hives'][$newId] ?? ['created' => time()];
            $s['hives'][$newId] = array_merge($old, [
                'apiary' => $to, 'name' => $name, 'state' => $state, 'started' => date_in('started'),
                'queen' => str_in('queen', 200), 'box' => str_in('box', 200), 'note' => str_in('note'),
                'order' => (int)($_POST['order'] ?? 0), 'sensors' => $sel, 'updated' => time(),
            ]);
            return $s;
        });
        redirect('hive.php?' . http_build_query(['a' => $to, 'h' => $newId, 'p' => $per]), '巣箱「' . $name . '」を保存しました。');
    }
    // 警報の設定(温度の上限・下限、基準日と重量の増減)。空の項目は見ない
    if ($act === 'alert_save') {
        $id = (string)($_POST['id'] ?? '');
        if (!isset($site['hives'][$id])) redirect($selfUrl());
        if (!apiary_can_edit($site, (string)$site['hives'][$id]['apiary'])) redirect($selfUrl(), '警報を設定できるのは、蜂場の持ち主とチーム管理者だけです。');
        $num = function (string $k, float $lo, float $hi): ?float {
            $v = trim(str_replace(',', '.', (string)($_POST[$k] ?? '')));
            return is_numeric($v) && (float)$v >= $lo && (float)$v <= $hi ? round((float)$v, 2) : null;
        };
        $al = ['t_hi' => $num('t_hi', -30, 80), 't_lo' => $num('t_lo', -30, 80), 'base' => date_in('base'),
            'w_up' => $num('w_up', 0.1, 200), 'w_dn' => $num('w_dn', 0.1, 200)];
        if ($al['t_hi'] !== null && $al['t_lo'] !== null && $al['t_lo'] >= $al['t_hi']) redirect($selfUrl([], 'alert'), '下限温度は上限温度より低くしてください。');
        site_use($site['hives'][$id]['_o']);
        site_update('site', function (array $s) use ($id, $al) {
            if (isset($s['hives'][$id])) $s['hives'][$id]['alert'] = array_filter($al, fn($v) => $v !== null && $v !== '');
            return $s;
        });
        redirect($selfUrl([], 'h-' . $id), '警報の設定を保存しました。');
    }
    if ($act === 'hive_delete') {
        $id = (string)($_POST['id'] ?? '');
        if (!isset($site['hives'][$id])) redirect($selfUrl());
        if (!apiary_can_edit($site, (string)$site['hives'][$id]['apiary'])) redirect($selfUrl(), '巣箱を削除できるのは、蜂場の持ち主とチーム管理者だけです。');
        site_use($site['hives'][$id]['_o']);
        $name = (string)$site['hives'][$id]['name'];
        site_update('site', function (array $s) use ($id) { unset($s['hives'][$id]); return $s; });
        redirect($selfUrl(['h' => '']), '巣箱「' . $name . '」を削除しました (メモと作業記録は残っています)。');
    }
    redirect($selfUrl());
}

function hive_form(array $hv, string $id, string $aid, array $site, array $sensors): string
{
    // 選べる蜂場とセンサは、その蜂場の持ち主のもので、自分が変えられる蜂場だけ
    $own = (string)$site['apiaries'][$aid]['_o'];
    $site = site_of_owner($site, $own);
    $site['apiaries'] = array_filter($site['apiaries'], fn($a) => in_array($a['_r'], ['owner', 'admin'], true));
    $sensors = array_filter($sensors, fn($sn) => $sn['o'] === $own);
    $owner = [];
    foreach ($site['hives'] as $hid => $x) foreach ((array)($x['sensors'] ?? []) as $v) $owner[$v] = $hid;
    $o = '<form method="post" class="form">' . csrf_field() . '<input type="hidden" name="act" value="hive_save"><input type="hidden" name="id" value="' . h($id) . '">'
        . '<div class="grid2"><p><label>巣箱の名前 (必須)</label><input type="text" name="name" maxlength="40" required value="' . h((string)($hv['name'] ?? '')) . '"></p>'
        . '<p><label>蜂場</label><select name="apiary">';
    foreach ($site['apiaries'] as $x => $a) $o .= '<option value="' . h($x) . '"' . ((($hv['apiary'] ?? $aid) === $x) ? ' selected' : '') . '>' . h((string)$a['name']) . '</option>';
    $o .= '</select></p><p><label>状態</label><select name="state"><option value="">-</option>';
    $sts = S_HIVE_STATES;
    if (($hv['state'] ?? '') !== '' && !in_array($hv['state'], $sts, true)) $sts[] = (string)$hv['state'];
    foreach ($sts as $st) $o .= '<option' . (($hv['state'] ?? '') === $st ? ' selected' : '') . '>' . h($st) . '</option>';
    $o .= '</select></p><p><label>群を入れた日</label><input type="date" name="started" value="' . h((string)($hv['started'] ?? '')) . '"></p></div>'
        . '<div class="grid2"><p><label>女王 (生まれ年・マーク・由来など)</label><input type="text" name="queen" value="' . h((string)($hv['queen'] ?? '')) . '"></p>'
        . '<p><label>巣箱 (種類・段数・巣枠の数など)</label><input type="text" name="box" value="' . h((string)($hv['box'] ?? '')) . '"></p></div>'
        . '<p><label>備考</label><textarea name="note">' . h((string)($hv['note'] ?? '')) . '</textarea></p>'
        . '<p><label>並び順 (小さいほど上)</label><input type="number" name="order" value="' . (int)($hv['order'] ?? 0) . '"></p>'
        . '<p><label>この巣箱のセンサ (温湿度・重量)</label>';
    $list = array_filter($sensors, fn($s) => $s['kind'] === 'th' || $s['kind'] === 'w');
    if (!$list) $o .= '<span class="muted">センサはまだ登録されていません (HPX-Core の管理画面で登録します)。</span>';
    foreach ($list as $k => $s) {
        $o .= '<span style="display:block"><input type="checkbox" name="sensors[]" value="' . h($k) . '"' . (in_array($k, (array)($hv['sensors'] ?? []), true) ? ' checked' : '') . '> '
            . h($s['name']) . ' <span class="muted">[' . h(kind_text($s['kind'])) . '] ' . h($s['hubName']) . ($s['retired'] ? ' (外した)' : '') . '</span>'
            . (isset($owner[$k]) && $owner[$k] !== $id ? ' <span class="muted">(今は「' . h(target_label($site, (string)($site['hives'][$owner[$k]]['apiary'] ?? ''), $owner[$k])) . '」。選ぶとこちらに移ります)</span>' : '')
            . '</span>';
    }
    return $o . '</p><p><button type="submit">保存</button></p></form>';
}

// QR コードのマークと、押すと出るメニュー (この巣箱の PNG、この蜂場・すべての蜂場の巣箱の印刷用 PDF)。
// QR コードの中身は hive.php?h=巣箱id の URL。ログインしていなければ、ログインのあとでその巣箱を開く
function qr_menu(array $site, string $aid, string $hid): string
{
    $item = function (string $id) use ($site): array {
        $hv = $site['hives'][$id];
        return ['name' => (string)$hv['name'], 'apiary' => (string)($site['apiaries'][$hv['apiary']]['name'] ?? ''), 'url' => site_url('hive.php?h=' . $id)];
    };
    $here = []; $all = [];
    foreach ($site['apiaries'] as $x => $a) {
        foreach (hives_of($site, (string)$x) as $id => $hv) {
            $all[] = $item((string)$id);
            if ($x === $aid) $here[] = $item((string)$id);
        }
    }
    $me = $item($hid);
    $j = fn($v) => h(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $multi = count($site['apiaries']) > 1;
    return '<details class="qrm"><summary title="QRコード">'
        . '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="currentColor" d="M3 3h8v8H3zm2 2v4h4V5zm8-2h8v8h-8zm2 2v4h4V5zM3 13h8v8H3zm2 2v4h4v-4zm1-9h2v2H6zm10 0h2v2h-2zM6 16h2v2H6zm7-3h2v2h-2zm2 2h2v2h-2zm-2 2h2v2h-2zm4 0h2v2h-2zm2-2h2v2h-2zm0 4h2v2h-2zm-4 0h2v2h-2zm2-6h2v2h-2z"/></svg>'
        . '<span>QR</span></summary><div class="qrp">'
        . '<p class="qrt">巣箱に貼る QR コード</p>'
        . '<p class="muted" style="margin:.2em 0 .5em">スマホのカメラで読むと、この巣箱のページが開きます (ログインが必要です)。ラベルは 50mm 角です。</p>'
        . '<img class="qrimg" alt="この巣箱のQRコード" data-h="' . $j($me) . '">'
        . '<p><button type="button" data-qr="png" data-h="' . $j($me) . '">この巣箱の PNG</button></p>'
        . '<p style="margin:.6em 0 .2em"><b>印刷用 PDF</b> <span class="muted">(A4 に 15 枚)</span></p>'
        . '<p><button type="button" data-qr="pdf" data-f="' . h('QR_' . preg_replace('/[\\\/:*?"<>|\s]+/u', '_', (string)$site['apiaries'][$aid]['name']) . '.pdf') . '" data-h="' . $j($here) . '">この蜂場の巣箱 (' . count($here) . ')</button></p>'
        . ($multi ? '<p><button type="button" data-qr="pdf" data-f="QR_all.pdf" data-h="' . $j($all) . '">すべての蜂場の巣箱 (' . count($all) . ')</button></p>' : '')
        . '<p class="muted" style="font-size:.8em;margin:.6em 0 0">屋外に貼るときは、耐水・屋外用のラベル用紙に印刷するか、上から透明の防水テープやラミネートで覆うと長持ちします。</p>'
        . '</div></details>';
}

page_head('巣箱 - ' . S_TITLE, 'hive.php');
echo '<style>.qrrow{display:flex;align-items:flex-start;gap:8px}.qrrow .per{flex:1}'
    . '.qrm{position:relative;margin:0}.qrm>summary{list-style:none;display:flex;align-items:center;gap:3px;padding:2px 8px;border:1px solid #bbc;border-radius:4px;background:#fff;color:#234;font-size:.85em}'
    . '.qrm>summary::-webkit-details-marker{display:none}.qrm[open]>summary{background:#234;color:#fff}.qrm>summary svg{background:none;border:0;width:22px;height:22px}'
    . '.qrp{position:absolute;right:0;top:calc(100% + 4px);z-index:20;width:260px;background:#fff;border:1px solid #bbc;border-radius:6px;padding:10px 12px;box-shadow:0 3px 12px rgba(0,0,0,.18)}'
    . '.qrp p{margin:.3em 0}.qrp .qrt{font-weight:bold;margin-top:0}.qrp button{width:100%}.qrimg{display:block;width:150px;height:150px;margin:0 auto 4px;border:1px solid #ddd}</style>';
echo flash_html();

if (!$site['apiaries']) {
    echo '<p>まだ蜂場が登録されていません。<a href="apiary.php#new">蜂場ページ</a>で蜂場を登録してから、巣箱を登録してください。</p></body></html>';
    exit;
}

// ---- 蜂場のボタン ----
echo '<div class="tabs">';
foreach ($site['apiaries'] as $x => $a) {
    echo '<a href="' . h('hive.php?' . http_build_query(['a' => $x, 'p' => $per])) . '"' . ($x === $aid ? ' class="on"' : '') . '>'
        . h((string)$a['name']) . ' <span class="muted">' . count(hives_of($site, $x)) . '</span></a>';
}
echo '</div>';

$ap = $site['apiaries'][$aid];

// ---- 巣箱のボタン(四角)。最後の「＋」で巣箱を追加 ----
echo '<div class="hbtns">';
foreach ($hives as $x => $hv) {
    echo '<a href="' . h($selfUrl(['h' => $x])) . '"' . ($x === $hsel ? ' class="on"' : '') . '>' . h((string)$hv['name']) . '</a>';
}
$canEdit = apiary_can_edit($site, $aid);
if (!$canEdit && $hsel === 'new') $hsel = '';
if ($canEdit) echo '<a class="add' . ($hsel === 'new' ? ' on' : '') . '" href="' . h($selfUrl(['h' => 'new'])) . '" title="巣箱を追加">＋</a>';
echo '</div>';
if (!$hives && !$canEdit) echo '<p class="muted">この蜂場には巣箱が登録されていません。</p>';

// グラフの色: 重量は青、温度は赤、湿度は緑。1つの巣箱にセンサが2台以上あるときは、2台目から破線にする
const H_C_W = '#1565c0';
const H_C_T = '#D55E00';
const H_C_H = '#56B4E9';
const H_DASH = ['', '7 4', '2 3', '10 3 2 3'];
$memos = memos_load();
usort($memos, fn($x, $y) => [$y['date'] ?? '', $y['created'] ?? 0] <=> [$x['date'] ?? '', $x['created'] ?? 0]);
$since = $per === 0 ? 0 : $now - $per * 86400;

foreach (array_intersect_key($hives, [$hsel => 1]) as $hid => $hv) {
    // 巣箱名は上の巣箱のボタンで分かるので、ボックスには出さない
    echo '<div class="card hive" id="h-' . h($hid) . '">';
    // グラフの期間(巣箱名の下)。右端に QR コードのマーク
    echo '<div class="qrrow"><div class="per sq">';
    foreach (S_PERIODS as $v => $lb) echo '<a href="' . h($selfUrl(['p' => $v])) . '"' . ($per === $v ? ' class="on"' : '') . '>' . h($lb) . '</a>';
    echo '</div>' . qr_menu($site, $aid, $hid) . '</div>';
    // 群の詳細(グラフ・タスク・メモの後に出す)
    ob_start();
    $info = [];
    if (($hv['state'] ?? '') !== '') $info['状態'] = (string)$hv['state'];
    if (($hv['started'] ?? '') !== '') $info['群を入れた日'] = date('Y-m-d', strtotime((string)$hv['started']));
    if (($hv['queen'] ?? '') !== '') $info['女王'] = (string)$hv['queen'];
    if (($hv['box'] ?? '') !== '') $info['巣箱'] = (string)$hv['box'];
    echo '<p style="margin:.8em 0 .2em"><b>群の詳細</b></p>';
    if (!$info && ($hv['note'] ?? '') === '') echo '<p class="muted">まだ入力されていません。' . ($canEdit ? '下の「巣箱の情報を変更」で入れられます。' : '') . '</p>';
    if ($info) {
        echo '<dl class="info">';
        foreach ($info as $k => $v) echo '<dt>' . h($k) . '</dt><dd>' . h($v) . '</dd>';
        echo '</dl>';
    }
    if (($hv['note'] ?? '') !== '') echo '<p>' . nl((string)$hv['note']) . '</p>';

    // センサの今の値とグラフ
    $tS = []; $hS = []; $wS = []; $rows = [];
    $first = 0; $nt = 0; $nw = 0;
    $wSer = [];   // 重量センサごとの記録(kg)。あとで巣箱の重さ(1台なら2倍、2台以上なら合計)にまとめる
    foreach ((array)($hv['sensors'] ?? []) as $k) {
        if (!isset($sensors[$k])) continue;
        $sn = $sensors[$k];
        $d = ble_data($sn['hub'], $since);
        if ($d['first'] && (!$first || $d['first'] < $first)) $first = $d['first'];
        $le = (int)($sn['s']['last_epoch'] ?? 0);
        $lastTxt = h($le ? date('m-d H:i', $le) . ' (' . ago_text($le, $now) . ')' : '-');
        if ($sn['kind'] === 'th') {
            $r = $d['th'][$sn['mac']] ?? [];
            $last = $r ? $r[count($r) - 1] : null;
            $dash = H_DASH[$nt++ % count(H_DASH)];
            $rows[] = '<tr><td><span style="color:' . H_C_T . '">■</span> ' . h($sn['name']) . ($dash !== '' ? ' <span class="muted">(破線)</span>' : '') . '</td>'
                . '<td class="n">' . h($last ? number_format($last[1], 1) . ' ℃' : '-') . '</td>'
                . '<td class="n">' . h($last && $last[2] !== null ? number_format($last[2], 1) . ' %' : '-') . '</td>'
                . '<td class="n">' . ($last && $last[3] !== null ? h((int)$last[3] . ' %') . ($last[3] <= 20 ? ' <span class="warn">交換</span>' : '') : '-') . '</td>'
                . '<td>' . $lastTxt . ($le > 0 && $now - $le >= S_TH_MISSING_S ? ' <span class="ng">受信できていません</span>' : '') . '</td></tr>';
            $tS[] = [$sn['name'], H_C_T, array_map(fn($p) => [$p[0], $p[1]], $r), $dash];
            $hS[] = [$sn['name'], H_C_H, array_values(array_map(fn($p) => [$p[0], $p[2]], array_filter($r, fn($p) => $p[2] !== null))), $dash];
        } else {
            $c = cal_load($sn['hub'])[$sn['mac']] ?? null;
            $r = $d['w'][$sn['mac']] ?? [];
            $last = $r ? $r[count($r) - 1] : null;
            $kg = $last ? to_kg($last[1], $c) : null;
            $diff = null;   // 24時間前との差(22時間以上前の記録があるときだけ)
            if ($last && $kg !== null) {
                foreach ($r as $p) {
                    if ($p[0] < $last[0] - 86400) continue;
                    if ($p[0] <= $last[0] - 79200) $diff = $kg - (float)to_kg($p[1], $c);
                    break;
                }
            }
            $dash = H_DASH[$nw++ % count(H_DASH)];
            $rows[] = '<tr><td><span style="color:' . H_C_W . '">■</span> ' . h($sn['name']) . ($dash !== '' ? ' <span class="muted">(破線)</span>' : '') . '</td>'
                . '<td class="n" colspan="2">' . ($kg !== null ? '<b>' . h(number_format($kg, 2) . ' kg') . '</b>'
                    . ($diff !== null ? ' <span class="muted">(24時間 ' . h(($diff >= 0 ? '+' : '') . number_format($diff, 2)) . ')</span>' : '')
                    : ($last ? '<span class="muted">未較正</span>' : '-')) . '</td>'
                . '<td class="n">' . (isset($sn['s']['w_fail']) && (int)($sn['s']['w_fail_epoch'] ?? 0) >= $le ? '<span class="ng">' . ((int)$sn['s']['w_fail'] === 1 ? '電池切れ' : '測定エラー') . '</span>' : '-') . '</td>'
                . '<td>' . $lastTxt . ($le > 0 && $now - $le >= S_W_MISSING_S ? ' <span class="ng">受信できていません</span>' : '') . '</td></tr>';
            if ($c && isset($c['per_kg'])) {
                $pts = [];
                foreach ($r as $p) { $v = to_kg($p[1], $c); if ($v !== null) $pts[] = [$p[0], $v]; }
                $wSer[] = $pts;
            }
        }
    }
    $infoHtml = (string)ob_get_clean();
    // 巣箱の重さ: 重量センサ1台なら2倍、2台以上なら合計。較正していないセンサがあるときは出さない
    $wHow = '';
    if ($nw > 0 && count($wSer) === $nw) {
        $wS[] = ['巣箱重量', H_C_W, hive_kg_series($wSer), ''];
        $wHow = ' <span class="muted" style="font-size:.7em">' . h(hive_weight_how($nw)) . '</span>';
    } elseif ($nw > 0) {
        $wHow = ' <span class="muted" style="font-size:.7em">較正していない重量センサがあるため、巣箱の重さを出せません</span>';
    }
    // グラフは上から 重量、温度と湿度(1つのグラフ。温度は左の目盛り、湿度は右の目盛り)。記録が無くても枠は出す
    $from = $per === 0 ? ($first > 0 ? min($first, $now - 3600) : $now - 86400) : $since;
    // パソコン用とスマホ用(幅を狭くして、目盛りの数字が小さくなりすぎないようにしたもの)を作り、画面の幅で出し分ける
    // マウスを乗せる(スマホはタッチ)と日時と値を出す
    $dual = fn(array $l, array $r, float $ls, float $rs, string $lc, string $rc, array $tip) =>
        '<div class="gw">' . chart_dual($l, $r, $from, $now, $ls, $rs, 600, 160, 8, $lc, $rc, $tip) . '</div>'
        . '<div class="gn">' . chart_dual($l, $r, $from, $now, $ls, $rs, 340, 120, 10, $lc, $rc, $tip) . '</div>';
    // 重量の見出しの右に、基準日と基準日の同時刻からの増減(巣箱の重さで)。温度の見出しの右に、警報の上限・下限
    $conf = hive_alert_conf($hv);
    $wb = '';
    if ($conf['base'] !== '') {
        $parts = [];
        $wbList = hive_weight_base((string)$hv['_o'], $hv);
        foreach ($wbList as [, , , , $diff]) {
            $parts[] = ($diff === null ? '<span class="muted">基準日の記録なし</span>'
                : '<b>' . h(($diff >= 0 ? '+' : '') . number_format($diff, 2)) . ' kg</b>');
        }
        $wb = '<span class="gx">基準日: ' . h(date('n月j日', strtotime($conf['base']))) . '　基準日からの増減: ' . ($parts ? implode('　', $parts) : '<span class="muted">-</span>') . '</span>';
    }
    $tl = [];
    if ($conf['t_hi'] !== null) $tl[] = '上限 ' . h((string)$conf['t_hi']) . '℃';
    if ($conf['t_lo'] !== null) $tl[] = '下限 ' . h((string)$conf['t_lo']) . '℃';
    echo '<p class="gt"><span style="color:' . H_C_W . '">重量 (kg)</span>' . $wHow . $wb . '</p>' . $dual($wS, [], 0.5, 0, H_C_W, '#666', ['l' => ['重量', 'kg', 2]])
        . '<p class="gt"><span style="color:' . H_C_T . '">温度 (℃)</span>・<span style="color:' . H_C_H . '">湿度 (%)</span>'
        . ' <span class="muted" style="font-size:.7em">温度は左、湿度は右の目盛り</span>' . '</p>'
        . $dual($tS, $hS, 2, 5, H_C_T, H_C_H, ['l' => ['温度', '℃', 1], 'r' => ['湿度', '%', 1]]);
    // センサが2台以上あるときだけ、線の種類とセンサ名の対応を出す
    $legend = [];
    foreach ([$wS, $tS] as $ser) {
        if (count($ser) < 2) continue;
        foreach ($ser as $x) $legend[] = '<span style="color:' . $x[1] . '">' . (($x[3] ?? '') === '' ? '━' : '┅') . '</span> ' . h($x[0]);
    }
    if ($legend) echo '<p class="muted" style="margin:.2em 0">' . implode('　', $legend) . '</p>';
    // センサの表はセンサページで見られるので、ここには出さない
    if (!$rows) echo '<p class="muted">センサは紐づいていません。' . ($canEdit ? '下の「巣箱の情報を変更」で選べます。' : '') . '</p>';

    // 警報の設定(メールで知らせる)
    $sum = [];
    if ($conf['base'] !== '' && ($conf['w_up'] !== null || $conf['w_dn'] !== null)) {
        $sum[] = '重量' . ($conf['w_up'] !== null ? ' +' . h((string)$conf['w_up']) . 'kg' : '') . ($conf['w_dn'] !== null ? ' -' . h((string)$conf['w_dn']) . 'kg' : '');
    }
    if ($tl) $sum[] = '温度 ' . implode('・', $tl);
    echo '<details class="alertset" id="alert"><summary>警報 (変更): ' . ($sum ? '現在の設定　' . implode('　', $sum) : '<span class="muted">設定なし</span>') . '</summary>';
    if ($canEdit) {
        $v = fn($k) => h($conf[$k] === null ? '' : (string)$conf[$k]);
        echo '<form method="post" class="form">' . csrf_field() . '<input type="hidden" name="act" value="alert_save"><input type="hidden" name="id" value="' . h($hid) . '">'
            . '<p class="muted" style="font-size:.85em">空欄の項目は通知しません。15分ごとの見回りで超えたときにメールを送ります。</p>'
            // 1行目が重量、2行目が温度(グラフの並び)。上限・下限の欄は2行でそろえる
            . '<div class="alg"><b>重量</b>'
            . '<label>上限<i>+</i><input type="number" name="w_up" step="0.1" min="0.1" value="' . $v('w_up') . '"> kg</label>'
            . '<label>下限<i>-</i><input type="number" name="w_dn" step="0.1" min="0.1" value="' . $v('w_dn') . '"> kg</label>'
            . '<label class="base">基準日 (採蜜した日など) <input type="date" name="base" value="' . h($conf['base']) . '"></label>'
            . '<div class="muted alnote">重量は、基準日の同時刻の重量からの増減で見ます。</div>'
            . '<b>温度</b>'
            . '<label>上限<i></i><input type="number" name="t_hi" step="0.1" value="' . $v('t_hi') . '"> ℃</label>'
            . '<label>下限<i></i><input type="number" name="t_lo" step="0.1" value="' . $v('t_lo') . '"> ℃</label><span></span></div>'
            . '<p><button type="submit">警報を保存</button></p></form>';
    } else {
        echo '<p class="muted">警報を変えられるのは、蜂場の持ち主とチーム管理者だけです。</p>';
    }
    echo '</details>';

    echo '<p style="margin:.8em 0 .2em"><b>タスク</b></p>' . todo_brief($site, $aid, $hid, false, $selfUrl([], 'h-' . $hid), 3);
    $mine = array_slice(array_values(array_filter($memos, fn($m) => ($m['hive'] ?? '') === $hid)), 0, 2);
    echo '<p style="margin:.8em 0 .2em"><b>最近のメモ</b></p>';
    if (!$mine) echo '<p class="muted">メモはありません。</p>';
    foreach ($mine as $m) {
        echo '<p style="margin:.3em 0"><span class="muted">' . h(date('n/j', strtotime((string)$m['date']))) . ' ' . h((string)($m['type'] ?? '')) . '</span> '
            . (!empty($m['photos']) ? '📷 ' : '') . h(mb_strimwidth(trim(implode('・', memo_chips($m)) . ' ' . (string)($m['text'] ?? '')), 0, 100, '…')) . '</p>';
    }
    echo '<p class="muted"><a href="memo.php?' . h(http_build_query(['a' => $aid, 'h' => $hid])) . '">メモをすべて見る</a>　'
        . '<a href="memo.php?' . h(http_build_query(['new' => $aid . '|' . $hid])) . '#memo-form">＋メモ (内検)</a>　'
        . '<a href="work.php?' . h(http_build_query(['new_log' => $aid . '|' . $hid])) . '#log-form">＋作業記録</a></p>';
    echo $infoHtml;

    if ($canEdit) echo '<details><summary>巣箱の情報を変更</summary>' . hive_form($hv, $hid, $aid, $site, $sensors)
        . '<form method="post" onsubmit="return confirm(\'この巣箱を削除しますか? (メモと作業記録は残ります)\');">' . csrf_field()
        . '<input type="hidden" name="act" value="hive_delete"><input type="hidden" name="id" value="' . h($hid) . '">'
        . '<button type="submit">この巣箱を削除</button></form></details>';
    echo '</div>';
}

if ($hsel === 'new') {
    echo '<div class="card hive" id="new"><h3>「' . h((string)$ap['name']) . '」に巣箱を追加</h3>' . hive_form([], '', $aid, $site, $sensors) . '</div>';
}
echo chart_tip_js();
// QR コード (ラベルの絵はブラウザで作る)
echo '<script src="js/qrcode.js"></script><script src="js/hpx_qr.js"></script><script>'
    . 'document.querySelectorAll("details.qrm").forEach(function(d){d.addEventListener("toggle",function(){var i=d.querySelector(".qrimg");if(d.open&&!i.src)hpxQr.preview(i,JSON.parse(i.dataset.h));});});'
    . 'document.addEventListener("click",function(e){if(!e.isTrusted)return;document.querySelectorAll("details.qrm[open]").forEach(function(d){if(!d.contains(e.target))d.open=false;});'
    . 'var b=e.target.closest("[data-qr]");if(!b)return;var h=JSON.parse(b.dataset.h),t=b.textContent;b.disabled=true;b.textContent="作成中…";'
    . '(b.dataset.qr==="png"?hpxQr.png(h):hpxQr.pdf(h,b.dataset.f)).catch(function(x){alert("作れませんでした: "+x);}).then(function(){b.disabled=false;b.textContent=t;});});'
    . '</script>';
echo '</body></html>';
