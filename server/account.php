<?php
// 設定ページ: ログインしている本人のアカウントの設定。
//   表示名、パスワード、メールアドレス(=ログインID)の変更、通知メールの送り先、お知らせメールのオン/オフ、プッシュ通知、LINE の連携、HPX-Core に入れる機器用トークン、
//   データ一括ダウンロード(export.php)、バックアップと復元(backup.php)。
//   メールアドレスは、新しいアドレスに確認メールを送り、そのリンクを開いた時点で切り替える(打ち間違いでログインできなくなるのを防ぐ)。
declare(strict_types=1);
require __DIR__ . '/hpx_site.php';

if (is_post()) {
    $act = (string)($_POST['act'] ?? '');
    $pwOk = fn() => password_verify((string)($_POST['cur'] ?? ''), (string)$ME['pw']);
    if ($act === 'name_save') {
        $name = str_in('name', 40);
        if ($name === '') redirect('account.php', '表示名を入れてください。');
        user_set($UID, ['name' => $name]);
        redirect('account.php', '表示名を「' . $name . '」にしました。');
    }
    if ($act === 'pw_save') {
        if (!$pwOk()) { sleep(2); redirect('account.php#pw', '今のパスワードが違います。パスワードは変えていません。'); }
        $err = pw_check_new((string)($_POST['pw'] ?? ''), (string)($_POST['pw2'] ?? ''));
        if ($err !== '') redirect('account.php#pw', $err);
        user_set($UID, ['pw' => password_hash((string)$_POST['pw'], PASSWORD_DEFAULT), 'pwv' => (int)($ME['pwv'] ?? 1) + 1]);
        auth_login(user_get($UID));   // この端末はログインしたまま。ほかの端末のログインは切れる
        redirect('account.php#pw', 'パスワードを変えました。ほかの端末では、もう一度ログインが必要です。');
    }
    if ($act === 'email_save') {
        $new = email_norm((string)($_POST['email'] ?? ''));
        if (!$pwOk()) { sleep(2); redirect('account.php#email', 'パスワードが違います。'); }
        if (!email_ok($new)) redirect('account.php#email', 'メールアドレスを正しく入れてください。');
        if ($new === $ME['email']) redirect('account.php#email', '今と同じメールアドレスです。');
        if (user_by_email($new)) redirect('account.php#email', 'そのメールアドレスはほかのアカウントで使われています。');
        [$ok] = account_mail($new, '【HPX】メールアドレスの確認',
            $ME['name'] . " さん\n\nHPX のログインに使うメールアドレスを、このアドレスに変える手続きを受け付けました。\n"
            . "次のリンクを開くと切り替わります (" . A_LINK_HOURS . "時間有効)。それまでは前のアドレスでログインできます。\n\n"
            . link_url(link_issue('email', $UID, ['email' => $new])) . "\n\n心当たりが無いときは、このメールは捨ててください。\n");
        redirect('account.php#email', $ok ? $new . ' に確認メールを送りました。メールのリンクを開くと切り替わります。'
            : '確認メールを送れませんでした。アドレスを見直すか、サーバー管理者に問い合わせてください。');
    }
    if ($act === 'notify_save') {
        $n = notify_clean((string)($_POST['notify'] ?? ''));
        if ($n === null) redirect('account.php#notify', 'メールアドレスを正しく入れてください (' . A_NOTIFY_MAX . 'つまで、カンマ区切り)。');
        account_update($UID, function (array $a) use ($n) { $a['notify'] = $n; return $a; });
        redirect('account.php#notify', '通知メールの送り先を保存しました。');
    }
    if ($act === 'mail_save') {
        $off = array_values(array_filter(array_keys(A_MAIL_KINDS), fn($k) => empty($_POST['mail'][$k])));
        account_update($UID, function (array $a) use ($off) { $a['mail_off'] = $off; return $a; });
        redirect('account.php#mail', $off === [] ? 'お知らせメールを、すべて受け取るようにしました。'
            : (count($off) === count(A_MAIL_KINDS) ? 'お知らせメールを、すべて止めました。' : '「' . implode('」「', array_map(fn($k) => A_MAIL_KINDS[$k][0], $off)) . '」のメールを止めました。'));
    }
    // プッシュ通知を受け取る端末 (hpx_push.php)。端末の登録・外すは、下の画面の JavaScript から送る
    if (str_starts_with($act, 'push_')) {
        if (is_demo($ME)) redirect('account.php#push', 'デモ用アカウントでは、プッシュ通知は使えません。');
        if (!push_on()) redirect('account.php#push', 'このサーバーの PHP では、プッシュ通知が使えません (openssl の機能が足りません)。');
        if ($act === 'push_add') {
            $ok = push_add($UID, (string)($_POST['ep'] ?? ''), (string)($_POST['key'] ?? ''), (string)($_POST['auth'] ?? ''), str_in('name', 60));
            redirect('account.php#push', $ok ? 'この端末でプッシュ通知を受け取るようにしました。「テストの通知を送る」で確かめてください。' : 'この端末を登録できませんでした。');
        }
        if ($act === 'push_del') {
            push_del($UID, (string)($_POST['ep'] ?? ''));
            redirect('account.php#push', 'その端末には、プッシュ通知を送らないようにしました。');
        }
        if ($act === 'push_test') {
            [$ok, $ng, $err] = push_user($UID, 'テストの通知', 'HPX-note からプッシュ通知が届くことを確かめました。', 'account.php#push');
            redirect('account.php#push', !$ok && !$ng ? 'プッシュ通知を受け取る端末がありません。'
                : ($ok ? $ok . '台の端末に送りました。届いたか確かめてください。' : '') . ($ng ? ' ' . $ng . '台には送れませんでした (' . $err . ')。' : ''));
        }
        redirect('account.php#push');
    }
    // LINE でお知らせを受け取る (hpx_line.php)
    if (line_on() && str_starts_with($act, 'line_')) {
        if (is_demo($ME)) redirect('account.php#line', 'デモ用アカウントでは LINE を連携できません。');
        if ($act === 'line_code') { line_code_issue($UID); redirect('account.php#line', '連携コードを出しました。' . L_CODE_MIN . '分以内に LINE のトークへ送ってください。'); }
        if ($act === 'line_mode' && line_link($UID)) {
            $only = ($_POST['mode'] ?? '') === 'only';
            account_update($UID, function (array $a) use ($only) { if (is_array($a['line'] ?? null)) $a['line']['only'] = $only; return $a; });
            redirect('account.php#line', $only ? 'お知らせを LINE だけで受け取るようにしました (LINE で送れなかったときはメールで届きます)。' : 'お知らせをメールと LINE の両方で受け取るようにしました。');
        }
        if ($act === 'line_test' && ($l = line_link($UID))) {
            [$ok, $code, $res] = line_push((string)$l['to'], "【HPX】LINE のテスト\n\nHPX-note から LINE にお知らせが届くことを確かめました。");
            redirect('account.php#line', $ok ? 'LINE にテストのメッセージを送りました。届いたか確かめてください。' : 'LINE に送れませんでした (' . $code . ' ' . mb_substr($res, 0, 120) . ')。');
        }
        if ($act === 'line_note' && line_link($UID)) {
            $on = !empty($_POST['note']);
            account_update($UID, function (array $a) use ($on) { if (is_array($a['line'] ?? null)) $a['line']['note'] = $on; return $a; });
            redirect('account.php#line', $on ? 'LINE で送った文を HPX-note に記録するようにしました。' : 'LINE で送った文を記録しないようにしました。');
        }
        if ($act === 'line_off') { line_unlink($UID); redirect('account.php#line', 'LINE の連携を外しました。お知らせはメールだけで届きます。'); }
        redirect('account.php#line');
    }
    if ($act === 'consent_save') {
        if (empty($_POST['consent'])) redirect('account.php#consent', '同意のチェックを入れてください。');
        if (!consent_given($ME)) user_set($UID, ['consent' => time()]);
        redirect('account.php#consent', 'データの利用に同意しました。');
    }
    if ($act === 'token_new') {
        token_issue($UID);
        redirect('account.php#token', '機器用トークンを作り直しました。HPX-Core の「送信先のトークン」を新しいものに変えてください。');
    }
    redirect('account.php');
}

