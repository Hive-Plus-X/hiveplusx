<?php
// HPX-CAM の写真が、それまでと大きく変わったらお知らせする (巣箱が強風で倒れた、人が来た、など)。cam_upload.php から読み込む。
//
//   1. 見くらべ (無料): 届いた写真を 32×24 の白黒に縮め、少し前の写真と比べる。明るさの違い (朝夕・雲) はならしてから比べ、
//      全体のうち変わったマスの割合が、カメラの設定の「感度」で決めた量を超えたら「大きく変わった」とする。
//      暗い写真 (夜・夕方) は比べない。前の写真が CW_GAP_S より前なら比べない (朝いちばんの写真など)。
//   2. AI で確認 (有料、AI のキーがあるときだけ): 見くらべで大きく変わったときだけ、前と今の写真を AI (Claude) に見せて
//      「巣箱が倒れた・ずれた、人や動物がいる、ふたが開いた」などかを聞く。明るさ・影・天気だけの違いなら知らせない。
//      キーが無ければ、2枚続けて大きく変わったまま (一時的な影や鳥ではない) のときに知らせる。
//   知らせたあと $HPX_CAM_QUIET_MIN 分は、同じカメラでは知らせない (知らせすぎ・料金の上がりすぎを防ぐ)。
//
//   cam.json に書くもの: watch(0=知らせない / 1=少なめ / 2=ふつう / 3=多め、無ければ 2)、ws(前の写真の縮めた白黒と時刻)、
//     wp(見くらべで変わったが、まだ知らせていない時の元の写真)、wq(最後に知らせた時刻)、wai(AI に聞いた日と回数)、
//     wlog(最近の「大きな変化」の記録 [t, 割合%, 知らせたか, AI の見立て] 新しい順 CW_LOG_MAX 件)
declare(strict_types=1);
require_once __DIR__ . '/hpx_alert.php';

const CW_W = 32;               // 見くらべ用に縮める大きさ
const CW_H = 24;
const CW_DARK = 40;            // 平均の明るさ (0〜255) がこれ未満の写真は比べない
const CW_CELL = 0.30;          // 1マスの明るさがこの割合以上変わったら「変わったマス」
const CW_RATIO = [1 => 0.35, 2 => 0.20, 3 => 0.12];   // 感度ごとの「大きく変わった」とする割合 (少なめ / ふつう / 多め)
const CW_LEVELS = [0 => '知らせない', 1 => '少なめ (大きく変わったときだけ)', 2 => 'ふつう', 3 => '多め (小さな変化も)'];
const CW_GAP_S = 5400;         // 前の写真がこれ (秒) より前なら比べない
const CW_LOG_MAX = 20;
const CW_AI_PX = 800;          // AI に見せる写真の長い辺 (小さいほど安い)
const CW_MSG = 'カメラ画像に大きな変化がありました。確認して下さい。';

function cw_level(array $c): int { $v = (int)($c['watch'] ?? 2); return isset(CW_LEVELS[$v]) ? $v : 2; }

// AI のキー (カメラ用が空なら AIメモと同じキー)
function cw_ai_key(): string
{
    $k = trim((string)($GLOBALS['HPX_CAM_AI_KEY'] ?? ''));
    return $k !== '' ? $k : trim((string)($GLOBALS['HPX_AI_KEY'] ?? ''));
}

// 写真を縮めた白黒 [明るさの配列 (CW_W×CW_H), 平均]。読めなければ null
function cw_sig(string $path): ?array
{
    if (!function_exists('imagecreatefromjpeg')) return null;
    $im = @imagecreatefromjpeg($path);
    if (!$im) return null;
    $t = imagecreatetruecolor(CW_W, CW_H);
    imagecopyresampled($t, $im, 0, 0, 0, 0, CW_W, CW_H, imagesx($im), imagesy($im));
    imagedestroy($im);
    $px = [];
    for ($y = 0; $y < CW_H; $y++) for ($x = 0; $x < CW_W; $x++) {
        $rgb = imagecolorat($t, $x, $y);
        $px[] = (int)round(0.299 * (($rgb >> 16) & 255) + 0.587 * (($rgb >> 8) & 255) + 0.114 * ($rgb & 255));
    }
    imagedestroy($t);
    return [$px, array_sum($px) / count($px)];
}
function cw_pack(array $sig, int $t, string $file): array { return ['t' => $t, 'f' => $file, 'm' => round($sig[1], 1), 'p' => base64_encode(pack('C*', ...$sig[0]))]; }
function cw_unpack(array $w): ?array
{
    $px = array_values(unpack('C*', (string)base64_decode((string)($w['p'] ?? ''))) ?: []);
    return count($px) === CW_W * CW_H ? [$px, (float)($w['m'] ?? 0)] : null;
}

