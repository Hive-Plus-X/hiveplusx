<?php
// メモページ: 蜂場単位・巣箱単位(と、すべての蜂場あて)のメモ。内検記録もここに書く(内容「内検」では内検の項目も入れられる)。
// メモにはタグ(給餌や分割などの作業)をクリックで付けられる。タグの一覧は「カスタマイズ」で足したり消したりできる(一覧は人ごと)
// チームに共有された蜂場のメモは、蜂場の持ち主のフォルダに保存する(その蜂場を見られる人なら誰でも書ける)。
// 対象「すべて」のメモは自分のフォルダに置き、自分にだけ見える
declare(strict_types=1);
require __DIR__ . '/hpx_site.php';

const M_PAGE = 50;   // 1ページに出すメモの数
// メモの写真: 1つのメモに5枚まで。持ち主の site/memo_photos/ に、長い辺 1280px の JPEG と一覧用の小さい版(_t、320px)で置く。
//   スマホの写真はブラウザで縮めてから送る(memoPhotos)。縮められなかったときもサーバーで縮めるので、大きいまま保存はしない
const M_PHOTO_MAX = 5;
const M_PHOTO_PX = 1280;
const M_PHOTO_THUMB_PX = 320;
const M_PHOTO_BYTES = 20 * 1024 * 1024;   // 送られてくる1枚の上限(縮める前)
function memo_photo_dir(string $owner): string { return site_dir_of($owner) . '/memo_photos'; }
function memo_photo_del(string $owner, string $name): void
{
    if (!preg_match('/^[0-9a-f]+_[0-9a-f]+$/', $name)) return;
    @unlink(memo_photo_dir($owner) . '/' . $name . '.jpg');
    @unlink(memo_photo_dir($owner) . '/' . $name . '_t.jpg');
}
// 内検の項目 (M_INSPECT)・ダニ調査 (M_MITE_METHODS, mite_text) は hpx_site.php にある(データ一括ダウンロードでも使う)

// タグの初期の一覧。カスタマイズしたら site/memo_tags.json に {"tags": [...]} で保存する
//   共通の出来事 (S_MEMO_EVENTS、hpx_site.php) はいつも先頭に出て、消せない
const M_TAGS_DEFAULT = ['分蜂', '入居', '大量死', 'スズメバチ', 'クマ'];
function memo_tags(): array { return tag_list('memo_tags', M_TAGS_DEFAULT, S_MEMO_EVENTS); }

$site = site_load();

