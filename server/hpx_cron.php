<?php
// HPX ハブの見張り: ハブ(HPX-Core)からしばらくレポートが届かなければメールで知らせる。
//   ハブが止まると電柵アラートも届かなくなるので、それに気付くためのもの。
//   巣箱ごとの警報(温度の上限・下限、基準日からの重量の増減。hpx_hive_alert.php)も見る。
//   あわせて、タスクページの タスク(「メールで知らせる」にしたもの)を毎朝1回まとめて知らせる(担当者がいれば担当者へ)。
//   アカウントごとに、そのアカウントの HPX-Core とタスクを見て、そのアカウントの通知先へ送る(停止中のアカウントは見ない)。
//   15分ごとなどに、次のどちらかで実行する。
//     CRON(スタンダード以上のプラン): /usr/local/bin/php /home/<アカウント名>/www/hpx/hpx_cron.php
//     URL(ライトプランなど。外部の定期実行サービスから呼ぶ):
//       https://<ドメイン>/hpx/hpx_cron.php?k=<config.php の $HPX_CRON_TOKEN>
declare(strict_types=1);
require __DIR__ . '/hpx_auth.php';
require __DIR__ . '/hpx_hive_alert.php';
require __DIR__ . '/hpx_cam.php';
if (PHP_SAPI !== 'cli') {
    // URL から呼ぶときは、機器用とは別のトークンで確かめる(定期実行サービスにURLを預けるため)
    $cronTok = (string)($HPX_CRON_TOKEN ?? '');
    if (strlen($cronTok) < 16 || !hash_equals($cronTok, (string)($_GET['k'] ?? ''))) { http_response_code(404); exit; }
    header('Content-Type: text/plain; charset=utf-8');
}

$limit = (int)($HPX_HUB_MISSING_S ?? 3600);
$hour = (int)($HPX_TODO_MAIL_HOUR ?? 7);
$now = time();
$today = date('Y-m-d', $now);

demo_sensor_fill(true);   // デモ用アカウントのセンサの見本の値を今の時刻まで作り直す
demo_cam_fill();          // デモ用アカウントの見本のカメラの写真を直近の3日分にする (日付が変わったときだけ)

// 見る場所: [HPX-Core のフォルダの親, 蜂場などのフォルダ, 通知先(null は config.php の $HPX_MAIL_TO), アカウントid(初期設定の前は '')]
$places = [];
if (users_exist()) {
    foreach (users_all() as $uid => $u) {
        if (($u['status'] ?? '') !== 'active' || !is_uid((string)$uid)) continue;
        $places[] = [account_hubs_dir((string)$uid), account_site_dir((string)$uid), account_notify((string)$uid), (string)$uid];
    }
} else {
    $places[] = [data_dir(), data_dir() . '/site', null, ''];   // 初期設定(login.php)の前
}

foreach ($places as [$hubsDir, , $notifyTo, $owner]) {
    foreach (glob($hubsDir . '/*/state.json') ?: [] as $statePath) {
        $dir = dirname($statePath);
        $st = json_decode((string)@file_get_contents($statePath), true);
        if (!is_array($st) || empty($st['last_rx'])) continue;
        if (is_file($dir . '/.retired')) continue;   // センサページで外したハブは見張らない
        $last = (int)$st['last_rx'];
        $hub = (string)($st['name'] ?? '') !== '' ? (string)$st['name'] : basename($dir);
        $flag = $dir . '/.hub_missing';

        if ($now - $last >= $limit && !is_file($flag)) {
            hpx_alert_queue("【HPX】ハブ($hub)から届いていません",
                "ハブ(HPX-Core)から " . intdiv($now - $last, 60) . " 分レポートが届いていません。この間は電柵アラートも届きません。\n"
                . "電源・Wi-Fi・ルーターを確認してください。\n\nハブ: $hub\n最後の受信: " . date('Y-m-d H:i', $last) . "\n");
            @touch($flag);
        } elseif ($now - $last < $limit && is_file($flag)) {
            hpx_alert_queue("【HPX】ハブ($hub)からの受信が戻りました",
                "ハブ(HPX-Core)からのレポートがまた届くようになりました。\n\nハブ: $hub\n最後の受信: " . date('Y-m-d H:i', $last) . "\n");
            @unlink($flag);
        }
        // 送り先: アカウントの通知先と、このハブを紐づけた蜂場を見られるチームの人
        hpx_alert_flush($dir, $owner !== '' ? apiary_alert_to($owner, hub_apiaries($owner, basename($dir)), 'device') : $notifyTo);
    }

}

