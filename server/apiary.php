<?php
// 蜂場ページ: 蜂場の登録・変更と、蜂場ごとの概要(巣箱の数、HPX-Core と電柵の状態、タスク、最近のメモ)。
//   HPX-Core(ハブ)と電柵アラートセンサは蜂場に紐づける。1台のハブ・センサは1つの蜂場にだけ紐づく。
//   チームに共有された蜂場も並べる。蜂場の情報を変えられるのは持ち主とチーム管理者、削除は持ち主だけ。
declare(strict_types=1);
require __DIR__ . '/hpx_site.php';

$site = site_load();
$sensors = hpx_sensors();
$hubs = hpx_hubs();

// 蜂場の写真: 持ち主の site/photos/<蜂場id>.jpg などに置き、その蜂場を見られる人にだけこのページから返す
const A_PHOTO_TYPES = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
const A_PHOTO_MAX = 8 * 1024 * 1024;
function photo_path(string $aid, array $a): string
{
    $ext = (string)($a['photo'] ?? '');
    return in_array($ext, A_PHOTO_TYPES, true) ? site_dir_of((string)($a['_o'] ?? $GLOBALS['UID'])) . '/photos/' . $aid . '.' . $ext : '';
}
// 蜂場ページに出す小さな写真(長い辺 640px の JPEG)。初めて見られたときに作って photos/<蜂場id>_t.jpg に置く
// 元の写真より古い・作れない(GD が無いなど)ときは作り直す・元の写真を使う。作れたらそのパスを返す
const A_THUMB_PX = 640;
function photo_thumb(string $aid, string $src): string
{
    $dst = dirname($src) . '/' . $aid . '_t.jpg';
    if (is_file($dst) && filemtime($dst) >= filemtime($src)) return $dst;
    return img_resize_jpeg($src, $dst, A_THUMB_PX) ? $dst : '';
}
// ====================================================================
// 天気予報(Open-Meteo の無料 API。キー不要。気象庁などのモデルの予報を緯度・経度で返す)
//   「緯度・経度」(map_q) が緯度,経度のときだけ出す。3時間はデータフォルダの site/weather/ に取っておく
// ====================================================================
const A_WX_CACHE_S = 3 * 3600;

function apiary_latlng(array $a): ?array
{
    if (!preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', (string)($a['map_q'] ?? ''), $m)) return null;
    $lat = (float)$m[1]; $lng = (float)$m[2];
    return abs($lat) <= 90 && abs($lng) <= 180 ? [$lat, $lng] : null;
}

// 今日から3日分: [[日付, 天気コード, 最高, 最低, 降水確率], ...]。取れなければ null
function weather_get(string $aid, float $lat, float $lng, string $siteDir): ?array
{
    $dir = $siteDir . '/weather';
    $path = $dir . '/' . $aid . '.json';
    $c = json_decode((string)@file_get_contents($path), true);
    $same = is_array($c) && abs((float)($c['lat'] ?? 999) - $lat) < 1e-4 && abs((float)($c['lng'] ?? 999) - $lng) < 1e-4;
    if (!$same || time() - (int)($c['fetched'] ?? 0) >= A_WX_CACHE_S || ($c['date'] ?? '') !== date('Y-m-d')) {
        $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude' => round($lat, 4), 'longitude' => round($lng, 4), 'timezone' => 'Asia/Tokyo', 'forecast_days' => 3,
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max']);
        $body = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3]);
            $body = curl_exec($ch);
            if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) $body = false;
            curl_close($ch);
        } else {
            $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5]]));
        }
        $d = is_string($body) ? json_decode($body, true) : null;
        if (is_array($d['daily']['time'] ?? null)) {
            $c = ['lat' => $lat, 'lng' => $lng, 'fetched' => time(), 'date' => date('Y-m-d'), 'daily' => $d['daily']];
            @mkdir($dir, 0755, true);
            @file_put_contents($path, json_encode($c));
            $same = true;
        }
    }
    if (!$same || !is_array($c['daily']['time'] ?? null)) return null;
    $out = [];
    foreach ($c['daily']['time'] as $i => $day) {
        if ($day < date('Y-m-d')) continue;   // 古い取り置きのときは今日より前を飛ばす
        $out[] = [(string)$day, (int)($c['daily']['weather_code'][$i] ?? -1), $c['daily']['temperature_2m_max'][$i] ?? null,
            $c['daily']['temperature_2m_min'][$i] ?? null, $c['daily']['precipitation_probability_max'][$i] ?? null];
    }
    return $out ? array_slice($out, 0, 3) : null;
}

