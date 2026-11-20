<?php
// ユーザー管理ページ(サーバー管理者だけ。前の名前は「アカウント管理」→「サーバー管理」。2026-11-20 TARO の指定で「サーバーの設定」と区別するため「ユーザー管理」に): アカウントの発行・停止・再開・削除、パスワード再設定のリンク、サーバー管理者の追加。
//   アカウントの登録方法の切り替え (招待 / 申請→承認 / 自由登録) と、届いた申請の承認・お断り。
//   全アカウントのデータのダウンロード (データ利用に同意したアカウントの分。export_all.php)。
//   発行すると招待メールが届き、本人がリンクを開いてパスワードを決めると使えるようになる。
//   メールが届かないときのために、招待・再設定のリンクはこの画面にも出す(本人に別の方法で伝えられる)。
//   削除したアカウントのデータは消さずに、データフォルダの deleted/<アカウントID>-<日時>/ に移す。
declare(strict_types=1);
require __DIR__ . '/hpx_site.php';
if (empty($ME['admin'])) {
    http_response_code(403);
    exit('このページはサーバー管理者だけが開けます。');
}

// 招待・再設定のメールを送る。画面に出すリンクを $_SESSION['admin_link'] に置く
function send_link(array $u, string $kind): void
{
    global $ME;
    $url = link_url(link_issue($kind, (string)$u['id']));
    $approve = $kind === 'approve';   // 登録の申請を承認したとき (リンクは招待と同じ)
    if ($approve) $kind = 'invite';
    $invite = $kind === 'invite';
    [$ok] = account_mail((string)$u['email'], $invite ? '【HPX】アカウントのご案内' : '【HPX】パスワードの再設定',
        ($approve
            ? $u['name'] . " さん\n\nHPX 蜂場管理のアカウント登録の申請が承認されました。\n次のリンクを開いて、パスワードを決めてください (" . A_LINK_HOURS . "時間有効)。\n"
              . "ログインには、このメールアドレス (" . $u['email'] . ") を使います。\n\n"
            : ($invite
            ? $u['name'] . " さん\n\n" . $ME['name'] . " さんが、HPX 蜂場管理のアカウントを作りました。\n次のリンクを開いて、パスワードを決めてください (" . A_LINK_HOURS . "時間有効)。\n"
              . "ログインには、このメールアドレス (" . $u['email'] . ") を使います。\n\n"
            : $u['name'] . " さん\n\nサーバー管理者が、パスワードの再設定のリンクを出しました。\n次のリンクを開いて、新しいパスワードを決めてください (" . A_LINK_HOURS . "時間有効)。\n\n"))
        . $url . "\n");
    $_SESSION['admin_link'] = ['email' => (string)$u['email'], 'url' => $url, 'mailed' => $ok, 'kind' => $kind];
}

