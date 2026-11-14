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
const LN_TASK_TAGS_DEFAULT = ['内検', '採蜜', '給餌', 'ダニ検査', '薬剤処理（入出）'];   // work.php の W_TAGS_DEFAULT と同じ (タスクのタグ)
const LN_MEMO_TAGS_DEFAULT = ['分蜂', '入居', '大量死', 'スズメバチ', 'クマ'];   // memo.php の M_TAGS_DEFAULT と同じ

// ====================================================================
// Claude API (Messages API を HTTP で呼ぶ。さくらのレンタルサーバーに composer を入れずに使えるように)
// ====================================================================

// [JSON の配列 または null, エラーの説明]
//   $user: 文 (string)、または写真と文を並べた content の配列 (手書きの記録の写真を読むとき)
function ai_json(string $system, string|array $user, array $schema, string $effort = 'low', int $timeout = 45): array
{
    global $HPX_AI_KEY, $HPX_AI_MODEL, $HPX_AI_API;
    $model = trim((string)($HPX_AI_MODEL ?? '')) ?: 'claude-opus-5-5';
    $body = [
        'model' => $model,
        'max_tokens' => 8000,
        'system' => $system,
        'output_config' => ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => $schema]],
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
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5]);
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

function ln_task_tags(): array { return tag_list('work_tags', LN_TASK_TAGS_DEFAULT); }

function ln_schema(array $codes, array $workTypes, array $memoTags, array $pcodes, array $taskTags = []): array
{
    $insp = [];
    foreach (M_INSPECT as $k => [, $opts, $multi]) {
        $insp[$k] = $multi ? ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $opts]]
            : ($opts ? ['type' => 'string', 'enum' => array_merge([''], $opts)] : ['type' => 'string']);
    }
    $obj = fn(array $p) => ['type' => 'object', 'properties' => $p, 'required' => array_keys($p), 'additionalProperties' => false];
    $rec = $obj([
        'kind' => ['type' => 'string', 'enum' => ['memo', 'work', 'task']],
        'target' => ['type' => 'string', 'enum' => array_merge([''], $codes)],
        'candidates' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $codes ?: ['']]],
        'date' => ['type' => 'string'],
        'memo_type' => ['type' => 'string', 'enum' => array_merge([''], S_MEMO_TYPES)],
        'text' => ['type' => 'string'],
        'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $memoTags ?: ['']]],
        'inspect' => $obj($insp),
        'flower_plant' => ['type' => 'string'],
        'flower_stage' => ['type' => 'string', 'enum' => array_merge([''], S_FLOWER_STAGES)],
        'flower_visit' => ['type' => 'string', 'enum' => array_merge([''], S_FLOWER_VISITS)],
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
        'task_title' => ['type' => 'string'],
        'task_due' => ['type' => 'string'],
        'task_tags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $taskTags ?: ['']]],
    ]);
    return $obj(['records' => ['type' => 'array', 'items' => $rec], 'not_a_record' => ['type' => 'boolean'], 'note' => ['type' => 'string']]);
}

