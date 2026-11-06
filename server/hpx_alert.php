<?php
// HPX 電柵アラートのメール通知。receive.php と hpx_cron.php から読み込んで使う。
//
//   HPX-A (電柵アラート)の記録(sensors.ble の "al" 付きの記録)を見て、状態が変わったときにメールを送る。
//     正常 → 電圧低下        : 「電柵の電圧が下がっています」
//     正常のまま回数が増えた : 「一時的に電圧が下がりました」(ハブが受信する間の短い低下)
//     電圧低下 → 正常        : 「電柵の電圧が戻りました」
//     常時点灯                : 「光センサが点灯したままです」(遮光不良・配線の確認)
//     電池電圧が低い          : 「HPX-A の電池を交換してください」
//     しばらく受信できない    : 「HPX-A から受信できていません」(ハブは届いているのにノードの記録が来ないとき)
//   HPX-W (重量)・HPX-TH (温湿度) と市販の温湿度計も見る (hpx_sensor_batt_record・hpx_alert_check_missing)。
//     電池電圧が低い          : 「HPX-W / HPX-TH の電池を交換してください」(センサページの表に交換が出るのと同じ電圧で1回)
//     しばらく受信できない    : 「〇〇 から受信できていません」($HPX_SENSOR_MISSING_S、初期 24 時間)
//   ハブ(HPX-Core)自体から届かないときは hpx_cron.php(さくらの cron で定期実行)が知らせる。
//
//   メールは記録の処理中にためておき、hpx_alert_flush() でまとめて送る(ロックを外してから送る)。
//   メールは Gmail のアカウントにログインして(SMTP認証、アプリパスワード)送る。さくらのサーバーからの直接送信(mb_send_mail)は
//   届いたり届かなかったりしたので使わない。設定は config.php の $HPX_SMTP_*。
//   送信の結果は データフォルダの mail.log に残る。
declare(strict_types=1);

$HPX_ALERT_QUEUE = [];

function hpx_alert_state_text(int $st): string
{
    return [0 => '正常', 1 => '電圧低下', 2 => '常時点灯(光センサの遮光・配線を確認)'][$st] ?? '不明';
}

function hpx_alert_queue(string $subject, string $body): void
{
    global $HPX_ALERT_QUEUE;
    $HPX_ALERT_QUEUE[] = [$subject, $body];
}