if (is_post()) {
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'create') {
        $email = email_norm((string)($_POST['email'] ?? ''));
        $name = str_in('name', 40);
        if (!email_ok($email) || $name === '') redirect('admin.php', 'メールアドレスと表示名を入れてください。');
        if (user_by_email($email)) redirect('admin.php', 'そのメールアドレスのアカウントはもうあります。');
        $uid = user_create($email, $name, !empty($_POST['admin']));
        if ($uid === '') redirect('admin.php', 'アカウントを作れませんでした。');
        send_link(user_get($uid), 'invite');
        redirect('admin.php', '「' . $name . '」のアカウントを作りました。');
    }
    if ($act === 'signup_set') {
        $m = (string)($_POST['signup'] ?? '');
        if (!isset(A_SIGNUP_MODES[$m])) redirect('admin.php#signup');
        server_conf_set(['signup' => $m]);
        redirect('admin.php#signup', 'アカウントの登録方法を「' . A_SIGNUP_MODES[$m] . '」にしました。');
    }
    $u = user_get((string)($_POST['uid'] ?? ''));
    if (!$u) redirect('admin.php');
    $self = $u['id'] === $UID;
    $nm = '「' . $u['name'] . '」';
    // 登録の申請: 承認すると招待中になり、パスワードを決めるリンクをメールで送る。断ると申請を消す
    if (($u['status'] ?? '') === 'pending') {
        if ($act === 'approve') {
            user_set($u['id'], ['status' => 'invited', 'approved' => time(), 'approved_by' => $UID]);
            send_link(user_get($u['id']), 'approve');
            redirect('admin.php', $nm . 'の申請を承認し、案内のメールを送りました。');
        }
        if ($act === 'reject') {
            if (is_dir(account_dir($u['id']))) dir_remove(account_dir($u['id']));   // 申請のときに作った空のフォルダ
            token_forget($u['id']);
            users_update(function (array $us) use ($u) { unset($us[$u['id']]); return $us; });
            redirect('admin.php', $nm . 'の申請をお断りしました (申請を消しました。メールは送っていません)。');
        }
        redirect('admin.php');
    }
    if ($act === 'invite' && ($u['status'] ?? '') === 'invited') { send_link($u, 'invite'); redirect('admin.php', $nm . 'に招待メールを送り直しました。'); }
    if ($act === 'reset' && ($u['status'] ?? '') === 'active') { send_link($u, 'reset'); redirect('admin.php', $nm . 'のパスワード再設定のリンクを出しました。'); }
    if ($act === 'name_set') {
        $name = str_in('name', 40);
        if ($name === '') redirect('admin.php', '表示名を入れてください。');
        user_set($u['id'], ['name' => $name]);
        redirect('admin.php', $nm . 'の表示名を「' . $name . '」にしました。');
    }
    if ($self) redirect('admin.php', '自分のアカウントは、ここでは停止・削除・権限の変更をできません。');
    if ($act === 'stop') {
        user_set($u['id'], ['status' => 'stopped', 'status_before' => (string)$u['status']]);
        redirect('admin.php', $nm . 'を停止しました。ログインできず、HPX-Core からの記録も受け付けません (データは残っています)。');
    }
    if ($act === 'start' && ($u['status'] ?? '') === 'stopped') {
        user_set($u['id'], ['status' => (string)($u['pw'] ?? '') !== '' ? 'active' : 'invited']);
        redirect('admin.php', $nm . 'を再開しました。');
    }
    // サーバー管理者 / 一般アカウント の切り替え(一覧の「権限」のプルダウン。admin_on / admin_off は前の版のボタン)
    if ($act === 'admin_set') $act = (string)($_POST['role'] ?? '') === 'admin' ? 'admin_on' : 'admin_off';
    if ($act === 'admin_on' || $act === 'admin_off') {
        if (is_demo($u) && $act === 'admin_on') redirect('admin.php', 'デモ用アカウントはサーバー管理者にできません。');
        if (!empty($u['admin']) === ($act === 'admin_on')) redirect('admin.php');
        user_set($u['id'], ['admin' => $act === 'admin_on']);
        redirect('admin.php', $nm . ($act === 'admin_on' ? 'をサーバー管理者にしました。' : 'を一般アカウントにしました。'));
    }
    if ($act === 'delete') {
        if (($u['status'] ?? '') !== 'stopped') redirect('admin.php', '削除する前に、アカウントを停止してください。');
        if (email_norm((string)($_POST['confirm'] ?? '')) !== $u['email']) redirect('admin.php', '確認のメールアドレスが違います。削除していません。');
        $dest = data_dir() . '/deleted/' . $u['id'] . '-' . date('Ymd-His');
        @mkdir(dirname($dest), 0700, true);
        if (is_dir(account_dir($u['id'])) && !@rename(account_dir($u['id']), $dest)) redirect('admin.php', 'データを移せませんでした。削除していません。');
        token_forget($u['id']);
        team_forget_user($u['id']);
        users_update(function (array $us) use ($u) { unset($us[$u['id']]); return $us; });
        redirect('admin.php', $nm . 'を削除しました。データはデータフォルダの deleted/' . basename($dest) . '/ に残っています。');
    }
    redirect('admin.php');
}

