<?php
// カメラページ: HPX-CAM (蜂場カメラ) が送ってきた写真を見る。
//   ・月の一覧: 日ごとに、12時にいちばん近い写真の小さい版をカレンダーの形で並べる。日付を指定して飛べる
//   ・1日の写真: 大きな写真と横向きのスライドバーで1枚ずつ見る。▶ でパラパラ漫画のように続けて再生する (速さを選べる)
//   ・カメラの設定 (持ち主だけ): 名前、置いている蜂場、撮影の間隔と時間帯 (カメラへの返事で伝え、次の撮影から使う)、
//     大きな変化のお知らせ (知らせない/感度、hpx_cam_watch.php)。最近の大きな変化の記録は見られる人みんなに出す
//   見られるのは、自分のカメラと、共有された蜂場に紐づいたカメラ。写真の受け口は cam_upload.php、保存の形は hpx_cam.php
declare(strict_types=1);
require __DIR__ . '/hpx_site.php';
require_once __DIR__ . '/hpx_cam_watch.php';

$cams = cams_visible();
$cid = strtoupper((string)($_GET['c'] ?? $_POST['c'] ?? ''));
if (!isset($cams[$cid])) $cid = (string)(array_key_first($cams) ?? '');
$cam = $cid !== '' ? $cams[$cid] : null;
$WD = ['日', '月', '火', '水', '木', '金', '土'];

// ---- 写真を返す ?img=<カメラ>&d=YYYYMMDD&f=HHMMSS (&t=1 で小さい版、&dl=1 で保存) ----
if (isset($_GET['img'])) {
    $d = (string)($_GET['d'] ?? ''); $f = (string)($_GET['f'] ?? '');
    if (!$cam || strtoupper((string)$_GET['img']) !== $cid || !preg_match('/^\d{8}$/', $d) || !preg_match('/^\d{6}$/', $f)) { http_response_code(404); exit; }
    $path = $cam['dir'] . '/' . $d . '/' . $f . '.jpg';
    if (!empty($_GET['t']) && is_file($cam['dir'] . '/' . $d . '/' . $f . '_t.jpg')) $path = $cam['dir'] . '/' . $d . '/' . $f . '_t.jpg';
    if (!is_file($path)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=2592000');   // 写真は書き換えないので長くとっておいてよい
    if (!empty($_GET['dl'])) header('Content-Disposition: attachment; filename="HPX-CAM_' . $cid . '_' . $d . '_' . $f . '.jpg"');
    readfile($path);
    exit;
}

// ---- 設定の保存 (持ち主だけ) ----
if (is_post()) {
    if (!$cam || !$cam['own']) redirect('camera.php', 'このカメラの設定は持ち主だけが変えられます。');
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'save') {
        $own = site_of_owner(site_load(), $UID)['apiaries'];
        $ownH = site_of_owner(site_load(), $UID)['hives'];
        $aid = (string)($_POST['apiary'] ?? '');
        $hvid = (string)($_POST['hive'] ?? '');
        if (isset($ownH[$hvid])) $aid = (string)($ownH[$hvid]['apiary'] ?? '');   // 巣箱を選んだら、蜂場はその巣箱の蜂場
        else $hvid = '';
        if (!isset($own[$aid])) { $aid = ''; $hvid = ''; }
        $iv = (int)($_POST['iv'] ?? CAM_IV_DEFAULT);
        $h0 = max(0, min(23, (int)($_POST['h0'] ?? CAM_H0_DEFAULT)));
        $h1 = max(1, min(24, (int)($_POST['h1'] ?? CAM_H1_DEFAULT)));
        if ($h1 <= $h0) redirect('camera.php?c=' . $cid . '#set', '撮影する時間帯は「から」より「まで」を後にしてください。');
        $name = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', '', (string)($_POST['name'] ?? '')) ?? ''), 0, 30);
        $watch = (int)($_POST['watch'] ?? 2);
        if (!isset(CW_LEVELS[$watch])) $watch = 2;
        cam_update($UID, $cid, function (array $x) use ($aid, $hvid, $iv, $h0, $h1, $name, $watch) {
            $x['name'] = $name; $x['apiary'] = $aid; $x['hive'] = $hvid; $x['watch'] = $watch;
            if ($watch === 0) unset($x['wp']);
            $x['iv'] = in_array($iv, CAM_IVS, true) ? $iv : CAM_IV_DEFAULT; $x['h0'] = $h0; $x['h1'] = $h1;
            return $x;
        });
        redirect('camera.php?c=' . $cid . '#set', 'カメラの設定を保存しました。撮影の間隔・時間帯は、カメラが次に写真を送ったあとから変わります。');
    }
    if ($act === 'remove' && ($_POST['ok'] ?? '') === '1') {
        // 消さずに cams_removed/ へ移す (間違えたときはサーバーのファイルを戻せば元に戻る)
        $to = account_dir($UID) . '/cams_removed';
        if (!is_dir($to)) @mkdir($to, 0700, true);
        @rename($cam['dir'], $to . '/' . $cid . '_' . date('Ymd_His'));
        redirect('camera.php', cam_name($cam['c'], $cid) . ' をこのアカウントから外しました。また写真が届くと、新しいカメラとして出ます。');
    }
    redirect('camera.php?c=' . $cid);
}

