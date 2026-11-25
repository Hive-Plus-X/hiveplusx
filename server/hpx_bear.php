<?php
// クマの出没情報 (くまっぷ https://kumap-xenon.web.app 株式会社Xenon のテナント API)。
//   蜂場の近くのクマの情報 (目撃・痕跡・人身被害・被害のすべて) を数えて、蜂場タイルに熊のマークを出す (2026-11-25 TARO 決定):
//   ・黄色: 過去3年・半径10km以内に情報がある
//   ・赤色: 過去1年・半径5km以内に情報がある
//   ・赤色の点滅 + お知らせ (メール・LINE・プッシュ通知): 半径5km以内に新しい情報が増えた (点滅は $HPX_BEAR_BLINK_DAYS 日)
//   お知らせには、いつ・どんな情報かの概要と、その情報のくまっぷの画面へのリンクを書く。
//   くまっぷの利用条件: 出典として「くまっぷ」と各データ提供元 (自治体など) を出す。不安を過度にあおる書き方をしない。
//
// しくみ:
//   ・API キーは運用者ごとにくまっぷと契約して、HPX-note の「サーバーの設定」の「クマの出没情報 (くまっぷ)」で入れる ($HPX_KUMAP_KEY、config.php に書いてもよい)。空ならこの機能は何も出さない
//   ・取り込みは定期実行 (hpx_cron.php の bear_cron)。今のくまっぷ API は緯度経度で絞れないので、
//     蜂場とその周り10kmの都道府県ごとに取って、距離はこちらで計算する (都道府県は国土地理院の住所検索で調べる)。
//     都道府県ごとに、過去3年分を1日1回取り直し、直近の分を1時間に1回足す。1回の定期実行で使う時間には上限を設ける
//   ・取り込んだ点: データフォルダの kumap/pref_<都道府県番号>.json (サーバーで1つ。どのアカウントも同じ情報を使う)
//   ・蜂場ごとの結果: 蜂場の持ち主の site/bear.json (ページはこれを読むだけ)
declare(strict_types=1);
require_once __DIR__ . '/hpx_weather.php';   // apiary_latlng()

const B_RED_KM = 5.0;          // 赤色・お知らせの範囲
const B_RED_DAYS = 365;        // 赤色の期間
const B_YEL_KM = 10.0;         // 黄色の範囲
const B_YEL_DAYS = 3 * 365;    // 黄色の期間 (取り込む期間もこれ)
const B_NEW_DAYS = 30;         // お知らせするのは、出来事の日時がこの日数以内の新しい情報だけ (古い記録がまとめて載ったときに大量に知らせないように)
const B_FULL_S = 86400;        // 過去3年分を取り直す間隔
const B_INC_S = 3600;          // 直近の分を取る間隔
const B_INC_DAYS = 45;         // 直近の分として取る日数
const B_BUDGET_S = 12;         // 1回の定期実行で取り込みに使う時間の上限 (外部の定期実行サービスは30秒ほどで打ち切るため)
const B_PAGE = 1000;           // 1回に取る件数 (API の上限)
const B_GEO_RETRY_S = 86400;   // 都道府県を調べられなかったとき、次に調べるまで
const B_MAIL_MAX = 10;         // 1通のお知らせに書く件数

const B_PREFS = ['01' => '北海道', '02' => '青森県', '03' => '岩手県', '04' => '宮城県', '05' => '秋田県', '06' => '山形県', '07' => '福島県',
    '08' => '茨城県', '09' => '栃木県', '10' => '群馬県', '11' => '埼玉県', '12' => '千葉県', '13' => '東京都', '14' => '神奈川県',
    '15' => '新潟県', '16' => '富山県', '17' => '石川県', '18' => '福井県', '19' => '山梨県', '20' => '長野県', '21' => '岐阜県',
    '22' => '静岡県', '23' => '愛知県', '24' => '三重県', '25' => '滋賀県', '26' => '京都府', '27' => '大阪府', '28' => '兵庫県',
    '29' => '奈良県', '30' => '和歌山県', '31' => '鳥取県', '32' => '島根県', '33' => '岡山県', '34' => '広島県', '35' => '山口県',
    '36' => '徳島県', '37' => '香川県', '38' => '愛媛県', '39' => '高知県', '40' => '福岡県', '41' => '佐賀県', '42' => '長崎県',
    '43' => '熊本県', '44' => '大分県', '45' => '宮崎県', '46' => '鹿児島県', '47' => '沖縄県'];
const B_KINDS = ['witness' => '目撃', 'trace' => '痕跡', 'injury' => '人身被害', 'damage' => '被害'];