// 変わったマスの割合 (0〜1)。それぞれの平均の明るさで割ってから比べる (全体が明るく・暗くなっただけなら変わらない)
function cw_diff(array $a, array $b): float
{
    $ma = max(1.0, $a[1]); $mb = max(1.0, $b[1]); $n = 0;
    foreach ($a[0] as $i => $v) {
        $x = $v / $ma; $y = $b[0][$i] / $mb;
        if (abs($x - $y) > CW_CELL * max(0.5, ($x + $y) / 2)) $n++;
    }
    return $n / count($a[0]);
}

// AI に見せる大きさに縮めた JPEG (base64)
function cw_ai_jpeg(string $path): string
{
    $im = @imagecreatefromjpeg($path);
    if (!$im) return '';
    $w = imagesx($im); $h = imagesy($im); $k = min(1, CW_AI_PX / max($w, $h));
    $t = imagecreatetruecolor(max(1, (int)round($w * $k)), max(1, (int)round($h * $k)));
    imagecopyresampled($t, $im, 0, 0, 0, 0, imagesx($t), imagesy($t), $w, $h);
    ob_start(); imagejpeg($t, null, 80); $jpg = (string)ob_get_clean();
    imagedestroy($im); imagedestroy($t);
    return base64_encode($jpg);
}

// AI に前と今の写真を見せる。[知らせるか (bool) または null (聞けなかった), 見立て (日本語の短い文) またはエラーの説明]
function cw_ai_check(string $before, string $after, string $camName): array
{
    global $HPX_CAM_AI_MODEL, $HPX_AI_API;
    $b = cw_ai_jpeg($before); $a = cw_ai_jpeg($after);
    if ($b === '' || $a === '') return [null, '写真を読めませんでした'];
    $model = trim((string)($HPX_CAM_AI_MODEL ?? '')) ?: 'claude-haiku-5-5';
    $img = fn(string $d) => ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $d]];
    $body = [
        'model' => $model,
        'max_tokens' => 4000,
        'system' => 'あなたは養蜂場の見守りカメラの写真を確認する係です。同じカメラで少し前に撮った写真 (1枚目) と今の写真 (2枚目) を比べ、'
            . '養蜂家がすぐ見に行くべきことが起きたかを判定してください。'
            . '知らせるもの: 巣箱が倒れた・ずれた・無くなった・ふたが開いた、人や車がいる、クマなどの動物がいる、倒木や柵の破損、カメラの向きが大きく変わった・レンズがふさがれた。'
            . '知らせないもの: 明るさや影・日差し・雲・雨・雪・霧の違い、草や枝のゆれ、ミツバチや小さな虫、写真の色合いやピントの違いだけ。'
            . 'reason は養蜂家に向けた日本語の短い1文 (40文字くらいまで) で、何が変わったかを書いてください。',
        'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['alert', 'reason'],
            'properties' => ['alert' => ['type' => 'boolean'], 'reason' => ['type' => 'string']],
        ]]],
        'messages' => [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'カメラ「' . $camName . '」の1枚目 (前の写真):'], $img($b),
            ['type' => 'text', 'text' => '2枚目 (今の写真):'], $img($a),
        ]]],
    ];
    $head = ['Content-Type: application/json', 'x-api-key: ' . cw_ai_key(), 'anthropic-version: 2026-10-21'];
    if (!str_starts_with($model, 'claude-haiku')) {   // 安全のための判定で断られたとき、ほかのモデルでやり直す (Haiku には無い)
        $body['fallbacks'] = 'default';
        $head[] = 'anthropic-beta: server-side-fallback-2026-07-01';
    }
    $url = rtrim((string)($HPX_AI_API ?? '') ?: 'https://api.anthropic.com', '/') . '/v1/messages';   // $HPX_AI_API は試験用
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => $head,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 5]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) return [null, 'AI に接続できませんでした (' . $err . ')'];
    $r = json_decode((string)$res, true);
    if ($code !== 200 || !is_array($r)) return [null, 'AI の返事が HTTP ' . $code . ' ' . mb_substr((string)($r['error']['message'] ?? $res), 0, 200)];
    if (($r['stop_reason'] ?? '') === 'refusal') return [null, 'AI に断られました'];
    foreach ((array)($r['content'] ?? []) as $blk) {
        if (($blk['type'] ?? '') !== 'text') continue;
        $j = json_decode((string)($blk['text'] ?? ''), true);
        if (is_array($j) && isset($j['alert'])) return [(bool)$j['alert'], mb_substr(trim((string)($j['reason'] ?? '')), 0, 80)];
    }
    return [null, 'AI の返事の形が違いました'];
}

