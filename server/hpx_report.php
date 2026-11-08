<?php
// 「作った・使ったレポート」(機器を作ったり、サーバーや養蜂記録アプリを使った人が、写真とひとことで報告するしくみ) の共通部分。関数の定義だけ。
//   使うページ:
//     サイトの report.php     … 投稿フォームと、公開した報告の一覧 (誰でも見られる)。トップページの新着もここから読む
//     サイトの report_img.php … 報告の写真を出す (公開した報告の写真だけ。まだのものは管理ページにログインした人だけ見られる)
//     サイトの report_admin.php … 管理者が、届いた報告を確かめて「公開する」「非公開にする」「削除」する
//                                 (HPX-note のアカウントとは別。config.php の $HPX_REPORT_ADMIN_PW のパスワードで入る)
//   流れ: 投稿 → 「確認待ち」で保存して管理者にメール → 管理者が公開したものだけサイトに出る
//   保存先 (データフォルダの reports/。data/.htaccess で外から開けない):
//     reports.json         報告の一覧 {"reports": {"r_0123456789": {...}}}
//     photos/<ID>_<番号>_l.jpg  写真 (長辺 1200px)、_s.jpg は一覧用の小さい写真 (長辺 600px)
//     secret / rate.json   フォームの署名の秘密の値 / 送信回数の制限
//   写真は必ずサーバーで作り直して保存する。撮影場所 (GPS) などの写真の中の情報は、このときすべて消える。
declare(strict_types=1);

// 作った機器・使ったもの。保存する名前 => 役割 (画面ではかっこ付きで出す)
const R_DEVICES = ['HPX-Core' => 'ハブ', 'HPX-TH' => '温湿度センサ', 'HPX-W' => '重量センサ', 'HPX-F' => 'アラーム',
    'HPX-note' => '養蜂記録アプリ', 'HPX-Server' => 'サーバー', 'その他' => ''];
function r_device_label(string $d): string { $r = R_DEVICES[$d] ?? ''; return $r !== '' ? $d . '（' . $r . '）' : $d; }
const R_MAX_PHOTOS = 3;                 // 1つの報告の写真の枚数
const R_MAX_BYTES = 15 * 1024 * 1024;   // 写真1枚の大きさの上限 (送られてきたまま)
const R_MAX_PIXELS = 50000000;          // 写真1枚の画素数の上限 (大きすぎると作り直せないため)
const R_LONG = 1200;                    // 保存する写真の長辺
const R_SMALL = 600;                    // 一覧用の写真の長辺
const R_NICK_MAX = 30;
const R_COMMENT_MAX = 300;
const R_REPLY_MAX = 300;
const R_MIN_S = 5;                      // ページを開いてからこの秒数より早い送信はロボットとみなす
const R_MAX_S = 7200;                   // ページを開いてからこの秒数を過ぎた送信は、開き直してもらう
const R_PER_IP_HOUR = 3;                // 同じ接続元から1時間に
const R_PER_DAY = 30;                   // 全体で1日に
const R_STATUS = ['pending' => '確認待ち', 'public' => '公開中', 'hidden' => '非公開'];

function r_dir(): string
{
    global $HPX_DATA_DIR;
    $d = rtrim((string)($HPX_DATA_DIR ?? (__DIR__ . '/data')), '/') . '/reports';
    if (!is_dir($d . '/photos')) @mkdir($d . '/photos', 0700, true);
    return $d;
}

function r_is_id(string $id): bool { return (bool)preg_match('/^r_[0-9a-f]{10}$/', $id); }

function r_all(): array
{
    $v = json_decode((string)@file_get_contents(r_dir() . '/reports.json'), true);
    return is_array($v['reports'] ?? null) ? $v['reports'] : [];
}

