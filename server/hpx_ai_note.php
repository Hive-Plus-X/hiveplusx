<?php
// AI メモ・記録: 話し言葉の文を AI (Claude) で読み取り、HPX-note のメモ・作業記録にする共通のしくみ。
//   ・ai_note.php (HPX-note の画面「AIメモ・記録」。読み取った結果を画面で確かめてから記録する)
//   ・hpx_line_note.php (LINE公式アカウントに送った文。すぐ記録して LINE で返事をする)
//   から使う。hpx_site.php の後に読み込む。
//
//   読み取るもの: 蜂場・巣箱、メモ (内容・内検の項目・ダニ調査・タグ) か作業記録 (種類・時間・作業した人・採蜜量) か、日付
//   設定は config.php の $HPX_AI_KEY (Claude の API キー)・$HPX_AI_MODEL・$HPX_AI_DAY_MAX・$HPX_AI_TERMS。$HPX_AI_KEY が空なら使えない。
//   AI に送るのは、文と、その人の蜂場・巣箱の名前、作業の種類・タグの一覧、作業した人に選べる人の表示名だけ。
declare(strict_types=1);

const LN_TEXT_MAX = 2000;     // AI に渡す文の長さの上限

function ai_key_on(): bool
{
    global $HPX_AI_KEY;
    return trim((string)($HPX_AI_KEY ?? '')) !== '';
}

// 1人が1日に AI を使える回数を数える (画面と LINE の合計)。使えれば true
function ai_quota_use(string $uid): bool
{
    global $HPX_AI_DAY_MAX;
    $max = (int)($HPX_AI_DAY_MAX ?? 0) ?: 50;
    $ok = false;
    $today = date('Y-m-d');
    json_update(data_dir() . '/ai_note_count.json', function (array $c) use ($uid, $max, $today, &$ok) {
        $c = array_filter($c, fn($v) => is_array($v) && ($v[0] ?? '') === $today);
        $n = (int)($c[$uid][1] ?? 0);
        if ($n < $max) { $ok = true; $c[$uid] = [$today, $n + 1]; }
        return $c;
    });
    return $ok;
}
function ai_day_max(): int { global $HPX_AI_DAY_MAX; return (int)($HPX_AI_DAY_MAX ?? 0) ?: 50; }

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

// 作業した人に選べる人 (自分の蜂場と共有された蜂場を見られる人)。記号 P1.. => [アカウントid, 表示名]。チーム機能を使っていなければ自分だけ
function ln_people(array $site): array
{
    global $UID;
    $all = [$UID => 1];
    if (team_on()) foreach ($site['apiaries'] as $aid => $a) foreach (apiary_people((string)$a['_o'], (string)$aid) as $u => $r) $all[(string)$u] = 1;
    $out = [];
    $i = 0;
    foreach (array_keys($all) as $u) { $i++; $out['P' . $i] = [(string)$u, user_name((string)$u)]; }
    return $out;
}

function ln_work_types(): array
{
    $v = site_read_of($GLOBALS['UID'], 'work_types');
    $l = isset($v['types']) && is_array($v['types']) ? array_values(array_unique(array_filter(array_map(fn($t) => tag_clean((string)$t), $v['types']), fn($t) => $t !== ''))) : [];
    return $l ?: S_WORK_TYPES;
}

function ln_schema(array $codes, array $workTypes, array $memoTags, array $pcodes): array
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
        'workers' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $pcodes ?: ['']]],
        'people' => ['type' => 'integer'],
        'honey_kg' => ['type' => 'number'],
    ]);
    return $obj(['records' => ['type' => 'array', 'items' => $rec], 'not_a_record' => ['type' => 'boolean'], 'note' => ['type' => 'string']]);
}

