<?php
// サーバーの設定 (サーバー管理者だけ): config.php の設定のうち、画面で変えてよいものをここで変える。
//   保存した値はデータフォルダの server_settings.json に置き、config.php の値より優先する (hpx_config.php)。
//   config.php と同じ値にしたとき・「config.php の値に戻す」を選んだときは、保存した値を消す (config.php の値に戻る)。
//   秘密 (パスワード・トークン・API キー) は config.php に書く。ここには設定済みかどうかだけ出す (HPX_SECRETS_ON_SCREEN)。
declare(strict_types=1);
require __DIR__ . '/hpx_site.php';
if (empty($ME['admin'])) {
    http_response_code(403);
    exit('このページはサーバー管理者だけが開けます。');
}

$DEFS = hpx_settings_def();
$GROUPS = array_diff_key(hpx_settings_groups(), array_flip(HPX_SITE_GROUPS));   // AI ヘルプ・デモは情報サイトの管理ページで

if (is_post()) {
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'save') {
        $g = (string)($_POST['g'] ?? '');
        if (!isset($GROUPS[$g])) redirect('settings.php');   // 情報サイトの欄はここでは保存しない
        $in = is_array($_POST['v'] ?? null) ? $_POST['v'] : [];
        $reset = is_array($_POST['reset'] ?? null) ? $_POST['reset'] : [];
        $errs = hpx_settings_save($g, $in, $reset);
        redirect('settings.php#g_' . $g, $errs ? '保存しましたが、次の項目は直してください (前の値のままです)。 ' . implode(' / ', $errs) : '「' . $GROUPS[$g] . '」を保存しました。');
    }
    if ($act === 'testmail') {
        $to = (string)($ME['email'] ?? '');
        [$ok, $msg] = hpx_smtp_send([$to], (string)($HPX_MAIL_FROM ?? ''), '【HPX】テストメール',
            "サーバーの設定画面から送ったテストメールです。\nこのメールが届いていれば、メールの送信の設定は正しく動いています。\n\n" . site_url('settings.php') . "\n");
        redirect('settings.php#g_mail', $ok ? $to . ' にテストメールを送りました。届いたか確かめてください (迷惑メールのフォルダも)。' : 'テストメールを送れませんでした: ' . $msg);
    }
    redirect('settings.php');
}

$saved = hpx_settings_saved();
page_head('サーバーの設定', 'settings.php');
echo flash_html();
echo '<style>.st-row{border-top:1px solid #eee;padding:8px 0}.st-row:first-of-type{border-top:0}.st-ch{display:inline-block;font-size:.8em;background:#F2A900;color:#1F2328;border-radius:10px;padding:0 8px;margin-left:6px}'
    . '.st-in input[type=text],.st-in input[type=number],.st-in select,.st-in textarea{max-width:100%}.st-in input[type=text]{width:28em}.st-in textarea{width:100%;min-height:5em}'
    . '.st-toc a{display:inline-block;margin:2px 10px 2px 0}.st-cf{font-size:.85em;color:#777}</style>';
echo '<div class="card"><p>ここで変えた値は、config.php の値より優先されます。変えた項目には <span class="st-ch">画面で変更</span> の印が付きます。'
    . '「config.php の値に戻す」にチェックして保存すると、config.php に書いてある値に戻ります。</p>'
    . '<p class="muted">config.php に残すもの (この画面では変えられません): データの保存先 ($HPX_DATA_DIR)、前の版からの引き継ぎ用の $HPX_TOKEN・$HPX_VIEW_PASSWORD・$HPX_MAIL_TO、時刻の地域、'
    . (HPX_SECRETS_ON_SCREEN ? '' : 'パスワード・トークン・API キー。') . '</p>'
    . '<p class="st-toc">';
foreach ($GROUPS as $g => $gl) echo '<a href="#g_' . $g . '">' . h($gl) . '</a>';
echo '</p></div>';

foreach ($GROUPS as $g => $gl) {
    $items = array_filter($DEFS, fn($d) => $d['g'] === $g);
    $editable = array_filter($items, 'hpx_setting_editable');
    echo '<h2 id="g_' . $g . '">' . h($gl) . '</h2><div class="card form">';
    if ($editable) echo '<form method="post">' . csrf_field() . '<input type="hidden" name="act" value="save"><input type="hidden" name="g" value="' . $g . '">';
    echo hpx_settings_rows($g);
    if ($editable) echo '<p><button type="submit">「' . h($gl) . '」を保存</button></p></form>';
    if ($g === 'mail') {
        echo '<form method="post" class="inl">' . csrf_field() . '<input type="hidden" name="act" value="testmail">'
            . '<button type="submit">自分 (' . h((string)($ME['email'] ?? '')) . ') にテストメールを送る</button></form> <span class="muted">保存してから押してください。</span>';
    }
    if ($g === 'secret' && !HPX_SECRETS_ON_SCREEN) {
        echo '<p class="muted">秘密は、管理者のアカウントが乗っ取られたときに漏れないよう、この画面では変えません。サーバーの config.php を直接書き換えてください。</p>';
    }
    echo '</div>';
}
echo '<p><a href="admin.php">← ユーザー管理へ戻る</a></p></body></html>';