// 写真を返す(そのメモを見られる人だけ)。?photo=<メモid>&n=<0か1>、&t=1 で一覧用の小さい版
if (isset($_GET['photo'])) {
    $m = record_find(memos_load(), (string)$_GET['photo']);
    $name = (string)(($m['photos'] ?? [])[(int)($_GET['n'] ?? 0)] ?? '');
    $path = $m && preg_match('/^[0-9a-f]+_[0-9a-f]+$/', $name) ? memo_photo_dir($m['_o']) . '/' . $name . (!empty($_GET['t']) ? '_t' : '') . '.jpg' : '';
    if ($path === '' || !is_file($path)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=2592000');   // 写真ごとに名前が変わるので長くとっておいてよい
    readfile($path);
    exit;
}

$fa = (string)($_GET['a'] ?? '');
$fh = (string)($_GET['h'] ?? '');
$ft = (string)($_GET['t'] ?? '');
$fq = trim((string)($_GET['q'] ?? ''));
$fb = (string)($_GET['b'] ?? '');   // 記入者(アカウントid)
if (!isset($site['apiaries'][$fa])) { $fa = ''; $fh = ''; }   // 絞り込みの「すべて」は空
if ($fh !== '' && (($site['hives'][$fh]['apiary'] ?? '') !== $fa)) $fh = '';
if (!in_array($ft, S_MEMO_TYPES, true)) $ft = '';
if (!is_uid($fb)) $fb = '';
// ヘギイタダニのグラフ(寄生率 %): 期間 (mp、日数。0 = 全期間)。初期は 1年
const M_MITE_PERIODS = [90 => '3か月', 365 => '1年', 0 => '全期間'];
$mp = (string)($_GET['mp'] ?? '');
if (!isset(M_MITE_PERIODS[(int)$mp]) || $mp === '365') $mp = '';
$filter = array_filter(['a' => $fa, 'h' => $fh, 't' => $ft, 'b' => $fb, 'q' => $fq, 'mp' => $mp], fn($v) => $v !== '');
$selfUrl = fn(array $ch = [], string $anchor = '') => 'memo.php' . (($q = http_build_query(array_filter(array_merge($filter, $ch), fn($v) => $v !== '' && $v !== null))) !== '' ? '?' . $q : '') . ($anchor !== '' ? '#' . $anchor : '');

if (is_post()) {
    $act = (string)($_POST['act'] ?? '');
    $back = safe_back((string)($_POST['back'] ?? ''), 'memo.php');
    tag_post($act, 'memo_tags', M_TAGS_DEFAULT, S_MEMO_EVENTS);   // タグの一覧の変更(JSON を返して終わる)
    if ($act === 'memo_save') {
        $id = (string)($_POST['id'] ?? '');
        // 対象: 蜂場で「すべて」(*)を選んだら、どの蜂場にも付けないメモ(apiary が空)
        $ta = (string)($_POST['t_a'] ?? '');
        $tg = $ta === '*' ? ['', ''] : parse_target($site, $ta . '|' . (string)($_POST['t_h'] ?? ''));
        $text = str_in('text', 10000);
        $tags = tags_in();
        $type = (string)($_POST['type'] ?? '');
        if ($type === '－') $type = '';   // 「－」= 内容を選ばない (空で保存)
        elseif (!in_array($type, array_merge(S_MEMO_TYPES, S_MEMO_TYPES_OLD), true)) $type = 'その他';
        $insp = [];
        if ($type === '内検') {
            foreach (M_INSPECT as $k => [, $opts, $multi]) {
                if ($multi) {   // 複数選べる項目は、選んだものを選択肢の順の配列で持つ
                    $sel = array_map('strval', (array)($_POST['i_' . $k] ?? []));
                    $v = array_values(array_filter($opts, fn($op) => in_array($op, $sel, true)));
                    if ($v) $insp[$k] = $v;
                    continue;
                }
                $v = trim((string)($_POST['i_' . $k] ?? ''));
                if ($opts ? in_array($v, $opts, true) : (is_numeric($v) && (float)$v >= 0 && (float)$v < 100)) $insp[$k] = $v;
            }
        }
        $mite = [];
        if ($type === '内検') {
            $mm = (string)($_POST['mite_method'] ?? '');
            $mb = trim((string)($_POST['mite_bees'] ?? '')); $mc = trim((string)($_POST['mite_count'] ?? ''));
            if (in_array($mm, M_MITE_METHODS, true)) $mite['method'] = $mm;
            if (ctype_digit($mb) && (int)$mb <= 100000) $mite['bees'] = (int)$mb;
            if (ctype_digit($mc) && (int)$mc <= 100000) $mite['mites'] = (int)$mc;
        }
        $hasPhoto = !empty(array_filter((array)($_FILES['photos']['error'] ?? []), fn($e) => (int)$e !== UPLOAD_ERR_NO_FILE)) || ($id !== '' && !empty(record_find(memos_load(), $id)['photos'] ?? []));
        if (!$tg || ($text === '' && !$insp && !$mite && !$tags && !$hasPhoto)) redirect($back, '対象とメモの中身を入れてください。');
        // 書き先は蜂場の持ち主(「すべて」は自分)。前からあるメモは、同じ持ち主の蜂場の間でだけ対象を変えられる
        $ex = $id !== '' ? record_find(memos_load(), $id) : null;
        if ($id !== '' && !$ex) redirect($back);
        $owner = $tg[0] === '' ? $UID : apiary_owner($site, $tg[0]);
        if ($ex && $ex['_o'] !== $owner) redirect($back, 'このメモは、ほかの人の蜂場へは移せません。新しいメモとして書いてください。');
        site_use($owner);
        $newId = $id !== '' ? $id : new_id();
        $date = date_in('date', date('Y-m-d'));
        $time = time_in('time');   // 出来事の時刻 (分かれば。HH:MM か空)
        // 写真: 「外す」にチェックしたものを消し、送られたものを足す(合わせて M_PHOTO_MAX 枚まで)
        $photos = array_values((array)($ex['photos'] ?? []));
        $del = array_map('strval', (array)($_POST['photo_del'] ?? []));
        foreach ($photos as $p) if (in_array($p, $del, true)) memo_photo_del($owner, $p);
        $photos = array_values(array_diff($photos, $del));
        $photoErr = '';
        $f = $_FILES['photos'] ?? null;
        if (is_array($f) && is_array($f['name'] ?? null)) {
            foreach (array_keys($f['name']) as $i) {
                $err = (int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE);
                if ($err === UPLOAD_ERR_NO_FILE) continue;
                if (count($photos) >= M_PHOTO_MAX) { $photoErr = '写真は1つのメモに' . M_PHOTO_MAX . '枚までです。'; break; }
                $info = $err === UPLOAD_ERR_OK && (int)$f['size'][$i] <= M_PHOTO_BYTES ? @getimagesize($f['tmp_name'][$i]) : false;
                if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
                    $photoErr = '保存できなかった写真があります (JPEG・PNG・WebP で 20MB まで。HEIC はこの端末では変換できませんでした)。';
                    continue;
                }
                $name = $newId . '_' . bin2hex(random_bytes(3));
                $dir = memo_photo_dir($owner);
                @mkdir($dir, 0755, true);
                if (img_resize_jpeg($f['tmp_name'][$i], $dir . '/' . $name . '.jpg', M_PHOTO_PX, 82)) {
                    img_resize_jpeg($dir . '/' . $name . '.jpg', $dir . '/' . $name . '_t.jpg', M_PHOTO_THUMB_PX, 78);
                    $photos[] = $name;
                } else {
                    $photoErr = '写真を保存できませんでした。';
                }
            }
        }
        // 内検のメモは「作業記録にも追加する」なら、作業の種類「内検」の作業記録を作る(メモの work にその id を持つ)。
        // チェックを外した・内検でなくなった・時間が無いときは、つないでいた作業記録を消す
        $oldWork = '';
        foreach (site_read('memos') as $x) if (($x['id'] ?? '') === $newId) $oldWork = (string)($x['work'] ?? '');
        $start = time_in('w_start'); $end = time_in('w_end');
        $min = work_minutes($start, $end, (int)($_POST['w_minutes'] ?? 0));
        $wantWork = $type === '内検' && !empty($_POST['to_work']) && $tg[0] !== '';
        $workId = '';
        $flash = 'メモを保存しました。';
        if ($wantWork && $min > 0 && $min <= 1440) {
            $workId = $oldWork !== '' ? $oldWork : new_id();
            $note = mb_strimwidth(trim(implode('・', memo_chips(['inspect' => $insp, 'tags' => $tags])) . ' ' . $text), 0, 200, '…');
            site_update('works', function (array $w) use ($workId, $newId, $tg, $date, $start, $end, $min, $note) {
                $w['logs'] = is_array($w['logs'] ?? null) ? $w['logs'] : [];
                $row = ['id' => $workId, 'apiary' => $tg[0], 'hive' => $tg[1], 'date' => $date, 'type' => '内検',
                    'start' => $start, 'end' => $end, 'minutes' => $min, 'people' => max(1, min(99, (int)($_POST['w_people'] ?? 1))),
                    'note' => $note, 'memo' => $newId, 'updated' => time(), 'updated_by' => $GLOBALS['UID']];
                foreach ($w['logs'] as &$x) if (($x['id'] ?? '') === $workId) { $x = array_merge($x, $row); return $w; }
                unset($x);
                $w['logs'][] = $row + ['created' => time(), 'by' => $GLOBALS['UID']];
                return $w;
            });
            $flash = 'メモを保存し、作業記録にも追加しました。';
        } else {
            if ($oldWork !== '') work_log_delete($oldWork);
            if ($wantWork) $flash = 'メモを保存しました。かかった時間が無いので、作業記録には追加していません。';
        }
        if ($photoErr !== '') $flash .= ' ' . $photoErr;
        site_update('memos', function (array $m) use ($newId, $tg, $text, $type, $insp, $mite, $tags, $date, $time, $workId, $photos) {
            $row = ['id' => $newId, 'apiary' => $tg[0], 'hive' => $tg[1], 'date' => $date, 'time' => $time,
                'type' => $type, 'tags' => $tags, 'text' => $text, 'inspect' => $insp, 'mite' => $mite, 'photos' => $photos, 'work' => $workId, 'updated' => time(), 'updated_by' => $GLOBALS['UID']];
            foreach ($m as &$x) if (($x['id'] ?? '') === $newId) { $x = array_merge($x, $row); return $m; }
            unset($x);
            $row['created'] = time();
            $row['by'] = $GLOBALS['UID'];   // 記入者(アカウントID)
            $m[] = $row;
            return $m;
        });
        redirect($back, $flash);
    }
    if ($act === 'memo_delete') {
        $id = (string)($_POST['id'] ?? '');
        $ex = record_find(memos_load(), $id);
        if (!$ex) redirect($back);
        site_use($ex['_o']);
        foreach (site_read('memos') as $x) if (($x['id'] ?? '') === $id && ($x['work'] ?? '') !== '') work_log_delete((string)$x['work']);   // つないだ作業記録も消す
        foreach ((array)($ex['photos'] ?? []) as $p) memo_photo_del($ex['_o'], (string)$p);
        site_update('memos', fn(array $m) => array_values(array_filter($m, fn($x) => ($x['id'] ?? '') !== $id)));
        redirect($back, 'メモを削除しました。');
    }
    redirect($back);
}

