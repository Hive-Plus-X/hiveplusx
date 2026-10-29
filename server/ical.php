<?php
// タスクのカレンダー配信(iCal 形式)。Googleカレンダーの「URLで追加」などに ical.php?k=<合言葉> を登録してもらう。
//   ログインは要らない。合言葉はアカウントごとにタスクページで発行・作り直し・停止する(hpx_auth.php の ical_*)。
//   期限のある未完了のタスクを、期限の日の終日予定にする(作業記録ページと同じく、共有された蜂場の分も入る)。
//   完了・削除したタスクは次の読み込みで消える。カレンダー側で動かしても作業記録には戻らない(一方向)。
declare(strict_types=1);
require_once __DIR__ . '/hpx_auth.php';

$icalUid = ical_uid((string)($_GET['k'] ?? ''));
if ($icalUid === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit("このカレンダーのURLは使えません。作業記録ページで新しいURLを確かめてください。\n");
}
define('S_AS_UID', $icalUid);
require __DIR__ . '/hpx_site.php';

// 文字の決まり(RFC 5545): \ ; , と改行をエスケープし、1行75バイトで折り返す
function ical_text(string $s): string
{
    return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ["\\\\", '\;', '\,', '\n', '\n', '\n'], $s);
}
function ical_line(string $line): string
{
    $out = '';
    $cur = '';
    foreach (mb_str_split($line, 1, 'UTF-8') as $ch) {
        if (strlen($cur) + strlen($ch) > ($out === '' ? 75 : 74)) {
            $out .= $cur . "\r\n ";
            $cur = '';
        }
        $cur .= $ch;
    }
    return $out . $cur . "\r\n";
}

$site = site_load();
$host = parse_url(site_url(''), PHP_URL_HOST) ?: 'hpx';
$lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Hive Plus X//tasks//JA', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
    // カレンダーの名前。NAME は新しい決まり(RFC 7986)、X-WR-CALNAME は Google・Apple が見る古い書き方
    'NAME:' . ical_text(BRAND_NAME . ' タスク'), 'X-WR-CALNAME:' . ical_text(BRAND_NAME . ' タスク'),
    'X-WR-CALDESC:' . ical_text(BRAND_NAME . ' の作業記録のタスク'), 'X-WR-TIMEZONE:Asia/Tokyo',
    'REFRESH-INTERVAL;VALUE=DURATION:PT1H', 'X-PUBLISHED-TTL:PT1H'];
foreach (todo_sort(works_load()['todos']) as $t) {
    $due = (string)($t['due'] ?? '');
    if (!empty($t['done']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) continue;
    $d = strtotime($due);
    $as = todo_assignees($t);
    $desc = [];
    $desc[] = '対象: ' . target_label($site, (string)$t['apiary'], (string)($t['hive'] ?? ''));
    if ($as) $desc[] = '担当: ' . implode('・', array_map('user_name', $as));
    if (($t['note'] ?? '') !== '') $desc[] = (string)$t['note'];
    $desc[] = site_url('task.php?' . http_build_query(['a' => (string)$t['apiary']]) . '#todo');
    $where = (string)($site['apiaries'][(string)$t['apiary']]['name'] ?? '');
    array_push($lines, 'BEGIN:VEVENT',
        'UID:todo-' . preg_replace('/[^0-9a-zA-Z_-]/', '', (string)$t['id']) . '@' . $host,
        'DTSTAMP:' . gmdate('Ymd\THis\Z', (int)($t['updated'] ?? $t['created'] ?? $now)),
        'DTSTART;VALUE=DATE:' . date('Ymd', $d),
        'DTEND;VALUE=DATE:' . date('Ymd', strtotime('+1 day', $d)),
        'SUMMARY:' . ical_text(todo_title($t) . ($where !== '' ? ' (' . $where . ')' : '')),
        'DESCRIPTION:' . ical_text(implode("\n", $desc)),
        'TRANSP:TRANSPARENT',
        'END:VEVENT');
}
$lines[] = 'END:VCALENDAR';

header('Content-Type: text/calendar; charset=UTF-8');
header('Content-Disposition: inline; filename="hpx-tasks.ics"');
header('Cache-Control: no-cache');
echo implode('', array_map('ical_line', $lines));
