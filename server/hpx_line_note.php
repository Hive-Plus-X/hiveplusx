<?php
// LINE で送ったメモ・作業記録を、AI (Claude) で読み取って HPX-note に記録する。line_webhook.php が読み込む。
//
//   LINE を連携した人が、LINE公式アカウントのトークに話し言葉で送る (スマホの音声入力で文字にしたものでよい)
//     例「山の蜂場の1番、女王いた、王台なし、糖液2リットルあげた、30分」
//   → AI が「どの蜂場・巣箱か」「メモ (内検など) か作業記録か」「作業の種類・時間」を読み取り、memos.json / works.json に書く
//   → 「記録しました」と LINE で返事をする (返事は月の通数に数えられない)。
//
//   ・巣箱が分からない・作業の時間が分からないときは、記録する前に LINE で聞き返す (番号や「30分」で答える)
//   ・「取り消し」と送ると、LINE で最後に記録したものを消す (30分以内)
//   ・「使い方」と送ると、送り方の例を返す
//   ・書き先は、ふつうの画面と同じ (蜂場の持ち主のフォルダ)。記録には via = 'line' を付ける
//
//   設定は config.php の $HPX_AI_KEY (Claude の API キー)。空なら、この機能は動かない (いままでどおり)。
//   AI に送るのは、送られた文と、その人の蜂場・巣箱の名前、作業の種類とタグの一覧だけ。
//
//   この file は hpx_site.php (S_AS_UID でその人として読む) の後に読み込む。
declare(strict_types=1);

const LN_PENDING_MIN = 30;    // 聞き返したことに答えられる時間 (分)
const LN_UNDO_MIN = 30;       // 「取り消し」できる時間 (分)
const LN_TEXT_MAX = 2000;     // AI に渡す文の長さの上限
const LN_ASK_MAX = 20;        // 聞き返すときに並べる候補の数の上限
// 養蜂の用語集: 正しい言葉 => 音声入力で化けやすい書き方の例。AI に渡し、文を読むときに直してもらう。
//   config.php の $HPX_AI_TERMS (同じ形の配列) で足せる。例 $HPX_AI_TERMS = ['ニホンミツバチ' => ['日本蜜蜂'], '待ち箱' => ['町箱', '待ちばこ']];
const LN_TERMS = [
    '内検' => ['内見', '内験', 'ない検', '内件'],
    '王台' => ['応対', '横隊', '王代', '大台'],
    '女王蜂' => ['女王ばち'],
    '雄蜂' => ['お蜂', 'おすばち'],
    '働き蜂' => ['働きばち'],
    '蜂児' => ['ほうじ', '法事', '邦字'],
    '有蓋蜂児' => ['ゆうがいほうじ', '有害法事', '有蓋法事'],
    '無王' => ['むおう', '夢王'],
    '巣脾' => ['すひ', '素肥', '巣ひ'],
    '巣板' => ['すいた', '酢板'],
    '巣門' => ['すもん', '巣紋', '素問'],
    '巣礎' => ['すそ', '裾'],
    '給餌' => ['休日', '急次', 'きゅうじ', '給仕'],
    '糖液' => ['当駅', '等液', 'とうえき'],
    '代用花粉' => ['代用か粉'],
    '貯蜜' => ['ちょみつ', '貯密'],
    '採蜜' => ['最密', 'さいみつ', '細密'],
    '分蜂' => ['分包', '文法', 'ぶんぽう', '分封'],
    '分割' => ['ぶんかつ'],
    '合同' => ['ごうどう'],
    '増段' => ['増援', 'ぞうだん', '雑談'],
    '減段' => ['げんだん', '厳談'],
    '継箱' => ['つぎばこ', '次箱'],
    '隔王板' => ['かくおうばん', '各王板'],
    '蜂数' => ['はちすう', '八数'],
    '弱群' => ['じゃくぐん', '若群'],
    '強群' => ['きょうぐん'],
    '盗蜂' => ['とうほう', '東方', '当方'],
    '逃去' => ['とうきょ', '東京'],
    'ヘギイタダニ' => ['へぎ板ダニ', 'へぎいただに'],
    'アピバール' => ['アピバル'],
    'チモバール' => ['チモバル'],
    'アピスタン' => [],
    'シュガーロール' => [],
    'スムシ' => ['巣虫'],
    'オオスズメバチ' => ['大雀蜂'],
    'キイロスズメバチ' => ['黄色雀蜂'],
    '燻煙器' => ['くんえんき', '燻製器'],
    '面布' => ['めんぷ', '綿布'],
    '待ち箱' => ['町箱', '待ちばこ'],
];
const LN_MEMO_TAGS_DEFAULT = ['分蜂', '入居', '大量死', 'スズメバチ', 'クマ'];   // memo.php の M_TAGS_DEFAULT と同じ

