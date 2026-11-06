<?php
// バックアップと復元: 自分のアカウントのデータと設定を1つの ZIP (hpx_backup_日付.zip) にまとめてダウンロードし、
//   あとでその ZIP をアップロードすれば、その時の状態に戻せる。マイアカウント(account.php)のボタンから POST で呼ぶ。
//   データ一括ダウンロード(export.php, Excel で見る CSV)とは別もの。こちらは HPX-Server に戻すための形(JSON などをそのまま)。
//
//   入るもの:
//     site/      自分の蜂場・巣箱・メモ・タスク・作業記録、メモのタグ・作業のタグなどのカスタマイズ(site/ の中身ぜんぶ)
//                写真(photos/・memo_photos/)は「写真も入れる」のときだけ。天気の一時保存(weather/)は入れない
//     hubs/      自分の HPX-Core の記録と設定(センサの名前・較正など)。「センサの記録も入れる」のときだけ
//     account.json  通知先・お知らせメールのオン/オフ(機器用トークンとカレンダーの合言葉は入れない)
//     hpx_backup.json  中身の説明(このファイルが無い ZIP は復元しない)
//   入らないもの: パスワード・メールアドレス・チーム(チームは複数の人のものなので)。共有してもらった蜂場は、その持ち主のバックアップに入る
//
//   復元: 今の site/ を backup_before/ に移して(1つ前の状態として残す)、ZIP の site/ を入れる。
//     ZIP に写真が無ければ、今の写真は残す。ZIP に hubs/ があれば、その HPX-Core の分だけ入れ替える(ほかの HPX-Core はそのまま。状態の履歴とメールの記録は今のものを引き継ぐ)。
//     「復元する前の状態に戻す」で backup_before/ と入れ替えられる(もう一度押すと、また入れ替わる)。
declare(strict_types=1);
require __DIR__ . '/hpx_site.php';

const B_FORMAT = 'hpx-backup';
const B_VERSION = 1;
const B_SKIP_DIRS = ['weather'];                     // site/ のうち入れないフォルダ(一時保存)
const B_PHOTO_DIRS = ['photos', 'memo_photos'];      // 写真のフォルダ
const B_ACCOUNT_KEYS = ['notify', 'mail_off'];       // account.json のうち入れる設定
const B_MAX_FILES = 20000;

if (!is_post()) redirect('account.php#backup');
@set_time_limit(300);
$act = (string)($_POST['act'] ?? '');
$acctDir = account_dir($UID);
$siteDir = account_site_dir($UID);
$beforeDir = $acctDir . '/backup_before';

// ZIP の中の名前として使ってよいか(フォルダは1段まで。「..」や隠しファイルは不可)
function b_name_ok(string $n): bool
{
    return (bool)preg_match('#^[A-Za-z0-9_][A-Za-z0-9_.-]*(/[A-Za-z0-9_][A-Za-z0-9_.-]*)?$#', $n) && !str_contains($n, '..')
        && !preg_match('/\.(php\d?|phtml|phar|htaccess|tmp)$/i', $n) && $n !== '.lock';
}

// $dir の中のファイル(1段下のフォルダまで)を [ZIPの中の名前 => 実際の場所] で
function b_files(string $dir, array $skipDirs): array
{
    $out = [];
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_file($p)) { if (b_name_ok($f)) $out[$f] = $p; continue; }
        if (!is_dir($p) || in_array($f, $skipDirs, true) || !b_name_ok($f)) continue;
        foreach (scandir($p) ?: [] as $g) if (is_file($p . '/' . $g) && b_name_ok($f . '/' . $g)) $out[$f . '/' . $g] = $p . '/' . $g;
    }
    return $out;
}