function ln_system(): string
{
    return <<<TXT
あなたは養蜂の記録アプリ HPX-note の入力係です。養蜂家が話し言葉で書いた・送った文 (音声入力の誤変換を含むことがある) を読み、記録に分けて JSON で返します。

記録の種類 (kind):
- memo: 観察したこと・気づいたこと。内検 (巣箱を開けて中を見ること) の結果もここ。memo_type は 内検・ミツバチ (分蜂や入居など蜂の出来事)・トラブル (外敵・病気・盗難など)・開花植物・その他 から選ぶ。
- work: 作業した記録 (作業の種類と、かかった時間)。work_type は一覧から選ぶ。
- 内検をして時間も言っていたら、memo (memo_type=内検) を1つだけ作り、minutes にその時間を入れる (作業記録はアプリが作る)。
- 内検以外の作業 (給餌・採蜜・移動など) をしたと言っていたら work を作る。作業についての気づきは work の text に書く。
- 1つの文に別々の巣箱や別々の出来事があれば、記録を分ける。同じ巣箱の同じ内検は1つにまとめる。

作業した人 (workers):
- 人の一覧の記号を使う。「私」「自分」「僕」は (書いた本人) の人。名前は「さん」「くん」を外して、呼び方の違い・誤変換も意味が通れば合わせる。
- 同じ作業を何人かでしたら、その人たちを workers に入れ、people にその人数を入れる。
- 人によって作業や時間が違えば、人ごとに記録を分ける (例「Aさんが給餌30分、Bさんが内検1時間」→ 2つ)。
- 誰がしたか言っていなければ workers は空 (アプリが書いた本人にする)。一覧にいない人の名前は text に書き、workers には入れない。

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
- minutes はかかった分。「1時間半」なら 90。start・end は HH:MM (言っていなければ空)。people は作業した人数 (workers の数より多いこともある。分からなければ 0)。honey_kg は採蜜量 kg (無ければ 0)。
- 記録ではない文 (あいさつ・質問・お礼など) なら records を空にして not_a_record を true にする。
- note には、読み取れなかったことや迷ったことがあれば一言で書く (無ければ空)。

この人が前に確かめた例:
- 「この人の前の例」があれば、それはこの人が確かめて直した正しい記録。この人の書き方・巣箱や人の呼び方・記録の分け方は、例に合わせる。
- 例の「前に AI が読み違えた記録」と同じまちがいをしない。
- 例の日付「前日」などは、その文を送った日から数えたもの。
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
    $people = ln_people($site);
    $plist = '';
    foreach ($people as $c => [$u, $nm]) $plist .= $c . ': ' . $nm . ($u === $GLOBALS['UID'] ? ' (書いた本人)' : '') . "\n";
    $w = ['日', '月', '火', '水', '木', '金', '土'][(int)date('w')];
    $list = '';
    foreach ($targets as $c => $t) $list .= $c . ': ' . $t[2] . "\n";
    $user = "今日: " . date('Y-m-d') . " ({$w})\n\n対象の一覧:\n" . ($list ?: "(登録なし)\n")
        . "\n人の一覧:\n" . $plist
        . "\n作業の種類: " . implode('・', $workTypes) . "\nメモのタグ: " . implode('・', $memoTags)
        . "\n\n用語集 (正しい言葉: 化けやすい書き方):\n" . ln_terms_text()
        . (($ex = ln_learn_text($GLOBALS['UID'])) !== '' ? "\nこの人の前の例 (新しい順):\n" . $ex : '')
        . "\n送られた文:\n" . mb_substr($text, 0, LN_TEXT_MAX);
    [$j, $err] = ai_json(ln_system(), $user, ln_schema(array_keys($targets), $workTypes, $memoTags, array_keys($people)));
    if ($j === null) return [null, false, $err];
    $recs = [];
    foreach ((array)($j['records'] ?? []) as $r) if (is_array($r)) $recs[] = ln_clean($r, $targets, $workTypes, $people);
    return [$recs, !empty($j['not_a_record']), trim((string)($j['note'] ?? ''))];
}