function ln_state_path(): string { return data_dir() . '/line_note_state.json'; }

// その人 (LINE の宛先) の状態: pending (聞き返し中) / last (最後に記録したもの) / day・n (今日 AI を使った回数)
function ln_state(string $to): array
{
    $s = json_load(ln_state_path())[$to] ?? [];
    return is_array($s) ? $s : [];
}
function ln_state_set(string $to, callable $fn): void
{
    json_update(ln_state_path(), function (array $all) use ($to, $fn) {
        $v = $fn(is_array($all[$to] ?? null) ? $all[$to] : []);
        if ($v) $all[$to] = $v; else unset($all[$to]);
        return $all;
    });
}

// ====================================================================
// Claude API (Messages API を HTTP で呼ぶ。さくらのレンタルサーバーに composer を入れずに使えるように)
// ====================================================================

// [JSON の配列 または null, エラーの説明]
function ai_json(string $system, string $user, array $schema): array
{
    global $HPX_AI_KEY, $HPX_AI_MODEL, $HPX_AI_API;
    $model = trim((string)($HPX_AI_MODEL ?? '')) ?: 'claude-opus-5-5';
    $body = [
        'model' => $model,
        'max_tokens' => 8000,
        'system' => $system,
        'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
        'messages' => [['role' => 'user', 'content' => $user]],
    ];
    $head = ['Content-Type: application/json', 'x-api-key: ' . trim((string)($HPX_AI_KEY ?? '')), 'anthropic-version: 2026-10-21'];
    // 安全のための判定で断られたとき、ほかのモデルでやり直す (Haiku には無い)
    if (!str_starts_with($model, 'claude-haiku')) {
        $body['fallbacks'] = 'default';
        $head[] = 'anthropic-beta: server-side-fallback-2026-07-01';
    }
    $url = rtrim((string)($HPX_AI_API ?? '') ?: 'https://api.anthropic.com', '/') . '/v1/messages';   // $HPX_AI_API は試験用
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => $head,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 45, CURLOPT_CONNECTTIMEOUT => 5]);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($res === false) return [null, '接続できませんでした (' . $err . ')'];
    $r = json_decode((string)$res, true);
    if ($code !== 200 || !is_array($r)) return [null, 'HTTP ' . $code . ' ' . mb_substr((string)($r['error']['message'] ?? $res), 0, 200)];
    if (($r['stop_reason'] ?? '') === 'refusal') return [null, '読み取りを断られました'];
    if (($r['stop_reason'] ?? '') === 'max_tokens') return [null, '文が長すぎました'];
    foreach ((array)($r['content'] ?? []) as $b) {
        if (($b['type'] ?? '') !== 'text') continue;
        $j = json_decode((string)($b['text'] ?? ''), true);
        if (is_array($j)) return [$j, ''];
    }
    return [null, '読み取った結果の形が違いました'];
}

// ====================================================================
// 読み取り
// ====================================================================

// 対象の一覧。AI には短い記号 (A1 / A1-H2) で渡し、記号から蜂場id・巣箱idに戻す
function ln_targets(array $site): array
{
    $t = [];
    $i = 0;
    foreach ($site['apiaries'] as $aid => $a) {
        $i++;
        $t['A' . $i] = [(string)$aid, '', (string)($a['name'] ?? '')];
        $j = 0;
        foreach (hives_of($site, (string)$aid) as $hid => $x) {
            $j++;
            $t['A' . $i . '-H' . $j] = [(string)$aid, (string)$hid, (string)($a['name'] ?? '') . ' / ' . (string)($x['name'] ?? '')];
        }
    }
    return $t;
}

