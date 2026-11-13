<?php
// HPX サーバー設定。
// 機器(HPX-Core)のトークンは、アカウントごとに設定ページ(account.php)で発行する。
// $HPX_TOKEN は前の版のトークン。初期設定(login.php)で、最初のアカウント(サーバー管理者)の機器用トークンとして引き継ぐ。
$HPX_TOKEN = 'CHANGE-ME-to-a-long-random-string';

// データの保存先。このファイルと同じフォルダの data/ (例 www/hpx/data/)。
// data/ には必ず .htaccess (ブラウザから開けないようにする設定)を置くこと。
// 公開フォルダ(www)の外に置きたいときは __DIR__ . '/../../hpx_data' にする。
$HPX_DATA_DIR = __DIR__ . '/data';

// 電柵アラートのメール通知(hpx_alert.php)
// 送り先は、アカウントごとに設定ページで決める(決めていなければログインのメールアドレス)。
// $HPX_MAIL_TO は前の版の送り先。初期設定で、最初のアカウントの送り先として引き継ぐ。
// 携帯のメールに届かないときは、迷惑メール設定で送り元を許可する。
$HPX_MAIL_TO = 'you@example.com';
// 送り元。下の $HPX_SMTP_USER と同じ Gmail のアドレスにする。
$HPX_MAIL_FROM = 'you@gmail.com';
// サイトのお問い合わせフォーム (hiveplusx.com/contact.php) の送り先。空なら support@hiveplusx.com に送る。
$HPX_CONTACT_TO = '';
// サイトの「作った・使ったレポート」(hiveplusx.com/report.php) が届いたときの知らせ先。空なら $HPX_CONTACT_TO (それも空なら support@hiveplusx.com)。
$HPX_REPORT_TO = '';
// 「作った・使ったレポート」の管理ページ (hiveplusx.com/report_admin.php、サイトの一番下の歯車) に入るパスワード。8文字以上。空なら入れない。
//   HPX-note のアカウントとは別。変えると、ログインしていた人もログインし直しになる。
$HPX_REPORT_ADMIN_PW = '';
// メールは Gmail のアカウントにログインして送る(SMTP認証)。
//   Googleアカウントで2段階認証をオンにし、https://myaccount.google.com/apppasswords で作った16文字のアプリパスワードを入れる。
//   $HPX_MAIL_FROM も同じ Gmail のアドレスにする。
$HPX_SMTP_HOST = 'smtp.gmail.com';
$HPX_SMTP_PORT = 587;
$HPX_SMTP_SECURE = 'tls';
$HPX_SMTP_USER = '';
$HPX_SMTP_PASS = '';
$HPX_ALERT_MISSING_S = 3600;   // HPX-F の記録がこの秒数来なければ知らせる
$HPX_SENSOR_MISSING_S = 86400; // HPX-W・HPX-TH・温湿度計の記録がこの秒数来なければ知らせる (24時間)
$HPX_HUB_MISSING_S = 3600;     // ハブ(HPX-Core)からこの秒数届かなければ知らせる(hpx_cron.php)
// hpx_cron.php を URL から呼ぶときのトークン(ライトプランなど CRON が無いとき)。機器用の $HPX_TOKEN とは別の、英数字20文字以上にする
$HPX_CRON_TOKEN = '';
// 前の版の閲覧ページの共通パスワード(8文字以上)。今はアカウントでログインするので、初期設定(login.php)の本人確認にだけ使う
$HPX_VIEW_PASSWORD = '';
// 招待・再設定のメールに書くURLの元(例 'https://example.sakura.ne.jp/hpx')。空ならページを開いたときのURLから決める
$HPX_SITE_URL = '';
// 1つのアカウントにつなげる HPX-Core の台数の上限(0 なら無制限。サーバー管理者は無制限)
$HPX_MAX_HUBS = 5;
// HPX-Core のファームウェア自動更新サーバーのURL (manifest.txt の https:// のURL)。
//   マイアカウントの「HPX-Core の接続」に表示し、利用者が HPX-Core の管理画面「動作設定」に入れる。
//   空なら、このフォルダの firmware/manifest.txt があるときに、そのURL (例 https://hiveplusx.com/hpx/firmware/manifest.txt) を表示する
$HPX_OTA_URL = '';
// アカウント登録のときに同意してもらう「データの利用」の文 (空なら決まった文。サーバー管理ページの「全アカウントのデータのダウンロード」の前提)。
//   登録方法 (招待 / 申請→承認 / 自由登録) はサーバー管理ページで切り替える。
$HPX_CONSENT_TEXT = '';
// 利用規約のページの URL (https://… 空なら同意の文にリンクを付けない)
$HPX_TERMS_URL = '';
// HPX-CAM (蜂場カメラ) の写真 (cam_upload.php で受けて、camera.php で見る)
$HPX_MAX_CAMS = 5;             // 1つのアカウントにつなげるカメラの台数の上限 (0 なら無制限。サーバー管理者は無制限)
$HPX_CAM_KEEP_DAYS = 365;      // 写真を残す日数。これより古い日の写真は自動で消す (0 なら消さない)。
                               //   目安: 800×600 で10分ごと・昼14時間なら 1日 約5MB、1年で 約2GB (カメラ1台)