page_head('ユーザー管理', 'admin.php');
echo flash_html();
echo '<p><a href="settings.php">⚙ サーバーの設定 (メールの送信・お知らせの基準・AI・デモなど) を変える</a></p>';
$link = $_SESSION['admin_link'] ?? null;
unset($_SESSION['admin_link']);
if (is_array($link)) {
    echo '<div class="card flash"><p>' . ($link['mailed'] ? h($link['email']) . ' にメールを送りました。' : '<span class="ng">' . h($link['email']) . ' へのメールを送れませんでした。</span> 下のリンクを本人に伝えてください。')
        . '</p><p><label class="muted">' . ($link['kind'] === 'invite' ? '招待' : 'パスワード再設定') . 'のリンク (' . A_LINK_HOURS . '時間有効。この画面を離れると、もう出ません)</label>'
        . '<input type="text" readonly value="' . h($link['url']) . '" onclick="this.select()"></p></div>';
}

echo '<details class="addbox"><summary><span class="plus">＋</span>アカウントを発行</summary><div class="card form"><form method="post">' . csrf_field()
    . '<input type="hidden" name="act" value="create">'
    . '<p><label>メールアドレス (ログインに使います)</label><input type="email" name="email" required style="width:100%"></p>'
    . '<p><label>表示名</label><input type="text" name="name" maxlength="40" required></p>'
    . '<p><label><input type="checkbox" name="admin" value="1"> サーバー管理者にする (アカウントを発行・停止できる)</label></p>'
    . '<p class="muted">招待された人は、メールのリンクからパスワードを決めるときに、データの利用への同意にチェックします。</p>'
    . '<button type="submit">発行して招待メールを送る</button></form></div></details>';