$acct = account_load($UID);
$hubCount = count(glob(account_hubs_dir($UID) . '/*/state.json') ?: []);
page_head('マイアカウント', 'account.php');
echo flash_html();

echo '<h2>アカウント</h2><div class="card form"><dl class="info"><dt>メールアドレス</dt><dd>' . h((string)$ME['email']) . ' <span class="muted">(ログインに使います)</span></dd>'
    . '<dt>権限</dt><dd>' . (!empty($ME['admin']) ? 'サーバー管理者' : '一般') . '</dd></dl>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="act" value="name_save">'
    . '<p><label>表示名 (メモや作業記録の記入者として出ます)</label><input type="text" name="name" maxlength="40" value="' . h((string)$ME['name']) . '" required></p>'
    . '<button type="submit">表示名を保存</button></form></div>';

echo '<h2 id="email">メールアドレスの変更</h2><div class="card form"><p class="muted">ログインに使うメールアドレスが変わります。新しいアドレスに確認メールを送り、そのリンクを開いた時点で切り替わります。'
    . '記録 (メモ・作業記録など) はそのまま引き継がれます。</p>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="act" value="email_save">'
    . '<p><label>新しいメールアドレス</label><input type="email" name="email" required style="width:100%"></p>'
    . '<p><label>今のパスワード</label><input type="password" name="cur" autocomplete="current-password" required></p>'
    . '<button type="submit">確認メールを送る</button></form></div>';

