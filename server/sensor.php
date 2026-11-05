<?php
// HPXセンサページ (前の名前は dashboard.php): 機器(ハブ)ごとに、温湿度・巣箱の重量・電柵アラートを1ページで表示する。
//   receive.php と同じフォルダ(例 www/hpx/)に置き、ブラウザで https://<ドメイン>/hpx/sensor.php を開く。
//   ログインはアカウントごと(hpx_auth.php。蜂場管理ページと同じセッション)。見えるのは、ログインしたアカウントの HPX-Core だけ。
//   ・機器が複数あるときは、上の機器名のボタンで切り替える
//   ・グラフ(重量・温度・湿度)はそれぞれ期間を選べる(1日/3日/7日/30日/全期間。初めは3日)
//   ・データダウンロード: 選んでいる機器の全期間の記録を CSV で保存する(日時ごとに1行、センサーごとに列を並べる。重量は kg)
//   ・データクリア: 選んでいる機器の記録を消す。ファイルはデータフォルダの cleared/<日時>/ に移すだけなので、戻すこともできる
//   重量センサの較正(生の値 → kg)は HPX-Core の管理画面で行い、ここではゼロリセットと差し引く重さだけ入れる。較正の値はハブごとのデータフォルダに weight_cal.json として保存する。
declare(strict_types=1);
require __DIR__ . '/hpx_auth.php';

const D_TITLE = 'センサ - ' . BRAND_NAME;
const D_PERIODS = [1 => '1日', 3 => '3日', 7 => '7日', 30 => '30日', 0 => '全期間'];   // 0 = 全期間
const D_PERIOD_DEFAULT = 3;
const D_MAX_POINTS = 1200;           // グラフ1本あたりの点の数の上限(多いときは時間で区切って平均する)
const D_TH_MISSING_S = 3600;         // 温湿度計の記録がこの秒数来なければ「受信できていません」
const D_TH_LOW_V = 2.7;              // HPX-TH の電池電圧(V)がこれ未満なら「交換」(CR123A は終わり近くで急に下がる)
const D_W_MISSING_S = 5400;          // 重量の記録がこの秒数来なければ「受信できていません」(ノードは30分ごとに測る)
const D_W_LOW_BV = 2.8;              // 重量センサの電池電圧(V)がこれ未満なら「そろそろ交換」(2.7V未満で測定を止める)
const D_FENCE_DAYS = 7;              // 電柵の検出の履歴を出す日数
const D_FENCE_MAX = 100;             // 電柵の検出の履歴の最大行数
const D_MAIL_MAX = 50;               // メール送信履歴の最大行数
const D_COLORS = ['#1565c0', '#c62828', '#2e7d32', '#6a1b9a', '#ef6c00', '#00838f', '#5d4037', '#ad1457'];

function page_head(string $title, bool $refresh = false): void
{
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . '</title>' . brand_head() . '<style>'
        . 'body{font-family:system-ui,sans-serif;margin:0 auto;padding:12px 16px;background:#f6f7f8;color:#222;max-width:900px}'
        . 'h1{font-size:1.3em}h2{font-size:1.15em;margin-top:1.8em;border-bottom:2px solid #ccd;padding-bottom:4px}'
        . 'table{border-collapse:collapse;width:100%;background:#fff;font-size:.92em}'
        . 'th,td{border:1px solid #ddd;padding:6px 8px;text-align:left;vertical-align:top}th{background:#eef0f2}'
        . 'td.n{text-align:right;white-space:nowrap}'
        . '.ok{color:#11734b;font-weight:bold}.ng{color:#c62828;font-weight:bold}.warn{color:#b26a00;font-weight:bold}'
        . '.muted{color:#777;font-size:.9em}.card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:10px 12px;margin:8px 0}'
        . '.wrap{overflow-x:auto}input,button,select{font-size:1em;padding:6px 10px}'
        . 'input[type=number]{width:9em}details{margin:8px 0}summary{cursor:pointer}svg{background:#fff;border:1px solid #ddd;width:100%;height:auto}'
        . '.tabs a,.per a{display:inline-block;padding:6px 12px;margin:0 6px 6px 0;border:1px solid #bbc;border-radius:16px;background:#fff;color:#234;text-decoration:none}'
        . '.tabs a.on,.per a.on{background:#234;color:#fff;border-color:#234}.per a{padding:3px 10px;font-size:.9em}'
        . 'dl.info{display:grid;grid-template-columns:auto 1fr;gap:4px 12px;margin:0}dl.info dt{color:#555}dl.info dd{margin:0}'
        . '.danger{border-color:#e0b4b4;background:#fff7f7}'
        . 'body{padding-top:0}nav{position:sticky;top:0;background:#234;margin:0 -16px;padding:8px 16px;z-index:5;display:flex;flex-wrap:wrap;gap:4px 14px;align-items:center}'
        . 'nav a{color:#fff;text-decoration:none;padding:4px 2px}nav a.on{border-bottom:2px solid #ffd54f;font-weight:bold}nav .r{margin-left:auto;font-size:.85em}'
        . '.badge{display:inline-block;background:#c62828;color:#fff;border-radius:10px;padding:0 7px;font-size:.8em;margin-left:3px}.badge.soon{background:#b26a00}'
        . brand_css() . '</style>';
    if ($refresh) {
        // 5分ごとに読み直す(入力中は読み直さない)
        echo '<script>setInterval(function(){var a=document.activeElement;'
            . 'if(!a||(a.tagName!=="INPUT"&&a.tagName!=="SELECT"))location.reload();},300000);</script>';
    }
    echo '</head><body>';
}

// ---- ログイン(hpx_auth.php) ----
auth_require('sensor.php');   // $ME・$UID・$csrf が決まる
$notifyTo = account_notify($UID);   // 電柵などの通知メールの送り先

// ====================================================================
// 共通
// ====================================================================

function ago_text(int $epoch, int $now): string
{
    if ($epoch <= 0) return '-';
    $m = intdiv(max(0, $now - $epoch), 60);
    if ($m < 60) return $m . '分前';
    if ($m < 1440) return intdiv($m, 60) . '時間' . ($m % 60) . '分前';
    return intdiv($m, 1440) . '日前';
}