// WMO の天気コード → マークと短い言葉
function weather_icon(int $code): array
{
    if ($code === 0) return ['☀️', '晴れ'];
    if ($code <= 2) return ['🌤️', '晴れ時々曇り'];
    if ($code === 3) return ['☁️', '曇り'];
    if ($code <= 48) return ['🌫️', '霧'];
    if ($code <= 57) return ['🌦️', '霧雨'];
    if ($code <= 67) return ['🌧️', '雨'];
    if ($code <= 77) return ['❄️', '雪'];
    if ($code <= 82) return ['🌦️', 'にわか雨'];
    if ($code <= 86) return ['🌨️', 'にわか雪'];
    if ($code <= 99) return ['⛈️', '雷雨'];
    return ['❔', '不明'];
}

function weather_html(?array $days): string
{
    if (!$days) return '<div class="wx"><div class="none" style="grid-column:1/-1">天気予報を取得できませんでした</div></div>';
    $o = '<div class="wx">';
    foreach ($days as $i => [$day, $code, $mx, $mn, $pp]) {
        [$ic, $txt] = weather_icon($code);
        $o .= '<div title="' . h($txt) . '"><span class="d">' . h(['今日', '明日', '明後日'][$i] ?? date('n/j', strtotime($day))) . '</span>'
            . '<span class="i">' . $ic . '</span><span class="t">'
            . ($mx !== null ? '<b class="mx">' . (int)round((float)$mx) . '°</b>' : '') . ($mn !== null ? '<b class="mn">' . (int)round((float)$mn) . '°</b>' : '')
            . ($pp !== null ? '<br>☂' . (int)$pp . '%' : '') . '</span></div>';
    }
    return $o . '</div>';
}

if (isset($_GET['photo'])) {
    $aid = (string)$_GET['photo'];
    $path = isset($site['apiaries'][$aid]) ? photo_path($aid, $site['apiaries'][$aid]) : '';
    if ($path === '' || !is_file($path)) { http_response_code(404); exit; }
    $type = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$site['apiaries'][$aid]['photo']];
    if (!empty($_GET['t']) && ($tp = photo_thumb($aid, $path)) !== '') { $path = $tp; $type = 'image/jpeg'; }
    header('Content-Type: ' . $type);
    header('Content-Length: ' . filesize($path));
    // ページ側の URL には更新日時(v)が付くので、写真を変えれば URL も変わる。それまではブラウザに30日とっておいてもらう
    header('Cache-Control: private, max-age=' . (isset($_GET['v']) ? 2592000 : 86400));
    readfile($path);
    exit;
}

