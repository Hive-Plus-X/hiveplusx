<?php
// 記録ページ(work.php)とタスクページ(task.php): 済んだ作業にかかった時間の記録と、これからの作業(タスク)。どちらも蜂場単位・巣箱単位で書ける。
//   2つのページの中身はこのファイルにある。task.php は W_TASK_PAGE を決めてからこのファイルを読む(保存の処理はどちらから送っても同じ)。
//   タスクは蜂場ページ・巣箱ページにも短く出し、期限切れ・今日の件数をメニューに出す。
//   「メールで知らせる」にした タスクは、hpx_cron.php が毎朝まとめてメールする(期限の前日から。担当者がいれば担当者へ)。
//   チームに共有された蜂場の分は、蜂場の持ち主のフォルダに保存する(その蜂場を見られる人なら誰でも書ける)。
declare(strict_types=1);
require __DIR__ . '/hpx_site.php';

$site = site_load();
$fa = (string)($_GET['a'] ?? '');
if (!isset($site['apiaries'][$fa])) $fa = '';
$month = (string)($_GET['m'] ?? date('Y-m', $now));
if ($month !== 'all' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) $month = date('Y-m', $now);   // all = 作業記録をすべての月で
$works = works_load();
$taskPage = defined('W_TASK_PAGE');
$fp = (string)($_GET['p'] ?? '');   // 記録ページのグラフの担当者
if (!is_uid($fp)) $fp = '';
$page = $taskPage ? 'task.php' : 'work.php';

// タスクのタグの初期の一覧。カスタマイズしたら site/work_tags.json に保存する
const W_TAGS_DEFAULT = ['内検', '採蜜', '給餌', 'ダニ検査', '薬剤処理（入出）'];
function work_tags(): array { return tag_list('work_tags', W_TAGS_DEFAULT); }

// 書き先を決める。新しく書くときは対象の蜂場の持ち主、前からあるものはそれを持っている人。
// 前からあるものの対象を、ほかの人の蜂場へは移せない
function work_owner(array $site, ?array $tg, ?array $ex, string $back): void
{
    // 対象が「すべて」(蜂場が空)なら自分
    $owner = $tg ? ($tg[0] === '' ? $GLOBALS['UID'] : apiary_owner($site, $tg[0])) : (string)($ex['_o'] ?? '');
    if ($owner === '' || ($ex && $tg && $ex['_o'] !== $owner)) redirect($back, 'ほかの人の蜂場へは移せません。新しく書いてください。');
    site_use($owner);
}

// タスクの担当者に選べる人: その蜂場を見られる人。チーム機能を使っていなければ空(担当者の欄を出さない)
function assignee_options(array $site, string $aid): array
{
    if (!team_on()) return [];
    if ($aid === '') return [$GLOBALS['UID'] => user_name($GLOBALS['UID'])];   // 対象「すべて」は自分にだけ見える
    if (!isset($site['apiaries'][$aid])) return [];
    $out = [];
    foreach (apiary_people(apiary_owner($site, $aid), $aid) as $u => $r) $out[(string)$u] = user_name((string)$u);
    if (demo_members_on()) foreach (DEMO_MEMBERS as $u => $nm) $out[$u] = $nm;   // デモの架空のメンバー
    asort($out);
    return $out;
}

$selfUrl = fn(array $ch = [], string $anchor = '') => $page . '?' . http_build_query(array_filter(array_merge(['a' => $fa, 'm' => $month, 'p' => $fp], $ch), fn($v) => $v !== '')) . ($anchor !== '' ? '#' . $anchor : '');

