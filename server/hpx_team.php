<?php
// HPX チームの共通部分。hpx_auth.php から読み込む(関数の定義だけ)。team.php・hpx_site.php・hpx_cron.php が使う。
//   チーム: データフォルダの teams.json
//     {"teams": {"t_3f9a1c7e": {name, created, by, members: {"u_…": "admin" | "member"}}}}
//     1人がいくつのチームに入ってもよい。役割は 管理者(admin) と メンバー(member) の2つ。
//   蜂場の共有: 蜂場の持ち主のアカウントの site/site.json で、蜂場ごとに
//     share: {"t_…": null(チームの全員) | ["u_…", …](選んだメンバーだけ)}
//     蜂場をチームに共有できるのは、そのチームの管理者でもある持ち主だけ。持ち主がチームを抜けると、その共有は効かなくなる。
//   蜂場での役割(apiary_role): owner(持ち主) / admin(共有先チームの管理者) / member(共有先チームのメンバーで、その蜂場を見られる人)
//     owner と admin は蜂場・巣箱の情報も変えられる。member はメモ・作業記録・タスクを書ける。蜂場の削除と共有は owner だけ。
//     同じ人が、ある蜂場では member、別の蜂場では admin や owner になってよい(役割は蜂場ごとに決まる)。
//   警報メール(電柵・HPX-Core・巣箱の警報)は、その蜂場を見られるチームの人にも届く(apiary_alert_to)。
declare(strict_types=1);

const T_NAME_MAX = 40;
const T_ROLES = ['admin' => 'チーム管理者', 'member' => 'メンバー'];   // 画面の呼び名。サイト管理者と区別するため「チーム管理者」(2026-10-28 TARO)

function is_tid(string $tid): bool { return (bool)preg_match('/^t_[0-9a-f]{8}$/', $tid); }

// チームの一覧 id => 内容。読むのは1回だけ(teams_update で読み直す)
function teams_all(bool $reload = false): array
{
    static $cache = null;
    if ($cache === null || $reload) {
        $t = json_load(data_dir() . '/teams.json')['teams'] ?? [];
        $cache = [];
        foreach (is_array($t) ? $t : [] as $tid => $x) {
            if (!is_array($x)) continue;
            $x['members'] = is_array($x['members'] ?? null) ? $x['members'] : [];
            $cache[(string)$tid] = $x;
        }
    }
    return $cache;
}

// $fn はチームの一覧(id => 内容)を受け取って直した一覧を返す
function teams_update(callable $fn): bool
{
    $ok = json_update(data_dir() . '/teams.json', function (array $d) use ($fn) {
        $d['teams'] = $fn(is_array($d['teams'] ?? null) ? $d['teams'] : []);
        return $d;
    });
    teams_all(true);
    return $ok;
}

function team_get(string $tid): ?array
{
    $t = teams_all()[$tid] ?? null;
    return $t ? ['id' => $tid] + $t : null;
}

// チームでの役割('admin' / 'member')。入っていなければ null。デモ用アカウントはどのチームにも入れない(入っていても無いものとする)
function team_role(string $tid, string $uid): ?string
{
    static $demo = null;
    $demo ??= demo_uid();
    if ($uid === '' || $uid === $demo) return null;
    $r = teams_all()[$tid]['members'][$uid] ?? null;
    return isset(T_ROLES[(string)$r]) ? (string)$r : null;
}

// その人が入っているチーム: id => 役割(名前の順)
function teams_of(string $uid): array
{
    $out = [];
    foreach (teams_all() as $tid => $t) if (($r = team_role($tid, $uid)) !== null) $out[$tid] = $r;
    uksort($out, fn($a, $b) => strcmp((string)teams_all()[$a]['name'], (string)teams_all()[$b]['name']) ?: strcmp($a, $b));
    return $out;
}

// チームを作る。作った人が管理者になる
function team_create(string $name, string $uid): string
{
    $tid = '';
    teams_update(function (array $ts) use ($name, $uid, &$tid) {
        do { $tid = 't_' . bin2hex(random_bytes(4)); } while (isset($ts[$tid]));
        $ts[$tid] = ['name' => $name, 'created' => time(), 'by' => $uid, 'members' => [$uid => 'admin']];
        return $ts;
    });
    return $tid;
}

// アカウントを消したときに、すべてのチームから外す(admin.php)
function team_forget_user(string $uid): void
{
    teams_update(function (array $ts) use ($uid) {
        foreach ($ts as &$t) if (is_array($t['members'] ?? null)) unset($t['members'][$uid]);
        unset($t);
        return $ts;
    });
}

// 持ち主のアカウントの site.json(読むだけ。1回の表示で何度も読まないようにとっておく)
function owner_site(string $owner, bool $reload = false): array
{
    static $cache = [];
    if ($reload) unset($cache[$owner]);
    if (!isset($cache[$owner])) $cache[$owner] = is_uid($owner) ? json_load(account_site_dir($owner) . '/site.json') : [];
    return $cache[$owner];
}