page_head('カメラ - ' . S_TITLE, 'camera.php');
echo flash_html();
echo '<style>'
    . '.ctabs a{display:inline-block;padding:6px 12px;margin:0 6px 6px 0;border:1px solid #bbc;border-radius:16px;background:#fff;color:#234;text-decoration:none}.ctabs a.on{background:#2F5D45;color:#fff;border-color:#2F5D45}'
    . '.mnav{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:.6em 0}.mnav .ttl{font-size:1.2em;font-weight:bold;margin:0 4px}.mnav a.b,.mnav button{display:inline-block;padding:5px 12px;border:1px solid #bbc;border-radius:6px;background:#fff;color:#234;text-decoration:none;cursor:pointer}'
    . '.mnav a.b.off{color:#bbb;border-color:#ddd;pointer-events:none}.mnav form{display:flex;gap:4px;align-items:center;margin:0 0 0 auto}'
    . '.ccal{table-layout:fixed;width:100%}.ccal th{text-align:center;padding:3px;font-size:.85em}.ccal td{padding:2px;vertical-align:top;height:auto}.ccal .w0,.ccal th.w0{color:#c62828}.ccal .w6,.ccal th.w6{color:#1565c0}'
    . '.ccal a{display:block;text-decoration:none;color:inherit}.ccal img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:3px;background:#eef0f2}'
    . '.ccal .dn{font-size:.8em;display:flex;justify-content:space-between;padding:0 2px}.ccal .dn i{font-style:normal;color:#888}.ccal .no{aspect-ratio:4/3;background:#f3f4f2;border-radius:3px}.ccal td.today .dn b{background:#F2A900;color:#000;border-radius:8px;padding:0 5px}'
    . '.player{background:#111;border-radius:6px;padding:6px;text-align:center}.player img{max-width:100%;max-height:70vh;display:block;margin:0 auto;border-radius:3px}'
    . '.ptime{font-size:1.6em;font-weight:bold;text-align:center;margin:.3em 0 0;font-variant-numeric:tabular-nums}'
    . '#sl{width:100%;height:32px;margin:.2em 0;accent-color:#2F5D45}'
    . '.pbtn{display:flex;flex-wrap:wrap;gap:6px;justify-content:center;align-items:center;margin:.3em 0}.pbtn button{min-width:52px;padding:8px 12px;border:1px solid #2F5D45;border-radius:6px;background:#fff;color:#2F5D45;font-size:1.05em;cursor:pointer}.pbtn button.main{background:#2F5D45;color:#fff;min-width:96px}'
    . '.strip{display:flex;gap:4px;overflow-x:auto;padding:4px 0 8px;scroll-snap-type:x proximity}.strip a{flex:0 0 auto;width:96px;text-align:center;font-size:.75em;color:#444;text-decoration:none;scroll-snap-align:center}.strip img{width:96px;height:72px;object-fit:cover;border-radius:3px;border:3px solid transparent;display:block;background:#eef0f2}.strip a.on img{border-color:#F2A900}.strip a.on{font-weight:bold;color:#000}'
    . '.latest{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}.latest a{text-decoration:none;color:inherit}.latest img{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:4px;display:block;background:#eef0f2}'
    . '</style>';