// AI の答えを確かめて、記録の形にする (対象は蜂場id・巣箱id に戻す)
function ln_clean(array $r, array $targets, array $workTypes, array $people = []): array
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
    $workers = [];
    foreach ((array)($r['workers'] ?? []) as $c) if (isset($people[(string)$c])) $workers[] = $people[(string)$c][0];
    return [
        'kind' => $kind, 'a' => $t[0] ?? null, 'h' => $t[1] ?? '', 'cand' => $cand, 'date' => $date,
        'type' => $kind === 'memo' ? $mtype : '', 'text' => mb_substr(trim((string)($r['text'] ?? '')), 0, 2000),
        'tags' => array_values(array_unique(array_map('strval', (array)($r['tags'] ?? [])))),
        'inspect' => $mtype === '内検' ? $insp : [], 'mite' => $mite,
        'wtype' => $kind === 'work' ? $wtype : '', 'start' => $start, 'end' => $end, 'min' => $min > 1440 ? 0 : $min,
        'workers' => array_values(array_unique($workers)), 'people' => max(0, min(99, (int)($r['people'] ?? 0))), 'honey' => $honey > 0 && $honey <= 100000 ? round($honey, 1) : null,
    ];
}

// ====================================================================
// 書く・取り消す
// ====================================================================