function bear_on(): bool { return trim((string)($GLOBALS['HPX_KUMAP_KEY'] ?? '')) !== ''; }
function bear_api(): string { $u = rtrim(trim((string)($GLOBALS['HPX_KUMAP_API'] ?? '')), '/'); return $u !== '' ? $u : 'https://xgzsccaaaxadvzzsztde.supabase.co/functions/v1'; }
function bear_web(): string { $u = rtrim(trim((string)($GLOBALS['HPX_KUMAP_WEB'] ?? '')), '/'); return $u !== '' ? $u : 'https://kumap-xenon.web.app'; }
// くまっぷの画面でその情報を開く URL (くまっぷの「URLをコピー」と同じ形)
function bear_point_url(string $id): string { return bear_web() . '/?' . http_build_query(['id' => $id]); }
function bear_blink_days(): int { return max(0, (int)($GLOBALS['HPX_BEAR_BLINK_DAYS'] ?? 7)); }
function bear_dir(): string { return data_dir() . '/kumap'; }

// 2点の距離 (km)
function bear_km(float $la1, float $lo1, float $la2, float $lo2): float
{
    $r = M_PI / 180;
    $a = sin(($la2 - $la1) * $r / 2) ** 2 + cos($la1 * $r) * cos($la2 * $r) * sin(($lo2 - $lo1) * $r / 2) ** 2;
    return 6371.0 * 2 * asin(min(1.0, sqrt($a)));
}
// 蜂場から見た向き (8方位)
function bear_dir8(float $la1, float $lo1, float $la2, float $lo2): string
{
    $deg = rad2deg(atan2(($lo2 - $lo1) * cos(deg2rad($la1)), $la2 - $la1));
    return ['北', '北東', '東', '南東', '南', '南西', '西', '北西'][((int)round(($deg + 360) / 45)) % 8];
}

// ---- 蜂場の周りの都道府県 (国土地理院の住所検索。蜂場と、周り10kmの8か所) ----
function bear_geo_key(float $lat, float $lng): string { return sprintf('%.4f,%.4f', $lat, $lng); }

// 緯度経度の地点の都道府県番号 ('05' など)。海の上などで分からなければ ''、つながらなければ null
function bear_geo_pref(float $lat, float $lng, int $timeout = 5): ?string
{
    $url = 'https://mreversegeocoder.gsi.go.jp/reverse-geocoder/LonLatToAddress?' . http_build_query(['lat' => round($lat, 5), 'lon' => round($lng, 5)]);
    $raw = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $timeout, 'header' => "User-Agent: HPX-note\r\n"]]));
    if (!is_string($raw)) return null;
    $j = json_decode($raw, true);
    if (!is_array($j)) return null;
    $cd = preg_replace('/\D/', '', (string)($j['results']['muniCd'] ?? ''));
    if ($cd === '') return '';
    $pc = substr(str_pad($cd, 5, '0', STR_PAD_LEFT), 0, 2);
    return isset(B_PREFS[$pc]) ? $pc : '';
}

// 蜂場の住所に都道府県名があれば、その番号
function bear_addr_pref(string $addr): string
{
    foreach (B_PREFS as $pc => $n) if (mb_strpos($addr, $n) !== false) return (string)$pc;   // 配列のキーは数になることがあるので文字に
    return '';
}

// 調べた結果を返す。まだなら調べる (時間の上限 $until を過ぎたら調べない)。分からなければ住所の都道府県だけ
function bear_area_prefs(float $lat, float $lng, string $addr, float $until): array
{
    $path = bear_dir() . '/geo.json';
    $geo = json_load($path);
    $k = bear_geo_key($lat, $lng);
    $g = $geo[$k] ?? null;
    if (is_array($g) && !empty($g['ok'])) return (array)$g['prefs'];
    if ((!is_array($g) || time() - (int)($g['at'] ?? 0) >= B_GEO_RETRY_S) && microtime(true) < $until) {
        $prefs = []; $fail = 0;
        $pts = [[$lat, $lng]];
        $dLat = B_YEL_KM / 111.0; $dLng = B_YEL_KM / (111.0 * max(0.2, cos(deg2rad($lat))));
        foreach ([[1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0], [-1, -1], [0, -1], [1, -1]] as [$a, $b]) {
            $f = ($a && $b) ? M_SQRT1_2 : 1.0;   // 斜めも10kmの円の上に
            $pts[] = [$lat + $a * $dLat * $f, $lng + $b * $dLng * $f];
        }
        foreach ($pts as [$la, $lo]) {
            $pc = microtime(true) < $until ? bear_geo_pref($la, $lo) : null;   // 時間が来たら残りは次の回に
            if ($pc === null) $fail++; elseif ($pc !== '') $prefs[$pc] = 1;
        }
        $ap = bear_addr_pref($addr);
        if ($ap !== '') $prefs[$ap] = 1;
        $g = ['prefs' => array_map('strval', array_keys($prefs)), 'ok' => $fail === 0 && $prefs !== [], 'at' => time()];
        json_update($path, function (array $all) use ($k, $g) { $all[$k] = $g; return $all; });
        return array_map('strval', $g['prefs']);
    }
    $ap = bear_addr_pref($addr);
    return array_values(array_unique(array_merge(array_map('strval', (array)($g['prefs'] ?? [])), $ap !== '' ? [$ap] : [])));
}