$HPX_ALERT_BATT_V = 2.6;       // HPX-F の電池電圧(V)がこれ未満なら知らせる
$HPX_W_BATT_V = 2.8;           // HPX-W の電池電圧(V)がこれ未満なら知らせる (センサページの「そろそろ交換」と同じ)
$HPX_TH_BATT_V = 2.7;          // HPX-TH の電池電圧(V)がこれ未満なら知らせる (センサページの「交換」と同じ)
// HPX-Core 有線LAN＋電圧チェック版 (ファーム v1.22〜) の 12V バッテリー
$HPX_BATT12_WARN_V = 12.0;     // バッテリー電圧(V)がこれ未満になったら知らせる (鉛バッテリーで残り半分くらい)
$HPX_BATT12_OK_V = 12.6;       // 知らせたあと、これ以上に戻ったら「戻りました」を知らせる (昼の充電で戻る)
$HPX_TODO_MAIL_HOUR = 7;       // タスクのお知らせメールを送る時刻(この時を過ぎた最初の hpx_cron.php の実行で、1日1回)
// LINE公式アカウント (Messaging API) でもお知らせを送る (空ならメールだけ)。
//   LINE Developers のチャネルの「チャネルアクセストークン (長期)」と「チャネルシークレット」。Webhook URL は このフォルダの line_webhook.php
//   無料プランで送れるのは月200通まで (友だち1人に1通 = 1通)。送れなかったときは、LINE だけにしている人にもメールで送る
$HPX_LINE_TOKEN = '';
$HPX_LINE_SECRET = '';
$HPX_LINE_ADD_URL = '';         // 友だち追加の URL (例 'https://line.me/R/ti/p/@222xdxtk')。マイアカウントの連携の説明に出す
// LINE で送ったメモ・作業記録を AI (Claude) で読み取って HPX-note に記録する (空なら使わない。LINE の設定も要る)。
//   Claude Console (https://console.anthropic.com/) で作った API キー。利用料は送った回数に応じてかかる
$HPX_AI_KEY = '';
$HPX_AI_MODEL = 'claude-opus-5-5';   // 読み取りに使うモデル。'claude-haiku-5-5' にすると利用料はずっと安い (読み取りの正確さは少し下がるかもしれない)
$HPX_AI_TERMS = [];            // 養蜂の用語集に足す言葉 (正しい言葉 => 音声入力で化けやすい書き方)。例 ['待ち箱' => ['町箱'], 'キンリョウヘン' => ['金陵辺']]。よく使う用語は hpx_line_note.php の LN_TERMS に入っている
$HPX_AI_DAY_MAX = 50;          // 1人が1日に LINE で記録できる回数 (使いすぎ・料金の上がりすぎを防ぐ)

// デモ用アカウント: このメールアドレスのアカウントはパスワードを公開して誰でも試せる (空ならデモなし)。
//   設定・チームの変更、写真の追加、メールの送信はできない。管理ページで保存した初期状態に、毎日 $HPX_DEMO_RESET_HOUR 時ごろ戻す
$HPX_DEMO_EMAIL = '';
$HPX_DEMO_READONLY = false;    // true にすると見るだけ (書き込みもできない)
$HPX_DEMO_RESET_HOUR = 3;
$HPX_DEMO_DUMMY = true;         // デモのセンサの記録を見本の値 (温度34℃前後・湿度の日変動・重さが毎日増える) で作る。false なら届いた記録を使う

date_default_timezone_set('Asia/Tokyo');
