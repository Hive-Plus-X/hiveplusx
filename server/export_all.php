<?php
// 全アカウントのデータのダウンロード (サーバー管理者だけ)。サーバー管理ページ (admin.php) のボタンから POST で呼ぶ。
//   データ利用に同意したアカウント (users.json の consent) の分だけを、アカウントごとのフォルダに分けて1つの ZIP で返す。
//   同意していないアカウント・登録の申請中のアカウント・デモ用アカウントは入れない。
//   中身は保存しているそのままの形 (蜂場などは JSON、センサの記録は ble.csv)。研究や集計、バックアップ向け。
//     アカウント一覧.csv          … 入れたアカウントの一覧 (パスワード・機器用トークンは入れない)
//     <表示名>_<アカウントID>/site/ … 蜂場・巣箱 (site.json)、メモ (memos.json)、作業記録・タスク (works.json) など
//     <表示名>_<アカウントID>/hubs/<HPX-CoreのID>/ … センサの記録 (ble.csv)、HPX-Core の状態 (state.json)、較正 (weight_cal.json・th_cal.json)
//   写真は「写真も入れる」にしたときだけ (site/photos/・site/memo_photos/)。
//     <表示名>_<アカウントID>/cams/<カメラID>/cam.json … HPX-CAM の設定と大きな変化の記録 (いつも入れる)
//     <表示名>_<アカウントID>/cams/<カメラID>/YYYYMMDD/HHMMSS.jpg … HPX-CAM の写真 (「前回から」か「すべて」のとき。小さい版 _t は入れない)
//     <表示名>_<アカウントID>/cams_removed/… … アカウントから外したカメラ (形は cams/ と同じ)
//     <表示名>_<アカウントID>/ai/ai_learn.json・ai_rules.json … AIメモ・記録で本人が直した例と、覚えた決まり
//   センサの記録 (ble.csv)・HPX-Core の状態の履歴 (reports-*.jsonl)・メモ・作業記録・タスク・つながりの履歴は、いつも全期間入る。
//   ダウンロードした時刻は export_all_last.json に残し、サーバー管理者へのお知らせ (hpx_dl_remind.php) と「前回から」に使う。
//   ZIP はデータフォルダの tmp/ に作り、送ったら消す。
declare(strict_types=1);
require __DIR__ . '/hpx_site.php';
if (empty($ME['admin'])) {
    http_response_code(403);
    exit('このページはサーバー管理者だけが開けます。');
}
if (!is_post()) redirect('admin.php#export_all');
@set_time_limit(900);

$photos = !empty($_POST['photos']);
$camMode = in_array($_POST['cams'] ?? '', ['none', 'new', 'all'], true) ? (string)$_POST['cams'] : 'none';
$camLast = (int)(export_all_last()['cam_t'] ?? 0);
$camFrom = $camMode === 'all' || ($camMode === 'new' && $camLast === 0) ? '00000000' : ($camMode === 'new' ? date('Ymd', $camLast) : '99999999');
$camDays = [];   // 入れた写真の日付 (はじめにお読みください と記録用)
$camFiles = 0;
$tmpDir = data_dir() . '/tmp';
@mkdir($tmpDir, 0755, true);
foreach (glob($tmpDir . '/export_all_*') ?: [] as $old) if (filemtime($old) < $now - 3600) @unlink($old);   // 送りそこねた古いもの
$zipPath = $tmpDir . '/export_all_' . bin2hex(random_bytes(6)) . '.zip';
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) redirect('admin.php#export_all', 'ダウンロードの準備ができませんでした。');