// ---- くまっぷから取る ----
// 1ページ取る。返す値: [点の配列, エラー文 (成功なら '')]
function bear_api_list(array $req, int $timeout = 20): array
{
    $key = trim((string)($GLOBALS['HPX_KUMAP_KEY'] ?? ''));
    $body = json_encode($req, JSON_UNESCAPED_UNICODE);
    $hdr = ['Content-Type: application/json', 'Authorization: Bearer ' . $key, 'User-Agent: HPX-note'];
    if (function_exists('curl_init')) {
        $ch = curl_init(bear_api() . '/api-points-list');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $hdr, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
    } else {
        $raw = @file_get_contents(bear_api() . '/api-points-list', false, stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $hdr),
            'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true]]));
        $code = preg_match('#^HTTP/\S+\s+(\d+)#', (string)($http_response_header[0] ?? ''), $m) ? (int)$m[1] : 0;
        $err = '';
    }
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if ($code !== 200 || !is_array($j['data'] ?? null)) {
        return [[], 'HTTP ' . $code . ' ' . mb_strimwidth((string)($j['error'] ?? $err), 0, 200, '…')];
    }
    return [$j['data'], ''];
}

// くまっぷの点を、取っておく形にする: [日時(秒), 緯度, 経度, 種類, 種類の表示名, 出典, 出典URL, 市区町村, 内容]
function bear_pack(array $p): ?array
{
    $lat = $p['location']['lat'] ?? null; $lng = $p['location']['lng'] ?? null;
    $t = strtotime((string)($p['event_time'] ?? ''));
    if (!is_numeric($lat) || !is_numeric($lng) || !$t || ($p['id'] ?? '') === '') return null;
    $kind = (string)($p['point_kind']['name'] ?? ($p['name'] ?? ''));
    return [$t, round((float)$lat, 6), round((float)$lng, 6), $kind, (string)($p['output_name'] ?? ''),
        (string)($p['source'] ?? ($p['tenant_name'] ?? '')), (string)($p['source_url'] ?? ''), (string)($p['city'] ?? ''),
        mb_substr(trim((string)($p['content'] ?? '')), 0, 200)];
}

function bear_pref_path(string $pc): string { return bear_dir() . '/pref_' . $pc . '.json'; }
function bear_pref_load(string $pc): array
{
    $c = json_decode((string)@file_get_contents(bear_pref_path($pc)), true);
    return is_array($c) ? $c + ['full' => 0, 'inc' => 0, 'pts' => []] : ['full' => 0, 'inc' => 0, 'pts' => []];
}
function bear_pref_save(string $pc, array $c): void
{
    @mkdir(bear_dir(), 0755, true);
    $p = bear_pref_path($pc);
    if (@file_put_contents($p . '.tmp', json_encode($c, JSON_UNESCAPED_UNICODE)) !== false) @rename($p . '.tmp', $p);
}

// 1つの都道府県を取る。$full なら過去3年分 (途中で時間が来たら続きを次の回に)、そうでなければ直近の分。
// 返す値: 終わったら true
function bear_fetch_pref(string $pc, bool $full, float $until, array &$log): bool
{
    $now = time();
    $c = bear_pref_load($pc);
    $job = $full ? (array)($c['job'] ?? []) : [];
    if ($full && !$job) $job = ['after' => date('c', $now - B_YEL_DAYS * 86400 - 86400), 'offset' => 0, 'pts' => [], 'started' => $now];
    $after = $full ? (string)$job['after'] : date('c', $now - B_INC_DAYS * 86400);
    $offset = $full ? (int)$job['offset'] : 0;
    $got = $full ? (array)$job['pts'] : [];
    while (true) {
        if (microtime(true) >= $until) {
            if ($full) { $c['job'] = ['after' => $after, 'offset' => $offset, 'pts' => $got, 'started' => (int)$job['started']]; bear_pref_save($pc, $c); }
            $log[] = B_PREFS[$pc] . ' 途中まで (' . count($got) . '件)';
            return false;
        }
        [$rows, $err] = bear_api_list(['limit' => B_PAGE, 'offset' => $offset, 'prefecture' => B_PREFS[$pc], 'event_time_after' => $after,
            'status' => 'active', 'sort_key' => 'event_time', 'sort_order' => 'asc']);
        if ($err !== '') {
            $c['err'] = date('Y-m-d H:i') . ' ' . $err;
            if ($full) $c['job'] = ['after' => $after, 'offset' => $offset, 'pts' => $got, 'started' => (int)$job['started']];
            $c[$full ? 'full_try' : 'inc'] = $now;   // しばらく取りに行かない (くまっぷに負担をかけないように)
            bear_pref_save($pc, $c);
            $log[] = B_PREFS[$pc] . ' 取れませんでした: ' . $err;
            return false;
        }
        foreach ($rows as $p) if (is_array($p) && ($x = bear_pack($p))) $got[(string)$p['id']] = $x;
        $offset += count($rows);
        if (count($rows) < B_PAGE) break;
    }
    $old = $c['pts'];
    if ($full) {
        $c['pts'] = $got;            // 取り直した分で置き換える (くまっぷで消された情報も消える)
        $c['full'] = $now;
        unset($c['job'], $c['full_try']);
    } else {
        $c['pts'] = $got + $old;
    }
    $c['inc'] = $now;
    $cut = $now - B_YEL_DAYS * 86400;
    $c['pts'] = array_filter($c['pts'], fn($x) => (int)$x[0] >= $cut);
    unset($c['err']);
    bear_pref_save($pc, $c);
    $log[] = B_PREFS[$pc] . ($full ? ' 3年分 ' : ' 直近 ') . count($got) . '件';
    return true;
}

