<?php
// 設定の読み込み。config.php を読んだあと、歯車の「サーバーの設定」画面 (settings.php) で保存した値で上書きする。
//   画面で保存した値は データフォルダの server_settings.json {"HPX_MAIL_FROM": "...", ...} に置く (data/.htaccess で外から開けない)。
//   画面で保存していない項目は config.php の値のまま。config.php の値は「既定値」として残る (画面の「config.php の値に戻す」で戻せる)。
//   config.php に残すもの (画面では変えない): $HPX_DATA_DIR (この設定ファイルの置き場所)、前の版からの引き継ぎ用の
//   $HPX_TOKEN・$HPX_VIEW_PASSWORD・$HPX_MAIL_TO、時刻の地域、それと秘密 (パスワード・トークン・API キー。HPX_SECRETS_ON_SCREEN を参照)。
//   アプリ (hpx/) のページは hpx_auth.php から、サイト (hiveplusx.com) のページは直接これを読む。
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// 秘密を画面で入れられるようにするか (false = config.php だけ。画面には「設定済み / 未設定」だけ出す)
const HPX_SECRETS_ON_SCREEN = false;

// 画面で変えられる項目。キー = config.php の変数名 ($ を除く)
//   g: 画面の欄、l: 名前、t: 種類 (str / email / url / int / float / bool / sel / text / terms / secret)、d: config.php に無いときの値、
//   o: 選択肢 (sel)、min・max: 数の範囲、u: 単位、hp: 説明
function hpx_settings_groups(): array
{
    return [
        'look' => 'HPX-note の表示',
        'mail' => 'メールの送信',
        'notify' => 'お知らせの基準',
        'users' => 'アカウントと利用者',
        'cam' => 'HPX-CAM (蜂場カメラ)',
        'line' => 'LINE',
        'bear' => 'クマの出没情報 (くまっぷ)',
        'ai' => 'AIメモ・記録',
        'help' => '情報サイトの AI ヘルプ',      // 情報サイトの管理ページ (report_admin.php) で変える
        'demo' => 'デモ用アカウント',            // 同上
        'secret' => 'パスワード・トークン・API キー (秘密)',
        'site_secret' => '情報サイトのパスワード・API キー (秘密)',   // 情報サイトの管理ページに出す
    ];
}

// 情報サイト (hiveplusx.com) の運営 (HPX の管理者) が決める欄。HPX-note の「サーバーの設定」には出さず、
// サイトの一番下の歯車から開く管理ページ (report_admin.php) で変える (2026-11-20 TARO 決定)
const HPX_SITE_GROUPS = ['help', 'demo', 'site_secret'];

