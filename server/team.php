<?php
// チームページ: チームを作る、メンバーを入れる・役割を変える・外す、蜂場をチームに共有する。
//   だれでもチームを作れる(作った人が管理者)。1人がいくつのチームに入ってもよい。
//   管理者: メンバーの追加・役割の変更・削除、チーム名の変更、チームの削除、蜂場ごとの「見られる人」の設定、
//           自分の蜂場をチームに共有する(共有した蜂場の情報・巣箱は、チームの管理者なら変えられる)
//   メンバー: 共有された蜂場のうち、見られる蜂場のメモ・作業記録・タスクを書ける。自分の蜂場はチームに共有できない
//   メンバーに入れられるのは、アカウントがある人だけ(アカウントはサイト管理者が発行する)。デモ用アカウントは入れられない
//   (誰でも使えるので、入れるとチームの蜂場が誰にでも見えてしまう)。デモ用アカウントではこのページの操作もできない(demo_guard)。
//   保存先は hpx_team.php を参照。
declare(strict_types=1);
require __DIR__ . '/hpx_site.php';

// 自分がそのチームの管理者でなければ止める
function team_admin_or_back(string $tid): array
{
    global $UID;
    $t = team_get($tid);
    if (!$t) redirect('team.php');
    if (team_role($tid, $UID) !== 'admin') redirect('team.php#t-' . $tid, 'この操作はチーム管理者だけができます。');
    return $t;
}

// 持ち主の蜂場の共有先を直す。$fn は共有先(チームid => null | [アカウントid])を受け取って直したものを返す
function share_update(string $owner, string $aid, callable $fn): void
{
    site_update_of($owner, 'site', function (array $s) use ($aid, $fn) {
        if (!isset($s['apiaries'][$aid]) || !is_array($s['apiaries'][$aid])) return $s;
        $sh = $fn(apiary_share($s['apiaries'][$aid]));
        $s['apiaries'][$aid]['share'] = $sh ?: new stdClass();
        return $s;
    });
}

// その人の蜂場から、そのチームへの共有をすべて外す(チームを抜けた・チームを消したとき)
function share_forget(string $owner, string $tid): void
{
    if (!is_uid($owner) || !user_get($owner)) return;
    site_update_of($owner, 'site', function (array $s) use ($tid) {
        foreach ((is_array($s['apiaries'] ?? null) ? $s['apiaries'] : []) as $aid => $a) {
            if (is_array($a['share'] ?? null) && array_key_exists($tid, $a['share'])) {
                unset($s['apiaries'][$aid]['share'][$tid]);
                if (!$s['apiaries'][$aid]['share']) $s['apiaries'][$aid]['share'] = new stdClass();
            }
        }
        return $s;
    });
}

function admins_of(array $t): array { return array_keys(array_filter($t['members'], fn($r) => $r === 'admin')); }