function ln_system(): string
{
    $plants = implode('、', S_FLOWER_PLANTS);
    return <<<TXT
あなたは養蜂の記録アプリ HPX-note の入力係です。養蜂家が話し言葉で書いた・送った文 (音声入力の誤変換を含むことがある) を読み、記録に分けて JSON で返します。

記録の種類 (kind):
- memo: 観察したこと・気づいたこと。内検 (巣箱を開けて中を見ること) の結果もここ。memo_type は 内検・ミツバチ (分蜂や入居など蜂の出来事)・トラブル (外敵・病気・盗難など)・開花植物・その他 から選ぶ。開花植物のときは flower_plant に植物名 (「アカシア」は「ニセアカシア」、「菜の花」は「ナタネ (菜の花)」のように、次の一覧にあればその書き方で: {$plants})、flower_stage に咲き具合、flower_visit にミツバチが花に来ている様子 (言っていなければ空)。
- work: 作業した記録 (作業の種類と、かかった時間)。work_type は一覧から選ぶ。
- 内検をして時間も言っていたら、memo (memo_type=内検) を1つだけ作り、minutes にその時間を入れる (作業記録はアプリが作る)。
- task: これからやること・予定 (「来週の土曜に給餌する」「15日までに継箱を用意」「次の内検で王台を見る」など)。task_title にやることを短く、task_due に期限 (YYYY-MM-DD。「来週の土曜」「月末まで」などは今日から計算。言っていなければ空)、task_tags にタスクのタグ一覧から当てはまるもの、workers に担当する人 (言っていなければ空)、text に補足。date は今日でよい。済んだ作業 (過去形) は task にしない。
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
- minutes はかかった分。「1時間半」なら 90。start・end は HH:MM (言っていなければ空)。メモ (kind=memo) でも、出来事の時刻 (「朝9時ごろスズメバチが来た」など) が分かれば start に入れる。people は作業した人数 (workers の数より多いこともある。分からなければ 0)。honey_kg は採蜜量 kg (無ければ 0)。
- 記録ではない文 (あいさつ・質問・お礼など) なら records を空にして not_a_record を true にする。
- note には、読み取れなかったことや迷ったことがあれば一言で書く (無ければ空)。

この人について覚えたこと・前に確かめた例:
- 「この人について覚えたこと」は、この人が前に直したことからまとめた決まり。必ず守る (用語集やほかの説明より優先)。
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
    $memoTags = tag_list('memo_tags', LN_MEMO_TAGS_DEFAULT, S_MEMO_EVENTS);
    $people = ln_people($site);
    $taskTags = ln_task_tags();
    $plist = '';
    foreach ($people as $c => [$u, $nm]) $plist .= $c . ': ' . $nm . ($u === $GLOBALS['UID'] ? ' (書いた本人)' : '') . "\n";
    $w = ['日', '月', '火', '水', '木', '金', '土'][(int)date('w')];
    $list = '';
    foreach ($targets as $c => $t) $list .= $c . ': ' . $t[2] . "\n";
    $user = "今日: " . date('Y-m-d') . " ({$w})\n\n対象の一覧:\n" . ($list ?: "(登録なし)\n")
        . "\n人の一覧:\n" . $plist
        . "\n作業の種類: " . implode('・', $workTypes) . "\nメモのタグ: " . implode('・', $memoTags) . "\nタスクのタグ: " . implode('・', $taskTags)
        . "\n\n用語集 (正しい言葉: 化けやすい書き方):\n" . ln_terms_text()
        . (($ru = ln_rules_text($GLOBALS['UID'])) !== '' ? "\nこの人について覚えたこと (必ず守る):\n" . $ru : '')
        . (($ex = ln_learn_text($GLOBALS['UID'], $text)) !== '' ? "\nこの人の前の例 (今回の文と似ているもの):\n" . $ex : '')
        . "\n送られた文:\n" . mb_substr($text, 0, LN_TEXT_MAX);
    [$j, $err] = ai_json(ln_system(), $user, ln_schema(array_keys($targets), $workTypes, $memoTags, array_keys($people), $taskTags));
    if ($j === null) return [null, false, $err];
    $recs = [];
    foreach ((array)($j['records'] ?? []) as $r) if (is_array($r)) $recs[] = ln_clean($r, $targets, $workTypes, $people, $taskTags);
    return [$recs, !empty($j['not_a_record']), trim((string)($j['note'] ?? ''))];
}

// AI の答えを確かめて、記録の形にする (対象は蜂場id・巣箱id に戻す)
function ln_clean(array $r, array $targets, array $workTypes, array $people = [], array $taskTags = []): array
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
    $kind = in_array($r['kind'] ?? '', ['work', 'task'], true) ? (string)$r['kind'] : 'memo';
    $due = (string)($r['task_due'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || strtotime($due) === false || $due < date('Y-m-d', time() - 30 * 86400) || $due > date('Y-m-d', time() + 800 * 86400)) $due = '';
    $mtype = $kind === 'memo' && in_array((string)($r['memo_type'] ?? ''), S_MEMO_TYPES, true) ? (string)$r['memo_type'] : '';
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
        'flower' => $mtype === '開花植物' ? array_filter(['plant' => mb_substr(trim((string)($r['flower_plant'] ?? '')), 0, 40),
            'stage' => in_array($r['flower_stage'] ?? '', S_FLOWER_STAGES, true) ? (string)$r['flower_stage'] : '',
            'visit' => in_array($r['flower_visit'] ?? '', S_FLOWER_VISITS, true) ? (string)$r['flower_visit'] : ''], fn($v) => $v !== '') : [],
        'wtype' => $kind === 'work' ? $wtype : '', 'start' => $start, 'end' => $end, 'min' => $min > 1440 ? 0 : $min,
        'title' => $kind === 'task' ? mb_substr(trim((string)($r['task_title'] ?? '')), 0, 200) : '', 'due' => $kind === 'task' ? $due : '',
        'ttags' => $kind === 'task' ? array_values(array_intersect($taskTags, array_map('strval', (array)($r['task_tags'] ?? [])))) : [],
        'workers' => array_values(array_unique($workers)), 'people' => max(0, min(99, (int)($r['people'] ?? 0))), 'honey' => $honey > 0 && $honey <= 100000 ? round($honey, 1) : null,
    ];
}