// フォルダの中身を ZIP に入れる。. で始まるファイル (印)、.lock・.tmp と、デモの控え (ble_real_backup.csv) は入れない。入れたファイル数を返す
function zip_dir(ZipArchive $zip, string $dir, string $as, array $skipDirs = []): int
{
    $n = 0;
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..' || $f[0] === '.' || $f === 'ble_real_backup.csv' || $f === 'account.json' || preg_match('/\.(lock|tmp)$/', $f)) continue;
        $p = $dir . '/' . $f;
        if (is_link($p)) continue;
        if (is_dir($p)) {
            if (!in_array($f, $skipDirs, true)) $n += zip_dir($zip, $p, $as . '/' . $f, $skipDirs);
        } elseif (is_file($p)) {
            $zip->addFile($p, $as . '/' . $f);
            $n++;
        }
    }
    return $n;
}
$fname = fn(string $s) => (preg_replace('#[\\\\/:*?"<>|\s]+#u', '_', $s) ?: 'account');
$cnt = fn(array $x) => count($x);

$rows = [];
$skipped = 0;
$users = users_all();
uasort($users, fn($a, $b) => (int)($a['created'] ?? 0) <=> (int)($b['created'] ?? 0));
foreach ($users as $uid => $u) {
    $uid = (string)$uid;
    if (!is_uid($uid) || is_demo($u) || ($u['status'] ?? '') === 'pending') continue;
    if (!consent_given($u)) { $skipped++; continue; }
    $folder = $fname((string)($u['name'] ?? '')) . '_' . $uid;
    $site = is_dir(account_site_dir($uid)) ? account_site_dir($uid) : '';
    $hubsDir = is_dir(account_hubs_dir($uid)) ? account_hubs_dir($uid) : '';
    if ($site !== '') zip_dir($zip, $site, $folder . '/site', $photos ? [] : ['photos', 'memo_photos']);
    if ($hubsDir !== '') zip_dir($zip, $hubsDir, $folder . '/hubs');
    foreach (['ai_learn.json', 'ai_rules.json'] as $af) {   // AIメモ・記録で本人が直した例と、覚えた決まり (AI の読み取りと正解の組)
        if (is_file(account_dir($uid) . '/' . $af)) $zip->addFile(account_dir($uid) . '/' . $af, $folder . '/ai/' . $af);
    }
    $camDirs = array_merge(glob(cams_dir($uid) . '/*/cam.json') ?: [], glob(account_dir($uid) . '/cams_removed/*/cam.json') ?: []);   // 外したカメラの分も
    foreach ($camDirs as $cj) {   // HPX-CAM: 設定はいつも、写真は選んだ日から
        $cd = dirname($cj);
        $as = $folder . (basename(dirname($cd)) === 'cams_removed' ? '/cams_removed/' : '/cams/') . basename($cd);
        $zip->addFile($cj, $as . '/cam.json');
        foreach (glob($cd . '/[0-9]*', GLOB_ONLYDIR) ?: [] as $dd) {
            $day = basename($dd);
            if (!preg_match('/^\d{8}$/', $day) || $day < $camFrom) continue;
            foreach (glob($dd . '/*.jpg') ?: [] as $jp) {
                if (str_ends_with($jp, '_t.jpg')) continue;
                $zip->addFile($jp, $as . '/' . $day . '/' . basename($jp));
                $camFiles++;
                $camDays[$day] = true;
            }
        }
    }
    $s = site_read_of($uid, 'site');
    $w = site_read_of($uid, 'works');
    $rows[] = [$uid, (string)($u['name'] ?? ''), (string)($u['email'] ?? ''), ['active' => '利用中', 'invited' => '招待中', 'stopped' => '停止中'][$u['status'] ?? ''] ?? (string)($u['status'] ?? ''),
        !empty($u['admin']) ? 'サーバー管理者' : '一般', !empty($u['created']) ? date('Y-m-d H:i', (int)$u['created']) : '',
        !empty($u['last_login']) ? date('Y-m-d H:i', (int)$u['last_login']) : '', date('Y-m-d H:i', (int)$u['consent']),
        count(glob(account_hubs_dir($uid) . '/*/state.json') ?: []), $cnt((array)($s['apiaries'] ?? [])), $cnt((array)($s['hives'] ?? [])),
        $cnt(site_read_of($uid, 'memos')), $cnt((array)($w['logs'] ?? [])), $cnt((array)($w['todos'] ?? [])), $folder];
}