// 記録を書く。返す値: 取り消し用の [[持ち主, 'memos'|'logs', id], ...]
//   $via: 'line' (LINE から) か 'ai' (画面の AIメモ・記録)
function ln_save(array $site, array $recs, string $via = 'line'): array
{
    global $UID;
    $done = [];
    foreach ($recs as $r) {
        $owner = apiary_owner($site, $r['a']);
        if ($owner === '') continue;
        site_use($owner);
        // 作業した人は、その蜂場を見られる人だけ (チーム機能を使っていなければ持たない。work.php と同じ)
        $can = team_on() ? apiary_people($owner, $r['a']) : [];
        $workers = array_values(array_filter((array)($r['workers'] ?? []), fn($u) => isset($can[$u])));
        $people = max(1, count($workers), (int)$r['people']);
        $base = ['apiary' => $r['a'], 'hive' => $r['h'], 'date' => $r['date'], 'via' => $via, 'updated' => time(), 'updated_by' => $UID, 'created' => time(), 'by' => $UID];
        if ($r['kind'] === 'work') {
            $id = new_id();
            $row = ['id' => $id] + $base + ['type' => $r['wtype'], 'workers' => $workers, 'start' => $r['start'], 'end' => $r['end'], 'minutes' => $r['min'],
                'people' => $people, 'note' => $r['text']];
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
            $row = ['id' => $workId] + $base + ['type' => '内検', 'workers' => $workers, 'start' => $r['start'], 'end' => $r['end'], 'minutes' => $r['min'], 'people' => $people, 'note' => $note, 'memo' => $id];
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
// ====================================================================
// 直した例から覚える (2026-11-13)
//   AI そのものは作り直さない。本人が直した結果を「直した例」としてアカウントの ai_learn.json に残し、
//   次に読み取るとき、その人の最近の例を見本として AI に渡す (その人の書き方・巣箱の呼び方に合ってくる)。
//   例は本人の分だけを使う (チームのほかの人の文は渡さない)。画面の「AIメモ・記録」で見る・消すことができる。
//   例を作るとき: 画面で「記録する」を押した (AI の読み取りと記録した内容が違えば「直した」)、LINE で聞き返しに答えた
// ====================================================================
const LN_LEARN_MAX = 100;     // 残す例の数
const LN_LEARN_USE = 6;       // AI に渡す例の数 (直したものを先に)

function ln_learn_path(string $uid): string { return account_dir($uid) . '/ai_learn.json'; }
function ln_learn_all(string $uid): array { $v = json_load(ln_learn_path($uid))['ex'] ?? []; return is_array($v) ? $v : []; }

// 記録1つを、名前で書いた1行にする (蜂場・巣箱の記号は変わることがあるので名前で残す)。日付は送った日からの差で
function ln_rec_line(array $site, array $r, string $sent): string
{
    $o = [];
    $o[] = '対象=' . ($r['a'] !== null ? target_label($site, (string)$r['a'], (string)$r['h']) : '(不明)');
    $dd = (int)round((strtotime($r['date']) - strtotime($sent)) / 86400);
    $o[] = '日付=' . ($dd === 0 ? '当日' : ($dd === -1 ? '前日' : ($dd < 0 ? (-$dd) . '日前' : $dd . '日後')));
    if ($r['kind'] === 'work') {
        $o[] = '作業記録=' . $r['wtype'];
        if ($r['min'] > 0) $o[] = '時間=' . $r['min'] . '分';
        if ($r['honey'] !== null) $o[] = '採蜜量=' . $r['honey'] . 'kg';
    } else {
        $o[] = 'メモ=' . ($r['type'] !== '' ? $r['type'] : '－');
        if ($r['min'] > 0) $o[] = '内検の時間=' . $r['min'] . '分';
        $chips = memo_chips($r);
        foreach ($r['inspect'] as $k => $v) if (!is_array($v)) $chips[] = M_INSPECT[$k][0] . ':' . $v;
        if ($r['mite']) $chips[] = 'ダニ調査:' . mite_text($r['mite']);
        if ($chips) $o[] = '項目=' . implode('・', $chips);
    }
    if (!empty($r['workers'])) $o[] = '作業した人=' . implode('・', array_map('user_name', $r['workers']));
    if ((int)$r['people'] > 0) $o[] = '人数=' . (int)$r['people'];
    if ($r['text'] !== '') $o[] = '文=' . mb_strimwidth($r['text'], 0, 80, '…');
    return implode(' / ', $o);
}

// 例を残す。$ai = AI が読み取った記録、$fixed = 本人が確かめて記録した記録
function ln_learn_add(string $uid, array $site, string $text, array $ai, array $fixed, string $via): void
{
    if (!is_uid($uid) || trim($text) === '') return;
    $sent = date('Y-m-d');
    $a = array_map(fn($r) => ln_rec_line($site, $r, $sent), $ai);
    $f = array_map(fn($r) => ln_rec_line($site, $r, $sent), $fixed);
    $ex = ['id' => new_id(), 'at' => time(), 'via' => $via, 'text' => mb_substr($text, 0, 500), 'ai' => $a, 'fixed' => $f, 'changed' => $a !== $f];
    json_update(ln_learn_path($uid), function (array $d) use ($ex) {
        $l = is_array($d['ex'] ?? null) ? $d['ex'] : [];
        $l[] = $ex;
        return ['ex' => array_slice($l, -LN_LEARN_MAX)];
    });
}

function ln_learn_del(string $uid, string $id): void
{
    json_update(ln_learn_path($uid), function (array $d) use ($id) {
        $l = is_array($d['ex'] ?? null) ? $d['ex'] : [];
        return ['ex' => $id === '*' ? [] : array_values(array_filter($l, fn($x) => ($x['id'] ?? '') !== $id))];
    });
}

// AI に渡す見本 (直した例を新しい順に。足りなければ直さなかった例も)
function ln_learn_text(string $uid): string
{
    $l = array_reverse(ln_learn_all($uid));
    $use = array_slice(array_merge(array_filter($l, fn($x) => !empty($x['changed'])), array_filter($l, fn($x) => empty($x['changed']))), 0, LN_LEARN_USE);
    $o = '';
    foreach ($use as $x) {
        $o .= "・文: " . (string)$x['text'] . "\n  正しい記録:\n";
        foreach ((array)$x['fixed'] as $line) $o .= "    - " . $line . "\n";
        if (!empty($x['changed']) && $x['ai']) {
            $o .= "  (前に AI が読み違えた記録:\n";
            foreach ((array)$x['ai'] as $line) $o .= "    - " . $line . "\n";
            $o .= "  )\n";
        }
    }
    return $o;
}

function ln_hm(int $min): string { return $min >= 60 ? intdiv($min, 60) . '時間' . ($min % 60 ? ($min % 60) . '分' : '') : $min . '分'; }

