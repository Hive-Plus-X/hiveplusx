<?php
// LINE公式アカウントの Webhook (LINE Developers の「Webhook URL」に、このページの https:// の URL を入れる)。
//   ログインは要らない。LINE から届いたことを署名 (X-Line-Signature と $HPX_LINE_SECRET) で確かめる。
//   ・トークに6けたの連携コードが届いた → そのアカウントに LINE を連携する (返事を送る。返事は月の通数に数えられない)
//   ・ブロックされた (unfollow) → 連携を外す
//   それ以外のメッセージには何もしない (LINE公式アカウントのチャットで、人が返事できるように)。
declare(strict_types=1);
require __DIR__ . '/hpx_auth.php';

$raw = (string)file_get_contents('php://input');
$sig = (string)($_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '');
if (!line_on() || $sig === '' || !hash_equals(base64_encode(hash_hmac('sha256', $raw, trim((string)($HPX_LINE_SECRET ?? "")), true)), $sig)) {
    http_response_code(400);
    exit('bad request');
}
$in = json_decode($raw, true);
foreach ((array)($in['events'] ?? []) as $ev) {
    if (!is_array($ev)) continue;
    $to = (string)($ev['source']['userId'] ?? '');
    if ($to === '' || ($ev['source']['type'] ?? '') !== 'user') continue;
    $type = (string)($ev['type'] ?? '');
    if ($type === 'unfollow') { line_unlink('', $to); continue; }
    if ($type !== 'message' || ($ev['message']['type'] ?? '') !== 'text') continue;
    $text = preg_replace('/\s+/u', '', mb_convert_kana((string)($ev['message']['text'] ?? ''), 'n')) ?? '';
    if (!preg_match('/^\d{6}$/', $text)) continue;   // 連携コードではない普通のメッセージ
    $uid = line_code_use($text);
    $rt = (string)($ev['replyToken'] ?? '');
    if ($uid === '' || is_demo(user_get($uid))) {
        line_reply($rt, "連携コードが正しくないか、有効時間 (" . L_CODE_MIN . "分) が過ぎています。\nHPX-note のマイアカウントで、もう一度コードを出してください。");
        continue;
    }
    line_link_set($uid, $to);
    line_reply($rt, user_name($uid) . " さんの HPX-note と連携しました。\nこれから、電柵・巣箱・センサなどのお知らせが LINE にも届きます。\n\n受け取り方 (メールと LINE の両方 / LINE だけ) は、マイアカウントで変えられます。");
}
http_response_code(200);
echo 'ok';