echo '<h2 id="pw">パスワードの変更</h2><div class="card form"><form method="post">' . csrf_field() . '<input type="hidden" name="act" value="pw_save">'
    . '<p><label>今のパスワード</label><input type="password" name="cur" autocomplete="current-password" required></p>'
    . '<p><label>新しいパスワード (' . A_PW_MIN . '文字以上)</label><input type="password" name="pw" autocomplete="new-password" required></p>'
    . '<p><label>新しいパスワード (確認)</label><input type="password" name="pw2" autocomplete="new-password" required></p>'
    . '<button type="submit">パスワードを変える</button></form></div>';

echo '<h2 id="notify">通知メールの送り先</h2><div class="card form"><p class="muted">アラート、タスクのリマインダーの送り先。空の場合はログインメールアドレスへ送ります。携帯メールなど' . A_NOTIFY_MAX . 'つまでカンマ区切りで入れられます。</p>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="act" value="notify_save">'
    . '<p><input type="text" name="notify" value="' . h((string)($acct['notify'] ?? '')) . '" placeholder="' . h((string)$ME['email']) . '"></p>'
    . '<button type="submit">送り先を保存</button></form></div>';

$off = (array)($acct['mail_off'] ?? []);
echo '<h2 id="mail">お知らせメールのオン/オフ</h2><div class="card form"><p class="muted">チェックを外した種類のメールは届かなくなります。チームの人には、それぞれの設定のとおりに届きます。'
    . '招待・パスワードの再設定・メールアドレスの確認などの手続きのメールは、いつも届きます。</p>'
    . '<form method="post">' . csrf_field() . '<input type="hidden" name="act" value="mail_save">';
foreach (A_MAIL_KINDS as $k => [$label, $desc]) {
    echo '<p><label style="display:inline-flex;align-items:center;gap:6px"><input type="checkbox" name="mail[' . h($k) . ']" value="1"' . (in_array($k, $off, true) ? '' : ' checked') . '> '
        . h($label) . '</label> <span class="muted">' . h($desc) . '</span></p>';
}
echo ($off !== [] && count($off) === count(A_MAIL_KINDS) ? '<p class="ng">いまは、お知らせメールがすべて止まっています。電柵の電圧低下にも気づけないので、ご注意ください。</p>' : '')
    . '<button type="submit">保存</button></form></div>';