function hpx_settings_def(): array
{
    $ai = ['claude-opus-5-5' => 'Opus 5.5 (いちばん正確・高い)', 'claude-sonnet-5-5' => 'Sonnet 5.5 (中くらい)', 'claude-haiku-5-5' => 'Haiku 5.5 (いちばん安い)'];
    return [
        // 表示
        'HPX_ORG_NAME' => ['g' => 'look', 'l' => 'このサーバーを運営する組織名・団体名', 't' => 'str', 'd' => '', 'len' => 12, 'zen' => 8, 'han' => 12, 'hp' => '入れると、上のバーのロゴの横に「× 組織名」と出ます (誰が導入したサーバーかわかるように)。空なら出しません。全角なら8文字、半角の英数字なら12文字まで (混ぜてもよく、全角2文字が半角3文字ぶんです)。'],
        // メール
        'HPX_SMTP_USER' => ['g' => 'mail', 'l' => 'メールサーバーのログイン名 (ユーザー名)', 't' => 'str', 'd' => '', 'hp' => '送信に使うメールアカウントのログイン名。ふつうはそのメールアドレスです (Gmail なら xxx@gmail.com)。パスワードは config.php の $HPX_SMTP_PASS に書きます。'],
        'HPX_MAIL_FROM' => ['g' => 'mail', 'l' => '送り元のメールアドレス (差出人)', 't' => 'email', 'd' => '', 'hp' => '受け取った人に見える差出人。空ならログイン名を使うので、ふつうは空のままで大丈夫です (Gmail ではログイン名と違うアドレスにしても、ログイン名に書き換えられます)。'],
        'HPX_SMTP_HOST' => ['g' => 'mail', 'l' => 'メールサーバー (SMTP サーバー)', 't' => 'str', 'd' => 'smtp.gmail.com', 'hp' => 'メールの会社の案内の「送信サーバー」「SMTP サーバー」。Gmail なら smtp.gmail.com、さくらのメールなら 初期ドメイン (例 xxx.sakura.ne.jp)。'],
        'HPX_SMTP_PORT' => ['g' => 'mail', 'l' => 'SMTP のポート番号', 't' => 'int', 'd' => 587, 'min' => 1, 'max' => 65535, 'hp' => 'ふつうは 587 (暗号化は tls)。465 のときは暗号化を ssl にします。'],
        'HPX_SMTP_SECURE' => ['g' => 'mail', 'l' => '暗号化の方法', 't' => 'sel', 'd' => 'tls', 'o' => ['tls' => 'tls (ポート587)', 'ssl' => 'ssl (ポート465)']],
        'HPX_CONTACT_TO' => ['g' => 'mail', 'l' => 'サイトのお問い合わせの送り先', 't' => 'email', 'd' => '', 'hp' => '空なら support@hiveplusx.com。'],
        'HPX_REPORT_TO' => ['g' => 'mail', 'l' => '「作った・使ったレポート」が届いたときの知らせ先', 't' => 'email', 'd' => '', 'hp' => '空ならお問い合わせの送り先。'],
        // お知らせの基準
        'HPX_HUB_MISSING_S' => ['g' => 'notify', 'l' => 'HPX-Core から届かないとき', 't' => 'int', 'd' => 3600, 'min' => 600, 'max' => 604800, 'u' => '秒', 'hp' => 'この秒数届かなければ知らせます (3600 = 1時間)。'],
        'HPX_ALERT_MISSING_S' => ['g' => 'notify', 'l' => 'HPX-F の記録が来ないとき', 't' => 'int', 'd' => 3600, 'min' => 600, 'max' => 604800, 'u' => '秒'],
        'HPX_SENSOR_MISSING_S' => ['g' => 'notify', 'l' => 'HPX-W・HPX-TH・温湿度計の記録が来ないとき', 't' => 'int', 'd' => 86400, 'min' => 600, 'max' => 604800, 'u' => '秒', 'hp' => '86400 = 24時間。'],
        'HPX_ALERT_BATT_V' => ['g' => 'notify', 'l' => 'HPX-F の電池電圧がこれ未満', 't' => 'float', 'd' => 2.6, 'min' => 1, 'max' => 5, 'u' => 'V'],
        'HPX_W_BATT_V' => ['g' => 'notify', 'l' => 'HPX-W の電池電圧がこれ未満', 't' => 'float', 'd' => 2.8, 'min' => 1, 'max' => 5, 'u' => 'V'],
        'HPX_TH_BATT_V' => ['g' => 'notify', 'l' => 'HPX-TH の電池電圧がこれ未満', 't' => 'float', 'd' => 2.7, 'min' => 1, 'max' => 5, 'u' => 'V'],
        'HPX_BATT12_WARN_V' => ['g' => 'notify', 'l' => '12V バッテリーの電圧がこれ未満', 't' => 'float', 'd' => 12.0, 'min' => 9, 'max' => 15, 'u' => 'V', 'hp' => '鉛バッテリーで残り半分くらいが 12.0V。'],
        'HPX_BATT12_OK_V' => ['g' => 'notify', 'l' => '12V バッテリーが戻ったとする電圧', 't' => 'float', 'd' => 12.6, 'min' => 9, 'max' => 15, 'u' => 'V', 'hp' => '知らせたあと、これ以上に戻ったら「戻りました」を知らせます。'],
        'HPX_TODO_MAIL_HOUR' => ['g' => 'notify', 'l' => 'タスクのお知らせメールを送る時刻', 't' => 'int', 'd' => 7, 'min' => 0, 'max' => 23, 'u' => '時'],
        // アカウントと利用者
        'HPX_SITE_URL' => ['g' => 'users', 'l' => 'HPX-note の URL', 't' => 'url', 'd' => '', 'hp' => '招待・再設定のメールに書く URL の元 (例 https://hiveplusx.com/hpx)。空なら開いたページの URL から決めます。'],
        'HPX_MAX_HUBS' => ['g' => 'users', 'l' => '1つのアカウントにつなげる HPX-Core の台数', 't' => 'int', 'd' => 5, 'min' => 0, 'max' => 1000, 'u' => '台', 'hp' => '0 なら無制限。サーバー管理者はいつも無制限。'],
        'HPX_MAX_CAMS' => ['g' => 'users', 'l' => '1つのアカウントにつなげるカメラの台数', 't' => 'int', 'd' => 5, 'min' => 0, 'max' => 1000, 'u' => '台', 'hp' => '0 なら無制限。'],
        'HPX_CONSENT_TEXT' => ['g' => 'users', 'l' => '登録のときに同意してもらう「データの利用」の文', 't' => 'text', 'd' => '', 'hp' => '空なら決まった文。'],
        'HPX_TERMS_URL' => ['g' => 'users', 'l' => '利用規約のページの URL', 't' => 'url', 'd' => '', 'hp' => '空なら同意の文にリンクを付けません。'],
        'HPX_EXPORT_REMIND_DAYS' => ['g' => 'users', 'l' => 'サーバー管理者に全データのダウンロードを促すまで', 't' => 'int', 'd' => 30, 'min' => 0, 'max' => 3650, 'u' => '日', 'hp' => '0 なら知らせません。'],
        'HPX_USER_DL_REMIND_DAYS' => ['g' => 'users', 'l' => '利用者に自分のデータのダウンロードを促すまで', 't' => 'int', 'd' => 90, 'min' => 0, 'max' => 3650, 'u' => '日', 'hp' => '0 なら知らせません。'],
        // カメラ
        'HPX_CAM_KEEP_DAYS' => ['g' => 'cam', 'l' => '写真を残す日数', 't' => 'int', 'd' => 365, 'min' => 0, 'max' => 36500, 'u' => '日', 'hp' => 'これより古い日の写真は自動で消します (0 なら消さない)。カメラ1台で1年 約2GB。'],
        'HPX_CAM_AI_MODEL' => ['g' => 'cam', 'l' => '大きな変化を確かめる AI のモデル', 't' => 'sel', 'd' => 'claude-haiku-5-5', 'o' => $ai, 'hp' => 'Haiku で1回 約0.05円、Sonnet で 約1円。'],
        'HPX_CAM_AI_DAY_MAX' => ['g' => 'cam', 'l' => '1台・1日に AI に聞く回数の上限', 't' => 'int', 'd' => 20, 'min' => 0, 'max' => 1000, 'u' => '回', 'hp' => '0 なら上限なし。'],
        'HPX_CAM_QUIET_MIN' => ['g' => 'cam', 'l' => '知らせたあと、同じカメラで知らせない時間', 't' => 'int', 'd' => 180, 'min' => 0, 'max' => 10080, 'u' => '分'],
        // LINE
        'HPX_LINE_ADD_URL' => ['g' => 'line', 'l' => '友だち追加の URL', 't' => 'url', 'd' => '', 'hp' => '例 https://line.me/R/ti/p/@222xdxtk。マイアカウントの LINE 連携の説明に出します。'],
        // クマの出没情報 (くまっぷ)
        'HPX_BEAR_BLINK_DAYS' => ['g' => 'bear', 'l' => '新しい情報のあと、熊のマークを点滅させる日数', 't' => 'int', 'd' => 7, 'min' => 0, 'max' => 365, 'u' => '日', 'hp' => 'API キーは config.php の $HPX_KUMAP_KEY に書きます (くまっぷとの契約が要ります)。空ならこの機能は出ません。'],
        'HPX_KUMAP_API' => ['g' => 'bear', 'l' => 'くまっぷ API の URL', 't' => 'url', 'd' => '', 'hp' => '空なら決まった URL。くまっぷから変更の案内があったときだけ入れます。'],
        'HPX_KUMAP_WEB' => ['g' => 'bear', 'l' => 'くまっぷの画面の URL (お知らせのリンク)', 't' => 'url', 'd' => '', 'hp' => '空なら https://kumap-xenon.web.app'],
        // AI メモ
        'HPX_AI_MODEL' => ['g' => 'ai', 'l' => '読み取りに使うモデル', 't' => 'sel', 'd' => 'claude-opus-5-5', 'o' => $ai, 'hp' => 'Haiku にすると利用料はずっと安くなります (正確さは少し下がるかもしれません)。'],
        'HPX_AI_DAY_MAX' => ['g' => 'ai', 'l' => '1人が1日に記録できる回数', 't' => 'int', 'd' => 50, 'min' => 0, 'max' => 10000, 'u' => '回'],
        'HPX_AI_TERMS' => ['g' => 'ai', 'l' => '養蜂の用語集に足す言葉', 't' => 'terms', 'd' => [], 'hp' => '1行に1語。「正しい言葉 = 化けやすい書き方, 化けやすい書き方」と書きます。例: 待ち箱 = 町箱'],
        // AI ヘルプ
        'HPX_HELP_MODEL' => ['g' => 'help', 'l' => '答えに使うモデル', 't' => 'sel', 'd' => 'claude-haiku-5-5', 'o' => $ai, 'hp' => 'Sonnet は Haiku の約20倍の料金。'],
        'HPX_HELP_DAY_MAX' => ['g' => 'help', 'l' => '1台の端末が1日に質問できる回数', 't' => 'int', 'd' => 20, 'min' => 0, 'max' => 10000, 'u' => '回'],
        'HPX_HELP_IP_DAY_MAX' => ['g' => 'help', 'l' => '同じ接続元 (IP) から1日に質問できる回数', 't' => 'int', 'd' => 60, 'min' => 0, 'max' => 100000, 'u' => '回'],
        'HPX_HELP_SITE_DAY_MAX' => ['g' => 'help', 'l' => 'サイト全体で1日に答える回数', 't' => 'int', 'd' => 300, 'min' => 0, 'max' => 100000, 'u' => '回'],
        'HPX_HELP_TURN_MAX' => ['g' => 'help', 'l' => '1つの対話で続けて質問できる回数', 't' => 'int', 'd' => 8, 'min' => 1, 'max' => 100, 'u' => '回'],
        'HPX_HELP_CACHE_TTL' => ['g' => 'help', 'l' => 'サイトの内容をキャッシュしておく時間', 't' => 'sel', 'd' => '5m', 'o' => ['5m' => '5分', '1h' => '1時間 (質問が数十分おきに来るとき)']],
        'HPX_HELP_GITHUB' => ['g' => 'help', 'l' => '答えに使う GitHub のリポジトリ', 't' => 'str', 'd' => 'Hive-Plus-X/hiveplusx', 'hp' => '「持ち主/名前」。空なら使いません。'],
        // デモ
        'HPX_DEMO_EMAIL' => ['g' => 'demo', 'l' => 'デモ用アカウントのメールアドレス', 't' => 'email', 'd' => '', 'hp' => 'このアカウントはパスワードを公開して誰でも試せます。空ならデモなし。'],
        'HPX_DEMO_READONLY' => ['g' => 'demo', 'l' => '見るだけにする (書き込みもできない)', 't' => 'bool', 'd' => false],
        'HPX_DEMO_RESET_HOUR' => ['g' => 'demo', 'l' => '毎日初期状態に戻す時刻', 't' => 'int', 'd' => 3, 'min' => 0, 'max' => 23, 'u' => '時'],
        'HPX_DEMO_DUMMY' => ['g' => 'demo', 'l' => 'センサの記録を見本の値で作る', 't' => 'bool', 'd' => true, 'hp' => 'オフなら届いた記録を使います。'],
        // 秘密
        'HPX_SMTP_PASS' => ['g' => 'secret', 'l' => 'メールサーバーのパスワード (Gmail ならアプリパスワード)', 't' => 'secret', 'd' => ''],
        'HPX_CRON_TOKEN' => ['g' => 'secret', 'l' => 'hpx_cron.php を URL から呼ぶときのトークン', 't' => 'secret', 'd' => ''],
        'HPX_REPORT_ADMIN_PW' => ['g' => 'site_secret', 'l' => '「作った・使ったレポート」の管理ページのパスワード', 't' => 'secret', 'd' => ''],
        'HPX_LINE_TOKEN' => ['g' => 'secret', 'l' => 'LINE のチャネルアクセストークン', 't' => 'secret', 'd' => ''],
        'HPX_LINE_SECRET' => ['g' => 'secret', 'l' => 'LINE のチャネルシークレット', 't' => 'secret', 'd' => ''],
        'HPX_KUMAP_KEY' => ['g' => 'secret', 'l' => 'くまっぷの API キー (クマの出没情報)', 't' => 'secret', 'd' => ''],
        'HPX_AI_KEY' => ['g' => 'secret', 'l' => 'Claude の API キー (AIメモ・記録)', 't' => 'secret', 'd' => ''],
        'HPX_CAM_AI_KEY' => ['g' => 'secret', 'l' => 'Claude の API キー (カメラ。空なら AIメモのキー)', 't' => 'secret', 'd' => ''],
        'HPX_HELP_KEY' => ['g' => 'site_secret', 'l' => 'Claude の API キー (AI ヘルプ。空なら AIメモのキー)', 't' => 'secret', 'd' => ''],
        'HPX_HELP_GITHUB_TOKEN' => ['g' => 'site_secret', 'l' => 'GitHub の読み取り用トークン (非公開リポジトリのときだけ)', 't' => 'secret', 'd' => ''],
    ];
}

