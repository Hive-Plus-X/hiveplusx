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

require_once __DIR__ . '/hpx_ai_note.php';

const LN_PENDING_MIN = 30;    // 聞き返したことに答えられる時間 (分)
const LN_UNDO_MIN = 30;       // 「取り消し」できる時間 (分)
const LN_ASK_MAX = 20;        // 聞き返すときに並べる候補の数の上限

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

function ln_saved_text(array $site, array $recs, string $note): string
{
    $o = "HPX-note に記録しました。\n";
    foreach ($recs as $r) {
        $d = date('n/j', strtotime($r['date']));
        $o .= "\n・{$d} " . target_label($site, $r['a'], $r['h']) . "\n  ";
        if ($r['kind'] === 'work') {
            $o .= '作業記録「' . $r['wtype'] . '」' . ln_hm($r['min']) . ($r['workers'] ? ' ' . implode('・', array_map('user_name', $r['workers'])) : '') . ($r['honey'] !== null && mb_strpos($r['wtype'], '採蜜') !== false ? ' 採蜜 ' . $r['honey'] . 'kg' : '');
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
    global $UID;
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

    // AI を使う回数の上限 (1人1日。画面の AIメモ・記録と合わせて)
    if (!ai_quota_use($UID)) return "今日 AI で記録できる回数 (" . ai_day_max() . "回) を使い切りました。HPX-note の画面で入力してください。\n" . site_url('memo.php');

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
