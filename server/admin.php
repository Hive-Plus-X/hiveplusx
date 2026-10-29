<?php
// 管理ページ(サイト管理者だけ): アカウントの発行・停止・再開・削除、パスワード再設定のリンク、サイト管理者の追加。
//   発行すると招待メールが届き、本人がリンクを開いてパスワードを決めると使えるようになる。
//   メールが届かないときのために、招待・再設定のリンクはこの画面にも出す(本人に別の方法で伝えられる)。
//   削除したアカウントのデータは消さずに、データフォルダの deleted/<アカウントID>-<日時>/ に移す。
declare(strict_types=1);
require __DIR__ . '/hpx_site.php';
if (empty($ME['admin'])) {
    http_response_code(403);
    exit('このページはサイト管理者だけが開けます。');
}

// 招待・再設定のメールを送る。画面に出すリンクを $_SESSION['admin_link'] に置く
function send_link(array $u, string $kind): void
{
    global $ME;
    $url = link_url(link_issue($kind, (string)$u['id']));
    $invite = $kind === 'invite';
    [$ok] = account_mail((string)$u['email'], $invite ? '【HPX】アカウントのご案内' : '【HPX】パスワードの再設定',
        ($invite
            ? $u['name'] . " さん\n\n" . $ME['name'] . " さんが、HPX 蜂場管理のアカウントを作りました。\n次のリンクを開いて、パスワードを決めてください (" . A_LINK_HOURS . "時間有効)。\n"
              . "ログインには、このメールアドレス (" . $u['email'] . ") を使います。\n\n"
            : $u['name'] . " さん\n\nサイト管理者が、パスワードの再設定のリンクを出しました。\n次のリンクを開いて、新しいパスワードを決めてください (" . A_LINK_HOURS . "時間有効)。\n\n")
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
    if ($act === 'demo_save') {
        if (!demo_seed_save()) redirect('admin.php#demo', 'デモ用アカウントが見つかりません (config.php の $HPX_DEMO_EMAIL)。');
        demo_set_editing(false);
        redirect('admin.php#demo', 'デモ用アカウントの今の状態を、初期状態として保存しました。');
    }
    if ($act === 'demo_edit_on') { demo_set_editing(true); redirect('admin.php#demo', '見本の準備中にしました。デモ用アカウントでログインして見本を直し、終わったらここで「初期状態として保存」を押してください。'); }
    if ($act === 'demo_reset') redirect('admin.php#demo', demo_reset() ? 'デモ用アカウントを初期状態に戻しました。' : '初期状態がまだ保存されていません。');
    $u = user_get((string)($_POST['uid'] ?? ''));
    if (!$u) redirect('admin.php');
    $self = $u['id'] === $UID;
    $nm = '「' . $u['name'] . '」';
    if ($act === 'invite' && ($u['status'] ?? '') === 'invited') { send_link($u, 'invite'); redirect('admin.php', $nm . 'に招待メールを送り直しました。'); }
    if ($act === 'reset' && ($u['status'] ?? '') === 'active') { send_link($u, 'reset'); redirect('admin.php', $nm . 'のパスワード再設定のリンクを出しました。'); }
    if ($self) redirect('admin.php', '自分のアカウントは、ここでは停止・削除・権限の変更をできません。');
    if ($act === 'stop') {
        user_set($u['id'], ['status' => 'stopped', 'status_before' => (string)$u['status']]);
        redirect('admin.php', $nm . 'を停止しました。ログインできず、HPX-Core からの記録も受け付けません (データは残っています)。');
    }
    if ($act === 'start' && ($u['status'] ?? '') === 'stopped') {
        user_set($u['id'], ['status' => (string)($u['pw'] ?? '') !== '' ? 'active' : 'invited']);
        redirect('admin.php', $nm . 'を再開しました。');
    }
    // サイト管理者 / 一般アカウント の切り替え(一覧の「権限」のプルダウン。admin_on / admin_off は前の版のボタン)
    if ($act === 'admin_set') $act = (string)($_POST['role'] ?? '') === 'admin' ? 'admin_on' : 'admin_off';
    if ($act === 'admin_on' || $act === 'admin_off') {
        if (is_demo($u) && $act === 'admin_on') redirect('admin.php', 'デモ用アカウントはサイト管理者にできません。');
        if (!empty($u['admin']) === ($act === 'admin_on')) redirect('admin.php');
        user_set($u['id'], ['admin' => $act === 'admin_on']);
        redirect('admin.php', $nm . ($act === 'admin_on' ? 'をサイト管理者にしました。' : 'を一般アカウントにしました。'));
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

page_head('管理', 'admin.php');
echo flash_html();
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
    . '<p><label><input type="checkbox" name="admin" value="1"> サイト管理者にする (アカウントを発行・停止できる)</label></p>'
    . '<button type="submit">発行して招待メールを送る</button></form></div></details>';

$users = users_all();
uasort($users, fn($a, $b) => (int)($a['created'] ?? 0) <=> (int)($b['created'] ?? 0));
$stText = ['active' => '<span class="ok">利用中</span>', 'invited' => '<span class="warn">招待中</span>', 'stopped' => '<span class="ng">停止中</span>'];
echo '<h2>アカウント (' . count($users) . ')</h2><div class="wrap"><table><tr><th>表示名</th><th>メールアドレス</th><th>権限</th><th>状態</th><th>HPX-Core</th><th>最終ログイン</th><th>操作</th></tr>';
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
        $role = h($isAdm ? 'サイト管理者' : '一般アカウント') . (is_demo($u) ? ' <span class="muted">(デモ用)</span>' : '');
    } else {
        $role = '<form class="inl" method="post">' . csrf_field() . '<input type="hidden" name="uid" value="' . h($uid) . '"><input type="hidden" name="act" value="admin_set">'
            . '<select name="role" onchange="this.form.submit()"><option value="admin"' . ($isAdm ? ' selected' : '') . '>サイト管理者</option>'
            . '<option value="general"' . (!$isAdm ? ' selected' : '') . '>一般アカウント</option></select></form>';
    }
    echo '<tr><td>' . h((string)($u['name'] ?? '')) . ($uid === $UID ? ' <span class="muted">(自分)</span>' : '') . '</td>'
        . '<td>' . h((string)($u['email'] ?? '')) . '</td><td style="white-space:nowrap">' . $role . '</td><td>' . ($stText[$st] ?? h($st)) . '</td><td class="n">' . $hubs . ' 台</td>'
        . '<td>' . (!empty($u['last_login']) ? h(date('Y-m-d H:i', (int)$u['last_login'])) : '<span class="muted">-</span>') . '</td><td>' . $ops . '</td></tr>';
}
echo '</table></div>';
echo '<p class="muted">停止したアカウントはログインできず、その HPX-Core からの記録も受け付けません。削除は停止してからできます (データは deleted/ フォルダに残ります)。</p>';

// デモ用アカウント
if (demo_email() !== '') {
    $seedAt = is_dir(demo_seed_dir()) ? date('Y-m-d H:i', (int)filemtime(demo_seed_dir())) : '';
    echo '<h2 id="demo">デモ用アカウント</h2><div class="card form">'
        . (demo_uid() === '' ? '<p class="ng">' . h(demo_email()) . ' のアカウントがありません。先に発行してください。</p>'
            : '<p>' . h(demo_email()) . ' は誰でも試せるデモ用です。設定・チームの変更、写真の追加、メールの送信はできません。'
              . (!empty($HPX_DEMO_READONLY) ? '書き込みもできません ($HPX_DEMO_READONLY)。' : '毎日 ' . (int)($HPX_DEMO_RESET_HOUR ?? 3) . '時ごろに初期状態へ戻します。') . '</p>'
              . (demo_editing() ? '<p class="warn">見本の準備中です (写真も追加でき、毎日の初期化は止めています)。デモ用アカウントで見本を直したら「今の状態を初期状態として保存」を押してください。準備中は終わります。</p>' : '')
              . '<p class="muted">初期状態: ' . ($seedAt !== '' ? h($seedAt) . ' に保存' : 'まだ保存していません (保存するまでは戻しません)') . '</p>'
              . (!demo_editing() ? '<form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="act" value="demo_edit_on"><button type="submit">見本を直す (準備中にする)</button></form> ' : '')
              . '<form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="act" value="demo_save"><button type="submit" onclick="return confirm(\'デモ用アカウントの今の状態を初期状態にしますか？\')">今の状態を初期状態として保存</button></form> '
              . ($seedAt !== '' ? '<form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="act" value="demo_reset"><button type="submit" onclick="return confirm(\'デモ用アカウントを初期状態に戻しますか？\')">今すぐ初期状態に戻す</button></form>' : ''))
        . '</div>';
}
$log = @file(data_dir() . '/account_mail.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (is_array($log) && $log) {
    echo '<details><summary>アカウントのメールの送信履歴</summary><div class="wrap"><table>';
    foreach (array_slice(array_reverse($log), 0, 30) as $ln) echo '<tr><td>' . h($ln) . '</td></tr>';
    echo '</table></div></details>';
}
echo '</body></html>';
