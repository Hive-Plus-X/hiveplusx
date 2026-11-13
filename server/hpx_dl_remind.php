<?php
// データのダウンロードを促すお知らせ (2026-11-13)。サーバーに古いデータを置き続けない代わりに、手元に保存してもらう。
//   ・サーバー管理者: 全アカウントのデータのダウンロード (export_all.php) を $HPX_EXPORT_REMIND_DAYS 日 (初期30日) していなければ、
//     ページの上にお知らせを出し、hpx_cron.php から1回メールも送る (次にダウンロードするまで、その日数ごとに1回)。
//     カメラの写真は $HPX_CAM_KEEP_DAYS 日で自動で消えるので、その前に保存してもらう。
//   ・ふつうの利用者: 自分のデータのダウンロード (バックアップ・データ一括ダウンロード・カメラの写真のまとめて保存) を
//     $HPX_USER_DL_REMIND_DAYS 日 (初期90日) していなければ、ページの上にお知らせを出す。「あとで」で30日だまる。
//   いつダウンロードしたかは users.json の dl_last (利用者) と、データフォルダの export_all_last.json (サーバー管理者) に残す。
declare(strict_types=1);
require_once __DIR__ . '/hpx_auth.php';
require_once __DIR__ . '/hpx_cam.php';

const DLR_SNOOZE_DAYS = 30;

function dlr_days(string $var, int $default): int
{
    $v = $GLOBALS[$var] ?? $default;
    return max(0, (int)$v);
}

// 利用者が自分のデータをダウンロードした
function dl_mark_user(string $uid): void
{
    if (is_uid($uid)) user_set($uid, ['dl_last' => time()]);
}

// 全アカウントのデータのダウンロードの記録: {t, by, cam (写真を入れた日の範囲 [から, まで] か null)}
function export_all_last(): array { return json_load(data_dir() . '/export_all_last.json'); }
function export_all_mark(string $by, ?array $camRange): void
{
    json_update(data_dir() . '/export_all_last.json', function (array $v) use ($by, $camRange) {
        $v['t'] = time();
        $v['by'] = $by;
        if ($camRange !== null) { $v['cam_t'] = time(); $v['cam'] = $camRange; }
        unset($v['mailed']);
        return $v;
    });
}

// サーバー管理者へのお知らせが要るか。要るなら前回のダウンロードの時刻 (一度も無ければ 0) を返す。要らなければ null
function export_all_due(): ?int
{
    $days = dlr_days('HPX_EXPORT_REMIND_DAYS', 30);
    if ($days === 0) return null;
    $last = (int)(export_all_last()['t'] ?? 0);
    $start = $last;
    if ($last === 0) {   // 一度もしていなければ、いちばん早く同意した人の同意から数える
        foreach (users_all() as $u) if (!is_demo($u) && (int)($u['consent'] ?? 0) > 0) $start = $start === 0 ? (int)$u['consent'] : min($start, (int)$u['consent']);
        if ($start === 0) return null;   // 同意した人がいない
    }
    return time() - $start >= $days * 86400 ? $last : null;
}

function dlr_cam_note(): string
{
    $keep = (int)($GLOBALS['HPX_CAM_KEEP_DAYS'] ?? 365);
    return $keep > 0 ? 'カメラの写真は ' . $keep . ' 日たつとサーバーから自動で消えます。' : '';
}

// ページの上に出すお知らせ (page_head から呼ぶ)
function dl_notice_html(?array $me, string $csrfField): string
{
    if (!$me || is_demo($me)) return '';
    $o = '';
    $box = fn(string $s) => '<div class="card" style="background:#fdf0c8;border-color:#e0c060">' . $s . '</div>';
    if (!empty($me['admin'])) {
        $last = export_all_due();
        if ($last !== null) {
            $o .= $box('<b>サーバー管理者へ:</b> 全アカウントのデータを' . ($last > 0 ? ' ' . h(date('Y-m-d', $last)) . ' から' : 'まだ一度も') . 'ダウンロードしていません。'
                . 'センサの記録・メモ・作業記録・写真など、研究用のデータをなくさないように、定期的に全部を手元へ保存してください。' . h(dlr_cam_note())
                . ' <a href="admin.php#export_all">ダウンロードのページへ</a>');
        }
    }
    $days = dlr_days('HPX_USER_DL_REMIND_DAYS', 90);
    $uid = (string)($me['id'] ?? '');
    if ($days > 0 && is_uid($uid) && is_file(account_site_dir($uid) . '/site.json')) {
        $since = max((int)($me['dl_last'] ?? 0), (int)($me['created'] ?? 0), (int)($me['dl_snooze'] ?? 0));
        if (time() - $since >= $days * 86400) {
            $o .= $box('自分のデータを' . ((int)($me['dl_last'] ?? 0) > 0 ? ' ' . h(date('Y-m-d', (int)$me['dl_last'])) . ' から' : 'まだ一度も') . 'ダウンロードしていません。'
                . '念のため、<a href="account.php#backup">マイアカウントのバックアップ</a> (センサの記録と写真も入れる) で、蜂場・メモ・作業記録・センサの記録を手元に保存しておきましょう。'
                . (glob(cams_dir($uid) . '/*/cam.json') ? h(dlr_cam_note()) . '残したい写真は、カメラのページの「この月の写真をまとめて保存」で保存できます。' : '')
                . ' <form method="post" class="inl" style="margin-left:6px">' . $csrfField . '<input type="hidden" name="act" value="dl_later">'
                . '<button type="submit" class="link">あとで (' . DLR_SNOOZE_DAYS . '日後にまた出す)</button></form>');
        }
    }
    return $o;
}

// 「あとで」を押した
function dl_snooze(string $uid): void
{
    if (!is_uid($uid)) return;
    $days = dlr_days('HPX_USER_DL_REMIND_DAYS', 90);
    // dl_snooze は「この時刻から数え直す」の意味。DLR_SNOOZE_DAYS 日後にまた出るように、さかのぼった時刻にする
    user_set($uid, ['dl_snooze' => time() - max(0, $days - DLR_SNOOZE_DAYS) * 86400]);
}

// サーバー管理者へのメール (hpx_cron.php から。前回のダウンロードかメールから $HPX_EXPORT_REMIND_DAYS 日ごとに1回)
function dl_remind_mail(): void
{
    $last = export_all_due();
    if ($last === null) return;
    $days = dlr_days('HPX_EXPORT_REMIND_DAYS', 30);
    $mailed = (int)(export_all_last()['mailed'] ?? 0);
    if ($mailed > 0 && time() - $mailed < $days * 86400) return;
    $to = server_admin_emails();
    if (!$to) return;
    json_update(data_dir() . '/export_all_last.json', function (array $v) { $v['mailed'] = time(); return $v; });
    foreach ($to as $addr) {
        account_mail($addr, '【HPX】全アカウントのデータを保存してください',
            "全アカウントのデータを" . ($last > 0 ? ' ' . date('Y-m-d', $last) . ' から' : 'まだ一度も') . "ダウンロードしていません。\n"
            . "センサの記録・メモ・作業記録・写真など、研究用のデータをなくさないように、定期的に全部を手元へ保存してください。\n" . dlr_cam_note() . "\n\n"
            . "サーバー管理のページ: " . site_url('admin.php#export_all') . "\n");
    }
}