$users = users_all();
uasort($users, fn($a, $b) => (int)($a['created'] ?? 0) <=> (int)($b['created'] ?? 0));
// 登録の申請 (承認待ち)
$pending = array_filter($users, fn($u) => ($u['status'] ?? '') === 'pending');
$users = array_filter($users, fn($u) => ($u['status'] ?? '') !== 'pending');
if ($pending) {
    echo '<h2 id="apply">登録の申請 (' . count($pending) . ')</h2><div class="wrap"><table><tr><th>表示名</th><th>メールアドレス</th><th>ひとこと</th><th>申請日時</th><th>操作</th></tr>';
    foreach ($pending as $uid => $u) {
        $f = fn(string $act, string $label, string $confirm) => '<form class="inl" method="post" onsubmit="return window.confirm(\'' . h($confirm) . '\')">' . csrf_field()
            . '<input type="hidden" name="uid" value="' . h((string)$uid) . '"><input type="hidden" name="act" value="' . $act . '"><button type="submit">' . h($label) . '</button></form> ';
        echo '<tr><td>' . h((string)($u['name'] ?? '')) . '</td><td>' . h((string)($u['email'] ?? '')) . '</td><td>' . h((string)($u['apply_note'] ?? '')) . '</td>'
            . '<td>' . h(date('Y-m-d H:i', (int)($u['created'] ?? 0))) . '</td>'
            . '<td>' . $f('approve', '承認する', $u['name'] . ' さんを承認して、案内のメールを送ります。よろしいですか?')
            . $f('reject', 'お断りする', $u['name'] . ' さんの申請を消します (メールは送りません)。よろしいですか?') . '</td></tr>';
    }
    echo '</table></div><p class="muted">承認すると、パスワードを決めるリンクがメールで届きます (' . A_LINK_HOURS . '時間有効)。</p>';
}
$stText = ['active' => '<span class="ok">利用中</span>', 'invited' => '<span class="warn">招待中</span>', 'stopped' => '<span class="ng">停止中</span>'];
echo '<h2>アカウント (' . count($users) . ')</h2><div class="wrap"><table><tr><th>表示名</th><th>メールアドレス</th><th>権限</th><th>状態</th><th>データ利用の同意</th><th>HPX-Core</th><th>最終ログイン</th><th>操作</th></tr>';
foreach ($users as $uid => $u) {
    $uid = (string)$uid;
    $st = (string)($u['status'] ?? '');
    $hubs = is_uid($uid) ? count(glob(account_hubs_dir($uid) . '/*/state.json') ?: []) : 0;
    $btn = fn(string $act, string $label, string $confirm = '') => '<form class="inl" method="post"' . ($confirm !== '' ? ' onsubmit="return window.confirm(\'' . h($confirm) . '\')"' : '') . '>'
        . csrf_field() . '<input type="hidden" name="uid" value="' . h($uid) . '"><input type="hidden" name="act" value="' . $act . '">'
        . '<button class="link" type="submit">' . h($label) . '</button></form> ';
    $ops = '';
    if ($st === 'invited') $ops .= $btn('invite', '招待を送り直す');
    if ($st === 'active') $ops .= $btn('reset', 'パスワード再設定');
    if ($uid !== $UID) {
        $ops .= $st === 'stopped' ? $btn('start', '再開') : $btn('stop', '停止', $u['name'] . ' を停止します。よろしいですか?');
        if ($st === 'stopped') {
            $ops .= '<details style="margin:4px 0"><summary class="muted">削除</summary><form method="post">' . csrf_field()
                . '<input type="hidden" name="uid" value="' . h($uid) . '"><input type="hidden" name="act" value="delete">'
                . '<span class="muted">確認のためメールアドレスを入れてください</span><br><input type="text" name="confirm" autocomplete="off" required> '
                . '<button type="submit">削除</button></form></details>';
        }
    }
    // 権限: プルダウンで切り替える(選ぶとすぐ保存)。自分とデモ用アカウントは文字だけ
    $isAdm = !empty($u['admin']);
    if ($uid === $UID || is_demo($u)) {
        $role = h($isAdm ? 'サーバー管理者' : '一般アカウント') . (is_demo($u) ? ' <span class="muted">(デモ用)</span>' : '');
    } else {
        $role = '<form class="inl" method="post">' . csrf_field() . '<input type="hidden" name="uid" value="' . h($uid) . '"><input type="hidden" name="act" value="admin_set">'
            . '<select name="role" onchange="this.form.submit()"><option value="admin"' . ($isAdm ? ' selected' : '') . '>サーバー管理者</option>'
            . '<option value="general"' . (!$isAdm ? ' selected' : '') . '>一般アカウント</option></select></form>';
    }
    echo '<tr><td>' . h((string)($u['name'] ?? '')) . ($uid === $UID ? ' <span class="muted">(自分)</span>' : '')
        . '<details class="rn"><summary class="muted">名前を変更</summary><form method="post">' . csrf_field() . '<input type="hidden" name="uid" value="' . h($uid) . '"><input type="hidden" name="act" value="name_set">'
        . '<input type="text" name="name" maxlength="40" required value="' . h((string)($u['name'] ?? '')) . '"> <button type="submit">保存</button></form></details></td>'
        . '<td>' . h((string)($u['email'] ?? '')) . '</td><td style="white-space:nowrap">' . $role . '</td><td>' . ($stText[$st] ?? h($st)) . '</td>'
        . '<td>' . (is_demo($u) ? '<span class="muted">-</span>' : (consent_given($u) ? '<span class="ok">同意</span> <span class="muted">' . h(date('Y-m-d', (int)$u['consent'])) . '</span>' : '<span class="muted">まだ</span>')) . '</td>'
        . '<td class="n">' . $hubs . ' 台</td>'
        . '<td>' . (!empty($u['last_login']) ? h(date('Y-m-d H:i', (int)$u['last_login'])) : '<span class="muted">-</span>') . '</td><td>' . $ops . '</td></tr>';
}
echo '</table></div>';
echo '<p class="muted">停止したアカウントはログインできず、その HPX-Core からの記録も受け付けません。削除は停止してからできます (データは deleted/ フォルダに残ります)。'
    . '「データ利用の同意」が「まだ」の人は、前からのアカウントです。マイアカウントのページで同意できます。</p>';

// アカウントの登録方法
$mode = signup_mode();
echo '<h2 id="signup">アカウントの登録方法</h2><div class="card form"><form method="post">' . csrf_field() . '<input type="hidden" name="act" value="signup_set">';
$help = ['invite' => 'サーバー管理者が「＋アカウントを発行」で招待した人だけが使えます。',
    'apply' => 'ログインページに「アカウント登録を申請する」が出ます。届いた申請をこのページで承認すると、本人に案内のメールが届きます。',
    'open' => 'ログインページに「アカウントを登録する」が出ます。だれでもメールアドレスを入れるだけで登録できます (メールのリンクでパスワードを決めると使えます)。'];