if (is_post()) {
    $act = (string)($_POST['act'] ?? '');
    $back = safe_back((string)($_POST['back'] ?? ''), $page);
    $id = (string)($_POST['id'] ?? '');
    tag_post($act, 'work_tags', W_TAGS_DEFAULT);   // タグの一覧の変更(JSON を返して終わる)
    if ($act === 'ical_new') {
        ical_issue($UID);
        redirect('task.php#ical', 'カレンダーのURLを作りました。' . (!empty($_POST['re']) ? '前のURLは使えなくなったので、Googleカレンダーに登録し直してください。' : ''));
    }
    if ($act === 'ical_stop') {
        ical_forget($UID);
        redirect('task.php#ical', 'カレンダーへの配信を止めました。Googleカレンダーからも、このカレンダーを削除してください。');
    }
    if ($act === 'todo_save') {
        $tg = parse_target_pair($site);
        $title = str_in('title', 200);
        if (!$tg || ($title === '' && !tags_in())) redirect($back, '対象と、やることかタグを入れてください。');
        $ex = record_find($works['todos'], $id);
        if ($id !== '' && !$ex) redirect($back);
        work_owner($site, $tg, $ex, $back);
        // 担当者はその蜂場を見られる人だけ(違えば「未定」)
        $opts = assignee_options($site, $tg[0]);
        $as = array_values(array_unique(array_filter(array_map('strval', (array)($_POST['assignees'] ?? [])), fn($u) => isset($opts[$u]))));
        $newId = $id !== '' ? $id : new_id();
        site_update('works', function (array $w) use ($newId, $tg, $title, $as) {
            $w['todos'] = is_array($w['todos'] ?? null) ? $w['todos'] : [];
            $row = ['id' => $newId, 'apiary' => $tg[0], 'hive' => $tg[1], 'tags' => tags_in(), 'title' => $title, 'due' => date_in('due'), 'assignees' => $as, 'assignee' => '',
                'notify' => !empty($_POST['notify']), 'note' => str_in('note'), 'updated' => time(), 'updated_by' => $GLOBALS['UID']];
            foreach ($w['todos'] as &$x) if (($x['id'] ?? '') === $newId) { $x = array_merge($x, $row); return $w; }
            unset($x);
            $w['todos'][] = $row + ['created' => time(), 'by' => $GLOBALS['UID'], 'done' => false];   // by: 記入者 / assignees: 担当者(複数。前の版は assignee に1人)
            return $w;
        });
        redirect($back, 'タスクを保存しました。');
    }
    if ($act === 'todo_done' || $act === 'todo_undo') {
        $done = $act === 'todo_done';
        $title = '';
        work_owner($site, null, record_find($works['todos'], $id), $back);
        site_update('works', function (array $w) use ($id, $done, &$title) {
            foreach ($w['todos'] as &$x) {
                if (($x['id'] ?? '') !== $id) continue;
                $x['done'] = $done;
                $x['done_at'] = $done ? time() : null;
                $x['done_by'] = $done ? $GLOBALS['UID'] : null;
                $title = todo_title($x);
            }
            unset($x);
            return $w;
        });
        redirect($back, $title === '' ? '' : ($done ? '「' . $title . '」を完了にしました。作業時間は「作業記録」に残せます。' : '「' . $title . '」を未完了に戻しました。'));
    }
    if ($act === 'todo_copy') {
        // 選んだ蜂場それぞれに、同じ内容の タスクを新しく作る(蜂場全体の タスクとして)
        $title = str_in('title', 200);
        $targets = array_values(array_unique(array_filter(array_map('strval', (array)($_POST['targets'] ?? [])), fn($v) => isset($site['apiaries'][$v]))));
        if (($title === '' && !tags_in()) || !$targets) redirect($back, 'コピー先の蜂場と、やることかタグを入れてください。');
        // コピー先の蜂場の持ち主ごとに書く(担当者は「未定」)
        $byOwner = [];
        foreach ($targets as $aid) $byOwner[apiary_owner($site, $aid)][] = $aid;
        foreach ($byOwner as $owner => $aids) {
            site_update_of((string)$owner, 'works', function (array $w) use ($aids, $title) {
                $w['todos'] = is_array($w['todos'] ?? null) ? $w['todos'] : [];
                foreach ($aids as $aid) {
                    $w['todos'][] = ['id' => new_id(), 'apiary' => $aid, 'hive' => '', 'tags' => tags_in(), 'title' => $title, 'due' => date_in('due'),
                        'notify' => !empty($_POST['notify']), 'note' => str_in('note'), 'updated' => time(), 'created' => time(), 'by' => $GLOBALS['UID'], 'assignees' => [], 'done' => false];
                }
                return $w;
            });
        }
        $names = array_map(fn($a) => (string)$site['apiaries'][$a]['name'], $targets);
        redirect($back, '「' . todo_title(['title' => $title, 'tags' => tags_in()]) . '」を ' . implode('・', $names) . ' にコピーしました。');
    }
    if ($act === 'todo_delete') {
        work_owner($site, null, record_find($works['todos'], $id), $back);
        site_update('works', function (array $w) use ($id) {
            $w['todos'] = array_values(array_filter($w['todos'] ?? [], fn($x) => ($x['id'] ?? '') !== $id));
            return $w;
        });
        redirect($back, 'タスクを削除しました。');
    }
    if ($act === 'log_save') {
        $tg = parse_target_pair($site);
        $type = (string)($_POST['type'] ?? '');
        if (!in_array($type, S_WORK_TYPES, true)) $type = 'その他';
        $start = time_in('start');
        $end = time_in('end');
        $min = (int)($_POST['minutes'] ?? 0);
        if ($start !== '' && $end !== '') {
            $min = (int)((strtotime('2000-01-01 ' . $end) - strtotime('2000-01-01 ' . $start)) / 60);
            if ($min <= 0) $min += 1440;   // 日をまたいだとき
        }
        if (!$tg || $min <= 0 || $min > 1440) redirect($back, '対象と、かかった時間 (開始と終了、または分) を入れてください。');
        $ex = record_find($works['logs'], $id);
        if ($id !== '' && (!$ex || !empty($ex['demo']))) redirect($back, $ex ? '見本の作業記録は変更できません。' : '');
        work_owner($site, $tg, $ex, $back);
        $date = date_in('date', date('Y-m-d'));
        $newId = $id !== '' ? $id : new_id();
        // 担当者(作業した人。複数)。人数は担当者の数より少なくしない
        $opts = assignee_options($site, $tg[0]);
        $workers = array_values(array_unique(array_filter(array_map('strval', (array)($_POST['workers'] ?? [])), fn($u) => isset($opts[$u]))));
        site_update('works', function (array $w) use ($newId, $tg, $type, $start, $end, $min, $date, $workers) {
            $w['logs'] = is_array($w['logs'] ?? null) ? $w['logs'] : [];
            $row = ['id' => $newId, 'apiary' => $tg[0], 'hive' => $tg[1], 'date' => $date, 'type' => $type, 'workers' => $workers,
                'start' => $start, 'end' => $end, 'minutes' => $min, 'people' => max(1, count($workers), min(99, (int)($_POST['people'] ?? 1))),
                'note' => str_in('note'), 'updated' => time(), 'updated_by' => $GLOBALS['UID']];
            foreach ($w['logs'] as &$x) if (($x['id'] ?? '') === $newId) { $x = array_merge($x, $row); return $w; }
            unset($x);
            $w['logs'][] = $row + ['created' => time(), 'by' => $GLOBALS['UID']];
            return $w;
        });
        redirect('work.php?' . http_build_query(array_filter(['a' => $fa, 'm' => substr($date, 0, 7)])) . '#log', '作業記録を保存しました。');
    }
    if ($act === 'log_delete') {
        if (!empty(record_find($works['logs'], $id)['demo'])) redirect($back, '見本の作業記録は削除できません。');
        work_owner($site, null, record_find($works['logs'], $id), $back);
        site_update('works', function (array $w) use ($id) {
            $w['logs'] = array_values(array_filter($w['logs'] ?? [], fn($x) => ($x['id'] ?? '') !== $id));
            return $w;
        });
        redirect($back, '作業記録を削除しました。');
    }
    redirect($back);
}

