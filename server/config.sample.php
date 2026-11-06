<?php
// HPX サーバー設定。
// 機器(HPX-Core)のトークンは、アカウントごとに設定ページ(account.php)で発行する。
// $HPX_TOKEN は前の版のトークン。初期設定(login.php)で、最初のアカウント(サイト管理者)の機器用トークンとして引き継ぐ。
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
$HPX_ALERT_MISSING_S = 3600;   // HPX-A の記録がこの秒数来なければ知らせる
$HPX_SENSOR_MISSING_S = 86400; // HPX-W・HPX-TH・温湿度計の記録がこの秒数来なければ知らせる (24時間)
$HPX_HUB_MISSING_S = 3600;     // ハブ(HPX-Core)からこの秒数届かなければ知らせる(hpx_cron.php)
// hpx_cron.php を URL から呼ぶときのトークン(ライトプランなど CRON が無いとき)。機器用の $HPX_TOKEN とは別の、英数字20文字以上にする
$HPX_CRON_TOKEN = '';
// 前の版の閲覧ページの共通パスワード(8文字以上)。今はアカウントでログインするので、初期設定(login.php)の本人確認にだけ使う
$HPX_VIEW_PASSWORD = '';
// 招待・再設定のメールに書くURLの元(例 'https://example.sakura.ne.jp/hpx')。空ならページを開いたときのURLから決める
$HPX_SITE_URL = '';
// 1つのアカウントにつなげる HPX-Core の台数の上限(0 なら無制限。サイト管理者は無制限)
$HPX_MAX_HUBS = 5;
$HPX_ALERT_BATT_V = 2.6;       // HPX-A の電池電圧(V)がこれ未満なら知らせる
$HPX_W_BATT_V = 2.8;           // HPX-W の電池電圧(V)がこれ未満なら知らせる (センサページの「そろそろ交換」と同じ)
$HPX_TH_BATT_V = 2.7;          // HPX-TH の電池電圧(V)がこれ未満なら知らせる (センサページの「交換」と同じ)
$HPX_TODO_MAIL_HOUR = 7;       // タスクのお知らせメールを送る時刻(この時を過ぎた最初の hpx_cron.php の実行で、1日1回)

// デモ用アカウント: このメールアドレスのアカウントはパスワードを公開して誰でも試せる (空ならデモなし)。
//   設定・チームの変更、写真の追加、メールの送信はできない。管理ページで保存した初期状態に、毎日 $HPX_DEMO_RESET_HOUR 時ごろ戻す
$HPX_DEMO_EMAIL = '';
$HPX_DEMO_READONLY = false;    // true にすると見るだけ (書き込みもできない)
$HPX_DEMO_RESET_HOUR = 3;
$HPX_DEMO_DUMMY = true;         // デモのセンサの記録を見本の値 (温度34℃前後・湿度の日変動・重さが毎日増える) で作る。false なら届いた記録を使う

date_default_timezone_set('Asia/Tokyo');
