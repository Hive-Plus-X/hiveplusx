<?php
// 設定ページ: ログインしている本人のアカウントの設定。
//   表示名、パスワード、メールアドレス(=ログインID)の変更、通知メールの送り先、お知らせメールのオン/オフ、HPX-Core に入れる機器用トークン、
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

$token = (string)($acct['token'] ?? '');
echo '<h2 id="token">HPX-Core の接続 (機器用トークン)</h2><div class="card form">'
    . '<p>HPX-Core の管理画面「動作設定」に、次の2つを入れてください。届いた記録はこのアカウントに保存され、センサページに出ます。</p>'
    . '<details><summary>送信先のURLを表示する</summary><p><input type="text" readonly value="' . h(site_url('receive.php')) . '" onclick="this.select()"></p></details>'
    . '<details><summary>送信先のトークンを表示する</summary><p><input type="text" readonly value="' . h($token) . '" onclick="this.select()"></p>'
    . '<p class="muted">このトークンを知っている人は、このアカウントに記録を送れます。人に見せないでください。'
    . ($token !== '' && !empty($acct['token_at']) ? ' (' . h(date('Y-m-d H:i', (int)$acct['token_at'])) . ' に作成)' : '') . '</p></details>'
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
    . '<p><label style="display:inline-flex;align-items:center;gap:4px"><input type="checkbox" name="photos" value="1"> 写真も入れる (蜂場とメモの写真。ファイルが大きくなります)</label></p>'
    . '<p><button type="submit">データ一括ダウンロード</button></p></form></div>';

// バックアップと復元(backup.php)
$bf = account_dir($UID) . '/backup_before/site';
echo '<h2 id="backup">バックアップと復元</h2><div class="card form">'
    . '<p class="muted">自分の蜂場・巣箱・メモ・タスク・作業記録と、タグなどのカスタマイズ、お知らせメールの設定を1つのファイル (hpx_backup_….zip) にまとめます。'
    . 'あとでこのファイルを選んで「復元する」を押すと、その時の状態に戻せます。上の CSV とちがい、HPX-Server に戻すためのファイルです (Excel では見られません)。</p>'
    . '<form method="post" action="backup.php">' . csrf_field() . '<input type="hidden" name="act" value="download">'
    . '<p><label style="display:inline-flex;align-items:center;gap:4px"><input type="checkbox" name="photos" value="1" checked> 写真も入れる</label><br>'
    . '<label style="display:inline-flex;align-items:center;gap:4px"><input type="checkbox" name="hubs" value="1"> センサの記録も入れる (自分の HPX-Core の全期間。ファイルが大きくなります)</label></p>'
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
