<?php
// プッシュ通知 (Web Push)。メール・LINE と同じお知らせを、HPX-note を入れたスマホ・パソコンにも出す。
//   ・受け取る端末は、マイアカウントの「プッシュ通知」で登録する (account.json の push に、端末ごとの宛先を入れる)
//   ・送るのは hpx_alert_flush() (hpx_alert.php)。お知らせメールの送り先に入っている人 (種類のオン/オフで止めた人は入っていない) の端末へ送る
//   ・送る中身は暗号化する (RFC 8291 aes128gcm)。送り元の名乗り (VAPID、RFC 8292) の鍵は、初めて使うときに data/push_vapid.json に作る
//   ・PHP の openssl (楕円曲線 P-256)・hash_hkdf を使う。追加のライブラリは要らない
// iPhone は iOS 16.4 以上で、ホーム画面に追加した HPX-note からだけ受け取れる。
declare(strict_types=1);

const P_MAX_DEVICES = 10;   // 1つのアカウントで登録できる端末の数
const P_TTL = 86400;        // 端末の電源が切れているとき、届けるのを待つ秒数

function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

function push_on(): bool { return function_exists('openssl_pkey_derive') && function_exists('hash_hkdf'); }

// 送り元の鍵 ['pub' => 公開鍵 (65バイト、b64u), 'pem' => 秘密鍵]。無ければ作る
function push_vapid(): array
{
    $path = data_dir() . '/push_vapid.json';
    $v = json_load($path);
    if (($v['pub'] ?? '') !== '' && ($v['pem'] ?? '') !== '') return $v;
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$k || !openssl_pkey_export($k, $pem)) return [];
    $v = ['pub' => b64u(ec_raw_pub($k)), 'pem' => $pem, 'created' => date('Y-m-d H:i:s')];
    json_update($path, function (array $old) use (&$v) { if (($old['pub'] ?? '') !== '') { $v = $old; return $old; } return $v; });   // 同時に作ったときは先のもの
    @chmod($path, 0600);
    return $v;
}

// 公開鍵を 65バイト (0x04 + X + Y) で
function ec_raw_pub($key): string
{
    $d = openssl_pkey_get_details($key);
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}
// 65バイトの公開鍵を openssl で使える形に
function ec_pub_key(string $raw)
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n");
}

// 送り元の名乗り (JWT、ES256)
function push_jwt(string $endpoint, array $vapid): string
{
    global $HPX_MAIL_FROM, $HPX_SITE_URL;
    $u = parse_url($endpoint);
    $sub = filter_var((string)($HPX_MAIL_FROM ?? ''), FILTER_VALIDATE_EMAIL) ? 'mailto:' . $HPX_MAIL_FROM : (string)($HPX_SITE_URL ?? 'https://hiveplusx.com');
    $in = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . b64u(json_encode(['aud' => $u['scheme'] . '://' . $u['host'], 'exp' => time() + 12 * 3600, 'sub' => $sub]));
    if (!openssl_sign($in, $der, $vapid['pem'], OPENSSL_ALGO_SHA256)) return '';
    // openssl の署名 (DER) を r||s の 64バイトに
    $p = 2;
    $int = function () use ($der, &$p): string { $len = ord($der[$p + 1]); $v = substr($der, $p + 2, $len); $p += 2 + $len; return str_pad(ltrim($v, "\0"), 32, "\0", STR_PAD_LEFT); };
    if (ord($der[1]) & 0x80) $p += ord($der[1]) & 0x7f;
    return $in . '.' . b64u($int() . $int());
}

// 中身を暗号化する (RFC 8291)。$uaPub・$auth は端末から受け取ったもの (b64u)
function push_encrypt(string $payload, string $uaPub, string $auth): string
{
    $ua = b64u_dec($uaPub);
    $as = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $asPub = ec_raw_pub($as);
    $shared = openssl_pkey_derive(ec_pub_key($ua), $as, 32);
    if ($shared === false || strlen($ua) !== 65) return '';
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $ua . $asPub, b64u_dec($auth));
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
}

