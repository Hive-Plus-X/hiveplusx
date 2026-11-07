<?php
// HPX 蜂場管理ページの共通部分。apiary.php(蜂場)・hive.php(巣箱)・memo.php(メモ)・task.php(タスク)・work.php(記録)から読み込む。
//   ・ログインはアカウントごと(hpx_auth.php。sensor.php と同じセッション)
//   ・登録した内容は、蜂場の持ち主のアカウントのフォルダ(データフォルダの accounts/<アカウントID>/site/)に JSON で保存する
//     チームに共有された蜂場(hpx_team.php)は、持ち主のフォルダの分も読み合わせて、自分の蜂場と同じように並べる。
//     書くときは site_use(持ち主) で書き先を決めてから site_update() を呼ぶ(初めは自分)
//       site.json  : 蜂場と巣箱
//       memos.json : メモ(蜂場単位・巣箱単位。内検記録もここ)
//       works.json : 作業記録(過去の作業時間)と タスク
//   ・センサーの記録は、アカウントの hubs/<HPX-CoreのID>/ の state.json / ble.csv / weight_cal.json を読むだけ(書き換えない)
declare(strict_types=1);
require_once __DIR__ . '/hpx_auth.php';
require_once __DIR__ . '/hpx_hive_alert.php';

const S_TITLE = BRAND_NAME;
const S_PERIODS = [1 => '1日', 3 => '3日', 7 => '7日', 30 => '30日', 0 => '全期間'];   // 0 = 全期間
const S_PERIOD_DEFAULT = 3;
const S_MAX_POINTS = 1200;
const S_TH_MISSING_S = 3600;
const S_W_MISSING_S = 5400;
const S_SOON_DAYS = 3;               // タスクの期限がこの日数以内なら「もうすぐ」
const S_COLORS = ['#1565c0', '#c62828', '#2e7d32', '#6a1b9a', '#ef6c00', '#00838f', '#5d4037', '#ad1457', '#827717', '#455a64'];   // 10台まで別の色 (HPX-Core v1.19 は最大10台)
const S_HIVE_STATES = ['健全', '弱群', '要観察', '無王'];   // 「空き箱」は 2026-10-28 に外した(前に選んだ巣箱はそのまま出す)
const S_MEMO_TYPES = ['内検', 'ミツバチ', 'トラブル', '開花植物', 'その他'];
// 名前を変えた内容とタグ (前の名前 => 今の名前)。前に書いたメモも、読むときに今の名前にする (2026-10-26)
const S_MEMO_RENAME = ['蜂のイベント' => 'ミツバチ', '外敵' => 'トラブル', '開花状況' => '開花植物'];
const S_TAG_RENAME = ['開花スタート' => '開花'];
const S_MEMO_TYPES_OLD = ['給餌', '分蜂', '採蜜', '病害虫', '天候'];   // 前の版の「種類」。そのメモを直すときだけ選べる
const S_WORK_TYPES = ['準備', '内検', '給餌', '採蜜', '分蜂対応', '片付け', '移動', 'その他'];

// 内検の項目: キー => [見出し, 選択肢(空なら数値), 複数選べるか, 見出しの横の注意書き(省略可)]
// ダニ調査(内検のとき): 調査方法・検査した数・ダニの数。寄生率 = ダニ数 ÷ 検査数 × 100 は表示のときに計算する。メモの mite に持つ
const M_MITE_METHODS = ['シュガーロール', 'アルコールウォッシュ', '雄蜂児チェック', '目視'];
function mite_text(array $mt): string
{
    $o = (string)($mt['method'] ?? '');
    if (isset($mt['bees'], $mt['mites'])) {
        $o .= ' ' . $mt['bees'] . '匹中 ダニ' . $mt['mites'] . '匹' . ($mt['bees'] > 0 ? ' (寄生率 ' . number_format($mt['mites'] / $mt['bees'] * 100, 1) . '%)' : '');
    } elseif (isset($mt['mites'])) {
        $o .= ' ダニ' . $mt['mites'] . '匹';
    }
    return trim($o);
}

const M_INSPECT = [
    'queen' => ['女王', ['見た', '見ない'], false],
    'eggs'  => ['卵・若い幼虫', ['あり', 'なし'], false],
    'capped' => ['有蓋蜂児', ['少ない', '普通', '多い'], false],
    'cells' => ['王台', ['なし', 'あり'], false],
    'frames' => ['蜂数 (〇枚群相当)', [], false],
    'honey' => ['貯蜜', ['多い', 'ふつう', '少ない'], false],
    'feed'  => ['給餌', ['糖液', 'シュガーペースト', '代用花粉'], true],
    'mite'  => ['ダニ管理', ['アピバール', 'チモバール', 'アピスタン', '雄蜂児除去', '女王隔離', 'その他'], true, '※処理中は毎回チェック'],
    'manage' => ['管理', ['増段', '減段', '分割', '合同', '採蜜', '移動'], true],
];

// ====================================================================
// ログイン(hpx_auth.php。センサページと同じセッション。どちらかでログインすれば両方見られる)
// ====================================================================

$selfPage = basename($_SERVER['SCRIPT_NAME'] ?? 'apiary.php');
if (defined('S_AS_UID')) {   // カレンダー配信(ical.php)は、ログインせずに URL の合言葉で決めたアカウントとして読むだけ
    $ME = user_get(S_AS_UID) ?? [];
    $UID = S_AS_UID;
    $csrf = '';
} else {
    auth_require($selfPage);   // $ME(ログインしたアカウント)・$UID・$csrf が決まる
}
$SITE_UID = $UID;          // site_read()・site_update() の読み書き先(蜂場の持ち主)
$now = time();
$today = date('Y-m-d', $now);

function csrf_field(): string
{
    global $csrf;
    return '<input type="hidden" name="csrf" value="' . h($csrf) . '">';
}

// POST のときだけ true。合言葉(CSRF)が違えば止める
function is_post(): bool
{
    global $csrf;
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return false;
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('画面を開き直してから、もう一度送ってください。');
    }
    return true;
}

// 保存した後は、同じページを GET で開き直す(再読み込みで二重に登録しないように)
function redirect(string $url, string $flash = ''): void
{
    if ($flash !== '') $_SESSION['flash'] = $flash;
    header('Location: ' . $url);
    exit;
}

function flash_html(): string
{
    $f = (string)($_SESSION['flash'] ?? '');
    unset($_SESSION['flash']);
    return $f === '' ? '' : '<p class="card flash">' . h($f) . '</p>';
}

// 戻り先(自分のページだけ許す)
function safe_back(string $back, string $default): string
{
    return preg_match('/^(apiary|hive|memo|task|work|sensor|team)\.php(\?[^\s<>"]*)?(#[A-Za-z0-9_-]*)?$/', $back) ? $back : $default;
}

// ====================================================================
// 保存(JSON)
// ====================================================================

function site_dir_of(string $owner): string
{
    $d = account_site_dir($owner);
    if (!is_dir($d)) @mkdir($d, 0755, true);
    return $d;
}
function site_dir(): string { return site_dir_of($GLOBALS['SITE_UID']); }

// 読み書き先を蜂場の持ち主に切り替える
function site_use(string $owner): void { $GLOBALS['SITE_UID'] = $owner; }

function site_read_of(string $owner, string $name): array
{
    $v = json_decode((string)@file_get_contents(site_dir_of($owner) . '/' . $name . '.json'), true);
    return is_array($v) ? $v : [];
}
function site_read(string $name): array { return site_read_of($GLOBALS['SITE_UID'], $name); }

// 読んで、直して、書く(ロックを取る)。$fn は配列を受け取って直した配列を返す
function site_update_of(string $owner, string $name, callable $fn): bool
{
    $dir = site_dir_of($owner);
    $lock = @fopen($dir . '/.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    $data = $fn(site_read_of($owner, $name));
    $tmp = $dir . '/' . $name . '.json.tmp';
    $ok = @file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) !== false
        && @rename($tmp, $dir . '/' . $name . '.json');
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    if ($name === 'site') owner_site($owner, true);
    return $ok;
}
function site_update(string $name, callable $fn): bool { return site_update_of($GLOBALS['SITE_UID'], $name, $fn); }

// 写真を、長い辺 $max px までの JPEG にして $dst に書く(GD を使う)。スマホの写真の向き(EXIF)を直し、
// 撮影場所などの EXIF は書き出さない。PNG の透明部分は白にする。作れなければ false
function img_resize_jpeg(string $src, string $dst, int $max, int $quality = 80): bool
{
    if (!function_exists('imagecreatefromstring')) return false;
    $im = @imagecreatefromstring((string)file_get_contents($src));
    if (!$im) return false;
    $or = function_exists('exif_read_data') ? (int)(@exif_read_data($src)['Orientation'] ?? 1) : 1;
    $rot = [3 => 180, 6 => -90, 8 => 90][$or] ?? 0;
    if ($rot) { $r = imagerotate($im, $rot, 0); if ($r) { imagedestroy($im); $im = $r; } }
    $w = imagesx($im); $h = imagesy($im); $k = min(1, $max / max($w, $h));
    $tw = max(1, (int)round($w * $k)); $th = max(1, (int)round($h * $k));
    $t = imagecreatetruecolor($tw, $th);
    imagefill($t, 0, 0, imagecolorallocate($t, 255, 255, 255));
    imagecopyresampled($t, $im, 0, 0, 0, 0, $tw, $th, $w, $h);
    $ok = imagejpeg($t, $dst . '.tmp', $quality) && rename($dst . '.tmp', $dst);
    imagedestroy($im); imagedestroy($t);
    return $ok;
}

function new_id(): string { return bin2hex(random_bytes(5)); }