if (!$cams) {
    echo '<h1>カメラ</h1><div class="card"><p>まだ HPX-CAM から写真が届いていません。</p>'
        . '<p>HPX-CAM (または写真を中継する HPX-Core) に、次の送り先と、マイアカウントの「HPX-Core の接続」にある機器用トークン (HPX-Core と同じもの) を入れると、写真が届いたときにここに出ます。</p>'
        . '<p>送り先: <code>' . h(site_url('cam_upload.php')) . '</code></p></div></body></html>';
    exit;
}

$c = $cam['c'];
$camName = cam_name($c, $cid);
$site = site_load();
$aid = (string)($c['apiary'] ?? '');
$apName = (string)($site['apiaries'][$aid]['name'] ?? '');
$hvName = (string)($site['hives'][(string)($c['hive'] ?? '')]['name'] ?? '');
if ($hvName !== '') $apName .= ' / ' . $hvName;
// 巣箱ページから来たとき (?h=<巣箱id>、そのカメラを置いた巣箱だけ): 「巣箱のページへ戻る」を出し、ほかのカメラへの切り替えは出さない
$fromHive = (string)($_GET['h'] ?? '');
if ($fromHive === '' || (string)($c['hive'] ?? '') !== $fromHive || !isset($site['hives'][$fromHive])) $fromHive = '';
$keep = $fromHive !== '' ? ['h' => $fromHive] : [];
$self = fn(array $q) => 'camera.php?' . http_build_query(['c' => $cid] + $keep + $q);
$keepField = $fromHive !== '' ? '<input type="hidden" name="h" value="' . h($fromHive) . '">' : '';
$img = fn(string $ymd, string $f, bool $t = false) => 'camera.php?' . http_build_query(array_filter(['c' => $cid, 'img' => $cid, 'd' => $ymd, 'f' => $f, 't' => $t ? 1 : 0]));

// カメラの切り替え
if ($fromHive !== '') {
    $fh = $site['hives'][$fromHive];
    echo '<p style="margin:.6em 0"><a class="admbtn" style="display:inline-block;padding:6px 14px;border-radius:6px;background:#2F5D45;color:#fff;text-decoration:none" href="'
        . h('hive.php?' . http_build_query(['a' => (string)($fh['apiary'] ?? ''), 'h' => $fromHive]) . '#h-' . $fromHive) . '">← ' . h((string)($fh['name'] ?? '巣箱')) . ' のページへ戻る</a></p>';
} elseif (count($cams) > 1) {
    echo '<div class="ctabs" style="margin-top:.6em">';
    foreach ($cams as $k => $x) echo '<a href="camera.php?c=' . h($k) . '"' . ($k === $cid ? ' class="on"' : '') . '>' . h(cam_name($x['c'], $k)) . '</a>';
    echo '</div>';
}
echo '<h1 style="margin-bottom:.2em">📷 ' . h($camName) . ($apName !== '' ? ' <span class="muted" style="font-size:.7em;font-weight:normal">' . h($apName) . '</span>' : '') . '</h1>';

// 状態 (最後に届いた・電池・経路)
$last = (int)($c['last'] ?? 0);
$iv = cam_iv($c); [$h0, $h1] = cam_hours($c);
$late = $last > 0 && $now - $last > max(3 * $iv * 60, 3600) && (int)date('G', $now) >= $h0 && (int)date('G', $now) < $h1;
$via = (string)($c['via'] ?? '');
$hubs = hpx_hubs();
$st = '最後に届いた写真 ' . ($last ? h(date('n/j H:i', $last)) . ' (' . h(ago_text($last, $now)) . ')' : '-')
    . ($late ? ' <span class="ng">届いていません</span>' : '')
    . ' ・ ' . ($via !== '' ? 'HPX-Core ' . h($hubs[$via]['name'] ?? $via) . ' 経由' : 'ルーターから直接')
    . (isset($c['bv']) ? ' ・ 電池 ' . h(number_format((float)$c['bv'], 2)) . 'V' : '')
    . (isset($c['w']) ? ' ・ ' . (int)$c['w'] . '×' . (int)$c['h'] : '')
    . ' ・ ' . $iv . '分ごと ' . $h0 . '〜' . $h1 . '時';