if (is_post()) {
    $act = (string)($_POST['act'] ?? '');
    $tid = (string)($_POST['t'] ?? '');
    $back = 'team.php#t-' . $tid;

    if ($act === 'team_create') {
        $name = str_in('name', T_NAME_MAX);
        if ($name === '') redirect('team.php', 'チームの名前を入れてください。');
        $tid = team_create($name, $UID);
        redirect('team.php#t-' . $tid, 'チーム「' . $name . '」を作りました。メンバーを追加してください。');
    }
    if ($act === 'team_rename') {
        team_admin_or_back($tid);
        $name = str_in('name', T_NAME_MAX);
        if ($name === '') redirect($back, 'チームの名前を入れてください。');
        teams_update(function (array $ts) use ($tid, $name) { if (isset($ts[$tid])) $ts[$tid]['name'] = $name; return $ts; });
        redirect($back, 'チームの名前を「' . $name . '」にしました。');
    }
    if ($act === 'member_add') {
        $t = team_admin_or_back($tid);
        $u = user_by_email((string)($_POST['email'] ?? ''));
        $role = (string)($_POST['role'] ?? '') === 'admin' ? 'admin' : 'member';
        if (!$u || ($u['status'] ?? '') === 'stopped') redirect($back, 'そのメールアドレスのアカウントはありません。アカウントはサイト管理者が発行します。');
        if (is_demo($u)) redirect($back, 'デモ用アカウントはチームに入れられません (誰でも使えるため、チームの蜂場が誰にでも見えてしまいます)。');
        if (isset($t['members'][$u['id']])) redirect($back, (string)$u['name'] . ' さんはもうこのチームにいます。');
        teams_update(function (array $ts) use ($tid, $u, $role) { if (isset($ts[$tid])) $ts[$tid]['members'][$u['id']] = $role; return $ts; });
        account_mail((string)$u['email'], '【HPX】チーム「' . $t['name'] . '」に追加されました',
            $u['name'] . " さん\n\n" . $ME['name'] . ' さんが、あなたを HPX 蜂場管理のチーム「' . $t['name'] . '」に' . T_ROLES[$role] . "として追加しました。\n"
            . "チームに共有された蜂場は、蜂場ページなどに自分の蜂場と並んで出ます。\n\n" . site_url('team.php') . "\n");
        redirect($back, (string)$u['name'] . ' さんを' . T_ROLES[$role] . 'として追加しました。');
    }
    if ($act === 'member_role') {
        $t = team_admin_or_back($tid);
        $uid = (string)($_POST['uid'] ?? '');
        $role = (string)($_POST['role'] ?? '') === 'admin' ? 'admin' : 'member';
        if (!isset($t['members'][$uid])) redirect($back);
        if ($role === 'member' && admins_of($t) === [$uid]) redirect($back, 'チーム管理者が1人もいなくなるので変えられません。先にほかの人をチーム管理者にしてください。');
        teams_update(function (array $ts) use ($tid, $uid, $role) { if (isset($ts[$tid]['members'][$uid])) $ts[$tid]['members'][$uid] = $role; return $ts; });
        redirect($back, user_name($uid) . ' さんを' . T_ROLES[$role] . 'にしました。');
    }
    if ($act === 'member_remove') {
        // 管理者はほかの人を外せる。自分で抜けるのはだれでもできる
        $uid = (string)($_POST['uid'] ?? '');
        $t = $uid === $UID ? (team_get($tid) ?? redirect('team.php')) : team_admin_or_back($tid);
        if (!isset($t['members'][$uid])) redirect($back);
        if (admins_of($t) === [$uid] && count($t['members']) > 1) redirect($back, '最後のチーム管理者は抜けられません。先にほかの人をチーム管理者にするか、チームを削除してください。');
        teams_update(function (array $ts) use ($tid, $uid) {
            unset($ts[$tid]['members'][$uid]);
            if (isset($ts[$tid]) && !$ts[$tid]['members']) unset($ts[$tid]);   // だれもいなくなったチームは消す
            return $ts;
        });
        share_forget($uid, $tid);   // 抜けた人の蜂場は、このチームに共有しない
        redirect($uid === $UID ? 'team.php' : $back, $uid === $UID ? 'チーム「' . $t['name'] . '」を抜けました。' : user_name($uid) . ' さんをチームから外しました。');
    }
    if ($act === 'team_delete') {
        $t = team_admin_or_back($tid);
        if (trim((string)($_POST['confirm'] ?? '')) !== $t['name']) redirect($back, '確認のチーム名が違います。削除していません。');
        foreach (array_keys($t['members']) as $m) share_forget((string)$m, $tid);
        teams_update(function (array $ts) use ($tid) { unset($ts[$tid]); return $ts; });
        redirect('team.php', 'チーム「' . $t['name'] . '」を削除しました。蜂場と記録は持ち主のところに残っています。');
    }
    if ($act === 'share_add') {
        // 自分の蜂場を、自分が管理者のチームに共有する(初めはチームの全員が見られる)
        team_admin_or_back($tid);
        $aid = (string)($_POST['aid'] ?? '');
        if ((owner_site($UID)['apiaries'][$aid] ?? null) === null) redirect($back);
        share_update($UID, $aid, function (array $sh) use ($tid) { $sh[$tid] ??= null; return $sh; });
        redirect($back, '蜂場「' . (string)owner_site($UID, true)['apiaries'][$aid]['name'] . '」をチームに共有しました。');
    }
    if ($act === 'share_set') {
        // 見られる人: 全員 か 選んだメンバーだけ(管理者はいつも見られる)
        $t = team_admin_or_back($tid);
        $owner = (string)($_POST['owner'] ?? '');
        $aid = (string)($_POST['aid'] ?? '');
        if (!isset($t['members'][$owner]) || !array_key_exists($tid, apiary_share((array)(owner_site($owner)['apiaries'][$aid] ?? [])))) redirect($back);
        $sel = null;
        if ((string)($_POST['mode'] ?? '') === 'some') {
            $sel = array_values(array_filter(array_map('strval', (array)($_POST['members'] ?? [])), fn($m) => ($t['members'][$m] ?? '') === 'member'));
        }
        share_update($owner, $aid, function (array $sh) use ($tid, $sel) { $sh[$tid] = $sel; return $sh; });
        redirect($back, '見られる人を変えました。');
    }
    if ($act === 'share_remove') {
        // 共有をやめる: 蜂場の持ち主か、チームの管理者
        $owner = (string)($_POST['owner'] ?? '');
        $aid = (string)($_POST['aid'] ?? '');
        if ($owner !== $UID) team_admin_or_back($tid);
        if (!is_uid($owner) || !user_get($owner)) redirect($back);
        share_update($owner, $aid, function (array $sh) use ($tid) { unset($sh[$tid]); return $sh; });
        redirect($back, '共有をやめました。');
    }
    redirect('team.php');
}

