<?php
// AIメモ・記録: 話し言葉で書いた文 (🎤 音声入力でもよい) を AI (Claude) で読み取り、メモ・作業記録にするページ。
//   1. 文を書いて「AI で読み取る」
//   2. 読み取った記録が並ぶので、対象・日付・内容・時間・作業した人を確かめて直す (いらないものはチェックを外す)
//   3. 「記録する」でメモ・作業記録に保存 (データには via = 'ai' が付く。あとはふつうのメモ・作業記録と同じ)
//   読み取りのしくみは hpx_ai_note.php (LINE で記録するのと同じ)。config.php の $HPX_AI_KEY が空なら使えない。
//   読み取った結果は、記録するまでセッションに置く (ai_note)。
//   手書きの記録の写真から: 「📷 写真から文字を読み取る」で写真の文字を 1 の文の欄に入れる (読みにくい所は 〔 〕? と一覧)。
//     直してから「AI で読み取る」。記録したメモには元の写真を付ける。LINE で送った写真の文は ?photo=line で読み込む。
declare(strict_types=1);
require __DIR__ . '/hpx_site.php';
require __DIR__ . '/hpx_ai_note.php';

const AN_PAGE = 'ai_note.php';
$site = site_load();
$workTypes = ln_work_types();
$draft = is_array($_SESSION['ai_note'] ?? null) ? $_SESSION['ai_note'] : null;