function hm(int $min): string { return ($min >= 60 ? intdiv($min, 60) . '時間' : '') . ($min % 60 || $min < 60 ? ($min % 60) . '分' : ''); }

function todo_form(array $t, array $site, string $back): string
{
    return '<form method="post" class="form">' . csrf_field() . '<input type="hidden" name="act" value="todo_save">'
        . '<input type="hidden" name="id" value="' . h((string)($t['id'] ?? '')) . '"><input type="hidden" name="back" value="' . h($back) . '">'
        . '<div class="grid2"><p><label>対象 (蜂場・巣箱)</label>' . target_pair($site, (string)($t['apiary'] ?? ''), (string)($t['hive'] ?? ''), isset($t['id']) && ($t['apiary'] ?? '') === '') . '</p>'
        . '<p><label>期限</label><input type="date" name="due" value="' . h((string)($t['due'] ?? '')) . '"></p></div>'
        . '<p><label>やること</label><input type="text" name="title" maxlength="200" value="' . h((string)($t['title'] ?? '')) . '"></p>'
        . tag_field(work_tags(), (array)($t['tags'] ?? []))
        . '<p class="muted" style="font-size:.85em;margin-top:-.4em">やることかタグの、どちらかを入れてください。</p>'
        . assignee_select(todo_assignees($t), $site)
        . '<p><label><input type="checkbox" name="notify" value="1"' . (!empty($t['notify']) || !isset($t['id']) ? ' checked' : '') . '> 期限の前日と当日 (過ぎたら毎朝) にメールで知らせる'
        . (team_on() ? ' (担当者がいれば担当者へ)' : '') . '</label></p>'
        . '<p><label>メモ</label><textarea name="note" rows="2">' . h((string)($t['note'] ?? '')) . '</textarea></p>'
        . '<p><button type="submit">保存</button></p></form>';
}

// 担当者の選択欄(チームの人から複数選べる。クリックで選ぶ)。選べる人は蜂場ごとに違うので、data-a に見られる蜂場を持たせ、
// 対象に合わせて絞る(todoPeople)。「すべて」(*)は自分だけ
function assignee_select(array $cur, array $site, string $name = 'assignees', string $note = '複数選べます。選ばなければ未定'): string
{
    if (!team_on()) return '';
    $all = [];
    foreach (array_merge([''], array_keys($site['apiaries'])) as $aid) {
        foreach (assignee_options($site, (string)$aid) as $u => $nm) $all[$u] = ['name' => $nm, 'a' => array_merge($all[$u]['a'] ?? [], [$aid === '' ? '*' : (string)$aid])];
    }
    uasort($all, fn($x, $y) => strcmp($x['name'], $y['name']));
    $o = '<p><label>担当者 <span class="muted" style="font-size:.85em">(' . h($note) . ')</span></label><span class="ichk asg">';
    foreach ($all as $u => $x) $o .= '<label class="tagc" data-a="' . h(implode(' ', $x['a'])) . '"><input type="checkbox" name="' . h($name) . '[]" value="' . h($u) . '"' . (in_array($u, $cur, true) ? ' checked' : '') . '><span>' . h($x['name']) . '</span></label>';
    return $o . '</span></p>';
}

// 作業の種類の色(色覚の違いでも見分けやすい Okabe-Ito の8色)
const W_TYPE_COLORS = ['準備' => '#E69F00', '内検' => '#56B4E9', '給餌' => '#009E73', '採蜜' => '#F0E442', '分蜂対応' => '#0072B2', '片付け' => '#D55E00', '移動' => '#CC79A7', 'その他' => '#999999'];