if (is_post()) {
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'apiary_save') {
        $id = (string)($_POST['id'] ?? '');
        $name = str_in('name', 40);
        if ($name === '') redirect('apiary.php', '蜂場の名前を入れてください。');
        if ($id !== '' && !isset($site['apiaries'][$id])) redirect('apiary.php');
        if ($id !== '' && !apiary_can_edit($site, $id)) redirect('apiary.php#a-' . $id, '蜂場の情報を変えられるのは、持ち主とチーム管理者だけです。');
        // 書き先は蜂場の持ち主(新しい蜂場は自分)。ハブ・センサは持ち主のものだけ選べる
        $owner = $id !== '' ? apiary_owner($site, $id) : $UID;
        site_use($owner);
        $selHubs = array_values(array_filter((array)($_POST['hubs'] ?? []), fn($v) => ($hubs[(string)$v]['o'] ?? '') === $owner));
        $selFences = array_values(array_filter((array)($_POST['fences'] ?? []), fn($v) => ($sensors[(string)$v]['kind'] ?? '') === 'fa' && $sensors[(string)$v]['o'] === $owner));
        // 新しい蜂場は、選んだチーム(自分が管理者のもの)の全員に共有する
        $share = [];
        if ($id === '') foreach ((array)($_POST['share'] ?? []) as $tid) if (team_role((string)$tid, $UID) === 'admin') $share[(string)$tid] = null;
        $newId = $id !== '' ? $id : new_id();
        // 写真(送られたときだけ置き換える。「写真を外す」なら消す)
        $photo = $id !== '' ? (string)($site['apiaries'][$id]['photo'] ?? '') : '';
        $f = $_FILES['photo'] ?? null;
        if (is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $info = ($f['error'] === UPLOAD_ERR_OK && $f['size'] <= A_PHOTO_MAX) ? @getimagesize($f['tmp_name']) : false;
            $ext = $info ? (A_PHOTO_TYPES[$info[2]] ?? '') : '';
            if ($ext === '') redirect('apiary.php#a-' . $newId, '写真を保存できませんでした (JPEG・PNG・WebP で 8MB までです)。ほかの内容も保存していません。');
            @mkdir(site_dir() . '/photos', 0755, true);
            foreach (A_PHOTO_TYPES as $e) @unlink(site_dir() . '/photos/' . $newId . '.' . $e);
            @unlink(site_dir() . '/photos/' . $newId . '_t.jpg');
            if (!move_uploaded_file($f['tmp_name'], site_dir() . '/photos/' . $newId . '.' . $ext)) redirect('apiary.php#a-' . $newId, '写真を保存できませんでした。');
            $photo = $ext;
        } elseif (!empty($_POST['photo_del']) && $photo !== '') {
            @unlink(site_dir() . '/photos/' . $newId . '.' . $photo);
            @unlink(site_dir() . '/photos/' . $newId . '_t.jpg');
            $photo = '';
        }
        site_update('site', function (array $s) use ($newId, $name, $selHubs, $selFences, $photo, $share) {
            $s['apiaries'] = is_array($s['apiaries'] ?? null) ? $s['apiaries'] : [];
            // ハブ・電柵センサは1つの蜂場にだけ紐づける(ほかの蜂場からは外す)
            foreach ($s['apiaries'] as $aid => &$a) {
                if ($aid === $newId) continue;
                $a['hubs'] = array_values(array_diff((array)($a['hubs'] ?? []), $selHubs));
                $a['fences'] = array_values(array_diff((array)($a['fences'] ?? []), $selFences));
            }
            unset($a);
            $old = $s['apiaries'][$newId] ?? ['created' => time(), 'by' => $GLOBALS['UID'], 'share' => $share];
            $s['apiaries'][$newId] = array_merge($old, [
                'name' => $name, 'address' => str_in('address', 200), 'map' => '', 'map_q' => str_in('map_q', 200), 'photo' => $photo,
                'owner' => str_in('owner', 200),
                // 巣箱数(手入力)。巣箱を1つずつ登録せずに蜂場単位で管理する人向け。空なら登録した巣箱の数を出す
                'hive_count' => is_numeric($_POST['hive_count'] ?? '') && (int)$_POST['hive_count'] >= 0 ? min(9999, (int)$_POST['hive_count']) : null,
                'note' => str_in('note'), 'order' => (int)($_POST['order'] ?? 0), 'hubs' => $selHubs, 'fences' => $selFences,
                'updated' => time(),
            ]);
            return $s;
        });
        redirect('apiary.php#a-' . $newId, '蜂場「' . $name . '」を保存しました。');
    }
    if ($act === 'apiary_delete') {
        $id = (string)($_POST['id'] ?? '');
        if (!isset($site['apiaries'][$id])) redirect('apiary.php');
        if ($site['apiaries'][$id]['_r'] !== 'owner') redirect('apiary.php#a-' . $id, '蜂場を削除できるのは持ち主だけです。');
        if (hives_of($site, $id)) redirect('apiary.php#a-' . $id, '巣箱が登録されている蜂場は削除できません。先に巣箱を移すか削除してください。');
        $name = (string)$site['apiaries'][$id]['name'];
        foreach (A_PHOTO_TYPES as $e) @unlink(site_dir() . '/photos/' . $id . '.' . $e);
        @unlink(site_dir() . '/photos/' . $id . '_t.jpg');
        site_update('site', function (array $s) use ($id) { unset($s['apiaries'][$id]); return $s; });
        redirect('apiary.php', '蜂場「' . $name . '」を削除しました (メモと作業記録は残っています)。');
    }
    redirect('apiary.php');
}