// 一覧を読んで $fn で書き換え、保存する (同時に書かないように鍵をかける)
function r_update(callable $fn): bool
{
    $fp = @fopen(r_dir() . '/reports.json', 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $v = json_decode((string)stream_get_contents($fp), true);
    $list = is_array($v['reports'] ?? null) ? $v['reports'] : [];
    $list = $fn($list);
    ftruncate($fp, 0);
    rewind($fp);
    $ok = fwrite($fp, json_encode(['reports' => $list], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

function r_get(string $id): ?array { return r_is_id($id) ? (r_all()[$id] ?? null) : null; }

// 新しい順に並べる。$status を渡すと、その状態のものだけ
function r_sorted(?string $status = null): array
{
    $list = array_filter(r_all(), fn($r) => $status === null || ($r['status'] ?? '') === $status);
    uasort($list, fn($a, $b) => (int)($b['time'] ?? 0) <=> (int)($a['time'] ?? 0));
    return $list;
}

function r_photo_path(string $id, int $n, string $size): string { return r_dir() . '/photos/' . $id . '_' . $n . '_' . ($size === 's' ? 's' : 'l') . '.jpg'; }

function r_delete(string $id): void
{
    if (!r_is_id($id)) return;
    foreach (glob(r_dir() . '/photos/' . $id . '_*.jpg') ?: [] as $f) @unlink($f);
    r_update(function (array $l) use ($id) { unset($l[$id]); return $l; });
}

// 送られてきた写真を読み、向きを直し、小さくして JPEG で保存し直す (写真の中の情報は消える)。できなければ理由を返す
function r_save_photo(string $tmp, string $id, int $n): string
{
    if (!function_exists('imagecreatetruecolor')) return 'サーバーで写真を扱えません (GD がありません)。';
    $info = @getimagesize($tmp);
    if (!$info) return '写真として読めないファイルがありました。';
    [$w, $h, $type] = $info;
    if ($w < 1 || $h < 1 || $w * $h > R_MAX_PIXELS) return '写真が大きすぎます。';
    $im = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG => @imagecreatefrompng($tmp),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        IMAGETYPE_GIF => @imagecreatefromgif($tmp),
        default => false,
    };
    if (!$im) return '写真は JPEG・PNG・WebP にしてください。';
    // スマホの写真は、向きが写真の中の情報 (Orientation) に入っていることがある。その向きに直す
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($tmp)['Orientation'] ?? 1);
        if (in_array($o, [2, 4, 5, 7], true)) imageflip($im, IMG_FLIP_HORIZONTAL);
        $deg = [3 => 180, 4 => 180, 5 => 270, 6 => 270, 7 => 90, 8 => 90][$o] ?? 0;
        if ($deg) { $r = imagerotate($im, $deg, 0); if ($r) { imagedestroy($im); $im = $r; } }
    }
    foreach (['l' => R_LONG, 's' => R_SMALL] as $size => $long) {
        $sw = imagesx($im); $sh = imagesy($im);
        $k = min(1, $long / max($sw, $sh));
        $dw = max(1, (int)round($sw * $k)); $dh = max(1, (int)round($sh * $k));
        $out = imagecreatetruecolor($dw, $dh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));   // 透明な部分は白にする
        imagecopyresampled($out, $im, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
        $ok = imagejpeg($out, r_photo_path($id, $n, $size), $size === 'l' ? 82 : 78);
        imagedestroy($out);
        if (!$ok) { imagedestroy($im); return '写真を保存できませんでした。'; }
    }
    imagedestroy($im);
    return '';
}

// フォームの署名 (ページを開いた時刻を、書き換えられないように入れておく)
function r_secret(): string
{
    $f = r_dir() . '/secret';
    $s = is_file($f) ? trim((string)file_get_contents($f)) : '';
    if (strlen($s) < 32) { $s = bin2hex(random_bytes(32)); @file_put_contents($f, $s, LOCK_EX); @chmod($f, 0600); }
    return $s;
}
function r_token(int $t): string { return $t . '.' . hash_hmac('sha256', 'report|' . $t, r_secret()); }
function r_token_age(string $tok): ?int
{
    if (!preg_match('/^(\d{9,11})\.([0-9a-f]{64})$/', $tok, $m)) return null;
    if (!hash_equals(hash_hmac('sha256', 'report|' . $m[1], r_secret()), $m[2])) return null;
    return time() - (int)$m[1];
}

// フォームを1回だけ使えるようにする。まだ使っていなければ「使った」と記録して true、使ったことがあれば false
function r_token_use(string $tok): bool
{
    $fp = @fopen(r_dir() . '/used.json', 'c+');
    if (!$fp) return true;
    flock($fp, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fp), true) ?: [];
    $now = time();
    $d = array_filter($d, fn($t) => $now - (int)$t < R_MAX_S + 600);   // 期限を過ぎたフォームは、もともと受け付けないので忘れる
    $k = hash('sha256', $tok);
    $ok = !isset($d[$k]);
    if ($ok) {
        $d[$k] = $now;
        ftruncate($fp, 0); rewind($fp);
        fwrite($fp, json_encode($d));
    }
    flock($fp, LOCK_UN); fclose($fp);
    return $ok;
}