$fh = fopen('php://temp', 'w+b');
fwrite($fh, "\xEF\xBB\xBF");   // Excel で文字化けしないように(UTF-8 の印)
fputcsv($fh, ['アカウントID', '表示名', 'メールアドレス', '状態', '権限', '作成', '最終ログイン', 'データ利用の同意', 'HPX-Core (台)', '蜂場', '巣箱', 'メモ', '作業記録', 'タスク', 'フォルダ']);
foreach ($rows as $r) fputcsv($fh, array_map('strval', $r));
rewind($fh);
$zip->addFromString('アカウント一覧.csv', (string)stream_get_contents($fh));
fclose($fh);

$zip->addFromString('はじめにお読みください.txt', "\xEF\xBB\xBF" . BRAND_NAME . " 全アカウントのデータ\r\n"
    . '作成: ' . date('Y-m-d H:i', $now) . ' / ' . (string)($ME['email'] ?? '') . "\r\n"
    . '入れたアカウント: ' . count($rows) . " 人 (データ利用に同意した人だけ。同意していない " . $skipped . " 人は入れていません)\r\n\r\n"
    . "・アカウント一覧.csv … 入れたアカウントの一覧です (Excel で開けます)。\r\n"
    . "・<表示名>_<アカウントID>/site/ … 蜂場と巣箱 (site.json)、メモ (memos.json)、作業記録とタスク (works.json) などです。サーバーに保存しているそのままの形 (JSON) です。\r\n"
    . "・<表示名>_<アカウントID>/hubs/<HPX-CoreのID>/ble.csv … センサの全期間の記録です。重量は生の値 (raw) で、kg にするには同じフォルダの weight_cal.json の較正を使います。\r\n"
    . "・チームで共有された蜂場の記録は、蜂場の持ち主のフォルダに入っています。記入者 (by) などはアカウントID で、アカウント一覧.csv で名前が分かります。\r\n"
    . ($photos ? "・写真は site/photos/ (蜂場) と site/memo_photos/ (メモ) にあります。\r\n" : "・写真は入れていません。\r\n")
    . "・cams/<カメラID>/cam.json … HPX-CAM の設定 (置いた蜂場・巣箱) と大きな変化の記録です。\r\n"
    . ($camFiles > 0 ? "・cams/<カメラID>/YYYYMMDD/HHMMSS.jpg … HPX-CAM の写真 " . $camFiles . " 枚 (" . min(array_keys($camDays)) . " 〜 " . max(array_keys($camDays)) . ") です。\r\n"
        : "・HPX-CAM の写真は入れていません" . ($camMode !== 'none' ? " (選んだ期間に写真がありません)" : '') . "。\r\n")
    . "・cams_removed/ … アカウントから外したカメラの設定と写真です (形は cams/ と同じ)。\r\n"
    . "・ai/ai_learn.json … AIメモ・記録で、AI の読み取りと本人が直した結果の組です。ai/ai_rules.json は直した例からまとめた決まりです。\r\n"
    . "・site/links.json … センサ・カメラ・HPX-Core と巣箱・蜂場のつながりの履歴です (付け替えた日時が分かります)。\r\n"
    . "・パスワードと機器用トークンは入っていません。\r\n\r\n"
    . "このデータは、本人がデータ利用に同意したものです。同意の範囲で使い、人に渡すときは個人が分からないようにしてください。\r\n");
$zip->close();
ksort($camDays);
export_all_mark((string)($ME['email'] ?? ''), $camMode !== 'none' ? [$camDays ? (string)array_key_first($camDays) : '', $camDays ? (string)array_key_last($camDays) : ''] : null);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="hpx_all_data_' . date('Ymd_Hi', $now) . '.zip"');
header('Content-Length: ' . filesize($zipPath));
header('Cache-Control: no-store');
readfile($zipPath);
@unlink($zipPath);