echo '<p class="muted" style="margin:0 0 .4em">' . $st . '</p>';

// 最近の大きな変化 (hpx_cam_watch.php の記録。知らせたものだけ、3日以内)
$wl = array_values(array_filter((array)($c['wlog'] ?? []), fn($r) => !empty($r[2]) && (int)$r[0] > $now - 3 * 86400));
if ($wl) {
    echo '<div style="margin:.2em 0 .6em;padding:6px 10px;border:1px solid #f0c36d;border-radius:6px;background:#fff8e1">⚠ <b>カメラ画像に大きな変化がありました</b>';
    foreach (array_slice($wl, 0, 5) as $r) {
        $u = $self(['d' => date('Y-m-d', (int)$r[0]), 'f' => date('His', (int)$r[0])]);
        echo '<br><a href="' . h($u) . '">' . h(date('n/j H:i', (int)$r[0])) . '</a>' . ((string)($r[3] ?? '') !== '' ? ' <span class="muted">' . h((string)$r[3]) . '</span>' : '');
    }
    echo '</div>';
}

$dp = (string)($_GET['d'] ?? '');
$days = cam_days($cam['dir']);
if ($dp !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dp, $mm) && checkdate((int)$mm[2], (int)$mm[3], (int)$mm[1])) {
    // ================= 1日の写真 =================
    $ymd = $mm[1] . $mm[2] . $mm[3];
    $list = cam_day_list($cam['dir'], $ymd);
    $prev = ''; $next = '';
    foreach ($days as $d) { if ($d < $ymd) $prev = $d; elseif ($d > $ymd && $next === '') $next = $d; }
    $fmt = fn(string $d) => substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2);
    $ts = strtotime($dp);
    echo '<div class="mnav">'
        . '<a class="b' . ($prev === '' ? ' off' : '') . '" href="' . h($prev !== '' ? $self(['d' => $fmt($prev)]) : '#') . '">‹ 前の日</a>'
        . '<span class="ttl">' . date('Y年n月j日', $ts) . '(' . $WD[(int)date('w', $ts)] . ')</span>'
        . '<a class="b' . ($next === '' ? ' off' : '') . '" href="' . h($next !== '' ? $self(['d' => $fmt($next)]) : '#') . '">次の日 ›</a>'
        . '<a class="b" href="' . h($self(['m' => substr($dp, 0, 7)])) . '">📅 ' . (int)substr($dp, 5, 2) . '月の一覧</a>'
        . '<form method="get" action="camera.php"><input type="hidden" name="c" value="' . h($cid) . '">' . $keepField . '<input type="date" name="d" value="' . h($dp) . '" aria-label="日付"><button>移動</button></form>'
        . '</div>';
    if (!$list) {
        echo '<div class="card">この日の写真はありません。</div>';
    } else {
        // はじめに出す写真: 指定 (?f=HHMMSS)、なければ12時にいちばん近いもの
        $f0 = (string)($_GET['f'] ?? '');
        $start = array_search($f0, $list, true);
        if ($start === false) $start = (int)array_search(cam_noon($cam['dir'], $ymd), $list, true);
        $js = ['u' => $img($ymd, '__F__'), 'ut' => $img($ymd, '__F__', true), 'f' => $list, 's' => $start, 'dl' => $img($ymd, '__F__') . '&dl=1'];
        echo '<div class="player"><img id="ph" src="' . h($img($ymd, $list[$start])) . '" alt="' . h($camName) . ' の写真"></div>'
            . '<div class="ptime" id="tm"></div>'
            . '<input type="range" id="sl" min="0" max="' . (count($list) - 1) . '" value="' . $start . '" aria-label="写真を選ぶ">'
            . '<div class="pbtn"><button type="button" id="b0" title="最初">⏮</button><button type="button" id="bp" title="1枚前">◀</button>'
            . '<button type="button" class="main" id="pl">▶ 再生</button><button type="button" id="bn" title="1枚後">▶</button><button type="button" id="b9" title="最後">⏭</button>'
            . '<label class="muted">速さ <select id="sp"><option value="1">1枚/秒</option><option value="2">2枚/秒</option><option value="4" selected>4枚/秒</option><option value="8">8枚/秒</option></select></label>'
            . '<label class="muted"><input type="checkbox" id="lp"> くり返す</label></div>'
            . '<p class="muted" style="text-align:center;margin:.2em 0">' . count($list) . '枚 ・ <a id="dl" href="#">この写真を保存</a> ・ キーボードの ← → でも動かせます</p>'
            . '<div class="strip" id="st">';
        foreach ($list as $i => $f) {
            echo '<a href="#" data-i="' . $i . '"><img src="' . h($img($ymd, $f, true)) . '" alt="" loading="lazy">' . substr($f, 0, 2) . ':' . substr($f, 2, 2) . '</a>';
        }
        echo '</div>';
        // パラパラ再生: 次の数枚を先に読み込み、読み込めてから進める (途中で白くならないように)
        echo '<script>(function(){var D=' . json_encode($js, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ',i=D.s,n=D.f.length,ph=document.getElementById("ph"),sl=document.getElementById("sl"),tm=document.getElementById("tm"),'
            . 'pl=document.getElementById("pl"),sp=document.getElementById("sp"),lp=document.getElementById("lp"),dl=document.getElementById("dl"),st=document.getElementById("st"),cache={},timer=null;'
            . 'function url(k){return D.u.replace("__F__",D.f[k]);}function load(k){if(!cache[k]){var im=new Image();im.src=url(k);cache[k]=im;}return cache[k];}'
            . 'function lab(k){var f=D.f[k];return f.substr(0,2)+":"+f.substr(2,2);}'
            . 'function show(k){i=Math.max(0,Math.min(n-1,k));ph.src=load(i).src;sl.value=i;tm.textContent=lab(i)+"  ("+(i+1)+" / "+n+")";dl.href=D.dl.replace("__F__",D.f[i]);'
            . 'st.querySelectorAll("a.on").forEach(function(a){a.classList.remove("on");});var a=st.querySelector(\'a[data-i="\'+i+\'"]\');if(a){a.classList.add("on");a.scrollIntoView({block:"nearest",inline:"center"});}'
            . 'for(var j=1;j<=3;j++)if(i+j<n)load(i+j);}'
            . 'function stop(){if(timer){clearTimeout(timer);timer=null;}pl.textContent="▶ 再生";}'
            . 'function step(){var nx=i+1;if(nx>=n){if(lp.checked)nx=0;else{stop();return;}}var im=load(nx);'
            . 'if(im.complete){show(nx);timer=setTimeout(step,1000/+sp.value);}else{im.onload=im.onerror=function(){if(timer){show(nx);timer=setTimeout(step,1000/+sp.value);}};timer=1;}}'
            . 'pl.onclick=function(){if(timer){stop();return;}if(i>=n-1)show(0);pl.textContent="⏸ 止める";timer=setTimeout(step,1000/+sp.value);};'
            . 'sl.oninput=function(){stop();show(+sl.value);};'
            . 'document.getElementById("bp").onclick=function(){stop();show(i-1);};document.getElementById("bn").onclick=function(){stop();show(i+1);};'
            . 'document.getElementById("b0").onclick=function(){stop();show(0);};document.getElementById("b9").onclick=function(){stop();show(n-1);};'
            . 'st.onclick=function(e){var a=e.target.closest("a[data-i]");if(!a)return;e.preventDefault();stop();show(+a.getAttribute("data-i"));};'
            . 'document.addEventListener("keydown",function(e){if(e.target.tagName==="INPUT"&&e.target.type!=="range"||e.target.tagName==="SELECT")return;'
            . 'if(e.key==="ArrowLeft"){e.preventDefault();stop();show(i-1);}else if(e.key==="ArrowRight"){e.preventDefault();stop();show(i+1);}else if(e.key===" "){e.preventDefault();pl.onclick();}});'
            . 'show(i);})();</script>';
    }
} else {
    // ================= 月の一覧 (日ごとの12時の写真) =================
    $mp = (string)($_GET['m'] ?? '');
    if (!preg_match('/^(\d{4})-(\d{2})$/', $mp, $mm) || (int)$mm[2] < 1 || (int)$mm[2] > 12) {
        $lastDay = $days ? end($days) : date('Ymd', $now);
        $mp = substr($lastDay, 0, 4) . '-' . substr($lastDay, 4, 2);
        preg_match('/^(\d{4})-(\d{2})$/', $mp, $mm);
    }
    $y = (int)$mm[1]; $mo = (int)$mm[2];
    $first = mktime(0, 0, 0, $mo, 1, $y);
    $pm = date('Y-m', mktime(0, 0, 0, $mo - 1, 1, $y)); $nm = date('Y-m', mktime(0, 0, 0, $mo + 1, 1, $y));
    $hasPrev = $days && $days[0] < date('Ymd', $first);
    $hasNext = $days && end($days) >= date('Ymd', mktime(0, 0, 0, $mo + 1, 1, $y));
    echo '<div class="mnav">'
        . '<a class="b' . ($hasPrev ? '' : ' off') . '" href="' . h($self(['m' => $pm])) . '">‹ 前の月</a>'
        . '<span class="ttl">' . $y . '年' . $mo . '月</span>'
        . '<a class="b' . ($hasNext ? '' : ' off') . '" href="' . h($self(['m' => $nm])) . '">次の月 ›</a>'
        . ($days ? '<a class="b" href="' . h($self(['d' => date('Y-m-d', strtotime(end($days)))])) . '">いちばん新しい日</a>' : '')
        . '<form method="get" action="camera.php"><input type="hidden" name="c" value="' . h($cid) . '">' . $keepField . '<input type="date" name="d" value="' . h(date('Y-m-d', $now)) . '" aria-label="日付"><button>日付へ移動</button></form>'
        . '</div>';
    echo '<p class="muted" style="margin:0 0 .3em">各日の12時ごろの写真です。押すと、その日の写真をスライドバーやパラパラ再生で見られます。</p>';
    echo '<table class="ccal"><tr>';
    foreach ($WD as $i => $w) echo '<th class="w' . $i . '">' . $w . '</th>';
    echo '</tr><tr>';
    $wd0 = (int)date('w', $first);
    for ($i = 0; $i < $wd0; $i++) echo '<td></td>';
    $nd = (int)date('t', $first);
    for ($d = 1; $d <= $nd; $d++) {
        $wd = ($wd0 + $d - 1) % 7;
        if ($wd === 0 && $d > 1) echo '</tr><tr>';
        $ymd = sprintf('%04d%02d%02d', $y, $mo, $d);
        $noon = in_array($ymd, $days, true) ? cam_noon($cam['dir'], $ymd) : '';
        $cnt = $noon !== '' ? count(cam_day_list($cam['dir'], $ymd)) : 0;
        echo '<td class="w' . $wd . ($ymd === date('Ymd', $now) ? ' today' : '') . '">';
        $dn = '<div class="dn"><b>' . $d . '</b>' . ($cnt ? '<i>' . $cnt . '枚</i>' : '') . '</div>';
        if ($noon !== '') echo '<a href="' . h($self(['d' => sprintf('%04d-%02d-%02d', $y, $mo, $d)])) . '">' . $dn . '<img src="' . h($img($ymd, $noon, true)) . '" alt="' . $mo . '月' . $d . '日" loading="lazy"></a>';
        else echo $dn . '<div class="no"></div>';
        echo '</td>';
    }
    for ($i = ($wd0 + $nd) % 7; $i > 0 && $i < 7; $i++) echo '<td></td>';
    echo '</tr></table>';
}