// 1つの端末へ送る。返す値 [HTTP のコード, 返事]。404・410 はその端末の登録が無くなった
function push_send(array $dev, array $msg): array
{
    $vapid = push_vapid();
    $ep = (string)($dev['ep'] ?? '');
    if (!$vapid || !preg_match('#^https://#', $ep)) return [0, '準備ができていません'];
    $body = push_encrypt(json_encode($msg, JSON_UNESCAPED_UNICODE), (string)($dev['key'] ?? ''), (string)($dev['auth'] ?? ''));
    if ($body === '') return [0, '暗号化できませんでした'];
    $head = ['Content-Type: application/octet-stream', 'Content-Encoding: aes128gcm', 'TTL: ' . P_TTL, 'Urgency: high',
        'Authorization: vapid t=' . push_jwt($ep, $vapid) . ', k=' . $vapid['pub']];
    if (function_exists('curl_init')) {
        $ch = curl_init($ep);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $head,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5]);
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$code, $res === false ? $err : (string)$res];
    }
    $res = @file_get_contents($ep, false, stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $head),
        'content' => $body, 'timeout' => 10, 'ignore_errors' => true]]));
    $code = preg_match('#^HTTP/\S+\s+(\d+)#', (string)($http_response_header[0] ?? ''), $m) ? (int)$m[1] : 0;
    return [$code, (string)$res];
}

// アカウントの登録した端末
function push_devices(string $uid): array { return array_values(array_filter((array)(account_load($uid)['push'] ?? []), 'is_array')); }

function push_add(string $uid, string $ep, string $key, string $auth, string $name): bool
{
    if (!preg_match('#^https://[^\s]+$#', $ep) || strlen($ep) > 1000 || strlen(b64u_dec($key)) !== 65 || strlen(b64u_dec($auth)) < 16) return false;
    return account_update($uid, function (array $a) use ($ep, $key, $auth, $name) {
        $l = array_values(array_filter((array)($a['push'] ?? []), fn($d) => is_array($d) && ($d['ep'] ?? '') !== $ep));
        $l[] = ['ep' => $ep, 'key' => $key, 'auth' => $auth, 'name' => mb_substr($name, 0, 60), 'at' => time()];
        $a['push'] = array_slice($l, -P_MAX_DEVICES);
        return $a;
    });
}
function push_del(string $uid, string $ep): void
{
    account_update($uid, function (array $a) use ($ep) {
        $a['push'] = array_values(array_filter((array)($a['push'] ?? []), fn($d) => is_array($d) && ($d['ep'] ?? '') !== $ep));
        return $a;
    });
}

// アカウントの端末すべてへ送る。返す値 [届けた数, 送れなかった数, 最後のエラー]。登録が無くなった端末は消す
function push_user(string $uid, string $title, string $body, string $url = 'apiary.php'): array
{
    $ok = 0; $ng = 0; $err = '';
    foreach (push_devices($uid) as $d) {
        [$code, $res] = push_send($d, ['t' => $title, 'b' => mb_substr($body, 0, 300), 'u' => $url]);
        if ($code >= 200 && $code < 300) { $ok++; continue; }
        $ng++;
        $err = $code . ' ' . mb_substr(trim(preg_replace('/\s+/', ' ', $res) ?? ''), 0, 120);
        if ($code === 404 || $code === 410) push_del($uid, (string)$d['ep']);   // その端末で通知を止めた・HPX-note を消した
    }
    return [$ok, $ng, $err];
}

// お知らせメールの送り先から、プッシュ通知も送る人 (アカウントid) を決める (line_split() と同じ見分け方)
function push_targets(array $emails): array
{
    if (!push_on() || !$emails) return [];
    $want = array_fill_keys(array_map('email_norm', $emails), 1);
    $out = [];
    foreach (users_all() as $uid => $u) {
        $uid = (string)$uid;
        if (($u['status'] ?? '') !== 'active' || is_demo($u) || !push_devices($uid)) continue;
        foreach (explode(',', account_notify($uid)) as $e) if (isset($want[email_norm($e)])) { $out[] = $uid; break; }
    }
    return $out;
}

// お知らせの件名から、通知を押したときに開くページ
function push_url(string $subject): string
{
    foreach (['タスク' => 'task.php', 'カメラ' => 'camera.php', '温度' => 'hive.php', '重量' => 'hive.php'] as $w => $u) if (mb_strpos($subject, $w) !== false) return $u;
    return preg_match('/電柵|HPX-|センサ|ハブ|バッテリー|電池|受信/u', $subject) ? 'sensor.php' : 'apiary.php';
}