// 蜂場の入力欄(新規と変更で共通)
function apiary_form(array $a, string $id, array $hubs, array $sensors, array $site): string
{
    global $UID;
    // ハブ・センサの紐づけは持ち主の中で決める(新しい蜂場は自分のもの)
    $own = $id !== '' ? (string)$a['_o'] : $UID;
    $hubs = array_filter($hubs, fn($hb) => $hb['o'] === $own);
    $sensors = array_filter($sensors, fn($sn) => $sn['o'] === $own);
    $site = site_of_owner($site, $own);
    $owner = [];   // 今どの蜂場に紐づいているか
    foreach ($site['apiaries'] as $aid => $x) {
        foreach ((array)($x['hubs'] ?? []) as $v) $owner[$v] = $aid;
        foreach ((array)($x['fences'] ?? []) as $v) $owner[$v] = $aid;
    }
    $other = fn(string $k) => isset($owner[$k]) && $owner[$k] !== $id
        ? ' <span class="muted">(今は「' . h((string)$site['apiaries'][$owner[$k]]['name']) . '」。選ぶとこちらに移ります)</span>' : '';
    $o = '<form method="post" class="form" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="act" value="apiary_save"><input type="hidden" name="id" value="' . h($id) . '">'
        . '<p><label>蜂場の名前 (必須)</label><input type="text" name="name" maxlength="40" required value="' . h((string)($a['name'] ?? '')) . '"></p>'
        . '<p><label>所在地住所</label><input type="text" name="address" value="' . h((string)($a['address'] ?? '')) . '"></p>'
        . '<p><label>緯度・経度 (Googleマップ上で右クリックしてコピーされる「35.6812, 139.7671」などの値を貼り付け)</label>'
        . '<input type="text" name="map_q" placeholder="35.6812, 139.7671" value="' . h((string)($a['map_q'] ?? '')) . '"></p>'
        . '<p><label>蜂場の写真</label><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" onchange="shrinkPhoto(this)">'
        . (($a['photo'] ?? '') !== '' ? ' <label style="display:inline"><input type="checkbox" name="photo_del" value="1"> 写真を外す</label>' : '') . '</p>'
        . '<p><label>巣箱数 (空なら巣箱ページで登録した巣箱の数を出す)</label><input type="number" name="hive_count" min="0" max="9999" value="'
            . h(isset($a['hive_count']) ? (string)(int)$a['hive_count'] : '') . '"> 群</p>'
        . '<p><label>地主・連絡先</label><input type="text" name="owner" value="' . h((string)($a['owner'] ?? '')) . '"></p>'
        . '<p><label>説明・備考</label><textarea name="note">' . h((string)($a['note'] ?? '')) . '</textarea></p>'
        . '<p><label>並び順 (小さいほど上)</label><input type="number" name="order" value="' . (int)($a['order'] ?? 0) . '"></p>'
        . '<p><label>この蜂場の HPX-Core (ハブ)</label>';
    if (!$hubs) $o .= '<span class="muted">まだハブからのデータがありません。</span>';
    foreach ($hubs as $hid => $hb) {
        $on = in_array($hid, (array)($a['hubs'] ?? []), true);
        $o .= '<span style="display:block"><input type="checkbox" name="hubs[]" value="' . h($hid) . '"' . ($on ? ' checked' : '') . '> '
            . h($hb['name']) . ' <span class="muted">' . h($hid) . ($hb['retired'] ? ' (外した)' : '') . '</span>' . $other($hid) . '</span>';
    }
    $o .= '</p><p><label>この蜂場の電柵アラートセンサ</label>';
    $fa = array_filter($sensors, fn($s) => $s['kind'] === 'fa');
    if (!$fa) $o .= '<span class="muted">電柵アラートセンサはまだ登録されていません (HPX-Core の管理画面で登録します)。</span>';
    foreach ($fa as $k => $s) {
        $o .= '<span style="display:block"><input type="checkbox" name="fences[]" value="' . h($k) . '"' . (in_array($k, (array)($a['fences'] ?? []), true) ? ' checked' : '') . '> '
            . h($s['name']) . ' <span class="muted">(' . h($s['hubName']) . ($s['retired'] ? '・外した' : '') . ')</span>' . $other($k) . '</span>';
    }
    // 新しい蜂場: 自分が管理者のチームに共有する(初めはすべてオン = チームの全員)。あとからチームページで変えられる
    $adm = array_keys(array_filter(teams_of($UID), fn($r) => $r === 'admin'));
    if ($id === '' && $adm) {
        $o .= '</p><p><label>共有するチーム (チームの全員が見られます。あとから<a href="team.php">チーム</a>のページで変えられます)</label>';
        foreach ($adm as $tid) $o .= '<span style="display:inline-block;margin-right:14px"><input type="checkbox" name="share[]" value="' . h($tid) . '" checked> ' . h((string)team_get($tid)['name']) . '</span>';
    }
    return $o . '</p><p><button type="submit">保存</button></p></form>';
}