// プッシュ通知を受け取る (hpx_push.php)。登録は端末ごと。iPhone はホーム画面に追加した HPX-note からだけ
if (push_on() && !is_demo($ME) && ($vapid = push_vapid())) {
    $devs = push_devices($UID);
    echo '<h2 id="push">プッシュ通知で受け取る</h2><div class="card form">'
        . '<p class="muted">電柵・巣箱・センサ・タスク・カメラのお知らせを、メールに加えて、このスマホやパソコンの通知でも受け取れます。'
        . '上の「お知らせメールのオン/オフ」で止めた種類は、通知も届きません。端末ごとに登録してください。</p>'
        . '<p>この端末: <b id="push-st">確かめています…</b></p><p id="push-btns"></p>'
        . '<p class="muted" id="push-note"></p>';
    if ($devs) {
        echo '<p style="margin-bottom:2px">登録してある端末 (' . count($devs) . '台)</p><ul style="margin-top:2px">';
        foreach ($devs as $d) {
            echo '<li>' . h((string)($d['name'] ?? '端末')) . ' <span class="muted">(' . h(date('Y-m-d', (int)($d['at'] ?? 0))) . ' から)</span> '
                . '<form method="post" class="inl" onsubmit="return confirm(\'この端末には通知を送らないようにしますか?\')">' . csrf_field()
                . '<input type="hidden" name="act" value="push_del"><input type="hidden" name="ep" value="' . h((string)$d['ep']) . '"><button type="submit" class="link">外す</button></form></li>';
        }
        echo '</ul><form method="post">' . csrf_field() . '<input type="hidden" name="act" value="push_test"><button type="submit">テストの通知を送る</button></form>';
    }
    echo '<p class="muted" style="margin-top:10px">iPhone・iPad は iOS 16.4 以上で、ホーム画面に追加した HPX-note から開いたときだけ設定できます。'
        . '通知が届かないこともあるので、電柵などの大事なお知らせは、メールか LINE も受け取ったままにしてください。</p></div>';
    $j = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    echo '<script>var PUSH_KEY=' . json_encode($vapid['pub'], $j) . ',PUSH_EPS=' . json_encode(array_column($devs, 'ep'), $j) . ',PUSH_CSRF=' . json_encode($csrf, $j) . ';</script>' . <<<'JS'
<script>
(function(){
  var st = document.getElementById('push-st'), btns = document.getElementById('push-btns'), note = document.getElementById('push-note');
  var ios = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var standalone = window.navigator.standalone === true || (window.matchMedia && matchMedia('(display-mode: standalone)').matches);
  if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
    st.textContent = '受け取れません';
    note.textContent = ios && !standalone ? 'iPhone・iPad では、Safari の共有ボタン →「ホーム画面に追加」をして、ホーム画面の HPX-note から開いてください。'
      : 'このブラウザでは、プッシュ通知が使えません。iPhone・iPad は iOS 16.4 以上が必要です。';
    return;
  }
  function post(act, data){
    var fd = new FormData(); fd.append('act', act); fd.append('csrf', PUSH_CSRF);
    for (var k in data) fd.append(k, data[k]);
    return fetch('account.php', {method: 'POST', body: fd, credentials: 'same-origin'}).then(function(){ location.href = 'account.php#push'; location.reload(); });
  }
  function b64u(buf){ return btoa(String.fromCharCode.apply(null, new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
  function keyBytes(s){ s = s.replace(/-/g, '+').replace(/_/g, '/'); s += '===='.slice(s.length % 4 || 4); var b = atob(s), a = new Uint8Array(b.length); for (var i = 0; i < b.length; i++) a[i] = b.charCodeAt(i); return a; }
  function devName(){
    var u = navigator.userAgent;
    var os = /iPhone/.test(u) ? 'iPhone' : /iPad/.test(u) || ios ? 'iPad' : /Android/.test(u) ? 'Android' : /Windows/.test(u) ? 'Windows' : /Mac/.test(u) ? 'Mac' : 'パソコン';
    var br = /Edg\//.test(u) ? 'Edge' : /Chrome\//.test(u) && !/Edg\//.test(u) ? 'Chrome' : /Firefox\//.test(u) ? 'Firefox' : 'Safari';
    return os + (standalone ? ' (ホーム画面)' : ' (' + br + ')');
  }
  function button(label, fn){ var b = document.createElement('button'); b.type = 'button'; b.textContent = label; b.onclick = function(){ b.disabled = true; fn().catch(function(e){ b.disabled = false; note.textContent = '設定できませんでした: ' + (e && e.message || e); }); }; btns.appendChild(b); btns.appendChild(document.createTextNode(' ')); }
  navigator.serviceWorker.register('sw.js').then(function(){ return navigator.serviceWorker.ready; }).then(function(reg){
    return reg.pushManager.getSubscription().then(function(sub){
      var known = sub && PUSH_EPS.indexOf(sub.endpoint) >= 0;
      if (Notification.permission === 'denied') {
        st.textContent = '通知が許可されていません';
        note.textContent = ios ? 'iPhone の「設定」→「通知」→「HPX-note」で通知を許可してから、このページを開き直してください。' : 'ブラウザのこのサイトの設定で、通知を「許可」にしてから、このページを開き直してください。';
        return;
      }
      if (sub && !known) {   // 端末では登録してあるのに、サーバーに無い (前に外した・別のアカウントで登録した)。登録し直す
        return post('push_add', {ep: sub.endpoint, key: b64u(sub.getKey('p256dh')), auth: b64u(sub.getKey('auth')), name: devName()});
      }
      if (known) {
        st.textContent = '受け取っています';
        button('この端末では受け取らない', function(){ return sub.unsubscribe().then(function(){ return post('push_del', {ep: sub.endpoint}); }); });
        return;
      }
      st.textContent = '受け取っていません';
      button('この端末で受け取る', function(){
        return Notification.requestPermission().then(function(p){
          if (p !== 'granted') throw new Error('通知が許可されませんでした');
          return reg.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: keyBytes(PUSH_KEY)});
        }).then(function(s){ return post('push_add', {ep: s.endpoint, key: b64u(s.getKey('p256dh')), auth: b64u(s.getKey('auth')), name: devName()}); });
      });
    });
  }).catch(function(e){ st.textContent = '確かめられませんでした'; note.textContent = String(e && e.message || e); });
})();
</script>
JS;
}