// 蜂場と巣箱。apiaries / hives は id => 内容。並びは order の小さい順(共有された蜂場は自分の蜂場の後)
//   自分の蜂場と巣箱はすべて、共有された蜂場はその人が見られるものだけ。
//   それぞれに _o(持ち主のアカウントid)、蜂場には _r(その人の役割 owner / admin / member)を付ける(保存はしない)
function site_load(): array
{
    global $UID;
    static $cache = null;
    if ($cache !== null) return $cache;
    $byOrder = fn($a, $b) => ((int)($a['order'] ?? 0) <=> (int)($b['order'] ?? 0)) ?: strcmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    $out = ['apiaries' => [], 'hives' => []];
    foreach (array_merge([$UID], shared_owners($UID)) as $o) {
        $s = site_read_of($o, 'site');
        $ap = is_array($s['apiaries'] ?? null) ? $s['apiaries'] : [];
        $hv = is_array($s['hives'] ?? null) ? $s['hives'] : [];
        uasort($ap, $byOrder);
        uasort($hv, $byOrder);
        foreach ($ap as $aid => $a) {
            $aid = (string)$aid;
            $r = $o === $UID ? 'owner' : apiary_role($o, $aid, $UID);
            if ($r === null || isset($out['apiaries'][$aid]) || !is_array($a)) continue;
            $out['apiaries'][$aid] = ['_o' => $o, '_r' => $r] + $a;
        }
        foreach ($hv as $hid => $x) {
            $hid = (string)$hid;
            if (!is_array($x) || isset($out['hives'][$hid])) continue;
            if ($o !== $UID && ($out['apiaries'][(string)($x['apiary'] ?? '')]['_o'] ?? '') !== $o) continue;
            $out['hives'][$hid] = ['_o' => $o] + $x;
        }
    }
    // 蜂場ページの「並び替え」で決めた順(自分の site.json の apiary_order。共有された蜂場も入る)。
    //   入っていない蜂場(あとから足した・共有された)は、その後ろに今までの順で並べる
    $ord = site_read_of($UID, 'site')['apiary_order'] ?? null;
    if (is_array($ord) && $ord) {
        $sorted = [];
        foreach ($ord as $aid) if (isset($out['apiaries'][(string)$aid])) $sorted[(string)$aid] = $out['apiaries'][(string)$aid];
        $out['apiaries'] = $sorted + $out['apiaries'];
    }
    return $cache = $out;
}

function hives_of(array $site, string $apiaryId): array
{
    return array_filter($site['hives'], fn($hv) => ($hv['apiary'] ?? '') === $apiaryId);
}

// 1つの蜂場だけを出す蜂場ページの URL (一覧・地図から飛ぶ先。保存したあとの戻り先にも使う)
function apiary_url(string $aid): string { return 'apiary.php?' . http_build_query(['a' => $aid]) . '#a-' . $aid; }

// 蜂場の持ち主。登録されていなければ ''
function apiary_owner(array $site, string $aid): string { return (string)($site['apiaries'][$aid]['_o'] ?? ''); }

// 蜂場・巣箱の情報を変えられるか(持ち主と、共有先チームの管理者)
function apiary_can_edit(array $site, string $aid): bool { return in_array($site['apiaries'][$aid]['_r'] ?? '', ['owner', 'admin'], true); }

// 1人の持ち主の蜂場と巣箱だけにしたもの(ハブ・センサの紐づけは持ち主の中で決めるので、その入力欄に使う)
function site_of_owner(array $site, string $owner): array
{
    return ['apiaries' => array_filter($site['apiaries'], fn($a) => $a['_o'] === $owner),
        'hives' => array_filter($site['hives'], fn($x) => $x['_o'] === $owner)];
}

// チーム機能を使っているか(チームに入っている・共有された蜂場がある)。記入者や持ち主を出すかどうかに使う
function team_on(): bool
{
    global $UID;
    static $on = null;
    return $on ??= demo_members_on() || teams_of($UID) !== [] || count(array_unique(array_column(site_load()['apiaries'], '_o'))) > 1;
}

// メモ・作業記録・タスクを持ち主ごとに読み合わせる。自分の分はすべて、ほかの人の分は見られる蜂場のものだけ。_o を付ける
function records_merge(callable $read): array
{
    global $UID;
    $site = site_load();
    $out = [];
    foreach (array_unique(array_merge([$UID], array_column($site['apiaries'], '_o'))) as $o) {
        foreach ($read((string)$o) as $x) {
            if (!is_array($x)) continue;
            if ($o !== $UID && apiary_owner($site, (string)($x['apiary'] ?? '')) !== $o) continue;
            $out[] = ['_o' => (string)$o] + $x;
        }
    }
    return $out;
}

function memos_load(): array
{
    static $cache = null;
    return $cache ??= array_map(function (array $m) {
        $m['type'] = S_MEMO_RENAME[(string)($m['type'] ?? '')] ?? ($m['type'] ?? '');
        if (!empty($m['tags'])) $m['tags'] = array_values(array_unique(array_map(fn($t) => S_TAG_RENAME[(string)$t] ?? (string)$t, (array)$m['tags'])));
        return $m;
    }, records_merge(fn($o) => site_read_of($o, 'memos')));
}

function works_load(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $w = [];
    foreach (['logs', 'todos'] as $k) {
        $w[$k] = records_merge(function ($o) use ($k) { $v = site_read_of($o, 'works')[$k] ?? []; return is_array($v) ? $v : []; });
    }
    if (demo_members_on()) $w['logs'] = array_merge($w['logs'], demo_work_logs(site_load()));
    return $cache = $w;
}

// デモ用アカウントの見本の作業記録: 架空のメンバー (DEMO_MEMBERS) が、今月までの12か月に作業した記録を作る。
//   保存はせず、開くたびに同じ内容を作る (月ごとに乱数の種を決める)。demo => true のものは変更・削除できない
function demo_work_logs(array $site): array
{
    $aids = array_keys($site['apiaries']);
    if (!$aids) return [];
    $hivesOf = [];
    foreach ($site['hives'] as $hid => $x) $hivesOf[(string)($x['apiary'] ?? '')][] = (string)$hid;
    // 月ごとの作業の回数と、作業の種類の重み (春〜初夏に多く、冬は少ない)
    $count = [1 => 2, 2 => 3, 3 => 6, 4 => 9, 5 => 10, 6 => 9, 7 => 7, 8 => 6, 9 => 6, 10 => 5, 11 => 4, 12 => 2];
    $kinds = [
        '内検' => [1 => 1, 2 => 2, 3 => 5, 4 => 6, 5 => 6, 6 => 6, 7 => 5, 8 => 5, 9 => 5, 10 => 4, 11 => 2, 12 => 1],
        '給餌' => [1 => 2, 2 => 3, 3 => 3, 4 => 1, 5 => 0, 6 => 0, 7 => 1, 8 => 2, 9 => 3, 10 => 3, 11 => 2, 12 => 1],
        '採蜜' => [5 => 3, 6 => 3, 7 => 2, 8 => 1],
        '分蜂対応' => [4 => 3, 5 => 3, 6 => 1],
        '準備' => [1 => 2, 2 => 3, 3 => 2, 4 => 1, 9 => 1, 10 => 1, 12 => 1],
        '片付け' => [7 => 1, 8 => 1, 10 => 1, 11 => 3, 12 => 3],
        '移動' => [3 => 1, 6 => 1, 9 => 1, 11 => 1],
    ];
    $len = ['内検' => [60, 180], '給餌' => [30, 90], '採蜜' => [120, 300], '分蜂対応' => [45, 150], '準備' => [60, 180], '片付け' => [60, 150], '移動' => [90, 240]];
    $notes = ['内検' => ['女王を確認', '王台なし', '巣板を1枚追加', 'ダニ検査も実施', ''], '給餌' => ['糖液 2L', '代用花粉', ''], '採蜜' => ['遠心分離機で採蜜', '巣板6枚', ''],
        '分蜂対応' => ['王台を除去', '分蜂群を捕獲', ''], '準備' => ['巣枠の組み立て', '巣箱の修理', ''], '片付け' => ['巣箱の清掃', '越冬の準備', ''], '移動' => ['蜂場間で移動', '']];
    $members = array_keys(DEMO_MEMBERS);
    $today = date('Y-m-d');
    $out = [];
    for ($i = 11; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
        $mon = (int)substr($ym, 5, 2);
        mt_srand(crc32('hpx-demo-work-' . $ym));
        $days = (int)date('t', strtotime($ym . '-01'));
        for ($n = 0; $n < $count[$mon]; $n++) {
            // 作業の種類を重みで選ぶ
            $pick = [];
            foreach ($kinds as $t => $wt) for ($k = 0; $k < ($wt[$mon] ?? 0); $k++) $pick[] = $t;
            $type = $pick[mt_rand(0, count($pick) - 1)];
            $date = sprintf('%s-%02d', $ym, mt_rand(1, $days));
            $aid = (string)$aids[mt_rand(0, count($aids) - 1)];
            $hs = $hivesOf[$aid] ?? [];
            $hive = $type === '内検' && $hs && mt_rand(0, 1) ? $hs[mt_rand(0, count($hs) - 1)] : '';
            $who = $members;
            shuffle($who);
            $who = array_slice($who, 0, mt_rand(1, mt_rand(0, 2) ? 2 : 3));
            sort($who);
            $min = (int)(round(mt_rand($len[$type][0], $len[$type][1]) / 15) * 15);
            $st = mt_rand(14, 30) * 30;   // 7:00〜15:00 に始める
            $note = $notes[$type][mt_rand(0, count($notes[$type]) - 1)];
            if ($date > $today) continue;   // 今月は今日まで
            $out[] = ['id' => 'demo' . str_replace('-', '', $ym) . $n, 'demo' => true, '_o' => demo_uid(), 'apiary' => $aid, 'hive' => $hive, 'date' => $date,
                'type' => $type, 'workers' => $who, 'start' => sprintf('%02d:%02d', intdiv($st, 60), $st % 60),
                'end' => sprintf('%02d:%02d', intdiv($st + $min, 60) % 24, ($st + $min) % 60), 'minutes' => $min, 'people' => count($who),
                'note' => $note, 'created' => strtotime($date), 'by' => $who[0]];
            // 採蜜には見本の採蜜量 (8〜30kg)。乱数の順を変えないよう、id から決める
            if ($type === '採蜜') $out[count($out) - 1]['honey_kg'] = 8 + (crc32('hpx-demo-honey-' . $ym . $n) % 221) / 10;
        }
    }
    mt_srand();
    return $out;
}

// id で記録を探す(_o で持ち主が分かる)。無ければ null
function record_find(array $list, string $id): ?array
{
    if ($id === '') return null;
    foreach ($list as $x) if (($x['id'] ?? '') === $id) return $x;
    return null;
}

// 記入者などの表示名。チーム機能を使っていないときは出さない
function by_html(array $x, string $label = '記入'): string
{
    $by = (string)($x['by'] ?? '');
    return $by !== '' ? ' <span class="muted">' . h($label . ': ' . user_name($by)) . '</span>' : '';
}