function ln_work_types(): array
{
    $v = site_read_of($GLOBALS['UID'], 'work_types');
    $l = isset($v['types']) && is_array($v['types']) ? array_values(array_unique(array_filter(array_map(fn($t) => tag_clean((string)$t), $v['types']), fn($t) => $t !== ''))) : [];
    return $l ?: S_WORK_TYPES;
}

function ln_schema(array $codes, array $workTypes, array $memoTags): array
{
    $insp = [];
    foreach (M_INSPECT as $k => [, $opts, $multi]) {
        $insp[$k] = $multi ? ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $opts]]
            : ($opts ? ['type' => 'string', 'enum' => array_merge([''], $opts)] : ['type' => 'string']);
    }
    $obj = fn(array $p) => ['type' => 'object', 'properties' => $p, 'required' => array_keys($p), 'additionalProperties' => false];
    $rec = $obj([
        'kind' => ['type' => 'string', 'enum' => ['memo', 'work']],
        'target' => ['type' => 'string', 'enum' => array_merge([''], $codes)],
        'candidates' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $codes ?: ['']]],
        'date' => ['type' => 'string'],
        'memo_type' => ['type' => 'string', 'enum' => array_merge([''], S_MEMO_TYPES)],
        'text' => ['type' => 'string'],
        'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $memoTags ?: ['']]],
        'inspect' => $obj($insp),
        'mite_method' => ['type' => 'string', 'enum' => array_merge([''], M_MITE_METHODS)],
        'mite_bees' => ['type' => 'integer'],
        'mite_count' => ['type' => 'integer'],
        'work_type' => ['type' => 'string', 'enum' => array_merge([''], $workTypes)],
        'minutes' => ['type' => 'integer'],
        'start' => ['type' => 'string'],
        'end' => ['type' => 'string'],
        'people' => ['type' => 'integer'],
        'honey_kg' => ['type' => 'number'],
    ]);
    return $obj(['records' => ['type' => 'array', 'items' => $rec], 'not_a_record' => ['type' => 'boolean'], 'note' => ['type' => 'string']]);
}

function ln_system(): string
{
    return <<<TXT
あなたは養蜂の記録アプリ HPX-note の入力係です。養蜂家が LINE に話し言葉で送った文 (音声入力の誤変換を含むことがある) を読み、記録に分けて JSON で返します。

記録の種類 (kind):
- memo: 観察したこと・気づいたこと。内検 (巣箱を開けて中を見ること) の結果もここ。memo_type は 内検・ミツバチ (分蜂や入居など蜂の出来事)・トラブル (外敵・病気・盗難など)・開花植物・その他 から選ぶ。
- work: 作業した記録 (作業の種類と、かかった時間)。work_type は一覧から選ぶ。
- 内検をして時間も言っていたら、memo (memo_type=内検) を1つだけ作り、minutes にその時間を入れる (作業記録はアプリが作る)。
- 内検以外の作業 (給餌・採蜜・移動など) をしたと言っていたら work を作る。作業についての気づきは work の text に書く。
- 1つの文に別々の巣箱や別々の出来事があれば、記録を分ける。同じ巣箱の同じ内検は1つにまとめる。

対象 (target):
- 対象の一覧の記号を使う。巣箱が分かれば巣箱の記号 (A1-H2)、蜂場全体のことなら蜂場の記号 (A1)。
- 名前の言い方の違い・音声入力の誤変換 (「いちばん」=「1番」、漢字違い) は、意味が通れば合わせてよい。
- 蜂場が1つしかなく、巣箱の話でなければ、その蜂場にする。
- どれか決められないときは target を空にし、ありそうな候補を candidates に入れる (分からなければ candidates も空)。推測で決めない。

用語:
- 文は音声入力で文字にしたものが多い。用語集の「化けやすい書き方」や、音が近い別の言葉は、養蜂の話として意味が通るなら正しい用語に直して読む (例「応対なし」→「王台なし」、「休日した」→「給餌した」)。
- text に書くときも正しい用語に直す。

そのほか:
- date は YYYY-MM-DD。「昨日」「おととい」「月曜」などは今日の日付から計算する。言っていなければ今日。
- text は、話し言葉の言いよどみやくり返しを除いて短くまとめた文。事実・数字は変えない。内検の項目や時間に入れたことはくり返さなくてよい。
- inspect は memo_type が内検のときだけ、言っていた項目だけ入れる (ほかは空文字・空の配列)。frames は蜂数を「〇枚」の数字だけで (例 "5")。
- タグ (tags) は一覧にあるものだけ、当てはまるときだけ。
- ダニの調査 (シュガーロールなど) の数は mite_method・mite_bees (調べた蜂の数)・mite_count (ダニの数)。無ければ空文字と 0。
- minutes はかかった分。「1時間半」なら 90。start・end は HH:MM (言っていなければ空)。people は作業した人数 (分からなければ 0)。honey_kg は採蜜量 kg (無ければ 0)。
- 記録ではない文 (あいさつ・質問・お礼など) なら records を空にして not_a_record を true にする。
- note には、読み取れなかったことや迷ったことがあれば一言で書く (無ければ空)。
TXT;
}