// LINE でお知らせを受け取る (config.php に $HPX_LINE_TOKEN・$HPX_LINE_SECRET があるときだけ出す)
if (line_on()) {
    $l = line_link($UID);
    echo '<h2 id="line">LINE でお知らせを受け取る</h2><div class="card form">';
    if ($l) {
        echo '<p><span class="ok">連携しています</span> <span class="muted">(' . h(date('Y-m-d H:i', (int)($l['at'] ?? 0))) . ' から)</span></p>'
            . '<p class="muted">上の「お知らせメールのオン/オフ」で止めた種類は、LINE にも届きません。</p>'
            . '<form method="post">' . csrf_field() . '<input type="hidden" name="act" value="line_mode">'
            . '<p><label style="display:inline-flex;align-items:center;gap:6px"><input type="radio" name="mode" value="both"' . (empty($l['only']) ? ' checked' : '') . '> メールと LINE の両方で受け取る</label></p>'
            . '<p><label style="display:inline-flex;align-items:center;gap:6px"><input type="radio" name="mode" value="only"' . (!empty($l['only']) ? ' checked' : '') . '> LINE だけで受け取る</label>'
            . ' <span class="muted">(LINE で送れなかったときは、メールで届きます)</span></p>'
            . '<button type="submit">保存</button></form>'
            . (ai_on() ? '<h3 style="margin:16px 0 4px;font-size:15px">LINE でメモ・作業記録を送る</h3>'
                . '<p class="muted">LINE公式アカウントのトークに話し言葉で送ると、AI が蜂場・巣箱・メモか作業記録かを読み取って記録します (例「山の蜂場の1番、女王いた、王台なし、30分」)。'
                . '巣箱などが分からないときは LINE で聞き返します。送り方の例は、トークに「使い方」と送ると出ます。送った文は読み取りのため AI (Claude) に送られます。</p>'
                . '<form method="post">' . csrf_field() . '<input type="hidden" name="act" value="line_note">'
                . '<p><label style="display:inline-flex;align-items:center;gap:6px"><input type="checkbox" name="note" value="1"' . (($l['note'] ?? true) !== false ? ' checked' : '') . '> LINE で送った文を HPX-note に記録する</label></p>'
                . '<button type="submit">保存</button></form>' : '')
            . '<form method="post" style="display:inline-block;margin-top:10px">' . csrf_field() . '<input type="hidden" name="act" value="line_test"><button type="submit">テストのメッセージを送る</button></form> '
            . '<form method="post" style="display:inline-block;margin-top:10px" onsubmit="return window.confirm(\'LINE の連携を外します。よろしいですか?\')">' . csrf_field()
            . '<input type="hidden" name="act" value="line_off"><button type="submit">連携を外す</button></form>';
    } else {
        $code = '';
        $exp = 0;
        foreach (json_load(line_codes_path()) as $c => $v) if (is_array($v) && (string)($v[0] ?? '') === $UID && (int)($v[1] ?? 0) > time()) { $code = (string)$c; $exp = (int)$v[1]; }
        $add = line_add_url();
        echo '<p class="muted">電柵・巣箱・センサ・タスクのお知らせを、メールに加えて LINE でも受け取れます。</p>';
        if ($code !== '') {
            echo '<ol><li>' . ($add !== '' ? '<a href="' . h($add) . '" target="_blank" rel="noopener">Hive Plus X の LINE公式アカウント</a>' : 'Hive Plus X の LINE公式アカウント') . ' を友だちに追加します。</li>'
                . '<li>そのトークに、次の連携コードを送ります。<p style="font-size:28px;font-weight:bold;letter-spacing:.15em;margin:6px 0">' . h($code) . '</p>'
                . '<span class="muted">' . h(date('H:i', $exp)) . ' まで有効</span></li>'
                . '<li>「連携しました」と返事が届いたら、このページを開き直してください。</li></ol>';
        }
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="act" value="line_code"><button type="submit">' . ($code !== '' ? '連携コードを出し直す' : '連携コードを出す') . '</button></form>';
    }
    echo '</div>';
}

