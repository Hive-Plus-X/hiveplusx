<?php
// 資料に聞く: 日本養蜂協会が公開している養蜂マニュアル・手引書の中だけから AI が答えるページ (メニュー「AI」の中)。
//   答えには「資料名 (年) ◯ページ」の出典と、元の PDF のそのページを開くリンクを付ける。しくみは hpx_kb.php。
//   使えるのは HPX-note にログインした人だけ。config.php の $HPX_AI_KEY が空、または data/kb/kb.json が無いと使えない。
declare(strict_types=1);
require __DIR__ . '/hpx_site.php';
require __DIR__ . '/hpx_ai_note.php';
require __DIR__ . '/hpx_kb.php';

const KB_PAGE = 'kb.php';

if (is_post()) {
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'ask') {
        $q = str_in('q', KB_Q_MAX);
        if (!ai_key_on() || !kb_load()) redirect(KB_PAGE);
        if (is_demo($ME)) redirect(KB_PAGE, 'デモ用アカウントでは AI は使えません。');
        if ($q === '') redirect(KB_PAGE, '質問を入れてください。');
        $_SESSION['kb_q'] = $q;
        if (!ai_quota_use($UID)) redirect(KB_PAGE, '今日 AI を使える回数 (' . ai_day_max() . '回) を使い切りました。');
        [$a, $err] = kb_ask($q);
        @file_put_contents(data_dir() . '/kb_ask.log', date('c') . " {$UID} " . ($a === null ? 'AI FAILED ' . $err : 'found=' . (int)$a['found'] . ' cites=' . count($a['cites'])) . "\n", FILE_APPEND);
        if ($a === null) redirect(KB_PAGE, 'いま AI で答えられませんでした。少し待ってもう一度押してください。');
        kb_hist_add($UID, $q, $a);
        unset($_SESSION['kb_q']);
        redirect(KB_PAGE . '#ans');
    }
    if ($act === 'del') {
        $id = (string)($_POST['id'] ?? '');
        if ($id === '*' || preg_match('/^[0-9a-f]{10}$/', $id)) kb_hist_del($UID, $id);
        redirect(KB_PAGE, $id === '*' ? '質問の控えをすべて消しました。' : '');
    }
    redirect(KB_PAGE);
}

page_head('資料に聞く - ' . S_TITLE, KB_PAGE);
echo flash_html();
echo '<p class="tabs"><a href="ai_note.php">AIメモ・記録</a><a href="kb.php" class="on">資料に聞く</a></p>';
$kb = kb_load();
if (!ai_key_on()) {
    echo '<p class="card">資料に聞くは、サーバーに Claude の API キーが設定されていないため使えません (サーバー管理者が config.php の $HPX_AI_KEY に入れると使えます)。</p></body></html>';
    exit;
}
if (!$kb) {
    echo '<p class="card">資料のデータがまだ入っていません。サーバー管理者が、資料のデータ (kb.json) を hpx/data/kb/ に入れると使えます。作り方は GitHub の tools/kb/README.md にあります。</p></body></html>';
    exit;
}

echo '<p class="muted">日本養蜂協会が公開している養蜂マニュアル・養蜂技術指導手引書の中だけから AI が答えます。ほかのサイトは見ません。'
    . '答えには出典 (資料名・年・ページ) が付き、押すと元の PDF のそのページが開きます。同じことが複数の資料にあるときは、新しい年の資料をもとに答えます。</p>'
    . '<form method="post" class="card form">' . csrf_field() . '<input type="hidden" name="act" value="ask">'
    . '<p><textarea name="q" rows="3" maxlength="' . KB_Q_MAX . '" data-voice placeholder="例: ヘギイタダニの寄生率はどうやって調べる? / 越冬前の給餌の量は? / 電気柵の電圧はどのくらい必要?">' . h((string)($_SESSION['kb_q'] ?? '')) . '</textarea></p>'
    . '<button type="submit" onclick="this.disabled=true;this.textContent=\'資料を探して答えています… (30秒ほど)\';this.form.submit()">資料に聞く</button> '
    . '<span class="muted">(質問は AI (Claude) に送られます。AIメモ・記録と合わせて1日 ' . ai_day_max() . '回まで)</span></form>';
echo voice_js();

$hist = kb_hist($UID);
foreach ($hist as $k => $x) {
    $a = (array)($x['a'] ?? []);
    echo '<div class="card"' . ($k === 0 ? ' id="ans"' : '') . '>'
        . '<p style="margin:.2em 0"><b>Q.</b> ' . nl((string)($x['q'] ?? '')) . ' <span class="muted">' . h(date('n/j H:i', strtotime((string)($x['at'] ?? '')) ?: time())) . '</span></p>'
        . '<p style="margin:.5em 0"><b>A.</b> ' . nl((string)($a['answer'] ?? '')) . '</p>';
    $cites = (array)($a['cites'] ?? []);
    if ($cites) {
        echo '<p class="muted" style="margin:.4em 0 .1em">出典 (押すと元の PDF のそのページが開きます)</p><ul style="margin:.1em 0 .4em">';
        foreach ($cites as $c) echo '<li><a href="' . h((string)($c[1] ?? '')) . '" target="_blank" rel="noopener">' . h((string)($c[0] ?? '')) . '</a></li>';
        echo '</ul>';
    }
    echo '<form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="act" value="del"><input type="hidden" name="id" value="' . h((string)($x['id'] ?? '')) . '">'
        . '<button type="submit" class="link">この質問を消す</button></form></div>';
    if ($k === 0 && count($hist) > 1) echo '<h2>前の質問</h2>';
}
if ($hist) echo '<form method="post" onsubmit="return window.confirm(\'質問の控えをすべて消します。よろしいですか?\')">' . csrf_field()
    . '<input type="hidden" name="act" value="del"><input type="hidden" name="id" value="*"><button type="submit" class="link">質問の控えをすべて消す</button></form>';

echo '<p class="muted" style="margin-top:1.5em">AI の答えはまちがうことがあります。大事なことは出典のページで確かめてください。薬剤の使い方や届出などは、最新の情報をラベルや役所でも確かめてください。</p>';
echo '<details><summary>答えのもとにしている資料 (' . count($kb['docs']) . ')</summary><ul>';
$docs = $kb['docs'];
usort($docs, fn($a, $b) => (int)$b['year'] <=> (int)$a['year']);
foreach ($docs as $d) echo '<li><a href="' . h((string)$d['url']) . '" target="_blank" rel="noopener">' . h((string)$d['title']) . '</a> (' . (int)$d['year'] . '年)</li>';
echo '</ul><p class="muted">発行: ' . h((string)($kb['publisher'] ?? '')) . '。資料は ' . h(substr((string)($kb['built'] ?? ''), 0, 10)) . ' に取り込みました。</p></details>';
echo '</body></html>';