// 送信回数の制限。超えていなければ数を1つ足して true
function r_rate_ok(): bool
{
    $fp = @fopen(r_dir() . '/rate.json', 'c+');
    if (!$fp) return true;
    flock($fp, LOCK_EX);
    $d = json_decode((string)stream_get_contents($fp), true) ?: [];
    $now = time();
    $ip = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));   // 接続元はそのまま残さない
    $d['ip'] = array_filter($d['ip'] ?? [], fn($v) => is_array($v) && $now - (int)($v[0] ?? 0) < 3600);
    $day = date('Ymd');
    if (($d['day'] ?? '') !== $day) { $d['day'] = $day; $d['n'] = 0; }
    $cnt = (int)($d['ip'][$ip][1] ?? 0);
    $ok = $cnt < R_PER_IP_HOUR && (int)$d['n'] < R_PER_DAY;
    if ($ok) {
        $d['ip'][$ip] = [$d['ip'][$ip][0] ?? $now, $cnt + 1];
        $d['n'] = (int)$d['n'] + 1;
        ftruncate($fp, 0); rewind($fp);
        fwrite($fp, json_encode($d));
    }
    flock($fp, LOCK_UN); fclose($fp);
    return $ok;
}

// 公開してよい項目だけにする (メールアドレスなどは出さない)
function r_public_view(array $r): array
{
    return [
        'id' => (string)$r['id'], 'date' => date('Y-m-d', (int)$r['time']), 'nick' => (string)$r['nick'],
        'devices' => array_map('r_device_label', array_values((array)($r['devices'] ?? []))), 'comment' => (string)$r['comment'], 'photos' => (int)($r['photos'] ?? 0),
        'reply' => (string)($r['reply'] ?? ''),
    ];
}

// 管理ページの住所 (メールに書く)。サイト (hiveplusx.com) の report_admin.php
function r_admin_url(): string
{
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off';
    $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/report_admin.php';
}

// ====================================================================
// 管理ページのログイン (HPX-note のアカウントとは別。config.php の $HPX_REPORT_ADMIN_PW のパスワードで入る)
// ====================================================================
const R_ADMIN_HOURS = 12;        // ログインを保つ時間
const R_FAIL_MAX = 5;            // 同じ接続元からこの回数続けて間違えたら
const R_FAIL_LOCK_S = 900;       // この秒数ログインを止める

// 8文字より短いパスワードは、使えないものとして扱う (簡単に当てられないように)
function r_admin_pw(): string { global $HPX_REPORT_ADMIN_PW; $p = (string)($HPX_REPORT_ADMIN_PW ?? ''); return strlen($p) >= 8 ? $p : ''; }

function r_admin_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('hpxreport');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict',
        'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')]);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// ログインしているか (パスワードを変えると、前のログインは切れる)
function r_admin_ok(): bool
{
    r_admin_session();
    $pw = r_admin_pw();
    return $pw !== '' && hash_equals(hash('sha256', 'report|' . $pw), (string)($_SESSION['pwv'] ?? ''))
        && time() - (int)($_SESSION['at'] ?? 0) < R_ADMIN_HOURS * 3600;
}

// 続けて間違えて止めている間なら、残り秒数を返す
function r_admin_locked(): int
{
    $d = json_decode((string)@file_get_contents(r_dir() . '/login_fail.json'), true) ?: [];
    $f = $d[hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''))] ?? null;
    if (!is_array($f) || (int)($f['n'] ?? 0) < R_FAIL_MAX) return 0;
    return max(0, (int)$f['t'] + R_FAIL_LOCK_S - time());
}

// パスワードを確かめてログインする。できたら true
function r_admin_login(string $pw): bool
{
    r_admin_session();
    $f = r_dir() . '/login_fail.json';
    $d = json_decode((string)@file_get_contents($f), true) ?: [];
    $ip = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $real = r_admin_pw();
    if ($real !== '' && hash_equals($real, $pw)) {
        unset($d[$ip]);
        @file_put_contents($f, json_encode($d), LOCK_EX);
        session_regenerate_id(true);
        $_SESSION['pwv'] = hash('sha256', 'report|' . $real);
        $_SESSION['at'] = time();
        return true;
    }
    $n = (time() - (int)($d[$ip]['t'] ?? 0) > R_FAIL_LOCK_S) ? 0 : (int)($d[$ip]['n'] ?? 0);
    $d[$ip] = ['n' => $n + 1, 't' => time()];
    @file_put_contents($f, json_encode($d), LOCK_EX);
    return false;
}

function r_admin_logout(): void
{
    r_admin_session();
    $_SESSION = [];
    session_destroy();
}