$token = (string)($acct['token'] ?? '');
// ファームウェア自動更新サーバーのURL: config の $HPX_OTA_URL、無ければこのサーバーの firmware/manifest.txt (置いてあるときだけ)
$otaUrl = trim((string)($HPX_OTA_URL ?? ''));
if ($otaUrl === '' && is_file(__DIR__ . '/firmware/manifest.txt')) $otaUrl = site_url('firmware/manifest.txt');
// HPX-CAM 用: config の $HPX_CAM_OTA_URL、無ければこのサーバーの firmware/manifest_cam.txt (置いてあるときだけ)
$camOtaUrl = trim((string)($HPX_CAM_OTA_URL ?? ''));
if ($camOtaUrl === '' && is_file(__DIR__ . '/firmware/manifest_cam.txt')) $camOtaUrl = site_url('firmware/manifest_cam.txt');
echo '<h2 id="token">HPX-Core の接続 (機器用トークン)</h2><div class="card form">'
    . '<p>HPX-Core の管理画面「動作設定」に、次の2つを入れてください。届いた記録はこのアカウントに保存され、センサページに出ます。</p>'
    . '<details><summary>送信先のURLを表示する</summary><p><input type="text" readonly value="' . h(site_url('receive.php')) . '" onclick="this.select()"></p></details>'
    . '<details><summary>送信先のトークンを表示する</summary><p><input type="text" readonly value="' . h($token) . '" onclick="this.select()"></p>'
    . '<p class="muted">このトークンを知っている人は、このアカウントに記録を送れます。人に見せないでください。'
    . ($token !== '' && !empty($acct['token_at']) ? ' (' . h(date('Y-m-d H:i', (int)$acct['token_at'])) . ' に作成)' : '') . '</p></details>'
    . ($otaUrl !== ''
        ? '<details><summary>ファームウェア自動更新サーバーのURLを表示する</summary><p><input type="text" readonly value="' . h($otaUrl) . '" onclick="this.select()"></p>'
          . '<p class="muted">入れなくても動きます。入れておくと、新しいファームウェアが出たときに HPX-Core が自分で更新します (電池なら1日1回、常時稼働なら6時間ごとに確かめます)。'
          . '管理画面「動作設定」の「ファームウェア自動更新サーバーのURL」に入れてください。</p></details>'
        : '<p class="muted">ファームウェア自動更新サーバーのURL: まだ用意されていません (HPX-Core の管理画面のその欄は空欄のままで動きます)。</p>')
    . ($camOtaUrl !== ''
        ? '<details><summary>HPX-CAM のファームウェア自動更新サーバーのURLを表示する</summary><p><input type="text" readonly value="' . h($camOtaUrl) . '" onclick="this.select()"></p>'
          . '<p class="muted">HPX-CAM の管理画面「動作設定」の「ファームウェア自動更新サーバーのURL」に入れてください。HPX-Core 用とは別の URL です。入れておくと、HPX-CAM が1日1回確かめて自分で更新します。'
          . 'HPX-CAM の送信先のURLとトークンは、HPX-Core と同じものを入れます。</p></details>'
        : '')
    . '<p class="muted">このアカウントの HPX-Core: ' . $hubCount . ' 台' . ((int)($HPX_MAX_HUBS ?? 0) > 0 && empty($ME['admin']) ? ' (' . (int)$HPX_MAX_HUBS . ' 台まで)' : '') . '</p>'
    . '<details><summary>トークンを作り直す</summary><div class="card danger"><p>今のトークンは使えなくなります。つないでいるすべての HPX-Core に、新しいトークンを入れ直してください。'
    . '入れ直すまでの記録は HPX-Core に貯まり、入れ直した後に送られます。</p>'
    . '<form method="post" onsubmit="return window.confirm(\'機器用トークンを作り直します。よろしいですか?\')">' . csrf_field()
    . '<input type="hidden" name="act" value="token_new"><button type="submit">作り直す</button></form></div></details></div>';