function rssi_text(?int $rssi): string
{
    if ($rssi === null || $rssi === 0) return '-';
    $lv = $rssi >= -60 ? ['ok', '強い'] : ($rssi >= -75 ? ['ok', 'ふつう'] : ($rssi >= -85 ? ['warn', '弱い'] : ['ng', 'とても弱い']));
    return h($rssi . ' dBm') . ' <span class="' . $lv[0] . '">' . $lv[1] . '</span>';
}

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
            $out['w'][$mac][] = [$ep, (int)$g($r, 'raw'), $num($g($r, 'spread')), $num($g($r, 'temp_c')), $num($g($r, 'batt_v'))];
        } elseif ($kind === 'fa') {
            if ($g($r, 'fence_state') === '') continue;
            $out['fa'][] = [$ep, $g($r, 'sensor') !== '' ? $g($r, 'sensor') : $mac, $mac, (int)$g($r, 'fence_state'), (int)$g($r, 'fence_events')];
        } else {
            $sht = $num($g($r, 'temp_c'));
            $t2 = $num($g($r, 'temp2_c'));   // HPX-TH のサーミスター(巣箱の中の温度)
            $t = $t2 ?? $sht;
            if ($t === null) continue;
            $t += th_temp_corr(dirname($csv), $mac, $t2 === null && $num($g($r, 'batt_v')) === null);   // Inkbird の一律補正と温湿度計ごとの補正
            $out['th'][$mac][] = [$ep, $t, th_hum_fix($num($g($r, 'humidity_pct')), dirname($csv), $mac), $num($g($r, 'battery_pct')), $num($g($r, 'batt_v')), $t2 !== null ? $sht : null];
        }
    }
    fclose($fh);
    foreach (['th', 'w'] as $k) foreach ($out[$k] as &$rows) usort($rows, fn($a, $b) => $a[0] <=> $b[0]);
    unset($rows);
    usort($out['fa'], fn($a, $b) => $a[0] <=> $b[0]);
    return $out;
}

// 点が多すぎるときは、時間で区切って平均する
function thin(array $pts, int $from, int $to): array
{
    if (count($pts) <= D_MAX_POINTS) return $pts;
    $bin = max(1, intdiv($to - $from, D_MAX_POINTS));
    $out = []; $k = null; $sx = 0; $sy = 0.0; $n = 0;
    foreach ($pts as [$e, $v]) {
        $b = intdiv($e - $from, $bin);
        if ($k !== null && $b !== $k) { $out[] = [intdiv($sx, $n), $sy / $n]; $sx = 0; $sy = 0.0; $n = 0; }
        $k = $b; $sx += $e; $sy += $v; $n++;
    }
    if ($n) $out[] = [intdiv($sx, $n), $sy / $n];
    return $out;
}

// 折れ線グラフ(SVG)と凡例。$series: [[名前, 色, [[epoch, 値], ...]], ...]  $unit: 縦軸の単位
function chart_svg(array $series, int $from, int $to, string $unit, float $minSpan, int $dec = 1): string
{
    $W = 600; $H = 280; $L = 50; $R = 10; $T = 14; $B = 30;
    $min = INF; $max = -INF;
    foreach ($series as $s) foreach ($s[2] as $p) { $min = min($min, $p[1]); $max = max($max, $p[1]); }
    if (!is_finite($min)) return '<p class="muted">この期間の記録はありません。</p>';
    if ($max - $min < $minSpan) { $mid = ($max + $min) / 2; $min = $mid - $minSpan / 2; $max = $mid + $minSpan / 2; }
    $pad = ($max - $min) * 0.08; $min -= $pad; $max += $pad;
    $x = fn(int $e) => $L + ($e - $from) / max(1, $to - $from) * ($W - $L - $R);
    $y = fn(float $v) => $T + ($max - $v) / ($max - $min) * ($H - $T - $B);
    $o = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" xmlns="http://www.w3.org/2000/svg" font-size="15" font-family="system-ui,sans-serif">';
    // 横線と目盛り
    $step = 50;
    foreach ([0.1, 0.2, 0.5, 1, 2, 5, 10, 20, 50] as $st) { $step = $st; if (($max - $min) / $st <= 6) break; }
    for ($v = ceil($min / $step) * $step; $v <= $max; $v += $step) {
        $yy = round($y($v), 1);
        $o .= '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '" stroke="#e5e7ea"/>'
            . '<text x="' . ($L - 6) . '" y="' . ($yy + 5) . '" text-anchor="end" fill="#666">' . h(rtrim(rtrim(number_format($v, 1), '0'), '.')) . '</text>';
    }
    // 縦線: 2日までは6時間ごと、8日までは毎日、40日までは7日ごと、それより長いと月ごと
    $days = ($to - $from) / 86400;
    $marks = [];
    if ($days <= 2) {
        for ($e = strtotime(date('Y-m-d', $from) . ' 00:00:00'); $e <= $to; $e += 21600) if ($e >= $from) $marks[] = [$e, date('H:i', $e)];
    } elseif ($days <= 40) {
        $dstep = $days <= 8 ? 1 : 7; $i = 0;
        for ($e = strtotime(date('Y-m-d', $from) . ' 00:00:00') + 86400; $e <= $to; $e += 86400, $i++) $marks[] = [$e, $i % $dstep === 0 ? date('n/j', $e) : ''];
    } else {
        $mstep = $days <= 400 ? 1 : 3;
        for ($e = strtotime(date('Y-m-01', $from) . ' 00:00:00'); $e <= $to; $e = strtotime('+' . $mstep . ' month', $e)) {
            if ($e >= $from) $marks[] = [$e, date('n', $e) === '1' ? date('Y/n', $e) : date('n', $e) . '月'];
        }
    }
    foreach ($marks as [$e, $lb]) {
        $xx = round($x($e), 1);
        $o .= '<line x1="' . $xx . '" x2="' . $xx . '" y1="' . $T . '" y2="' . ($H - $B) . '" stroke="#f0f1f3"/>';
        if ($lb !== '') $o .= '<text x="' . $xx . '" y="' . ($H - 10) . '" text-anchor="middle" fill="#666">' . h($lb) . '</text>';
    }
    $o .= '<text x="4" y="' . ($T + 4) . '" fill="#666">' . h($unit) . '</text>';
    $tipS = [];   // マウスを乗せたときに値を出すための記録(chart_tip_js)
    foreach ($series as $s) {
        $pts = []; $tp = [];
        foreach (thin($s[2], $from, $to) as $p) {
            $pts[] = round($x((int)$p[0]), 1) . ',' . round($y((float)$p[1]), 1);
            $tp[] = [(int)$p[0], round((float)$p[1], $dec), round($y((float)$p[1]), 1)];
        }
        if ($tp) $tipS[] = ['n' => (string)$s[0], 'u' => $unit, 'd' => $dec, 'c' => $s[1], 'p' => $tp];
        if (count($pts) === 1) {
            [$px, $py] = explode(',', $pts[0]);
            $o .= '<circle cx="' . $px . '" cy="' . $py . '" r="3" fill="' . $s[1] . '"/>';
        } elseif ($pts) {
            $o .= '<polyline fill="none" stroke="' . $s[1] . '" stroke-width="2" stroke-linejoin="round"'
                . (($s[3] ?? '') !== '' ? ' stroke-dasharray="' . h((string)$s[3]) . '"' : '') . ' points="' . implode(' ', $pts) . '"/>';
        }
    }
    $o .= '</svg>';
    $data = ['W' => $W, 'x0' => $L, 'x1' => $W - $R, 'y0' => $T, 'y1' => $H - $B, 'f' => $from, 't' => $to, 's' => $tipS];
    $o = '<div class="tipw" data-tip="' . h(json_encode($data, JSON_UNESCAPED_UNICODE)) . '">' . $o . '</div><p class="muted">';
    foreach ($series as $s) $o .= '<span style="color:' . $s[1] . '">' . (($s[3] ?? '') !== '' ? '┅' : '■') . '</span> ' . h($s[0]) . '　';
    return $o . '</p>';
}

