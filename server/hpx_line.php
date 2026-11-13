<?php
// LINE公式アカウント (Messaging API) でお知らせを送る。hpx_alert.php が読み込む。
//
//   お知らせメール (電柵・HPX-Core が止まった・巣箱の警報・タスク) を、LINE を連携した人には LINE にも送る。
//   メールの種類ごとのオン/オフ (マイアカウント) は LINE にも同じように効く (止めた種類は LINE にも送らない)。
//
//   連携のしかた: マイアカウントの「LINE でお知らせを受け取る」で6けたの連携コードを出し、
//     LINE公式アカウントを友だち追加して、そのトークにコードを送る → line_webhook.php が受け取り、アカウントに LINE の宛先を保存する。
//   保存する場所: アカウントの account.json の line = ['to' => LINE の宛先 (userId), 'at' => 連携した時刻, 'only' => LINE だけにするか]
//     と、data/line_users.json (LINE の宛先 → アカウントid。ブロックされたときに外すため)。
//
//   設定は config.php の $HPX_LINE_TOKEN (チャネルアクセストークン・長期) と $HPX_LINE_SECRET (チャネルシークレット)。
//   どちらかが空なら LINE の機能は出さない (いままでどおりメールだけ)。
//
//   注意: LINE の無料プランで送れるのは月200通まで (友だち1人に1通 = 1通と数える)。
//     送れなかったとき (通数の上限など) は、LINE だけにしている人にもメールで送る。結果は mail.log に「LINE sent / LINE FAILED」で残る。
declare(strict_types=1);

const L_CODE_MIN = 30;        // 連携コードの有効時間 (分)
const L_TEXT_MAX = 4500;      // 1通の文字数の上限 (LINE は 5000 文字まで)

function line_on(): bool
{
    global $HPX_LINE_TOKEN, $HPX_LINE_SECRET;
    return trim((string)($HPX_LINE_TOKEN ?? '')) !== '' && trim((string)($HPX_LINE_SECRET ?? '')) !== '';
}

// LINE で送った文を AI で読み取って HPX-note に記録する機能 (hpx_line_note.php) が使えるか。config の $HPX_AI_KEY (Claude の API キー) があれば使える
function ai_on(): bool
{
    global $HPX_AI_KEY;
    return line_on() && trim((string)($HPX_AI_KEY ?? '')) !== '';
}

// その人が LINE で記録する機能を使うか (マイアカウントで止められる。初めはオン)
function line_note_on(string $uid): bool
{
    $l = line_link($uid);
    return ai_on() && $l && ($l['note'] ?? true) !== false;
}

// 友だち追加の URL (config の $HPX_LINE_ADD_URL)
function line_add_url(): string
{
    global $HPX_LINE_ADD_URL;
    $u = trim((string)($HPX_LINE_ADD_URL ?? ''));
    return preg_match('#^https://#', $u) ? $u : '';
}

// Messaging API を呼ぶ。[成功したか, HTTPの番号, 返事]
function line_api(string $path, array $body): array
{
    global $HPX_LINE_TOKEN, $HPX_LINE_API;
    $url = rtrim((string)($HPX_LINE_API ?? '') ?: 'https://api.line.me', '/') . $path;   // $HPX_LINE_API は試験用 (ふつうは書かない)
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    $head = ['Content-Type: application/json', 'Authorization: Bearer ' . trim((string)($HPX_LINE_TOKEN ?? ''))];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $head,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) return [false, 0, $err];
    } else {
        $res = @file_get_contents($url, false, stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $head),
            'content' => $json, 'timeout' => 10, 'ignore_errors' => true]]));
        $code = preg_match('#^HTTP/\S+\s+(\d+)#', (string)($http_response_header[0] ?? ''), $m) ? (int)$m[1] : 0;
        if ($res === false) return [false, 0, '接続できませんでした'];
    }
    return [$code === 200, $code, (string)$res];
}

function line_text(string $subject, string $body): string
{
    $t = trim($subject . "\n\n" . trim($body));
    return mb_strlen($t) > L_TEXT_MAX ? mb_substr($t, 0, L_TEXT_MAX) . '…' : $t;
}

// 1人に送る (push。月の通数に数えられる)
function line_push(string $to, string $text): array
{
    if (!line_on() || $to === '') return [false, 0, 'LINE が設定されていません'];
    $r = line_api('/v2/bot/message/push', ['to' => $to, 'messages' => [['type' => 'text', 'text' => $text]]]);
    if (!$r[0] && $r[1] === 429) $r[2] = '今月の送信数の上限に達しました (' . $r[2] . ')';
    return $r;
}

// 届いたメッセージへの返事 (reply。月の通数に数えられない)
function line_reply(string $replyToken, string $text): void
{
    if (line_on() && $replyToken !== '') line_api('/v2/bot/message/reply', ['replyToken' => $replyToken, 'messages' => [['type' => 'text', 'text' => $text]]]);
}