function hpx_settings_path(): string
{
    return rtrim((string)($GLOBALS['HPX_DATA_DIR'] ?? (__DIR__ . '/data')), '/') . '/server_settings.json';
}

// 画面で保存した値 (無ければ空)
function hpx_settings_saved(): array
{
    $raw = @file_get_contents(hpx_settings_path());
    $j = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($j) ? $j : [];
}

// 画面で変えてよい項目か (秘密は HPX_SECRETS_ON_SCREEN のときだけ)
function hpx_setting_editable(array $d): bool
{
    return $d['t'] !== 'secret' || HPX_SECRETS_ON_SCREEN;
}

// 値を項目の種類に合わせる (おかしい値は null)
function hpx_setting_cast(array $d, $v)
{
    switch ($d['t']) {
        case 'int': return is_numeric($v) ? (int)$v : null;
        case 'float': return is_numeric($v) ? (float)$v : null;
        case 'bool': return (bool)$v;
        case 'terms':
            if (!is_array($v)) return null;
            $out = [];
            foreach ($v as $k => $vs) {
                if (!is_string($k) || $k === '') continue;
                $out[$k] = array_values(array_filter(array_map('strval', is_array($vs) ? $vs : [$vs]), fn($s) => $s !== ''));
            }
            return $out;
        case 'sel': return is_string($v) && isset($d['o'][$v]) ? $v : null;
        default: return is_scalar($v) ? (string)$v : null;
    }
}