// ---- カメラの設定 (持ち主だけ) ----
if ($cam['own']) {
    $own = site_of_owner($site, $UID)['apiaries'];
    $opt = fn(int $v, int $cur, string $lb) => '<option value="' . $v . '"' . ($v === $cur ? ' selected' : '') . '>' . h($lb) . '</option>';
    echo '<details id="set" class="card form" style="margin-top:1.4em"><summary>⚙ このカメラの設定</summary>'
        . '<form method="post">' . csrf_field() . '<input type="hidden" name="c" value="' . h($cid) . '"><input type="hidden" name="act" value="save">'
        . '<p><label>名前</label><input type="text" name="name" value="' . h((string)($c['name'] ?? '')) . '" maxlength="30" placeholder="' . h('HPX-CAM ' . substr($cid, -4)) . '"></p>'
        . '<p><label>置いている蜂場 (チームで共有した蜂場なら、メンバーも写真を見られます)</label><select name="apiary"><option value="">(選ばない)</option>';
    foreach ($own as $k => $a) echo '<option value="' . h((string)$k) . '"' . ((string)$k === $aid ? ' selected' : '') . '>' . h((string)$a['name']) . '</option>';
    echo '</select></p><p><label>撮っている巣箱 (選ぶと巣箱のページにもリンクが出ます。蜂場全体を撮るときは選ばない)</label><select name="hive"><option value="">(選ばない)</option>';
    $hvSel = (string)($c['hive'] ?? '');
    foreach (site_of_owner($site, $UID)['hives'] as $k => $x) {
        if (!isset($own[(string)($x['apiary'] ?? '')])) continue;
        echo '<option value="' . h((string)$k) . '"' . ((string)$k === $hvSel ? ' selected' : '') . '>' . h($own[(string)$x['apiary']]['name'] . ' / ' . (string)($x['name'] ?? '')) . '</option>';
    }
    echo '</select></p><p><label>撮影の間隔</label><select name="iv">';
    foreach (CAM_IVS as $v) echo $opt($v, $iv, $v . '分ごと');
    echo '</select></p><p><label>撮影する時間帯 (夜は暗くて写らないので、昼だけにすると電池と通信量が節約できます)</label><select name="h0">';
    for ($v = 0; $v <= 23; $v++) echo $opt($v, $h0, $v . '時');
    echo '</select> から <select name="h1">';
    for ($v = 1; $v <= 24; $v++) echo $opt($v, $h1, $v . '時');
    echo '</select> まで</p><p><label>写真が大きく変わったときのお知らせ (巣箱が倒れた、人が来た、など)</label><select name="watch">';
    foreach (CW_LEVELS as $v => $lb) echo $opt($v, cw_level($c), $lb);
    echo '</select><br><small class="muted">前の写真と見くらべて、大きく変わったら蜂場のメンバーにお知らせメール (LINE) を送ります。'
        . (cw_ai_key() !== '' ? 'そのときだけ AI が写真を見て、明るさ・影・天気だけの違いなら送りません。' : 'AI のキーが設定されていないので、2枚続けて変わったままのときに送ります (明るさ・影で送ることがあります)。')
        . '暗い時間の写真は比べません。</small></p><p><button>保存</button></p></form>'
        . '<p class="muted">カメラの ID: ' . h($cid) . ' ・ 届いた写真 通算 ' . (int)($c['n'] ?? 0) . '枚 ・ 写真は '
        . ((int)($HPX_CAM_KEEP_DAYS ?? 365) > 0 ? (int)($HPX_CAM_KEEP_DAYS ?? 365) . '日たつと自動で消します' : '消さずに残します') . '<br>'
        . '送り先: <code>' . h(site_url('cam_upload.php')) . '</code> (トークンは HPX-Core と同じ機器用トークン)</p>'
        . '<form method="post" onsubmit="return confirm(\'このカメラをアカウントから外しますか？ 写真も見られなくなります。\')">' . csrf_field()
        . '<input type="hidden" name="c" value="' . h($cid) . '"><input type="hidden" name="act" value="remove"><input type="hidden" name="ok" value="1">'
        . '<button class="danger" style="border:1px solid #e0b4b4;color:#c62828">このカメラを外す</button></form></details>';
}
echo '</body></html>';