// ---- 蜂場ごとの結果 ----
// 点の一覧から、蜂場の近くの情報を数える。$data: [都道府県番号 => 取っておいた点の一覧]
// 返す値: [黄色の範囲の数, 赤色の範囲の数, 5km以内の点 [id => [距離, 点]] (新しい順), 黄色の範囲の出典の一覧]
function bear_near(float $lat, float $lng, array $prefs, int $now, array $data): array
{
    $nY = 0; $nR = 0; $near = []; $srcs = [];
    $dLat = B_YEL_KM / 110.0; $dLng = B_YEL_KM / (110.0 * max(0.2, cos(deg2rad($lat))));
    foreach ($prefs as $pc) {
        foreach ((array)($data[(string)$pc]['pts'] ?? []) as $id => $x) {
            if (abs($x[1] - $lat) > $dLat || abs($x[2] - $lng) > $dLng) continue;   // 先に大まかに外す
            $km = bear_km($lat, $lng, (float)$x[1], (float)$x[2]);
            $age = $now - (int)$x[0];
            if ($km <= B_YEL_KM && $age <= B_YEL_DAYS * 86400) { $nY++; if ((string)$x[5] !== '') $srcs[(string)$x[5]] = 1; }
            if ($km <= B_RED_KM && $age <= B_RED_DAYS * 86400) $nR++;
            if ($km <= B_RED_KM) $near[(string)$id] = [round($km, 2), $x];
        }
    }
    uasort($near, fn($a, $b) => $b[1][0] <=> $a[1][0]);
    return [$nY, $nR, $near, array_keys($srcs)];
}

function bear_kind_text(array $x): string
{
    if ((string)$x[4] !== '') return (string)$x[4];
    return 'クマ' . (B_KINDS[(string)$x[3]] ?? '情報');
}