// 入力を項目の種類に合わせて確かめる。[値, エラーの文 (なければ '')]
function hpx_st_parse(array $d, string $raw): array
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
            if (isset($d['zen'], $d['han']) && function_exists('text_fit_zh') && text_fit_zh($raw, (int)$d['zen'], (int)$d['han']) !== $raw) return [null, '全角なら' . (int)$d['zen'] . '文字、半角なら' . (int)$d['han'] . '文字までにしてください'];
            return [$raw, ''];
    }
}

// 画面に出す形
function hpx_st_show(array $d, $v): string
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

function hpx_st_same(array $d, $a, $b): bool
{
    if ($d['t'] === 'float') return abs((float)$a - (float)$b) < 1e-9;
    if ($d['t'] === 'int') return (int)$a === (int)$b;
    if ($d['t'] === 'bool') return (bool)$a === (bool)$b;
    return hpx_st_show($d, $a) === hpx_st_show($d, $b);
}


// 1つの欄 ($g) の入力を保存する。$in = $_POST['v']、$reset = $_POST['reset']。直してほしい項目の文の一覧を返す
//   config.php と同じ値のとき・「config.php の値に戻す」のときは、保存した値を消す
function hpx_settings_save(string $g, array $in, array $reset): array
{
    $errs = [];
    $set = [];
    $unset = [];
    foreach (hpx_settings_def() as $k => $d) {
        if ($d['g'] !== $g || !hpx_setting_editable($d)) continue;
        if (!empty($reset[$k])) { $unset[] = $k; continue; }
        if ($d['t'] !== 'bool' && !array_key_exists($k, $in)) continue;
        [$v, $err] = hpx_st_parse($d, (string)($in[$k] ?? ''));
        if ($err !== '') { $errs[] = $d['l'] . ': ' . $err; continue; }
        if (hpx_st_same($d, $v, $GLOBALS['HPX_CONFIG_FILE'][$k] ?? $d['d'])) $unset[] = $k;
        else $set[$k] = $v;
    }
    $path = hpx_settings_path();
    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0755, true);
    $lock = @fopen($path . '.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    $c = hpx_settings_saved();
    foreach ($unset as $k) unset($c[$k]);
    $c = array_merge($c, $set);
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) @rename($tmp, $path);
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    return $errs;
}

