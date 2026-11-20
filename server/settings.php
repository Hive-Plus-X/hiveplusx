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
$GROUPS = hpx_settings_groups();

// 入力を項目の種類に合わせて確かめる。[値, エラーの文 (なければ '')]
function st_parse(array $d, string $raw): array
{
    $raw = str_replace("\r\n", "\n", $raw);
    $t = $d['t'];
    if ($t !== 'text' && $t !== 'terms') $raw = trim($raw);
    switch ($t) {
        case 'int':
        case 'float':
            if ($raw === '' || !is_numeric($raw)) return [null, '数を入れてください'];
            $v = $t === 'int' ? (int)$raw : (float)$raw;
            if ($t === 'int' && (string)(int)$raw !== ltrim($raw, '+')) return [null, '整数を入れてください'];
            if (isset($d['min']) && $v < $d['min']) return [null, $d['min'] . ' 以上にしてください'];
            if (isset($d['max']) && $v > $d['max']) return [null, $d['max'] . ' 以下にしてください'];
            return [$v, ''];
        case 'bool': return [$raw === '1', ''];
        case 'sel': return isset($d['o'][$raw]) ? [$raw, ''] : [null, '選びなおしてください'];
        case 'email':
            if ($raw !== '' && !filter_var($raw, FILTER_VALIDATE_EMAIL)) return [null, 'メールアドレスの形になっていません'];
            return [$raw, ''];
        case 'url':
            $raw = rtrim($raw, '/') === '' ? '' : $raw;
            if ($raw !== '' && !preg_match('#^https?://[^\s"\'<>]+$#', $raw)) return [null, 'http:// か https:// で始まる URL にしてください'];
            return [$raw, ''];
        case 'text':
            $raw = trim($raw);
            if (mb_strlen($raw) > 3000) return [null, '3000文字までにしてください'];
            return [$raw, ''];
        case 'terms':
            $out = [];
            foreach (explode("\n", $raw) as $i => $ln) {
                $ln = trim($ln);
                if ($ln === '') continue;
                $p = preg_split('/\s*[=＝]\s*/u', $ln, 2);
                $word = trim((string)$p[0]);
                if ($word === '') return [null, ($i + 1) . '行目: 正しい言葉がありません'];
                $alts = isset($p[1]) ? preg_split('/\s*[,、，]\s*/u', trim($p[1])) : [];
                $out[$word] = array_values(array_filter(array_map('trim', $alts), fn($s) => $s !== ''));
            }
            return [$out, ''];
        default:
            if (preg_match('/[\x00-\x1f]/', $raw)) return [null, '1行で入れてください'];
            $len = (int)($d['len'] ?? 300);
            if (mb_strlen($raw) > $len) return [null, $len . '文字までにしてください'];
            if (isset($d['zen'], $d['han']) && text_fit_zh($raw, (int)$d['zen'], (int)$d['han']) !== $raw) return [null, '全角なら' . (int)$d['zen'] . '文字、半角なら' . (int)$d['han'] . '文字までにしてください'];
            return [$raw, ''];
    }
}

// 画面に出す形
function st_show(array $d, $v): string
{
    switch ($d['t']) {
        case 'bool': return $v ? 'オン' : 'オフ';
        case 'sel': return (string)($d['o'][(string)$v] ?? (string)$v);
        case 'terms':
            $ls = [];
            foreach ((array)$v as $w => $alts) $ls[] = $w . ' = ' . implode(', ', (array)$alts);
            return implode("\n", $ls);
        default:
            $s = is_float($v) ? rtrim(rtrim(sprintf('%.2f', $v), '0'), '.') : (string)$v;
            return $s;
    }
}

function st_same(array $d, $a, $b): bool
{
    if ($d['t'] === 'float') return abs((float)$a - (float)$b) < 1e-9;
    if ($d['t'] === 'int') return (int)$a === (int)$b;
    if ($d['t'] === 'bool') return (bool)$a === (bool)$b;
    return st_show($d, $a) === st_show($d, $b);
}