// 定期実行: くまっぷから取り込み、蜂場ごとの結果を作り、5km以内に新しい情報があれば知らせる
function bear_cron(): string
{
    if (!bear_on() || !users_exist()) return 'off';
    $start = microtime(true);
    $until = $start + B_BUDGET_S;
    $now = time();
    $log = [];

    // 1. 緯度・経度のある蜂場と、その周りの都道府県
    $aps = [];   // [持ち主, 蜂場id, 蜂場, 緯度, 経度, 都道府県]
    foreach (users_all() as $uid => $u) {
        if (($u['status'] ?? '') !== 'active' || !is_uid((string)$uid)) continue;
        $site = json_load(account_site_dir((string)$uid) . '/site.json');
        foreach ((array)($site['apiaries'] ?? []) as $aid => $a) {
            if (!is_array($a) || !($ll = apiary_latlng($a))) continue;
            $aps[] = [(string)$uid, (string)$aid, $a, $ll[0], $ll[1], bear_area_prefs($ll[0], $ll[1], (string)($a['address'] ?? ''), $start + B_BUDGET_S / 2)];   // 都道府県調べは時間の半分まで (くまっぷから取る時間を残す)
        }
    }
    $need = [];
    foreach ($aps as $x) foreach ($x[5] as $pc) $need[(string)$pc] = 1;

    // 2. 都道府県ごとに取る (3年分が古いものを先に。取れなかった直後は1時間あける)
    $todo = [];
    foreach (array_keys($need) as $pc) {
        $c = bear_pref_load((string)$pc);
        if ($now - (int)($c['full_try'] ?? 0) < B_INC_S) continue;
        if (!empty($c['job']) || $now - (int)$c['full'] >= B_FULL_S) $todo[] = [(string)$pc, true, (int)$c['full']];
        elseif ($now - (int)$c['inc'] >= B_INC_S) $todo[] = [(string)$pc, false, (int)$c['full']];
    }
    usort($todo, fn($a, $b) => [$b[1], $a[2]] <=> [$a[1], $b[2]]);
    foreach ($todo as [$pc, $full]) {
        if (microtime(true) >= $until) break;
        bear_fetch_pref($pc, $full, $until, $log);
    }

    // 3. 蜂場ごとの結果。周りの都道府県を一度も取り終えていない蜂場は、まだ数えない (全部が「新しい」になるので)
    $data = [];
    foreach (array_keys($need) as $pc) $data[(string)$pc] = bear_pref_load((string)$pc);
    $byOwner = [];
    foreach ($aps as $x) $byOwner[$x[0]][] = $x;
    $sent = 0;
    foreach ($byOwner as $owner => $list) {
        $path = account_site_dir($owner) . '/bear.json';
        $old = json_load($path);
        $out = [];
        foreach ($list as [, $aid, $a, $lat, $lng, $prefs]) {
            $ready = $prefs !== [];
            foreach ($prefs as $pc) if ((int)($data[(string)$pc]['full'] ?? 0) === 0) $ready = false;
            $k = bear_geo_key($lat, $lng);
            $prev = (is_array($old[$aid] ?? null) && ($old[$aid]['ll'] ?? '') === $k) ? $old[$aid] : null;   // 場所を変えたら初めから
            if (!$ready) { $out[$aid] = $prev ?: ['ll' => $k, 'pending' => 1, 'prefs' => $prefs]; continue; }   // まだ数えられない (ページで「取り込み中」と出す)
            [$nY, $nR, $near, $srcs] = bear_near($lat, $lng, $prefs, $now, $data);
            $seen = $prev ? array_flip((array)($prev['seen'] ?? [])) : null;
            $new = [];
            if ($seen !== null) {
                foreach ($near as $id => [$km, $p]) if (!isset($seen[$id]) && $now - (int)$p[0] <= B_NEW_DAYS * 86400) $new[$id] = [$km, $p];
            }
            $newAt = $new ? $now : (int)($prev['new_at'] ?? 0);
            $recent = [];   // ページに出す、5km以内の新しいもの5件
            foreach (array_slice($near, 0, 5, true) as $id => [$km, $p]) {
                $recent[] = ['id' => $id, 't' => (int)$p[0], 'km' => $km, 'dir' => bear_dir8($lat, $lng, (float)$p[1], (float)$p[2]),
                    'kind' => bear_kind_text($p), 'city' => (string)$p[7], 'src' => (string)$p[5]];
            }
            $out[$aid] = ['ll' => $k, 'at' => $now, 'n10' => $nY, 'n5' => $nR, 'new_at' => $newAt,
                'new_ids' => $new ? array_keys($new) : (array)($prev['new_ids'] ?? []), 'recent' => $recent, 'srcs' => $srcs,
                'seen' => array_keys($near), 'prefs' => $prefs];
            // お知らせ (デモ用アカウントには送らない)
            if ($new && $owner !== demo_uid()) {
                $name = (string)($a['name'] ?? '');
                $lines = []; $ss = [];
                foreach (array_slice($new, 0, B_MAIL_MAX, true) as $id => [$km, $p]) {
                    $lines[] = '・' . date('n/j H:i', (int)$p[0]) . ' ' . bear_kind_text($p) . ' (蜂場から約' . number_format($km, 1) . 'km ' . bear_dir8($lat, $lng, (float)$p[1], (float)$p[2])
                        . ((string)$p[7] !== '' ? '、' . $p[7] : '') . ")\n"
                        . ((string)$p[8] !== '' ? '  ' . mb_strimwidth(preg_replace('/\s+/u', ' ', (string)$p[8]), 0, 120, '…') . "\n" : '')
                        . '  ' . bear_point_url((string)$id);
                    if ((string)$p[5] !== '') $ss[(string)$p[5]] = 1;
                }
                if (count($new) > B_MAIL_MAX) $lines[] = 'ほか ' . (count($new) - B_MAIL_MAX) . '件';
                hpx_alert_queue('【HPX】クマの出没情報 (' . $name . 'の近く)',
                    '蜂場「' . $name . '」から ' . (int)B_RED_KM . "km 以内で、くまっぷに新しい情報が載りました。\n\n" . implode("\n", $lines) . "\n\n"
                    . '出典: くまっぷ (株式会社Xenon)' . ($ss ? '、データ提供: ' . implode('・', array_keys($ss)) : '') . "\n"
                    . '蜂場ページ: ' . site_url('apiary.php?' . http_build_query(['a' => $aid]) . '#a-' . $aid) . "\n");
                hpx_alert_flush(account_site_dir($owner), apiary_alert_to($owner, [$aid], 'bear'));
                $sent++;
            }
        }
        json_save($path, $out);
    }
    return count($aps) . ' apiaries, ' . count($need) . ' prefs' . ($log ? ' (' . implode(' / ', $log) . ')' : '') . ($sent ? ", $sent alerts" : '');
}

// ---- ページ用 ----
// 蜂場の結果 (無い・場所が違えば null)
function bear_state(string $siteDir, string $aid, array $a): ?array
{
    if (!bear_on() || !($ll = apiary_latlng($a))) return null;
    static $cache = [];
    $cache[$siteDir] ??= json_load($siteDir . '/bear.json');
    $b = $cache[$siteDir][$aid] ?? null;
    return is_array($b) && ($b['ll'] ?? '') === bear_geo_key($ll[0], $ll[1]) ? $b : null;
}