function ln_terms_text(): string
{
    global $HPX_AI_TERMS;
    $all = LN_TERMS;
    foreach ((array)($HPX_AI_TERMS ?? []) as $k => $v) {
        $k = trim((string)$k);
        if ($k !== '' && mb_strlen($k) <= 30) $all[$k] = array_values(array_unique(array_merge($all[$k] ?? [], array_map('strval', (array)$v))));
    }
    $o = '';
    foreach ($all as $k => $v) $o .= $k . ($v ? ': ' . implode('、', array_slice($v, 0, 10)) : '') . "\n";
    return $o;
}

// AI で読み取る。[記録の配列 または null, 記録ではない文か, 説明]
function ln_parse(array $site, string $text): array
{
    $targets = ln_targets($site);
    $workTypes = ln_work_types();
    $memoTags = tag_list('memo_tags', LN_MEMO_TAGS_DEFAULT);
    $w = ['日', '月', '火', '水', '木', '金', '土'][(int)date('w')];
    $list = '';
    foreach ($targets as $c => $t) $list .= $c . ': ' . $t[2] . "\n";
    $user = "今日: " . date('Y-m-d') . " ({$w})\n\n対象の一覧:\n" . ($list ?: "(登録なし)\n")
        . "\n作業の種類: " . implode('・', $workTypes) . "\nメモのタグ: " . implode('・', $memoTags)
        . "\n\n用語集 (正しい言葉: 化けやすい書き方):\n" . ln_terms_text()
        . "\n送られた文:\n" . mb_substr($text, 0, LN_TEXT_MAX);
    [$j, $err] = ai_json(ln_system(), $user, ln_schema(array_keys($targets), $workTypes, $memoTags));
    if ($j === null) return [null, false, $err];
    $recs = [];
    foreach ((array)($j['records'] ?? []) as $r) if (is_array($r)) $recs[] = ln_clean($r, $targets, $workTypes);
    return [$recs, !empty($j['not_a_record']), trim((string)($j['note'] ?? ''))];
}