// 対象の名前。「蜂場A」「蜂場A / 巣箱1」
// メモに付いたタグとして出すもの: 内検で選んだ給餌・ダニ管理・管理(複数選べる項目)と、タグ
function memo_chips(array $m): array
{
    $o = [];
    foreach ((array)($m['inspect'] ?? []) as $v) if (is_array($v)) foreach ($v as $x) $o[] = (string)$x;
    foreach ((array)($m['tags'] ?? []) as $x) $o[] = (string)$x;
    return array_values(array_unique($o));
}

function target_label(array $site, string $apiaryId, string $hiveId): string
{
    if ($apiaryId === '') return 'すべての蜂場';   // メモの対象「すべて」
    $a = (string)($site['apiaries'][$apiaryId]['name'] ?? '(削除した蜂場)');
    if ($hiveId === '') return $a;
    return $a . ' / ' . (string)($site['hives'][$hiveId]['name'] ?? '(削除した巣箱)');
}

// 対象の選択欄。値は「蜂場id|巣箱id」(巣箱idが空なら蜂場単位)
function target_select(array $site, string $selA, string $selH, string $name = 'target'): string
{
    $o = '<select name="' . h($name) . '" required>';
    if (!$site['apiaries']) $o .= '<option value="">(先に蜂場を登録してください)</option>';
    foreach ($site['apiaries'] as $aid => $a) {
        $o .= '<optgroup label="' . h((string)$a['name']) . '">';
        $v = $aid . '|';
        $o .= '<option value="' . h($v) . '"' . ($selA === $aid && $selH === '' ? ' selected' : '') . '>' . h((string)$a['name']) . ' (蜂場全体)</option>';
        foreach (hives_of($site, $aid) as $hid => $hv) {
            $o .= '<option value="' . h($aid . '|' . $hid) . '"' . ($selH === $hid ? ' selected' : '') . '>' . h((string)$a['name'] . ' / ' . (string)$hv['name']) . '</option>';
        }
        $o .= '</optgroup>';
    }
    return $o . '</select>';
}

// 送られた「蜂場id|巣箱id」を確かめる。正しくなければ null
function parse_target(array $site, string $v): ?array
{
    [$a, $hv] = array_pad(explode('|', $v, 2), 2, '');
    if (!isset($site['apiaries'][$a])) return null;
    if ($hv !== '' && (($site['hives'][$hv]['apiary'] ?? '') !== $a)) return null;
    return [$a, $hv];
}

// 対象の選択欄を「蜂場」と「巣箱」の2つに分けたもの(タスク・作業記録。メモは memo.php に同じ形のものがある)
//   蜂場の「すべて」(*)は、どの蜂場にも付けない記録(apiary が空。自分のフォルダに書き、どの蜂場の絞り込みにも出す)
//   巣箱は選んだ蜂場のものだけ出す(tHives)。空 = 蜂場全体。$all: 前からある記録で、対象が「すべて」
function target_pair(array $site, string $a, string $hv, bool $all): string
{
    $sa = '<select name="t_a" required onchange="tHives(this)"><option value="*"' . ($all ? ' selected' : '') . '>すべて</option>';
    $sh = '<select name="t_h"' . ($all ? ' disabled' : '') . '><option value=""></option>';
    foreach ($site['apiaries'] as $aid => $ap) {
        $sa .= '<option value="' . h((string)$aid) . '"' . (!$all && $a === (string)$aid ? ' selected' : '') . '>' . h((string)$ap['name']) . '</option>';
        foreach (hives_of($site, (string)$aid) as $hid => $x) {
            $sh .= '<option value="' . h((string)$hid) . '" data-a="' . h((string)$aid) . '"' . ($hv === (string)$hid ? ' selected' : '') . '>' . h((string)$x['name']) . '</option>';
        }
    }
    return '<span class="tsel">' . $sa . '</select>' . $sh . '</select></span>';
}

// target_pair から送られた対象。「すべて」は ['', '']、正しくなければ null
function parse_target_pair(array $site): ?array
{
    $ta = (string)($_POST['t_a'] ?? '');
    return $ta === '*' ? ['', ''] : parse_target($site, $ta . '|' . (string)($_POST['t_h'] ?? ''));
}

// target_pair の巣箱の欄を、選んだ蜂場のものだけにする(iPhone では option の hidden が効かないので入れ直す)。
// 巣箱の欄が変わったら、その欄の change を起こす
function target_pair_js(): string
{
    return <<<'JS'
<script>
function tHives(sa){
  var sh = sa.form.elements['t_h'], cur = sh.value;
  if (!sh._all) sh._all = Array.prototype.slice.call(sh.options).filter(function(o){ return o.value !== ''; });
  while (sh.options.length > 1) sh.remove(1);
  sh._all.forEach(function(o){ if (o.getAttribute('data-a') === sa.value) sh.appendChild(o); });
  sh.value = cur; if (sh.value !== cur) sh.value = '';
  sh.disabled = sa.value === '*'; if (sh.disabled) sh.value = '';
  sh.dispatchEvent(new Event('change'));
}
document.querySelectorAll('select[name=t_a]').forEach(tHives);
</script>
JS;
}

// ====================================================================
// タグ(メモ・タスク)。一覧はページごとに site/<名前>.json に {"tags": [...]} で保存し、画面の「カスタマイズ」で変える
// ====================================================================
const S_TAG_LEN = 20;
const S_TAG_MAX = 50;

function tag_list(string $file, array $default): array
{
    $v = site_read($file);
    return isset($v['tags']) && is_array($v['tags']) ? array_values(array_unique(array_map(fn($t) => S_TAG_RENAME[(string)$t] ?? (string)$t, $v['tags']))) : $default;
}

function tag_clean(string $t): string
{
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $t)), 0, S_TAG_LEN);
}

// 送られたタグ(tags[])を並べ直す
function tags_in(): array
{
    $tags = [];
    foreach ((array)($_POST['tags'] ?? []) as $t) {
        $t = tag_clean((string)$t);
        if ($t !== '' && !in_array($t, $tags, true) && count($tags) < S_TAG_MAX) $tags[] = $t;
    }
    return $tags;
}

// 一覧の変更(画面からその場で送られ、新しい一覧を JSON で返す。書きかけのフォームは消えない)。act が違えば何もしない
function tag_post(string $act, string $file, array $default): void
{
    if (!in_array($act, ['tag_add', 'tag_del', 'tag_reset'], true)) return;
    $t = tag_clean((string)($_POST['tag'] ?? ''));
    $err = '';
    site_update($file, function (array $v) use ($act, $t, $default, &$err) {
        $tags = isset($v['tags']) && is_array($v['tags']) ? $v['tags'] : $default;
        if ($act === 'tag_reset') $tags = $default;
        elseif ($act === 'tag_del') $tags = array_values(array_filter($tags, fn($x) => $x !== $t));
        elseif ($t === '') $err = 'タグの名前を入れてください。';
        elseif (in_array($t, $tags, true)) $err = '「' . $t . '」はもうあります。';
        elseif (count($tags) >= S_TAG_MAX) $err = 'タグは ' . S_TAG_MAX . ' 個までです。';
        else $tags[] = $t;
        return ['tags' => array_values($tags)];
    });
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['tags' => tag_list($file, $default), 'error' => $err], JSON_UNESCAPED_UNICODE);
    exit;
}

// タグを並べる(クリックで付け外し)。一覧にないタグがこの記録に付いていれば、それも出す。後ろに「カスタマイズ」
function tag_field(array $list, array $sel): string
{
    $o = '<p><label>タグ (クリックで選ぶ)</label><span class="tags">';
    foreach (array_values(array_unique(array_merge($list, $sel))) as $t) {
        $o .= '<label class="tagc"><input type="checkbox" name="tags[]" value="' . h($t) . '"' . (in_array($t, $sel, true) ? ' checked' : '') . '><span>' . h($t) . '</span></label>';
    }
    return $o . '</span><button type="button" class="tagcz" onclick="tagPanel(this)">カスタマイズ</button></p>';
}

// 一覧に出す、付いているタグ
function tag_show(array $tags): string
{
    if (!$tags) return '';
    return '<span class="tagl">' . implode('', array_map(fn($t) => '<span>' . h((string)$t) . '</span>', $tags)) . '</span>';
}

