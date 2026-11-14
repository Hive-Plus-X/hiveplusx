<?php
// LINE公式アカウントの Webhook (LINE Developers の「Webhook URL」に、このページの https:// の URL を入れる)。
//   ログインは要らない。LINE から届いたことを署名 (X-Line-Signature と $HPX_LINE_SECRET) で確かめる。
//   ・トークに6けたの連携コードが届いた → そのアカウントに LINE を連携する (返事を送る。返事は月の通数に数えられない)
//   ・ブロックされた (unfollow) → 連携を外す
//   ・連携した人のそれ以外の文 → AI で読み取って HPX-note のメモ・作業記録にする (hpx_line_note.php。config に $HPX_AI_KEY があるときだけ)
//   ・連携した人の写真 → 手書きの記録として AI で文字を読み取り、確かめてもらってから記録する (同じく $HPX_AI_KEY があるときだけ)
//   それ以外 (連携していない人・AI を使わない設定) のメッセージには何もしない (LINE公式アカウントのチャットで、人が返事できるように)。
declare(strict_types=1);
require __DIR__ . '/hpx_auth.php';

$raw = (string)file_get_contents('php://input');
$sig = (string)($_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '');
if (!line_on() || $sig === '' || !hash_equals(base64_encode(hash_hmac('sha256', $raw, trim((string)($HPX_LINE_SECRET ?? "")), true)), $sig)) {
    http_response_code(400);
    exit('bad request');
}
$in = json_decode($raw, true);
$notes = [];   // AI で記録する文: [LINE の宛先, アカウントid, 文, replyToken, 写真のメッセージid (文なら '')]
foreach ((array)($in['events'] ?? []) as $ev) {
    if (!is_array($ev)) continue;
    $to = (string)($ev['source']['userId'] ?? '');
    if ($to === '' || ($ev['source']['type'] ?? '') !== 'user') continue;
    $type = (string)($ev['type'] ?? '');
    if ($type === 'unfollow') { line_unlink('', $to); continue; }
    if ($type !== 'message') continue;
    if (($ev['message']['type'] ?? '') === 'image') {   // 写真 (手書きの記録)。LINE のサーバーにある写真だけ (contentProvider が line)
        $uid = (string)(json_load(line_users_path())[$to] ?? '');
        $id = (string)($ev['webhookEventId'] ?? '');
        if ($uid !== '' && user_get($uid) !== null && !is_demo(user_get($uid)) && line_note_on($uid) && ($ev['message']['contentProvider']['type'] ?? 'line') === 'line' && !line_event_seen($id)) {
            $notes[] = [$to, $uid, '', (string)($ev['replyToken'] ?? ''), (string)($ev['message']['id'] ?? '')];
        }
        continue;
    }
    if (($ev['message']['type'] ?? '') !== 'text') continue;
    $text = preg_replace('/\s+/u', '', mb_convert_kana((string)($ev['message']['text'] ?? ''), 'n')) ?? '';
    if (!preg_match('/^\d{6}$/', $text)) {   // 連携コードではない普通のメッセージ
        $uid = (string)(json_load(line_users_path())[$to] ?? '');
        $id = (string)($ev['webhookEventId'] ?? '');
        if ($uid !== '' && user_get($uid) !== null && !is_demo(user_get($uid)) && line_note_on($uid) && !line_event_seen($id)) {
            $notes[] = [$to, $uid, (string)($ev['message']['text'] ?? ''), (string)($ev['replyToken'] ?? ''), ''];
        }
        continue;
    }
    $uid = line_code_use($text);
    $rt = (string)($ev['replyToken'] ?? '');
    if ($uid === '' || is_demo(user_get($uid))) {
        line_reply($rt, "連携コードが正しくないか、有効時間 (" . L_CODE_MIN . "分) が過ぎています。\nHPX-note のマイアカウントで、もう一度コードを出してください。");
        continue;
    }
    line_link_set($uid, $to);
    line_reply($rt, user_name($uid) . " さんの HPX-note と連携しました。\nこれから、電柵・巣箱・センサなどのお知らせが LINE にも届きます。\n\n受け取り方 (メールと LINE の両方 / LINE だけ) は、マイアカウントで変えられます。");
}
if (!$notes) {
    http_response_code(200);
    echo 'ok';
    exit;
}

// AI で読み取るのは数秒かかるので、先に LINE へ「受け取った」と答えてから続ける
$hasPhoto = in_array(true, array_map(fn($n) => $n[4] !== '', $notes), true);
foreach ($notes as [$to]) line_api('/v2/bot/chat/loading/start', ['chatId' => $to, 'loadingSeconds' => $hasPhoto ? 60 : 20]);   // トークに「…」を出す
ignore_user_abort(true);
@set_time_limit($hasPhoto ? 240 : 90);   // 写真は、文字を読む + 記録に分ける の2回 AI を使うので長めに
http_response_code(200);
header('Content-Type: text/plain');
header('Content-Length: 2');
header('Connection: close');
echo 'ok';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
else { while (ob_get_level() > 0) ob_end_flush(); flush(); }

// hpx_site.php は「ログインした人」として読み書きするので、その人 (1回に1人) として読み込む。
//   ふつう LINE は1人の1通ごとに送ってくる。まれに別の人の分が一緒に来たら、その人にはもう一度送ってもらう
define('S_AS_UID', $notes[0][1]);
require __DIR__ . '/hpx_site.php';
require __DIR__ . '/hpx_line_note.php';
foreach ($notes as [$to, $uid, $text, $rt, $img]) {
    $msg = $uid !== S_AS_UID ? "混み合っていて記録できませんでした。もう一度送ってください。"
        : ($img !== '' ? line_photo_handle($to, $img) : line_note_handle($to, $text));
    if ($msg === '') continue;
    $msg = mb_strlen($msg) > L_TEXT_MAX ? mb_substr($msg, 0, L_TEXT_MAX) . '…' : $msg;
    // 返事 (reply) が間に合わなかったときだけ push で送る (push は月の通数に数えられる)
    $r = $rt !== '' ? line_api('/v2/bot/message/reply', ['replyToken' => $rt, 'messages' => [['type' => 'text', 'text' => $msg]]]) : [false];
    if (!$r[0]) line_push($to, $msg);
}
ln_rules_update(S_AS_UID);   // 聞き返しに答えて直したところがあれば、AI が「覚えたこと」にまとめる (返事の後で)