// データの利用への同意 (登録のときにチェックする。前からのアカウントはここで同意する)
echo '<h2 id="consent">データの利用について</h2><div class="card form">'
    . (consent_given($ME)
        ? '<p><span class="ok">同意済み</span> <span class="muted">(' . h(date('Y-m-d H:i', (int)$ME['consent'])) . ')</span></p><p class="muted">' . h(consent_text()) . '</p>'
        : '<p class="warn">まだ同意していません。</p><form method="post">' . csrf_field() . '<input type="hidden" name="act" value="consent_save">'
          . '<p>' . consent_field() . '</p><button type="submit">同意する</button></form>')
    . '</div>';
echo '<h2 id="export">データ一括ダウンロード</h2><div class="card form">'
    . '<p class="muted">蜂場・巣箱・メモ・タスク・作業記録・センサの記録を、Excel で開ける CSV にまとめて ZIP で保存します。手元への保管や、ほかのソフトへの移行に使えます。</p>'
    . '<form method="post" action="export.php">' . csrf_field()
    . '<p>センサの記録: <label style="display:inline-flex;align-items:center;gap:4px;margin-right:12px"><input type="radio" name="sensor" value="fixed" checked> 定時記録データ</label>'
    . '<label style="display:inline-flex;align-items:center;gap:4px"><input type="radio" name="sensor" value="all"> 全データ</label><br>'
    . '<span class="muted">定時記録データは、HPX-Core の保存間隔 (15分など) ごとに必ず1行で、記録が無い時刻は日時だけです。全データは、入れ直した時や「今すぐ記録」の分も含むすべての記録を、電池や電波も付けて1件1行で出します。</span></p>'
    . '<p><label style="display:inline-flex;align-items:center;gap:4px"><input type="checkbox" name="photos" value="1"> 写真も入れる (蜂場とメモの写真。ファイルが大きくなります)</label></p>'
    . '<p><button type="submit">データ一括ダウンロード</button></p></form></div>';

