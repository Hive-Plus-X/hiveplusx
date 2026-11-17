<?php
// 天気予報 (Open-Meteo の無料 API。キー不要。気象庁などのモデルの予報を緯度・経度で返す)
//   蜂場の「緯度・経度」(map_q) が緯度,経度のときだけ出す。蜂場の持ち主のデータフォルダの site/weather/<蜂場id>.json に取っておく。
//   ページを速く出すため (2026-11-17):
//   ・定期実行 (hpx_cron.php) が古くなった予報を先に取っておく (weather_refresh_all)
//   ・ページでは、取り置きがあれば (少し古くても) それを使い、3時間より古いものだけ取り直す。取り直すときは全部の蜂場を同時に、短い待ち時間で
//   ・取れなかったときは 20分は取り直さない (Open-Meteo につながらないときに、毎回待たないように)
declare(strict_types=1);

const A_WX_CACHE_S = 3 * 3600;    // この時間より古ければ取り直す
const A_WX_RETRY_S = 20 * 60;     // 取れなかったときに、次に取りに行くまでの時間
const A_WX_TIMEOUT_S = 4;         // ページから取るときの待ち時間 (秒、全部の蜂場を同時に)

function apiary_latlng(array $a): ?array
{
    if (!preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', (string)($a['map_q'] ?? ''), $m)) return null;
    $lat = (float)$m[1]; $lng = (float)$m[2];
    return abs($lat) <= 90 && abs($lng) <= 180 ? [$lat, $lng] : null;
}

function wx_path(string $siteDir, string $aid): string { return $siteDir . '/weather/' . $aid . '.json'; }

// 取り置きを読む。場所が違えば null
function wx_cache(string $siteDir, string $aid, float $lat, float $lng): ?array
{
    $c = json_decode((string)@file_get_contents(wx_path($siteDir, $aid)), true);
    if (!is_array($c) || abs((float)($c['lat'] ?? 999) - $lat) >= 1e-4 || abs((float)($c['lng'] ?? 999) - $lng) >= 1e-4) return null;
    return $c;
}

// 取り直したほうがよいか (古い、日付が変わった、取り置きが無い)。取れなかった直後は取りに行かない
function wx_need(?array $c, int $now): bool
{
    if ($c && $now - (int)($c['failed'] ?? 0) < A_WX_RETRY_S) return false;
    return !$c || !is_array($c['daily']['time'] ?? null) || $now - (int)($c['fetched'] ?? 0) >= A_WX_CACHE_S || ($c['date'] ?? '') !== date('Y-m-d', $now);
}

// まとめて取る。$jobs: [[蜂場id, 緯度, 経度, 蜂場の持ち主の site フォルダ], ...]。同時に問い合わせ、$timeout 秒で打ち切る
function weather_fetch_many(array $jobs, int $timeout = A_WX_TIMEOUT_S): void
{
    if (!$jobs) return;
    $url = fn(float $lat, float $lng) => 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
        'latitude' => round($lat, 4), 'longitude' => round($lng, 4), 'timezone' => 'Asia/Tokyo', 'forecast_days' => 3,
        'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max']);
    $bodies = [];
    if (function_exists('curl_multi_init')) {
        $mh = curl_multi_init();
        $hs = [];
        foreach ($jobs as $i => [, $lat, $lng]) {
            $ch = curl_init($url($lat, $lng));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(3, $timeout)]);
            curl_multi_add_handle($mh, $ch);
            $hs[$i] = $ch;
        }
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 0.5);
        } while ($running && $st === CURLM_OK);
        foreach ($hs as $i => $ch) {
            $bodies[$i] = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 ? curl_multi_getcontent($ch) : false;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    } else {
        foreach ($jobs as $i => [, $lat, $lng]) $bodies[$i] = @file_get_contents($url($lat, $lng), false, stream_context_create(['http' => ['timeout' => $timeout]]));
    }
    $now = time();
    foreach ($jobs as $i => [$aid, $lat, $lng, $siteDir]) {
        $d = is_string($bodies[$i] ?? null) ? json_decode($bodies[$i], true) : null;
        $old = wx_cache($siteDir, $aid, $lat, $lng);
        if (is_array($d['daily']['time'] ?? null)) {
            $c = ['lat' => $lat, 'lng' => $lng, 'fetched' => $now, 'date' => date('Y-m-d', $now), 'daily' => $d['daily']];
        } else {
            $c = ($old ?? ['lat' => $lat, 'lng' => $lng]) + [];   // 取れなかった。前の予報は残し、しばらく取りに行かない
            $c['failed'] = $now;
        }
        @mkdir($siteDir . '/weather', 0755, true);
        @file_put_contents(wx_path($siteDir, $aid), json_encode($c));
    }
}

// ページ用: 取り直しが要る蜂場を、まとめて先に取る。$list: [[蜂場id, 蜂場の持ち主の site フォルダ, 蜂場], ...]
function weather_prefetch(array $list, int $timeout = A_WX_TIMEOUT_S): void
{
    $now = time();
    $jobs = [];
    foreach ($list as [$aid, $siteDir, $a]) {
        if (!($ll = apiary_latlng($a))) continue;
        if (wx_need(wx_cache($siteDir, (string)$aid, $ll[0], $ll[1]), $now)) $jobs[] = [(string)$aid, $ll[0], $ll[1], $siteDir];
    }
    weather_fetch_many($jobs, $timeout);
}

// 今日から3日分: [[日付, 天気コード, 最高, 最低, 降水確率], ...]。取り置きから読むだけ (取るのは weather_prefetch)。無ければ null
function weather_get(string $aid, float $lat, float $lng, string $siteDir): ?array
{
    $c = wx_cache($siteDir, $aid, $lat, $lng);
    if (!$c || !is_array($c['daily']['time'] ?? null)) return null;
    $out = [];
    foreach ($c['daily']['time'] as $i => $day) {
        if ($day < date('Y-m-d')) continue;   // 古い取り置きのときは今日より前を飛ばす
        $out[] = [(string)$day, (int)($c['daily']['weather_code'][$i] ?? -1), $c['daily']['temperature_2m_max'][$i] ?? null,
            $c['daily']['temperature_2m_min'][$i] ?? null, $c['daily']['precipitation_probability_max'][$i] ?? null];
    }
    return $out ? array_slice($out, 0, 3) : null;
}

// 定期実行用: すべてのアカウントの蜂場の予報を、古ければ取っておく (ページを開いたときに待たないように)
function weather_refresh_all(): int
{
    $list = [];
    foreach (users_all() as $uid => $u) {
        if (($u['status'] ?? '') !== 'active' || !is_uid((string)$uid)) continue;
        $dir = account_site_dir((string)$uid);
        $site = json_decode((string)@file_get_contents($dir . '/site.json'), true);
        foreach ((array)($site['apiaries'] ?? []) as $aid => $a) if (is_array($a)) $list[] = [(string)$aid, $dir, $a];
    }
    $n = count($list);
    foreach (array_chunk($list, 10) as $part) weather_prefetch($part, 10);   // 10か所ずつ同時に
    return $n;
}