// 蜂場の共有先: チームid => null(全員) | [アカウントid, …]
function apiary_share(array $a): array
{
    $out = [];
    foreach (is_array($a['share'] ?? null) ? $a['share'] : [] as $tid => $sel) {
        if (!is_tid((string)$tid)) continue;
        $out[(string)$tid] = is_array($sel) ? array_values(array_map('strval', $sel)) : null;
    }
    return $out;
}

// 効いている共有先(チームがあり、持ち主がまだそのチームにいるもの)
function apiary_share_live(string $owner, array $a): array
{
    return array_filter(apiary_share($a), fn($sel, $tid) => team_role((string)$tid, $owner) !== null, ARRAY_FILTER_USE_BOTH);
}

// 蜂場での役割(hpx_auth.php の apiary_role から呼ぶ)。持ち主以外の分
function team_apiary_role(string $owner, string $apiaryId, string $uid): ?string
{
    $a = owner_site($owner)['apiaries'][$apiaryId] ?? null;
    if (!is_array($a)) return null;
    $best = null;
    foreach (apiary_share_live($owner, $a) as $tid => $sel) {
        $r = team_role($tid, $uid);
        if ($r === 'admin') return 'admin';
        if ($r === 'member' && ($sel === null || in_array($uid, $sel, true))) $best = 'member';
    }
    return $best;
}

// その人に蜂場を共有している持ち主(本人は入れない)。同じチームの人の site.json だけを見る
function shared_owners(string $uid): array
{
    $cand = [];
    foreach (teams_of($uid) as $tid => $r) foreach (teams_all()[$tid]['members'] as $m => $x) if ($m !== $uid) $cand[(string)$m] = 1;
    $out = [];
    foreach (array_keys($cand) as $o) {
        if (!is_uid($o) || !user_get($o)) continue;
        foreach (array_keys((array)(owner_site($o)['apiaries'] ?? [])) as $aid) {
            if (team_apiary_role($o, (string)$aid, $uid) !== null) { $out[] = $o; break; }
        }
    }
    return $out;
}

// チームに共有されている蜂場: [[持ち主, 蜂場id, 蜂場, 見られる人(null = 全員)], …]
function team_apiaries(string $tid): array
{
    $out = [];
    foreach (array_keys(teams_all()[$tid]['members'] ?? []) as $o) {
        $o = (string)$o;
        if (!is_uid($o)) continue;
        foreach ((array)(owner_site($o)['apiaries'] ?? []) as $aid => $a) {
            $sh = apiary_share_live($o, (array)$a);
            if (array_key_exists($tid, $sh)) $out[] = [$o, (string)$aid, (array)$a, $sh[$tid]];
        }
    }
    usort($out, fn($x, $y) => strcmp((string)($x[2]['name'] ?? ''), (string)($y[2]['name'] ?? '')));
    return $out;
}

// その蜂場を見られる人: アカウントid => 役割(owner / admin / member)。タスクの担当者を選ぶときに使う
function apiary_people(string $owner, string $apiaryId): array
{
    $out = [$owner => 'owner'];
    $a = owner_site($owner)['apiaries'][$apiaryId] ?? null;
    if (!is_array($a)) return $out;
    foreach (apiary_share_live($owner, $a) as $tid => $sel) {
        foreach (teams_all()[$tid]['members'] as $m => $r) {
            $m = (string)$m;
            if (isset($out[$m]) && ($out[$m] !== 'member' || $r !== 'admin')) continue;
            if ($r === 'admin' || $sel === null || in_array($m, $sel, true)) $out[$m] = $r === 'admin' ? 'admin' : 'member';
        }
    }
    return array_filter($out, fn($r, $m) => user_get((string)$m) !== null, ARRAY_FILTER_USE_BOTH);
}

// 警報メール(電柵・HPX-Core が止まった・巣箱の警報)の送り先: 蜂場の持ち主の通知先と、その蜂場を見られるチームの人の通知先。
//   停止中・招待中のアカウントとデモ用アカウントには送らない。同じアドレスは1つにまとめる。カンマ区切り
function apiary_alert_to(string $owner, array $apiaryIds): string
{
    $to = [account_notify($owner)];
    foreach (array_unique(array_map('strval', $apiaryIds)) as $aid) {
        foreach (apiary_people($owner, $aid) as $u => $r) {
            $u = (string)$u;
            if ($u === $owner || (users_all()[$u]['status'] ?? '') !== 'active' || is_demo(user_get($u))) continue;
            $to[] = account_notify($u);
        }
    }
    $list = [];
    foreach ($to as $x) foreach (explode(',', $x) as $e) if (($e = email_norm($e)) !== '') $list[$e] = 1;
    return implode(', ', array_keys($list));
}

// そのハブ(HPX-Core)か、そのハブの電柵センサを紐づけている、持ち主の蜂場
function hub_apiaries(string $owner, string $hubId): array
{
    $out = [];
    foreach ((array)(owner_site($owner)['apiaries'] ?? []) as $aid => $a) {
        $keys = array_map('strval', array_merge((array)($a['hubs'] ?? []), (array)($a['fences'] ?? [])));
        if (in_array($hubId, $keys, true) || array_filter($keys, fn($k) => str_starts_with($k, $hubId . '/'))) $out[] = (string)$aid;
    }
    return $out;
}