// バックアップと復元(backup.php)
$bf = account_dir($UID) . '/backup_before/site';
echo '<h2 id="backup">バックアップと復元</h2><div class="card form">'
    . '<p class="muted">自分の蜂場・巣箱・メモ・タスク・作業記録と、タグなどのカスタマイズ、お知らせメールの設定を1つのファイル (hpx_backup_….zip) にまとめます。'
    . 'あとでこのファイルを選んで「復元する」を押すと、その時の状態に戻せます。上の CSV とちがい、HPX-Server に戻すためのファイルです (Excel では見られません)。</p>'
    . '<form method="post" action="backup.php">' . csrf_field() . '<input type="hidden" name="act" value="download">'
    . '<p><label style="display:inline-flex;align-items:center;gap:4px"><input type="checkbox" name="photos" value="1" checked> 写真も入れる</label><br>'
    . '<label style="display:inline-flex;align-items:center;gap:4px"><input type="checkbox" name="hubs" value="1" checked> センサの記録も入れる (自分の HPX-Core の全期間。ファイルが大きくなります)</label></p>'
    . '<p><button type="submit">バックアップをダウンロード</button></p></form>'
    . '<details><summary>バックアップから復元する</summary><div class="card danger">'
    . '<p>今の蜂場・巣箱・メモ・タスク・作業記録が、バックアップの時の内容に置きかわります。バックアップの後に書いたものは消えます (直前の状態は1つだけ残るので、下のボタンで戻せます)。'
    . 'センサの記録は、バックアップに入っている HPX-Core の分だけ置きかわります。チームの設定とパスワードは変わりません。</p>'
    . '<p class="muted">送れるファイルの大きさの上限: ' . h((string)ini_get('upload_max_filesize')) . ' (サーバーの設定)。大きすぎるときは、写真を入れないバックアップを使ってください。</p>'
    . '<form method="post" action="backup.php" enctype="multipart/form-data" onsubmit="return window.confirm(\'今のデータをバックアップの内容に置きかえます。よろしいですか?\')">' . csrf_field()
    . '<input type="hidden" name="act" value="restore">'
    . '<p><label>バックアップのファイル</label><input type="file" name="file" accept=".zip,application/zip" required></p>'
    . '<p><label>今のパスワード</label><input type="password" name="cur" autocomplete="current-password" required></p>'
    . '<button type="submit">復元する</button></form>'
    . (is_dir($bf) ? '<form method="post" action="backup.php" style="margin-top:1em" onsubmit="return window.confirm(\'復元する前の状態と入れかえます。よろしいですか?\')">' . csrf_field()
        . '<input type="hidden" name="act" value="undo"><p class="muted">直前の状態: ' . h(date('Y-m-d H:i', (int)filemtime(dirname($bf)))) . ' に残したもの</p>'
        . '<p><label>今のパスワード</label><input type="password" name="cur" autocomplete="current-password" required></p>'
        . '<button type="submit">復元する前の状態に戻す</button></form>' : '')
    . '</div></details></div>';
// パスワードの欄があるフォームに「パスワードを表示」を付ける(login.php と同じ。そのフォームのパスワード欄をまとめて切り替える)
echo '<script>document.querySelectorAll("form").forEach(function(f){'
    . 'var ps=f.querySelectorAll("input[type=password]");if(!ps.length)return;'
    . 'var p=document.createElement("p"),l=document.createElement("label"),c=document.createElement("input");c.type="checkbox";'
    . 'l.style.cssText="display:inline-flex;align-items:center;gap:4px;cursor:pointer";'
    . 'c.onchange=function(){ps.forEach(function(x){x.type=c.checked?"text":"password";});};'
    . 'l.appendChild(c);l.appendChild(document.createTextNode(" パスワードを表示"));p.appendChild(l);'
    . 'var b=f.querySelector("button[type=submit],button:not([type])");var at=b&&b.closest("p")&&b.closest("p").parentNode===f?b.closest("p"):b;'
    . 'if(at&&at.parentNode)at.parentNode.insertBefore(p,at);else f.appendChild(p);});</script>';
echo '</body></html>';