// ====================================================================
// 書く・取り消す
// ====================================================================

// 記録を書く。返す値: 取り消し用の [[持ち主, 'memos'|'logs', id], ...]
//   $via: 'line' (LINE から) か 'ai' (画面の AIメモ・記録)
//   $photo: 手書きの記録の写真 (ln_photo_path)。'' でなければ、メモにその写真を付ける (元の紙を後で見られるように)
function ln_save(array $site, array $recs, string $via = 'line', string $photo = ''): array
{
    global $UID;
    $done = [];
    if ($photo !== '' && !is_file($photo)) $photo = '';
    foreach ($recs as $r) {
        $owner = apiary_owner($site, $r['a']);
        if ($owner === '') continue;
        site_use($owner);
        // 作業した人は、その蜂場を見られる人だけ (チーム機能を使っていなければ持たない。work.php と同じ)
        $can = team_on() ? apiary_people($owner, $r['a']) : [];
        $workers = array_values(array_filter((array)($r['workers'] ?? []), fn($u) => isset($can[$u])));
        $people = max(1, count($workers), (int)$r['people']);
        $base = ['apiary' => $r['a'], 'hive' => $r['h'], 'date' => $r['date'], 'via' => $via, 'updated' => time(), 'updated_by' => $UID, 'created' => time(), 'by' => $UID];
        if ($r['kind'] === 'task') {   // work.php の todo_save と同じ形。担当者 (assignees) は workers から、メールのお知らせは画面の初期と同じくオン
            $id = new_id();
            $row = ['id' => $id, 'apiary' => $r['a'], 'hive' => $r['h'], 'tags' => (array)($r['ttags'] ?? []), 'title' => (string)($r['title'] ?? ''), 'due' => (string)($r['due'] ?? ''),
                'assignees' => $workers, 'assignee' => '', 'notify' => (bool)($r['notify'] ?? true), 'note' => $r['text'], 'via' => $via,
                'updated' => time(), 'updated_by' => $UID, 'created' => time(), 'by' => $UID, 'done' => false];
            site_update('works', function (array $w) use ($row) { $w['todos'] = is_array($w['todos'] ?? null) ? $w['todos'] : []; $w['todos'][] = $row; return $w; });
            $done[] = [$owner, 'todos', $id];
            continue;
        }
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
        $row = ['id' => $id] + $base + ['time' => (string)($r['start'] ?? ''), 'type' => $r['type'], 'tags' => $r['tags'], 'text' => $r['text'], 'inspect' => $r['inspect'], 'mite' => $r['mite'], 'flower' => (array)($r['flower'] ?? []), 'photos' => $photo !== '' ? ln_photo_attach($owner, $id, $photo) : [], 'work' => $workId];
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
            site_update_of((string)$owner, 'memos', function (array $m) use ($id, $owner, &$n) {
                $c = count($m);
                foreach ($m as $x) if (($x['id'] ?? '') === $id) foreach ((array)($x['photos'] ?? []) as $p) {   // 付けた写真も消す (memo.php の memo_photo_del と同じ)
                    if (!preg_match('/^[0-9a-f]+_[0-9a-f]+$/', (string)$p)) continue;
                    @unlink(site_dir_of((string)$owner) . '/memo_photos/' . $p . '.jpg');
                    @unlink(site_dir_of((string)$owner) . '/memo_photos/' . $p . '_t.jpg');
                }
                $m = array_values(array_filter($m, fn($x) => ($x['id'] ?? '') !== $id));
                $n += $c - count($m);
                return $m;
            });
        } else {
            $k = $kind === 'todos' ? 'todos' : 'logs';
            site_update_of((string)$owner, 'works', function (array $w) use ($id, $k) {
                $w[$k] = array_values(array_filter(is_array($w[$k] ?? null) ? $w[$k] : [], fn($x) => ($x['id'] ?? '') !== $id));
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
    if ($r['kind'] === 'task') {
        $o[] = 'タスク=' . (string)($r['title'] ?? '');
        $due = (string)($r['due'] ?? '');
        if ($due !== '') { $dd2 = (int)round((strtotime($due) - strtotime($sent)) / 86400); $o[] = '期限=' . ($dd2 === 0 ? '当日' : ($dd2 > 0 ? $dd2 . '日後' : (-$dd2) . '日前')) . ' (' . ['日', '月', '火', '水', '木', '金', '土'][(int)date('w', strtotime($due))] . '曜)'; }
        if (!empty($r['ttags'])) $o[] = 'タグ=' . implode('・', $r['ttags']);
        if (!empty($r['workers'])) $o[] = '担当=' . implode('・', array_map('user_name', $r['workers']));
        if ($r['text'] !== '') $o[] = '文=' . mb_strimwidth($r['text'], 0, 80, '…');
        return implode(' / ', $o);
    }
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
    if (str_starts_with($text, LN_PHOTO_HEAD)) $text = '(手書きの写真から) ' . substr($text, strlen(LN_PHOTO_HEAD));   // 長い前置きは残さない
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

// 文の似ている度合い (0〜1)。2文字ずつの組 (bigram) がどれだけ重なるか。空白・記号は除く
function ln_bigrams(string $t): array
{
    $t = preg_replace('/[\s、。,.!！?？・「」()（）]+/u', '', mb_convert_kana($t, 'asKV')) ?? '';
    $g = [];
    $n = mb_strlen($t);
    for ($i = 0; $i < $n - 1; $i++) $g[mb_substr($t, $i, 2)] = 1;
    if ($n === 1) $g[$t] = 1;
    return $g;
}
function ln_similar(array $a, array $b): float
{
    if (!$a || !$b) return 0.0;
    $both = count(array_intersect_key($a, $b));
    return $both / (count($a) + count($b) - $both);
}

// AI に渡す見本: 覚えた例 (最大 LN_LEARN_MAX 件) から、今回の文と似ているものを選ぶ。
//   点数 = 似ている度合い + 直した例なら 0.15 + 新しいほど少し (最大 0.05)。上から LN_LEARN_USE 件
function ln_learn_pick(string $uid, string $text): array
{
    $l = ln_learn_all($uid);
    $q = ln_bigrams($text);
    $n = count($l);
    $sc = [];
    foreach ($l as $i => $x) $sc[$i] = ln_similar($q, ln_bigrams((string)($x['text'] ?? ''))) + (!empty($x['changed']) ? 0.15 : 0) + ($n > 1 ? 0.05 * $i / ($n - 1) : 0.05);
    arsort($sc);
    return array_map(fn($i) => $l[$i], array_slice(array_keys($sc), 0, LN_LEARN_USE));
}

function ln_learn_text(string $uid, string $text = ''): string
{
    $o = '';
    foreach (ln_learn_pick($uid, $text) as $x) {
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

// ====================================================================
// 覚えたこと (決まりの一覧)。直した例から AI が短い決まりにまとめて、アカウントの ai_rules.json にためる。
//   毎回すべて AI に渡す (例は似たものしか渡さないので、古いまちがいも決まりで防ぐ)。画面で見る・直す・足す・消す
// ====================================================================
const LN_RULES_MAX = 100;     // ためる決まりの数
const LN_RULE_LEN = 100;      // 1つの決まりの長さ

function ln_rules_path(string $uid): string { return account_dir($uid) . '/ai_rules.json'; }
function ln_rules_all(string $uid): array { $v = json_load(ln_rules_path($uid))['rules'] ?? []; return is_array($v) ? $v : []; }

// 決まりを足す・直す・消す。$id が空なら足す、$rule が空なら消す ('*' ならすべて消す)
function ln_rule_set(string $uid, string $id, string $rule, string $by = 'me'): void
{
    $rule = mb_substr(trim(preg_replace('/\s+/u', ' ', $rule) ?? ''), 0, LN_RULE_LEN);
    json_update(ln_rules_path($uid), function (array $d) use ($id, $rule, $by) {
        $l = is_array($d['rules'] ?? null) ? $d['rules'] : [];
        if ($id === '*') return ['rules' => []];
        if ($id === '') {
            if ($rule === '' || in_array($rule, array_column($l, 'rule'), true)) return ['rules' => $l];
            $l[] = ['id' => new_id(), 'rule' => $rule, 'at' => time(), 'by' => $by];
        } else {
            foreach ($l as $k => $x) if (($x['id'] ?? '') === $id) { if ($rule === '') unset($l[$k]); else $l[$k] = ['rule' => $rule, 'at' => time(), 'by' => 'me'] + $x; }
        }
        return ['rules' => array_slice(array_values($l), -LN_RULES_MAX)];
    });
}

function ln_rules_text(string $uid): string
{
    $o = '';
    foreach (ln_rules_all($uid) as $x) $o .= '・' . (string)($x['rule'] ?? '') . "\n";
    return $o;
}

// まだ決まりにしていない「直した例」から、AI に決まりを作ってもらう (1回に3件まで)。
//   画面で記録した後と、LINE で返事をした後に呼ぶ。利用料は直したときだけかかる
function ln_rules_update(string $uid): int
{
    if (!ai_key_on() || !is_uid($uid)) return 0;
    $todo = [];
    foreach (ln_learn_all($uid) as $x) if (!empty($x['changed']) && empty($x['ruled'])) $todo[] = $x;
    $todo = array_slice($todo, -3);
    if (!$todo) return 0;
    $ex = '';
    foreach ($todo as $x) {
        $ex .= "・文: " . (string)$x['text'] . "\n  AI の読み取り:\n";
        foreach ((array)$x['ai'] as $line) $ex .= "    - " . $line . "\n";
        $ex .= "  本人が直した正しい記録:\n";
        foreach ((array)$x['fixed'] as $line) $ex .= "    - " . $line . "\n";
    }
    $system = "あなたは養蜂の記録アプリ HPX-note の入力係の先輩です。AI が話し言葉の文を読み取ってまちがえ、本人が直した例を見て、次から同じまちがいをしないための決まりを短く書きます。\n"
        . "- 決まりは、この人の書き方・呼び方についての具体的なもの (例「『いちばん』は 山の蜂場 / 1番 のこと」「『鈴木くん』は 鈴木 のこと」「『えさやり』は作業記録の 給餌」「時間を言わない内検は 30分」)。\n"
        . "- 1つの決まりは " . LN_RULE_LEN . " 文字以内。今ある決まりと同じこと・その例だけの偶然 (日付や数字そのもの) は書かない。\n"
        . "- 文のまとめ方を少し直しただけなど、決まりにすることが無ければ rules は空にする。";
    $user = "今ある決まり:\n" . (ln_rules_text($uid) ?: "(なし)\n") . "\n直した例:\n" . $ex;
    $schema = ['type' => 'object', 'properties' => ['rules' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['rules'], 'additionalProperties' => false];
    [$j, $err] = ai_json($system, $user, $schema);
    @file_put_contents(data_dir() . '/line_note.log', date('c') . " {$uid} rules " . ($j === null ? 'FAILED ' . $err : count((array)($j['rules'] ?? []))) . "\n", FILE_APPEND);
    if ($j === null) return 0;
    $n = 0;
    foreach (array_slice((array)($j['rules'] ?? []), 0, 5) as $r) if (trim((string)$r) !== '') { ln_rule_set($uid, '', (string)$r, 'ai'); $n++; }
    $ids = array_column($todo, 'id');
    json_update(ln_learn_path($uid), function (array $d) use ($ids) {
        $l = is_array($d['ex'] ?? null) ? $d['ex'] : [];
        foreach ($l as &$x) if (in_array($x['id'] ?? '', $ids, true)) $x['ruled'] = true;
        unset($x);
        return ['ex' => $l];
    });
    return $n;
}

// ====================================================================
// 手書きの記録の写真を読む (2026-11-14)
//   ノート・記録用紙・カレンダーなどに手書きした記録を写真に撮って送る (LINE か、画面の「AIメモ・記録」)
//   → AI (Claude) が写真の文字を読み取って文にする (読みにくい所は 〔王台?〕 のように印を付け、一覧にする)
//   → その文を、ふつうの AIメモ・記録と同じしくみ (ln_parse) で記録に分ける
//   → 利用者が確かめて直してから記録する (LINE は「OK」と送るまで記録しない)。記録したメモには、元の写真を付ける。
//   写真は その人の accounts/<uid>/ai_photo.jpg に1枚だけ置く (次の写真で上書き。記録・やめるで消す)。
//   AI に送るのは、写真と、その人の蜂場・巣箱の名前・作業の種類 (読み取りの手がかり)、用語集だけ。
// ====================================================================
const LN_PHOTO_PX = 1568;               // AI に送る写真の長い辺 (これより大きくしても読み取りは良くならない)
const LN_PHOTO_BYTES = 20 * 1024 * 1024; // 受け取る写真の上限 (縮める前)
const LN_PHOTO_KEEP_H = 24;             // 写真と読み取った文を残す時間 (時間)
const LN_PHOTO_HEAD = "(手書きの記録の写真から読み取った文です。〔 〕? は読みにくかった所 (いちばんありそうな読み)。日付が書いてあればその日の記録。予定と書いていなければ、すんだ記録として読む)\n";

function ln_photo_path(string $uid): string { return account_dir($uid) . '/ai_photo.jpg'; }
function ln_photo_info_path(string $uid): string { return account_dir($uid) . '/ai_photo.json'; }

// 受け取った写真 (JPEG・PNG・WebP) を、AI に送る大きさの JPEG にしてその人の所に置く
function ln_photo_store(string $uid, string $src): bool
{
    $info = @getimagesize($src);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || filesize($src) > LN_PHOTO_BYTES) return false;
    @mkdir(account_dir($uid), 0755, true);
    @unlink(ln_photo_info_path($uid));
    return img_resize_jpeg($src, ln_photo_path($uid), LN_PHOTO_PX, 85);
}

// 写真と読み取った文を消す
function ln_photo_clear(string $uid): void
{
    @unlink(ln_photo_path($uid));
    @unlink(ln_photo_info_path($uid));
}

// 前に読み取った写真の文 (LINE で送った写真を画面で直すとき)。無い・古ければ null
function ln_photo_info(string $uid): ?array
{
    $j = json_load(ln_photo_info_path($uid));
    if (!is_array($j) || (string)($j['text'] ?? '') === '' || (int)($j['at'] ?? 0) < time() - LN_PHOTO_KEEP_H * 3600 || !is_file(ln_photo_path($uid))) return null;
    return $j;
}

// 記録したメモに写真を付ける (memo.php と同じ置き場所・大きさ)。付けた写真の名前の配列を返す
function ln_photo_attach(string $owner, string $memoId, string $photo): array
{
    $dir = site_dir_of($owner) . '/memo_photos';
    @mkdir($dir, 0755, true);
    $name = $memoId . '_' . bin2hex(random_bytes(3));
    if (!img_resize_jpeg($photo, $dir . '/' . $name . '.jpg', 1280, 82)) return [];
    img_resize_jpeg($dir . '/' . $name . '.jpg', $dir . '/' . $name . '_t.jpg', 320, 78);
    return [$name];
}

function ln_photo_system(): string
{
    return <<<TXT
あなたは、養蜂家が手書きした記録 (ノート・記録用紙・カレンダー・ホワイトボードなど) の写真から、文字を読み取る係です。

- 写真に書いてあることを、そのまま文字にして text に入れる。言葉を足したり、まとめたり、意味を推測して書きかえたりしない。
- 行の区切りは改行で残す。表になっている記録は「項目: 値」のように、どの欄の値か分かる形で1行ずつ書く。日付ごと・巣箱ごとのまとまりが分かるようにする。
- 日付・数字・巣箱の番号・記号 (○ × ✓ など) は特に正確に。○ や ✓ は「あり」、× は「なし」のように意味が分かるように書いてよい (例「王台: ×(なし)」)。
- 読みにくい・自信がない所は、いちばんありそうな読みを 〔 〕で囲み、最後に ? を付ける (例「〔王台?〕なし」「〔3?〕番」)。まったく読めない所は〔?〕と書く。推測で決めつけない。
- 〔 〕を付けた所はすべて unsure にも入れる: read に 〔 〕の中の読み、maybe にほかに考えられる読み (無ければ空)、where にどこに書いてあったか (「2行目」「11/3 の欄」など短く)。
- 養蜂の言葉 (用語集) や、この人の蜂場・巣箱の名前・作業の種類 (手がかり) にある言葉は、字の形が近ければそれだと読んでよい。ただし自信がなければ 〔 〕を付ける。
- 消してある (線で消した) 文字は読まない。印刷された見出し・罫線の数字などは、記録の欄名として必要なときだけ書く。
- 手書きの文字が無い・養蜂の記録ではない写真なら、no_text を true にし、text は空にする。
- note には、写真が暗い・ぼやけている・切れているなど、読み取りについて伝えたいことがあれば一言で (無ければ空)。
TXT;
}

// 置いてある写真を AI で読み取る。[['text' => 文, 'unsure' => [[read, maybe[], where], ...], 'note' => ''] または null, エラーの説明]
//   読み取れた文は ai_photo.json に残す (LINE で送った写真を、画面で直すときに使う)
function ln_photo_read(array $site, string $uid): array
{
    $path = ln_photo_path($uid);
    if (!is_file($path)) return [null, '写真がありません'];
    $hint = '';
    foreach (ln_targets($site) as $t) $hint .= $t[2] . "\n";
    $user = [
        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode((string)file_get_contents($path))]],
        ['type' => 'text', 'text' => "今日: " . date('Y-m-d') . "\n\nこの人の蜂場・巣箱の名前 (手がかり):\n" . ($hint ?: "(登録なし)\n")
            . "\n作業の種類 (手がかり): " . implode('・', ln_work_types())
            . "\n\n用語集 (正しい言葉: 化けやすい書き方):\n" . ln_terms_text()
            . "\nこの写真の手書きの記録を読み取ってください。"],
    ];
    $obj = fn(array $p) => ['type' => 'object', 'properties' => $p, 'required' => array_keys($p), 'additionalProperties' => false];
    $schema = $obj([
        'text' => ['type' => 'string'],
        'unsure' => ['type' => 'array', 'items' => $obj(['read' => ['type' => 'string'], 'maybe' => ['type' => 'array', 'items' => ['type' => 'string']], 'where' => ['type' => 'string']])],
        'no_text' => ['type' => 'boolean'],
        'note' => ['type' => 'string'],
    ]);
    // 手書きは読み違えやすいので、ふつうの読み取りより少しよく考えさせる (medium)
    [$j, $err] = ai_json(ln_photo_system(), $user, $schema, 'medium', 90);
    @file_put_contents(data_dir() . '/line_note.log', date('c') . " {$uid} photo " . ($j === null ? 'FAILED ' . $err : 'chars=' . mb_strlen((string)($j['text'] ?? ''))) . "\n", FILE_APPEND);
    if ($j === null) return [null, $err];
    $text = mb_substr(trim((string)($j['text'] ?? '')), 0, LN_TEXT_MAX);
    $unsure = [];
    foreach (array_slice((array)($j['unsure'] ?? []), 0, 30) as $u) {
        if (!is_array($u) || trim((string)($u['read'] ?? '')) === '') continue;
        $unsure[] = ['read' => mb_substr(trim((string)$u['read']), 0, 40), 'maybe' => array_slice(array_values(array_filter(array_map(fn($v) => mb_substr(trim((string)$v), 0, 40), (array)($u['maybe'] ?? [])), fn($v) => $v !== '')), 0, 4),
            'where' => mb_substr(trim((string)($u['where'] ?? '')), 0, 40)];
    }
    $out = ['text' => !empty($j['no_text']) ? '' : $text, 'unsure' => $unsure, 'note' => mb_substr(trim((string)($j['note'] ?? '')), 0, 200)];
    if ($out['text'] !== '') file_put_contents(ln_photo_info_path($uid), json_encode($out + ['at' => time()], JSON_UNESCAPED_UNICODE));
    return [$out, ''];
}

// 読みにくかった所の一覧 (LINE の返事・画面の注意書き)
function ln_unsure_lines(array $unsure): array
{
    $o = [];
    foreach ($unsure as $u) {
        $o[] = '「' . $u['read'] . '」' . ($u['maybe'] ? ' (' . implode('・', $u['maybe']) . ' かも)' : '') . ($u['where'] !== '' ? ' … ' . $u['where'] : '');
    }
    return $o;
}

// 写真から読んだ文を、記録に分ける (ln_parse) ときに渡す形にする
function ln_photo_src(string $text, string $fix = ''): string
{
    return LN_PHOTO_HEAD . $text . ($fix !== '' ? "\n\n(本人からの直し。写真の読み取りより、こちらを優先する)\n" . $fix : '');
}

function ln_hm(int $min): string { return $min >= 60 ? intdiv($min, 60) . '時間' . ($min % 60 ? ($min % 60) . '分' : '') : $min . '分'; }