$memos = memos_load();
usort($memos, fn($x, $y) => [$y['date'] ?? '', $y['created'] ?? 0] <=> [$x['date'] ?? '', $x['created'] ?? 0]);

function memo_form(array $m, array $site, string $back): string
{
    [$a, $hv] = [(string)($m['apiary'] ?? ''), (string)($m['hive'] ?? '')];
    $type = (string)($m['type'] ?? '');   // 空 = 「－」(内容を選んでいない)
    $fid = 'f' . substr(md5((string)($m['id'] ?? 'new')), 0, 6);
    // 対象は 蜂場(すべて・各蜂場) と 巣箱(空 = 蜂場全体)。巣箱は選んだ蜂場のものだけ出す(memoHives)
    $all = $a === '';   // 新しいメモも、蜂場を指定しないで開いたら「すべて」
    $sa = '<select name="t_a" required onchange="memoHives(this)"><option value="*"' . ($all ? ' selected' : '') . '>すべて</option>';
    $sh = '<select name="t_h" onchange="memoWork(this.form)"' . ($all ? ' disabled' : '') . '><option value=""></option>';
    foreach ($site['apiaries'] as $aid => $ap) {
        $sa .= '<option value="' . h($aid) . '"' . (!$all && $a === $aid ? ' selected' : '') . '>' . h((string)$ap['name']) . '</option>';
        foreach (hives_of($site, $aid) as $hid => $x) {
            $sh .= '<option value="' . h($hid) . '" data-a="' . h($aid) . '"' . ($hv === $hid ? ' selected' : '') . ($aid !== $a ? ' hidden' : '') . '>' . h((string)$x['name']) . '</option>';
        }
    }
    $types = array_merge(['－'], S_MEMO_TYPES);
    if ($type === '') $type = '－';
    if (!in_array($type, $types, true)) $types[] = $type;   // 前の版の内容のメモ
    $o = '<form method="post" class="form" id="' . $fid . '" enctype="multipart/form-data">' . csrf_field() . '<input type="hidden" name="act" value="memo_save">'
        . '<input type="hidden" name="id" value="' . h((string)($m['id'] ?? '')) . '"><input type="hidden" name="back" value="' . h($back) . '">'
        . '<div class="grid2"><p><label>対象 (蜂場・巣箱)</label><span class="tsel">' . $sa . '</select>' . $sh . '</select></span></p>'
        . '<p><label>日付・時刻 (時刻は分かれば)</label><span style="display:flex;gap:6px"><input type="date" name="date" value="' . h((string)($m['date'] ?? date('Y-m-d'))) . '">'
        . '<input type="time" name="time" value="' . h((string)($m['time'] ?? '')) . '" aria-label="時刻" title="スズメバチや分蜂などを見た時刻。AI の学習に役立ちます"></span></p></div>'
        . '<p><label>内容</label><select name="type" onchange="document.getElementById(\'' . $fid . 'i\').style.display=this.value===\'内検\'?\'\':\'none\'">';
    foreach ($types as $t) $o .= '<option' . ($t === $type ? ' selected' : '') . '>' . h($t) . '</option>';
    $o .= '</select></p><div id="' . $fid . 'i" class="grid2 insp"' . ($type === '内検' ? '' : ' style="display:none"') . '>';
    $iv = (array)($m['inspect'] ?? []);
    foreach (M_INSPECT as $k => $it) {
        [$lb, $opts, $multi] = $it;
        if ($multi) {   // 複数選べる項目はタグと同じ見た目で、クリックで選ぶ。横幅いっぱいに出す
            $cur = (array)($iv[$k] ?? []);
            $o .= '<p class="wide"><label>' . h($lb) . ' <span class="muted" style="font-size:.85em">(複数選べます)' . (isset($it[3]) ? '　' . h($it[3]) : '') . '</span></label><span class="ichk">';
            foreach ($opts as $op) $o .= '<label class="tagc"><input type="checkbox" name="i_' . $k . '[]" value="' . h($op) . '"' . (in_array($op, $cur, true) ? ' checked' : '') . '><span>' . h($op) . '</span></label>';
            $o .= '</span></p>';
            continue;
        }
        $o .= '<p><label>' . h($lb) . '</label>';
        if ($opts) {
            $o .= '<select name="i_' . $k . '"><option value="">-</option>';
            foreach ($opts as $op) $o .= '<option' . (($iv[$k] ?? '') === $op ? ' selected' : '') . '>' . h($op) . '</option>';
            $o .= '</select>';
        } else {
            $o .= '<input type="number" step="0.5" min="0" name="i_' . $k . '" value="' . h((string)($iv[$k] ?? '')) . '">';
        }
        $o .= '</p>';
    }
    // ダニ調査。寄生率は入れたそばから計算して見せる(miteRate)
    $mt = (array)($m['mite'] ?? []);
    $o .= '<div class="wide mite"><label>ダニ調査</label><div class="mrow"><select name="mite_method" onchange="miteRate(this.form)"><option value="">-</option>';
    foreach (M_MITE_METHODS as $op) $o .= '<option' . (($mt['method'] ?? '') === $op ? ' selected' : '') . '>' . h($op) . '</option>';
    $o .= '</select><span>検査ミツバチ数 <input type="number" name="mite_bees" min="0" step="1" style="width:6em" value="' . h(isset($mt['bees']) ? (string)$mt['bees'] : '') . '" oninput="miteRate(this.form)"> 匹</span>'
        . '<span>ダニ数 <input type="number" name="mite_count" min="0" step="1" style="width:5em" value="' . h(isset($mt['mites']) ? (string)$mt['mites'] : '') . '" oninput="miteRate(this.form)"> 匹</span>'
        . '<b class="mrate"></b></div></div>';
    // 作業記録にも追加する(蜂場単位の内検は最初からオン、巣箱単位はオフ。新しいメモでは、蜂場・巣箱を選び直すと合わせて変わる)
    $wl = null;
    if (($m['work'] ?? '') !== '') foreach (works_load()['logs'] as $x) if (($x['id'] ?? '') === $m['work']) $wl = $x;
    $isNew = !isset($m['id']);
    $on = $isNew ? ($hv === '' && $a !== '') : $wl !== null;
    $o .= '<div class="wide towork"><label style="display:inline"><input type="checkbox" name="to_work" value="1"' . ($on ? ' checked' : '') . ($isNew ? ' data-auto="1"' : '')
        . ($all ? ' disabled' : '') . ' onchange="this.removeAttribute(\'data-auto\');memoWork(this.form)"> 作業記録にも追加する</label>'
        . ' <span class="muted" style="font-size:.85em">(作業の種類「内検」で記録します。蜂場が「すべて」のときは追加できません)</span>'
        . '<div class="wbox"' . ($on ? '' : ' style="display:none"') . '><label>かかった時間 (開始と終了を入れるか、分を入れる)</label>'
        . '<input type="time" name="w_start" value="' . h((string)($wl['start'] ?? '')) . '"> 〜 <input type="time" name="w_end" value="' . h((string)($wl['end'] ?? '')) . '">'
        . '　または <input type="number" name="w_minutes" min="1" max="1440" style="width:5em" value="' . h($wl && ($wl['start'] ?? '') === '' ? (string)$wl['minutes'] : '') . '"> 分'
        . '　人数 <input type="number" name="w_people" min="1" max="99" style="width:4em" value="' . (int)($wl['people'] ?? 1) . '"> 人</div></div>';
    return $o . '</div>' . tag_field(memo_tags(), (array)($m['tags'] ?? []))
        . '<p><label>メモ</label><textarea name="text" rows="5" data-voice>' . h((string)($m['text'] ?? '')) . '</textarea></p>'
        . memo_photo_field($m)
        . '<p><button type="submit">保存</button></p></form>';
}