// 蜂場の持ち主と共有先の短い説明(チーム機能を使っているときだけ)
function share_line(array $a): string
{
    if (!team_on() || demo_members_on()) return '';   // デモの架空のメンバーは作業記録だけ
    if (!team_on()) return '';
    if ($a['_o'] !== $UID) {
        $tn = [];
        foreach (apiary_share_live($a['_o'], $a) as $tid => $sel) if (team_role($tid, $UID) !== null) $tn[] = (string)team_get($tid)['name'];
        return '<p class="muted" style="margin:0 0 .3em">' . h(user_name($a['_o'])) . ' さんの蜂場 (' . h(implode('・', $tn)) . ')　あなたは'
            . ($a['_r'] === 'admin' ? 'チーム管理者' : 'メンバー') . '</p>';
    }
    $sh = apiary_share_live($UID, $a);
    if (!$sh) return '<p class="muted" style="margin:0 0 .3em">自分の蜂場 (共有なし)</p>';
    $tn = [];
    foreach ($sh as $tid => $sel) $tn[] = (string)team_get($tid)['name'] . ($sel === null ? '' : ' の ' . count($sel) . '人');
    return '<p class="muted" style="margin:0 0 .3em">自分の蜂場　共有: <a href="team.php">' . h(implode('・', $tn)) . '</a></p>';
}

// 蜂場の平均のグラフ用: その蜂場の巣箱に紐づいたセンサの記録を、同じ時刻(分単位)ごとに平均する
//   温度・湿度は温湿度センサすべての平均、重量は較正済みの重量センサ(巣箱ごと)の平均
//   n: 平均のもとになった群(巣箱)の数。期間内に記録がある巣箱だけ数える
function apiary_avg(array $hv, array $sensors, int $since): array
{
    $sum = ['t' => [], 'h' => [], 'w' => []];
    $n = ['t' => [], 'h' => [], 'w' => []];
    foreach ($hv as $hid => $x) {
        foreach ((array)($x['sensors'] ?? []) as $k) {
            if (!isset($sensors[$k])) continue;
            $sn = $sensors[$k];
            $d = ble_data($sn['hub'], $since);
            if ($sn['kind'] === 'th') {
                foreach ($d['th'][$sn['mac']] ?? [] as $r) {
                    $t = intdiv($r[0], 60) * 60;
                    $sum['t'][$t][] = $r[1];
                    $n['t'][$hid] = 1;
                    if ($r[2] !== null) { $sum['h'][$t][] = $r[2]; $n['h'][$hid] = 1; }
                }
            } else {
                $c = cal_load($sn['hub'])[$sn['mac']] ?? null;
                foreach ($d['w'][$sn['mac']] ?? [] as $r) {
                    $v = to_kg($r[1], $c);
                    if ($v !== null) { $sum['w'][intdiv($r[0], 60) * 60][] = $v; $n['w'][$hid] = 1; }
                }
            }
        }
    }
    $out = [];
    foreach ($sum as $k => $byT) {
        ksort($byT);
        $out[$k] = [];
        foreach ($byT as $t => $vs) $out[$k][] = [$t, array_sum($vs) / count($vs)];
    }
    $out['n'] = array_map('count', $n);
    return $out;
}