// 月ごとの作業時間の積み上げ棒グラフ(12か月。$endM の月まで)。$person が空なら全員ののべ時間(時間 × 人数)、
// 人を選んだらその人が担当した作業の時間。上に担当者を選ぶボタン(チームを使っているとき)
function work_chart(array $logs, string $endM, string $person, callable $selfUrl, array $members = []): string
{
    $months = [];
    for ($i = 11; $i >= 0; $i--) $months[] = date('Y-m', strtotime($endM . "-01 -$i month"));
    $val = [];   // 月 => 種類 => 分
    $people = $members;   // 作業記録のないメンバーにもボタンを出す
    foreach ($logs as $l) {
        $ws = log_workers($l);
        foreach ($ws as $u) $people[$u] = user_name($u);
        $m = substr((string)($l['date'] ?? ''), 0, 7);
        if (!in_array($m, $months, true)) continue;
        if ($person !== '' && !in_array($person, $ws, true)) continue;
        $t = isset(W_TYPE_COLORS[$l['type'] ?? '']) ? (string)$l['type'] : 'その他';
        $min = (int)($l['minutes'] ?? 0) * ($person === '' ? max(1, (int)($l['people'] ?? 1)) : 1);
        $val[$m][$t] = ($val[$m][$t] ?? 0) + $min;
    }
    asort($people);
    $o = '<div class="card" id="wchart"><b>月ごとの作業時間</b> <span class="muted" style="font-size:.85em">(' . ($person === '' ? 'のべ時間 = 時間 × 人数' : h(user_name($person)) . ' が担当した作業') . ')</span>';
    if (team_on() || count($people) > 1) {
        $o .= '<div class="per" style="margin-top:6px"><a href="' . h($selfUrl(['p' => ''], 'wchart')) . '"' . ($person === '' ? ' class="on"' : '') . '>すべて</a>';
        foreach ($people as $u => $nm) $o .= '<a href="' . h($selfUrl(['p' => $u], 'wchart')) . '"' . ($person === $u ? ' class="on"' : '') . '>' . h($nm) . '</a>';
        $o .= '</div>';
    }
    // 縦軸: 目盛り4本。1目盛りを 0.25・0.5・1・2・5・10… 時間の切りのよい数にする
    $maxH = 0;
    foreach ($months as $m) $maxH = max($maxH, array_sum($val[$m] ?? []) / 60);
    $step = 0.25;
    foreach ([0.25, 0.5, 1, 2, 5, 10, 20, 50, 100, 200, 500, 1000] as $c) { $step = $c; if ($c * 4 >= $maxH) break; }
    $top = $step * 4;
    // パソコン用の横長と、スマホ用の文字を大きくした版(gw / gn)
    $svgs = '';
    foreach ([['gw', 720, 240, 11], ['gn', 380, 230, 12]] as [$cls, $W, $H, $fs]) {
        $L = $fs * 3 + 6; $R = 6; $T = 14; $B = $fs * 2 + 8;
        $pw = $W - $L - $R; $ph = $H - $T - $B; $bw = $pw / 12;
        $svg = '<svg class="' . $cls . '" viewBox="0 0 ' . $W . ' ' . $H . '" style="width:100%;height:auto;background:#fff;border:0;margin-top:6px" role="img" aria-label="月ごとの作業時間">';
        for ($g = 0; $g <= 4; $g++) {
            $y = $T + $ph - $ph * $g / 4;
            $v = $step * $g;
            $svg .= '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '" stroke="' . ($g ? '#e3e7e4' : '#9aa3ad') . '"/>'
                . '<text x="' . ($L - 4) . '" y="' . round($y + $fs / 3, 1) . '" font-size="' . $fs . '" text-anchor="end" fill="#555">' . rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.') . '</text>';
        }
        $svg .= '<text x="2" y="' . ($T - 4) . '" font-size="' . ($fs - 1) . '" fill="#555">時間</text>';
        foreach ($months as $i => $m) {
            $x = $L + $bw * $i + $bw * 0.16;
            $y = $T + $ph;
            foreach (W_TYPE_COLORS as $t => $col) {
                $min = $val[$m][$t] ?? 0;
                if ($min <= 0) continue;
                $hgt = $ph * ($min / 60) / $top;
                $y -= $hgt;
                $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bw * 0.68, 1) . '" height="' . round($hgt, 1) . '" fill="' . $col . '"><title>'
                    . h(date('Y年n月', strtotime($m . '-01')) . ' ' . $t . ' ' . hm($min)) . '</title></rect>';
            }
            $sum = array_sum($val[$m] ?? []);
            $cx = round($L + $bw * ($i + 0.5), 1);
            if ($sum > 0) $svg .= '<text x="' . $cx . '" y="' . round($y - 3, 1) . '" font-size="' . ($fs - 1) . '" text-anchor="middle" fill="#333">' . round($sum / 60, 1) . '</text>';
            $svg .= '<text x="' . $cx . '" y="' . ($H - $fs - 4) . '" font-size="' . $fs . '" text-anchor="middle" fill="#333">' . (int)substr($m, 5) . ($cls === 'gw' ? '月' : '') . '</text>';
            if ($i === 0 || substr($m, 5) === '01') $svg .= '<text x="' . $cx . '" y="' . ($H - 2) . '" font-size="' . ($fs - 2) . '" text-anchor="middle" fill="#777">' . substr($m, 0, 4) . '</text>';
        }
        $svgs .= $svg . '</svg>';
    }
    $leg = '<div class="wleg">';
    foreach (W_TYPE_COLORS as $t => $col) $leg .= '<span><i style="background:' . $col . '"></i>' . h($t) . '</span>';
    return $o . $svgs . $leg . '</div></div>';
}