function hpx_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

// 1つの欄 ($g) の項目の入力欄 (HPX-note の settings.php と、情報サイトの report_admin.php で使う)
function hpx_settings_rows(string $g): string
{
    $o = '';
    $saved = hpx_settings_saved();
    foreach (array_filter(hpx_settings_def(), fn($d) => $d['g'] === $g) as $k => $d) {
        $cur = array_key_exists($k, $GLOBALS['HPX_SETTINGS_SHOWN'] ?? []) ? $GLOBALS['HPX_SETTINGS_SHOWN'][$k] : ($GLOBALS[$k] ?? $d['d']);
        $cfg = ($GLOBALS['HPX_CONFIG_FILE'][$k] ?? $d['d']);
        $changed = array_key_exists($k, $saved) && hpx_setting_editable($d);
        $o .= '<div class="st-row st-in"><label for="f_' . $k . '"><b>' . hpx_h($d['l']) . '</b>' . ($changed ? '<span class="st-ch">画面で変更</span>' : '') . '</label>';
        if (!hpx_setting_editable($d)) {
            $has = trim((string)(is_scalar($cur) ? $cur : '')) !== '';
            $o .= '<div>' . ($has ? '<span class="ok">設定済み</span>' : '<span class="muted">未設定</span>') . ' <span class="st-cf">config.php の $' . hpx_h($k) . ' に書きます</span></div></div>';
            continue;
        }
        $name = 'v[' . $k . ']';
        $id = ' id="f_' . $k . '"';
        switch ($d['t']) {
            case 'bool':
                $o .= '<label style="display:inline-flex;align-items:center;gap:4px"><input type="checkbox"' . $id . ' name="' . $name . '" value="1"' . ($cur ? ' checked' : '') . '> オン</label>';
                break;
            case 'sel':
                $o .= '<select' . $id . ' name="' . $name . '">';
                $opts = $d['o'];
                if (!isset($opts[(string)$cur])) $opts = [(string)$cur => (string)$cur] + $opts;
                foreach ($opts as $ov => $ol) $o .= '<option value="' . hpx_h((string)$ov) . '"' . ((string)$ov === (string)$cur ? ' selected' : '') . '>' . hpx_h((string)$ol) . '</option>';
                $o .= '</select>';
                break;
            case 'text':
            case 'terms':
                $o .= '<textarea' . $id . ' name="' . $name . '">' . hpx_h(hpx_st_show($d, $cur)) . '</textarea>';
                break;
            case 'int':
            case 'float':
                $o .= '<input type="number"' . $id . ' name="' . $name . '" value="' . hpx_h(hpx_st_show($d, $cur)) . '"' . ($d['t'] === 'float' ? ' step="0.01"' : ' step="1"')
                    . (isset($d['min']) ? ' min="' . $d['min'] . '"' : '') . (isset($d['max']) ? ' max="' . $d['max'] . '"' : '') . ' style="width:9em"> ' . hpx_h((string)($d['u'] ?? ''));
                break;
            default:
                $o .= '<input type="text"' . $id . ' name="' . $name . '" value="' . hpx_h(hpx_st_show($d, $cur)) . '"' . ($d['t'] === 'email' ? ' inputmode="email"' : ($d['t'] === 'url' ? ' inputmode="url"' : '')) . (isset($d['len']) ? ' maxlength="' . (int)$d['len'] . '"' : '') . '>';
        }
        if (!empty($d['hp'])) $o .= '<div class="muted">' . hpx_h($d['hp']) . '</div>';
        $cfgShow = hpx_st_show($d, $cfg);
        $o .= '<div class="st-cf">config.php の値: ' . ($cfgShow === '' ? '(空)' : hpx_h(mb_strimwidth(str_replace("\n", ' / ', $cfgShow), 0, 80, '…')))
            . ($changed ? ' <label style="display:inline-flex;align-items:center;gap:4px;margin-left:8px"><input type="checkbox" name="reset[' . $k . ']" value="1"> config.php の値に戻す</label>' : '')
            . '</div></div>';
    }
    return $o;
}