// 蜂場に紐づいている HPX-Core と電柵アラートの稼働状況のボタン
//   受信できている: 薄い緑 / 届いていない: グレーに赤の✕ / 電柵の電圧低下など: 赤に⚠
function sensor_chips(array $a, array $hv, array $hubs, array $sensors, string $aid, int $now): string
{
    global $HPX_HUB_MISSING_S, $HPX_ALERT_MISSING_S;
    $chips = [];
    $chip = function (string $kind, string $label, string $state, string $title, string $href) use (&$chips) {
        $mark = $state === 'ng' ? '<span class="x">✕</span>' : ($state === 'alert' ? '<span class="x">⚠</span>' : '');
        $chips[] = '<a class="chip ' . $kind . ($state !== 'ok' ? ' ' . $state : '') . '" href="' . h($href) . '" title="' . h($title) . '">' . $mark . h($label) . '</a>';
    };
    $lastTxt = fn(int $e) => $e > 0 ? '最後の受信 ' . date('m-d H:i', $e) . ' (' . ago_text($e, $now) . ')' : '受信の記録なし';
    foreach ((array)($a['hubs'] ?? []) as $hid) {
        if (!isset($hubs[$hid])) continue;
        $last = (int)($hubs[$hid]['st']['last_rx'] ?? 0);
        $ok = $last > 0 && $now - $last < (int)($HPX_HUB_MISSING_S ?? 3600);
        $chip('core', 'HPX-Core', $ok ? 'ok' : 'ng', $hubs[$hid]['name'] . ': ' . ($ok ? '受信中' : '届いていません') . '。' . $lastTxt($last),
            $hubs[$hid]['o'] === $GLOBALS['UID'] ? 'sensor.php?' . http_build_query(['hub' => $hid]) : 'apiary.php#a-' . $aid);   // センサページは自分の HPX-Core だけ
    }
    foreach ((array)($a['fences'] ?? []) as $k) {
        if (!isset($sensors[$k])) continue;
        $s = $sensors[$k]['s'];
        $last = (int)($s['last_epoch'] ?? 0);
        $ok = $last > 0 && $now - $last < (int)($HPX_ALERT_MISSING_S ?? 3600);
        $st = (int)($s['fence_state'] ?? 0);
        $chip('fa', '電柵', !$ok ? 'ng' : ($st !== 0 ? 'alert' : 'ok'),
            $sensors[$k]['name'] . ': ' . (!$ok ? '届いていません' : strip_tags(fence_state_html($s))) . '。' . $lastTxt($last), 'apiary.php#a-' . $aid);
    }
    return '<div class="chips">' . ($chips ? implode('', $chips) : '<span class="muted">登録センサはありません</span>') . '</div>';
}

page_head('蜂場 - ' . S_TITLE, 'apiary.php');
echo flash_html();
if (!$site['apiaries']) echo '<p class="muted" style="margin-top:1em">まだ蜂場が登録されていません。「＋ 蜂場を追加」から登録してください。</p>';

$memos = memos_load();
usort($memos, fn($x, $y) => [$y['date'] ?? '', $y['created'] ?? 0] <=> [$x['date'] ?? '', $x['created'] ?? 0]);
$since = $now - S_PERIOD_DEFAULT * 86400;