// ====================================================================
// 重量の較正
// ====================================================================
// weight_cal.json: { "<mac>": { zero: ゼロ点の生の値, per_kg: 1kgあたりの生の値, offset_kg: 差し引く重さ,
//                              cal_raw, cal_g: 較正に使った値と重さ, updated: 更新時刻 } }
function cal_load(string $dir): array
{
    return wcal_with_inherit($dir);   // ほかのハブで較正済みのセンサは引き継ぐ (hpx_auth.php)
}

function cal_save(string $dir, array $cal): bool
{
    $tmp = $dir . '/weight_cal.json.tmp';
    if (@file_put_contents($tmp, json_encode($cal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false) return false;
    return @rename($tmp, $dir . '/weight_cal.json');
}

// 生の値 → kg。較正が済んでいなければ null
function to_kg(int $raw, ?array $c): ?float
{
    if (!$c || !isset($c['zero'], $c['per_kg']) || (float)$c['per_kg'] == 0.0) return null;
    return ($raw - (float)$c['zero']) / (float)$c['per_kg'] - (float)($c['offset_kg'] ?? 0);
}

function kg_text(?float $kg): string
{
    return $kg === null ? '-' : number_format($kg, 2) . ' kg';
}

// ====================================================================
// 電柵
// ====================================================================

function state_html(int $st): string
{
    $cls = $st === 0 ? 'ok' : ($st === 1 ? 'ng' : 'warn');
    return '<span class="' . $cls . '">' . h(hpx_alert_state_text($st)) . '</span>';
}

// 記録の並びから「状態が変わった」「記録の間に低下した」ところだけを取り出す(新しい順)
function fence_events(array $rows): array
{
    $out = [];
    $prev = [];
    foreach ($rows as $r) {
        [, , $mac, $st, $ev] = $r;
        $p = $prev[$mac] ?? null;
        if ($p === null) {
            if ($st !== 0) $out[] = [$r, $st === 1 ? '電圧低下' : '常時点灯', $st];
        } elseif ($st !== $p[3]) {
            if ($st === 0) $out[] = [$r, '正常に戻った', 0];
            else $out[] = [$r, $st === 1 ? '電圧低下になった' : '常時点灯になった', $st];
        } elseif ($st === 0 && $ev > $p[4]) {
            $out[] = [$r, '記録の間に一時的に低下 (' . ($ev - $p[4]) . '回)', 1];
        }
        $prev[$mac] = $r;
    }
    return array_slice(array_reverse($out), 0, D_FENCE_MAX);
}

function read_mail_log(string $path): array
{
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return [];
    return array_slice(array_reverse($lines), 0, D_MAIL_MAX);
}

// ====================================================================
// 機器(ハブ)の一覧と、選んでいる機器
// ====================================================================

$now = time();
$hubs = [];   // id => [dir, st, name]
foreach (glob(account_hubs_dir($UID) . '/*/state.json') ?: [] as $statePath) {
    $st = json_decode((string)@file_get_contents($statePath), true);
    if (!is_array($st)) continue;
    $id = basename(dirname($statePath));
    $hubs[$id] = ['dir' => dirname($statePath), 'st' => $st, 'name' => (string)($st['name'] ?? '') !== '' ? (string)$st['name'] : $id];
}
uasort($hubs, fn($a, $b) => strcmp($a['name'], $b['name']));
$hubId = (string)($_GET['hub'] ?? $_POST['hub'] ?? '');
if (!isset($hubs[$hubId])) $hubId = (string)(array_key_first($hubs) ?? '');
$hub = $hubs[$hubId] ?? null;

// グラフごとの期間(pt=温度 ph=湿度 pw=重量)
$per = [];
foreach (['pt', 'ph', 'pw'] as $k) {
    $v = (int)($_GET[$k] ?? $_POST[$k] ?? D_PERIOD_DEFAULT);
    $per[$k] = array_key_exists($v, D_PERIODS) ? $v : D_PERIOD_DEFAULT;
}
function self_url(array $change = [], string $anchor = ''): string
{
    global $hubId, $per;
    return 'sensor.php?' . http_build_query(array_merge(['hub' => $hubId], $per, $change)) . ($anchor !== '' ? '#' . $anchor : '');
}

// ====================================================================
// データダウンロード(CSV、全期間)
// ====================================================================

if (isset($_GET['download']) && $hub) {
    // 日時ごとに1行。列は 温湿度計(温度・湿度)を台ごとに → 重量センサ(kg)を台ごとに → 電柵アラート(正常/異常)
    $data = read_ble($hub['dir'] . '/ble.csv', 0);
    if (!$data['th'] && !$data['w'] && !$data['fa']) {
        page_head(D_TITLE);
        echo '<p>この機器の記録はまだありません。 <a href="' . h(self_url()) . '">戻る</a></p></body></html>';
        exit;
    }
    $cal = cal_load($hub['dir']);
    // 列の順: 状態に登録されている順(=画面の表と同じ)、記録にだけあるセンサーは後ろに付ける
    $names = [];
    $order = ['th' => [], 'w' => []];
    foreach ((is_array($hub['st']['sensors'] ?? null) ? $hub['st']['sensors'] : []) as $mac => $s) {
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

    // 同じ区切りの記録を1行にまとめる(分単位)
    $rows = [];
    $slot = fn(int $ep) => intdiv($ep, 60) * 60;
    $head = ['日時'];
    foreach ($order['th'] as $i => $mac) {
        $head[] = $nm($mac) . ' 温度(℃)';
        $head[] = $nm($mac) . ' 湿度(%)';
        foreach ($data['th'][$mac] as $r) {
            $rows[$slot($r[0])][2 * $i] = number_format($r[1], 1, '.', '');
            $rows[$slot($r[0])][2 * $i + 1] = $r[2] === null ? '' : number_format($r[2], 1, '.', '');
        }
    }
    $base = 2 * count($order['th']);
    foreach ($order['w'] as $i => $mac) {
        $c = $cal[$mac] ?? null;
        $calOk = $c && isset($c['per_kg']);
        $head[] = $nm($mac) . ($calOk ? ' 重量(kg)' : ' 重量(生の値・未較正)');
        foreach ($data['w'][$mac] as $r) {
            $v = $calOk ? to_kg($r[1], $c) : null;
            $rows[$slot($r[0])][$base + $i] = $calOk ? ($v === null ? '' : number_format($v, 2, '.', '')) : (string)$r[1];
        }
    }
    $faCol = $base + count($order['w']);
    if ($data['fa']) {
        $head[] = '電柵アラート';
        $multi = count(array_unique(array_column($data['fa'], 2))) > 1;
        foreach ($data['fa'] as $r) {
            [$ep, $fname, , $fst] = $r;
            $t = $slot($ep);
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

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hpx_' . (preg_replace('/[^A-Za-z0-9_-]/', '', $hub['name']) ?: $hubId) . '_' . date('Ymd_Hi', $now) . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");   // Excel で文字化けしないように(UTF-8 の印)
    fputcsv($out, $head);
    $n = count($head) - 1;
    foreach ($rows as $t => $vals) {
        $line = [date('Y-m-d H:i', $t)];
        for ($j = 0; $j < $n; $j++) $line[] = $vals[$j] ?? '';
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

// ====================================================================
// 操作(較正の保存、テストメール、データクリア)
// ====================================================================

$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
    $act = (string)$_POST['action'];
    $err = '';
    $done = '';
    $anchor = '';
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $err = 'ページを開き直してから、もう一度操作してください。';
    } elseif (!$hub) {
        $err = '機器が見つかりません。';
    } elseif ($act === 'testmail') {
        hpx_alert_queue('【HPX】テストメール', "ダッシュボードから送ったテストメールです。\n送信時刻: " . date('Y-m-d H:i:s', $now) . "\n");
        hpx_alert_flush($hub['dir'], $notifyTo);
        $done = 'tested'; $anchor = 'fence';
    } elseif ($act === 'clear') {
        // 記録・状態の履歴・メール送信履歴を cleared/<日時>/ へ移す。
        // state.json と較正は残す(ハブが同じ記録を送り直しても二重に保存しないため)
        if (!password_verify((string)($_POST['pw2'] ?? ''), (string)($ME['pw'] ?? ''))) {
            sleep(2);
            $err = 'パスワードが違います。データは消していません。';
        } elseif (trim((string)($_POST['confirm_name'] ?? '')) !== $hub['name']) {
            $err = '機器名が違います。データは消していません。';
        } else {
            $dest = $hub['dir'] . '/cleared/' . date('Ymd-His', $now);
            $files = array_values(array_filter(array_merge([$hub['dir'] . '/ble.csv', $hub['dir'] . '/mail.log'],
                glob($hub['dir'] . '/reports-*.jsonl') ?: []), 'is_file'));
            $lock = @fopen($hub['dir'] . '/.lock', 'c');   // receive.php の書き込みと重ならないように
            if ($lock) flock($lock, LOCK_EX);
            if ($files && !@mkdir($dest, 0700, true) && !is_dir($dest)) $err = '移す先のフォルダを作れませんでした。データは消していません。';
            foreach ($err === '' ? $files : [] as $f) {
                if (!@rename($f, $dest . '/' . basename($f))) { $err = basename($f) . ' を移せませんでした。'; break; }
            }
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
            $done = 'cleared';
        }
    } elseif ($act === 'remove') {
        // この HPX-Core をアカウントから外す。記録と蜂場・巣箱の紐づけは残し (巣箱のグラフに出し続ける)、
        // 見張りのメールを止め、蜂場・巣箱の選択肢から外す (.retired)。またこのアカウントのトークンで届けば元に戻る
        if (!password_verify((string)($_POST['pw2'] ?? ''), (string)($ME['pw'] ?? ''))) {
            sleep(2);
            $err = 'パスワードが違います。外していません。';
        } elseif (trim((string)($_POST['confirm_name'] ?? '')) !== $hub['name']) {
            $err = '機器名が違います。外していません。';
        } elseif (!@file_put_contents($hub['dir'] . '/.retired', date('Y-m-d H:i:s', $now))) {
            $err = '保存できませんでした(データフォルダに書き込めません)。';
        } else {
            @unlink($hub['dir'] . '/.hub_missing');
            $done = 'removed';
        }
    } elseif ($act === 'unretire') {
        @unlink($hub['dir'] . '/.retired');
        $done = 'unretired';
    } elseif ($act === 'th_offset') {
        // 温湿度計ごとの温度(℃)・湿度(%)の補正。th_cal.json に保存し、表示・グラフ・警報で読むときに足す
        $mac = (string)($_POST['mac'] ?? '');
        $off = trim((string)($_POST['offset_c'] ?? ''));
        $offH = trim((string)($_POST['offset_h'] ?? ''));
        if (!isset($hub['st']['sensors'][$mac])) $err = '温湿度計が見つかりません。';
        elseif ($off !== '' && (!is_numeric($off) || abs((float)$off) > 10)) $err = '温度の補正は -10〜10 ℃ の数字で入れてください。';
        elseif ($offH !== '' && (!is_numeric($offH) || abs((float)$offH) > 30)) $err = '湿度の補正は -30〜30 % の数字で入れてください。';
        else {
            $all = th_cal_load($hub['dir']);
            $e = array_filter(['offset_c' => $off === '' ? 0.0 : round((float)$off, 2), 'offset_h' => $offH === '' ? 0.0 : round((float)$offH, 1)], fn($v) => $v != 0.0);
            if (!$e) unset($all[$mac]);
            else $all[$mac] = $e + ['updated' => date('Y-m-d H:i:s', $now)];
            $tmp = $hub['dir'] . '/th_cal.json.tmp';
            if (@file_put_contents($tmp, json_encode($all ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false || !@rename($tmp, $hub['dir'] . '/th_cal.json'))
                $err = '保存できませんでした(データフォルダに書き込めません)。';
        }
        $done = 'th_saved'; $anchor = 'th';
    } else {
        $mac = strtoupper((string)($_POST['mac'] ?? ''));
        if (!preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $mac)) $err = '重量センサが見つかりません。';
        if ($err === '') {
            $all = cal_load($hub['dir']);
            $c = $all[$mac] ?? [];
            $num = fn(string $k) => is_numeric(trim((string)($_POST[$k] ?? ''))) ? (float)trim((string)$_POST[$k]) : null;
            if ($act === 'zero') {
                // ゼロリセット: 最新の記録の生の値をゼロ点にする(較正は HPX-Core の管理画面で行う)
                $wr = read_ble($hub['dir'] . '/ble.csv', $now - 7 * 86400)['w'][$mac] ?? [];
                if (!isset($c['per_kg'])) $err = '先に HPX-Core の管理画面で較正してください。';
                elseif (!$wr) $err = 'この1週間の記録がないので、ゼロリセットできません。';
                else {
                    if (!isset($c['zero_before'])) $c['zero_before'] = $c['zero'];   // 解除で戻すゼロ点(何度リセットしても最初の値)
                    $c['zero'] = (int)$wr[count($wr) - 1][1];
                    $c['zero_reset'] = $now;   // receive.php: これより前に較正した HPX-Core の値でゼロ点を戻さない
                }
            } elseif ($act === 'zero_undo') {
                // ゼロリセット解除: リセットする前のゼロ点に戻す
                if (!isset($c['zero_before'])) $err = 'ゼロリセットしていません。';
                else {
                    $c['zero'] = (int)$c['zero_before'];
                    unset($c['zero_before'], $c['zero_reset']);
                }
            } elseif ($act === 'offset') {
                $off = $num('offset_kg');
                $c['offset_kg'] = $off === null ? 0 : $off;
            } else {
                $err = '不明な操作です。';
            }
            if ($err === '') {
                $c['updated'] = date('Y-m-d H:i:s', $now);
                $all[$mac] = $c;
                if (!cal_save($hub['dir'], $all)) $err = '保存できませんでした(データフォルダに書き込めません)。';
            }
        }
        $done = 'saved'; $anchor = 'weight';
    }
    if ($err === '') {
        header('Location: ' . self_url([$done => 1], $anchor));
        exit;
    }
    $msg = $err;
}

// ====================================================================
// 表示
// ====================================================================

page_head(D_TITLE, true);
// 蜂場管理ページ(hpx_site.php)と同じメニュー。作業記録には期限切れ・今日の タスクの数を出す
$todo = json_decode((string)@file_get_contents(account_site_dir($UID) . '/works.json'), true);
$over = 0; $due = 0;
foreach ((is_array($todo['todos'] ?? null) ? $todo['todos'] : []) as $t) {
    if (!empty($t['done']) || (string)($t['due'] ?? '') === '') continue;
    if ($t['due'] < date('Y-m-d', $now)) $over++;
    elseif ($t['due'] === date('Y-m-d', $now)) $due++;
}
echo '<nav>' . brand_nav_logo() . '<a href="apiary.php">蜂場</a><a href="hive.php">巣箱</a><a href="memo.php">メモ</a><a href="task.php">タスク'
    . ($over ? '<span class="badge" title="期限切れの タスク">' . $over . '</span>' : '')
    . ($due ? '<span class="badge soon" title="今日までの タスク">' . $due . '</span>' : '') . '</a><a href="work.php">記録</a>'
    . auth_nav_right('sensor.php') . '</nav>';
echo '<p class="muted" style="text-align:right;margin:0">' . h(date('Y-m-d H:i', $now)) . ' 現在 (5分ごとに自動で更新)</p>';
if ($msg !== '') echo '<p class="ng">' . h($msg) . '</p>';
if (isset($_GET['saved'])) echo '<p class="ok">重量センサの設定を保存しました。</p>';
if (isset($_GET['th_saved'])) echo '<p class="ok">温度・湿度の補正を保存しました。</p>';
if (isset($_GET['tested'])) echo '<p class="ok">テストメールを送りました。結果は「メール送信履歴」に出ます(届くまで数分かかることがあります)。</p>';
if (isset($_GET['cleared'])) echo '<p class="ok">データをクリアしました。</p>';
if (isset($_GET['removed'])) echo '<p class="ok">' . h($hub['name'] ?? '') . ' をこのアカウントから外しました。これまでの記録は、紐づけた巣箱・蜂場のグラフにそのまま出ます。</p>';
if (isset($_GET['unretired'])) echo '<p class="ok">' . h($hub['name'] ?? '') . ' を元に戻しました。</p>';
if (!$hub) {
    echo '<p>まだ機器からのデータがありません。</p></body></html>';
    exit;
}

// ---- 機器名(選択) ----
echo '<p class="muted" style="margin:.4em 0 .2em;font-size:.85em">HPX-Core名</p><div class="tabs">';
foreach ($hubs as $id => $hb) {
    echo '<a href="' . h('sensor.php?' . http_build_query(['hub' => $id] + $per)) . '"' . ($id === $hubId ? ' class="on"' : '') . '>' . h($hb['name'])
        . (is_file($hb['dir'] . '/.retired') ? ' <span style="font-size:.8em;opacity:.7">(外した)</span>' : '') . '</a>';
}
echo '</div>';

$st = $hub['st'];
$dir = $hub['dir'];
$byKind = ['th' => [], 'w' => [], 'fa' => []];
foreach ((is_array($st['sensors'] ?? null) ? $st['sensors'] : []) as $mac => $s) {
    if (!is_array($s)) continue;
    $k = (string)($s['kind'] ?? 'th');
    $byKind[isset($byKind[$k]) ? $k : 'th'][(string)$mac] = $s;
}
// 読む範囲: 3つのグラフの期間のうち長いもの(全期間ならすべて)と、電柵の履歴の日数
$since = in_array(0, $per, true) ? 0 : $now - max(D_FENCE_DAYS, ...array_values($per)) * 86400;
$data = read_ble($dir . '/ble.csv', $since);
$range = fn(int $p): int => $p === 0 ? ($data['first'] > 0 ? min($data['first'], $now - 3600) : $now - 86400) : $now - $p * 86400;
$perLinks = function (string $key, string $anchor) use ($per): string {
    $o = '<div class="per">';
    foreach (D_PERIODS as $v => $lb) $o .= '<a href="' . h(self_url([$key => $v], $anchor)) . '"' . ($per[$key] === $v ? ' class="on"' : '') . '>' . h($lb) . '</a>';
    return $o . '</div>';
};

// ---- 機器の情報 ----
// 設置場所: 蜂場管理ページ(apiary.php)でこのハブを紐づけた蜂場
$place = ''; $placeId = '';
$siteJson = json_decode((string)@file_get_contents(account_site_dir($UID) . '/site.json'), true);
foreach ((is_array($siteJson['apiaries'] ?? null) ? $siteJson['apiaries'] : []) as $aid => $ap) {
    if (in_array($hubId, (array)($ap['hubs'] ?? []), true)) { $place = (string)($ap['name'] ?? ''); $placeId = (string)$aid; break; }
}
// センサの紐づけ先(蜂場・巣箱ページで選んだもの)。キーは "ハブid/mac"。温湿度・重量は巣箱、電柵アラートは蜂場
$where = [];
$siteAp = is_array($siteJson['apiaries'] ?? null) ? $siteJson['apiaries'] : [];
foreach ($siteAp as $aid => $ap) foreach ((array)($ap['fences'] ?? []) as $k) $where[(string)$k] = [(string)($ap['name'] ?? ''), 'apiary.php#a-' . $aid];
foreach ((is_array($siteJson['hives'] ?? null) ? $siteJson['hives'] : []) as $hid => $hv) {
    $aid = (string)($hv['apiary'] ?? '');
    foreach ((array)($hv['sensors'] ?? []) as $k) $where[(string)$k] = [(string)($siteAp[$aid]['name'] ?? '') . ' / ' . (string)($hv['name'] ?? ''), 'hive.php?' . http_build_query(['a' => $aid, 'h' => $hid])];
}
$whereTd = function (string $mac) use ($where, $hubId): string {
    $w = $where[$hubId . '/' . $mac] ?? null;
    return '<td>' . ($w ? '<a href="' . h($w[1]) . '">' . h($w[0]) . '</a>' : '<span class="muted">-</span>') . '</td>';
};
$lastRx = (int)($st['last_rx'] ?? 0);
$rxOk = $lastRx > 0 && $now - $lastRx < (int)($HPX_HUB_MISSING_S ?? 3600);
$vbat = isset($st['vbat']) && is_numeric($st['vbat']) && (float)$st['vbat'] > 0 ? number_format((float)$st['vbat'], 2) . ' V' : '';
$fw = trim((string)($st['fw'] ?? '') . ((int)($st['build'] ?? 0) ? ' (build ' . (int)$st['build'] . ')' : ''));
echo '<div class="card"><dl class="info">'
    . '<dt>設置場所</dt><dd>' . ($place !== '' ? '<a href="hive.php?' . h(http_build_query(['a' => $placeId])) . '">' . h($place) . '</a>'
        : '<span class="muted">未設定 (<a href="apiary.php">蜂場ページ</a>でこの機器を蜂場に紐づけます)</span>') . '</dd>'
    . '<dt>最終受信日時</dt><dd>' . h($lastRx ? date('Y-m-d H:i', $lastRx) . ' (' . ago_text($lastRx, $now) . ')' : '-') . ' '
        . ($rxOk ? '<span class="ok">受信中</span>' : '<span class="ng">届いていません</span>') . '</dd>'
    . '<dt>ファームウェア</dt><dd>' . h($fw !== '' ? $fw : '-') . '</dd>'
    . '<dt>電池電圧</dt><dd>' . ($vbat !== '' ? h($vbat) : '<span class="muted">未対応 (後で実装予定)</span>') . '</dd>'
    . '<dt>Wi-Fiの電波</dt><dd>' . rssi_text(isset($st['rssi']) && is_numeric($st['rssi']) ? (int)$st['rssi'] : null) . '</dd>'
    . '</dl></div>';

// ---- 温湿度計の表 ----
echo '<h2 id="th">温湿度計</h2>';
$tS = []; $hS = [];
if (!$byKind['th']) {
    echo '<p class="muted">温湿度計の記録はまだありません。</p>';
} else {
    echo '<div class="wrap"><table><tr><th>名前</th><th>巣箱</th><th>温度</th><th>湿度</th><th>電池</th><th>最後の記録</th></tr>';
    $i = 0;
    $thForms = '';
    $ft = $range($per['pt']);
    $fhm = $range($per['ph']);
    foreach ($byKind['th'] as $mac => $s) {
        $nm = (string)(($s['name'] ?? '') !== '' ? $s['name'] : $mac);
        $rows = $data['th'][$mac] ?? [];
        $last = $rows ? $rows[count($rows) - 1] : null;
        $color = D_COLORS[$i++ % count(D_COLORS)];
        $le = (int)($s['last_epoch'] ?? 0);
        echo '<tr><td style="white-space:nowrap"><span style="color:' . $color . '">■</span> ' . h($nm) . '</td>'
            . $whereTd((string)$mac)
            . '<td class="n">' . h($last ? number_format($last[1], 1) . ' ℃' : '-')
            . ($last && ($last[5] ?? null) !== null ? '<br><small class="muted">HPX-TH本体温度 ' . h(number_format($last[5], 1)) . ' ℃</small>' : '') . '</td>'
            . '<td class="n">' . h($last && $last[2] !== null ? number_format($last[2], 1) . ' %' : '-') . '</td>'
            . '<td class="n">' . ($last && $last[3] !== null ? h((int)$last[3] . ' %') . ($last[3] <= 20 ? ' <span class="warn">交換</span>' : '')
                : ($last && ($last[4] ?? null) !== null ? h(number_format($last[4], 2) . ' V') . ($last[4] < D_TH_LOW_V ? ' <span class="warn">交換</span>' : '') : '-')) . '</td>'
            . '<td>' . h($le ? date('m-d H:i', $le) . ' (' . ago_text($le, $now) . ')' : '-')
            . ($le > 0 && $now - $le >= D_TH_MISSING_S ? '<br><span class="ng">受信できていません</span>' : '') . '</td></tr>';
        $tp = []; $hp = [];
        foreach ($rows as $r) {
            if ($r[0] >= $ft) $tp[] = [$r[0], $r[1]];
            if ($r[0] >= $fhm && $r[2] !== null) $hp[] = [$r[0], $r[2]];
        }
        $tS[] = [$nm, $color, $tp];
        $hS[] = [$nm, $color, $hp];

        // 温度・湿度の補正の入力(表の下にまとめて出す)
        $model = (string)($s['model'] ?? '');
        $offC = (float)(th_cal_load($dir)[$mac]['offset_c'] ?? 0);
        $offH = (float)(th_cal_load($dir)[$mac]['offset_h'] ?? 0);
        $fmt = fn(float $v) => $v != 0.0 ? rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') : '';
        $thForms .= '<form method="post" class="thoff" style="margin:0 0 10px"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="hub" value="' . h($hubId) . '">'
            . '<input type="hidden" name="mac" value="' . h((string)$mac) . '"><input type="hidden" name="action" value="th_offset">';
        foreach ($per as $k => $v) $thForms .= '<input type="hidden" name="' . $k . '" value="' . $v . '">';
        $thForms .= '<span style="color:' . $color . '">■</span> <b>' . h($nm) . '</b>' . (isset(TH_MODEL_NAMES[$model]) ? ' <span class="muted">' . h(TH_MODEL_NAMES[$model]) . '</span>' : '')
            . '<br>温度 <input type="number" step="0.1" min="-10" max="10" name="offset_c" value="' . h($fmt($offC)) . '" placeholder="0" style="width:5em"> ℃　'
            . '湿度 <input type="number" step="0.1" min="-30" max="30" name="offset_h" value="' . h($fmt($offH)) . '" placeholder="0" style="width:5em"> % '
            . '<button type="submit">保存</button> <span class="muted">上方補正は数値のみ、下方補正はマイナスをつけて入力してください。</span></form>';
    }
    echo '</table></div>';
    echo '<details' . (isset($_GET['th_saved']) ? ' open' : '') . '><summary>温度・湿度の補正</summary><div class="card"><p class="muted">温湿度計ごとに、温度と湿度の補正ができます。機種ごとの初期補正はしてあります（温度：Inkbird ' . h(number_format(TH_INKBIRD_OFFSET_C, 1)) . '℃、湿度：SwitchBot 温湿度計 ' . h(sprintf('%+.1f', TH_MODEL_OFFSET_H['switchbot'])) . '%、SwitchBot 防水温湿度計 ' . h(sprintf('%+.1f', TH_MODEL_OFFSET_H['switchbot_outdoor'])) . '%）。さらに補正が必要な場合に使ってください。</p>' . $thForms . '</div></details>';
}

// ---- 重量の表 ----
echo '<h2 id="weight">重量</h2>';
$wS = [];
$cal = cal_load($dir);
if (!$byKind['w']) {
    echo '<p class="muted">重量センサの記録はまだありません。</p>';
} else {
    echo '<div class="wrap"><table><tr><th>名前</th><th>巣箱</th><th>重さ</th><th>24時間</th><th>電池</th><th>最後の記録</th></tr>';
    $i = 0;
    $calForms = '';
    $fw = $range($per['pw']);
    foreach ($byKind['w'] as $mac => $s) {
        $nm = (string)(($s['name'] ?? '') !== '' ? $s['name'] : $mac);
        $c = $cal[$mac] ?? null;
        $calOk = $c && isset($c['per_kg']);
        $r = $data['w'][$mac] ?? [];
        $last = $r ? $r[count($r) - 1] : null;
        $color = D_COLORS[$i++ % count(D_COLORS)];
        $kg = $last ? to_kg($last[1], $c) : null;
        // 24時間前との差(22時間以上前の記録があるときだけ)
        $diff = null;
        if ($last && $kg !== null) {
            foreach ($r as $p) {
                if ($p[0] < $last[0] - 86400) continue;
                if ($p[0] <= $last[0] - 79200) $diff = $kg - (float)to_kg($p[1], $c);
                break;
            }
        }
        $le = (int)($s['last_epoch'] ?? 0);
        $failEp = (int)($s['w_fail_epoch'] ?? 0);
        $note = '';
        if ($failEp > 0 && $failEp >= $le) {
            $note = '<br><span class="ng">' . ((int)($s['w_fail'] ?? 0) === 1 ? '電池交換' : 'HX711 読めず') . '</span>';
        } elseif ($last && $last[4] !== null && $last[4] < D_W_LOW_BV) {
            $note = '<br><span class="warn">そろそろ交換</span>';
        }
        $seen = max($le, $failEp);
        echo '<tr><td style="white-space:nowrap"><span style="color:' . $color . '">■</span> ' . h($nm) . $note . '</td>'
            . $whereTd((string)$mac)
            . '<td class="n">' . ($last ? ($calOk ? '<b>' . h(kg_text($kg)) . '</b>' : '<span class="warn">較正前</span>')
                . '<br><span class="muted">生の値 ' . h((string)$last[1]) . '</span>' : '-') . '</td>'
            . '<td class="n">' . ($diff === null ? '-' : '<span class="' . ($diff >= 0 ? 'ok' : 'warn') . '">' . h(($diff >= 0 ? '+' : '') . number_format($diff, 2)) . ' kg</span>') . '</td>'
            . '<td class="n">' . h($last && $last[4] !== null ? number_format($last[4], 2) . ' V' : '-') . '</td>'
            . '<td>' . h($le ? date('m-d H:i', $le) . ' (' . ago_text($le, $now) . ')' : '-')
            . ($seen > 0 && $now - $seen >= D_W_MISSING_S ? '<br><span class="ng">受信できていません</span>' : '') . '</td></tr>';

        // 較正の入力(表の下にまとめて出す)
        $hid = '<input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="hub" value="' . h($hubId) . '">'
            . '<input type="hidden" name="mac" value="' . h($mac) . '">';
        foreach ($per as $k => $v) $hid .= '<input type="hidden" name="' . $k . '" value="' . $v . '">';
        $f = '<details' . ($calOk ? '' : ' open') . '><summary>' . h($nm) . ' の調整' . ($calOk ? '' : ' <span class="warn">(較正がまだです)</span>') . '</summary><div class="card">';
        if ($calOk) {
            $f .= '<p class="muted">今の較正値: ゼロ点 ' . h((string)$c['zero']) . ' ・ 1kg あたり ' . h(number_format((float)$c['per_kg'], 1))
                . (isset($c['cal_g']) ? ' (' . h(number_format((float)$c['cal_g'])) . 'g で較正)' : (!empty($c['common']) ? ' (全台共通の値)' : ''))
                . (($c['src'] ?? '') === 'HPX-core' ? ' ・ HPX-Core の管理画面で較正' : '')
                . (!empty($c['offset_kg']) ? ' ・ 差し引く重さ ' . h(number_format((float)$c['offset_kg'], 2)) . ' kg' : '')
                . ' ・ ' . h((string)($c['updated'] ?? '')) . '</p>';
        }
        if (!$calOk) $f .= '<p>較正は HPX-Core の管理画面で行います。較正すると、次の送信でここに届いて kg で表示します。</p>';
        if ($calOk) {
            $f .= '<form method="post" onsubmit="return confirm(' . h(json_encode($nm . ' の今の重さを 0 kg にしますか?', JSON_UNESCAPED_UNICODE)) . ')"><input type="hidden" name="action" value="zero">' . $hid
                . '<p><b>1. ゼロリセット</b> <span class="muted">現在の状態を0kgとします。</span><br>'
                . '<button type="submit">ゼロリセット</button></p></form>'
                . (isset($c['zero_before']) ? '<form method="post" onsubmit="return confirm(' . h(json_encode($nm . ' のゼロリセットを解除して、リセットする前に戻しますか?', JSON_UNESCAPED_UNICODE)) . ')"><input type="hidden" name="action" value="zero_undo">' . $hid
                    . '<p style="margin-top:-.4em"><button type="submit" style="background:#fff;color:#2F5D45;border:1px solid #2F5D45">ゼロリセット解除</button> <span class="muted">' . h(date('m-d H:i', (int)($c['zero_reset'] ?? 0))) . ' にリセットしました</span></p></form>' : '')
                . '<form method="post"><input type="hidden" name="action" value="offset">' . $hid
                . '<p><b>2. 差し引く重さ</b> <span class="muted">巣箱に乗せる重しなどの重さを入れると、巣箱だけの重さを表示できます。</span><br>'
                . '<input type="number" step="0.01" name="offset_kg" value="' . h(isset($c['offset_kg']) ? (string)$c['offset_kg'] : '0') . '"> kg '
                . '<button type="submit">保存</button></p></form>';
        }
        $calForms .= $f . '</div></details>';

        if ($calOk) {
            $pts = [];
            foreach ($r as $p) if ($p[0] >= $fw) $pts[] = [$p[0], (float)to_kg($p[1], $c)];
            $wS[] = [$nm, $color, $pts];
        }
    }
    echo '</table></div>' . $calForms;
}

// ---- アラート状況の表 ----
echo '<h2 id="fence">電柵アラート</h2>';
if (!$byKind['fa']) {
    echo '<p class="muted">電柵アラート (HPX-A) の記録はまだありません。</p>';
} else {
    $nodeLimit = (int)($HPX_ALERT_MISSING_S ?? 3600);
    echo '<div class="wrap"><table><tr><th>名前</th><th>蜂場</th><th>状態</th><th>電圧低下の回数(通算)</th><th>電池</th><th>最後の記録</th></tr>';
    foreach ($byKind['fa'] as $mac => $s) {
        $le = (int)($s['last_epoch'] ?? 0);
        echo '<tr><td>' . h((string)(($s['name'] ?? '') !== '' ? $s['name'] : $mac)) . '</td>'
            . $whereTd((string)$mac)
            . '<td>' . (isset($s['fence_state']) ? state_html((int)$s['fence_state']) : '-')
            . ($le > 0 && $now - $le >= $nodeLimit ? '<br><span class="ng">受信できていません</span>' : '') . '</td>'
            . '<td class="n">' . h((string)($s['fence_events'] ?? '-')) . '</td>'
            . '<td class="n">' . (isset($s['fence_bv']) ? h(number_format((float)$s['fence_bv'], 2) . ' V')
                . (!empty($s['batt_notified']) ? ' <span class="warn">交換</span>' : '') : '-') . '</td>'
            . '<td>' . h($le ? date('m-d H:i', $le) . ' (' . ago_text($le, $now) . ')' : '-') . '</td></tr>';
    }
    echo '</table></div>';
    $ev = fence_events(array_values(array_filter($data['fa'], fn($r) => $r[0] >= $now - D_FENCE_DAYS * 86400)));
    echo '<details><summary><b>検出の履歴</b> (直近' . D_FENCE_DAYS . '日、' . count($ev) . '件)</summary>';
    if (!$ev) {
        echo '<p class="muted">この期間に電圧低下はありません。</p>';
    } else {
        echo '<div class="wrap"><table><tr><th>時刻</th><th>名前</th><th>内容</th></tr>';
        foreach ($ev as [$r, $text, $lvl]) {
            $cls = $lvl === 0 ? 'ok' : ($lvl === 1 ? 'ng' : 'warn');
            echo '<tr><td>' . h(date('m-d H:i', $r[0])) . '</td><td>' . h($r[1]) . '</td><td><span class="' . $cls . '">' . h($text) . '</span></td></tr>';
        }
        echo '</table></div>';
    }
    echo '</details>';
}
$mails = read_mail_log($dir . '/mail.log');
$fails = count(array_filter($mails, fn($ln) => (explode(' ', $ln, 4)[2] ?? '') !== 'sent'));
echo '<details><summary><b>メール送信履歴</b> (新しい順、最大' . D_MAIL_MAX . '件)'
    . ($mails ? ' 最新 ' . h(substr($mails[0], 5, 11)) : '') . ($fails ? ' <span class="ng">失敗 ' . $fails . '件</span>' : '') . '</summary>';
echo '<form method="post" style="margin:8px 0"><input type="hidden" name="action" value="testmail"><input type="hidden" name="csrf" value="' . h($csrf) . '">'
    . '<input type="hidden" name="hub" value="' . h($hubId) . '"><button type="submit">テストメールを送る</button> <span class="muted">送り先: ' . h($notifyTo !== '' ? $notifyTo : '(未設定)') . ' <a href="account.php#notify">変える</a>' . '</span></form>';
if (!$mails) {
    echo '<p class="muted">まだメールを送っていません。</p>';
} else {
    echo '<div class="wrap"><table><tr><th>時刻</th><th>結果</th><th>件名</th></tr>';
    foreach ($mails as $ln) {
        // 形式: "YYYY-MM-DD HH:MM:SS sent|FAILED 件名"
        $parts = explode(' ', $ln, 4);
        if (count($parts) < 4) continue;
        echo '<tr><td>' . h($parts[0] . ' ' . substr($parts[1], 0, 5)) . '</td>'
            . '<td>' . ($parts[2] === 'sent' ? '<span class="ok">送信</span>' : '<span class="ng">失敗</span>') . '</td>'
            . '<td>' . h($parts[3]) . '</td></tr>';
    }
    echo '</table></div>';
}
echo '</details>';

// ---- グラフ ----
echo '<h2 id="g-weight">重量のグラフ</h2>' . $perLinks('pw', 'g-weight');
echo $wS ? chart_svg($wS, $range($per['pw']), $now, 'kg', 0.5, 2)
    : '<p class="muted">' . ($byKind['w'] ? '較正が済むと表示します。' : '重量センサの記録はまだありません。') . '</p>';
echo '<h2 id="g-temp">温度のグラフ</h2>' . $perLinks('pt', 'g-temp');
echo $tS ? chart_svg($tS, $range($per['pt']), $now, '℃', 2) : '<p class="muted">温湿度計の記録はまだありません。</p>';
echo '<h2 id="g-hum">湿度のグラフ</h2>' . $perLinks('ph', 'g-hum');
echo $hS ? chart_svg($hS, $range($per['ph']), $now, '%', 5, 0) : '<p class="muted">温湿度計の記録はまだありません。</p>';

// ---- データダウンロード・データクリア ----
echo '<h2 id="data">データ</h2>';
$size = @filesize($dir . '/ble.csv');
echo '<div class="card"><b>データダウンロード</b><br><span class="muted">' . h($hub['name']) . ' の全期間の記録を CSV で保存します'
    . ($size ? ' (約 ' . h(number_format(max(1, (int)round($size / 1024)))) . ' KB' . ($data['first'] ? '、' . h(date('Y-m-d', $data['first'])) . ' から' : '') . ')' : '')
    . '。日時ごとに1行で、温湿度計・重量・電柵アラートを列に並べます。</span><br>'
    . '<a href="' . h(self_url(['download' => 1])) . '"><button type="button">CSV をダウンロード</button></a></div>';
echo '<details><summary><b>データクリア</b></summary><div class="card danger">'
    . '<p>' . h($hub['name']) . ' の記録 (温湿度・重量・電柵)、状態の履歴、メール送信履歴を消します。<b>先に CSV をダウンロードしてください。</b><br>'
    . '<span class="muted">消したファイルは、サーバーのデータフォルダの中の cleared/ に日時の名前で残ります。重量の較正と機器の設定は消えません。</span></p>'
    . '<form method="post" onsubmit="return window.confirm(\'' . h($hub['name']) . ' のデータをクリアします。よろしいですか?\')">'
    . '<input type="hidden" name="action" value="clear"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="hub" value="' . h($hubId) . '">'
    . '<p>確認のため、機器名 <b>' . h($hub['name']) . '</b> を入れてください<br><input type="text" name="confirm_name" autocomplete="off" required></p>'
    . '<p>ログインのパスワード<br><input type="password" name="pw2" autocomplete="current-password" required></p>'
    . '<button type="submit">データをクリア</button></form></div></details>';
if (is_file($dir . '/.retired')) {
    echo '<div class="card"><b>外した HPX-Core</b><br><span class="muted">' . h(date('Y-m-d H:i', (int)@filemtime($dir . '/.retired'))) . ' に外しました。これまでの記録は、紐づけた巣箱・蜂場のグラフにそのまま出ます。'
        . '「届いていません」のメールは出しません。このアカウントのトークンでまた届くと、自動で元に戻ります。</span>'
        . '<form method="post"><input type="hidden" name="action" value="unretire"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="hub" value="' . h($hubId) . '">'
        . '<button type="submit">元に戻す</button></form></div>';
} else {
    echo '<details><summary><b>この HPX-Core をアカウントから外す</b></summary><div class="card danger">'
        . '<p>' . h($hub['name']) . ' をほかのアカウントのトークンに付け替えたときなどに使います。「届いていません」のメールが止まり、蜂場・巣箱の設定の選択肢からも消えます。<br>'
        . '<span class="muted">これまでの記録と、巣箱・蜂場への紐づけはそのまま残すので、過去のグラフは見られます。センサページにも「外した」として残ります。</span></p>'
        . '<form method="post" onsubmit="return window.confirm(\'' . h($hub['name']) . ' をこのアカウントから外します。よろしいですか?\')">'
        . '<input type="hidden" name="action" value="remove"><input type="hidden" name="csrf" value="' . h($csrf) . '"><input type="hidden" name="hub" value="' . h($hubId) . '">'
        . '<p>確認のため、機器名 <b>' . h($hub['name']) . '</b> を入れてください<br><input type="text" name="confirm_name" autocomplete="off" required></p>'
        . '<p>ログインのパスワード<br><input type="password" name="pw2" autocomplete="current-password" required></p>'
        . '<button type="submit">アカウントから外す</button></form></div></details>';
}
echo chart_tip_js() . '</body></html>';
