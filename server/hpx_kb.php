<?php
// 資料に聞く: 日本養蜂協会などが公開している養蜂マニュアル・手引書の中だけから AI (Claude) が答えるしくみ。kb.php から使う。
//   hpx_site.php・hpx_ai_note.php の後に読み込む (AI の呼び出し ai_json()・回数 ai_quota_use() を使う)。
//
//   データ: data/kb/kb.json (tools/kb/kb_build.py で PDF から作ってアップロードする。協会の著作物なので GitHub には入れない)
//     {built, publisher, docs:[{id,title,year,url,from,pages}], pages:[{d:資料id, p:PDFの何枚目, n:印刷のページ番号 ("" は不明), t:文章}]}
//   しくみ:
//     1. 質問から AI が探す言葉 (言いかえ・専門用語も) を出す (安い・短い呼び出し)
//     2. サーバーの中の kb.json だけから、探す言葉と質問の2文字ずつ (bigram) で点数を付けてページを選ぶ (ほかのサイトは見ない)
//     3. 選んだページだけを AI に渡し、その中だけで答える・出典を「資料名 (年) ◯ページ」で書く・新しい年の資料を優先する
//   AI の回数は AIメモ・記録と合わせて1日 $HPX_AI_DAY_MAX 回 (1問で1回と数える)。
declare(strict_types=1);

const KB_Q_MAX = 500;        // 質問の長さの上限
const KB_PICK = 10;          // AI に渡すページの数
const KB_PER_DOC = 4;        // 1つの資料から渡すページの上限 (いろいろな資料・年を見くらべられるように)
const KB_PAGE_MAX = 3500;    // 1ページの文章の長さの上限 (長いページは切る)

function kb_path(): string { return data_dir() . '/kb/kb.json'; }

function kb_load(): ?array
{
    static $kb = false;
    if ($kb !== false) return $kb;
    $p = kb_path();
    $j = is_file($p) ? json_decode((string)file_get_contents($p), true) : null;
    $kb = is_array($j) && !empty($j['pages']) && !empty($j['docs']) ? $j : null;
    return $kb;
}

function kb_docs(array $kb): array
{
    $o = [];
    foreach ($kb['docs'] as $d) $o[(string)$d['id']] = $d;
    return $o;
}

// 出典の書き方: 「資料名 (2024年) 12ページ」。印刷のページ番号が読めないページは「PDFの5枚目」
function kb_cite(array $doc, array $pg): string
{
    $n = (string)($pg['n'] ?? '');
    return $doc['title'] . '（' . (int)$doc['year'] . '年）' . ($n !== '' ? $n . 'ページ' : 'PDFの' . (int)$pg['p'] . '枚目');
}

// 元の PDF のそのページを開くリンク (#page= はたいていのブラウザの PDF 表示で効く)
function kb_link(array $doc, array $pg): string
{
    return (string)$doc['url'] . '#page=' . (int)$pg['p'];
}

// 2文字ずつに分ける (漢字・かな・カナ・英数字の続きだけ。ひらがなだけの2文字は助詞などが多いので除く)
function kb_bigrams(string $t): array
{
    $t = mb_strtolower(mb_convert_kana($t, 'asKV', 'UTF-8'), 'UTF-8');
    $o = [];
    preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}ー0-9a-z]+/u', $t, $m);
    foreach ($m[0] as $run) {
        $c = mb_str_split($run);
        for ($i = 0; $i + 1 < count($c); $i++) {
            $b = $c[$i] . $c[$i + 1];
            if (preg_match('/^\p{Hiragana}+$/u', $b)) continue;
            $o[$b] = true;
        }
    }
    return array_keys($o);
}

// ページを選ぶ。$words: AI が出した探す言葉。[[ページ, 点数], ...] を点数の高い順に
function kb_search(array $kb, string $q, array $words): array
{
    $pages = $kb['pages'];
    $docs = kb_docs($kb);
    $N = count($pages);
    $norm = fn(string $s) => mb_strtolower(mb_convert_kana($s, 'asKV', 'UTF-8'), 'UTF-8');
    static $low = null;
    if ($low === null) { $low = []; foreach ($pages as $i => $p) $low[$i] = $norm((string)$p['t']); }
    $terms = [];   // 言葉 => 重み
    foreach ($words as $w) {
        $w = $norm(trim((string)$w));
        if (mb_strlen($w) >= 2) $terms[$w] = 3.0;
    }
    foreach (kb_bigrams($q) as $b) if (!isset($terms[$b])) $terms[$b] = 1.0;
    if (!$terms) return [];
    $score = array_fill(0, $N, 0.0);
    foreach ($terms as $w => $wt) {
        $hit = [];
        foreach ($low as $i => $t) { $c = substr_count($t, $w); if ($c) $hit[$i] = $c; }
        if (!$hit) continue;
        $idf = log(($N + 1) / (count($hit) + 0.5));
        if ($idf <= 0) continue;
        foreach ($hit as $i => $c) $score[$i] += $wt * $idf * (1 + log(min($c, 8)));
    }
    arsort($score);
    $out = [];
    $per = [];
    foreach ($score as $i => $s) {
        if ($s <= 0 || count($out) >= KB_PICK) break;
        $d = (string)$pages[$i]['d'];
        if (!isset($docs[$d]) || ($per[$d] ?? 0) >= KB_PER_DOC) continue;
        $per[$d] = ($per[$d] ?? 0) + 1;
        $out[] = [$pages[$i], $s];
    }
    return $out;
}