// マークの段階: 'blink' (赤の点滅) / 'red' / 'yel' / '' (出さない)
function bear_level(?array $b, int $now): string
{
    if (!$b || !empty($b['pending'])) return '';
    if ((int)($b['new_at'] ?? 0) > 0 && $now - (int)$b['new_at'] < bear_blink_days() * 86400 && (int)($b['n5'] ?? 0) > 0) return 'blink';
    if ((int)($b['n5'] ?? 0) > 0) return 'red';
    if ((int)($b['n10'] ?? 0) > 0) return 'yel';
    return '';
}

// 熊のマーク: 角の丸い四角 (色は CSS の color) に、熊の頭と肩の横向きの黒いシルエット (黄・赤とも黒、2026-11-25 TARO 決定) (2026-11-25 TARO 決定で 歩く熊→横顔→今の絵。左と下は四角のふちまで) (標識風。2026-11-25 TARO 決定 = 怖くも可愛くもない絵。
//   シルエットは TARO が用意した画像を線の形にしたもの。四角の内側を 0〜100 とした座標)
function bear_svg(): string
{
    return '<svg viewBox="0 0 24 24" width="1em" height="1em" aria-hidden="true"><clipPath id="bearmk-c"><rect x="1" y="1" width="22" height="22" rx="4"/></clipPath>'
        . '<rect x="1" y="1" width="22" height="22" rx="4" fill="currentColor"/>'
        . '<g clip-path="url(#bearmk-c)"><path transform="translate(1 1) scale(.22)" fill="#1F2328" d="M16.1 100.1C10.2 99.0 4.7 95.0 2.1 89.7C0.1 85.4 0.1 86.2 0.1 67.8L-0.0 51.6L1.6 50.3C3.1 49.1 3.6 48.5 2.8 48.8C1.8 49.2 3.3 47.4 5.2 46.1C5.9 45.6 6.1 45.3 5.9 45.2C5.6 45.0 6.1 44.4 8.2 42.7C9.2 41.8 9.2 41.8 8.6 41.8C8.1 41.8 8.0 41.7 8.0 41.5C8.1 41.2 9.7 39.9 11.4 38.8C12.3 38.1 12.3 38.1 11.9 38.0C11.6 38.0 11.4 37.8 11.4 37.7C11.4 37.5 13.4 36.1 15.0 35.4C16.1 34.8 16.1 34.7 15.8 34.5C15.2 34.0 16.0 33.6 18.3 32.8C21.0 32.0 22.0 31.5 21.4 31.4C21.1 31.2 21.2 31.1 22.2 30.5C23.4 29.7 26.3 27.5 26.5 27.2C26.6 27.1 26.4 27.1 26.0 27.2C25.7 27.3 25.5 27.2 25.5 27.1C25.5 26.8 28.5 24.5 29.8 23.7C30.5 23.3 30.7 22.8 30.0 23.1C29.3 23.3 29.5 22.8 30.3 22.2C31.0 21.7 31.7 21.3 33.1 20.8C33.4 20.7 33.5 20.6 33.2 20.5C32.6 20.1 33.2 19.9 34.9 19.8C36.6 19.7 36.6 19.7 36.5 20.2C36.3 20.7 36.8 23.8 37.1 24.2C37.1 24.3 37.2 23.3 37.2 22.0C37.2 17.8 39.0 14.5 42.2 13.0C42.8 12.7 43.5 12.3 43.7 12.2C45.0 11.6 48.9 12.6 49.2 13.6C49.3 13.9 49.5 14.1 49.7 14.2C50.0 14.2 50.2 14.6 50.3 15.1C50.4 15.6 50.6 16.1 50.7 16.2C50.9 16.5 51.1 17.9 51.3 19.8C51.4 21.0 51.5 21.3 51.7 21.2C51.9 21.1 52.4 21.3 52.9 21.6C54.1 22.3 56.6 23.3 60.9 24.7C66.7 26.6 69.6 28.3 73.1 31.8C75.4 34.1 75.7 34.6 75.7 36.4C75.7 38.7 76.6 39.5 82.3 42.8C84.0 43.8 86.2 45.2 87.3 45.9C88.5 46.7 90.1 47.6 91.3 48.0C95.2 49.4 95.6 51.2 92.8 54.6C92.4 55.1 91.7 56.3 91.3 57.2C90.4 59.1 89.7 59.8 88.2 60.1C87.2 60.4 87.2 60.4 87.2 61.1C87.5 63.2 82.8 64.7 76.2 64.4C72.5 64.3 72.3 64.3 70.6 64.9C69.4 65.3 68.7 65.4 68.6 65.2C68.5 65.1 68.1 65.3 67.4 65.9C66.2 67.0 66.1 67.0 66.1 66.2C66.1 65.9 66.0 65.7 65.9 65.7C65.8 65.7 65.7 65.8 65.7 65.9C65.7 66.0 65.3 66.6 64.9 67.4L64.0 68.7L63.6 68.2C63.3 67.7 63.3 67.7 62.8 68.8C62.2 70.2 61.9 70.4 61.4 69.9C61.1 69.4 60.7 69.7 60.7 70.4C60.7 70.5 60.6 70.9 60.4 71.3C60.2 71.8 60.2 71.9 59.8 71.5C59.2 71.0 59.1 71.0 59.0 72.3C59.0 73.2 58.9 73.5 58.7 73.5C58.4 73.4 58.4 73.7 58.5 74.9C58.7 76.4 58.6 76.5 57.9 76.1C57.3 75.8 57.4 76.1 58.3 77.9C59.1 79.4 59.2 79.8 58.4 79.6C57.7 79.4 58.0 80.0 60.0 82.2C61.7 84.1 63.9 86.8 64.0 87.3C64.1 87.4 64.0 87.5 63.8 87.5C63.3 87.5 63.3 87.5 64.4 89.2C65.6 90.8 65.7 91.1 65.0 91.2C64.6 91.2 64.6 91.3 65.2 92.3C65.6 93.0 65.9 93.6 65.8 93.8C65.8 94.0 66.0 94.7 66.3 95.3C66.9 96.5 67.0 96.9 66.5 96.7C66.0 96.5 66.0 96.7 67.0 98.4C67.5 99.2 68.0 99.9 68.0 100.0C68.0 100.2 17.2 100.3 16.1 100.1Z"/></g>'
        . '<rect x="1" y="1" width="22" height="22" rx="4" fill="none" stroke="#3b2a00" stroke-width=".7"/></svg>';
}