// ---- 巣箱の警報 ----
check_hive_alerts($now);

// ---- タスクのリマインド(1日1回、$HPX_TODO_MAIL_HOUR 時を過ぎた最初の実行で) ----
//   期限の前日・当日と、期限を過ぎた未完了の タスクを、人ごとに1通にまとめる。
//   担当者がいればその人(蜂場をまだ見られて、停止中でないとき)、いなければ蜂場の持ち主に送る
$sentFlag = data_dir() . '/.todo_mailed';
if ((int)date('G', $now) >= $hour && trim((string)@file_get_contents($sentFlag)) !== $today) {
    $tomorrow = date('Y-m-d', $now + 86400);
    $byTo = [];   // 送り先のアカウントid(初期設定の前は '') => [並べ替えのキー => 行]
    foreach ($places as [, $siteDir, , $owner]) {
        if (!is_dir($siteDir)) continue;
        $works = json_decode((string)@file_get_contents($siteDir . '/works.json'), true);
        $site = json_decode((string)@file_get_contents($siteDir . '/site.json'), true);
        foreach ((is_array($works['todos'] ?? null) ? $works['todos'] : []) as $t) {
            if (!empty($t['done']) || empty($t['notify']) || ($t['due'] ?? '') === '' || $t['due'] > $tomorrow) continue;
            $where = (string)($site['apiaries'][$t['apiary'] ?? '']['name'] ?? '');
            if (($t['hive'] ?? '') !== '') $where .= ' / ' . (string)($site['hives'][$t['hive']]['name'] ?? '');
            $when = $t['due'] < $today ? '期限切れ(' . date('n/j', strtotime($t['due'])) . ')' : ($t['due'] === $today ? '今日まで' : '明日まで');
            // 担当者(複数のときは全員)。蜂場をもう見られない人・停止中の人は外し、残らなければ持ち主へ
            $asl = todo_assignees($t);
            $tos = [];
            foreach ($asl as $as) {
                if ($owner !== '' && ((users_all()[$as]['status'] ?? '') === 'active') && apiary_role($owner, (string)($t['apiary'] ?? ''), $as) !== null) $tos[] = $as;
            }
            if (!$tos) $tos = [$owner];
            $line = "・[$when] " . (($t['title'] ?? '') !== '' ? $t['title'] : implode('・', (array)($t['tags'] ?? []))) . ($where !== '' ? " ($where)" : '');
            foreach ($tos as $to) {
                $byTo[$to][$t['due'] . ($t['id'] ?? '')] = $line . (count($asl) > 1 || ($to === $owner && $asl && !in_array($owner, $asl, true)) ? ' 担当: ' . implode('・', array_map('user_name', $asl)) : '');
            }
        }
    }
    foreach ($byTo as $to => $lines) {
        if ($to !== '' && $to === demo_uid()) continue;   // デモ用アカウントにはタスクのメールを送らない
        ksort($lines);
        hpx_alert_queue('【HPX】タスクのお知らせ (' . count($lines) . '件)',
            "期限が近い・過ぎている作業があります。\n\n" . implode("\n", $lines) . "\n\n済んだものはタスクページで「完了」にしてください。\n" . site_url('task.php') . "\n");
        if ($to === '') hpx_alert_flush(data_dir(), null);
        else hpx_alert_flush(account_site_dir((string)$to), account_notify((string)$to, 'task'));   // タスクのお知らせを止めている人は '' (送らない)
    }
    @file_put_contents($sentFlag, $today);
}
// デモ用アカウントを毎日 $HPX_DEMO_RESET_HOUR 時を過ぎた最初の実行で、初期状態 (data/demo_seed/) に戻す
$demoFlag = data_dir() . '/.demo_reset';
if (demo_uid() !== '' && !demo_editing() && (int)date('G', $now) >= (int)($HPX_DEMO_RESET_HOUR ?? 3) && trim((string)@file_get_contents($demoFlag)) !== $today) {
    if (demo_reset()) echo "demo reset\n";
    @file_put_contents($demoFlag, $today);
}
echo "ok\n";