page_head('チーム - ' . S_TITLE, 'team.php');
echo '<p class="muted" style="font-size:.85em;margin:0 0 .5em">同じ蜂場を複数の人で管理するときに使います。チームに共有した蜂場は、メンバーの蜂場ページ・巣箱ページ・メモ・作業記録に、自分の蜂場と並んで出ます。</p>'
    . flash_html();
if (is_demo($ME)) {
    echo '<div class="card"><p>デモ用アカウントでは、チームを作ったり入ったりはできません。</p>'
        . '<p class="muted">自分のアカウントでは、ここでチームを作り、メンバーをメールアドレスで追加して、蜂場ごとにチームへ共有できます。'
        . '共有した蜂場は、メンバーの蜂場・巣箱・メモ・作業記録のページに並んで出て、メモや作業記録には書いた人、タスクには担当者が出ます。</p></div></body></html>';
    exit;
}

$mine = teams_of($UID);
if (!$mine) echo '<p class="muted">まだチームに入っていません。下の「＋ チームを作る」から作れます。ほかの人のチームに入るときは、そのチーム管理者に、あなたのメールアドレス (' . h((string)$ME['email']) . ') で追加してもらってください。</p>';

$hidden = fn(string $tid) => csrf_field() . '<input type="hidden" name="t" value="' . h($tid) . '">';
foreach ($mine as $tid => $myRole) {
    $t = team_get($tid);
    $isAdmin = $myRole === 'admin';
    $mem = $t['members'];
    uksort($mem, fn($a, $b) => [$mem[$a] !== 'admin', user_name((string)$a)] <=> [$mem[$b] !== 'admin', user_name((string)$b)]);
    echo '<div class="card apiary" id="t-' . h($tid) . '"><h2 style="margin-top:.2em">' . h((string)$t['name'])
        . ' <span class="muted" style="font-size:.7em;font-weight:normal">あなたは' . T_ROLES[$myRole] . '</span></h2>';

    // ---- メンバー ----
    echo '<h3>メンバー (' . count($mem) . '人)</h3><div class="wrap"><table class="nw"><tr><th>名前</th>' . ($isAdmin ? '<th>メールアドレス</th>' : '') . '<th>役割</th><th></th></tr>';
    foreach ($mem as $m => $r) {
        $m = (string)$m;
        $u = user_get($m);
        echo '<tr><td>' . h(user_name($m)) . ($m === $UID ? ' <span class="muted">(自分)</span>' : '') . (($u['status'] ?? '') === 'stopped' ? ' <span class="muted">(停止中)</span>' : '') . '</td>'
            . ($isAdmin ? '<td>' . h((string)($u['email'] ?? '')) . '</td>' : '') . '<td>';
        if ($isAdmin && $u) {
            echo '<form method="post" class="inl">' . $hidden($tid) . '<input type="hidden" name="act" value="member_role"><input type="hidden" name="uid" value="' . h($m) . '">'
                . '<select name="role" onchange="this.form.submit()">';
            foreach (T_ROLES as $k => $lb) echo '<option value="' . $k . '"' . ($k === $r ? ' selected' : '') . '>' . h($lb) . '</option>';
            echo '</select></form>';
        } else echo h(T_ROLES[$r] ?? $r);
        echo '</td><td>';
        if ($m === $UID || $isAdmin) {
            echo '<form method="post" class="inl" onsubmit="return confirm(' . h(json_encode($m === $UID ? 'このチームを抜けますか?' : user_name($m) . ' さんをチームから外しますか?', JSON_UNESCAPED_UNICODE)) . ')">' . $hidden($tid)
                . '<input type="hidden" name="act" value="member_remove"><input type="hidden" name="uid" value="' . h($m) . '">'
                . '<button class="link" type="submit">' . ($m === $UID ? 'チームを抜ける' : '外す') . '</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</table></div>';
    if ($isAdmin) {
        echo '<details><summary>＋ メンバーを追加</summary><form method="post" class="form card">' . $hidden($tid) . '<input type="hidden" name="act" value="member_add">'
            . '<div class="grid2"><p><label>メールアドレス (その人のログインID)</label><input type="email" name="email" required style="width:100%"></p>'
            . '<p><label>役割</label><select name="role"><option value="member">メンバー</option><option value="admin">チーム管理者</option></select></p></div>'
            . '<p class="muted">アカウントがある人だけ追加できます。追加するとその人にメールで知らせます。アカウントが無い人は、サイト管理者に発行してもらってください。</p>'
            . '<p><button type="submit">追加</button></p></form></details>';
    }

    // ---- 共有している蜂場 ----
    $shared = team_apiaries($tid);
    echo '<h3 style="margin-top:1em">共有している蜂場</h3>';
    if (!$shared) echo '<p class="muted">まだありません。' . ($isAdmin ? '下の「＋ 蜂場を共有」から、自分の蜂場を共有できます。' : 'チーム管理者が蜂場を共有すると、ここと蜂場ページに出ます。') . '</p>';
    else {
        echo '<div class="wrap"><table><tr><th>蜂場</th><th>持ち主</th><th>見られる人</th>' . ($isAdmin ? '<th></th>' : '') . '</tr>';
        foreach ($shared as [$o, $aid, $a, $sel]) {
            $who = $sel === null ? 'チームの全員' : 'チーム管理者と ' . ($sel ? implode('・', array_map('user_name', array_filter($sel, fn($m) => ($mem[$m] ?? '') === 'member'))) : '(メンバーなし)');
            echo '<tr><td>' . h((string)($a['name'] ?? '')) . '</td><td>' . h(user_name($o)) . '</td><td>' . h($who);
            if ($isAdmin) {
                $members = array_filter($mem, fn($r) => $r === 'member');
                echo '<details><summary class="muted">変える</summary><form method="post" class="form">' . $hidden($tid)
                    . '<input type="hidden" name="act" value="share_set"><input type="hidden" name="owner" value="' . h($o) . '"><input type="hidden" name="aid" value="' . h($aid) . '">'
                    . '<p><label style="display:inline;color:#1F2328"><input type="radio" name="mode" value="all"' . ($sel === null ? ' checked' : '') . '> チームの全員</label>　'
                    . '<label style="display:inline;color:#1F2328"><input type="radio" name="mode" value="some"' . ($sel !== null ? ' checked' : '') . '> 選んだメンバーだけ (チーム管理者はいつも見られます)</label></p><p>';
                if (!$members) echo '<span class="muted">メンバー (チーム管理者でない人) はいません。</span>';
                foreach ($members as $m => $r) {
                    echo '<span style="display:inline-block;margin-right:14px"><input type="checkbox" name="members[]" value="' . h((string)$m) . '"'
                        . ($sel !== null && in_array((string)$m, $sel, true) ? ' checked' : '') . '> ' . h(user_name((string)$m)) . '</span>';
                }
                echo '</p><p><button type="submit">保存</button></p></form></details>';
            }
            echo '</td>';
            if ($isAdmin || $o === $UID) {
                echo '<td><form method="post" class="inl" onsubmit="return confirm(' . h(json_encode('「' . (string)($a['name'] ?? '') . '」の共有をやめますか? (蜂場と記録は持ち主のところに残ります)', JSON_UNESCAPED_UNICODE)) . ')">' . $hidden($tid)
                    . '<input type="hidden" name="act" value="share_remove"><input type="hidden" name="owner" value="' . h($o) . '"><input type="hidden" name="aid" value="' . h($aid) . '">'
                    . '<button class="link" type="submit">共有をやめる</button></form></td>';
            } elseif ($isAdmin) echo '<td></td>';
            echo '</tr>';
        }
        echo '</table></div>';
    }
    if ($isAdmin) {
        $sharedIds = array_map(fn($x) => $x[1], array_filter($shared, fn($x) => $x[0] === $UID));
        $cand = array_filter((array)(owner_site($UID)['apiaries'] ?? []), fn($a, $aid) => !in_array((string)$aid, $sharedIds, true), ARRAY_FILTER_USE_BOTH);
        echo '<details><summary>＋ 蜂場を共有</summary><div class="card form">';
        if (!$cand) echo '<p class="muted">共有できる自分の蜂場はありません (すべて共有済みか、まだ蜂場がありません)。</p>';
        else {
            echo '<form method="post">' . $hidden($tid) . '<input type="hidden" name="act" value="share_add"><p><select name="aid">';
            foreach ($cand as $aid => $a) echo '<option value="' . h((string)$aid) . '">' . h((string)($a['name'] ?? '')) . '</option>';
            echo '</select> <button type="submit">共有する</button></p><p class="muted">初めはチームの全員が見られます。見られる人は共有した後で選べます。</p></form>';
        }
        echo '</div></details>';

        echo '<details><summary>チームの設定</summary><div class="card form"><form method="post">' . $hidden($tid) . '<input type="hidden" name="act" value="team_rename">'
            . '<p><label>チームの名前</label><input type="text" name="name" maxlength="' . T_NAME_MAX . '" required value="' . h((string)$t['name']) . '"></p><p><button type="submit">名前を保存</button></p></form>'
            . '<div class="card danger"><p>チームを削除すると、共有はすべて外れます。蜂場・メモ・作業記録は、それぞれの蜂場の持ち主のところに残ります。</p>'
            . '<form method="post">' . $hidden($tid) . '<input type="hidden" name="act" value="team_delete">'
            . '<p><label>確認のため、チームの名前を入れてください</label><input type="text" name="confirm" required></p><p><button type="submit">チームを削除</button></p></form></div></div></details>';
    }
    echo '</div>';
}

echo '<details class="addbox" id="new"' . (!$mine ? ' open' : '') . '><summary><span class="plus">＋</span>チームを作る</summary>'
    . '<div class="card"><form method="post" class="form">' . csrf_field() . '<input type="hidden" name="act" value="team_create">'
    . '<p><label>チームの名前 (会社名・同好会の名前など)</label><input type="text" name="name" maxlength="' . T_NAME_MAX . '" required></p>'
    . '<p class="muted">作った人がチーム管理者になります。</p><p><button type="submit">作る</button></p></form></div></details>';
echo '</body></html>';