// 置いてある写真を見せる (本人の分だけ)
if (isset($_GET['img'])) {
    $p = ln_photo_path($UID);
    if (!is_file($p)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, no-store');
    readfile($p);
    exit;
}
// LINE で送った手書きの記録の写真から読み取った文を、ここで直す
if (($_GET['photo'] ?? '') === 'line') {
    $info = ln_photo_info($UID);
    if (!$info) redirect(AN_PAGE, 'LINE で送った写真の読み取りが見つかりません (' . LN_PHOTO_KEEP_H . '時間たったか、もう記録・やめた写真です)。');
    unset($_SESSION['ai_note']);
    $_SESSION['ai_note_text'] = (string)$info['text'];
    $_SESSION['ai_note_unsure'] = (array)($info['unsure'] ?? []);
    $_SESSION['ai_note_photo'] = true;
    redirect(AN_PAGE, 'LINE で送った写真から読み取った文を入れました。〔 〕? の所を確かめて直してから「AI で読み取る」を押してください。');
}
$hasPhoto = !empty($_SESSION['ai_note_photo']) && is_file(ln_photo_path($UID));

if (is_post()) {
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'photo') {   // 手書きの記録の写真から文字を読み取る
        if (!ai_key_on()) redirect(AN_PAGE);
        if (is_demo($ME)) redirect(AN_PAGE, 'デモ用アカウントでは AI の読み取りは使えません。');
        if (!$site['apiaries']) redirect(AN_PAGE, '先に蜂場を登録してください。');
        $f = $_FILES['photo'] ?? null;
        if (!is_array($f) || (int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) redirect(AN_PAGE, '写真を選んでください (大きすぎる写真は送れません)。');
        if (!ai_quota_use($UID)) redirect(AN_PAGE, '今日 AI で読み取れる回数 (' . ai_day_max() . '回) を使い切りました。');
        if (!ln_photo_store($UID, (string)$f['tmp_name'])) redirect(AN_PAGE, 'この写真は読めませんでした (JPEG・PNG・WebP で 20MB まで)。');
        [$ph, $err] = ln_photo_read($site, $UID);
        if ($ph === null) redirect(AN_PAGE, 'いま写真を読み取れませんでした。少し待ってもう一度送ってください。');
        if ($ph['text'] === '') {
            ln_photo_clear($UID);
            redirect(AN_PAGE, '写真から手書きの記録が読み取れませんでした。' . ($ph['note'] !== '' ? ' (' . $ph['note'] . ')' : '') . ' 明るい所で、紙全体がまっすぐ大きく写るように撮ってください。');
        }
        unset($_SESSION['ai_note']);
        $_SESSION['ai_note_text'] = $ph['text'];
        $_SESSION['ai_note_unsure'] = $ph['unsure'];
        $_SESSION['ai_note_photo'] = true;
        redirect(AN_PAGE, '写真から文字を読み取りました。〔 〕? の所を確かめて直してから「AI で読み取る」を押してください。' . ($ph['note'] !== '' ? ' (' . $ph['note'] . ')' : ''));
    }
    if ($act === 'photo_clear') {
        ln_photo_clear($UID);
        unset($_SESSION['ai_note_photo'], $_SESSION['ai_note_unsure'], $_SESSION['ai_note_text'], $_SESSION['ai_note']);
        redirect(AN_PAGE, '写真をやめました。');
    }
    if ($act === 'parse') {
        $text = str_in('text', LN_TEXT_MAX);
        if (!ai_key_on()) redirect(AN_PAGE);
        if (is_demo($ME)) redirect(AN_PAGE, 'デモ用アカウントでは AI の読み取りは使えません。');
        if ($text === '') redirect(AN_PAGE, '文を入れてください。');
        if (!$site['apiaries']) redirect(AN_PAGE, '先に蜂場を登録してください。');
        if (!ai_quota_use($UID)) { $_SESSION['ai_note_text'] = $text; redirect(AN_PAGE, '今日 AI で読み取れる回数 (' . ai_day_max() . '回) を使い切りました。メモ・記録のページで入力してください。'); }
        [$recs, $notRec, $note] = ln_parse($site, $hasPhoto ? ln_photo_src($text) : $text);
        @file_put_contents(data_dir() . '/line_note.log', date('c') . " {$UID} web " . ($recs === null ? 'AI FAILED ' . $note : 'records=' . count($recs)) . "\n", FILE_APPEND);
        $_SESSION['ai_note_text'] = $text;
        if ($recs === null) redirect(AN_PAGE, 'いま AI で読み取れませんでした。少し待ってもう一度押すか、メモ・記録のページで入力してください。');
        if (!$recs) redirect(AN_PAGE, $notRec ? 'メモや作業記録として読み取れる内容がありませんでした。どの蜂場・巣箱で、何を見た・何をしたかを書いてください。'
            : '記録にする内容が読み取れませんでした。' . ($note !== '' ? ' (' . $note . ')' : ''));
        $_SESSION['ai_note'] = ['recs' => $recs, 'note' => $note, 'src' => $hasPhoto ? ln_photo_src($text) : $text, 'ai' => $recs, 'photo' => $hasPhoto];
        redirect(AN_PAGE . '#check');
    }
    if ($act === 'rule_set') {   // 覚えたこと: 足す (id 空)・直す・消す (rule 空)・すべて消す (id *)
        $id = (string)($_POST['id'] ?? '');
        $del = !empty($_POST['del']);
        if ($id === '' || $id === '*' || preg_match('/^[0-9a-f]{10}$/', $id)) ln_rule_set($UID, $id, $del ? '' : (string)($_POST['rule'] ?? ''));
        redirect(AN_PAGE . '#rules', $id === '*' ? '覚えたことをすべて消しました。' : ($del ? '覚えたことを1つ消しました。' : '覚えたことを保存しました。'));
    }
    if ($act === 'learn_del') {
        $id = (string)($_POST['id'] ?? '');
        if ($id === '*' || preg_match('/^[0-9a-f]{10}$/', $id)) ln_learn_del($UID, $id);
        redirect(AN_PAGE . '#learn', $id === '*' ? '覚えた例をすべて消しました。' : '覚えた例を1つ消しました。');
    }
    if ($act === 'reset') {
        unset($_SESSION['ai_note']);
        redirect(AN_PAGE);
    }
    if ($act === 'save' && $draft) {
        // 画面で直したところを入れる
        $recs = $draft['recs'];
        $use = [];
        $people = ln_people($site);
        $pu = array_column($people, 0);
        foreach ($recs as $i => &$r) {
            $use[$i] = !empty($_POST['use'][$i]);
            $tg = parse_target($site, (string)($_POST['t'][$i] ?? ''));
            $r['a'] = $tg[0] ?? null;
            $r['h'] = $tg[1] ?? '';
            $d = (string)($_POST['date'][$i] ?? '');
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false) $r['date'] = $d;
            $r['text'] = mb_substr(trim(str_replace("\r\n", "\n", (string)($_POST['text'][$i] ?? ''))), 0, 2000);
            $min = trim((string)($_POST['min'][$i] ?? ''));
            $r['min'] = ctype_digit($min) && (int)$min <= 1440 ? (int)$min : 0;
            if ($r['kind'] === 'task') {
                $r['title'] = mb_substr(trim((string)($_POST['title'][$i] ?? '')), 0, 200);
                $d = (string)($_POST['due'][$i] ?? '');
                $r['due'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false ? $d : '';
                $r['ttags'] = array_values(array_intersect(array_unique(array_merge(ln_task_tags(), (array)($r['ttags'] ?? []))), array_map('strval', (array)($_POST['ttags'][$i] ?? []))));
                $r['notify'] = !empty($_POST['notify'][$i]);
            } elseif ($r['kind'] === 'memo') {
                $t = (string)($_POST['type'][$i] ?? '');
                $r['type'] = in_array($t, S_MEMO_TYPES, true) ? $t : '';
                if ($r['type'] !== '内検') { $r['inspect'] = []; $r['mite'] = []; }
            } else {
                $t = (string)($_POST['wtype'][$i] ?? '');
                if (in_array($t, $workTypes, true)) $r['wtype'] = $t;
                $hk = trim((string)($_POST['honey'][$i] ?? ''));
                $r['honey'] = is_numeric($hk) && (float)$hk > 0 && (float)$hk <= 100000 ? round((float)$hk, 1) : null;
            }
            $r['workers'] = array_values(array_unique(array_filter(array_map('strval', (array)($_POST['workers'][$i] ?? [])), fn($u) => in_array($u, $pu, true))));
            $pp = (int)($_POST['people'][$i] ?? 0);
            $r['people'] = max(0, min(99, $pp));
        }
        unset($r);
        $_SESSION['ai_note']['recs'] = $recs;
        $save = [];
        foreach ($recs as $i => $r) {
            if (!$use[$i]) continue;
            if ($r['a'] === null) redirect(AN_PAGE . '#r' . $i, (string)($i + 1) . '件目の対象 (蜂場・巣箱) を選んでください。');
            if ($r['kind'] === 'work' && $r['min'] <= 0) redirect(AN_PAGE . '#r' . $i, (string)($i + 1) . '件目の作業記録の、かかった時間を入れてください。');
            if ($r['kind'] === 'task' && $r['title'] === '' && !$r['ttags']) redirect(AN_PAGE . '#r' . $i, (string)($i + 1) . '件目のタスクの、やることかタグを入れてください。');
            if ($r['kind'] === 'memo' && $r['text'] === '' && !$r['inspect'] && !$r['mite'] && !$r['tags']) redirect(AN_PAGE . '#r' . $i, (string)($i + 1) . '件目のメモの中身を入れてください。');
            $save[] = $r;
        }
        if (!$save) redirect(AN_PAGE . '#check', '記録するものにチェックを入れてください。');
        $withPhoto = !empty($draft['photo']) && $hasPhoto;
        ln_save($site, $save, 'ai', $withPhoto && !empty($_POST['photo_attach']) ? ln_photo_path($UID) : '');
        if ($withPhoto) ln_photo_clear($UID);
        // 直した例として残す (次からの読み取りの見本になる)。AI の読み取りと同じなら「直さなかった例」
        $newRules = 0;
        if (empty($_POST['learn_off'])) {
            ln_learn_add($UID, $site, (string)($draft['src'] ?? ''), (array)($draft['ai'] ?? []), $save, 'ai');
            $newRules = ln_rules_update($UID);   // 直したところがあれば、AI が「覚えたこと」にまとめる
        }
        unset($_SESSION['ai_note'], $_SESSION['ai_note_text'], $_SESSION['ai_note_photo'], $_SESSION['ai_note_unsure']);
        $nm = count(array_filter($save, fn($r) => $r['kind'] === 'memo'));
        $nt = count(array_filter($save, fn($r) => $r['kind'] === 'task'));
        $nw = count(array_filter($save, fn($r) => $r['kind'] === 'work')) + count(array_filter($save, fn($r) => $r['kind'] === 'memo' && $r['type'] === '内検' && $r['min'] > 0));
        redirect(AN_PAGE, '記録しました (' . implode('・', array_filter([$nm ? 'メモ ' . $nm . '件' : '', $nw ? '作業記録 ' . $nw . '件' : '', $nt ? 'タスク ' . $nt . '件' : ''])) . ')。メモ・記録・タスクのページで見られます。' . ($newRules ? ' 直したところから、AI が「覚えたこと」を ' . $newRules . '件 足しました (このページの下で見られます)。' : ''));
    }
    redirect(AN_PAGE);
}

page_head('AIメモ・記録 - ' . S_TITLE, AN_PAGE);
echo flash_html();
echo '<p class="tabs"><a href="ai_note.php" class="on">AIメモ・記録</a><a href="kb.php">資料に聞く</a></p>';   // 資料に聞く (kb.php)
if (!ai_key_on()) {
    echo '<p class="card">AIメモ・記録は、サーバーに Claude の API キーが設定されていないため使えません (サーバー管理者が config.php の $HPX_AI_KEY に入れると使えます)。</p></body></html>';
    exit;
}

// 1. 文を書く
$text = (string)($_SESSION['ai_note_text'] ?? '');
echo '<p class="muted">話し言葉のままで書いてください (🎤 音声入力も使えます)。AI がどの蜂場・巣箱か、メモか作業記録かタスク (これからやること) か、作業の種類・時間・作業した人、タスクの期限・担当者を読み取ります。記録する前に、ここで確かめて直せます。</p>'
    . '<form method="post" class="card form">' . csrf_field() . '<input type="hidden" name="act" value="parse">'
    . '<p><textarea name="text" rows="5" data-voice placeholder="例: 山の蜂場の1番、女王いた、卵あり、王台なし。Aさんと2人で給餌、糖液を全部の巣箱に、1時間。来週の土曜までに継箱を用意、Bさん担当">' . h($text) . '</textarea></p>'
    . '<button type="submit">AI で読み取る</button> <span class="muted">(送った文は読み取りのため AI (Claude) に送られます。1日 ' . ai_day_max() . '回まで)</span></form>';

// 手書きの記録の写真から
if ($hasPhoto) {
    $unsure = (array)($_SESSION['ai_note_unsure'] ?? []);
    echo '<div class="card"><p><b>📷 手書きの記録の写真から読み取った文です。</b> 上の欄で直してから「AI で読み取る」を押してください。記録したメモには、この写真を付けます。</p>'
        . '<p><img src="ai_note.php?img=1" alt="送った写真" style="max-width:100%;max-height:360px;border:1px solid #ccc"></p>';
    if ($unsure) echo '<p class="warn">読みにくかった所 (文の中の 〔 〕? の所。写真と見くらべて直してください):</p><ul>'
        . implode('', array_map(fn($l) => '<li>' . h($l) . '</li>', ln_unsure_lines($unsure))) . '</ul>';
    echo '<form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="act" value="photo_clear"><button type="submit" class="link">写真をやめる</button></form></div>';
} else {
    echo '<form method="post" enctype="multipart/form-data" class="card form">' . csrf_field() . '<input type="hidden" name="act" value="photo">'
        . '<p><label>📷 手書きの記録 (ノート・記録用紙など) の写真から</label><input type="file" name="photo" accept="image/*" required onchange="anPhoto(this)"></p>'
        . '<button type="submit">写真から文字を読み取る</button> <span class="muted">(写真の文字を上の欄に入れます。読みにくい所には印が付くので、直してから読み取ってください。LINE に写真を送っても使えます)</span></form>';
}

// 2. 読み取った記録を確かめる
if ($draft) {
    $targets = ln_targets($site);
    $people = team_on() ? ln_people($site) : [];
    echo '<h2 id="check">読み取った記録 (確かめてから「記録する」)</h2>';
    if ((string)($draft['note'] ?? '') !== '') echo '<p class="muted">AI のメモ: ' . h((string)$draft['note']) . '</p>';
    echo '<form method="post">' . csrf_field() . '<input type="hidden" name="act" value="save">';
    foreach ($draft['recs'] as $i => $r) {
        $isWork = $r['kind'] === 'work';
        $isTask = $r['kind'] === 'task';
        $cur = $r['a'] !== null ? $r['a'] . '|' . $r['h'] : '';
        $sel = '<select name="t[' . $i . ']" required>' . ($cur === '' ? '<option value="">(選んでください)</option>' : '');
        if ($cur === '' && $r['cand']) {
            $sel .= '<optgroup label="候補">';
            foreach ($r['cand'] as [$a, $hv]) $sel .= '<option value="' . h($a . '|' . $hv) . '">' . h(target_label($site, $a, $hv)) . '</option>';
            $sel .= '</optgroup>';
        }
        foreach ($targets as [$a, $hv, $label]) $sel .= '<option value="' . h($a . '|' . $hv) . '"' . ($cur === $a . '|' . $hv ? ' selected' : '') . '>' . h($label . ($hv === '' ? ' (蜂場全体)' : '')) . '</option>';
        $sel .= '</select>';
        echo '<div class="card form ' . ($isWork ? 'apiary' : 'hive') . '" id="r' . $i . '">'
            . '<p><label style="display:inline-flex;align-items:center;gap:6px;color:#222;font-weight:bold"><input type="checkbox" name="use[' . $i . ']" value="1" checked> '
            . ($i + 1) . '. ' . ($isTask ? 'タスク (これからやること)' : ($isWork ? '作業記録' : 'メモ')) . '</label></p><div class="grid2">'
            . '<p><label>対象</label>' . $sel . '</p>';
        if ($isTask) {
            echo '<p><label>期限</label><input type="date" name="due[' . $i . ']" value="' . h((string)$r['due']) . '"></p>'
                . '<p class="wide"><label>やること</label><input type="text" name="title[' . $i . ']" maxlength="200" value="' . h((string)$r['title']) . '"></p></div>';
            echo '<p><label>タグ</label><span class="ichk">';
            foreach (array_unique(array_merge(ln_task_tags(), (array)$r['ttags'])) as $t) echo '<label class="tagc"><input type="checkbox" name="ttags[' . $i . '][]" value="' . h($t) . '"' . (in_array($t, (array)$r['ttags'], true) ? ' checked' : '') . '><span>' . h($t) . '</span></label>';
            echo '</span></p>';
            if ($people) {
                echo '<p><label>担当者 <span class="muted">(選ばなければ未定。その蜂場を見られる人だけ記録されます)</span></label><span class="ichk">';
                foreach ($people as [$u, $nm]) echo '<label class="tagc"><input type="checkbox" name="workers[' . $i . '][]" value="' . h($u) . '"' . (in_array($u, $r['workers'], true) ? ' checked' : '') . '><span>' . h($nm) . '</span></label>';
                echo '</span></p>';
            }
            echo '<p><label style="display:inline-flex;align-items:center;gap:6px;color:#222"><input type="checkbox" name="notify[' . $i . ']" value="1"' . (($r['notify'] ?? true) ? ' checked' : '') . '> 期限の前日と当日 (過ぎたら毎朝) にメールで知らせる</label></p>'
                . '<p><label>メモ</label><textarea name="text[' . $i . ']" rows="2">' . h($r['text']) . '</textarea></p></div>';
            continue;
        }
        echo '<p><label>日付</label><input type="date" name="date[' . $i . ']" value="' . h($r['date']) . '" required></p>';
        if ($isWork) {
            $opt = '';
            foreach (array_unique(array_merge($workTypes, [$r['wtype']])) as $t) $opt .= '<option' . ($t === $r['wtype'] ? ' selected' : '') . '>' . h($t) . '</option>';
            echo '<p><label>作業の種類</label><select name="wtype[' . $i . ']">' . $opt . '</select></p>'
                . '<p><label>かかった時間 (分)</label><input type="number" name="min[' . $i . ']" min="1" max="1440" value="' . ($r['min'] > 0 ? $r['min'] : '') . '"> <span class="muted">' . ($r['min'] > 0 ? h(ln_hm($r['min'])) : '入れてください') . '</span></p>'
                . (mb_strpos($r['wtype'], '採蜜') !== false || $r['honey'] !== null ? '<p><label>採蜜量 (kg)</label><input type="number" name="honey[' . $i . ']" step="0.1" min="0" value="' . h((string)($r['honey'] ?? '')) . '"></p>' : '');
        } else {
            $opt = '<option value="">－</option>';
            foreach (S_MEMO_TYPES as $t) $opt .= '<option' . ($t === $r['type'] ? ' selected' : '') . '>' . h($t) . '</option>';
            echo '<p><label>内容</label><select name="type[' . $i . ']">' . $opt . '</select></p>'
                . '<p><label>内検の作業時間 (分) <span class="muted">(入れると作業記録「内検」も作る)</span></label><input type="number" name="min[' . $i . ']" min="0" max="1440" value="' . ($r['min'] > 0 ? $r['min'] : '') . '"></p>';
        }
        echo '</div>';
        // 作業した人 (作業記録と、時間のある内検)
        if ($people) {
            echo '<p><label>作業した人 <span class="muted">(選ばなければ記入者。その蜂場を見られる人だけ記録されます)</span></label><span class="ichk">';
            foreach ($people as [$u, $nm]) echo '<label class="tagc"><input type="checkbox" name="workers[' . $i . '][]" value="' . h($u) . '"' . (in_array($u, $r['workers'], true) ? ' checked' : '') . '><span>' . h($nm) . '</span></label>';
            echo '</span></p>';
        }
        echo '<p><label>人数 <span class="muted">(作業した人より多いとき)</span></label><input type="number" name="people[' . $i . ']" min="0" max="99" value="' . ($r['people'] > 0 ? $r['people'] : '') . '"></p>';
        // 内検の項目・ダニ・タグ (ここでは直せない。記録してからメモのページで直す)
        $chips = memo_chips($r);
        foreach ($r['inspect'] as $k => $v) if (!is_array($v)) $chips[] = $k === 'frames' ? '蜂数 ' . $v . '枚群' : M_INSPECT[$k][0] . ' ' . $v;
        if ($r['mite']) $chips[] = 'ダニ調査 ' . mite_text($r['mite']);
        if ($chips && !$isWork) echo '<p><label>内検の項目・タグ <span class="muted">(直すときは、記録してからメモのページで)</span></label><span class="tagl">' . implode('', array_map(fn($c) => '<span>' . h($c) . '</span>', $chips)) . '</span></p>';
        echo '<p><label>' . ($isWork ? 'メモ' : '内容の文') . '</label><textarea name="text[' . $i . ']" rows="3">' . h($r['text']) . '</textarea></p></div>';
    }
    if (!empty($draft['photo']) && $hasPhoto) echo '<p><label style="display:inline-flex;align-items:center;gap:6px"><input type="checkbox" name="photo_attach" value="1" checked> 手書きの写真をメモに付ける</label></p>';
    echo '<p><label style="display:inline-flex;align-items:center;gap:6px"><input type="checkbox" name="learn_off" value="1"> この結果は AI に覚えさせない</label></p>'
        . '<p><button type="submit">チェックした記録を記録する</button></p></form>'
        . '<form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="act" value="reset"><button type="submit" class="link">読み取った結果を捨てる</button></form>';
}
// 3. 覚えたこと (決まりの一覧。毎回すべて AI に渡す)
$rules = ln_rules_all($UID);
echo '<h2 id="rules">AI が覚えたこと (' . count($rules) . '件)</h2>'
    . '<p class="muted">記録するときに直したところを、AI が「『いちばん』は 山の蜂場 / 1番 のこと」のような短い決まりにまとめて、ここにためます。読み取るたびに、すべて AI に渡します。'
    . 'まちがっている決まりは直すか消してください。自分で足すこともできます (' . LN_RULES_MAX . '件まで。古いものから消えます)。</p><div class="card form">';
foreach ($rules as $x) {
    echo '<form method="post" style="display:flex;gap:6px;align-items:center;margin:4px 0">' . csrf_field() . '<input type="hidden" name="act" value="rule_set"><input type="hidden" name="id" value="' . h((string)($x['id'] ?? '')) . '">'
        . '<input type="text" name="rule" value="' . h((string)($x['rule'] ?? '')) . '" maxlength="' . LN_RULE_LEN . '" style="flex:1">'
        . '<button type="submit">保存</button><button type="submit" name="del" value="1" class="link">消す</button></form>';
}
echo '<form method="post" style="display:flex;gap:6px;align-items:center;margin:8px 0 4px">' . csrf_field() . '<input type="hidden" name="act" value="rule_set"><input type="hidden" name="id" value="">'
    . '<input type="text" name="rule" maxlength="' . LN_RULE_LEN . '" placeholder="足す決まり (例: 「えさやり」は作業記録の 給餌)" style="flex:1"><button type="submit">足す</button></form>';
if ($rules) echo '<form method="post" onsubmit="return window.confirm(\'覚えたことをすべて消します。よろしいですか?\')">' . csrf_field()
    . '<input type="hidden" name="act" value="rule_set"><input type="hidden" name="id" value="*"><button type="submit" class="link">すべて消す</button></form>';
echo '</div>';

// 4. AI が覚えた例 (直した例。次からの読み取りの見本になる)
$learn = array_reverse(ln_learn_all($UID));
echo '<h2 id="learn">AI が覚えた例 (' . count($learn) . '件)</h2>'
    . '<p class="muted">記録するときに直した内容を覚えておき、次から読み取るとき、今回の文と似ている例 (直したものを先に ' . LN_LEARN_USE . '件まで) を見本として AI に渡します。'
    . 'あなたの書き方や巣箱の呼び方に、だんだん合ってきます。例はあなたの読み取りにだけ使います (チームのほかの人には使いません)。いらない例は消せます。</p>';
if ($learn) {
    echo '<details><summary>覚えた例を見る</summary>';
    foreach (array_slice($learn, 0, 30) as $x) {
        echo '<div class="card"><p class="muted">' . h(date('Y-m-d H:i', (int)($x['at'] ?? 0))) . ' ' . (($x['via'] ?? '') === 'line' ? 'LINE' : '画面')
            . (!empty($x['changed']) ? ' <span class="warn">直した</span>' : ' 直さなかった') . '</p>'
            . '<p>' . nl((string)($x['text'] ?? '')) . '</p><p class="muted">記録した内容:</p><ul>';
        foreach ((array)($x['fixed'] ?? []) as $line) echo '<li>' . h((string)$line) . '</li>';
        echo '</ul>';
        if (!empty($x['changed'])) {
            echo '<p class="muted">AI の読み取り:</p><ul class="muted">';
            foreach ((array)($x['ai'] ?? []) as $line) echo '<li>' . h((string)$line) . '</li>';
            echo '</ul>';
        }
        echo '<form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="act" value="learn_del"><input type="hidden" name="id" value="' . h((string)($x['id'] ?? '')) . '"><button type="submit" class="link">この例を消す</button></form></div>';
    }
    echo '<form method="post" onsubmit="return window.confirm(\'覚えた例をすべて消します。よろしいですか?\')">' . csrf_field()
        . '<input type="hidden" name="act" value="learn_del"><input type="hidden" name="id" value="*"><button type="submit">すべて消す</button></form></details>';
}
// 写真は送る前にブラウザで小さくする (iPhone の HEIC も JPEG に。memo.php と同じやり方)
echo voice_js() . <<<'JS'
<script>
function anPhoto(inp){
  var file = inp.files && inp.files[0];
  if (!file || !window.createImageBitmap || !window.DataTransfer) return;
  var btn = inp.form.querySelector('button[type=submit]'); btn.disabled = true;
  createImageBitmap(file, {imageOrientation: 'from-image'}).then(function(b){
    var r = Math.min(1, 1568 / Math.max(b.width, b.height)), c = document.createElement('canvas');
    c.width = Math.round(b.width * r); c.height = Math.round(b.height * r);
    c.getContext('2d').drawImage(b, 0, 0, c.width, c.height);
    return new Promise(function(ok){ c.toBlob(function(bl){ ok(bl ? new File([bl], 'photo.jpg', {type: 'image/jpeg'}) : file); }, 'image/jpeg', 0.88); });
  }).catch(function(){ return file; }).then(function(x){
    var d = new DataTransfer(); d.items.add(x); inp.files = d.files; btn.disabled = false;
  });
}
</script>
JS . '</body></html>';