function log_form(array $l, array $site, string $back): string
{
    $o = '<form method="post" class="form">' . csrf_field() . '<input type="hidden" name="act" value="log_save">'
        . '<input type="hidden" name="id" value="' . h((string)($l['id'] ?? '')) . '"><input type="hidden" name="back" value="' . h($back) . '">'
        . '<div class="grid2"><p><label>対象 (蜂場・巣箱)</label>' . target_pair($site, (string)($l['apiary'] ?? ''), (string)($l['hive'] ?? ''), isset($l['id']) && ($l['apiary'] ?? '') === '') . '</p>'
        . '<p><label>日付</label><input type="date" name="date" value="' . h((string)($l['date'] ?? date('Y-m-d'))) . '"></p></div>'
        . assignee_select(isset($l['id']) ? (array)($l['workers'] ?? []) : [$GLOBALS['UID']], $site, 'workers', '作業した人。複数選べます。選ばなければ記入者')
        . '<p><label>作業の種類</label><select name="type">';
    foreach (S_WORK_TYPES as $t) $o .= '<option' . (($l['type'] ?? '') === $t ? ' selected' : '') . '>' . h($t) . '</option>';
    return $o . '</select></p>'
        . '<p><label>かかった時間 (開始と終了を入れるか、分を入れる)</label>'
        . '<input type="time" name="start" value="' . h((string)($l['start'] ?? '')) . '"> 〜 <input type="time" name="end" value="' . h((string)($l['end'] ?? '')) . '">'
        . '　または <input type="number" name="minutes" min="1" max="1440" value="' . h(($l['start'] ?? '') === '' && isset($l['minutes']) ? (string)$l['minutes'] : '') . '"> 分</p>'
        . '<p><label>人数</label><input type="number" name="people" min="1" max="99" value="' . (int)($l['people'] ?? 1) . '"> 人</p>'
        . '<p><label>メモ</label><textarea name="note" rows="2">' . h((string)($l['note'] ?? '')) . '</textarea></p>'
        . '<p><button type="submit">保存</button></p></form>';
}

page_head(($taskPage ? 'タスク' : '記録') . ' - ' . S_TITLE, $page);
echo flash_html();
if (!$site['apiaries']) echo '<p class="muted">先に<a href="apiary.php#new">蜂場ページ</a>で蜂場を登録してください。</p>';

echo '<div class="tabs"><a href="' . h($page . '?' . http_build_query(['m' => $month])) . '"' . ($fa === '' ? ' class="on"' : '') . '>すべての蜂場</a>';
foreach ($site['apiaries'] as $x => $a) echo '<a href="' . h($page . '?' . http_build_query(['a' => $x, 'm' => $month])) . '"' . ($fa === $x ? ' class="on"' : '') . '>' . h((string)$a['name']) . '</a>';
echo '</div>';

// ====================================================================
// タスク(タスクページだけ)
// ====================================================================
if ($taskPage):
echo '<div id="todo"></div>';
$open = todo_sort(array_values(array_filter($works['todos'], fn($t) => empty($t['done']) && ($fa === '' || in_array($t['apiary'] ?? '', [$fa, ''], true)))));
$nt = parse_target($site, (string)($_GET['new_todo'] ?? ''));
echo '<details id="todo-form"' . ($nt ? ' open' : '') . '><summary><b>＋ タスクを追加</b></summary><div class="card">'
    . todo_form($nt ? ['apiary' => $nt[0], 'hive' => $nt[1]] : ['apiary' => $fa], $site, $selfUrl([], 'todo')) . '</div></details>';