// 電柵アラートの記録1件を処理する。$s は receive.php の温湿度計ごとの状態(このノードの分)で、ここで更新する。
function hpx_alert_record(array &$s, array $r, int $epoch, string $hubName, string $nodeName): void
{
    global $HPX_ALERT_BATT_V;
    $st = (int)$r['al'];
    $ev = isset($r['ev']) && is_numeric($r['ev']) ? (int)$r['ev'] : 0;
    $when = date('Y-m-d H:i', $epoch);
    $where = ($hubName !== '' ? $hubName . ' / ' : '') . $nodeName;
    $ago = isset($r['ago']) && is_numeric($r['ago']) ? (int)$r['ago'] : null;
    $agoText = $ago === null ? '' : "\n最後の点滅: 約" . max(0, intdiv($ago, 60)) . '分前';

    $hadState = array_key_exists('fence_state', $s);
    $prevSt = $hadState ? (int)$s['fence_state'] : 0;
    $prevEv = (int)($s['fence_events'] ?? $ev);

    if ($st === 1 && $prevSt !== 1) {
        hpx_alert_queue("【HPX】電柵の電圧が下がっています ($where)",
            "電柵の電圧が下がっています。電牧器・柵線(草の接触、断線、漏電)を確認してください。\n\n"
            . "場所: $where\n時刻: $when\n直近1分の点滅: " . (int)($r['bl'] ?? 0) . "回$agoText\n");
    } elseif ($st === 2 && $prevSt !== 2) {
        hpx_alert_queue("【HPX】電柵アラートの光センサが点灯したままです ($where)",
            "HPX-A の入力が光りっぱなしになっています。電圧低下かどうか判定できません。\n"
            . "光センサの遮光(日光が入っていないか)と配線を確認してください。\n\n場所: $where\n時刻: $when\n");
    } elseif ($st === 0 && $hadState && $prevSt !== 0) {
        hpx_alert_queue("【HPX】電柵の電圧が戻りました ($where)",
            "電柵の電圧低下の表示が消えました(" . hpx_alert_state_text($prevSt) . " → 正常)。\n\n場所: $where\n時刻: $when$agoText\n");
    } elseif ($st === 0 && $hadState && $ev > $prevEv) {
        hpx_alert_queue("【HPX】電柵の電圧が一時的に下がりました ($where)",
            "前回の記録から今回までの間に、電柵の電圧が " . ($ev - $prevEv) . " 回下がり、今は戻っています。\n"
            . "くり返すときは、柵線への草の接触や漏電を確認してください。\n\n場所: $where\n時刻: $when$agoText\n");
    }
    // 回数がノードの再起動で 0 に戻ったときは、そのまま新しい基準にする(メールは出さない)
    $s['fence_state'] = $st;
    $s['fence_events'] = $ev;

    if (isset($r['bv']) && is_numeric($r['bv'])) {
        $bv = (float)$r['bv'];
        $s['fence_bv'] = $bv;
        $limit = (float)($HPX_ALERT_BATT_V ?? 2.6);
        if ($bv < $limit && empty($s['batt_notified'])) {
            hpx_alert_queue("【HPX】HPX-A の電池を交換してください ($where)",
                "HPX-A の電池電圧が " . number_format($bv, 2) . "V に下がっています。\n\n場所: $where\n時刻: $when\n");
            $s['batt_notified'] = 1;
        } elseif ($bv >= $limit + 0.1) {
            unset($s['batt_notified']);
        }
    }
    unset($s['missing_notified']);
}

// センサの種類の表示名 (メールの件名に使う)
function hpx_sensor_label(array $s): string
{
    $k = (string)($s['kind'] ?? '');
    if ($k === 'fa') return 'HPX-A';
    if ($k === 'w') return 'HPX-W';
    if (!empty($s['th_bv']) || ($s['model'] ?? '') === 'hpx_th') return 'HPX-TH';
    return '温湿度計';
}

// HPX-W・HPX-TH の記録1件ごとに呼ぶ。電池電圧がセンサページで「交換」と出る値を下回ったら1回知らせる。
//   $bv: 記録の電池電圧(V)。$stopped: HPX-W が電池電圧の低下で測定を止めた印を送ってきたとき true
//   電圧が 0.1V 以上戻ったら(電池を替えたら)、また知らせられるようにする。
function hpx_sensor_batt_record(array &$s, ?float $bv, bool $stopped, int $epoch, string $hubName, string $nodeName): void
{
    global $HPX_W_BATT_V, $HPX_TH_BATT_V;
    $isW = ($s['kind'] ?? '') === 'w';
    $limit = $isW ? (float)($HPX_W_BATT_V ?? 2.8) : (float)($HPX_TH_BATT_V ?? 2.7);   // sensor.php の D_W_LOW_BV・D_TH_LOW_V と同じ
    $where = ($hubName !== '' ? $hubName . ' / ' : '') . $nodeName;
    $label = $isW ? 'HPX-W' : 'HPX-TH';
    if (!$isW && $bv !== null) $s['th_bv'] = 1;   // 電池電圧を送ってくる温湿度計 = HPX-TH
    if (($stopped || ($bv !== null && $bv > 0 && $bv < $limit)) && empty($s['batt_notified'])) {
        hpx_alert_queue("【HPX】$label の電池を交換してください ($where)",
            $label . ' の電池電圧が' . ($bv !== null ? ' ' . number_format($bv, 2) . 'V に下がっています' : '低くなっています') . "。\n"
            . ($stopped ? "電圧が低すぎるため、重さを測るのを止めています。\n" : '')
            . "電池を交換してください。\n\n場所: $where\n時刻: " . date('Y-m-d H:i', $epoch) . "\n");
        $s['batt_notified'] = 1;
    } elseif (!$stopped && $bv !== null && $bv >= $limit + 0.1) {
        unset($s['batt_notified']);
    }
}

