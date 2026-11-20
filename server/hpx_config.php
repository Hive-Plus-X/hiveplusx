<?php
// 設定の読み込み。config.php を読んだあと、サーバー管理の「サーバーの設定」画面 (settings.php) で保存した値で上書きする。
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
        'ai' => 'AIメモ・記録',
        'help' => '情報サイトの AI ヘルプ',
        'demo' => 'デモ用アカウント',
        'secret' => 'パスワード・トークン・API キー (秘密)',
    ];
}

function hpx_settings_def(): array
{
    $ai = ['claude-opus-5-5' => 'Opus 5.5 (いちばん正確・高い)', 'claude-sonnet-5-5' => 'Sonnet 5.5 (中くらい)', 'claude-haiku-5-5' => 'Haiku 5.5 (いちばん安い)'];
    return [
        // 表示
        'HPX_ORG_NAME' => ['g' => 'look', 'l' => 'このサーバーを運営する組織名・団体名', 't' => 'str', 'd' => '', 'len' => 12, 'zen' => 8, 'han' => 12, 'hp' => '入れると、上のバーのロゴの横に「× 組織名」と出ます (誰が導入したサーバーかわかるように)。空なら出しません。全角なら8文字、半角の英数字なら12文字まで (混ぜてもよく、全角2文字が半角3文字ぶんです)。'],
        // メール
        'HPX_MAIL_FROM' => ['g' => 'mail', 'l' => '送り元のメールアドレス', 't' => 'email', 'd' => '', 'hp' => '下の「Gmail のアドレス」と同じにします。'],
        'HPX_SMTP_USER' => ['g' => 'mail', 'l' => 'Gmail のアドレス (SMTP のユーザー)', 't' => 'str', 'd' => '', 'hp' => 'アプリパスワードは config.php の $HPX_SMTP_PASS に書きます。'],
        'HPX_SMTP_HOST' => ['g' => 'mail', 'l' => 'SMTP サーバー', 't' => 'str', 'd' => 'smtp.gmail.com', 'hp' => 'Gmail なら smtp.gmail.com のまま。'],
        'HPX_SMTP_PORT' => ['g' => 'mail', 'l' => 'SMTP のポート番号', 't' => 'int', 'd' => 587, 'min' => 1, 'max' => 65535, 'hp' => 'Gmail なら 587。'],
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
        'HPX_OTA_URL' => ['g' => 'users', 'l' => 'HPX-Core のファーム自動更新の URL', 't' => 'url', 'd' => '', 'hp' => 'manifest.txt の URL。空なら、このフォルダの firmware/manifest.txt があればその URL。'],
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
        'HPX_SMTP_PASS' => ['g' => 'secret', 'l' => 'Gmail のアプリパスワード', 't' => 'secret', 'd' => ''],
        'HPX_CRON_TOKEN' => ['g' => 'secret', 'l' => 'hpx_cron.php を URL から呼ぶときのトークン', 't' => 'secret', 'd' => ''],
        'HPX_REPORT_ADMIN_PW' => ['g' => 'secret', 'l' => '「作った・使ったレポート」の管理ページのパスワード', 't' => 'secret', 'd' => ''],
        'HPX_LINE_TOKEN' => ['g' => 'secret', 'l' => 'LINE のチャネルアクセストークン', 't' => 'secret', 'd' => ''],
        'HPX_LINE_SECRET' => ['g' => 'secret', 'l' => 'LINE のチャネルシークレット', 't' => 'secret', 'd' => ''],
        'HPX_AI_KEY' => ['g' => 'secret', 'l' => 'Claude の API キー (AIメモ・記録)', 't' => 'secret', 'd' => ''],
        'HPX_CAM_AI_KEY' => ['g' => 'secret', 'l' => 'Claude の API キー (カメラ。空なら AIメモのキー)', 't' => 'secret', 'd' => ''],
        'HPX_HELP_KEY' => ['g' => 'secret', 'l' => 'Claude の API キー (AI ヘルプ。空なら AIメモのキー)', 't' => 'secret', 'd' => ''],
        'HPX_HELP_GITHUB_TOKEN' => ['g' => 'secret', 'l' => 'GitHub の読み取り用トークン (非公開リポジトリのときだけ)', 't' => 'secret', 'd' => ''],
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