// config.php に書いてある値 (画面で上書きする前)。画面の「config.php の値」に出す
$HPX_CONFIG_FILE = [];
foreach (hpx_settings_def() as $__hk => $__hd) {
    $HPX_CONFIG_FILE[$__hk] = array_key_exists($__hk, $GLOBALS) ? $GLOBALS[$__hk] : $__hd['d'];
}
// 画面で保存した値で上書きする
foreach (hpx_settings_saved() as $__hk => $__hv) {
    $__hd = hpx_settings_def()[$__hk] ?? null;
    if ($__hd === null || !hpx_setting_editable($__hd)) continue;
    $__hv = hpx_setting_cast($__hd, $__hv);
    if ($__hv !== null) $GLOBALS[$__hk] = $__hv;
}
unset($__hk, $__hd, $__hv);
// 送り元が空なら、メールサーバーのログイン名 (メールアドレスの形のとき) を使う。画面には空のまま出す (HPX_SETTINGS_SHOWN)
$HPX_SETTINGS_SHOWN = ['HPX_MAIL_FROM' => $GLOBALS['HPX_MAIL_FROM'] ?? ''];
if (trim((string)($GLOBALS['HPX_MAIL_FROM'] ?? '')) === '' && filter_var(trim((string)($GLOBALS['HPX_SMTP_USER'] ?? '')), FILTER_VALIDATE_EMAIL)) {
    $GLOBALS['HPX_MAIL_FROM'] = trim((string)$GLOBALS['HPX_SMTP_USER']);
}