// マークの説明 (マウスを乗せたとき・読み上げ)
function bear_title(array $b, string $lv): string
{
    if ($lv === 'yel') return 'クマの出没情報: 過去3年・' . (int)B_YEL_KM . 'km以内に' . (int)$b['n10'] . '件';
    return ($lv === 'blink' ? '新しい情報があります。' : '') . 'クマの出没情報: 過去1年・' . (int)B_RED_KM . 'km以内に' . (int)$b['n5'] . '件';
}

// 蜂場タイルの見出し・蜂場一覧の名前に付けるマーク。押すと蜂場詳細のクマの欄へ ($toPage: 一覧から蜂場ごとのページへ)
function bear_mark_html(string $siteDir, string $aid, array $a, int $now, bool $toPage = false): string
{
    $b = bear_state($siteDir, $aid, $a);
    $lv = bear_level($b, $now);
    if ($lv === '') return '';
    $t = bear_title($b, $lv) . ' (出典: くまっぷ' . (($b['srcs'] ?? []) ? '、データ提供: ' . implode('・', (array)$b['srcs']) : '') . ')';
    if ($toPage) return '<a class="bearmk ' . $lv . '" href="apiary.php?' . h(http_build_query(['a' => $aid])) . '#bear-' . h($aid) . '" title="' . h($t) . '" aria-label="' . h($t) . '">' . bear_svg() . '</a>';
    return '<a class="bearmk ' . $lv . '" href="#bear-' . h($aid) . '" title="' . h($t) . '" aria-label="' . h($t) . '" '
        . 'onclick="var d=document.getElementById(\'bear-' . h($aid) . '\');if(d){var s=d.closest(\'details\');if(s)s.open=true;}">' . bear_svg() . '</a>';
}