// コピー(「コピー」を押したときだけ出す)
$cp = null;
foreach ($works['todos'] as $t) if (($t['id'] ?? '') === (string)($_GET['copy'] ?? '')) $cp = $t;
if ($cp) {
    echo '<div class="card" id="todo-copy" style="border:2px solid #1565c0"><h3>タスクをほかの蜂場にコピー</h3>'
        . '<p class="muted">元: ' . h(target_label($site, (string)$cp['apiary'], (string)($cp['hive'] ?? ''))) . '。選んだ蜂場ごとに、蜂場全体の タスクとして作ります。</p>'
        . '<form method="post" class="form">' . csrf_field() . '<input type="hidden" name="act" value="todo_copy">'
        . '<input type="hidden" name="back" value="' . h($selfUrl([], 'todo')) . '">'
        . '<p><label>コピー先の蜂場</label>';
    foreach ($site['apiaries'] as $aid => $a) {
        echo '<span style="display:inline-block;margin-right:14px"><input type="checkbox" name="targets[]" value="' . h($aid) . '"> ' . h((string)$a['name'])
            . ($aid === ($cp['apiary'] ?? '') ? ' <span class="muted">(元の蜂場)</span>' : '') . '</span>';
    }
    echo '</p><p><label>やること</label><input type="text" name="title" maxlength="200" value="' . h((string)$cp['title']) . '"></p>' . tag_field(work_tags(), (array)($cp['tags'] ?? []))
        . '<p><label>期限</label><input type="date" name="due" value="' . h((string)($cp['due'] ?? '')) . '"></p>'
        . '<p><label><input type="checkbox" name="notify" value="1"' . (!empty($cp['notify']) ? ' checked' : '') . '> 期限の前日と当日 (過ぎたら毎朝) にメールで知らせる</label></p>'
        . '<p><label>メモ</label><textarea name="note" rows="2">' . h((string)($cp['note'] ?? '')) . '</textarea></p>'
        . '<p><button type="submit">コピーする</button> <a href="' . h($selfUrl([], 'todo')) . '">やめる</a></p></form></div>';
}
if (!$open) echo '<p class="muted">未完了の タスクはありません。</p>';
else {
    echo '<div class="wrap"><table class="todot"><tr><th>期限</th><th>やること</th>' . (team_on() ? '<th>担当</th>' : '') . '<th>対象</th><th></th></tr>';
    foreach ($open as $t) {
        echo '<tr><td style="white-space:nowrap">' . due_html((string)($t['due'] ?? '')) . (!empty($t['notify']) ? ' <span title="メールで知らせる">✉</span>' : '') . '</td>'
            . '<td>' . tag_show((array)($t['tags'] ?? [])) . h((string)$t['title']) . by_html($t) . (($t['note'] ?? '') !== '' ? '<br><span class="muted">' . nl((string)$t['note']) . '</span>' : '')
            . '</td>'
            . (team_on() ? '<td>' . h(implode('・', array_map('user_name', todo_assignees($t))) ?: '-') . '</td>' : '')
            . '<td>' . h(target_label($site, (string)$t['apiary'], (string)($t['hive'] ?? ''))) . '</td>'
            . '<td><div class="tbtn"><form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="act" value="todo_done">'
            . '<input type="hidden" name="id" value="' . h((string)$t['id']) . '"><input type="hidden" name="back" value="' . h($selfUrl([], 'todo')) . '">'
            . '<button type="submit">完了</button></form>'
            . '<a href="' . h($selfUrl(['copy' => (string)$t['id']], 'todo-copy')) . '"><button type="button">コピー</button></a>'
            . '<button type="button" onclick="var r=this.closest(\'tr\').nextElementSibling;r.hidden=!r.hidden">変更</button>'
            . '<form method="post" class="inl" onsubmit="return confirm(\'この タスクを削除しますか?\');">' . csrf_field()
            . '<input type="hidden" name="act" value="todo_delete"><input type="hidden" name="id" value="' . h((string)$t['id']) . '">'
            . '<input type="hidden" name="back" value="' . h($selfUrl([], 'todo')) . '"><button type="submit" class="del">削除</button></form></div></td></tr>'
            . '<tr hidden class="tedit"><td colspan="' . (team_on() ? 5 : 4) . '">' . todo_form($t, $site, $selfUrl([], 'todo')) . '</td></tr>';
    }
    echo '</table></div>';
}
// カレンダー(月ごと。期限の日にタスクを並べる。完了したものは薄く)。?cm=年-月 で月を変える
$cm = (string)($_GET['cm'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $cm)) $cm = date('Y-m', $now);
$first = strtotime($cm . '-01');
$byDay = [];
foreach ($works['todos'] as $t) {
    $due = (string)($t['due'] ?? '');
    if (substr($due, 0, 7) !== $cm || !($fa === '' || in_array($t['apiary'] ?? '', [$fa, ''], true))) continue;
    $byDay[(int)substr($due, 8, 2)][] = $t;
}
$cmPrev = date('Y-m', strtotime('-1 month', $first));
$cmNext = date('Y-m', strtotime('+1 month', $first));
echo '<div id="tcal" class="tcal"><div class="per"><a href="' . h($selfUrl(['cm' => $cmPrev], 'tcal')) . '">← ' . h(date('n月', strtotime($cmPrev . '-01'))) . '</a>'
    . '<a class="on">' . h(date('Y年n月', $first)) . '</a><a href="' . h($selfUrl(['cm' => $cmNext], 'tcal')) . '">' . h(date('n月', strtotime($cmNext . '-01'))) . ' →</a>'
    . ($cm !== date('Y-m', $now) ? '<a href="' . h($selfUrl(['cm' => ''], 'tcal')) . '">今月</a>' : '') . '</div>'
    . '<table><tr>';
foreach (['日', '月', '火', '水', '木', '金', '土'] as $i => $wd) echo '<th class="w' . $i . '">' . $wd . '</th>';
echo '</tr><tr>';
$lead = (int)date('w', $first);
for ($i = 0; $i < $lead; $i++) echo '<td class="out"></td>';
$days = (int)date('t', $first);
for ($d = 1; $d <= $days; $d++) {
    $ymd = $cm . '-' . sprintf('%02d', $d);
    $wd = ($lead + $d - 1) % 7;
    if ($wd === 0 && $d > 1) echo '</tr><tr>';
    echo '<td class="w' . $wd . ($ymd === $today ? ' today' : '') . '"><div class="d">' . $d . '</div>';
    foreach (todo_sort($byDay[$d] ?? []) as $t) {
        $cls = !empty($t['done']) ? 'done' : ($ymd < $today ? 'over' : '');
        $as = todo_assignees($t);
        echo '<div class="ti ' . $cls . '" title="' . h(todo_title($t) . ' / ' . target_label($site, (string)$t['apiary'], (string)($t['hive'] ?? '')) . ($as ? ' / 担当: ' . implode('・', array_map('user_name', $as)) : '')) . '">' . h(todo_title($t)) . '</div>';
    }
    echo '</td>';
}
for ($i = ($lead + $days) % 7; $i > 0 && $i < 7; $i++) echo '<td class="out"></td>';
echo '</tr></table><p class="muted" style="font-size:.8em;margin:.2em 0 0">期限のあるタスクを期限の日に出します (赤 = 期限切れ、薄い字 = 完了)。</p></div>';