// 写真の欄: 付いている写真(「外す」で消す)と、足す写真を選ぶボタン(合わせて M_PHOTO_MAX 枚まで)
function memo_photo_field(array $m): string
{
    $cur = array_values((array)($m['photos'] ?? []));
    $o = '<div class="mphoto" data-max="' . M_PHOTO_MAX . '"><label>写真 (' . M_PHOTO_MAX . '枚まで)</label><div class="mph">';
    foreach ($cur as $i => $p) {
        $o .= '<div class="mpcur"><img src="memo.php?' . h(http_build_query(['photo' => (string)$m['id'], 'n' => $i, 't' => 1])) . '" alt="">'
            . '<label><input type="checkbox" name="photo_del[]" value="' . h((string)$p) . '" onchange="memoPhotoCount(this.form)"> 外す</label></div>';
    }
    return $o . '<span class="mpnew"></span></div><input type="file" name="photos[]" accept="image/*" multiple onchange="memoPhotos(this)"' . (count($cur) >= M_PHOTO_MAX ? ' disabled' : '') . '>'
        . '<div class="mperr ng"></div></div>';
}

// ヘギイタダニのグラフ。巣箱ごと(蜂場全体あてのメモは蜂場ごと)に色を分けた折れ線で、記録した日に丸を付ける。
//   値は 寄生率 (%) = ダニ数 ÷ 検査ミツバチ数 × 100(検査ミツバチ数とダニ数の両方が入っている記録だけ)
function memo_mite_chart(array $site, array $memos, string $fa, string $fh, int $days, callable $selfUrl): string
{
    $now = time();
    $since = $days > 0 ? $now - $days * 86400 : 0;
    $groups = [];
    foreach ($memos as $m) {
        $mt = (array)($m['mite'] ?? []);
        if (!isset($mt['mites']) || ($m['apiary'] ?? '') === '') continue;
        if ($fa !== '' && $m['apiary'] !== $fa) continue;
        if ($fh !== '' && ($m['hive'] ?? '') !== $fh) continue;
        if ((int)($mt['bees'] ?? 0) <= 0) continue;
        $e = strtotime((string)$m['date'] . ' 12:00:00');
        if ($e === false || $e < $since) continue;
        $key = (string)$m['apiary'] . '|' . (string)($m['hive'] ?? '');
        $groups[$key][] = [$e, $mt['mites'] / $mt['bees'] * 100];
    }
    $sel = function (array $ch) use ($selfUrl) { return h($selfUrl(array_merge(['pg' => null], $ch), 'mite-chart')); };
    $o = '<details id="mite-chart" open><summary><b>ヘギイタダニのグラフ</b></summary><div class="card" style="margin-top:4px">'
        . '<div class="per" style="margin:0 0 4px">';
    foreach (M_MITE_PERIODS as $d => $lb) $o .= '<a href="' . $sel(['mp' => $d === 365 ? '' : (string)$d]) . '"' . ($d === $days ? ' class="on"' : '') . '>' . $lb . '</a>';
    $o .= '</div>';
    if (!$groups) {
        return $o . '<p class="muted">検査ミツバチ数とダニ数を入れたダニ調査の記録が、この期間にはありません。'
            . '内容「内検」のメモの「ダニ調査」に両方入れるとグラフになります。</p></div></details>';
    }
    // 名前: 巣箱で絞り込んだときは巣箱名だけ、蜂場で絞り込んだときは巣箱名、すべてのときは「蜂場 / 巣箱」
    $series = []; $leg = ''; $i = 0; $first = $now;
    ksort($groups);
    foreach ($groups as $key => $pts) {
        [$a, $hv] = explode('|', $key, 2);
        $name = $fa === '' ? target_label($site, $a, $hv) : ($hv === '' ? '蜂場全体' : (string)($site['hives'][$hv]['name'] ?? '?'));
        usort($pts, fn($x, $y) => $x[0] <=> $y[0]);
        $first = min($first, $pts[0][0]);
        $c = S_COLORS[$i++ % count(S_COLORS)];
        $series[] = [$name, $c, $pts, '', true];
        $leg .= '<span><i style="background:' . $c . '"></i>' . h($name) . '</span>';
    }
    // 全期間のときは最初の記録の少し前から。記録が今日だけでも横幅が出るよう、最低でも30日分にする
    $from = $days > 0 ? $since : min($first - 86400 * 3, $now - 30 * 86400);
    $tip = ['l' => ['寄生率', '%', 1], 'day' => true];
    $span = 2.0;
    return $o . '<p class="muted" style="margin:0;font-size:.85em">寄生率 (%) = ダニ数 ÷ 検査ミツバチ数 × 100</p>'
        . '<div class="gw">' . chart_dual($series, [], $from, $now, $span, 0, 600, 200, 9, '#666', '#666', $tip) . '</div>'
        . '<div class="gn">' . chart_dual($series, [], $from, $now, $span, 0, 340, 150, 10, '#666', '#666', $tip) . '</div>'
        . (count($series) > 1 ? '<div class="wleg">' . $leg . '</div>' : '')
        . '</div></details>';
}