// 「カスタマイズ」の画面。$url はタグの変更を送るページ
function tag_js(string $url, array $tags): string
{
    global $csrf;
    $j = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
    return '<script>var TAGS=' . json_encode($tags, $j) . ',TAG_URL=' . json_encode($url, $j) . ',CSRF=' . json_encode($csrf, $j) . ';</script>' . <<<'JS'
<script>
function tagEsc(t){ return String(t).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
function tagRedraw(){
  document.querySelectorAll('.tags').forEach(function(box){
    var sel = Array.prototype.map.call(box.querySelectorAll('input:checked'), function(i){ return i.value; });
    var list = TAGS.concat(sel.filter(function(t){ return TAGS.indexOf(t) < 0; }));
    box.innerHTML = list.map(function(t){ return '<label class="tagc"><input type="checkbox" name="tags[]" value="' + tagEsc(t) + '"' + (sel.indexOf(t) >= 0 ? ' checked' : '') + '><span>' + tagEsc(t) + '</span></label>'; }).join('');
  });
  document.querySelectorAll('.tagp .tagx').forEach(function(x){
    x.innerHTML = TAGS.map(function(t){ return '<span>' + tagEsc(t) + '<button type="button" title="一覧から外す" data-t="' + tagEsc(t) + '">×</button></span>'; }).join('') || '<span class="muted">(タグはありません)</span>';
  });
}
function tagSend(act, tag, p){
  var fd = new FormData(); fd.append('act', act); fd.append('tag', tag || ''); fd.append('csrf', CSRF);
  fetch(TAG_URL, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function(r){ return r.json(); }).then(function(d){
    TAGS = d.tags; tagRedraw(); p.querySelector('.tagerr').textContent = d.error || '';
  }).catch(function(){ p.querySelector('.tagerr').textContent = '保存できませんでした。画面を開き直してください。'; });
}
function tagPanel(btn){
  var p = btn.parentNode.querySelector('.tagp');
  if (p){ p.remove(); return; }
  p = document.createElement('div'); p.className = 'tagp';
  p.innerHTML = '<div class="tagx"></div><input type="text" maxlength="20" placeholder="新しいタグ"> <button type="button" class="add">追加</button>'
    + ' <button type="button" class="rst">初期のタグに戻す</button><div class="tagerr ng"></div>'
    + '<div class="muted" style="font-size:.85em">ここでの変更は、このページで使うタグの一覧に反映されます (付けたタグは消えません)。</div>';
  btn.parentNode.appendChild(p); tagRedraw();
  var inp = p.querySelector('input');
  var add = function(){ if (inp.value.trim() !== ''){ tagSend('tag_add', inp.value, p); inp.value = ''; } };
  p.querySelector('.add').onclick = add;
  inp.onkeydown = function(e){ if (e.key === 'Enter'){ e.preventDefault(); add(); } };   // Enter でフォームが送られないように
  p.querySelector('.rst').onclick = function(){ if (confirm('タグの一覧を初期の状態に戻しますか?')) tagSend('tag_reset', '', p); };
  p.querySelector('.tagx').onclick = function(e){ var t = e.target.getAttribute('data-t'); if (t !== null) tagSend('tag_del', t, p); };
  inp.focus();
}
</script>
JS;
}

// センサの選択欄を「その蜂場の HPX-Core に登録されたセンサ」だけにする (巣箱フォーム・蜂場フォームで共通)
//   フォームに data-cf を付け、センサの行 (.cf) に data-hub="ハブid"、今の紐づけ先がこのフォームなら data-keep="1"。
//   どの HPX-Core が選ばれているかは、巣箱フォームは蜂場の選択肢 (option の data-hubs、JSON)、蜂場フォームは hubs[] のチェック。
//   ほかの HPX-Core のセンサは隠して送らない。前から紐づいているものだけは注意書き付きで残し、外せるようにする。
//   1ページに何度出しても script は1回だけ
function core_filter_js(): string
{
    static $done = false;
    if ($done) return '';
    $done = true;
    return <<<'JS'
<script>
(function(){
  function apply(f){
    var allow = {}, sel = f.querySelector('select[name=apiary]');
    if (sel) {
      var op = sel.options[sel.selectedIndex];
      try { (JSON.parse(op ? op.getAttribute('data-hubs') || '[]' : '[]')).forEach(function(h){ allow[h] = 1; }); } catch (e) {}
    } else {
      f.querySelectorAll('input[name="hubs[]"]').forEach(function(c){ if (c.checked) allow[c.value] = 1; });
    }
    var n = 0;
    f.querySelectorAll('.cf').forEach(function(r){
      var ok = allow[r.getAttribute('data-hub')] === 1, keep = r.getAttribute('data-keep') === '1';
      var c = r.querySelector('input'), x = r.querySelector('.cf-x');
      r.style.display = (ok || keep) ? 'block' : 'none';
      c.disabled = !(ok || keep);
      if (x) x.style.display = ok ? 'none' : '';
      if (ok || keep) n++;
    });
    var none = f.querySelector('.cf-none');
    if (none) none.style.display = n ? 'none' : '';
  }
  function init(){
    document.querySelectorAll('form[data-cf]').forEach(function(f){
      apply(f);
      f.addEventListener('change', function(e){
        if (e.target.name === 'apiary' || e.target.name === 'hubs[]') apply(f);
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
</script>
JS;
}

// 音声入力ボタン(🎤)。data-voice の付いたメモ欄の上に出す。文字起こしはブラウザの音声認識(Web Speech API、日本語)が
// するので、サーバーには文字だけが届き、音声は送らず保存もしない。使えないブラウザではボタンを出さない
function voice_js(): string
{
    return <<<'JS'
<style>.vrow{display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px;margin:2px 0 4px}.vbtn{padding:3px 12px;font-size:.9em;border:1px solid #b9c0c8;border-radius:16px;background:#fff;color:#234;cursor:pointer}.vbtn.on{background:#c62828;border-color:#c62828;color:#fff}.vmsg{font-size:.85em;color:#777}</style>
<script>
(function(){
  var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SR) return;   // 使えないブラウザ(Firefox など)ではボタンを出さない。スマホのキーボードのマイクは使える
  var cur = null;    // 今 聞いている欄 {rec, btn, msg, ta}
  function stop(){ if (cur) cur.rec.stop(); }
  function put(ta, txt){   // カーソルの位置(なければ最後)に文字を入れる
    var s = ta.selectionStart, e = ta.selectionEnd, v = ta.value;
    if (typeof s !== 'number' || document.activeElement !== ta) { s = e = v.length; }
    ta.value = v.slice(0, s) + txt + v.slice(e);
    ta.selectionStart = ta.selectionEnd = s + txt.length;
    ta.dispatchEvent(new Event('input', {bubbles: true}));
  }
  function start(ta, btn, msg){
    if (cur) { var same = cur.ta === ta; stop(); if (same) return; }
    var rec = new SR();
    rec.lang = 'ja-JP'; rec.continuous = true; rec.interimResults = true;
    var me = {rec: rec, btn: btn, msg: msg, ta: ta};
    rec.onresult = function(ev){
      var mid = '';
      for (var i = ev.resultIndex; i < ev.results.length; i++) {
        var r = ev.results[i];
        if (r.isFinal) put(ta, r[0].transcript); else mid += r[0].transcript;
      }
      msg.textContent = mid ? '… ' + mid : '聞いています。話してください';
    };
    rec.onerror = function(ev){
      if (ev.error === 'not-allowed' || ev.error === 'service-not-allowed') alert('マイクが使えません。ブラウザの設定で、このサイトのマイクを「許可」にしてください。');
      else if (ev.error === 'no-speech') msg.textContent = '声が聞き取れませんでした';
      else if (ev.error === 'network') alert('音声入力にはインターネットの接続が必要です。');
    };
    rec.onend = function(){
      btn.classList.remove('on'); btn.textContent = '🎤 音声入力';
      if (msg.textContent.indexOf('聞いて') === 0 || msg.textContent.indexOf('…') === 0) msg.textContent = '';
      if (cur === me) cur = null;
    };
    try { rec.start(); } catch (e) { return; }
    cur = me;
    btn.classList.add('on'); btn.textContent = '■ 止める';
    msg.textContent = '聞いています。話してください';
  }
  document.querySelectorAll('textarea[data-voice]').forEach(function(ta){
    var row = document.createElement('div'); row.className = 'vrow';
    var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'vbtn'; btn.textContent = '🎤 音声入力';
    btn.title = '話した言葉を文字にしてメモに入れます (音声は保存しません)';
    var msg = document.createElement('span'); msg.className = 'vmsg';
    btn.onclick = function(){ start(ta, btn, msg); };
    row.appendChild(btn); row.appendChild(msg);
    ta.parentNode.insertBefore(row, ta);
  });
  // 保存するときは止める
  document.addEventListener('submit', stop, true);
})();
</script>
JS;
}

function str_in(string $key, int $max = 2000): string
{
    $v = trim(str_replace("\r\n", "\n", (string)($_POST[$key] ?? '')));
    return mb_substr($v, 0, $max);
}

function date_in(string $key, string $default = ''): string
{
    $v = (string)($_POST[$key] ?? '');
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : $default;
}

function time_in(string $key): string
{
    $v = (string)($_POST[$key] ?? '');
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : '';
}

// かかった時間(分)。開始と終了があればその差(日をまたいでもよい)、なければ入れた分
function work_minutes(string $start, string $end, int $min): int
{
    if ($start === '' || $end === '') return $min;
    $m = (int)((strtotime('2000-01-01 ' . $end) - strtotime('2000-01-01 ' . $start)) / 60);
    return $m <= 0 ? $m + 1440 : $m;
}

function work_log_delete(string $id): void
{
    site_update('works', function (array $w) use ($id) {
        $w['logs'] = array_values(array_filter(is_array($w['logs'] ?? null) ? $w['logs'] : [], fn($x) => ($x['id'] ?? '') !== $id));
        return $w;
    });
}

function nl(string $s): string { return nl2br(h($s), false); }

// ====================================================================
// 画面
// ====================================================================

function page_head(string $title, string $active): void
{
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . '</title>' . brand_head() . '<style>'
        . 'body{font-family:system-ui,sans-serif;margin:0 auto;padding:0 16px 24px;background:#f6f7f8;color:#222;max-width:900px}'
        . 'h1{font-size:1.3em;margin:.6em 0}h2{font-size:1.15em;margin-top:1.6em;border-bottom:2px solid #ccd;padding-bottom:4px}h3{font-size:1.05em;margin:.2em 0 .4em}'
        . 'table{border-collapse:collapse;width:100%;background:#fff;font-size:.92em}'
        . 'th,td{border:1px solid #ddd;padding:6px 8px;text-align:left;vertical-align:top}th{background:#eef0f2}'
        . 'td.n{text-align:right;white-space:nowrap}'
        . '.ok{color:#11734b;font-weight:bold}.ng{color:#c62828;font-weight:bold}.warn{color:#b26a00;font-weight:bold}'
        . '.muted{color:#777;font-size:.9em}.card{background:#fff;border:1px solid #ddd;border-radius:6px;padding:10px 12px;margin:8px 0}'
        . '.flash{background:#eef8f1;border-color:#b5dcc2}'
        . '.wrap{overflow-x:auto}input,button,select,textarea{font-size:1em;padding:6px 10px;max-width:100%;box-sizing:border-box}'
        . 'textarea{width:100%;min-height:5em}input[type=text]{width:100%}input[type=number]{width:7em}'
        . 'details{margin:8px 0}summary{cursor:pointer}svg{background:#fff;border:1px solid #ddd;width:100%;height:auto}'
        . '.tabs a,.per a{display:inline-block;padding:6px 12px;margin:0 6px 6px 0;border:1px solid #bbc;border-radius:16px;background:#fff;color:#234;text-decoration:none}'
        . '.tabs a.on,.per a.on{background:#234;color:#fff;border-color:#234}.per a{padding:3px 10px;font-size:.9em}'
        . 'dl.info{display:grid;grid-template-columns:auto 1fr;gap:4px 12px;margin:0}dl.info dt{color:#555}dl.info dd{margin:0}'
        . 'nav{position:sticky;top:0;background:#234;margin:0 -16px 10px;padding:8px 16px;z-index:5;display:flex;flex-wrap:wrap;gap:4px 14px;align-items:center}'
        . 'nav a{color:#fff;text-decoration:none;padding:4px 2px}nav a.on{border-bottom:2px solid #ffd54f;font-weight:bold}nav .r{margin-left:auto;font-size:.85em}'
        . '.badge{display:inline-block;background:#c62828;color:#fff;border-radius:10px;padding:0 7px;font-size:.8em;margin-left:3px}'
        . '.badge.soon{background:#b26a00}'
        . '.hive{border-left:5px solid #f2b21b}.apiary{border-left:5px solid #2e7d32}'
        . '.form p{margin:.5em 0}.form label{display:block;color:#555;font-size:.92em}'
        . '.grid2{display:grid;grid-template-columns:1fr 1fr;gap:0 12px}@media(max-width:560px){.grid2{grid-template-columns:1fr}}'
        . 'ul.todo{margin:.3em 0;padding-left:1.2em}ul.todo li{margin:2px 0}'
        . '[id]{scroll-margin-top:90px}.nw td,.nw th{white-space:nowrap}.cols{display:grid;grid-template-columns:1fr 1fr;gap:0 12px;align-items:stretch}.cols>.apiary{display:flex;flex-direction:column;border:2px solid #b9c0c8;border-left:6px solid #2e7d32}.cols>.apiary>.bottom{margin-top:auto}.addbox{align-self:start;margin:8px 0}.addbox>summary{list-style:none;display:flex;flex-direction:column;align-items:center;justify-content:center;width:180px;height:110px;background:#fff;border:2px dashed #9aa3ad;border-radius:6px;color:#345}.addbox>summary::-webkit-details-marker{display:none}.addbox>summary:hover{background:#f0f4f8}.addbox .plus{font-size:2.6em;line-height:1;font-weight:300}.addbox[open]>summary{width:auto;height:auto;flex-direction:row;gap:8px;padding:6px 12px}.addbox[open] .plus{font-size:1.4em}@media(max-width:600px){.cols{grid-template-columns:1fr}}.mini p.muted{margin:.4em 0 0}.pm{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin:.4em 0}.pm>*{width:100%;height:150px;border:1px solid #ddd;border-radius:4px;display:block}.pm img{object-fit:cover;height:150px;width:100%;border:0}.pm a{overflow:hidden}.per.sq{margin:0 0 .3em}.per.sq a{border-radius:3px;padding:1px 9px;margin:0 4px 4px 0;font-size:.85em;line-height:1.4}.gn{display:none}@media(max-width:600px){.gw{display:none}.gn{display:block}}.gt{margin:.7em 0 .1em;font-size:1.1em;font-weight:bold}.hbtns{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 8px}.hbtns a{display:flex;align-items:center;justify-content:center;width:64px;height:64px;padding:4px;box-sizing:border-box;border:2px solid #f2b21b;border-radius:6px;background:#fff;color:#5d4300;text-decoration:none;font-size:1.05em;font-weight:bold;line-height:1.15;text-align:center;overflow:hidden;word-break:break-all}.hbtns a.on{background:#f2b21b;color:#000}.hbtns a.add{border-style:dashed;border-color:#9aa3ad;color:#777;font-size:1.8em;font-weight:300}.hbtns a.add.on{background:#eef0f2}.chips{display:flex;flex-wrap:wrap;gap:4px;margin:.2em 0 .4em;align-items:center}.chip{display:inline-flex;align-items:center;gap:2px;font-size:.6em;line-height:1.15;padding:1px 6px;border-radius:6px;background:#c8e6c9;color:#1b5e20;text-decoration:none;white-space:nowrap}.chip.ng{background:#cfd3d8;color:#666}.chip.alert{background:#c62828}.chip .x{color:#d32f2f;font-weight:bold;margin-right:2px}.chip.alert .x{color:#fff}.wx{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin:0 0 .4em;height:75px}.wx>div{background:#f4f8fc;border:1px solid #dde5ee;border-radius:4px;display:grid;grid-template-columns:auto auto;grid-template-rows:auto 1fr;align-items:center;justify-content:center;column-gap:6px;overflow:hidden}.wx .d{grid-column:1/-1;text-align:center;font-size:.8em;color:#555;padding-top:2px}.wx .i{font-size:2em;line-height:1}.wx .t{font-size:.8em;line-height:1.3;color:#444}.wx .mx{color:#c62828;margin-right:4px}.wx .mn{color:#1565c0}.wx .none{display:flex;align-items:center;justify-content:center;color:#999;background:#f3f4f6;font-size:.9em}.pm .none{display:flex;align-items:center;justify-content:center;color:#999;background:#f3f4f6;font-size:.9em}.danger{border-color:#e0b4b4;background:#fff7f7}.inl{display:inline;margin:0}.tipw{position:relative;touch-action:pan-y}.tsel{display:flex;gap:6px}.tsel select{flex:1;min-width:0}.tags{display:flex;flex-wrap:wrap;gap:5px;margin:2px 0 4px}.tagc input{position:absolute;opacity:0;pointer-events:none}.tagc span{display:inline-block;padding:3px 10px;border:1px solid #b9c0c8;border-radius:14px;background:#fff;color:#345;font-size:.9em;cursor:pointer;user-select:none}.tagc input:checked+span{background:#f2b21b;border-color:#d99a00;color:#000;font-weight:bold}.tagc input:focus-visible+span{outline:2px solid #1565c0}.tagcz{font-size:.8em;padding:2px 10px}.tagp{margin:6px 0;padding:8px;border:1px dashed #9aa3ad;border-radius:6px;background:#f8f9fa}.tagx{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:6px}.tagx>span{display:inline-flex;align-items:center;gap:2px;padding:2px 4px 2px 10px;border:1px solid #ccd;border-radius:14px;background:#fff;font-size:.9em}.tagx button{border:0;background:none;color:#c62828;font-size:1.1em;padding:0 4px;cursor:pointer}.tagp input[type=text]{width:12em}.msearch{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 6px}.msearch input[type=search]{width:12em}.ichk{display:flex;flex-wrap:wrap;gap:5px;margin:2px 0 4px}.grid2>.wide{grid-column:1/-1}.tbtn{display:flex;gap:4px}@media(max-width:600px){.tbtn{display:grid;grid-template-columns:auto auto}.tbtn button{padding:3px 6px}.todot td:first-child{white-space:normal!important}}.tbtn .del{background:#fff;color:#c62828;border:1px solid #e0b4b4}.tedit>td{background:#fafbf7}.wleg{display:flex;flex-wrap:wrap;gap:4px 12px;font-size:.8em;margin-top:4px}.wleg i{display:inline-block;width:11px;height:11px;border-radius:2px;margin-right:4px;vertical-align:-1px}.tbtn button{padding:3px 9px;font-size:.85em;white-space:nowrap}.tcal{margin:12px 0}.tcal table{table-layout:fixed;width:100%}.tcal th{text-align:center;padding:3px;font-size:.85em}.tcal td{height:72px;padding:2px 3px;vertical-align:top;font-size:.78em;overflow:hidden}.tcal .w0 .d,.tcal th.w0{color:#c62828}.tcal .w6 .d,.tcal th.w6{color:#1565c0}.tcal td.out{background:#f3f4f2}.tcal td.today{background:#fdf6dc}.tcal td.today .d{font-weight:bold}.tcal .d{font-size:.95em;margin-bottom:1px}.tcal .ti{background:#dbe8e0;color:#1F2328;border-radius:3px;padding:0 3px;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tcal .ti.over{background:#f8d7d7;color:#8e1b1b}.tcal .ti.done{background:none;color:#999;text-decoration:line-through}@media(max-width:600px){.tcal td{height:56px;font-size:.66em;padding:1px}}.grid2.insp{grid-template-columns:max-content max-content 1fr;column-gap:48px}.insp>p:not(.wide):nth-child(odd){grid-column:1}.insp>p:not(.wide):nth-child(even){grid-column:2}.insp input[type=number]{width:6em}.insp>.wide{width:0;min-width:100%;box-sizing:border-box}@media(max-width:600px){.grid2.insp{column-gap:20px}}.gt .gx{float:right;font-size:.72em;font-weight:normal;color:#1F2328;margin-top:.25em}@media(max-width:600px){.gt .gx{float:none;display:block;margin:.1em 0 0}}.alertset{margin:.6em 0}.alertset>summary{font-size:.92em}.alg{display:grid;grid-template-columns:auto auto auto 1fr;gap:8px 16px;align-items:center;margin:.4em 0}.alg>b{white-space:nowrap}.alg label{display:inline-flex;align-items:center;gap:3px;color:#1F2328;font-size:.95em;white-space:nowrap}.alg label i{font-style:normal;width:.8em;text-align:center}.alg input[type=number]{width:5em}.alg .base{white-space:normal;flex-wrap:wrap;gap:4px}.alg .alnote{grid-column:1/-1;font-size:.85em;margin:-2px 0 4px}@media(max-width:600px){.alg{grid-template-columns:auto auto auto;gap:8px 8px}.alg input[type=number]{width:3.8em}.alg .base{grid-column:2/-1}.alg>span{display:none}}.mite{margin:.2em 0 .5em}.mrow{display:flex;flex-wrap:wrap;gap:6px 12px;align-items:center}.mrate{color:#2F5D45}.towork{margin:.4em 0 .6em;padding:6px 8px;background:#f4f8fc;border:1px solid #dde5ee;border-radius:4px}.towork .wbox{margin-top:4px}.tagl{display:flex;flex-wrap:wrap;gap:4px;margin:.3em 0}.tagl span{padding:1px 8px;border-radius:10px;background:#c8e6c9;color:#1b5e20;font-size:.8em}#memo-form>summary{display:inline-block;list-style:none;margin:2px 0 4px;padding:6px 16px;border:1px solid #e0c060;border-radius:18px;background:#fdf0c8;color:#3d2c00;font-size:1.1em}#memo-form>summary::-webkit-details-marker{display:none}#memo-form>summary:hover{background:#fbe6a6}#memo-form[open]>summary{background:#fbe6a6}.tip{display:none;position:absolute;top:4px;z-index:5;background:rgba(255,255,255,.95);border:1px solid #aab;border-radius:4px;padding:3px 7px;font-size:.8em;line-height:1.35;white-space:nowrap;pointer-events:none;box-shadow:0 1px 3px rgba(0,0,0,.15)}.tip .tt{font-weight:bold;color:#333}.mph{display:flex;flex-wrap:wrap;gap:6px;margin:.4em 0}.mph img{height:120px;width:auto;max-width:100%;border-radius:4px;border:1px solid #ddd;display:block;object-fit:cover}.mpcur{display:flex;flex-direction:column;align-items:center;gap:2px;font-size:.85em}.mpcur label{display:inline;color:#1F2328}.mpnew{display:contents}.lb{display:none;position:fixed;inset:0;z-index:50;background:rgba(20,24,28,.88);align-items:center;justify-content:center}.lb.on{display:flex}.lb img{max-width:94vw;max-height:88vh;border-radius:4px;box-shadow:0 4px 24px rgba(0,0,0,.5)}.lb button{position:absolute;background:rgba(0,0,0,.35);border:0;color:#fff;cursor:pointer;border-radius:50%;width:44px;height:44px;font-size:28px;line-height:1;padding:0}.lb button:hover{background:rgba(0,0,0,.6)}.lb .lbx{top:12px;right:12px}.lb .lbp{left:10px;top:50%;transform:translateY(-50%)}.lb .lbn{right:10px;top:50%;transform:translateY(-50%)}.lb .lbc{position:absolute;bottom:14px;left:0;right:0;text-align:center;color:#fff;font-size:.9em}.mphoto input[type=file]{margin:2px 0}'
        . 'button.link{background:none;border:none;color:#1565c0;padding:0;cursor:pointer;text-decoration:underline;font-size:.92em}'
        . brand_css() . '</style></head><body>';
    if ($active === '') return;
    // メニューと、期限切れ・今日の タスクの数
    [$over, $due] = todo_counts();
    $items = ['apiary.php' => '蜂場', 'hive.php' => '巣箱', 'memo.php' => 'メモ', 'task.php' => 'タスク', 'work.php' => '記録'];
    echo '<nav>' . brand_nav_logo();
    foreach ($items as $f => $lb) {
        echo '<a href="' . $f . '"' . ($active === $f ? ' class="on"' : '') . '>' . h($lb)
            . ($f === 'task.php' && $over ? '<span class="badge" title="期限切れの タスク">' . $over . '</span>' : '')
            . ($f === 'task.php' && $due ? '<span class="badge soon" title="今日までの タスク">' . $due . '</span>' : '') . '</a>';
    }
    echo auth_nav_right($active) . '</nav>';
    global $ME, $HPX_DEMO_READONLY, $HPX_DEMO_RESET_HOUR;
    if (is_demo($ME)) echo '<div class="card" style="background:#fdf0c8;border-color:#e0c060">これは<b>デモ用アカウント</b>です。'
        . (demo_editing() ? '<b>見本の準備中</b>です (写真も追加できます)。入れ終わったら、管理者のアカウントの管理ページで「今の状態を初期状態として保存」を押してください。'
            : (!empty($HPX_DEMO_READONLY) ? '見るだけで、変更はできません。'
            : '自由に入力して試せます。ほかの人も同じアカウントを使っているので、個人の情報は書かないでください。入力した内容は毎日 ' . (int)($HPX_DEMO_RESET_HOUR ?? 3) . '時ごろに元に戻ります (写真の追加と設定の変更はできません)。'))
        . '</div>';
}

// ====================================================================
// タスク
// ====================================================================

// 期限切れ・今日が期限 の件数(メニューに出す)
function todo_counts(): array
{
    global $today;
    $over = 0; $due = 0;
    foreach (works_load()['todos'] as $t) {
        if (!empty($t['done']) || ($t['due'] ?? '') === '') continue;
        if ($t['due'] < $today) $over++;
        elseif ($t['due'] === $today) $due++;
    }
    return [$over, $due];
}

// タスクの名前。やることが空(タグだけで書いた)なら、タグを「・」でつないだもの
function todo_title(array $t): string
{
    $title = (string)($t['title'] ?? '');
    return $title !== '' ? $title : implode('・', array_map('strval', (array)($t['tags'] ?? [])));
}

// 期限の表示。期限切れは赤、今日は橙、S_SOON_DAYS 日以内は橙
function due_html(string $due): string
{
    global $today;
    if ($due === '') return '<span class="muted">期限なし</span>';
    $d = (int)round((strtotime($due) - strtotime($today)) / 86400);
    $txt = date('n/j', strtotime($due));
    if ($d < 0) return '<span class="ng">' . h($txt) . ' (' . -$d . '日過ぎ)</span>';
    if ($d === 0) return '<span class="warn">' . h($txt) . ' (今日)</span>';
    if ($d <= S_SOON_DAYS) return '<span class="warn">' . h($txt) . ' (あと' . $d . '日)</span>';
    return h($txt);
}

// タスクの担当者。決まっていなければ何も出さない
function assignee_html(array $t): string
{
    $as = todo_assignees($t);
    return $as ? ' <span class="muted">(担当: ' . h(implode('・', array_map('user_name', $as))) . ')</span>' : '';
}

// 作業記録の担当者(作業した人。複数)。選んでいなければ記入者
function log_workers(array $l): array
{
    $w = isset($l['workers']) && is_array($l['workers']) ? array_values(array_filter(array_map('strval', $l['workers']), 'is_uid')) : [];
    if (!$w && is_uid((string)($l['by'] ?? ''))) $w = [(string)$l['by']];
    return $w;
}

function todo_sort(array $todos): array
{
    usort($todos, fn($a, $b) => [($a['due'] ?? '') === '' ? 1 : 0, $a['due'] ?? '', $a['created'] ?? 0]
        <=> [($b['due'] ?? '') === '' ? 1 : 0, $b['due'] ?? '', $b['created'] ?? 0]);
    return $todos;
}

// 蜂場・巣箱ページに出す タスクの短い一覧(未完了のみ)。$withHives: 蜂場の分に、その巣箱の分も含める
function todo_brief(array $site, string $apiaryId, string $hiveId, bool $withHives, string $back, int $max = 5): string
{
    $list = [];
    foreach (works_load()['todos'] as $t) {
        if (!empty($t['done'])) continue;
        // 対象が「すべて」(蜂場が空)のタスクは、どの蜂場にも出す(巣箱ページには出さない)
        if (($t['apiary'] ?? '') === '' && $apiaryId !== '' && $hiveId === '') { $list[] = $t; continue; }
        if (($t['apiary'] ?? '') !== $apiaryId) continue;
        $th = (string)($t['hive'] ?? '');
        if ($hiveId !== '' ? $th !== $hiveId : (!$withHives && $th !== '')) continue;
        $list[] = $t;
    }
    $add = '<a href="task.php?' . h(http_build_query(['new_todo' => $apiaryId . '|' . $hiveId])) . '#todo-form">＋タスク</a>';
    if (!$list) return '<p class="muted">タスクはありません。 ' . $add . '</p>';
    $list = todo_sort($list);
    $o = '<ul class="todo">';
    foreach (array_slice($list, 0, $max) as $t) {
        $th = (string)($t['hive'] ?? '');
        $o .= '<li>' . due_html((string)($t['due'] ?? '')) . ' ' . h(todo_title($t)) . assignee_html($t)
            . (($t['apiary'] ?? '') === '' ? ' <span class="muted">(すべての蜂場)</span>' : '')
            . ($hiveId === '' && $th !== '' ? ' <span class="muted">(' . h((string)($site['hives'][$th]['name'] ?? '')) . ')</span>' : '')
            . ' <form class="inl" method="post" action="work.php">' . csrf_field()
            . '<input type="hidden" name="act" value="todo_done"><input type="hidden" name="id" value="' . h((string)$t['id']) . '">'
            . '<input type="hidden" name="back" value="' . h($back) . '"><button class="link" type="submit">完了</button></form></li>';
    }
    $o .= '</ul>';
    if (count($list) > $max) $o .= '<p class="muted">ほか ' . (count($list) - $max) . ' 件</p>';
    return $o . '<p class="muted"><a href="task.php?' . h(http_build_query(['a' => $apiaryId])) . '#todo">タスクをすべて見る</a>　' . $add . '</p>';
}

// ====================================================================
// センサー(ハブのデータフォルダを読むだけ)
// ====================================================================

// 共有された蜂場・巣箱に紐づいている、ほかの人のハブとセンサ: 持ち主 => ["ハブid" => 1, "ハブid/mac" => 1, …]
//   その持ち主の蜂場で管理者なら、どこにも紐づいていないハブ・センサも入れる(共有された蜂場・巣箱に紐づけられるように)。
//   紐づいていないものは "*free" => [キー => 1] に入れる
function shared_devices(): array
{
    global $UID;
    static $out = null;
    if ($out !== null) return $out;
    $site = site_load();
    $out = [];
    foreach ($site['apiaries'] as $a) {
        if ($a['_o'] === $UID) continue;
        $out[$a['_o']] ??= [];
        foreach (array_merge((array)($a['hubs'] ?? []), (array)($a['fences'] ?? [])) as $k) $out[$a['_o']][(string)$k] = 1;
        if ($a['_r'] === 'admin') $out[$a['_o']]['*free'] = [];
    }
    foreach ($site['hives'] as $x) {
        if ($x['_o'] === $UID) continue;
        foreach ((array)($x['sensors'] ?? []) as $k) $out[$x['_o']][(string)$k] = 1;
    }
    foreach ($out as $o => &$keys) {
        if (!isset($keys['*free'])) continue;
        $used = [];   // 持ち主のどこかの蜂場・巣箱に紐づいているもの
        $raw = owner_site((string)$o);
        foreach ((array)($raw['apiaries'] ?? []) as $a) foreach (array_merge((array)($a['hubs'] ?? []), (array)($a['fences'] ?? [])) as $k) $used[(string)$k] = 1;
        foreach ((array)($raw['hives'] ?? []) as $x) foreach ((array)($x['sensors'] ?? []) as $k) $used[(string)$k] = 1;
        foreach (glob(account_hubs_dir((string)$o) . '/*/state.json') ?: [] as $p) {
            $hid = basename(dirname($p));
            if (!isset($used[$hid])) $keys['*free'][$hid] = 1;
            $st = json_decode((string)@file_get_contents($p), true);
            foreach ((is_array($st['sensors'] ?? null) ? $st['sensors'] : []) as $mac => $sn) if (!isset($used[$hid . '/' . $mac])) $keys['*free'][$hid . '/' . $mac] = 1;
        }
        $keys += $keys['*free'];
    }
    unset($keys);
    return $out;
}

// ハブ(HPX-Core)の一覧: id => [dir, st, name, o(持ち主)]
//   自分のハブはすべて。ほかの人のハブは、共有された蜂場・巣箱に紐づいているものだけ
function hpx_hubs(): array
{
    global $UID;
    static $hubs = null;
    if ($hubs !== null) return $hubs;
    $hubs = [];
    $shared = shared_devices();
    foreach (array_merge([$UID => null], $shared) as $o => $keys) {
        foreach (glob(account_hubs_dir((string)$o) . '/*/state.json') ?: [] as $p) {
            $id = basename(dirname($p));
            if (isset($hubs[$id])) continue;
            if ($keys !== null && !isset($keys[$id]) && !array_filter(array_keys($keys), fn($k) => str_starts_with((string)$k, $id . '/'))) continue;
            $st = json_decode((string)@file_get_contents($p), true);
            if (!is_array($st)) continue;
            $hubs[$id] = ['dir' => dirname($p), 'st' => $st, 'name' => (string)($st['name'] ?? '') !== '' ? (string)$st['name'] : $id, 'o' => (string)$o,
                'retired' => is_file(dirname($p) . '/.retired')];   // センサページで「外した」もの。記録と紐づけは残す
        }
    }
    uasort($hubs, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $hubs;
}

// センサーの一覧: "ハブid/mac" => [hub, mac, kind(th/w/fa), name, hubName, s(state.json の内容), o(持ち主)]
//   ほかの人のハブのセンサは、共有された蜂場・巣箱に紐づいているものだけ
function hpx_sensors(): array
{
    global $UID;
    $shared = shared_devices();
    $out = [];
    foreach (hpx_hubs() as $hid => $hb) {
        foreach ((is_array($hb['st']['sensors'] ?? null) ? $hb['st']['sensors'] : []) as $mac => $s) {
            if (!is_array($s)) continue;
            if ($hb['o'] !== $UID && !isset($shared[$hb['o']][$hid . '/' . $mac])) continue;
            $k = (string)($s['kind'] ?? 'th');
            $out[$hid . '/' . $mac] = ['hub' => $hid, 'mac' => (string)$mac, 'kind' => in_array($k, ['th', 'w', 'fa'], true) ? $k : 'th',
                'name' => (string)(($s['name'] ?? '') !== '' ? $s['name'] : $mac), 'hubName' => $hb['name'], 's' => $s, 'o' => $hb['o'], 'retired' => $hb['retired']];
        }
    }
    return $out;
}

function kind_text(string $k): string { return ['th' => '温湿度', 'w' => '重量', 'fa' => '電柵アラート'][$k] ?? $k; }

// ble.csv(ハブごと)を1回だけ読む。th: mac => [[epoch, 温度, 湿度, 電池%]]  w: mac => [[epoch, 生の値]]  first: 最古の時刻
function ble_data(string $hubId, int $since): array
{
    static $cache = [];
    $key = $hubId . '@' . $since;
    if (isset($cache[$key])) return $cache[$key];
    $out = ['th' => [], 'w' => [], 'first' => 0];
    $hub = hpx_hubs()[$hubId] ?? null;
    $fh = $hub ? @fopen($hub['dir'] . '/ble.csv', 'rb') : false;
    if (!$fh) return $cache[$key] = $out;
    $hd = fgetcsv($fh);
    $col = is_array($hd) ? array_flip($hd) : [];
    if (!isset($col['epoch'], $col['temp_c'], $col['mac'], $col['kind'])) { fclose($fh); return $cache[$key] = $out; }
    $g = fn(array $r, string $k) => isset($col[$k]) ? (string)($r[$col[$k]] ?? '') : '';
    while (($r = fgetcsv($fh)) !== false) {
        if (count($r) < count($hd)) continue;
        $ep = (int)$r[$col['epoch']];
        if ($ep <= 0) continue;
        if ($out['first'] === 0 || $ep < $out['first']) $out['first'] = $ep;
        if ($ep < $since) continue;
        $mac = $g($r, 'mac');
        $kind = $g($r, 'kind');
        if ($kind === 'w') {
            if (is_numeric($g($r, 'raw'))) $out['w'][$mac][] = [$ep, (int)$g($r, 'raw')];
        } elseif ($kind !== 'fa' && (is_numeric($g($r, 'temp2_c')) || is_numeric($g($r, 'temp_c')))) {
            $hm = $g($r, 'humidity_pct');
            $bt = $g($r, 'battery_pct');
            $tc = is_numeric($g($r, 'temp2_c')) ? $g($r, 'temp2_c') : $g($r, 'temp_c');   // HPX-TH はサーミスターの温度 (無いときは SHT40)
            $ink = !is_numeric($g($r, 'temp2_c')) && !is_numeric($g($r, 'batt_v'));   // Inkbird の形(HPX-TH でない)
            $tc = (float)$tc + th_temp_corr($hub['dir'], $mac, $ink);   // 機種ごとの補正(Inkbird・HPX-TH)と温湿度計ごとの補正
            $out['th'][$mac][] = [$ep, $tc, th_hum_fix(is_numeric($hm) ? (float)$hm : null, $hub['dir'], $mac, $ink), is_numeric($bt) ? (float)$bt : null];
        }
    }
    fclose($fh);
    foreach (['th', 'w'] as $k) foreach ($out[$k] as &$rows) usort($rows, fn($a, $b) => $a[0] <=> $b[0]);
    unset($rows);
    return $cache[$key] = $out;
}

function cal_load(string $hubId): array
{
    static $cache = [];
    if (isset($cache[$hubId])) return $cache[$hubId];
    $hub = hpx_hubs()[$hubId] ?? null;
    return $cache[$hubId] = $hub ? wcal_with_inherit($hub['dir']) : [];   // ほかのハブで較正済みのセンサは引き継ぐ
}

function to_kg(int $raw, ?array $c): ?float
{
    if (!$c || !isset($c['zero'], $c['per_kg']) || (float)$c['per_kg'] == 0.0) return null;
    return ($raw - (float)$c['zero']) / (float)$c['per_kg'] - (float)($c['offset_kg'] ?? 0);
}

function ago_text(int $epoch, int $now): string
{
    $d = max(0, $now - $epoch);
    if ($d < 3600) return intdiv($d, 60) . '分前';
    if ($d < 86400) return intdiv($d, 3600) . '時間前';
    return intdiv($d, 86400) . '日前';
}

function rssi_text(?int $rssi): string
{
    if ($rssi === null || $rssi === 0) return '-';
    $lv = $rssi >= -60 ? ['ok', '強い'] : ($rssi >= -75 ? ['ok', 'ふつう'] : ($rssi >= -85 ? ['warn', '弱い'] : ['ng', 'とても弱い']));
    return h($rssi . ' dBm') . ' <span class="' . $lv[0] . '">' . $lv[1] . '</span>';
}

function fence_state_html(array $s): string
{
    if (!array_key_exists('fence_state', $s)) return '<span class="muted">記録なし</span>';
    $st = (int)$s['fence_state'];
    $txt = [0 => '正常', 1 => '電圧低下', 2 => '常時点灯 (光センサの遮光・配線を確認)'][$st] ?? '不明';
    return '<span class="' . ($st === 0 ? 'ok' : ($st === 1 ? 'ng' : 'warn')) . '">' . h($txt) . '</span>';
}

// 点が多すぎるときは、時間で区切って平均する
function thin(array $pts, int $from, int $to): array
{
    if (count($pts) <= S_MAX_POINTS) return $pts;
    $bin = max(1, intdiv($to - $from, S_MAX_POINTS));
    $out = []; $k = null; $sx = 0; $sy = 0.0; $n = 0;
    foreach ($pts as [$e, $v]) {
        $b = intdiv($e - $from, $bin);
        if ($k !== null && $b !== $k) { $out[] = [intdiv($sx, $n), $sy / $n]; $sx = 0; $sy = 0.0; $n = 0; }
        $k = $b; $sx += $e; $sy += $v; $n++;
    }
    if ($n) $out[] = [intdiv($sx, $n), $sy / $n];
    return $out;
}

// 折れ線グラフ(SVG)と凡例。$series: [[名前, 色, [[epoch, 値], ...]], ...]
// $W・$H・$fs で大きさを変えられる(蜂場ページの小さなグラフ用)
function chart_svg(array $series, int $from, int $to, string $unit, float $minSpan, int $W = 600, int $H = 240, int $fs = 15, bool $frameIfEmpty = false): string
{
    $L = (int)round($fs * 3.3); $R = 10; $T = 14; $B = $fs * 2;
    $min = INF; $max = -INF;
    foreach ($series as $s) foreach ($s[2] as $p) { $min = min($min, $p[1]); $max = max($max, $p[1]); }
    // 記録がないとき: $frameIfEmpty なら枠と日付だけのグラフ(大きさをそろえるため)、そうでなければ文で知らせる
    $empty = !is_finite($min);
    if ($empty && !$frameIfEmpty) return '<p class="muted">この期間の記録はありません。</p>';
    if ($empty) { $min = 0.0; $max = 1.0; }
    if ($max - $min < $minSpan) { $mid = ($max + $min) / 2; $min = $mid - $minSpan / 2; $max = $mid + $minSpan / 2; }
    $pad = ($max - $min) * 0.08; $min -= $pad; $max += $pad;
    $x = fn(int $e) => $L + ($e - $from) / max(1, $to - $from) * ($W - $L - $R);
    $y = fn(float $v) => $T + ($max - $v) / ($max - $min) * ($H - $T - $B);
    $o = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" xmlns="http://www.w3.org/2000/svg" font-size="' . $fs . '" font-family="system-ui,sans-serif">';
    $step = 50;
    foreach ([0.1, 0.2, 0.5, 1, 2, 5, 10, 20, 50] as $st) { $step = $st; if (($max - $min) / $st <= ($H < 100 ? 2 : ($H < 180 ? 3 : 6))) break; }
    for ($v = ceil($min / $step) * $step; !$empty && $v <= $max; $v += $step) {
        $yy = round($y($v), 1);
        $o .= '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '" stroke="#e5e7ea"/>'
            . '<text x="' . ($L - 6) . '" y="' . ($yy + 5) . '" text-anchor="end" fill="#666">' . h(rtrim(rtrim(number_format($v, 1), '0'), '.')) . '</text>';
    }
    // 縦線: 2日までは6時間ごと、40日までは毎日(8日を超えたら7日ごとに日付)、それより長いと月ごと
    $days = ($to - $from) / 86400;
    $marks = [];
    if ($days <= 2) {
        for ($e = strtotime(date('Y-m-d', $from) . ' 00:00:00'); $e <= $to; $e += 21600) if ($e >= $from) $marks[] = [$e, date('H:i', $e)];
    } elseif ($days <= 40) {
        $dstep = $days <= 8 ? 1 : 7; $i = 0;
        for ($e = strtotime(date('Y-m-d', $from) . ' 00:00:00') + 86400; $e <= $to; $e += 86400, $i++) $marks[] = [$e, $i % $dstep === 0 ? date('n/j', $e) : ''];
    } else {
        $mstep = $days <= 400 ? 1 : 3;
        for ($e = strtotime(date('Y-m-01', $from) . ' 00:00:00'); $e <= $to; $e = strtotime('+' . $mstep . ' month', $e)) {
            if ($e >= $from) $marks[] = [$e, date('n', $e) === '1' ? date('Y/n', $e) : date('n', $e) . '月'];
        }
    }
    foreach ($marks as [$e, $lb]) {
        $xx = round($x($e), 1);
        $o .= '<line x1="' . $xx . '" x2="' . $xx . '" y1="' . $T . '" y2="' . ($H - $B) . '" stroke="#f0f1f3"/>';
        if ($lb !== '') $o .= '<text x="' . $xx . '" y="' . ($H - 10) . '" text-anchor="middle" fill="#666">' . h($lb) . '</text>';
    }
    if ($empty) $o .= '<text x="' . (($L + $W - $R) / 2) . '" y="' . (($T + $H - $B) / 2 + 5) . '" text-anchor="middle" fill="#aaa">記録なし</text>';
    else $o .= '<text x="4" y="' . ($T + 4) . '" fill="#666">' . h($unit) . '</text>';
    foreach ($series as $s) {
        $pts = [];
        foreach (thin($s[2], $from, $to) as $p) $pts[] = round($x((int)$p[0]), 1) . ',' . round($y((float)$p[1]), 1);
        if (count($pts) === 1) {
            [$px, $py] = explode(',', $pts[0]);
            $o .= '<circle cx="' . $px . '" cy="' . $py . '" r="3" fill="' . $s[1] . '"/>';
        } elseif ($pts) {
            $o .= '<polyline fill="none" stroke="' . $s[1] . '" stroke-width="2" stroke-linejoin="round" points="' . implode(' ', $pts) . '"/>';
        }
    }
    $o .= '</svg>';
    if (count($series) > 1) {
        $o .= '<p class="muted">';
        foreach ($series as $s) $o .= '<span style="color:' . $s[1] . '">■</span> ' . h($s[0]) . '　';
        $o .= '</p>';
    }
    return $o;
}

// 巣箱ページのグラフ: 左の縦軸に $left、右の縦軸に $right(空なら右軸なし)。系列は [名前, 色, [[epoch, 値], ...], 破線(省略可)]
//   目盛りの数字は $tfs の大きさ。縦軸の単位は出さない(グラフの上の見出しに書く)。左右の目盛りは $lColor / $rColor で色分けする
//   $tip に ['l' => [項目名, 単位, 小数桁], 'r' => [...]] を渡すと、マウスやタッチで日時と値を出すためのデータを埋め込む(chart_tip_js と組で使う)
//   系列の5つめに true を入れると、記録した点に丸を付ける。$tip['day'] = true なら、日時の代わりに日付だけを出す
function chart_dual(array $left, array $right, int $from, int $to, float $lSpan, float $rSpan, int $W, int $H, int $tfs,
    string $lColor = '#666', string $rColor = '#666', array $tip = []): string
{
    $L = (int)round($tfs * 3.2); $R = $right ? (int)round($tfs * 3.2) : 10; $T = 8; $B = (int)round($tfs * 1.9);
    // 縦軸の範囲と目盛り
    $axis = function (array $series, float $span) use ($H) {
        $min = INF; $max = -INF;
        foreach ($series as $s) foreach ($s[2] as $p) { $min = min($min, $p[1]); $max = max($max, $p[1]); }
        if (!is_finite($min)) return null;
        if ($max - $min < $span) { $mid = ($max + $min) / 2; $min = $mid - $span / 2; $max = $mid + $span / 2; }
        $pad = ($max - $min) * 0.08; $min -= $pad; $max += $pad;
        $step = 50;
        foreach ([0.1, 0.2, 0.5, 1, 2, 5, 10, 20, 50] as $st) { $step = $st; if (($max - $min) / $st <= ($H < 180 ? 4 : 6)) break; }
        return [$min, $max, $step];
    };
    $la = $axis($left, $lSpan);
    $ra = $right ? $axis($right, $rSpan) : null;
    $x = fn(int $e) => $L + ($e - $from) / max(1, $to - $from) * ($W - $L - $R);
    $yOf = fn(array $a) => fn(float $v) => $T + ($a[1] - $v) / ($a[1] - $a[0]) * ($H - $T - $B);
    $o = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" xmlns="http://www.w3.org/2000/svg" font-size="' . $tfs . '" font-family="system-ui,sans-serif">';
    $fmt = fn(float $v) => h(rtrim(rtrim(number_format($v, 1), '0'), '.'));
    if ($la) {
        $y = $yOf($la);
        for ($v = ceil($la[0] / $la[2]) * $la[2]; $v <= $la[1]; $v += $la[2]) {
            $yy = round($y($v), 1);
            $o .= '<line x1="' . $L . '" x2="' . ($W - $R) . '" y1="' . $yy . '" y2="' . $yy . '" stroke="#e5e7ea"/>'
                . '<text x="' . ($L - 4) . '" y="' . ($yy + $tfs * 0.35) . '" text-anchor="end" fill="' . $lColor . '">' . $fmt($v) . '</text>';
        }
    }
    if ($ra) {
        $y = $yOf($ra);
        for ($v = ceil($ra[0] / $ra[2]) * $ra[2]; $v <= $ra[1]; $v += $ra[2]) {
            $o .= '<text x="' . ($W - $R + 4) . '" y="' . (round($y($v), 1) + $tfs * 0.35) . '" fill="' . $rColor . '">' . $fmt($v) . '</text>';
        }
    }
    // 縦線: 2日までは6時間ごと、40日までは毎日(8日を超えたら7日ごとに日付)、それより長いと月ごと
    $days = ($to - $from) / 86400;
    $marks = [];
    if ($days <= 2) {
        for ($e = strtotime(date('Y-m-d', $from) . ' 00:00:00'); $e <= $to; $e += 21600) if ($e >= $from) $marks[] = [$e, date('H:i', $e)];
    } elseif ($days <= 40) {
        $dstep = $days <= 8 ? 1 : 7; $i = 0;
        for ($e = strtotime(date('Y-m-d', $from) . ' 00:00:00') + 86400; $e <= $to; $e += 86400, $i++) $marks[] = [$e, $i % $dstep === 0 ? date('n/j', $e) : ''];
    } else {
        $mstep = $days <= 400 ? 1 : 3;
        for ($e = strtotime(date('Y-m-01', $from) . ' 00:00:00'); $e <= $to; $e = strtotime('+' . $mstep . ' month', $e)) {
            if ($e >= $from) $marks[] = [$e, date('n', $e) === '1' ? date('Y/n', $e) : date('n', $e) . '月'];
        }
    }
    foreach ($marks as [$e, $lb]) {
        $xx = round($x($e), 1);
        $o .= '<line x1="' . $xx . '" x2="' . $xx . '" y1="' . $T . '" y2="' . ($H - $B) . '" stroke="#f0f1f3"/>';
        if ($lb !== '') $o .= '<text x="' . $xx . '" y="' . ($H - $tfs * 0.5) . '" text-anchor="middle" fill="#666">' . h($lb) . '</text>';
    }
    if (!$la && !$ra) $o .= '<text x="' . (($L + $W - $R) / 2) . '" y="' . (($T + $H - $B) / 2 + 4) . '" text-anchor="middle" fill="#aaa" font-size="' . ($tfs * 1.4) . '">記録なし</text>';
    $tipS = [];
    foreach ([[$left, $la, 'l'], [$right, $ra, 'r']] as [$series, $a, $side]) {
        if (!$a) continue;
        $y = $yOf($a);
        $tc = $tip[$side] ?? null;
        foreach ($series as $s) {
            $pts = []; $tp = [];
            foreach (thin($s[2], $from, $to) as $p) {
                $pts[] = round($x((int)$p[0]), 1) . ',' . round($y((float)$p[1]), 1);
                if ($tc) $tp[] = [(int)$p[0], round((float)$p[1], $tc[2]), round($y((float)$p[1]), 1)];
            }
            if ($tc && $tp) $tipS[] = ['n' => $tc[0] . (count($series) > 1 ? ' ' . $s[0] : ''), 'u' => $tc[1], 'd' => $tc[2], 'c' => $s[1], 'p' => $tp];
            if (count($pts) === 1) {
                [$px, $py] = explode(',', $pts[0]);
                $o .= '<circle cx="' . $px . '" cy="' . $py . '" r="3" fill="' . $s[1] . '"/>';
            } elseif ($pts) {
                $o .= '<polyline fill="none" stroke="' . $s[1] . '" stroke-width="2" stroke-linejoin="round"'
                    . (($s[3] ?? '') !== '' ? ' stroke-dasharray="' . h((string)$s[3]) . '"' : '') . ' points="' . implode(' ', $pts) . '"/>';
                if (!empty($s[4])) foreach ($pts as $pt) {   // 記録がまばらなグラフ(ダニ数など)は、記録した点に丸を付ける
                    [$px, $py] = explode(',', $pt);
                    $o .= '<circle cx="' . $px . '" cy="' . $py . '" r="3" fill="' . $s[1] . '"/>';
                }
            }
        }
    }
    $o .= '</svg>';
    if (!$tip) return $o;
    $data = ['W' => $W, 'x0' => $L, 'x1' => $W - $R, 'y0' => $T, 'y1' => $H - $B, 'f' => $from, 't' => $to, 's' => $tipS];
    if (!empty($tip['day'])) $data['day'] = 1;
    return '<div class="tipw" data-tip="' . h(json_encode($data, JSON_UNESCAPED_UNICODE)) . '">' . $o . '</div>';
}