// 1. 質問から探す言葉を出す
function kb_words(string $q): array
{
    $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['words'],
        'properties' => ['words' => ['type' => 'array', 'items' => ['type' => 'string']]]];
    $sys = 'あなたは養蜂の資料 (日本養蜂協会の養蜂マニュアル・養蜂技術指導手引書) を検索する係です。'
        . '利用者の質問から、資料の本文を文字で探すための言葉を 5〜12 個出してください。'
        . '質問の大事な言葉に加えて、資料で使われそうな言いかえ・専門用語・漢字とカタカナの書き分け (例: ダニ→ミツバチヘギイタダニ・バロア、腐蛆病→腐そ病、女王→女王蜂・王台) も入れます。'
        . '1つの言葉は短く (2〜10文字)。「ミツバチ」「養蜂」のようにどの資料にもある言葉は入れません。';
    [$j, ] = ai_json($sys, $q, $schema, 'low', 30);
    $w = is_array($j['words'] ?? null) ? $j['words'] : [];
    return array_slice(array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 20), $w), fn($x) => $x !== '')), 0, 15);
}

// 質問に答える。[答え (配列) または null, エラーの説明]
//   答え: ['found' => bool, 'answer' => 文, 'cites' => [[出典の書き方, リンク], ...], 'words' => 探した言葉]
function kb_ask(string $q): array
{
    $kb = kb_load();
    if (!$kb) return [null, '資料のデータがありません'];
    $docs = kb_docs($kb);
    $words = kb_words($q);
    $hits = kb_search($kb, $q, $words);
    if (!$hits) return [['found' => false, 'answer' => '資料の中に、この質問に関係するところが見つかりませんでした。言葉を変えて聞いてみてください。', 'cites' => [], 'words' => $words], ''];
    // 新しい年の資料から並べる (AI が新しい情報を優先しやすいように)
    usort($hits, fn($a, $b) => ((int)$docs[$b[0]['d']]['year'] <=> (int)$docs[$a[0]['d']]['year']) ?: ($b[1] <=> $a[1]));
    $ctx = '';
    $ids = [];
    foreach ($hits as $k => [$pg, ]) {
        $id = 'S' . ($k + 1);
        $doc = $docs[$pg['d']];
        $ids[$id] = [kb_cite($doc, $pg), kb_link($doc, $pg)];
        $ctx .= "<資料 id=\"{$id}\" 出典=\"" . $ids[$id][0] . "\" 年=\"" . (int)$doc['year'] . "\">\n"
            . mb_substr((string)$pg['t'], 0, KB_PAGE_MAX) . "\n</資料>\n";
    }
    $schema = ['type' => 'object', 'additionalProperties' => false, 'required' => ['found', 'answer', 'used'],
        'properties' => [
            'found' => ['type' => 'boolean'],
            'answer' => ['type' => 'string'],
            'used' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]];
    $sys = 'あなたは養蜂家の質問に答える係です。答えの根拠にしてよいのは、利用者のメッセージの <資料> の中に書かれていることだけです。'
        . 'あなた自身の知識や、ほかのサイト・本の情報で補ってはいけません。資料に書かれていないことは「資料には見当たりません」と書きます。推測で答えません。' . "\n"
        . '書き方:' . "\n"
        . '- 日本語で、養蜂の初心者にもわかるように、やさしく短めに (目安 400 字まで、必要なら箇条書き)。' . "\n"
        . '- 根拠にした文のすぐ後ろに、その <資料> の「出典」をそのまま括弧で書く。例: …を行います（養蜂技術指導手引書2024「ミツバチヘギイタダニの生物学」（2024年）12ページ）。' . "\n"
        . '- 同じことについて複数の資料があり、内容が違うときは、年の新しい資料の内容で答え、古い資料と違う点があれば「以前の資料 (◯年) では…とされていました」と短く添える。' . "\n"
        . '- 薬剤の使い方・法律の手続きなどは、資料の年を書き、最新の情報はラベルや役所で確かめるよう一言添える。' . "\n"
        . '- used には、根拠にした <資料> の id (S1 など) を並べる。' . "\n"
        . '- 資料の中に答えがまったく無いときは found を false にし、answer に「資料には見当たりませんでした」と、関係しそうなことが書いてあればその出典を短く書く。';
    $user = "<資料の一覧>\n{$ctx}</資料の一覧>\n\n質問: {$q}";
    [$j, $err] = ai_json($sys, $user, $schema, 'medium', 90);
    if (!is_array($j)) return [null, $err];
    $cites = [];
    foreach ((array)($j['used'] ?? []) as $u) {
        $u = (string)$u;
        if (isset($ids[$u]) && !in_array($ids[$u], $cites, true)) $cites[] = $ids[$u];
    }
    return [['found' => (bool)($j['found'] ?? false), 'answer' => trim((string)($j['answer'] ?? '')), 'cites' => $cites, 'words' => $words], ''];
}

// 質問と答えの控え (本人の分だけ、新しい順に20件)
function kb_hist_path(string $uid): string { return account_dir($uid) . '/kb_hist.json'; }
function kb_hist(string $uid): array { $v = json_load(kb_hist_path($uid))['h'] ?? []; return is_array($v) ? $v : []; }
function kb_hist_add(string $uid, string $q, array $a): void
{
    json_update(kb_hist_path($uid), function (array $d) use ($q, $a) {
        $h = is_array($d['h'] ?? null) ? $d['h'] : [];
        array_unshift($h, ['id' => bin2hex(random_bytes(5)), 'at' => date('c'), 'q' => $q, 'a' => $a]);
        $d['h'] = array_slice($h, 0, 20);
        return $d;
    });
}
function kb_hist_del(string $uid, string $id): void
{
    json_update(kb_hist_path($uid), function (array $d) use ($id) {
        $d['h'] = $id === '*' ? [] : array_values(array_filter(is_array($d['h'] ?? null) ? $d['h'] : [], fn($x) => ($x['id'] ?? '') !== $id));
        return $d;
    });
}