page_head('メモ - ' . S_TITLE, 'memo.php');
echo '<p class="muted" style="font-size:.85em;margin:0 0 .5em">内検の記録、蜂の異常、外敵、開花植物などについて記載</p>' . flash_html();

// ---- 新しいメモ ----
$nt = (string)($_GET['new'] ?? '');
// 巣箱に貼った QR コードから来たとき (memo.php?qr=巣箱id): その蜂場・巣箱で、内容「内検」のメモを書く欄を開く
$qr = (string)($_GET['qr'] ?? '');
$fromQr = isset($site['hives'][$qr]);
if ($fromQr) $nt = $site['hives'][$qr]['apiary'] . '|' . $qr;
$pre = parse_target($site, $nt) ?? ['', ''];   // 対象の初期は「すべて」(蜂場・巣箱のページから来たときはその対象)
echo '<details id="memo-form"' . ($nt !== '' ? ' open' : '') . '><summary>＋ メモを書く</summary><div class="card">'
    . memo_form(['apiary' => $pre[0], 'hive' => $pre[1], 'type' => $fromQr ? '内検' : ''], $site, $selfUrl()) . '</div></details>';
if ($fromQr) echo '<script>document.getElementById("memo-form").scrollIntoView();</script>';

// ---- メモ検索(蜂場・巣箱はプルダウン。選んだらすぐ絞り込む) ----
echo '<p style="margin:.8em 0 .2em"><b>メモ検索</b></p><form method="get" class="msearch">'
    . '<select name="a" onchange="this.form.h.value=\'\';this.form.submit()"><option value="">蜂場: すべて</option>';