// アカウントの LINE 連携。連携していなければ []
function line_link(string $uid): array
{
    $l = account_load($uid)['line'] ?? null;
    return is_array($l) && (string)($l['to'] ?? '') !== '' ? $l : [];
}

// 同じイベントが2回届いた (LINE の再送) か。初めてなら覚えて false
function line_event_seen(string $id): bool
{
    if ($id === '') return false;
    $seen = false;
    json_update(data_dir() . '/line_events.json', function (array $e) use ($id, &$seen) {
        $now = time();
        $e = array_filter($e, fn($t) => (int)$t > $now - 86400);
        if (isset($e[$id])) $seen = true;
        $e[$id] = $now;
        return $e;
    });
    return $seen;
}

function line_users_path(): string { return data_dir() . '/line_users.json'; }
function line_codes_path(): string { return data_dir() . '/line_codes.json'; }

// 連携コード (6けた) を出す。同じアカウントの前のコードは消す
function line_code_issue(string $uid): array
{
    $code = '';
    $exp = time() + L_CODE_MIN * 60;
    json_update(line_codes_path(), function (array $c) use ($uid, $exp, &$code) {
        $now = time();
        $c = array_filter($c, fn($v) => is_array($v) && (int)($v[1] ?? 0) > $now && (string)($v[0] ?? '') !== $uid);
        do { $code = (string)random_int(100000, 999999); } while (isset($c[$code]));
        $c[$code] = [$uid, $exp];
        return $c;
    });
    return [$code, $exp];
}

// 連携コードを使う。正しければアカウントid (コードは1回で消える)
function line_code_use(string $code): string
{
    $uid = '';
    json_update(line_codes_path(), function (array $c) use ($code, &$uid) {
        $v = $c[$code] ?? null;
        if (is_array($v) && (int)($v[1] ?? 0) > time()) $uid = (string)$v[0];
        unset($c[$code]);
        return $c;
    });
    return $uid !== '' && user_get($uid) !== null ? $uid : '';
}

// アカウントに LINE の宛先を保存する。同じ LINE をほかのアカウントに連携していたら、そちらは外す
function line_link_set(string $uid, string $to): void
{
    $old = '';
    json_update(line_users_path(), function (array $m) use ($uid, $to, &$old) {
        $old = (string)($m[$to] ?? '');
        foreach ($m as $k => $v) if ((string)$v === $uid) unset($m[$k]);   // このアカウントの前の LINE
        $m[$to] = $uid;
        return $m;
    });
    if ($old !== '' && $old !== $uid) account_update($old, function (array $a) { unset($a['line']); return $a; });
    account_update($uid, function (array $a) use ($to) {
        $a['line'] = ['to' => $to, 'at' => time(), 'only' => !empty($a['line']['only'])] + (isset($a['line']['note']) ? ['note' => (bool)$a['line']['note']] : []);
        return $a;
    });
}

// 連携を外す ($uid か、LINE の宛先 $to のどちらかで)
function line_unlink(string $uid = '', string $to = ''): void
{
    json_update(line_users_path(), function (array $m) use (&$uid, $to) {
        if ($uid === '' && $to !== '') $uid = (string)($m[$to] ?? '');
        foreach ($m as $k => $v) if ((string)$v === $uid || (string)$k === $to) unset($m[$k]);
        return $m;
    });
    if ($uid !== '' && user_get($uid) !== null) account_update($uid, function (array $a) { unset($a['line']); return $a; });
}

// メールの送り先から、LINE にも送る人を決める。
//   $emails: お知らせメールの送り先 (種類のオン/オフで止めた人は、もう入っていない)
//   返す値: [LINE の宛先 => アカウントid, メールで送る送り先 (LINE だけの人のアドレスは外す), LINE だけの人 => その人のアドレス]
//   アドレスは、アカウントの通知先 (無ければログインのメールアドレス) で持ち主を見分ける。
function line_split(array $emails): array
{
    if (!line_on() || !$emails) return [[], $emails, []];
    $want = array_fill_keys(array_map('email_norm', $emails), 1);
    $lineTo = [];
    $onlyMail = [];      // LINE だけの人: uid => [アドレス]
    $keep = [];          // LINE だけではない人が使っているアドレス
    foreach (users_all() as $uid => $u) {
        $uid = (string)$uid;
        if (($u['status'] ?? '') !== 'active' || is_demo($u)) continue;
        $mine = array_values(array_filter(array_map('email_norm', explode(',', account_notify($uid))), fn($e) => isset($want[$e])));
        if (!$mine) continue;
        $l = line_link($uid);
        if ($l) $lineTo[(string)$l['to']] = $uid;
        if ($l && !empty($l['only'])) $onlyMail[$uid] = $mine;
        else foreach ($mine as $e) $keep[$e] = 1;
    }
    $drop = [];
    foreach ($onlyMail as $list) foreach ($list as $e) if (!isset($keep[$e])) $drop[$e] = 1;
    $mail = array_values(array_filter($emails, fn($e) => !isset($drop[email_norm($e)])));
    return [$lineTo, $mail, $onlyMail];
}