// ---- ダウンロード ----
if ($act === 'download') {
    $withPhotos = !empty($_POST['photos']);
    $withHubs = !empty($_POST['hubs']);
    $tmpDir = data_dir() . '/tmp';
    @mkdir($tmpDir, 0755, true);
    foreach (glob($tmpDir . '/backup_*') ?: [] as $old) if (filemtime($old) < $now - 3600) @unlink($old);
    $zipPath = $tmpDir . '/backup_' . bin2hex(random_bytes(6)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) redirect('account.php#backup', 'バックアップの準備ができませんでした。');

    $n = 0;
    if (is_dir($siteDir)) {
        foreach (b_files($siteDir, $withPhotos ? B_SKIP_DIRS : array_merge(B_SKIP_DIRS, B_PHOTO_DIRS)) as $name => $p) { $zip->addFile($p, 'site/' . $name); $n++; }
    }
    $hubIds = [];
    if ($withHubs) {
        foreach (glob(account_hubs_dir($UID) . '/*', GLOB_ONLYDIR) ?: [] as $hd) {
            $id = basename($hd);
            if (!preg_match('/^[0-9A-F]{12}$/', $id)) continue;
            foreach (scandir($hd) ?: [] as $f) {   // 記録(ble.csv)と設定(*.json)。状態の履歴(reports-*.jsonl)やメールの記録は入れない
                if (is_file($hd . '/' . $f) && (preg_match('/^[A-Za-z0-9_-]+\.(json|csv)$/', $f) || $f === '.retired')) { $zip->addFile($hd . '/' . $f, 'hubs/' . $id . '/' . $f); $n++; }
            }
            $hubIds[] = $id;
        }
    }
    $acct = array_intersect_key(account_load($UID), array_flip(B_ACCOUNT_KEYS));
    $zip->addFromString('account.json', json_encode($acct, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $zip->addFromString('hpx_backup.json', json_encode([
        'format' => B_FORMAT, 'version' => B_VERSION, 'created' => date('c', $now), 'uid' => $UID,
        'email' => (string)($ME['email'] ?? ''), 'name' => (string)($ME['name'] ?? ''), 'server' => site_url(''),
        'photos' => $withPhotos, 'hubs' => $hubIds, 'files' => $n,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $zip->addFromString('はじめにお読みください.txt', "\xEF\xBB\xBF" . BRAND_NAME . " バックアップ\r\n"
        . '作成: ' . date('Y-m-d H:i', $now) . ' / ' . (string)($ME['email'] ?? '') . "\r\n\r\n"
        . "・このファイルは、HPX-Server の「マイアカウント」→「バックアップと復元」で、そのまま戻すためのものです。\r\n"
        . "・中のファイルを書きかえたり、解凍して作り直したりすると、復元できなくなることがあります。\r\n"
        . "・Excel で見たいときは、「データ一括ダウンロード」(CSV) を使ってください。\r\n"
        . ($withPhotos ? '' : "・写真は入っていません。\r\n")
        . ($withHubs ? "・センサの記録 (HPX-Core " . count($hubIds) . " 台分) が入っています。\r\n" : "・センサの記録は入っていません。\r\n"));
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="hpx_backup_' . date('Ymd_Hi', $now) . '.zip"');
    header('Content-Length: ' . filesize($zipPath));
    header('Cache-Control: no-store');
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}

// ここから下はデータを書き換える。デモ用アカウントではできない
if (is_demo($ME)) redirect('account.php#backup', 'デモ用アカウントでは、復元はできません。');
if (!password_verify((string)($_POST['cur'] ?? ''), (string)$ME['pw'])) { sleep(2); redirect('account.php#backup', 'パスワードが違います。何も変えていません。'); }

// 2つのフォルダを入れ替える(どちらかが無ければ、ある方を移すだけ)
function b_swap(string $a, string $b): bool
{
    $t = $a . '_swap';
    if (is_dir($t)) dir_remove($t);
    if (is_dir($a) && !@rename($a, $t)) return false;
    if (is_dir($b) && !@rename($b, $a)) { if (is_dir($t)) @rename($t, $a); return false; }
    return !is_dir($t) || @rename($t, $b);
}

// ---- 復元する前の状態に戻す(今の状態と backup_before/ を入れ替える。もう一度押すと、また入れ替わる) ----
if ($act === 'undo') {
    if (!is_dir($beforeDir . '/site')) redirect('account.php#backup', '戻せる前の状態がありません。');
    $lock = @fopen($siteDir . '/.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    $ok = b_swap($siteDir, $beforeDir . '/site');
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    if ($ok) {   // 復元で入れ替えた HPX-Core の分も
        foreach (glob($beforeDir . '/hubs/*', GLOB_ONLYDIR) ?: [] as $hd) {
            if (!preg_match('/^[0-9A-F]{12}$/', basename($hd))) continue;
            $cur = account_hubs_dir($UID) . '/' . basename($hd);
            $lk = is_dir($cur) ? @fopen($cur . '/.lock', 'c') : false;
            if ($lk) flock($lk, LOCK_EX);
            b_swap($cur, $hd);
            if ($lk) { flock($lk, LOCK_UN); fclose($lk); }
        }
    }
    owner_site($UID, true);
    redirect('account.php#backup', $ok ? '入れ替えました。(もう一度押すと、また元に戻ります)' : '戻せませんでした。');
}

// ---- 復元 ----
if ($act !== 'restore') redirect('account.php#backup');
$f = $_FILES['file'] ?? null;
if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $e = is_array($f) ? (int)$f['error'] : UPLOAD_ERR_NO_FILE;
    redirect('account.php#backup', $e === UPLOAD_ERR_NO_FILE ? 'バックアップのファイルを選んでください。'
        : ($e === UPLOAD_ERR_INI_SIZE || $e === UPLOAD_ERR_FORM_SIZE ? 'ファイルが大きすぎて受け取れませんでした (サーバーの上限 ' . ini_get('upload_max_filesize') . ')。写真を入れないバックアップで試してください。'
        : 'ファイルを受け取れませんでした (エラー ' . $e . ')。'));
}
$zip = new ZipArchive();
if ($zip->open((string)$f['tmp_name']) !== true) redirect('account.php#backup', 'ZIP ファイルとして開けませんでした。HPX のバックアップ (hpx_backup_….zip) を選んでください。');
$man = json_decode((string)$zip->getFromName('hpx_backup.json'), true);
if (!is_array($man) || ($man['format'] ?? '') !== B_FORMAT) { $zip->close(); redirect('account.php#backup', 'HPX のバックアップではありません (hpx_backup.json がありません)。何も変えていません。'); }
if ((int)($man['version'] ?? 0) > B_VERSION) { $zip->close(); redirect('account.php#backup', 'このサーバーより新しい版のバックアップです。HPX-Server を新しくしてから復元してください。'); }
if ($zip->numFiles > B_MAX_FILES) { $zip->close(); redirect('account.php#backup', 'ファイルの数が多すぎます。'); }

// 中身を一時フォルダに出す(決まった場所・名前のものだけ)
$work = $acctDir . '/backup_new';
if (is_dir($work)) dir_remove($work);
@mkdir($work . '/site', 0755, true);
$hasSite = false;
$hubs = [];
$hasPhotos = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = (string)$zip->getNameIndex($i);
    if (str_ends_with($name, '/')) continue;
    if (str_starts_with($name, 'site/')) {
        $rel = substr($name, 5);
        if (!b_name_ok($rel)) continue;
        $sub = str_contains($rel, '/') ? explode('/', $rel)[0] : '';
        if (in_array($sub, B_SKIP_DIRS, true)) continue;
        if ($sub !== '') { @mkdir($work . '/site/' . $sub, 0755, true); if (in_array($sub, B_PHOTO_DIRS, true)) $hasPhotos[$sub] = true; }
        $dst = $work . '/site/' . $rel;
        $hasSite = true;
    } elseif (preg_match('#^hubs/([0-9A-F]{12})/([A-Za-z0-9_-]+\.(json|csv)|\.retired)$#', $name, $m)) {
        @mkdir($work . '/hubs/' . $m[1], 0700, true);
        $dst = $work . '/hubs/' . $m[1] . '/' . $m[2];
        $hubs[$m[1]] = true;
    } else {
        continue;
    }
    $in = $zip->getStream($name);
    $out = $in ? @fopen($dst, 'wb') : false;
    if (!$in || !$out || stream_copy_to_stream($in, $out) === false) { $zip->close(); dir_remove($work); redirect('account.php#backup', 'バックアップを読み出せませんでした (' . $name . ')。何も変えていません。'); }
    fclose($in); fclose($out);
    if (str_ends_with($dst, '.json') && !is_array(json_decode((string)file_get_contents($dst), true))) {
        $zip->close(); dir_remove($work); redirect('account.php#backup', 'バックアップの中身が壊れています (' . $name . ')。何も変えていません。');
    }
}
$acctIn = json_decode((string)$zip->getFromName('account.json'), true);
$zip->close();
if (!$hasSite || !is_file($work . '/site/site.json')) { dir_remove($work); redirect('account.php#backup', 'バックアップに蜂場のデータ (site.json) がありません。何も変えていません。'); }

// 写真がバックアップに無ければ、今の写真を引き継ぐ
foreach (B_PHOTO_DIRS as $pd) if (empty($hasPhotos[$pd]) && is_dir($siteDir . '/' . $pd)) dir_copy($siteDir . '/' . $pd, $work . '/site/' . $pd);
if (is_dir($siteDir . '/weather')) dir_copy($siteDir . '/weather', $work . '/site/weather');

// 入れ替え。今の site/ は backup_before/site に残す
if (is_dir($beforeDir)) dir_remove($beforeDir);
@mkdir($beforeDir, 0755, true);
$lock = @fopen($siteDir . '/.lock', 'c');
if ($lock) flock($lock, LOCK_EX);
$ok = (!is_dir($siteDir) || @rename($siteDir, $beforeDir . '/site')) && @rename($work . '/site', $siteDir);
if (!$ok && !is_dir($siteDir) && is_dir($beforeDir . '/site')) @rename($beforeDir . '/site', $siteDir);
if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
if (!$ok) { dir_remove($work); redirect('account.php#backup', '入れ替えができませんでした。何も変えていません。'); }

// HPX-Core の記録(バックアップにある HPX-Core だけ。今の分は backup_before/hubs に残す)
$nh = 0;
foreach (array_keys($hubs) as $id) {
    $cur = account_hubs_dir($UID) . '/' . $id;
    @mkdir($beforeDir . '/hubs', 0755, true);
    $lk = is_dir($cur) ? @fopen($cur . '/.lock', 'c') : false;   // receive.php が書いている途中なら待つ
    if ($lk) flock($lk, LOCK_EX);
    if (is_dir($cur)) {
        // バックアップに入れていないもの(状態の履歴 reports-*.jsonl・メールの記録など)は今のものを引き継ぐ
        foreach (scandir($cur) ?: [] as $g) {
            if ($g !== '.lock' && is_file($cur . '/' . $g) && !is_file($work . '/hubs/' . $id . '/' . $g)) @copy($cur . '/' . $g, $work . '/hubs/' . $id . '/' . $g);
        }
        @rename($cur, $beforeDir . '/hubs/' . $id);
    }
    @mkdir(dirname($cur), 0755, true);
    if (@rename($work . '/hubs/' . $id, $cur)) $nh++;
    if ($lk) { flock($lk, LOCK_UN); fclose($lk); }
}
dir_remove($work);

// 設定(通知先・お知らせメール)
if (is_array($acctIn)) {
    $keep = array_intersect_key($acctIn, array_flip(B_ACCOUNT_KEYS));
    if ($keep) account_update($UID, fn(array $a) => array_merge($a, $keep));
}
owner_site($UID, true);
$other = (string)($man['uid'] ?? '') !== $UID;
redirect('account.php#backup', '復元しました (' . date('Y-m-d H:i', (int)strtotime((string)($man['created'] ?? ''))) . ' のバックアップ'
    . ($nh ? '、HPX-Core ' . $nh . ' 台の記録も' : '') . ')。'
    . ($other ? ' ほかのアカウントのバックアップなので、記入者の名前は「(削除したアカウント)」と出ることがあります。' : '')
    . ' まちがえたときは「復元する前の状態に戻す」を押してください。');