foreach ($site['apiaries'] as $x => $a) echo '<option value="' . h($x) . '"' . ($fa === $x ? ' selected' : '') . '>' . h((string)$a['name']) . '</option>';
echo '</select><select name="h" onchange="this.form.submit()"' . ($fa === '' ? ' disabled' : '') . '><option value="">巣箱: ' . ($fa === '' ? '-' : '蜂場のすべて') . '</option>';
if ($fa !== '') foreach (hives_of($site, $fa) as $x => $hv) echo '<option value="' . h($x) . '"' . ($fh === $x ? ' selected' : '') . '>' . h((string)$hv['name']) . '</option>';
echo '</select><select name="t" onchange="this.form.submit()"><option value="">内容: すべて</option>';
foreach (S_MEMO_TYPES as $t) echo '<option' . ($t === $ft ? ' selected' : '') . '>' . h($t) . '</option>';
// 記入者: 見られるメモを書いた人
$writers = [];
foreach ($memos as $m) if (($m['by'] ?? '') !== '') $writers[(string)$m['by']] = user_name((string)$m['by']);
asort($writers);
echo '</select><select name="b" onchange="this.form.submit()"><option value="">記入者: すべて</option>';
foreach ($writers as $u => $nm) echo '<option value="' . h($u) . '"' . ($fb === $u ? ' selected' : '') . '>' . h($nm) . '</option>';
echo '</select><input type="search" name="q" placeholder="キーワード" value="' . h($fq) . '"><button type="submit">絞り込む</button>';
if ($mp !== '') echo '<input type="hidden" name="mp" value="' . h($mp) . '">';
echo '</form>';

// ---- ヘギイタダニのグラフ(内検のダニ調査の記録から。絞り込みの蜂場・巣箱に合わせる) ----
echo memo_mite_chart($site, $memos, $fa, $fh, $mp === '' ? 365 : (int)$mp, $selfUrl);