// ハブからレポートが届くたびに呼ぶ。センサの記録がしばらく来ていなければ知らせる。
//   HPX-A は $HPX_ALERT_MISSING_S (初期1時間)、HPX-W・HPX-TH・温湿度計は $HPX_SENSOR_MISSING_S (初期24時間)。
//   $registered: いまハブに登録されているセンサのアドレス(空なら全部見る)。ハブから外したセンサは見ない。
function hpx_alert_check_missing(array &$sens, int $now, string $hubName, array $registered = []): void
{
    global $HPX_ALERT_MISSING_S, $HPX_SENSOR_MISSING_S;
    foreach ($sens as $mac => &$s) {
        $kind = (string)($s['kind'] ?? '');
        if (!in_array($kind, ['fa', 'w', 'th'], true)) continue;
        if ($registered && !in_array((string)$mac, $registered, true)) continue;
        $limit = $kind === 'fa' ? (int)($HPX_ALERT_MISSING_S ?? 3600) : (int)($HPX_SENSOR_MISSING_S ?? 86400);
        $last = (int)($s['last_epoch'] ?? 0);
        if ($last <= 0 || $now - $last < $limit || !empty($s['missing_notified'])) continue;
        $where = ($hubName !== '' ? $hubName . ' / ' : '') . (($s['name'] ?? '') !== '' ? $s['name'] : $mac);
        if ($kind !== 'fa') {
            $label = hpx_sensor_label($s);
            $min = intdiv($now - $last, 60);
            hpx_alert_queue("【HPX】$label から受信できていません ($where)",
                "ハブ(HPX-Core)からは届いていますが、$label の記録が " . ($min >= 120 ? intdiv($min, 60) . ' 時間' : $min . ' 分') . "来ていません。\n"
                . "電池・設置場所(ハブとの距離)を確認してください。\n\n"
                . "場所: $where\n最後の記録: " . date('Y-m-d H:i', $last) . "\n");
            $s['missing_notified'] = 1;
            continue;
        }
        hpx_alert_queue("【HPX】HPX-A から受信できていません ($where)",
            "ハブ(HPX-Core)からは届いていますが、HPX-A の記録が " . intdiv($now - $last, 60) . " 分来ていません。\n"
            . "電池・設置場所(ハブとの距離)を確認してください。この間の電柵の状態は分かりません。\n\n"
            . "場所: $where\n最後の記録: " . date('Y-m-d H:i', $last) . "\n");
        $s['missing_notified'] = 1;
    }
    unset($s);
}