// AI の答えを確かめて、記録の形にする (対象は蜂場id・巣箱id に戻す)
function ln_clean(array $r, array $targets, array $workTypes): array
{
    $t = $targets[(string)($r['target'] ?? '')] ?? null;
    $cand = [];
    foreach ((array)($r['candidates'] ?? []) as $c) if (isset($targets[(string)$c])) $cand[] = [$targets[$c][0], $targets[$c][1]];
    $date = (string)($r['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false || $date > date('Y-m-d', time() + 86400) || $date < date('Y-m-d', time() - 400 * 86400)) $date = date('Y-m-d');
    $hm = fn($v) => preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', (string)$v) ? sprintf('%05s', (string)$v) : '';
    $insp = [];
    foreach (M_INSPECT as $k => [, $opts, $multi]) {
        $v = $r['inspect'][$k] ?? null;
        if ($multi) { $v = array_values(array_filter($opts, fn($o) => in_array($o, (array)$v, true))); if ($v) $insp[$k] = $v; continue; }
        $v = trim((string)$v);
        if ($opts ? in_array($v, $opts, true) : (is_numeric($v) && (float)$v >= 0 && (float)$v < 100)) $insp[$k] = $v;
    }
    $kind = ($r['kind'] ?? '') === 'work' ? 'work' : 'memo';
    $mtype = in_array((string)($r['memo_type'] ?? ''), S_MEMO_TYPES, true) ? (string)$r['memo_type'] : '';
    $mite = [];
    if ($mtype === '内検') {
        if (in_array((string)($r['mite_method'] ?? ''), M_MITE_METHODS, true)) $mite['method'] = (string)$r['mite_method'];
        if ((int)($r['mite_bees'] ?? 0) > 0) $mite['bees'] = min(100000, (int)$r['mite_bees']);
        if ((int)($r['mite_count'] ?? 0) > 0 || isset($mite['bees'])) $mite['mites'] = max(0, min(100000, (int)($r['mite_count'] ?? 0)));
    }
    $start = $hm($r['start'] ?? ''); $end = $hm($r['end'] ?? '');
    $min = work_minutes($start, $end, max(0, (int)($r['minutes'] ?? 0)));
    $wtype = (string)($r['work_type'] ?? '');
    if ($kind === 'work' && !in_array($wtype, $workTypes, true)) $wtype = in_array('その他', $workTypes, true) ? 'その他' : $workTypes[0];
    $honey = (float)($r['honey_kg'] ?? 0);
    return [
        'kind' => $kind, 'a' => $t[0] ?? null, 'h' => $t[1] ?? '', 'cand' => $cand, 'date' => $date,
        'type' => $kind === 'memo' ? $mtype : '', 'text' => mb_substr(trim((string)($r['text'] ?? '')), 0, 2000),
        'tags' => array_values(array_unique(array_map('strval', (array)($r['tags'] ?? [])))),
        'inspect' => $mtype === '内検' ? $insp : [], 'mite' => $mite,
        'wtype' => $kind === 'work' ? $wtype : '', 'start' => $start, 'end' => $end, 'min' => $min > 1440 ? 0 : $min,
        'people' => max(0, min(99, (int)($r['people'] ?? 0))), 'honey' => $honey > 0 && $honey <= 100000 ? round($honey, 1) : null,
    ];
}

// ====================================================================
// 聞き返し
// ====================================================================

// まだ決まっていないこと。[記録の番号, 'target' か 'minutes'] か null
function ln_need(array $recs): ?array
{
    foreach ($recs as $i => $r) {
        if ($r['a'] === null) return [$i, 'target'];
        if ($r['kind'] === 'work' && $r['min'] <= 0) return [$i, 'minutes'];
    }
    return null;
}

function ln_rec_label(array $site, array $r): string
{
    $what = $r['kind'] === 'work' ? '作業記録「' . $r['wtype'] . '」' : 'メモ' . ($r['type'] !== '' ? '「' . $r['type'] . '」' : '');
    $txt = $r['text'] !== '' ? ' ' . mb_strimwidth($r['text'], 0, 40, '…') : '';
    return ($r['a'] !== null ? target_label($site, $r['a'], $r['h']) . ' の' : '') . $what . $txt;
}

// 聞き返す文。候補 (番号で選ぶ) も返す
function ln_ask(array $site, array $recs, array $need): array
{
    [$i, $what] = $need;
    $r = $recs[$i];
    if ($what === 'minutes') {
        return ['', ln_rec_label($site, $r) . "\n\nかかった時間を教えてください (例「30分」「1時間半」)。\n時間なしでメモとして残すときは「メモ」、やめるときは「やめる」と送ってください。"];
    }
    $cand = $r['cand'];
    if (!$cand) {   // 候補が無ければ、蜂場と巣箱をすべて並べる
        foreach ($site['apiaries'] as $aid => $a) {
            $cand[] = [(string)$aid, ''];
            foreach (hives_of($site, (string)$aid) as $hid => $x) $cand[] = [(string)$aid, (string)$hid];
        }
    }
    $cand = array_slice($cand, 0, LN_ASK_MAX);
    $o = ln_rec_label($site, $r) . "\n\nどの蜂場・巣箱の記録ですか？ 番号で答えてください。\n";
    foreach ($cand as $n => [$a, $h]) $o .= ($n + 1) . '. ' . target_label($site, $a, $h) . "\n";
    return [$cand, $o . "\nやめるときは「やめる」と送ってください。"];
}

// 「30分」「1時間半」「90」などを分に。分からなければ 0
function ln_minutes_of(string $t): int
{
    $t = mb_convert_kana($t, 'n');
    if (preg_match('/^(\d+(?:\.\d+)?)\s*(分|ふん|ぷん)?$/u', $t, $m)) return (int)round((float)$m[1]);
    if (preg_match('/^(\d+(?:\.\d+)?)\s*時間\s*(半|(\d+)\s*分)?$/u', $t, $m)) return (int)round((float)$m[1] * 60) + (($m[2] ?? '') === '半' ? 30 : (int)($m[3] ?? 0));
    return 0;
}

// ====================================================================
// 書く・取り消す
// ====================================================================

// 記録を書く。返す値: 取り消し用の [[持ち主, 'memos'|'logs', id], ...]
function ln_save(array $site, array $recs): array
{
    global $UID;
    $done = [];
    foreach ($recs as $r) {
        $owner = apiary_owner($site, $r['a']);
        if ($owner === '') continue;
        site_use($owner);
        $base = ['apiary' => $r['a'], 'hive' => $r['h'], 'date' => $r['date'], 'via' => 'line', 'updated' => time(), 'updated_by' => $UID, 'created' => time(), 'by' => $UID];
        if ($r['kind'] === 'work') {
            $id = new_id();
            $row = ['id' => $id] + $base + ['type' => $r['wtype'], 'workers' => [], 'start' => $r['start'], 'end' => $r['end'], 'minutes' => $r['min'],
                'people' => max(1, $r['people']), 'note' => $r['text']];
            if ($r['honey'] !== null && mb_strpos($r['wtype'], '採蜜') !== false) $row['honey_kg'] = $r['honey'];
            site_update('works', function (array $w) use ($row) { $w['logs'] = is_array($w['logs'] ?? null) ? $w['logs'] : []; $w['logs'][] = $row; return $w; });
            $done[] = [$owner, 'logs', $id];
            continue;
        }
        $id = new_id();
        $workId = '';
        if ($r['type'] === '内検' && $r['min'] > 0) {   // memo.php の「作業記録にも追加する」と同じ
            $workId = new_id();
            $note = mb_strimwidth(trim(implode('・', memo_chips(['inspect' => $r['inspect'], 'tags' => $r['tags']])) . ' ' . $r['text']), 0, 200, '…');
            $row = ['id' => $workId] + $base + ['type' => '内検', 'start' => $r['start'], 'end' => $r['end'], 'minutes' => $r['min'], 'people' => max(1, $r['people']), 'note' => $note, 'memo' => $id];
            site_update('works', function (array $w) use ($row) { $w['logs'] = is_array($w['logs'] ?? null) ? $w['logs'] : []; $w['logs'][] = $row; return $w; });
            $done[] = [$owner, 'logs', $workId];
        }
        $row = ['id' => $id] + $base + ['type' => $r['type'], 'tags' => $r['tags'], 'text' => $r['text'], 'inspect' => $r['inspect'], 'mite' => $r['mite'], 'photos' => [], 'work' => $workId];
        site_update('memos', function (array $m) use ($row) { $m[] = $row; return $m; });
        $done[] = [$owner, 'memos', $id];
    }
    site_use($UID);
    return $done;
}

function ln_undo(array $done): int
{
    $n = 0;
    foreach ($done as [$owner, $kind, $id]) {
        if (!is_uid((string)$owner)) continue;
        if ($kind === 'memos') {
            site_update_of((string)$owner, 'memos', function (array $m) use ($id, &$n) {
                $c = count($m);
                $m = array_values(array_filter($m, fn($x) => ($x['id'] ?? '') !== $id));
                $n += $c - count($m);
                return $m;
            });
        } else {
            site_update_of((string)$owner, 'works', function (array $w) use ($id) {
                $w['logs'] = array_values(array_filter(is_array($w['logs'] ?? null) ? $w['logs'] : [], fn($x) => ($x['id'] ?? '') !== $id));
                return $w;
            });
        }
    }
    return $n;
}

// 記録したことの返事
function ln_saved_text(array $site, array $recs, string $note): string
{
    $o = "HPX-note に記録しました。\n";
    foreach ($recs as $r) {
        $d = date('n/j', strtotime($r['date']));
        $o .= "\n・{$d} " . target_label($site, $r['a'], $r['h']) . "\n  ";
        if ($r['kind'] === 'work') {
            $o .= '作業記録「' . $r['wtype'] . '」' . ln_hm($r['min']) . ($r['honey'] !== null && mb_strpos($r['wtype'], '採蜜') !== false ? ' 採蜜 ' . $r['honey'] . 'kg' : '');
        } else {
            $o .= 'メモ' . ($r['type'] !== '' ? '「' . $r['type'] . '」' : '') . ($r['type'] === '内検' && $r['min'] > 0 ? ' (作業記録も ' . ln_hm($r['min']) . ')' : '');
            $chips = memo_chips($r);
            foreach ($r['inspect'] as $k => $v) if (!is_array($v)) $chips[] = $k === 'frames' ? '蜂数 ' . $v . '枚群' : M_INSPECT[$k][0] . ' ' . $v;
            if ($r['mite']) $chips[] = 'ダニ調査 ' . mite_text($r['mite']);
            if ($chips) $o .= "\n  " . implode('・', $chips);
        }
        if ($r['text'] !== '') $o .= "\n  " . mb_strimwidth($r['text'], 0, 120, '…');
    }
    if ($note !== '') $o .= "\n\n(" . $note . ')';
    $o .= "\n\n違っていたら「取り消し」と送ってください (" . LN_UNDO_MIN . "分以内)。画面で直すこともできます。\n" . site_url('memo.php');
    return $o;
}

function ln_hm(int $min): string { return $min >= 60 ? intdiv($min, 60) . '時間' . ($min % 60 ? ($min % 60) . '分' : '') : $min . '分'; }

function ln_help(): string
{
    return "LINE で送った文を、HPX-note のメモや作業記録にします。\n話し言葉のままで大丈夫です (スマホのキーボードのマイクで声を文字にして送れます)。\n\n"
        . "送り方の例:\n・山の蜂場の1番、女王いた、卵あり、王台なし、30分\n・きのう里の蜂場で給餌、糖液を全部の巣箱に、1時間\n・2番の巣箱の前にスズメバチが10匹くらい\n\n"
        . "蜂場や巣箱が分からないときは聞き返します。\n「取り消し」… 最後に記録したものを消す (" . LN_UNDO_MIN . "分以内)\n「使い方」… この説明\n\n"
        . "記録した内容は HPX-note で見られます。\n" . site_url('memo.php');
}

// ====================================================================
// 1通を受け取って返事の文を返す (返事が要らなければ '')
// ====================================================================
function line_note_handle(string $to, string $raw): string
{
    global $UID, $HPX_AI_DAY_MAX;
    $site = site_load();
    $text = trim(mb_convert_kana($raw, 'asKV'));
    $cmd = preg_replace('/[\s。、!！?？]+/u', '', $text) ?? '';
    $st = ln_state($to);
    $pending = (is_array($st['pending'] ?? null) && (int)($st['pexp'] ?? 0) > time()) ? $st['pending'] : null;

    if (in_array($cmd, ['使い方', 'つかいかた', 'ヘルプ', 'help'], true)) return ln_help();
    if (in_array($cmd, ['取り消し', '取消', '取消し', 'とりけし', '取り消す', '消して'], true)) {
        $last = (is_array($st['last'] ?? null) && (int)($st['lexp'] ?? 0) > time()) ? $st['last'] : [];
        if (!$last) return "取り消せる記録がありません (LINE で記録してから " . LN_UNDO_MIN . "分以内だけ取り消せます)。\n前の記録は HPX-note の画面で消してください。";
        $n = ln_undo($last);
        ln_state_set($to, function (array $s) { unset($s['last'], $s['lexp']); return $s; });
        return "最後に LINE で記録したものを取り消しました。" . ($n === 0 && count($last) > 0 ? "\n(画面ですでに消されていたかもしれません)" : '');
    }
    if ($pending && in_array($cmd, ['やめる', 'やめ', 'キャンセル', '中止', 'いいえ'], true)) {
        ln_state_set($to, function (array $s) { unset($s['pending'], $s['pexp']); return $s; });
        return '記録をやめました。';
    }

    // 聞き返したことへの答え
    if ($pending) {
        $recs = (array)$pending['recs'];
        [$i, $what] = (array)$pending['need'];
        $ok = false;
        if ($what === 'target' && preg_match('/^\d{1,2}$/', mb_convert_kana($cmd, 'n'))) {
            $c = ((array)$pending['cand'])[(int)mb_convert_kana($cmd, 'n') - 1] ?? null;
            if (is_array($c) && isset($site['apiaries'][$c[0]]) && ($c[1] === '' || ($site['hives'][$c[1]]['apiary'] ?? '') === $c[0])) {
                // 同じく決まっていない記録には、同じ対象を使う
                foreach ($recs as $k => $r) if ($r['a'] === null && ($k === $i || $r['cand'] === $recs[$i]['cand'])) { $recs[$k]['a'] = $c[0]; $recs[$k]['h'] = $c[1]; }
                $ok = true;
            }
        } elseif ($what === 'minutes') {
            if (in_array($cmd, ['メモ', 'めも', 'なし', '時間なし'], true)) {
                $r = $recs[$i];
                $recs[$i] = array_merge($r, ['kind' => 'memo', 'type' => 'その他', 'text' => trim($r['wtype'] . ' ' . $r['text']), 'wtype' => '']);
                $ok = true;
            } elseif (($m = ln_minutes_of($cmd)) > 0 && $m <= 1440) {
                $recs[$i]['min'] = $m;
                $ok = true;
            }
        }
        if ($ok) return ln_finish($to, $site, $recs, (string)($pending['note'] ?? ''));
        if (preg_match('/^\d{1,2}$/', mb_convert_kana($cmd, 'n')) || ($what === 'minutes' && mb_strlen($cmd) <= 8)) {
            return $what === 'target' ? '一覧の番号で答えてください。やめるときは「やめる」と送ってください。' : '「30分」のように時間で答えてください。時間なしなら「メモ」、やめるときは「やめる」と送ってください。';
        }
        // それ以外は新しい記録として読む (前の聞き返しはやめる)
        ln_state_set($to, function (array $s) { unset($s['pending'], $s['pexp']); return $s; });
    }

    if (!$site['apiaries']) return "まだ蜂場が登録されていません。HPX-note で蜂場と巣箱を登録してから送ってください。\n" . site_url('apiary.php');

    // AI を使う回数の上限 (1人1日)
    $max = (int)($HPX_AI_DAY_MAX ?? 0) ?: 50;
    $today = date('Y-m-d');
    $n = ((string)($st['day'] ?? '') === $today) ? (int)($st['n'] ?? 0) : 0;
    if ($n >= $max) return "今日は LINE で記録できる回数 ({$max}回) を使い切りました。HPX-note の画面で入力してください。\n" . site_url('memo.php');
    ln_state_set($to, function (array $s) use ($today, $n) { $s['day'] = $today; $s['n'] = $n + 1; return $s; });

    [$recs, $notRec, $note] = ln_parse($site, $text);
    if ($recs === null) {
        @file_put_contents(data_dir() . '/line_note.log', date('c') . " {$UID} AI FAILED {$note}\n", FILE_APPEND);
        return "ごめんなさい、いま読み取りができませんでした。少し待ってもう一度送るか、HPX-note の画面で入力してください。\n" . site_url('memo.php');
    }
    @file_put_contents(data_dir() . '/line_note.log', date('c') . " {$UID} records=" . count($recs) . "\n", FILE_APPEND);
    if (!$recs) {
        return $notRec ? "メモや作業記録として送るときは、どの蜂場・巣箱で、何を見た・何をしたかを書いてください。\n送り方の例は「使い方」と送ると出ます。"
            : "記録にする内容が読み取れませんでした。" . ($note !== '' ? "\n(" . $note . ')' : '') . "\n送り方の例は「使い方」と送ると出ます。";
    }
    return ln_finish($to, $site, $recs, $note);
}

// 決まっていないことがあれば聞き返し、全部決まれば書いて返事をする
function ln_finish(string $to, array $site, array $recs, string $note): string
{
    $need = ln_need($recs);
    if ($need) {
        [$cand, $msg] = ln_ask($site, $recs, $need);
        ln_state_set($to, function (array $s) use ($recs, $need, $cand, $note) {
            $s['pending'] = ['recs' => $recs, 'need' => $need, 'cand' => $cand, 'note' => $note];
            $s['pexp'] = time() + LN_PENDING_MIN * 60;
            return $s;
        });
        return $msg;
    }
    $done = ln_save($site, $recs);
    ln_state_set($to, function (array $s) use ($done) {
        unset($s['pending'], $s['pexp']);
        $s['last'] = $done;
        $s['lexp'] = time() + LN_UNDO_MIN * 60;
        return $s;
    });
    return ln_saved_text($site, $recs, $note);
}