// 届いた写真1枚を見る。cam_upload.php で保存したあと (カメラへの返事を返したあと) に呼ぶ
function cam_watch(string $uid, string $id, string $dir, string $file, int $t): void
{
    global $HPX_CAM_QUIET_MIN, $HPX_CAM_AI_DAY_MAX;
    $c = cam_load($uid, $id);
    $lv = cw_level($c);
    if ($lv === 0) return;
    $sig = cw_sig($file);
    if ($sig === null) return;
    $rel = substr($file, strlen($dir) + 1);   // YYYYMMDD/HHMMSS.jpg
    $prev = isset($c['ws']) ? cw_unpack((array)$c['ws']) : null;
    $prevT = (int)($c['ws']['t'] ?? 0);
    $pend = (array)($c['wp'] ?? []);
    $keep = function (array $x) use ($sig, $t, $rel): array { $x['ws'] = cw_pack($sig, $t, $rel); return $x; };

    // 送り直しで古い写真が後から届いたときは何もしない
    if ($t <= $prevT) return;
    // 暗い写真・前の写真が古い・前の写真が無いときは、比べずに今の写真を次の比べる元にする
    if ($sig[1] < CW_DARK || $prev === null || $prev[1] < CW_DARK || $t - $prevT > CW_GAP_S) {
        cam_update($uid, $id, function (array $x) use ($keep) { unset($x['wp']); return $keep($x); });
        return;
    }
    // まだ知らせていない変化があれば、その前の写真と比べる (2枚続けて変わったままかを見る)
    $base = $prev; $baseRel = (string)($c['ws']['f'] ?? '');
    if ($pend && ($p = cw_unpack($pend)) !== null && $t - (int)($pend['t'] ?? 0) <= CW_GAP_S) { $base = $p; $baseRel = (string)($pend['f'] ?? ''); }
    $ratio = cw_diff($base, $sig);
    if ($ratio < CW_RATIO[$lv]) {
        cam_update($uid, $id, function (array $x) use ($keep) { unset($x['wp']); return $keep($x); });
        return;
    }

    // ---- 大きく変わった ----
    $now = time();
    $quiet = max(0, (int)($HPX_CAM_QUIET_MIN ?? 180)) * 60;
    $inQuiet = $now - (int)($c['wq'] ?? 0) < $quiet;
    $useAi = cw_ai_key() !== '' && function_exists('curl_init');
    $send = false; $note = '';
    if ($inQuiet) {
        $note = '前のお知らせから間もないので知らせません';
    } elseif ($useAi) {
        $day = date('Ymd', $now);
        $used = ($c['wai']['d'] ?? '') === $day ? (int)($c['wai']['n'] ?? 0) : 0;
        $max = (int)($HPX_CAM_AI_DAY_MAX ?? 20);
        if ($max > 0 && $used >= $max) {
            $note = 'きょう AI に聞ける回数 (' . $max . '回) を超えたので、AI に聞かずに知らせます';
            $send = true;
        } else {
            cam_update($uid, $id, function (array $x) use ($day, $used) { $x['wai'] = ['d' => $day, 'n' => $used + 1]; return $x; });
            [$ok, $note] = cw_ai_check($dir . '/' . $baseRel, $file, cam_name($c, $id));
            if ($ok === null) { $send = true; $note = 'AI で確かめられませんでした: ' . $note; }   // 確かめられないときは知らせる (見落とさないほう)
            else $send = $ok;
        }
    } elseif ($pend) {
        $send = true;   // AI なし: 2枚続けて変わったまま
    } else {
        // AI なし・1枚目: 次の写真でも変わったままなら知らせる
        cam_update($uid, $id, function (array $x) use ($keep, $c) {
            $x['wp'] = $c['wp'] ?? ($c['ws'] ?? []);
            return $keep($x);
        });
        return;
    }

    cam_update($uid, $id, function (array $x) use ($keep, $t, $ratio, $send, $note, $now) {
        unset($x['wp']);
        if ($send) $x['wq'] = $now;
        $log = (array)($x['wlog'] ?? []);
        array_unshift($log, [$t, (int)round($ratio * 100), $send ? 1 : 0, $note]);
        $x['wlog'] = array_slice($log, 0, CW_LOG_MAX);
        return $keep($x);
    });
    if (!$send) return;

    $name = cam_name($c, $id);
    $aid = (string)($c['apiary'] ?? '');
    $where = $name;
    if ($aid !== '' && ($ap = (array)(owner_site($uid)['apiaries'][$aid] ?? [])) && ($ap['name'] ?? '') !== '') $where = $ap['name'] . ' / ' . $name;
    $url = site_url('camera.php?' . http_build_query(['c' => $id, 'd' => date('Y-m-d', $t), 'f' => date('His', $t)]));
    hpx_alert_queue('【HPX】カメラ画像に大きな変化がありました (' . $where . ')',
        CW_MSG . "\n\n場所: $where\n撮影: " . date('Y-m-d H:i', $t) . "\n"
        . ($useAi && $note !== '' ? 'AI の見立て: ' . $note . "\n" : '')
        . '写真: ' . $url . "\n");
    hpx_alert_flush(account_site_dir($uid), apiary_alert_to($uid, $aid !== '' ? [$aid] : [], 'camera'));
}