// SMTP認証で1通送る。成功なら [true, サーバーの返事]、失敗なら [false, 理由] を返す。
//   $HPX_SMTP_SECURE: 'tls' = 587番でSTARTTLS / 'ssl' = 465番で最初から暗号化 / 'none' = 暗号化なし(試験用)
function hpx_smtp_send(array $to, string $from, string $subject, string $body): array
{
    global $HPX_SMTP_HOST, $HPX_SMTP_PORT, $HPX_SMTP_USER, $HPX_SMTP_PASS, $HPX_SMTP_SECURE;
    $host = (string)$HPX_SMTP_HOST;
    $secure = (string)($HPX_SMTP_SECURE ?? 'tls');
    $port = (int)($HPX_SMTP_PORT ?? ($secure === 'ssl' ? 465 : 587));
    $ctx = stream_context_create(['ssl' => ['peer_name' => $host, 'verify_peer' => true, 'verify_peer_name' => true]]);
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15,
        STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return [false, "接続できません ($host:$port $errstr)"];
    stream_set_timeout($fp, 20);

    // 返事を読む(複数行の "250-..." にも対応)。期待したコードでなければ例外
    $read = function (string $want) use ($fp): string {
        $all = '';
        while (($ln = fgets($fp, 1024)) !== false) {
            $all .= $ln;
            if (strlen($ln) < 4 || $ln[3] !== '-') break;
        }
        $all = trim($all);
        if (strncmp($all, $want, strlen($want)) !== 0) throw new RuntimeException($all !== '' ? $all : '返事がありません');
        return $all;
    };
    $cmd = function (string $line, string $want) use ($fp, $read): string {
        fwrite($fp, $line . "\r\n");
        return $read($want);
    };

    try {
        $read('220');
        $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
        $cmd($ehlo, '250');
        if ($secure === 'tls') {
            $cmd('STARTTLS', '220');
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('暗号化(STARTTLS)に失敗しました');
            }
            $cmd($ehlo, '250');
        }
        $cmd('AUTH LOGIN', '334');
        $cmd(base64_encode((string)$HPX_SMTP_USER), '334');
        $cmd(base64_encode((string)$HPX_SMTP_PASS), '235');
        $cmd('MAIL FROM:<' . $from . '>', '250');
        foreach ($to as $t) $cmd('RCPT TO:<' . $t . '>', '25');
        $cmd('DATA', '354');
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $msg = 'Date: ' . date('r') . "\r\n"
            . 'From: ' . $from . "\r\n"
            . 'To: ' . implode(', ', $to) . "\r\n"
            . 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n") . "\r\n"
            . 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domain . ">\r\n"
            . "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($body), 76, "\r\n");
        fwrite($fp, $msg . ".\r\n");   // 本文は base64 なので、行頭の "." の処理は要らない
        $res = $read('250');
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return [true, $res];
    } catch (Throwable $e) {
        @fwrite($fp, "QUIT\r\n");
        fclose($fp);
        return [false, $e->getMessage()];
    }
}

// ためたメールを送る。$logDir に mail.log を書く。$to は送り先(カンマ区切り。省略したら config.php の $HPX_MAIL_TO)
function hpx_alert_flush(string $logDir, ?string $to = null): void
{
    global $HPX_ALERT_QUEUE, $HPX_MAIL_TO, $HPX_MAIL_FROM;
    if (!$HPX_ALERT_QUEUE) return;
    if ($to === '') {   // 送り先が無い(全員がこの種類のお知らせメールを止めている)。送らずに記録だけ残す
        foreach ($HPX_ALERT_QUEUE as [$subject]) @file_put_contents(rtrim($logDir, '/') . '/mail.log', date('Y-m-d H:i:s') . ' SKIP ' . $subject . ' | お知らせメールを止めているので送りません' . "\n", FILE_APPEND);
        $HPX_ALERT_QUEUE = [];
        return;
    }
    $to ??= (string)($HPX_MAIL_TO ?? '');
    $from = (string)($HPX_MAIL_FROM ?? '');
    global $HPX_SMTP_HOST;
    mb_language('Japanese');
    mb_internal_encoding('UTF-8');
    $toList = array_values(array_filter(array_map('trim', explode(',', $to)), fn($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
    $useSmtp = (string)($HPX_SMTP_HOST ?? '') !== '';
    foreach ($HPX_ALERT_QUEUE as [$subject, $body]) {
        $ok = false;
        $detail = '';
        $text = $body . "\n-- \nHive Plus X\n";
        if (!$toList || !filter_var($from, FILTER_VALIDATE_EMAIL) || $to === 'you@example.com') {
            $detail = '送り先・送り元のアドレスが設定されていません';
        } elseif (!$useSmtp) {
            $detail = 'config.php の $HPX_SMTP_HOST などが設定されていません';
        } else {
            [$ok, $detail] = hpx_smtp_send($toList, $from, $subject, $text);
        }
        $detail = trim(preg_replace('/\s+/', ' ', $detail) ?? '');
        @file_put_contents(rtrim($logDir, '/') . '/mail.log',
            date('Y-m-d H:i:s') . ' ' . ($ok ? 'sent' : 'FAILED') . ' ' . $subject . ($detail !== '' ? ' | ' . mb_substr($detail, 0, 200) : '') . "\n", FILE_APPEND);
    }
    $HPX_ALERT_QUEUE = [];
}