if (is_post()) {
    $act = (string)($_POST['act'] ?? '');
    if ($act === 'save') {
        $g = (string)($_POST['g'] ?? '');
        if (!isset($GROUPS[$g])) redirect('settings.php');
        $in = is_array($_POST['v'] ?? null) ? $_POST['v'] : [];
        $reset = is_array($_POST['reset'] ?? null) ? $_POST['reset'] : [];
        $errs = [];
        $set = [];
        $unset = [];
        foreach ($DEFS as $k => $d) {
            if ($d['g'] !== $g || !hpx_setting_editable($d)) continue;
            if (!empty($reset[$k])) { $unset[] = $k; continue; }
            if ($d['t'] !== 'bool' && !array_key_exists($k, $in)) continue;
            [$v, $err] = st_parse($d, (string)($in[$k] ?? ''));
            if ($err !== '') { $errs[] = $d['l'] . ': ' . $err; continue; }
            if (st_same($d, $v, $HPX_CONFIG_FILE[$k])) $unset[] = $k;
            else $set[$k] = $v;
        }
        json_update(hpx_settings_path(), function (array $c) use ($set, $unset) {
            foreach ($unset as $k) unset($c[$k]);
            return array_merge($c, $set);
        });
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
    foreach ($items as $k => $d) {
        $cur = $GLOBALS[$k] ?? $d['d'];
        $cfg = $HPX_CONFIG_FILE[$k];
        $changed = array_key_exists($k, $saved) && hpx_setting_editable($d);
        echo '<div class="st-row st-in"><label for="f_' . $k . '"><b>' . h($d['l']) . '</b>' . ($changed ? '<span class="st-ch">画面で変更</span>' : '') . '</label>';
        if (!hpx_setting_editable($d)) {
            $has = trim((string)(is_scalar($cur) ? $cur : '')) !== '';
            echo '<div>' . ($has ? '<span class="ok">設定済み</span>' : '<span class="muted">未設定</span>') . ' <span class="st-cf">config.php の $' . h($k) . ' に書きます</span></div></div>';
            continue;
        }
        $name = 'v[' . $k . ']';
        $id = ' id="f_' . $k . '"';
        switch ($d['t']) {
            case 'bool':
                echo '<label style="display:inline-flex;align-items:center;gap:4px"><input type="checkbox"' . $id . ' name="' . $name . '" value="1"' . ($cur ? ' checked' : '') . '> オン</label>';
                break;
            case 'sel':
                echo '<select' . $id . ' name="' . $name . '">';
                $opts = $d['o'];
                if (!isset($opts[(string)$cur])) $opts = [(string)$cur => (string)$cur] + $opts;
                foreach ($opts as $ov => $ol) echo '<option value="' . h((string)$ov) . '"' . ((string)$ov === (string)$cur ? ' selected' : '') . '>' . h((string)$ol) . '</option>';
                echo '</select>';
                break;
            case 'text':
            case 'terms':
                echo '<textarea' . $id . ' name="' . $name . '">' . h(st_show($d, $cur)) . '</textarea>';
                break;
            case 'int':
            case 'float':
                echo '<input type="number"' . $id . ' name="' . $name . '" value="' . h(st_show($d, $cur)) . '"' . ($d['t'] === 'float' ? ' step="0.01"' : ' step="1"')
                    . (isset($d['min']) ? ' min="' . $d['min'] . '"' : '') . (isset($d['max']) ? ' max="' . $d['max'] . '"' : '') . ' style="width:9em"> ' . h((string)($d['u'] ?? ''));
                break;
            default:
                echo '<input type="text"' . $id . ' name="' . $name . '" value="' . h(st_show($d, $cur)) . '"' . ($d['t'] === 'email' ? ' inputmode="email"' : ($d['t'] === 'url' ? ' inputmode="url"' : '')) . (isset($d['len']) ? ' maxlength="' . (int)$d['len'] . '"' : '') . '>';
        }
        if (!empty($d['hp'])) echo '<div class="muted">' . h($d['hp']) . '</div>';
        $cfgShow = st_show($d, $cfg);
        echo '<div class="st-cf">config.php の値: ' . ($cfgShow === '' ? '(空)' : h(mb_strimwidth(str_replace("\n", ' / ', $cfgShow), 0, 80, '…')))
            . ($changed ? ' <label style="display:inline-flex;align-items:center;gap:4px;margin-left:8px"><input type="checkbox" name="reset[' . $k . ']" value="1"> config.php の値に戻す</label>' : '')
            . '</div></div>';
    }
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
echo '<p><a href="admin.php">← サーバー管理へ戻る</a></p></body></html>';