$doneList = array_values(array_filter($works['todos'], fn($t) => !empty($t['done']) && ($fa === '' || in_array($t['apiary'] ?? '', [$fa, ''], true))));
usort($doneList, fn($x, $y) => (int)($y['done_at'] ?? 0) <=> (int)($x['done_at'] ?? 0));
if ($doneList) {
    echo '<details><summary>完了した タスク(新しい順に30件)</summary><div class="wrap"><table><tr><th>完了日</th><th>やること</th><th>対象</th><th></th></tr>';
    foreach (array_slice($doneList, 0, 30) as $t) {
        $logPre = http_build_query(['new_log' => $t['apiary'] . '|' . ($t['hive'] ?? ''), 'note' => todo_title($t), 'a' => $fa, 'm' => $month]);
        echo '<tr><td>' . h(!empty($t['done_at']) ? date('n/j', (int)$t['done_at']) : '-') . '</td><td>' . tag_show((array)($t['tags'] ?? [])) . h((string)$t['title']) . by_html($t) . '</td>'
            . '<td>' . h(target_label($site, (string)$t['apiary'], (string)($t['hive'] ?? ''))) . '</td>'
            . '<td style="white-space:nowrap"><a href="work.php?' . h($logPre) . '#log-form">作業記録に残す</a> '
            . '<form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="act" value="todo_undo">'
            . '<input type="hidden" name="id" value="' . h((string)$t['id']) . '"><input type="hidden" name="back" value="' . h($selfUrl([], 'todo')) . '">'
            . '<button class="link" type="submit">戻す</button></form></td></tr>';
    }
    echo '</table></div></details>';
}

// Googleカレンダーへの配信(一方向)。合言葉はアカウントごと
$icalKey = (string)(account_load($UID)['ical'] ?? '');
echo '<details id="ical" class="icalset"><summary>📅 タスクをGoogleカレンダーに表示する' . ($icalKey !== '' ? ' <span class="muted">(配信中)</span>' : '') . '</summary><div class="card">'
    . '<p class="muted" style="margin-top:0">期限のある未完了のタスクが、期限の日の終日予定として出ます。作業記録で足したり完了にしたりすると、カレンダーにも反映されます (Googleの読み込みは数時間〜1日おきなので、すぐには出ません)。カレンダーで動かしても作業記録には戻りません。</p>';
if ($icalKey === '') {
    echo '<form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="act" value="ical_new"><button type="submit">カレンダー用のURLを作る</button></form>';
} else {
    $icalUrl = site_url('ical.php?k=' . $icalKey);
    echo '<p><input type="text" id="icalurl" readonly value="' . h($icalUrl) . '" onclick="this.select()" style="width:100%;max-width:640px"> '
        . '<button type="button" onclick="var i=document.getElementById(\'icalurl\');i.select();navigator.clipboard&&navigator.clipboard.writeText(i.value);this.textContent=\'コピーしました\'">URLをコピー</button></p>'
        . '<ol style="margin:.3em 0;padding-left:1.4em"><li>上のURLをコピーします。</li>'
        . '<li>パソコンで <a href="https://calendar.google.com/calendar/r/settings/addbyurl" target="_blank" rel="noopener">Googleカレンダーの「URLで追加」</a> を開きます (左の「他のカレンダー」の「＋」→「URLで追加」)。</li>'
        . '<li>URLを貼り付けて「カレンダーを追加」を押します。スマホのGoogleカレンダーにも同じものが出ます。</li>'
        . '<li>カレンダーの名前がURLのままのときは、左の一覧でそのカレンダーの「⋮」→「設定」を開き、「名前」を「Hive Plus X タスク」などに変えます。</li></ol>'
        . '<p class="muted">iPhoneのカレンダーなら <a href="' . h(preg_replace('#^https?://#', 'webcal://', $icalUrl)) . '">こちら</a> から登録できます。'
        . 'このURLを知っている人は誰でもタスクを見られるので、人に教えないでください。</p>'
        . '<div><form method="post" class="inl" onsubmit="return confirm(\'URLを作り直すと、今のURLは使えなくなります。よろしいですか?\');">' . csrf_field()
        . '<input type="hidden" name="act" value="ical_new"><input type="hidden" name="re" value="1"><button type="submit">URLを作り直す</button></form> '
        . '<form method="post" class="inl" onsubmit="return confirm(\'カレンダーへの配信を止めますか?\');">' . csrf_field()
        . '<input type="hidden" name="act" value="ical_stop"><button type="submit">配信を止める</button></form></div>';
}
echo '</div></details>';

endif;

// ====================================================================
// 作業記録(過去。記録ページだけ)
// ====================================================================
if (!$taskPage):
echo '<div id="log"></div>';
$nl = parse_target($site, (string)($_GET['new_log'] ?? ''));
echo '<details id="log-form"' . ($nl ? ' open' : '') . '><summary><b>＋ 作業を記録</b></summary><div class="card">'
    . log_form($nl ? ['apiary' => $nl[0], 'hive' => $nl[1], 'note' => mb_substr((string)($_GET['note'] ?? ''), 0, 200)] : ['apiary' => $fa], $site, $selfUrl([], 'log'))
    . '</div></details>';