// 蜂場詳細の「クマの出没情報」の欄
function bear_detail_html(string $siteDir, string $aid, array $a, int $now): string
{
    if (!bear_on()) return '';
    $id = ' id="bear-' . h($aid) . '"';
    // 一覧のマークから来たとき (#bear-<蜂場id>) は、蜂場詳細を開いておく
    $open = '<script>(function(){var d=document.getElementById("bear-' . h($aid) . '");if(d&&location.hash==="#bear-' . h($aid) . '"){var s=d.closest("details");if(s){s.open=true;d.scrollIntoView();}}})();</script>';
    if (!apiary_latlng($a)) return '<dt' . $id . '>クマの出没情報</dt><dd><span class="muted">「緯度・経度」を入れると、くまっぷの情報で近くの出没を数えます</span></dd>';
    $b = bear_state($siteDir, $aid, $a);
    if (!$b) return '<dt' . $id . '>クマの出没情報</dt><dd><span class="muted">くまっぷから取り込み中です (しばらくすると出ます)</span></dd>';
    if (!empty($b['pending'])) {
        $ps = array_map(fn($pc) => B_PREFS[sprintf('%02d', (int)$pc)] ?? '', (array)($b['prefs'] ?? []));
        return '<dt' . $id . '>クマの出没情報</dt><dd><span class="muted">' . ($ps ? 'くまっぷから取り込み中です (' . h(implode('・', $ps)) . '。しばらくすると出ます)'
            : 'どの都道府県か分かりませんでした。蜂場の「所在地」に都道府県名 (例: 秋田県…) を入れてください') . '</span></dd>';
    }
    $o = '<dt' . $id . '>クマの出没情報</dt><dd>'
        . '過去1年・' . (int)B_RED_KM . 'km以内 <b>' . (int)$b['n5'] . '件</b>、過去3年・' . (int)B_YEL_KM . 'km以内 <b>' . (int)$b['n10'] . '件</b>';
    if (bear_level($b, $now) === 'blink') $o .= '<br><span class="ng">' . h(date('n/j', (int)$b['new_at'])) . ' に ' . (int)B_RED_KM . 'km以内の新しい情報がありました</span>';
    if ($b['recent'] ?? []) {
        $o .= '<br><span class="muted">' . (int)B_RED_KM . 'km以内の最近の情報:</span><ul class="bearl">';
        $newIds = array_flip((array)($b['new_ids'] ?? []));
        foreach ((array)$b['recent'] as $r) {
            $o .= '<li>' . (isset($newIds[$r['id']]) && bear_level($b, $now) === 'blink' ? '<b class="ng">新</b> ' : '')
                . h(date('Y/n/j', (int)$r['t'])) . ' ' . h((string)$r['kind']) . ' <span class="muted">約' . h(number_format((float)$r['km'], 1)) . 'km ' . h((string)$r['dir'])
                . ((string)$r['city'] !== '' ? '・' . h((string)$r['city']) : '') . '</span> '
                . '<a href="' . h(bear_point_url((string)$r['id'])) . '" target="_blank" rel="noopener">くまっぷで見る</a></li>';
        }
        $o .= '</ul>';
    } elseif ((int)$b['n10'] === 0) {
        $o .= '<br><span class="muted">くまっぷに載っている情報の中には、近くの出没はありません (載っていない地域もあります)。</span>';
    }
    $o .= '<br><span class="muted" style="font-size:.85em">出典: <a href="' . h(bear_web()) . '" target="_blank" rel="noopener">くまっぷ</a> (株式会社Xenon)'
        . (($b['srcs'] ?? []) ? '、データ提供: ' . h(implode('・', (array)$b['srcs'])) : '') . '。'
        . '黄色のマーク = 過去3年・' . (int)B_YEL_KM . 'km以内に情報あり、赤色 = 過去1年・' . (int)B_RED_KM . 'km以内に情報あり、赤の点滅 = ' . (int)B_RED_KM . 'km以内の新しい情報 (' . bear_blink_days() . '日間)</span></dd>' . $open;
    return $o;
}

// 「サーバーの設定」に出す取り込み状況 (都道府県ごとの件数・取った時刻・エラー)
function bear_status_html(): string
{
    if (!bear_on()) return '<p class="muted">API キーを入れると、ここに取り込みの状況が出ます。</p>';
    $rows = [];
    foreach (glob(bear_dir() . '/pref_*.json') ?: [] as $p) {
        if (!preg_match('/pref_(\d\d)\.json$/', $p, $m) || !isset(B_PREFS[$m[1]])) continue;
        $c = bear_pref_load($m[1]);
        $st = (int)$c['full'] > 0 ? count((array)$c['pts']) . '件' : '<span class="muted">まだ</span>';
        if (!empty($c['job'])) $st .= ' <span class="muted">(取り直し中 ' . count((array)$c['job']['pts']) . '件まで)</span>';
        $rows[] = '<tr><td>' . h(B_PREFS[$m[1]]) . '</td><td>' . $st . '</td><td>' . ((int)$c['full'] ? h(date('n/j H:i', (int)$c['full'])) : '-') . '</td><td>'
            . ((int)$c['inc'] ? h(date('n/j H:i', (int)$c['inc'])) : '-') . '</td><td>' . (($c['err'] ?? '') !== '' ? '<span class="ng">' . h((string)$c['err']) . '</span>' : '') . '</td></tr>';
    }
    $geo = json_load(bear_dir() . '/geo.json');
    $ng = count(array_filter($geo, fn($g) => is_array($g) && empty($g['ok'])));
    return '<p style="margin-bottom:2px"><b>取り込みの状況</b> <span class="muted">(定期実行のたびに進みます。1回12秒まで)</span></p>'
        . ($rows ? '<table class="nw"><tr><th>都道府県</th><th>3年分の件数</th><th>3年分を取った</th><th>直近を取った</th><th>エラー</th></tr>' . implode('', $rows) . '</table>'
            : '<p class="muted">まだ取り込んでいません。緯度・経度のある蜂場があれば、次の定期実行から始まります。</p>')
        . ($ng ? '<p class="muted">周りの都道府県を国土地理院で調べきれていない蜂場の場所: ' . $ng . 'か所 (住所の都道府県だけで取り込み中。あとで調べ直します)</p>' : '');
}