foreach (A_SIGNUP_MODES as $k => $lb) {
    echo '<p><label style="display:flex;gap:6px;align-items:flex-start;cursor:pointer"><input type="radio" name="signup" value="' . $k . '"' . ($mode === $k ? ' checked' : '') . ' style="margin-top:.25em">'
        . '<span><b>' . h($lb) . '</b><br><span class="muted">' . h($help[$k]) . '</span></span></label></p>';
}
echo '<p class="muted">どの方法でも、登録するときに「データの利用への同意」へのチェックが要ります。</p>'
    . '<button type="submit">登録方法を保存</button></form>'
    . ($mode !== 'invite' ? '<p><label class="muted">登録のページ (人に伝えるとき)</label><input type="text" readonly value="' . h(site_url('login.php?signup=1')) . '" onclick="this.select()"></p>' : '')
    . '</div>';

// 全アカウントのデータのダウンロード
$nAll = count(array_filter($users, fn($u) => !is_demo($u)));
$nOk = count(array_filter($users, fn($u) => !is_demo($u) && consent_given($u)));
$exLast = export_all_last();
echo '<h2 id="export_all">全アカウントのデータのダウンロード</h2><div class="card form">'
    . '<p class="muted">前回のダウンロード: ' . (!empty($exLast['t']) ? h(date('Y-m-d H:i', (int)$exLast['t'])) : 'まだありません') . ' (' . dlr_days('HPX_EXPORT_REMIND_DAYS', 30) . ' 日あくと、お知らせを出します)</p>'
    . '<p>データ利用に同意したアカウント (' . $nOk . ' / ' . $nAll . ' 人) の、蜂場・巣箱・メモ・タスク・作業記録・センサの全期間の記録・機器と巣箱のつながりの履歴・AIメモで直した例を、アカウントごとのフォルダに分けて1つの ZIP にします。</p>'
    . '<p class="muted">同意していないアカウントと、デモ用アカウントの分は入りません。パスワードや機器用トークンは入りません。人数や記録が多いと、時間がかかり、ファイルも大きくなります。</p>'
    . '<form method="post" action="export_all.php">' . csrf_field()
    . '<p><label style="display:inline-flex;align-items:center;gap:4px"><input type="checkbox" name="photos" value="1" checked> 蜂場とメモの写真も入れる (大きくなることがあります)</label></p>'
    . '<p><label>HPX-CAM の写真</label>'
    . '<label style="display:inline-flex;align-items:center;gap:4px;margin-right:1em"><input type="radio" name="cams" value="none" checked> 入れない</label>'
    . '<label style="display:inline-flex;align-items:center;gap:4px;margin-right:1em"><input type="radio" name="cams" value="new"> 前回入れた日から' . ($exLast['cam_t'] ?? 0 ? ' (' . h(date('Y-m-d', (int)$exLast['cam_t'])) . ' 〜)' : ' (初めてなのですべて)') . '</label>'
    . '<label style="display:inline-flex;align-items:center;gap:4px"><input type="radio" name="cams" value="all"> すべて</label></p>'
    . '<p class="muted">カメラの写真はとても大きくなります (1台で1年に数GBになることがあります)。' . h(dlr_cam_note()) . '「前回入れた日から」で少しずつ保存すると軽くすみます。カメラの設定と、機器と巣箱のつながりの履歴はいつも入ります。</p>'
    . '<p><button type="submit"' . ($nOk === 0 ? ' disabled' : '') . '>全アカウントのデータをダウンロード</button></p></form></div>';

$log = @file(data_dir() . '/account_mail.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (is_array($log) && $log) {
    echo '<details><summary>アカウントのメールの送信履歴</summary><div class="wrap"><table>';
    foreach (array_slice(array_reverse($log), 0, 30) as $ln) echo '<tr><td>' . h($ln) . '</td></tr>';
    echo '</table></div></details>';
}
echo '</body></html>';