if ($month === 'all') {   // すべての月: 今月へ戻るボタンと「すべて」
    $curM = date('Y-m', $now);
    echo '<div class="per"><a href="' . h($selfUrl(['m' => $curM], 'log')) . '">' . h(date('Y年n月', strtotime($curM . '-01'))) . '</a><a class="on">すべて</a></div>';
} else {
    $prevM = date('Y-m', strtotime($month . '-01 -1 month'));
    $nextM = date('Y-m', strtotime($month . '-01 +1 month'));
    echo '<div class="per"><a href="' . h($selfUrl(['m' => $prevM], 'log')) . '">← ' . h(date('n月', strtotime($prevM . '-01'))) . '</a>'
        . '<a class="on">' . h(date('Y年n月', strtotime($month . '-01'))) . '</a>'
        . ($nextM <= date('Y-m', $now) ? '<a href="' . h($selfUrl(['m' => $nextM], 'log')) . '">' . h(date('n月', strtotime($nextM . '-01'))) . ' →</a>' : '')
        . '<a href="' . h($selfUrl(['m' => 'all'], 'log')) . '">すべて</a></div>';
}

$logs = array_values(array_filter($works['logs'], fn($l) => ($month === 'all' || substr((string)($l['date'] ?? ''), 0, 7) === $month) && ($fa === '' || in_array($l['apiary'] ?? '', [$fa, ''], true))));
usort($logs, fn($x, $y) => [$y['date'] ?? '', $y['start'] ?? '', $y['created'] ?? 0] <=> [$x['date'] ?? '', $x['start'] ?? '', $x['created'] ?? 0]);
$members = [];   // グラフの担当者ボタン: 選んだ蜂場(すべてなら全部の蜂場)を見られる人
foreach ($fa === '' ? array_keys($site['apiaries']) : [$fa] as $aid) $members += assignee_options($site, (string)$aid);
$chart = work_chart(array_values(array_filter($works['logs'], fn($l) => $fa === '' || in_array($l['apiary'] ?? '', [$fa, ''], true))),
    $month === 'all' ? date('Y-m', $now) : $month, $fp, $selfUrl, $members);
if (!$logs) echo '<p class="muted">' . ($month === 'all' ? '' : 'この月の') . '作業記録はありません。</p>' . $chart;
else {
    // 月の合計(種類ごと)。のべ時間 = 時間 × 人数
    $byType = []; $total = 0; $totalP = 0;
    foreach ($logs as $l) {
        $m = (int)$l['minutes']; $p = (int)($l['people'] ?? 1);
        $byType[$l['type']] = ($byType[$l['type']] ?? 0) + $m;
        $total += $m; $totalP += $m * $p;
    }
    $parts = [];
    foreach (S_WORK_TYPES as $t) if (isset($byType[$t])) $parts[] = $t . ' ' . hm($byType[$t]);
    echo '<div class="card"><b>' . ($month === 'all' ? 'すべての期間の合計 ' : 'この月の合計 ') . h(hm($total)) . '</b>' . ($totalP !== $total ? ' <span class="muted">(のべ ' . h(hm($totalP)) . ')</span>' : '')
        . '<br><span class="muted">' . h(implode('　', $parts)) . '</span></div>' . $chart;
    echo '<div class="wrap"><table><tr><th>日付</th><th>対象</th><th>作業</th>' . (team_on() ? '<th>担当</th>' : '') . '<th>時間</th><th>メモ</th></tr>';
    foreach ($logs as $l) {
        echo '<tr><td style="white-space:nowrap">' . h(date($month === 'all' ? 'Y/n/j' : 'n/j', strtotime((string)$l['date']))) . '</td>'
            . '<td>' . h(target_label($site, (string)$l['apiary'], (string)($l['hive'] ?? ''))) . '</td>'
            . '<td>' . h((string)$l['type']) . '</td>'
            . (team_on() ? '<td>' . h(implode('・', array_map('user_name', log_workers($l)))) . '</td>' : '')
            . '<td style="white-space:nowrap">' . h(hm((int)$l['minutes'])) . ((int)($l['people'] ?? 1) > 1 ? ' ×' . (int)$l['people'] . '人' : '')
            . (($l['start'] ?? '') !== '' ? '<br><span class="muted">' . h($l['start'] . '〜' . $l['end']) . '</span>' : '') . '</td>'
            . '<td>' . nl((string)($l['note'] ?? '')) . by_html($l)
            . (!empty($l['demo']) ? '' : '<details><summary class="muted">変更・削除</summary>' . log_form($l, $site, $selfUrl([], 'log'))
            . '<form method="post" onsubmit="return confirm(\'この作業記録を削除しますか?\');">' . csrf_field()
            . '<input type="hidden" name="act" value="log_delete"><input type="hidden" name="id" value="' . h((string)$l['id']) . '">'
            . '<input type="hidden" name="back" value="' . h($selfUrl([], 'log')) . '"><button type="submit">削除</button></form></details>') . '</td></tr>';
    }
    echo '</table></div>';
}
endif;
// 担当者の欄: 対象の蜂場を見られる人だけを選べるようにする
echo <<<'JS'
<script>
function todoPeople(f){
  var sa = f.elements['t_a'], box = f.querySelector('.asg'); if (!sa || !box) return;
  box.querySelectorAll('label[data-a]').forEach(function(l){
    var ok = (' ' + l.getAttribute('data-a') + ' ').indexOf(' ' + sa.value + ' ') >= 0, c = l.querySelector('input');
    l.style.display = ok ? '' : 'none'; c.disabled = !ok; if (!ok) c.checked = false;
  });
}
document.querySelectorAll('.asg').forEach(function(s){ var f = s.closest('form'); todoPeople(f); f.elements['t_a'].addEventListener('change', function(){ todoPeople(f); }); });
</script>
JS;
echo target_pair_js() . tag_js('work.php', work_tags());
echo '</body></html>';