// ---- 一覧 ----
$list = array_values(array_filter($memos, function ($m) use ($fa, $fh, $ft, $fb, $fq) {
    // 対象「すべて」のメモは、どの蜂場で絞り込んでも出す(巣箱で絞り込んだときは出さない)
    if ($fa !== '' && ($m['apiary'] ?? '') !== $fa && ($m['apiary'] ?? '') !== '') return false;
    if ($fh !== '' && ($m['hive'] ?? '') !== $fh) return false;
    if ($ft !== '' && ($m['type'] ?? '') !== $ft) return false;
    if ($fb !== '' && ($m['by'] ?? '') !== $fb) return false;
    if ($fq !== '' && mb_stripos((string)($m['text'] ?? '') . ' ' . implode(' ', memo_chips($m)), $fq) === false) return false;
    return true;
}));
$pages = max(1, (int)ceil(count($list) / M_PAGE));
$pg = min($pages, max(1, (int)($_GET['pg'] ?? 1)));
echo '<p class="muted">' . count($list) . ' 件' . ($pages > 1 ? ' (' . $pg . ' / ' . $pages . ' ページ)' : '') . '</p>';
if (!$list) echo '<p class="muted">メモはありません。</p>';
foreach (array_slice($list, ($pg - 1) * M_PAGE, M_PAGE) as $m) {
    $iv = (array)($m['inspect'] ?? []);
    echo '<div class="card ' . (($m['hive'] ?? '') !== '' ? 'hive' : 'apiary') . '">'
        . '<b>' . h(date('Y-m-d', strtotime((string)$m['date']))) . (($m['time'] ?? '') !== '' ? ' ' . h((string)$m['time']) : '') . '</b> ' . h((string)($m['type'] ?? ''))
        . ' <span class="muted">' . h(target_label($site, (string)$m['apiary'], (string)($m['hive'] ?? ''))) . '</span>' . by_html($m);
    if ($chips = memo_chips($m)) echo '<div class="tagl">' . implode('', array_map(fn($t) => '<span>' . h($t) . '</span>', $chips)) . '</div>';
    if ($iv) {
        $parts = [];
        foreach (M_INSPECT as $k => [$lb]) if (isset($iv[$k]) && !is_array($iv[$k])) $parts[] = $lb . ': ' . $iv[$k];   // 複数選ぶ項目は上のタグに出す
        if ($parts) echo '<p class="muted" style="margin:.3em 0">' . h(implode('　', $parts)) . '</p>';
    }
    if (!empty($m['mite'])) echo '<p class="muted" style="margin:.3em 0">ダニ調査: ' . h(mite_text((array)$m['mite'])) . '</p>';
    if (($m['text'] ?? '') !== '') echo '<p style="margin:.4em 0">' . nl((string)$m['text']) . '</p>';
    if (!empty($m['photos'])) {   // 小さい版を並べ、押すと大きい写真をポップアップで開く(lbOpen。JavaScript が無ければ新しいタブで開く)
        echo '<div class="mph lbg">';
        foreach (array_values((array)$m['photos']) as $i => $p) {
            $q = ['photo' => (string)$m['id'], 'n' => $i];
            echo '<a href="memo.php?' . h(http_build_query($q)) . '" target="_blank" onclick="return lbOpen(this)"><img src="memo.php?' . h(http_build_query($q + ['t' => 1])) . '" alt="写真' . ($i + 1) . '" loading="lazy"></a>';
        }
        echo '</div>';
    }
    echo '<details><summary class="muted">変更・削除</summary>' . memo_form($m, $site, $selfUrl(['pg' => $pg]))
        . '<form method="post" onsubmit="return confirm(\'このメモを削除しますか?\');">' . csrf_field()
        . '<input type="hidden" name="act" value="memo_delete"><input type="hidden" name="id" value="' . h((string)$m['id']) . '">'
        . '<input type="hidden" name="back" value="' . h($selfUrl(['pg' => $pg])) . '"><button type="submit">このメモを削除</button></form></details></div>';
}
if ($pages > 1) {
    echo '<div class="per">';
    for ($i = 1; $i <= $pages; $i++) echo '<a href="' . h($selfUrl(['pg' => $i])) . '"' . ($i === $pg ? ' class="on"' : '') . '>' . $i . '</a>';
    echo '</div>';
}
// 対象の巣箱は選んだ蜂場のものだけ。タグの「カスタマイズ」は、その場で一覧を直して、ページ内のすべてのフォームのタグを並べ直す
echo tag_js('memo.php', memo_tags()) . chart_tip_js() . voice_js();
echo <<<'JS'
<script>
function memoHives(sa){   // iPhone では option の hidden が効かないので、選べる巣箱だけを入れ直す
  var sh = sa.form.elements['t_h'], cur = sh.value;
  if (!sh._all) sh._all = Array.prototype.slice.call(sh.options).filter(function(o){ return o.value !== ''; });
  while (sh.options.length > 1) sh.remove(1);
  sh._all.forEach(function(o){ if (o.getAttribute('data-a') === sa.value){ o.hidden = false; sh.appendChild(o); } });
  sh.value = cur; if (sh.value !== cur) sh.value = '';
  sh.disabled = sa.value === '*'; if (sh.disabled) sh.value = '';
  memoWork(sa.form);
}
function miteRate(f){   // ダニの寄生率 = ダニ数 ÷ 検査ミツバチ数 × 100
  var b = parseInt(f.elements['mite_bees'].value, 10), c = parseInt(f.elements['mite_count'].value, 10);
  f.querySelector('.mrate').textContent = b > 0 && c >= 0 ? '寄生率 ' + (c / b * 100).toFixed(1) + '%' : '';
}
document.querySelectorAll('form .mite').forEach(function(x){ miteRate(x.closest('form')); });
function memoWork(f){   // 「作業記録にも追加する」: 触っていなければ 蜂場単位=オン・巣箱単位=オフ。オンのときだけ時間の欄を出す
  var c = f.elements['to_work']; if (!c) return;
  var all = f.elements['t_a'].value === '*';
  c.disabled = all;
  if (c.getAttribute('data-auto')) c.checked = !all && f.elements['t_h'].value === '';
  f.querySelector('.wbox').style.display = c.checked && !all ? '' : 'none';
}
document.querySelectorAll('select[name=t_a]').forEach(memoHives);
// 写真: 選んだ写真をブラウザで長い辺 1280px の JPEG に縮めてから送る(iPhone の HEIC もここで JPEG になる)。
// 縮められない写真(この端末で開けない HEIC など)はそのまま送り、サーバー側で確かめる
function memoPhotoCount(f){
  var box = f.querySelector('.mphoto'), max = +box.getAttribute('data-max');
  var keep = box.querySelectorAll('input[name="photo_del[]"]:not(:checked)').length;
  box.querySelector('input[type=file]').disabled = max - keep <= 0;   // 上限まで付いているときは、外すまで選べない
  return max - keep;
}
function memoPhotos(inp){
  var f = inp.form, box = f.querySelector('.mphoto'), err = box.querySelector('.mperr'), prev = box.querySelector('.mpnew');
  var room = memoPhotoCount(f), files = Array.prototype.slice.call(inp.files);
  err.textContent = ''; prev.innerHTML = '';
  if (files.length > room){ err.textContent = 'あと ' + room + ' 枚まで選べます。最初の ' + room + ' 枚だけ送ります。'; files = files.slice(0, room); }
  if (!files.length){ inp.value = ''; return; }
  var btn = f.querySelector('button[type=submit]'); btn.disabled = true;
  Promise.all(files.map(function(file){
    if (!window.createImageBitmap) return Promise.resolve(file);
    return createImageBitmap(file, {imageOrientation: 'from-image'}).then(function(b){
      var r = Math.min(1, 1280 / Math.max(b.width, b.height)), c = document.createElement('canvas');
      c.width = Math.round(b.width * r); c.height = Math.round(b.height * r);
      c.getContext('2d').drawImage(b, 0, 0, c.width, c.height);
      return new Promise(function(ok){ c.toBlob(function(bl){ ok(bl ? new File([bl], 'photo.jpg', {type: 'image/jpeg'}) : file); }, 'image/jpeg', 0.82); });
    }).catch(function(){ return file; });
  })).then(function(out){
    if (window.DataTransfer){ var d = new DataTransfer(); out.forEach(function(x){ d.items.add(x); }); inp.files = d.files; }
    out.forEach(function(x){
      if (x.type !== 'image/jpeg'){ err.textContent = 'この端末では開けない写真がありました (HEIC など)。保存できないときは JPEG で選び直してください。'; return; }
      var im = document.createElement('img'); im.src = URL.createObjectURL(x); prev.appendChild(im);
    });
    btn.disabled = false;
  });
}
// 写真のポップアップ: 画面を暗くして大きく出す。×・背景・Esc で閉じる。同じメモの写真は 矢印・左右キー・スワイプ で切り替える
var LB = null;
function lbOpen(a){
  if (!LB){
    LB = document.createElement('div'); LB.className = 'lb'; LB.setAttribute('role', 'dialog'); LB.setAttribute('aria-modal', 'true');
    LB.innerHTML = '<img alt=""><button type="button" class="lbx" aria-label="閉じる">×</button>'
      + '<button type="button" class="lbp" aria-label="前の写真">‹</button><button type="button" class="lbn" aria-label="次の写真">›</button><div class="lbc"></div>';
    document.body.appendChild(LB);
    LB.onclick = function(e){ if (e.target === LB || e.target.className === 'lbx') lbClose(); };
    LB.querySelector('.lbp').onclick = function(){ lbShow(LB.i - 1); };
    LB.querySelector('.lbn').onclick = function(){ lbShow(LB.i + 1); };
    var x0 = null;
    LB.addEventListener('touchstart', function(e){ x0 = e.touches[0].clientX; }, {passive: true});
    LB.addEventListener('touchend', function(e){
      if (x0 === null) return; var dx = e.changedTouches[0].clientX - x0; x0 = null;
      if (Math.abs(dx) > 50) lbShow(LB.i + (dx < 0 ? 1 : -1));
    });
    document.addEventListener('keydown', function(e){
      if (!LB.classList.contains('on')) return;
      if (e.key === 'Escape') lbClose(); else if (e.key === 'ArrowLeft') lbShow(LB.i - 1); else if (e.key === 'ArrowRight') lbShow(LB.i + 1);
    });
  }
  LB.list = Array.prototype.map.call(a.parentNode.querySelectorAll('a'), function(x){ return x.href; });
  LB.classList.add('on'); document.body.style.overflow = 'hidden';
  lbShow(LB.list.indexOf(a.href));
  return false;
}
function lbShow(i){
  var n = LB.list.length; LB.i = (i + n) % n;
  LB.querySelector('img').src = LB.list[LB.i];
  LB.querySelector('.lbc').textContent = n > 1 ? (LB.i + 1) + ' / ' + n : '';
  LB.querySelector('.lbp').style.display = LB.querySelector('.lbn').style.display = n > 1 ? '' : 'none';
}
function lbClose(){ LB.classList.remove('on'); LB.querySelector('img').removeAttribute('src'); document.body.style.overflow = ''; }
</script>
JS;
echo '</body></html>';