echo '<div class="cols">';
foreach ($site['apiaries'] as $aid => $a) {
    $hv = hives_of($site, $aid);
    echo '<div class="card apiary" id="a-' . h($aid) . '"><h2 style="margin-top:.2em;display:flex;align-items:baseline;gap:8px">' . h((string)$a['name'])
        . '<span style="margin-left:auto;font-size:.75em;font-weight:normal;color:#444;white-space:nowrap">'
        . '登録<a href="hive.php?' . h(http_build_query(['a' => $aid])) . '">' . count($hv) . '群</a>／全' . (isset($a['hive_count']) ? (int)$a['hive_count'] : count($hv)) . '群</span></h2>';
    echo share_line($a) . sensor_chips($a, $hv, $hubs, $sensors, $aid, $now);
    $mq = trim((string)(($a['map_q'] ?? '') !== '' ? $a['map_q'] : ($a['address'] ?? '')));
    $pm = '';
    if (($a['photo'] ?? '') !== '') {
        $pm .= '<a href="apiary.php?photo=' . h($aid) . '" target="_blank"><img src="apiary.php?' . h(http_build_query(['photo' => $aid, 't' => 1, 'v' => (int)($a['updated'] ?? 0)])) . '" alt="' . h((string)$a['name']) . 'の写真" loading="lazy"></a>';
    }
    if ($mq !== '') {
        $pm .= '<iframe src="https://maps.google.com/maps?' . h(http_build_query(['q' => $mq, 'z' => 15, 'output' => 'embed'])) . '" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="' . h((string)$a['name']) . 'の地図"></iframe>';
    }
    // 写真と地図はいつも2つ分の場所を取る(ボックスの大きさをそろえるため)
    if (($a['photo'] ?? '') === '') $pm = '<div class="none">写真なし</div>' . $pm;
    if ($mq === '') $pm .= '<div class="none">地図なし</div>';
    echo '<div class="pm">' . $pm . '</div>';
    // 天気予報(今日・明日・明後日)。緯度,経度が無ければ、同じ高さで案内だけ出す
    $ll = apiary_latlng($a);
    echo $ll ? weather_html(weather_get($aid, $ll[0], $ll[1], site_dir_of($a['_o'])))
        : '<div class="wx"><div class="none" style="grid-column:1/-1">地図の場所に緯度,経度を入れると天気予報が出ます</div></div>';

    // HPX-Core と電柵(詳細の中に入れる。異常のときだけ上にも出す)
    $hubTxt = []; $warn = [];
    foreach ((array)($a['hubs'] ?? []) as $hid) {
        if (!isset($hubs[$hid])) continue;
        $last = (int)($hubs[$hid]['st']['last_rx'] ?? 0);
        $ok = $last > 0 && $now - $last < (int)($HPX_HUB_MISSING_S ?? 3600);
        $hubTxt[] = h($hubs[$hid]['name']) . ' ' . ($ok ? '<span class="ok">受信中</span>' : '<span class="ng">届いていません</span>')
            . ' <span class="muted">' . h($last ? ago_text($last, $now) : '-') . '</span>';
    }
    $faTxt = [];
    foreach ((array)($a['fences'] ?? []) as $k) {
        if (!isset($sensors[$k])) continue;
        $faTxt[] = h($sensors[$k]['name']) . ' ' . fence_state_html($sensors[$k]['s']);
        if ((int)($sensors[$k]['s']['fence_state'] ?? 0) !== 0) $warn[] = '電柵 ' . h($sensors[$k]['name']) . ': ' . strip_tags(fence_state_html($sensors[$k]['s']));
    }
    if ($warn) echo '<p class="ng" style="margin:.3em 0">' . implode('<br>', $warn) . '</p>';

    // 平均のグラフ(小さく、3日分)
    $avg = apiary_avg($hv, $sensors, $since);
    // 記録がないグラフも枠だけ出して、ボックスの大きさをそろえる。高さは巣箱ページのグラフより低く
    // 上が重量(青)、下が温度(赤)。巣箱ページと同じ色
    echo '<div class="mini"><p class="muted">巣箱重量 (kg) ／' . $avg['n']['w'] . '群平均</p>' . chart_svg([['平均重量', '#1565c0', $avg['w']]], $since, $now, 'kg', 0.5, 400, 96, 11, true)
        . '<p class="muted">中心温度 (℃) ／' . $avg['n']['t'] . '群平均</p>' . chart_svg([['平均温度', '#D55E00', $avg['t']]], $since, $now, '℃', 2, 400, 96, 11, true)
        . '<p class="muted">直近3日。巣箱ごとのグラフは<a href="hive.php?' . h(http_build_query(['a' => $aid])) . '">巣箱ページ</a></p></div>';

    echo '<div class="bottom"><h3 style="margin-top:.8em">タスク</h3>' . todo_brief($site, $aid, '', true, 'apiary.php#a-' . $aid);

    // 蜂場詳細(見たいときだけ開く)
    echo '<details><summary><b>蜂場詳細</b></summary><dl class="info" style="margin-top:.5em">';
    echo '<dt>巣箱数</dt><dd>' . (isset($a['hive_count']) ? (int)$a['hive_count'] . ' 群' : '<span class="muted">未入力 (登録した巣箱 ' . count($hv) . ' 箱)</span>') . '</dd>';
    echo '<dt>所在地</dt><dd>' . (($a['address'] ?? '') !== '' ? h((string)$a['address']) : '<span class="muted">-</span>')
        . ($mq !== '' ? ' <a href="https://www.google.com/maps?' . h(http_build_query(['q' => $mq])) . '" target="_blank" rel="noopener">地図</a>' : '') . '</dd>';
    echo '<dt>地主・連絡先</dt><dd>' . (($a['owner'] ?? '') !== '' ? h((string)$a['owner']) : '<span class="muted">-</span>') . '</dd>';
    echo '<dt>HPX-Core</dt><dd>' . ($hubTxt ? implode('<br>', $hubTxt) : '<span class="muted">未設定</span>') . '</dd>';
    echo '<dt>電柵</dt><dd>' . ($faTxt ? implode('<br>', $faTxt) : '<span class="muted">未設定</span>') . '</dd>';
    echo '</dl>';
    if (($a['note'] ?? '') !== '') echo '<p>' . nl((string)$a['note']) . '</p>';

    echo '<h3 style="margin-top:.8em">最近のメモ</h3>';
    $mine = array_slice(array_values(array_filter($memos, fn($m) => ($m['apiary'] ?? '') === $aid)), 0, 3);
    if (!$mine) echo '<p class="muted">メモはありません。</p>';
    foreach ($mine as $m) {
        echo '<p style="margin:.3em 0"><span class="muted">' . h(date('n/j', strtotime((string)$m['date']))) . ' ' . h((string)($m['type'] ?? '')) . '</span> '
            . (($m['hive'] ?? '') !== '' ? '<b>' . h((string)($site['hives'][$m['hive']]['name'] ?? '')) . '</b> ' : '')
            . (!empty($m['photos']) ? '📷 ' : '') . h(mb_strimwidth(trim(implode('・', memo_chips($m)) . ' ' . (string)($m['text'] ?? '')), 0, 80, '…')) . '</p>';
    }
    echo '<p class="muted"><a href="memo.php?' . h(http_build_query(['a' => $aid])) . '">メモをすべて見る</a>　'
        . '<a href="memo.php?' . h(http_build_query(['new' => $aid . '|'])) . '#memo-form">＋蜂場のメモ</a>　'
        . '<a href="work.php?' . h(http_build_query(['new_log' => $aid . '|'])) . '#log-form">＋作業記録</a></p>';

    if (apiary_can_edit($site, $aid)) {
        echo '<details><summary>蜂場の情報を変更</summary>' . apiary_form($a, $aid, $hubs, $sensors, $site);
        if ($a['_r'] === 'owner') {
            echo '<form method="post" onsubmit="return confirm(\'この蜂場を削除しますか?\');">' . csrf_field()
                . '<input type="hidden" name="act" value="apiary_delete"><input type="hidden" name="id" value="' . h($aid) . '">'
                . '<button type="submit"' . ($hv ? ' disabled title="巣箱が登録されているので削除できません"' : '') . '>この蜂場を削除</button>'
                . ($hv ? ' <span class="muted">巣箱があるうちは削除できません</span>' : '') . '</form>';
        }
        echo '</details>';
    }
    echo '</details></div></div>';
}
// 蜂場を追加(小さなボックス。押すと入力欄が開く)
echo '<details class="addbox" id="new"' . (!$site['apiaries'] ? ' open' : '') . '><summary><span class="plus">＋</span>蜂場を追加</summary>'
    . '<div class="card">' . apiary_form([], '', $hubs, $sensors, $site) . '</div></details>';
echo '</div>';

echo '<p class="muted" style="font-size:.75em">天気予報: <a href="https://open-meteo.com/" target="_blank" rel="noopener">Open-Meteo.com</a></p>';
echo '<script>function shrinkPhoto(inp){var f=inp.files&&inp.files[0];if(!f||!window.createImageBitmap||!window.DataTransfer)return;'
    . 'createImageBitmap(f,{imageOrientation:"from-image"}).then(function(b){var M=1600,r=Math.min(1,M/Math.max(b.width,b.height));'
    . 'if(r===1&&f.size<1500000)return;var c=document.createElement("canvas");c.width=Math.round(b.width*r);c.height=Math.round(b.height*r);'
    . 'c.getContext("2d").drawImage(b,0,0,c.width,c.height);c.toBlob(function(bl){if(!bl)return;var d=new DataTransfer();'
    . 'd.items.add(new File([bl],"photo.jpg",{type:"image/jpeg"}));inp.files=d.files;},"image/jpeg",0.85);}).catch(function(){});}</script>';
echo '</body></html>';
