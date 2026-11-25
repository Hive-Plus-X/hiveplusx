// ====================================================================
// HPX_CAM_v1_09.ino   Hive Plus X  HPX-CAM (巣箱・巣門を撮るカメラ)
//
// ボード: Seeed Studio XIAO ESP32S3 Sense (カメラ OV3660 / OV2640 / OV5640、microSD 枠つき)
//   Arduino IDE のボードマネージャーで「esp32 by Espressif Systems」(3.x) を入れ、
//   ボードは「XIAO_ESP32S3」、ツール → PSRAM は「OPI PSRAM」を選ぶ (選ばないとカメラが動かない)。
//   追加のライブラリは要らない (カメラ・microSD・Wi-Fi・HTTPS はボードの中に入っている)。
//
// 配線 (hardware/wiring/HPX-CAM_KP1228D_pcb.png):
//   単三リチウム電池×3 (約4.5〜5.4V) → ショットキーダイオード 1N5819 → XIAO の 5V
//   電池＋ ─[100kΩ]─ D0 ─[100kΩ]─ GND、D0–GND に 0.1µF (電池の電圧の 1/2 を D0 で読む)
//   5V–GND に 100µF (Wi-Fi 送信の瞬間の電圧の下がりよけ)
//
// 動き方:
//   1. 初めて電源を入れると、HPX-Core と同じ Wi-Fi「HPX-Setup-xxxx」(パスワード 12345678) を出す。
//      スマートフォンでつないで http://192.168.4.1/ を開き、「ネットワーク設定」(SSID・Wi-Fiパスワード・管理画面のパスワード)
//      を保存するとすぐ試しにつなぐ → 管理画面のURL (http://IPアドレス/ と http://<機器ID>.local/) を控えて OK → 再起動。
//      そのあとは HPX-Core と同じく、ルーターにつないだスマートフォンから管理画面 (ユーザー名 admin) を開き、
//      「動作設定」で送信先のURL・トークンなどを入れる → 保存すると写真を1枚送って確かめる。
//      電源を入れなおすと約10分、ルーター経由で管理画面を開ける (HPX-Core の電池のときと同じ)。だれも触らないまま
//      約10分たつと撮影を始める。ルーターにつながらないときは設定用 Wi-Fi「HPX-Setup-xxxx」を出す。
//   2. 撮影のたびに目を覚まし、写真を撮って microSD にためる → Wi-Fi でサーバー (cam_upload.php) へ送る → 眠る。
//      送れなかった写真は microSD に残し、次に起きたときに送り直す (新しい写真から順に)。
//      送れた写真は microSD の日付のフォルダへ移して残す (いっぱいに近くなったら古い日から消す)。
//   3. 撮影の間隔 (5・10・15・30・60分) と撮影する時間帯 (例 5時〜19時) は、HPX-note のカメラのページで決め、
//      サーバーの返事でカメラに届く (次の撮影から使う)。時間帯の外は、次の時間帯の始めまで眠る。
//      時計もサーバーの返事 (と NTP) で合わせる。時刻は日本時間。
//
// サーバーへの送り方 (server/cam_upload.php に合わせた):
//   POST <サーバー>/cam_upload.php?id=<カメラのID 12けた16進>&t=<撮影時刻 epoch秒>&bv=<電池V>&rssi=<dBm>&tc=<℃>&fw=<ビルド番号>&name=<名前>
//   Authorization: Bearer <機器用トークン> (HPX-Core と同じ。HPX-note のマイアカウントに出る)
//   Content-Type: image/jpeg、本文は JPEG そのもの
//   返事: {"ok":true,"saved":1,"iv":10,"h0":5,"h1":19,"now":...}
//
// v1.09 (2026-11-25 TARO: HPX-CAM もファームウェアの確認先は自動で決まるので、URL を入れる必要はないのでは)
//   * HPX-Core v1.41 と同じく、動作設定の「ファームウェア自動更新サーバーのURL」の入力欄をなくし、
//     「ファームウェアを自動更新する」のチェックにした
//   * 確認する場所は、送信先のURLから自動で決める (写真の送り先 cam_upload.php と同じフォルダの firmware/manifest_cam.txt):
//       送信先 https://hiveplusx.com/hpx/receive.php → https://hiveplusx.com/hpx/firmware/manifest_cam.txt
//     送信先が https:// のときだけ
//   * 前の版からの引き継ぎ: 前の版で自動更新のURLを入れていた機器は「自動更新する」がオン、空欄だった機器はオフ。
//     まだ何も設定していない機器はオン。前に入れたURL (murl) は使わなくなり、次に動作設定を保存したときに消す
//   * ほかは v1.08 と同じ
//
// v1.08 (2026-11-24 TARO: HPX-Core v1.38 と同じように、自動更新の不具合を直す)
//   * 管理画面のメイン画面と「ファームウェア更新」の画面に「今すぐ更新を確認」ボタンを足した (HPX-Core v1.38 と同じ)。
//     押すと、その場でマニフェスト (manifest_cam.txt) を読み、新しい版があれば「更新します」と出してから
//     ダウンロード・書き込み・再起動する (1〜2分)。同じか古ければ「最新です」と、サーバーの番号とこの機器の番号を出す。
//     読めなかったときは理由を出す。1日1回の間隔には関係なく、すぐ確認する (電池が少ないとき・設定用 Wi-Fi のときはしない)
//   * メイン画面に「自動更新の確認」(最後に確認した時刻と結果、確認できない理由、次の自動確認までの時間) を出す。
//     管理画面は電源を入れなおさないと開けず、そのとき眠りの間の記録 (RTC メモリ) は消えるので、結果は NVS に残す
//   * 時刻の合わせ方を強くした: これまでは時刻合わせ (NTP) ができないと撮影も自動更新の確認もせずに眠っていた
//     (HPX-Core v1.37 までと同じく、時刻が合っていないと自動更新の確認を黙って飛ばす)。
//     NTP がだめなときは、送信先のサーバーの返事の時刻 (HTTP の Date) で合わせる
//   * 撮影で起きている時間の上限 (150秒) の近くでは自動更新の確認をしないで次に回す。
//     これまでは写真を送るのに時間がかかると、確認の途中で見張りに眠らされ、その日の確認が済んだことになっていた
//   * HPX-Core と違い、眠るたびに時刻を合わせ直すので「時刻合わせに1回失敗すると二度とやり直さない」不具合は無かった
//   * ほかは v1.07 と同じ
//
// v1.07 (2026-11-23 TARO: 写真が左右反転のまま)
//   * 動作設定に「左右を逆にする」を足した (v1.03 から左右はいつも反転なしにしていたが、カメラによっては左右が逆に写る)
//   * HPX-note のカメラのページでも写真の向きを選べるようにした。サーバーの返事の "fl" (0〜3、1=上下 2=左右 の足し算) で届き、
//     届いたときは管理画面の設定より優先する (写真の大きさ "sz" と同じ考え方)。"fl" が無ければ管理画面の設定を使う
//
// v1.06 (2026-11-22 TARO: HPX-note のカメラのページにファームウェアの版を出したい)
//   * 写真と一緒にファームのビルド番号を "fw" (例 106) としてサーバーへ送る。HPX-note のカメラのページで「v1.06」と出る
//
// v1.05 (2026-11-22 TARO: ケースが熱くなりすぎたら警告したい。正確でなくてよい)
//   * 起きた直後 (Wi-Fi・カメラを動かす前、自分の熱が少ないうち) に ESP32-S3 の中の温度センサでチップの温度を測り、
//     写真と一緒に "tc" (℃) としてサーバーへ送る。サーバーはカメラのページで決めた温度 (初め 60℃) 以上になったら
//     「機器の警報」でお知らせする。正確な気温ではなく、ケースの中の目安
//   * 管理画面にもチップの温度を出す
//
// v1.04 (2026-11-22 TARO: 写真の大きさ (解像度) も HPX-note から変えたい)
//   * サーバーの返事の "sz" (0=SVGA 1=XGA 2=UXGA 3=QXGA) で写真の大きさを受け取るようにした。
//     HPX-note のカメラのページで選んだときはそちらを優先し、選んでいないとき ("sz" が無いとき) は
//     これまでどおり管理画面「動作設定」の写真の大きさを使う (TARO 決定)。次の撮影から変わる
//
// v1.03 (2026-11-22 TARO: 「上下左右を逆に」だと左右も反転して映像がおかしい)
//   * 動作設定の「上下・左右を逆にする」を「上下を逆にする」に変え、上下だけを反転するようにした (左右は反転しない)
//   * 設定の保存場所 (flip) は同じなので、v1.02 でチェックしていた人はそのまま「上下を逆にする」になる
//
// v1.02 (2026-11-22 TARO: HPX-CAM にも自動更新が欲しい)
//   * HPX-Core と同じファームウェアの自動更新 (HTTPS OTA) を足した。管理画面「動作設定」の
//     「ファームウェア自動更新サーバーのURL」にマニフェスト (manifest_cam.txt など、2行のテキスト) の URL を入れる。
//       1行目: ビルド番号 (整数、この版は 102)   2行目: .bin の URL (https://)
//     撮影で起きたとき、1日1回 (HPX-Core の電池のときと同じ) マニフェストを見て、ビルド番号が今より大きければ
//     .bin を受け取って書き込み、再起動する。確かめられなかったときは1時間後にもう一度見る。
//     電池が 4.0V より低いときは更新しない (書き込みの途中で止まらないように)
//   * HPX-Core と同じく、新しいファームが動かなかったときは前のファームへ戻す (ロールバック)。
//     新しいファームで初めて起きたとき、Wi-Fi につながれば「動いた」とし、つながらなければ前のファームへ戻す
//   * HPX-Core と同じく、管理画面の「ファームウェア更新」で .bin を選んで手で書き込める
//
// v1.01 (2026-11-22 TARO: Wi-Fi の設定の手順を、できるだけ全部 HPX-Core とそろえる)
//   * 設定用 Wi-Fi の名前を HPX-Core と同じ「HPX-Setup-xxxx」にした (v1.00 は HPX-CAM-xxxx)
//   * HPX-Core と同じ2段の形にした。1段目「ネットワーク設定」(接続方法・SSID・Wi-Fiパスワード・管理画面のパスワード・動作モード。
//     接続方法は Wi-Fi、動作モードはディープスリープに決まっているので表示だけ) を保存すると、すぐ試しにルーターへつなぎ、
//     つながったら管理画面の開き方を出す →「控えました」にチェックして OK。2段目は管理画面の「動作設定」で送信先のURL・トークンなど
//   * 管理画面のパスワード (ユーザー名 admin、BASIC 認証) を足した。設定のあとは管理画面のどのページもパスワードが要る
//   * 管理画面を開ける時間を HPX-Core の電池のときと同じ約10分に (設定前は 30分で眠る、v1.00 は 3分・10分)
//   * HPX-Core と同じく、つないだあとはルーター経由で管理画面を開く (http://IPアドレス/ と http://<機器ID>.local/)。
//     電源を入れなおしてから約10分開ける。ルーターにつながらないときは設定用 Wi-Fi を出す
//   * 電源を入れなおし、3秒以内に BOOT ボタンを5秒長押しすると、設定が消えて初めの状態に戻る
//   * HPX-Core と同じく「機器ID」を持つ (初めは hpx-cam-<MAC の最後の6けた>)。管理画面の「機器ID変更」で変えると、
//     管理画面の名前の URL が http://<新しい機器ID>.local/ になる
//
// v1.00 (2026-11-22 TARO 依頼: HPX-CAM のファームウェアを作る)
//   * 初めての版。実機ではまだ確かめていない (クラウドでコンパイルだけ確認)
//   * LTE-M の蜂場で HPX-Core に中継してもらう送り方 (&core=) はまだ無い (HPX-Core 側の中継もまだ無いため)
// ====================================================================

#include <Arduino.h>
#include <WiFi.h>
#include <WebServer.h>
#include <DNSServer.h>
#include <ESPmDNS.h>
#include <Preferences.h>
#include <HTTPClient.h>
#include <HTTPUpdate.h>
#include <Update.h>
#include "esp_ota_ops.h"
#include <WiFiClientSecure.h>
#include <FS.h>
#include <SD.h>
#include <SPI.h>
#include <time.h>
#include <sys/time.h>
#include "esp_camera.h"
#include "esp_sleep.h"
#include "esp_wifi.h"
#include "esp_mac.h"
#include "esp_timer.h"

static const char* FW_VERSION = "HPX-CAM.1.09";
#define FW_BUILD 109   // 自動更新の新旧の見分けに使う。版を変えたら必ず FW_VERSION と両方を変える

// ---- 設定モードの Wi-Fi ----
static const char* AP_PASSWORD = "12345678";          // HPX-Core と同じ初期パスワード
static const uint32_t SETUP_IDLE_MS_CONFIGURED = 10UL * 60 * 1000;  // 設定済み: 約10分だれも触らなければ撮影を始める (HPX-Core の電池のときと同じ)
static const uint32_t SETUP_IDLE_MS_NEW = 30UL * 60 * 1000;         // 設定前: 30分で眠る (HPX-Core と同じ。電池を守る)
static const uint32_t SETUP_NEW_SLEEP_S = 6UL * 3600;               // 設定前に眠ったら 6時間後にもう一度設定モード

// ---- ピン (XIAO ESP32S3 Sense) ----
static const int PIN_BOOT = 0;      // BOOT ボタン (初めの状態に戻すとき)
static const int PIN_VBAT = 1;      // D0 (GPIO1) 電池の電圧の 1/2
static const int PIN_SD_CS = 21;    // Sense 基板の microSD の CS (基板の黄色い LED と同じピン)
// カメラ (Seeed の公式の割り当て)
#define CAM_PIN_PWDN -1
#define CAM_PIN_RESET -1
#define CAM_PIN_XCLK 10
#define CAM_PIN_SIOD 40
#define CAM_PIN_SIOC 39
#define CAM_PIN_D7 48
#define CAM_PIN_D6 11
#define CAM_PIN_D5 12
#define CAM_PIN_D4 14
#define CAM_PIN_D3 16
#define CAM_PIN_D2 18
#define CAM_PIN_D1 17
#define CAM_PIN_D0 15
#define CAM_PIN_VSYNC 38
#define CAM_PIN_HREF 47
#define CAM_PIN_PCLK 13

// ---- 電池 ----
static const float VBAT_DIV = 2.0f;          // 100kΩ + 100kΩ
static const float VBAT_LOW = 3.6f;          // これより低いと撮らずに眠る (XIAO の 5V 入口の下限に近いため。未実測)
static const uint32_t LOW_BAT_SLEEP_S = 3600;

// ---- 撮影・送信 ----
static const int IVS[] = {5, 10, 15, 30, 60};          // サーバーの CAM_IVS と同じ
static const int IV_DEFAULT = 10;
static const int H0_DEFAULT = 5;
static const int H1_DEFAULT = 19;
static const uint32_t WIFI_TIMEOUT_MS = 15000;
static const uint32_t UPLOAD_BUDGET_MS = 60000;        // 1回に送る時間の上限 (残りは次に)
static const int UPLOAD_MAX_FILES = 12;                // 1回に送る枚数の上限
static const uint32_t AWAKE_MAX_MS = 150000;           // 撮影モードで起きている時間の上限 (止まったときの電池よけ)
static const char* Q_DIR = "/hpxcam/q";                // 送る前の写真
static const char* A_DIR = "/hpxcam";                  // 送った写真 /hpxcam/YYYYMMDD/HHMMSS.jpg
static const float SD_KEEP_RATIO = 0.90f;              // microSD がこれより埋まったら古い日から消す
static const time_t TIME_VALID = 1767225600;           // 2026-01-01 より前の時計は「まだ合っていない」

// ---- 設定 (NVS に保存) ----
Preferences prefs;
String cfgSsid, cfgPass, cfgUrl, cfgToken, cfgName, cfgAdmin, cfgDevId, cfgOta;   // cfgOta = ファームウェア自動更新のマニフェストの URL (v1.09: 送信先から自動で決める。自動更新しないときは空)   // cfgDevId = 機器ID (管理画面の http://<機器ID>.local/ に使う)   // cfgAdmin = 管理画面のパスワード (ユーザー名 admin)
bool cfgAutoOta = true;   // v1.09: ファームウェアを自動更新する (NVS "ota")
uint8_t cfgSize = 0;      // 0=SVGA 800×600, 1=XGA 1024×768, 2=UXGA 1600×1200, 3=QXGA 2048×1536 (管理画面の設定)
int8_t srvSize = -1;      // HPX-note で選んだ写真の大きさ (サーバーの返事の sz)。-1 = 選んでいない → cfgSize を使う
uint8_t shotSize() { return srvSize >= 0 && srvSize <= 3 ? (uint8_t)srvSize : cfgSize; }
bool cfgFlip = false;     // 上下を逆に
bool cfgMirror = false;   // 左右を逆に (v1.07 から)
int8_t srvFlip = -1;      // HPX-note で選んだ写真の向き (サーバーの返事の fl、1=上下 2=左右 の足し算)。-1 = 選んでいない → cfgFlip・cfgMirror を使う
bool flipV() { return srvFlip >= 0 ? (srvFlip & 1) != 0 : cfgFlip; }
bool mirrorH() { return srvFlip >= 0 ? (srvFlip & 2) != 0 : cfgMirror; }
uint8_t cfgSend = 0;      // 0=撮るたびに送る, 1=1時間に1回まとめて送る
int curIv = IV_DEFAULT, curH0 = H0_DEFAULT, curH1 = H1_DEFAULT;   // サーバーから届いた値 (NVS にも保存)

// ---- 眠っている間も消えない値 (RTC メモリ) ----
RTC_DATA_ATTR uint32_t rtcBoot = 0;
RTC_DATA_ATTR time_t rtcLastUpload = 0;
RTC_DATA_ATTR char rtcSdCheckDay[9] = "";
RTC_DATA_ATTR int8_t rtcLastRssi = 0;
RTC_DATA_ATTR int16_t rtcLastHttp = 0;
RTC_DATA_ATTR int64_t rtcLastOta = 0;   // 前に自動更新を確かめた時刻 (眠っても消えない)
String otaCheckText;      // v1.08: 最後の自動更新の確認の結果 (NVS「otares」にも残す。管理画面に出す)
bool otaNowPending = false;   // v1.08: 「今すぐ更新を確認」で新しい版があったとき、画面を返してから更新する
int otaNowBuild = 0;
String otaNowUrl;

String camId;             // 12けた16進 (Wi-Fi の MAC アドレス)
bool sdOk = false;
bool camOk = false;
uint32_t bootMs = 0;
float bootTempC = NAN;   // 起きた直後のチップの温度 (℃、目安)
void startGuard();

// ====================================================================
// 小さな道具
// ====================================================================

String htmlEscape(const String& s)
{
  String o;
  o.reserve(s.length() + 8);
  for (size_t i = 0; i < s.length(); i++)
  {
    char c = s[i];
    if (c == '&') o += "&amp;";
    else if (c == '<') o += "&lt;";
    else if (c == '>') o += "&gt;";
    else if (c == '\'') o += "&#39;";
    else if (c == '"') o += "&quot;";
    else o += c;
  }
  return o;
}

String urlEncode(const String& s)
{
  String o;
  const char* hex = "0123456789ABCDEF";
  for (size_t i = 0; i < s.length(); i++)
  {
    uint8_t c = (uint8_t)s[i];
    if (isalnum(c) || c == '-' || c == '_' || c == '.' || c == '~') o += (char)c;
    else { o += '%'; o += hex[c >> 4]; o += hex[c & 15]; }
  }
  return o;
}

String makeCamId()
{
  uint8_t m[6];
  esp_read_mac(m, ESP_MAC_WIFI_STA);
  char b[13];
  snprintf(b, sizeof(b), "%02X%02X%02X%02X%02X%02X", m[0], m[1], m[2], m[3], m[4], m[5]);
  return String(b);
}

bool timeValid() { return time(nullptr) > TIME_VALID; }

void setClock(time_t t)
{
  if (t < TIME_VALID) return;
  struct timeval tv = {t, 0};
  settimeofday(&tv, nullptr);
}

String fmtTime(time_t t, const char* f)
{
  struct tm tmv;
  localtime_r(&t, &tmv);
  char b[32];
  strftime(b, sizeof(b), f, &tmv);
  return String(b);
}

bool ivOk(int v)
{
  for (int x : IVS) if (x == v) return true;
  return false;
}

// 返事の JSON から "key":数字 を読む (小さな返事なので、ライブラリを使わずに探す)
bool jsonInt(const String& j, const char* key, long& out)
{
  String k = String("\"") + key + "\":";
  int p = j.indexOf(k);
  if (p < 0) return false;
  p += k.length();
  while (p < (int)j.length() && j[p] == ' ') p++;
  int e = p;
  if (e < (int)j.length() && j[e] == '-') e++;
  while (e < (int)j.length() && isdigit((unsigned char)j[e])) e++;
  if (e == p) return false;
  out = j.substring(p, e).toInt();
  return true;
}

// 送り先: 入力が receive.php などのページなら同じフォルダの cam_upload.php、フォルダなら末尾に cam_upload.php
String uploadUrl()
{
  String u = cfgUrl;
  u.trim();
  int q = u.indexOf('?');
  if (q >= 0) u = u.substring(0, q);
  if (u.endsWith("cam_upload.php")) return u;
  if (u.endsWith(".php")) return u.substring(0, u.lastIndexOf('/') + 1) + "cam_upload.php";
  if (!u.endsWith("/")) u += "/";
  return u + "cam_upload.php";
}

bool configured() { return cfgSsid.length() > 0 && cfgUrl.length() > 0 && cfgToken.length() > 0; }

// v1.09: 送信先のURLから、自動更新のマニフェストの場所を決める (写真の送り先と同じフォルダの firmware/manifest_cam.txt)
//   https://hiveplusx.com/hpx/receive.php → https://hiveplusx.com/hpx/firmware/manifest_cam.txt。https:// でなければ空
String otaUrlFromServer()
{
  if (cfgUrl.length() == 0) return "";
  String u = uploadUrl();
  if (!u.startsWith("https://")) return "";
  return u.substring(0, u.lastIndexOf('/') + 1) + "firmware/manifest_cam.txt";
}

// 確認先を決め直す (設定を読んだとき・保存したとき)
void updateOtaUrl() { cfgOta = cfgAutoOta ? otaUrlFromServer() : String(""); }

// 自動更新しない理由 (画面に出す)。自動更新するときは空
String otaOffReason()
{
  if (!cfgAutoOta) return "しない (動作設定の「ファームウェアを自動更新する」がオフ)";
  if (cfgUrl.length() == 0) return "しない (送信先のURLが空欄)";
  if (cfgOta.length() == 0) return "しない (送信先のURLが https:// でない)";
  return "";
}

// ====================================================================
// 設定 (NVS)
// ====================================================================

void loadConfig()
{
  prefs.begin("hpxcam", true);
  cfgSsid = prefs.getString("ssid", "");
  cfgPass = prefs.getString("pass", "");
  cfgUrl = prefs.getString("url", "");
  cfgToken = prefs.getString("tok", "");
  cfgName = prefs.getString("name", "");
  cfgAdmin = prefs.getString("adminpw", "");
  cfgDevId = prefs.getString("deviceID", "");
  // v1.09: 自動更新する/しない。前の版からは「URL を入れていたらオン」で引き継ぐ。何も設定していない機器はオン
  if (prefs.isKey("ota")) cfgAutoOta = prefs.getBool("ota", true);
  else cfgAutoOta = prefs.getString("murl", "").length() > 0 || cfgUrl.length() == 0;
  cfgSize = prefs.getUChar("size", 0);
  srvSize = prefs.getChar("srvsz", -1);
  cfgFlip = prefs.getBool("flip", false);
  cfgMirror = prefs.getBool("mir", false);
  srvFlip = prefs.getChar("srvfl", -1);
  cfgSend = prefs.getUChar("send", 0);
  curIv = prefs.getInt("iv", IV_DEFAULT);
  curH0 = prefs.getInt("h0", H0_DEFAULT);
  curH1 = prefs.getInt("h1", H1_DEFAULT);
  otaCheckText = prefs.getString("otares", "");
  prefs.end();
  updateOtaUrl();
  if (cfgSize > 3) cfgSize = 0;
  if (srvSize < -1 || srvSize > 3) srvSize = -1;
  if (srvFlip < -1 || srvFlip > 3) srvFlip = -1;
  if (cfgSend > 1) cfgSend = 0;
  if (!ivOk(curIv)) curIv = IV_DEFAULT;
  if (curH0 < 0 || curH0 > 23) curH0 = H0_DEFAULT;
  if (curH1 < 1 || curH1 > 24) curH1 = H1_DEFAULT;
}

void saveConfig()
{
  prefs.begin("hpxcam", false);
  prefs.putString("ssid", cfgSsid);
  prefs.putString("pass", cfgPass);
  prefs.putString("url", cfgUrl);
  prefs.putString("tok", cfgToken);
  prefs.putString("name", cfgName);
  prefs.putString("adminpw", cfgAdmin);
  prefs.putString("deviceID", cfgDevId);
  prefs.putBool("ota", cfgAutoOta);
  if (prefs.isKey("murl")) prefs.remove("murl");   // v1.08 までの URL (使わない)
  prefs.putUChar("size", cfgSize);
  prefs.putBool("flip", cfgFlip);
  prefs.putBool("mir", cfgMirror);
  prefs.putUChar("send", cfgSend);
  prefs.end();
}

void saveSchedule(int iv, int h0, int h1)
{
  if (!ivOk(iv)) iv = IV_DEFAULT;
  h0 = constrain(h0, 0, 23);
  h1 = constrain(h1, 1, 24);
  if (iv == curIv && h0 == curH0 && h1 == curH1) return;   // 変わったときだけ書く (NVS を傷めない)
  curIv = iv; curH0 = h0; curH1 = h1;
  prefs.begin("hpxcam", false);
  prefs.putInt("iv", iv);
  prefs.putInt("h0", h0);
  prefs.putInt("h1", h1);
  prefs.end();
}

// ====================================================================
// 電池
// ====================================================================

float readVbat()
{
  analogSetPinAttenuation(PIN_VBAT, ADC_11db);
  uint32_t sum = 0;
  for (int i = 0; i < 16; i++) { sum += analogReadMilliVolts(PIN_VBAT); delay(2); }
  return (sum / 16.0f) / 1000.0f * VBAT_DIV;
}

// ====================================================================
// カメラ
// ====================================================================

framesize_t frameSize(uint8_t s)
{
  switch (s)
  {
    case 1: return FRAMESIZE_XGA;
    case 2: return FRAMESIZE_UXGA;
    case 3: return FRAMESIZE_QXGA;
    default: return FRAMESIZE_SVGA;
  }
}

const char* sizeName(uint8_t s)
{
  switch (s)
  {
    case 1: return "XGA 1024×768";
    case 2: return "UXGA 1600×1200";
    case 3: return "QXGA 2048×1536 (OV3660・OV5640 だけ)";
    default: return "SVGA 800×600";
  }
}

void camApply();

bool camStart()
{
  if (camOk) return true;
  camera_config_t c = {};
  c.pin_pwdn = CAM_PIN_PWDN;
  c.pin_reset = CAM_PIN_RESET;
  c.pin_xclk = CAM_PIN_XCLK;
  c.pin_sccb_sda = CAM_PIN_SIOD;
  c.pin_sccb_scl = CAM_PIN_SIOC;
  c.pin_d7 = CAM_PIN_D7;
  c.pin_d6 = CAM_PIN_D6;
  c.pin_d5 = CAM_PIN_D5;
  c.pin_d4 = CAM_PIN_D4;
  c.pin_d3 = CAM_PIN_D3;
  c.pin_d2 = CAM_PIN_D2;
  c.pin_d1 = CAM_PIN_D1;
  c.pin_d0 = CAM_PIN_D0;
  c.pin_vsync = CAM_PIN_VSYNC;
  c.pin_href = CAM_PIN_HREF;
  c.pin_pclk = CAM_PIN_PCLK;
  c.xclk_freq_hz = 20000000;
  c.ledc_timer = LEDC_TIMER_0;
  c.ledc_channel = LEDC_CHANNEL_0;
  c.pixel_format = PIXFORMAT_JPEG;
  c.frame_size = FRAMESIZE_QXGA;   // 一番大きいもので場所を取り、あとで小さくする
  c.jpeg_quality = 12;             // 小さいほどきれい・大きい (10〜15 くらい)
  c.fb_count = 1;
  c.fb_location = CAMERA_FB_IN_PSRAM;
  c.grab_mode = CAMERA_GRAB_LATEST;
  if (!psramFound())
  {
    Serial.println("PSRAM がありません (ツール → PSRAM を OPI PSRAM に)");
    c.frame_size = FRAMESIZE_SVGA;
    c.fb_location = CAMERA_FB_IN_DRAM;
  }
  esp_err_t e = esp_camera_init(&c);
  if (e != ESP_OK)
  {
    // QXGA を扱えないカメラ (OV2640) のときは UXGA でやり直す
    c.frame_size = FRAMESIZE_UXGA;
    e = esp_camera_init(&c);
  }
  if (e != ESP_OK)
  {
    Serial.printf("カメラを始められません 0x%x\n", e);
    return false;
  }
  camOk = true;
  camApply();
  return true;
}

// 写真の大きさ・向きをカメラに伝える (始めたときと、サーバーから大きさが届いたとき)
void camApply()
{
  if (!camOk) return;
  sensor_t* s = esp_camera_sensor_get();
  if (!s) return;
  framesize_t want = frameSize(shotSize());
  if (s->id.PID == OV2640_PID && want == FRAMESIZE_QXGA) want = FRAMESIZE_UXGA;
  s->set_framesize(s, want);
  s->set_vflip(s, flipV() ? 1 : 0);
  s->set_hmirror(s, mirrorH() ? 1 : 0);   // 左右 (v1.07 から選べる)
  Serial.printf("カメラ PID=0x%04x 大きさ %d\n", s->id.PID, (int)shotSize());
}

void camStop()
{
  if (!camOk) return;
  esp_camera_deinit();
  camOk = false;
}

// 写真を1枚撮る。起きてすぐは明るさの調整が済んでいないので、何枚か捨ててから使う
camera_fb_t* camShot(int skip)
{
  for (int i = 0; i < skip; i++)
  {
    camera_fb_t* f = esp_camera_fb_get();
    if (f) esp_camera_fb_return(f);
    delay(150);
  }
  return esp_camera_fb_get();
}

// ====================================================================
// microSD
// ====================================================================

bool sdStart()
{
  if (sdOk) return true;
  if (!SD.begin(PIN_SD_CS)) { Serial.println("microSD が読めません"); return false; }
  if (SD.cardType() == CARD_NONE) { Serial.println("microSD がありません"); return false; }
  if (!SD.exists(A_DIR)) SD.mkdir(A_DIR);
  if (!SD.exists(Q_DIR)) SD.mkdir(Q_DIR);
  sdOk = true;
  return true;
}

String qPath(time_t t) { return String(Q_DIR) + "/" + String((uint32_t)t) + ".jpg"; }

bool sdSave(const String& path, const uint8_t* buf, size_t len)
{
  File f = SD.open(path, FILE_WRITE);
  if (!f) return false;
  size_t w = f.write(buf, len);
  f.close();
  if (w != len) { SD.remove(path); return false; }
  return true;
}

// 送る前の写真の撮影時刻の一覧 (新しい順)
int listQueue(time_t* out, int maxN)
{
  int n = 0;
  File d = SD.open(Q_DIR);
  if (!d) return 0;
  // 新しい順に maxN 枚を残す
  for (File f = d.openNextFile(); f; f = d.openNextFile())
  {
    String nm = f.name();
    size_t sz = f.size();
    f.close();
    int sl = nm.lastIndexOf('/');
    if (sl >= 0) nm = nm.substring(sl + 1);
    if (!nm.endsWith(".jpg")) continue;
    time_t t = (time_t)strtoul(nm.c_str(), nullptr, 10);
    if (t <= 0) continue;
    if (sz == 0) { SD.remove(qPath(t)); continue; }   // 書きかけで止まったもの
    if (n < maxN) { out[n++] = t; }
    else
    {
      int mi = 0;
      for (int i = 1; i < n; i++) if (out[i] < out[mi]) mi = i;
      if (t > out[mi]) out[mi] = t;
    }
  }
  d.close();
  // 新しい順に並べる
  for (int i = 0; i < n; i++)
    for (int j = i + 1; j < n; j++)
      if (out[j] > out[i]) { time_t x = out[i]; out[i] = out[j]; out[j] = x; }
  return n;
}

int countQueue()
{
  int n = 0;
  File d = SD.open(Q_DIR);
  if (!d) return 0;
  for (File f = d.openNextFile(); f; f = d.openNextFile()) { n++; f.close(); }
  d.close();
  return n;
}

// 送った写真を日付のフォルダへ移す
void archive(time_t t)
{
  String day = String(A_DIR) + "/" + fmtTime(t, "%Y%m%d");
  if (!SD.exists(day)) SD.mkdir(day);
  String dst = day + "/" + fmtTime(t, "%H%M%S") + ".jpg";
  if (SD.exists(dst)) SD.remove(dst);
  if (!SD.rename(qPath(t), dst)) SD.remove(qPath(t));
}

void rmDir(const String& path)
{
  File d = SD.open(path);
  if (!d) return;
  for (File f = d.openNextFile(); f; f = d.openNextFile())
  {
    String nm = f.name();
    bool dir = f.isDirectory();
    f.close();
    int sl = nm.lastIndexOf('/');
    if (sl >= 0) nm = nm.substring(sl + 1);
    String p = path + "/" + nm;
    if (dir) rmDir(p); else SD.remove(p);
  }
  d.close();
  SD.rmdir(path);
}

// microSD がいっぱいに近ければ、送った写真の古い日から消す (1日1回。大きなカードは空きを数えるのに時間がかかるため)
void sdTidy()
{
  if (!timeValid()) return;
  String today = fmtTime(time(nullptr), "%Y%m%d");
  if (today == rtcSdCheckDay) return;
  strlcpy(rtcSdCheckDay, today.c_str(), sizeof(rtcSdCheckDay));
  for (int guard = 0; guard < 400; guard++)
  {
    uint64_t total = SD.totalBytes(), used = SD.usedBytes();
    if (total == 0 || (double)used < (double)total * SD_KEEP_RATIO) break;
    String oldest;
    File d = SD.open(A_DIR);
    if (!d) break;
    for (File f = d.openNextFile(); f; f = d.openNextFile())
    {
      String nm = f.name();
      bool dir = f.isDirectory();
      f.close();
      int sl = nm.lastIndexOf('/');
      if (sl >= 0) nm = nm.substring(sl + 1);
      if (!dir || nm.length() != 8 || !isdigit((unsigned char)nm[0])) continue;   // 日付のフォルダだけ (q は消さない)
      if (oldest.length() == 0 || nm < oldest) oldest = nm;
    }
    d.close();
    if (oldest.length() == 0) break;
    Serial.println("microSD の空きが少ないので消す: " + oldest);
    rmDir(String(A_DIR) + "/" + oldest);
  }
}

// ====================================================================
// Wi-Fi・送信
// ====================================================================

bool wifiConnect(uint32_t timeoutMs)
{
  if (WiFi.status() == WL_CONNECTED) return true;
  if (cfgSsid.length() == 0) return false;
  WiFi.persistent(false);
  if (WiFi.getMode() == WIFI_MODE_NULL) WiFi.mode(WIFI_STA);
  WiFi.begin(cfgSsid.c_str(), cfgPass.c_str());
  uint32_t t0 = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - t0 < timeoutMs) delay(100);
  if (WiFi.status() != WL_CONNECTED) { Serial.println("Wi-Fi につながりません"); return false; }
  rtcLastRssi = (int8_t)WiFi.RSSI();
  Serial.printf("Wi-Fi OK %s RSSI %d\n", WiFi.localIP().toString().c_str(), WiFi.RSSI());
  return true;
}

void ntpSync()
{
  configTzTime("JST-9", "ntp.nict.jp", "pool.ntp.org");
  uint32_t t0 = millis();
  while (!timeValid() && millis() - t0 < 8000) delay(100);
}

// v1.08: HTTP の Date (例 "Mon, 24 Nov 2026 01:02:03 GMT") を epoch 秒にする。読めなければ 0
time_t parseHttpDate(const String& d)
{
  static const char* MON = "JanFebMarAprMayJunJulAugSepOctNovDec";
  int c = d.indexOf(',');
  if (c < 0) return 0;
  String s = d.substring(c + 1);
  s.trim();   // "24 Nov 2026 01:02:03 GMT"
  int day = 0, year = 0, hh = 0, mm = 0, ss = 0;
  char mon[4] = {0};
  if (sscanf(s.c_str(), "%d %3s %d %d:%d:%d", &day, mon, &year, &hh, &mm, &ss) != 6) return 0;
  const char* f = strstr(MON, mon);
  if (!f || mon[0] == 0) return 0;
  int m = (int)(f - MON) / 3 + 1;
  // 1970-01-01 からの日数 (グレゴリオ暦、タイムゾーンに左右されない計算)
  int y = year - (m <= 2 ? 1 : 0);
  int era = y / 400;
  int yoe = y - era * 400;
  int doy = (153 * (m + (m > 2 ? -3 : 9)) + 2) / 5 + day - 1;
  int doe = yoe * 365 + yoe / 4 - yoe / 100 + doy;
  long days = (long)era * 146097L + doe - 719468L;
  return (time_t)(days * 86400L + hh * 3600L + mm * 60L + ss);
}

// v1.08: NTP で合わないときの予備。送信先のサーバーに聞いて、返事の Date で時計を合わせる
bool serverTimeSync()
{
  if (WiFi.status() != WL_CONNECTED || cfgUrl.length() == 0) return false;
  String u = uploadUrl();
  HTTPClient http;
  WiFiClientSecure sc;
  WiFiClient pc;
  http.setConnectTimeout(8000);
  http.setTimeout(8000);
  bool ok;
  if (u.startsWith("https://"))
  {
    sc.setInsecure();
    sc.setHandshakeTimeout(15);
    ok = http.begin(sc, u);
  }
  else ok = http.begin(pc, u);
  if (!ok) return false;
  const char* keys[] = {"Date"};
  http.collectHeaders(keys, 1);
  int code = http.sendRequest("HEAD");
  String d = http.header("Date");
  http.end();
  time_t t = parseHttpDate(d);
  Serial.printf("サーバーの時刻で合わせる: %d 「%s」\n", code, d.c_str());
  if (t < TIME_VALID) return false;
  setClock(t);
  return timeValid();
}

// v1.08: NTP → だめならサーバーの時刻
void timeSync()
{
  ntpSync();
  if (!timeValid()) serverTimeSync();
}

// 写真1枚を送る。from は Stream (microSD のファイル) か、メモリの JPEG。成功したら HTTP 200 で ok:true
int postPhoto(time_t t, float vbat, Stream* from, const uint8_t* buf, size_t len, String& reply)
{
  String url = uploadUrl() + "?id=" + camId + "&t=" + String((uint32_t)t) + "&bv=" + String(vbat, 2) +
               "&rssi=" + String((int)WiFi.RSSI());
  if (!isnan(bootTempC)) url += "&tc=" + String(bootTempC, 1);
  url += "&fw=" + String(FW_BUILD);   // v1.06: ファームの版 (HPX-note のカメラのページに出す)
  if (cfgName.length()) url += "&name=" + urlEncode(cfgName);
  WiFiClientSecure sc;
  WiFiClient pc;
  HTTPClient http;
  http.setTimeout(20000);
  http.setConnectTimeout(10000);
  bool ok = url.startsWith("https://") ? (sc.setInsecure(), sc.setHandshakeTimeout(20), http.begin(sc, url))
                                       : http.begin(pc, url);
  if (!ok) return -1;
  http.addHeader("Authorization", "Bearer " + cfgToken);
  http.addHeader("Content-Type", "image/jpeg");
  int code = from ? http.sendRequest("POST", from, len) : http.POST((uint8_t*)buf, len);
  reply = code > 0 ? http.getString() : String();
  http.end();
  rtcLastHttp = (int16_t)code;
  Serial.printf("送信 t=%lu %u B → %d %s\n", (unsigned long)t, (unsigned)len, code, reply.c_str());
  return code;
}

// サーバーの返事から撮影の間隔・時間帯・時計を受け取る
void applyReply(const String& r)
{
  long iv, h0, h1, now;
  if (jsonInt(r, "now", now)) setClock((time_t)now);
  if (jsonInt(r, "iv", iv) && jsonInt(r, "h0", h0) && jsonInt(r, "h1", h1)) saveSchedule((int)iv, (int)h0, (int)h1);
  // 写真の大きさ: HPX-note で選んでいれば "sz" が付く。付いていなければ選んでいない (管理画面の設定を使う)
  long sz = -1;
  if (!jsonInt(r, "sz", sz) || sz < 0 || sz > 3) sz = -1;
  if ((int8_t)sz != srvSize)
  {
    srvSize = (int8_t)sz;
    prefs.begin("hpxcam", false);
    prefs.putChar("srvsz", srvSize);
    prefs.end();
    camApply();   // カメラが動いていれば、次に撮る写真から新しい大きさにする
  }
  // 写真の向き (v1.07): HPX-note で選んでいれば "fl" が付く。付いていなければ管理画面の設定を使う
  long fl = -1;
  if (!jsonInt(r, "fl", fl) || fl < 0 || fl > 3) fl = -1;
  if ((int8_t)fl != srvFlip)
  {
    srvFlip = (int8_t)fl;
    prefs.begin("hpxcam", false);
    prefs.putChar("srvfl", srvFlip);
    prefs.end();
    camApply();
  }
}

// microSD にたまった写真を新しい順に送る。送れた枚数を返す
int uploadQueue(float vbat)
{
  static time_t list[UPLOAD_MAX_FILES];
  int n = listQueue(list, UPLOAD_MAX_FILES);
  int sent = 0;
  uint32_t t0 = millis();
  for (int i = 0; i < n; i++)
  {
    if (millis() - t0 > UPLOAD_BUDGET_MS) break;
    File f = SD.open(qPath(list[i]), FILE_READ);
    if (!f) continue;
    size_t len = f.size();
    String r;
    int code = postPhoto(list[i], vbat, &f, nullptr, len, r);
    f.close();
    if (code == 200 && r.indexOf("\"ok\":true") >= 0)
    {
      applyReply(r);
      archive(list[i]);
      sent++;
    }
    else if (code == 400 || code == 413 || code == 415)
    {
      // サーバーが受け取れない写真 (壊れている・大きすぎる) は送り直しても同じなので、送った扱いで移す
      archive(list[i]);
    }
    else break;   // つながらない・トークン違い・上限など。次に起きたときに送り直す
  }
  if (sent > 0) rtcLastUpload = time(nullptr);
  return sent;
}

// ====================================================================
// 眠る時間の計算
// ====================================================================

bool inHours(time_t t)
{
  if (curH1 <= curH0) return true;   // 時間帯の指定がおかしいときは一日中
  struct tm tmv;
  localtime_r(&t, &tmv);
  return tmv.tm_hour >= curH0 && tmv.tm_hour < curH1;
}

// 次に撮る時刻 (間隔の区切り。例 10分なら :00 :10 :20 …。時間帯の外なら次の時間帯の始め)
time_t nextShot(time_t now)
{
  const time_t step = (time_t)curIv * 60;
  const time_t jst = 9 * 3600;
  time_t n = ((now + jst + 20) / step + 1) * step - jst;   // 20秒の余裕 (撮った直後に同じ区切りをもう一度選ばない)
  if (inHours(n)) return n;
  struct tm tmv;
  localtime_r(&n, &tmv);
  if (tmv.tm_hour >= curH1) tmv.tm_mday += 1;   // 今日の時間帯は終わった → 明日の始め
  tmv.tm_hour = curH0;
  tmv.tm_min = 0;
  tmv.tm_sec = 0;
  return mktime(&tmv);
}

void goSleep(uint32_t sec)
{
  if (sec < 20) sec = 20;
  Serial.printf("眠る %lu 秒\n", (unsigned long)sec);
  Serial.flush();
  camStop();
  if (sdOk) SD.end();
  WiFi.disconnect(true);
  WiFi.mode(WIFI_OFF);
  esp_sleep_enable_timer_wakeup((uint64_t)sec * 1000000ULL);
  esp_deep_sleep_start();
}

void sleepUntilNext()
{
  if (!timeValid()) goSleep((uint32_t)curIv * 60);
  time_t now = time(nullptr);
  time_t n = nextShot(now);
  // 体内時計は少しずれるので、長く眠るときは少し早めに起きる (起きたら時計を合わせ直してまた眠る)
  long s = (long)(n - now);
  if (s > 3 * 3600) s = 3 * 3600;
  goSleep((uint32_t)(s > 0 ? s : 20));
}

// ====================================================================
// 撮影モード (タイマーで起きたとき)
// ====================================================================

// ====================================================================
// ファームウェアの自動更新 (HPX-Core と同じしくみ)
// ====================================================================

static const uint32_t OTA_INTERVAL_S = 24UL * 3600;   // 1日1回 (HPX-Core の電池のときと同じ)
static const float OTA_MIN_VBAT = 4.0f;                // これより電池が低いときは更新しない (USB だけのときは更新する)
bool otaPendingVerify = false;
esp_timer_handle_t guardTimer = nullptr;

// 新しいファームで初めて起きたかを調べる
void otaCheckState()
{
  const esp_partition_t* running = esp_ota_get_running_partition();
  esp_ota_img_states_t st;
  if (running && esp_ota_get_state_partition(running, &st) == ESP_OK) otaPendingVerify = (st == ESP_OTA_IMG_PENDING_VERIFY);
  if (otaPendingVerify) Serial.println("OTA: 新しいファームで初めて起きた (Wi-Fi につながれば確定)");
}

void otaMarkValid()
{
  if (!otaPendingVerify) return;
  esp_ota_mark_app_valid_cancel_rollback();
  otaPendingVerify = false;
  Serial.println("OTA: 新しいファームを確定した");
}

// 新しいファームで Wi-Fi につながらなかったら、前のファームへ戻す
void otaRollbackIfPending()
{
  if (!otaPendingVerify) return;
  Serial.println("OTA: 新しいファームで Wi-Fi につながらないので、前のファームへ戻す");
  Serial.flush();
  esp_ota_mark_app_invalid_rollback_and_reboot();
}

// マニフェスト (1行目 ビルド番号、2行目 .bin の URL) を読む
bool otaManifest(int& build, String& binUrl)
{
  WiFiClientSecure sc;
  sc.setInsecure();   // HPX-Core と同じく証明書は確かめない
  sc.setHandshakeTimeout(20);
  HTTPClient http;
  http.setConnectTimeout(8000);
  http.setTimeout(10000);
  http.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
  if (!http.begin(sc, cfgOta)) return false;
  int code = http.GET();
  if (code != 200) { Serial.printf("OTA: マニフェストを取れません (%d)\n", code); http.end(); return false; }
  String body = http.getString();
  http.end();
  int nl = body.indexOf('\n');
  if (nl < 0) return false;
  String l1 = body.substring(0, nl), l2 = body.substring(nl + 1);
  int nl2 = l2.indexOf('\n');
  if (nl2 >= 0) l2 = l2.substring(0, nl2);
  l1.trim(); l2.trim();
  build = l1.toInt();
  binUrl = l2;
  if (build <= 0 || !binUrl.startsWith("https://")) { Serial.println("OTA: マニフェストの形が違います"); return false; }
  return true;
}

// v1.08: 確認の結果を覚える (管理画面に出す。電源を入れなおしても消えないよう NVS にも)
void otaSetResult(const String& msg)
{
  otaCheckText = (timeValid() ? fmtTime(time(nullptr), "%m/%d %H:%M") + " " : String("")) + msg;
  prefs.begin("hpxcam", false);
  prefs.putString("otares", otaCheckText);
  prefs.end();
  Serial.println("OTA: " + otaCheckText);
}

// マニフェストの .bin を受け取って書き込む。うまくいけば再起動するので戻らない
void otaApply(int build, const String& binUrl)
{
  otaSetResult(String(build) + " に更新しようとしました (再起動しないときは失敗です。手で入れてください)");
  camStop();
  if (guardTimer) esp_timer_stop(guardTimer);   // 受け取りに時間がかかるので、止まったときの見張りを止める
  WiFiClientSecure sc;
  sc.setInsecure();
  sc.setHandshakeTimeout(20);
  httpUpdate.rebootOnUpdate(true);
  httpUpdate.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
  Serial.println("OTA: 受け取り " + binUrl);
  t_httpUpdate_return ret = httpUpdate.update(sc, binUrl);   // うまくいくと再起動するので戻らない
  Serial.printf("OTA: 失敗 %d (%s)\n", (int)ret, httpUpdate.getLastErrorString().c_str());
  otaSetResult(String(build) + " への更新に失敗しました (" + httpUpdate.getLastErrorString() + ")");
  if (guardTimer) esp_timer_start_once(guardTimer, 60ULL * 1000000ULL);
}

// 1日1回、新しいファームがあれば書き込んで再起動する (Wi-Fi につながっていること)
void otaMaybe(float vbat)
{
  if (cfgOta.length() == 0 || !timeValid() || WiFi.status() != WL_CONNECTED) return;
  if (vbat > 1.0f && vbat < OTA_MIN_VBAT) return;
  time_t now = time(nullptr);
  if (rtcLastOta != 0 && now - (time_t)rtcLastOta < (time_t)OTA_INTERVAL_S) return;
  // v1.08: 起きている時間の上限の近くでは確かめない (途中で眠らされると、その日の確認が済んだことになっていた)。次に起きたときに回す
  if (millis() - bootMs > AWAKE_MAX_MS - 45000) { Serial.println("OTA: 時間が足りないので次に回す"); return; }
  rtcLastOta = (int64_t)now;
  int build = 0;
  String binUrl;
  if (!otaManifest(build, binUrl))
  {
    otaSetResult("マニフェストを読めませんでした (1時間後にもう一度)");
    rtcLastOta = (int64_t)now - (int64_t)OTA_INTERVAL_S + 3600;   // 1時間後にもう一度
    return;
  }
  Serial.printf("OTA: マニフェスト build=%d、今のファーム %d\n", build, FW_BUILD);
  if (build <= FW_BUILD) { otaSetResult("最新です (サーバー " + String(build) + " / この機器 " + String(FW_BUILD) + ")"); return; }
  otaApply(build, binUrl);
}

void runCycle()
{
  float vbat = readVbat();
  Serial.printf("電池 %.2fV\n", vbat);
  if (vbat > 1.0f && vbat < VBAT_LOW)
  {
    Serial.println("電池が少ないので撮らずに眠る");
    otaMarkValid();   // 電池切れ間近で前のファームへ戻すことはしない
    goSleep(LOW_BAT_SLEEP_S);
  }

  // 時計がまだ合っていなければ、先に Wi-Fi でつないで合わせる
  if (!timeValid())
  {
    if (wifiConnect(WIFI_TIMEOUT_MS)) { timeSync(); otaMarkValid(); }   // v1.08: NTP がだめならサーバーの時刻
    if (!timeValid()) { otaRollbackIfPending(); goSleep((uint32_t)curIv * 60); }
  }
  time_t now = time(nullptr);

  // 時間帯の外で起きた (長い眠りの途中) → 時計を合わせ直す必要はないのでまた眠る
  if (!inHours(now + 90))
  {
    if (otaPendingVerify) { if (wifiConnect(WIFI_TIMEOUT_MS)) otaMarkValid(); else otaRollbackIfPending(); }
    sleepUntilNext();
  }

  // 区切りの時刻にそろえる (少し早く起きたときは待つ)
  time_t step = (time_t)curIv * 60;
  // (体内時計のずれで最大90秒早く起きることがあるので、そのときは区切りまで待つ)
  time_t mark = ((now + 9 * 3600 + 90) / step) * step - 9 * 3600;
  if (mark > now && mark - now <= 90) { delay((mark - now) * 1000); now = mark; }
  else if (now - mark > 120) mark = now;   // 区切りから大きくずれているときはその時刻で撮る
  time_t shotT = mark > now ? now : mark;

  // 撮る
  sdStart();
  camera_fb_t* fb = nullptr;
  if (camStart()) fb = camShot(3);
  bool queued = false;
  String r;
  if (fb)
  {
    Serial.printf("写真 %u B\n", (unsigned)fb->len);
    if (sdOk) queued = sdSave(qPath(shotT), fb->buf, fb->len);
  }
  else Serial.println("写真を撮れませんでした");

  // 送るかどうか (撮るたび / 1時間に1回まとめて。時間帯の最後の1枚と、電源を入れ直した最初はすぐ送る)
  bool sendNow = (cfgSend == 0) || rtcLastUpload == 0 || (time(nullptr) - rtcLastUpload >= 3600 - 60) ||
                 !inHours(nextShot(time(nullptr)) - 1) || !queued;
  if (sendNow && wifiConnect(WIFI_TIMEOUT_MS))
  {
    if (!queued && fb)
    {
      // microSD が無い・書けないときはメモリから直接送る (送れなければその写真は無くなる)
      int code = postPhoto(shotT, vbat, nullptr, fb->buf, fb->len, r);
      if (code == 200) { applyReply(r); rtcLastUpload = time(nullptr); }
    }
    if (fb) { esp_camera_fb_return(fb); fb = nullptr; }
    camStop();   // 送る間はカメラを止めて電気を減らす
    if (sdOk) uploadQueue(vbat);
    if (!timeValid()) timeSync();   // サーバーの時計が届かないときの予備
    else if (rtcBoot % 36 == 0) ntpSync();
    otaMarkValid();
    otaMaybe(vbat);
  }
  if (fb) esp_camera_fb_return(fb);
  camStop();
  if (sdOk) sdTidy();
  if (otaPendingVerify)
  {
    // 新しいファームで初めて起きたのに、まだ Wi-Fi で確かめていない (まとめて送るとき・時間帯の外など) → つないで確かめる
    if (wifiConnect(WIFI_TIMEOUT_MS)) otaMarkValid();
    else otaRollbackIfPending();
  }
  sleepUntilNext();
}

// ====================================================================
// 設定モード (電源を入れたとき)
// ====================================================================

WebServer server(80);
DNSServer dns;
uint32_t lastTouch = 0;
bool trialRunning = false;
String trialMsg;

static const char PAGE_CSS[] =
  "<style>"
  "body{font-size:18px;line-height:1.6;margin:12px;"
  "font-family:'Hiragino Kaku Gothic ProN',Meiryo,'Yu Gothic',sans-serif}"
  "h1{font-size:1.5em}"
  "input,select,button{font-size:1em;max-width:100%;box-sizing:border-box}"
  "input[type=text],input[type=password],select{padding:8px;min-height:44px;width:26em}"
  "button{padding:10px 16px;min-height:44px;margin:4px 0;border-radius:8px}"
  "input[type=submit],.go{display:block;width:100%;max-width:26em;padding:16px;margin:12px 0;"
  "font-size:1.25em;font-weight:bold;color:#fff;background:#2e7d32;border:0;border-radius:10px;text-align:center;text-decoration:none}"
  ".sub{background:#555}"
  "input[type=radio],input[type=checkbox]{width:1.4em;height:1.4em;vertical-align:middle}"
  "img{max-width:100%;border:1px solid #ccc}"
  "small{color:#555}"
  "</style>";

String pageHeadR(const String& title, int refresh)
{
  String h = "<!DOCTYPE html><html lang='ja'><head><meta charset='UTF-8'>"
             "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
  if (refresh > 0) h += "<meta http-equiv='refresh' content='" + String(refresh) + "'>";
  h += PAGE_CSS;
  h += "<title>" + title + "</title></head><body>";
  return h;
}

String pageHead(const String& title) { return pageHeadR(title, 0); }

void sendHtml(const String& h)
{
  server.sendHeader("Cache-Control", "no-store");
  server.send(200, "text/html; charset=utf-8", h);
}

void touch() { lastTouch = millis(); }

bool requireAuth()
{
  touch();
  if (cfgAdmin.length() == 0) return true;
  if (server.authenticate("admin", cfgAdmin.c_str())) return true;
  server.requestAuthentication(BASIC_AUTH, "HPX");
  return false;
}

bool netConfigured() { return cfgSsid.length() > 0 && cfgAdmin.length() > 0; }

void redirectTo(const char* where)
{
  server.sendHeader("Location", where, true);
  server.send(303, "text/plain", "");
}

String apName() { return "HPX-Setup-" + camId.substring(8); }
// 機器ID (HPX-Core と同じしくみ)。初めは MAC アドレスの最後の6けたから作り、管理画面の「機器ID変更」で変えられる。
// 管理画面の名前の URL は http://<機器ID>.local/ になる
String defaultDeviceId() { String t = camId.substring(6); t.toLowerCase(); return "hpx-cam-" + t; }
String deviceId() { return cfgDevId.length() ? cfgDevId : defaultDeviceId(); }
String mdnsHost() { return deviceId(); }
bool apMode = true;   // true = 設定用 Wi-Fi で開いている、false = ルーター経由で開いている

// ---- 1段目: ネットワーク設定 (HPX-Core の初回設定と同じ形) ----
enum { NT_NONE, NT_CONNECTING, NT_OK, NT_FAIL };
uint8_t netTrial = NT_NONE;
uint32_t netTrialMs = 0;
static const uint32_t NET_TRIAL_TIMEOUT_MS = 25000;   // HPX-Core と同じ

void handleNetPage()
{
  if (!requireAuth()) return;
  if ((netTrial == NT_CONNECTING || netTrial == NT_OK) && !server.hasArg("edit")) { redirectTo("/nresult"); return; }
  String h = pageHead("ネットワーク設定");
  h += "<h1>ネットワーク設定</h1>";
  if (netTrial == NT_FAIL)
  {
    h += "<p style='color:#c00'><b>Wi-Fi「" + htmlEscape(cfgSsid) + "」につながりませんでした。</b><br>"
         "SSID(Wi-Fiの名前)とWi-Fiパスワードを確かめて、もう一度保存してください。"
         "ルーターが近くにあるか、2.4GHz の Wi-Fi か(ESP32 は 5GHz につなげません)も確かめてください。</p>";
  }
  h += "<p>機器ID: <b>" + htmlEscape(deviceId()) + "</b></p>";
  h += "<form action='/save' method='POST'>";
  h += "<label>接続方法</label><br><b>Wi-Fi</b><br><small>HPX-CAM は Wi-Fi だけです (有線LAN・LTE-M はありません)</small><br><br>";
  h += "<label for='ssid'>SSID (LTEルーターなど)</label><br><input type='text' id='ssid' name='ssid' maxlength='32' value='" +
       htmlEscape(cfgSsid) + "'><br><br>";
  h += "<label for='password'>Wi-Fiパスワード</label><br><input type='password' id='password' name='password' maxlength='63'><br><br>";
  h += "<label for='adminpw'>管理画面のパスワード(6〜32文字。ユーザー名は admin)</label><br>"
       "<input type='password' id='adminpw' name='adminpw' maxlength='32'" + String(cfgAdmin.length() == 0 ? " required" : "") + "><br>";
  if (cfgAdmin.length() > 0) h += "<small>変更しない場合は空欄のまま</small><br>";
  h += "<br><label>動作モード</label><br><b>ディープスリープ (電池)</b><br><small>撮るときだけ起きて、あとは眠ります (HPX-CAM は常時稼働を選べません)</small><br><br>";
  h += "<input type='submit' value='保存'>";
  h += "</form>";
  if (configured()) h += "<p><a href='/'>管理画面へ戻る</a></p>";
  h += "</body></html>";
  sendHtml(h);
}

void handleNetSave()
{
  if (!requireAuth()) return;
  String ssid = server.arg("ssid"), pass = server.arg("password"), apw = server.arg("adminpw");
  ssid.trim();
  if (ssid.length() == 0 || ssid.length() > 32) { server.send(400, "text/plain; charset=utf-8", "SSIDは1〜32文字で入力してください。"); return; }
  if (pass.length() != 0 && (pass.length() < 8 || pass.length() > 63)) { server.send(400, "text/plain; charset=utf-8", "Wi-Fiパスワードは8〜63文字(または空欄)にしてください。"); return; }
  if (cfgAdmin.length() == 0 && apw.length() == 0) { server.send(400, "text/plain; charset=utf-8", "管理画面のパスワードを入力してください。"); return; }
  if (apw.length() != 0 && (apw.length() < 6 || apw.length() > 32)) { server.send(400, "text/plain; charset=utf-8", "管理画面のパスワードは6〜32文字にしてください。"); return; }
  cfgSsid = ssid;
  cfgPass = pass;   // HPX-Core と同じく、空欄はパスワードなしの Wi-Fi
  if (apw.length()) cfgAdmin = apw;
  saveConfig();
  if (!apMode)
  {
    // ルーター経由で開いているときは、保存して再起動する (新しい Wi-Fi につながらなければ設定用 Wi-Fi が出る)
    String h = pageHead("ネットワーク設定保存");
    h += "<h1>設定を保存しました</h1><p>HPX-CAM を再起動して、Wi-Fi「" + htmlEscape(cfgSsid) + "」につなぎ直します。</p>"
         "<p>つながらなかったときは、設定用の Wi-Fi「" + apName() + "」が出るので、つないで http://192.168.4.1/ を開いてください。</p></body></html>";
    sendHtml(h);
    delay(1500);
    ESP.restart();
    return;
  }
  // HPX-Core と同じく、設定用 Wi-Fi を出したまま試しにつなぐ
  WiFi.disconnect(false);
  WiFi.mode(WIFI_AP_STA);
  WiFi.begin(cfgSsid.c_str(), cfgPass.c_str());
  netTrial = NT_CONNECTING;
  netTrialMs = millis();
  redirectTo("/nresult");
}

void netTrialLoop()
{
  if (netTrial != NT_CONNECTING) return;
  if (WiFi.status() == WL_CONNECTED) { netTrial = NT_OK; rtcLastRssi = (int8_t)WiFi.RSSI(); }
  else if (millis() - netTrialMs > NET_TRIAL_TIMEOUT_MS)
  {
    netTrial = NT_FAIL;
    WiFi.disconnect(false);
    WiFi.mode(WIFI_AP);
  }
}

void handleNetResult()
{
  touch();
  if (netTrial == NT_NONE || netTrial == NT_FAIL) { redirectTo("/net"); return; }
  if (netTrial == NT_CONNECTING)
  {
    String h = pageHeadR("接続を確かめています", 3);
    h += "<h1>Wi-Fi「" + htmlEscape(cfgSsid) + "」につないでいます…</h1>";
    h += "<p>つながるか確かめているところです(最大" + String(NET_TRIAL_TIMEOUT_MS / 1000) + "秒)。この画面は自動で変わります。</p>";
    h += "<p><small>途中でスマートフォンの Wi-Fi が切れたときは、もう一度「" + apName() + "」につなぎ、"
         "<a href='http://192.168.4.1/'>http://192.168.4.1/</a> を開いてください。</small></p></body></html>";
    sendHtml(h);
    return;
  }
  const String ipUrl = "http://" + WiFi.localIP().toString() + "/";
  const String nameUrl = "http://" + mdnsHost() + ".local/";
  String h = pageHead("管理画面のURL");
  h += "<h1>Wi-Fi「" + htmlEscape(cfgSsid) + "」につながりました</h1>";
  h += "<p><b>まだ切り替えていません。</b>切り替えたあとは、HPX-CAM の管理画面を次のURLで開きます。"
       "<b>スクリーンショットを撮るか、メモしてください。</b></p>";
  h += "<p style='font-size:1.4em;border:2px solid #333;padding:8px'><b>" + htmlEscape(ipUrl) + "</b></p>";
  h += "<p>名前でも開けます: <b>" + htmlEscape(nameUrl) + "</b><br>"
       "<small>(iPhone・Mac・Windows 向け。Android では開けないことがあります)</small></p>";
  h += "<p>開くときは、スマートフォンやPCを <b>HPX-CAM と同じ Wi-Fi「" + htmlEscape(cfgSsid) + "」</b>につないでください。"
       "ユーザー名は admin、パスワードはさきほど決めた管理画面のパスワードです。</p>";
  h += "<p><b>HPX-CAM は撮影を始めると眠っているので、管理画面を開けるのは電源を入れてから約10分の間です。</b>"
       "あとで開くときは、電源を入れなおしてから開いてください。</p>";
  h += "<p>電波の強さ: " + String(WiFi.RSSI()) + " dBm (−80 より弱いと写真を送れないことがあります)</p>";
  h += "<p><small>IPアドレスはルーターの都合で変わることがあります。開けなくなったら、ルーターの接続機器一覧で「" +
       mdnsHost() + "」を探してください。</small></p>";
  h += "<form action='/nfinish' method='POST'>";
  h += "<p><label><input type='checkbox' name='noted' value='1' required> 管理画面のURLを控えました</label></p>";
  h += "<button type='submit' class='go'>OK(Wi-Fiを切り替える)</button></form>";
  h += "<p><a href='/net?edit=1'>設定をやり直す</a></p></body></html>";
  sendHtml(h);
}

void handleNetFinish()
{
  touch();
  if (netTrial != NT_OK || !server.hasArg("noted")) { redirectTo("/net"); return; }
  const String ip = WiFi.localIP().toString();
  String h = pageHead("切り替えます");
  h += "<h1>切り替えます</h1>";
  h += "<p>HPX-CAM を再起動して、Wi-Fi「" + htmlEscape(cfgSsid) + "」で動き始めます。</p>";
  h += "<p>スマートフォンやPCを「" + htmlEscape(cfgSsid) + "」に戻し、約30秒後に "
       "<a href='http://" + ip + "/'>http://" + ip + "/</a> を開いて、「動作設定」で送信先のURLとトークンを入れてください。</p>";
  h += "</body></html>";
  sendHtml(h);
  delay(1500);
  ESP.restart();
}

// ---- 2段目: 管理画面 ----
void handleRoot()
{
  if (!netConfigured()) { handleNetPage(); return; }   // 初めはネットワーク設定から (HPX-Core と同じ)
  if (!requireAuth()) return;
  String h = pageHead("HPX-CAM 管理画面");
  h += "<h1>HPX-CAM 管理画面</h1>";
  h += "<p><b>機器ID : " + htmlEscape(deviceId()) + "</b><br>固有ID(MAC) : " + camId + "<br><b>Firmware Version: " + String(FW_VERSION) + " (build " + String(FW_BUILD) + ")</b><br>";
  if (cfgOta.length() == 0) h += "自動更新の確認: " + otaOffReason() + "<br>";
  else
  {
    // v1.08: 最後の確認の結果と、確認できない理由
    String ot = "撮影で起きたとき1日1回 (電源を入れなおすと、撮影を始めた最初に確かめます)";
    ot += "<br>　前の確認: " + (otaCheckText.length() ? htmlEscape(otaCheckText) : String("まだ確認していません"));
    if (!timeValid()) ot += "<br>　<span style='color:#c00'>時刻が合っていません。合うまで撮影と自動更新の確認をしません (時刻合わせ NTP・サーバーの時刻を待っています)</span>";
    else if (rtcLastOta != 0)
    {
      const long left = (long)((time_t)rtcLastOta + (time_t)OTA_INTERVAL_S - time(nullptr));
      if (left > 0) ot += "<br>　次の自動確認は約 " + String((left + 3599) / 3600) + " 時間後";
    }
    if (apMode) ot += "<br>　<span style='color:#c00'>いまは設定用 Wi-Fi なので、ルーターにつながらず確認できません</span>";
    h += "自動更新の確認: " + ot + "<br>";
  }
  if (!apMode) h += "管理画面: http://" + WiFi.localIP().toString() + "/ ・ http://" + htmlEscape(mdnsHost()) + ".local/<br>";
  float v = readVbat();
  h += "電池: " + (v > 1.0f ? String(v, 2) + " V" : String("測れません (USB だけのとき)")) + "<br>";
  h += "ケースの温度 (電源を入れた直後のチップの温度、目安): " + (isnan(bootTempC) ? String("測れません") : String(bootTempC, 1) + " ℃") + "<br>";
  if (sdOk)
    h += "microSD: " + String((uint32_t)(SD.cardSize() / (1024ULL * 1024ULL))) + " MB、送る前の写真 " + String(countQueue()) + " 枚<br>";
  else h += "<span style='color:#c00'>microSD: 読めません (無しでも動きますが、送れなかった写真は残りません)</span><br>";
  h += "Wi-Fi: " + htmlEscape(cfgSsid) + "<br>";
  h += "撮影: " + String(curIv) + "分ごと、" + String(curH0) + "時〜" + String(curH1) + "時 (HPX-note のカメラのページで変えられます)";
  if (rtcLastHttp != 0) h += "<br>前の送信の結果: " + String(rtcLastHttp);
  h += "</p>";
  if (!configured())
    h += "<p style='color:#c00'><b>送信先が設定されていません。「動作設定」でサーバーのアドレスとトークンを入れてください。</b></p>";
  h += "<p><a class='go sub' href='/snap' target='_blank'>試し撮り (向きとピントを見る)</a></p>";
  h += "<p><a class='go sub' href='/settings'>動作設定 (送信先・写真の大きさなど)</a></p>";
  if (configured())
  {
    h += "<form action='/test' method='POST'><button class='go sub'>接続テスト (試しに1枚送る)</button></form>";
    h += "<form action='/start' method='POST'><button class='go'>管理画面を終えて撮影を始める</button></form>";
    h += "<p><small>このまま約10分さわらなければ、自動で撮影を始めます。撮影を始めたあとで管理画面を開くときは、電源を入れなおしてください。</small></p>";
  }
  h += "<p><a href='/net?edit=1'>ネットワーク設定 (Wi-Fi・管理画面のパスワード)</a></p>";
  h += "<p><a href='/deviceid'>機器ID変更</a></p>";
  h += "<form action='/otanow' method='POST'><button class='go sub'>今すぐ更新を確認</button></form>";   // v1.08
  h += "<p><a href='/update'>ファームウェア更新</a></p>";
  h += "<p><small>最初からやり直すときは、電源を入れなおし、3秒以内に BOOT ボタンを5秒長押しします。</small></p>";
  h += "</body></html>";
  sendHtml(h);
}

void handleSettingsPage()
{
  if (!netConfigured()) { redirectTo("/"); return; }
  if (!requireAuth()) return;
  String h = pageHead("動作設定");
  h += "<h1>動作設定</h1><form action='/savesettings' method='POST'>";
  h += "<label>送信先のURL</label><br><input type='text' name='url' maxlength='200' value='" +
       htmlEscape(cfgUrl) + "' placeholder='https://example.com/hpx/receive.php'><br>"
       "<small>HPX-Core と同じ送信先 (…/receive.php) を入れてください。同じフォルダの cam_upload.php へ送ります</small><br><br>";
  h += "<label>トークン</label><br><input type='password' name='tok' maxlength='100'><br>"
       "<small>HPX-note のマイアカウントに出る、HPX-Core と同じ機器用トークン。";
  if (cfgToken.length()) h += "変更しない場合は空欄のまま";
  h += "</small><br><br>";
  h += "<label>カメラの名前 (初めの1回だけ HPX-note に送ります。あとは HPX-note で変えます)</label><br>"
       "<input type='text' name='name' maxlength='30' value='" + htmlEscape(cfgName) + "'><br><br>";
  h += "<label>写真の大きさ</label><br><select name='size'>";
  for (uint8_t i = 0; i < 4; i++)
    h += "<option value='" + String(i) + "'" + (cfgSize == i ? " selected" : "") + ">" + sizeName(i) + "</option>";
  h += "</select><br><small>大きいほど電池と通信量が増えます。ふつうは SVGA で十分です。"
       "HPX-note のカメラのページで写真の大きさを選んだときは、そちらが優先されます</small><br>";
  if (srvSize >= 0) h += "<small style='color:#c60'>今は HPX-note で選んだ「" + String(sizeName((uint8_t)srvSize)) + "」で撮っています</small><br>";
  h += "<br>";
  h += "<label><input type='checkbox' name='flip' value='1'" + String(cfgFlip ? " checked" : "") +
       "> 上下を逆にする (写真が上下さかさまに写るとき)</label><br>";
  h += "<label><input type='checkbox' name='mir' value='1'" + String(cfgMirror ? " checked" : "") +
       "> 左右を逆にする (写真が鏡に映したように左右反対に写るとき)</label><br>"
       "<small>HPX-note のカメラのページで写真の向きを選んだときは、そちらが優先されます</small><br>";
  if (srvFlip >= 0) h += "<small style='color:#c60'>今は HPX-note で選んだ向き (上下" + String((srvFlip & 1) ? "逆" : "そのまま") + "・左右" + String((srvFlip & 2) ? "逆" : "そのまま") + ") で撮っています</small><br>";
  h += "<br>";
  h += "<label>送り方</label><br>";
  h += "<label><input type='radio' name='send' value='0'" + String(cfgSend == 0 ? " checked" : "") +
       "> 撮るたびに送る (おすすめ。変化のお知らせが早い)</label><br>";
  h += "<label><input type='radio' name='send' value='1'" + String(cfgSend == 1 ? " checked" : "") +
       "> 1時間に1回まとめて送る (電池が長もち。お知らせは最大1時間遅れ)</label><br>";
  h += "<small>撮影の間隔と時間帯は HPX-note のカメラのページで決めます</small><br><br>";
  h += "<label><input type='checkbox' name='ota' value='1'" + String(cfgAutoOta ? " checked" : "") + "> ファームウェアを自動更新する</label><br>";
  h += "<small>送信先と同じサーバーの firmware/manifest_cam.txt を見て、新しい版があれば1日1回確かめて自分で更新します。送信先のURLが https:// のときだけ使えます。";
  {
    const String ou = otaUrlFromServer();
    if (ou.length()) h += "今の確認先: " + htmlEscape(ou);
  }
  h += "</small><br>";
  h += "<input type='submit' value='保存'></form>";
  h += "<p><a href='/'>管理画面へ戻る</a></p></body></html>";
  sendHtml(h);
}

void handleDeviceIdPage()
{
  if (!netConfigured()) { redirectTo("/"); return; }
  if (!requireAuth()) return;
  String h = pageHead("機器ID設定");
  h += "<h1>機器ID設定</h1>";
  h += "<p>現在の機器ID：<b>" + htmlEscape(deviceId()) + "</b></p>";
  h += "<form action='/saveid' method='POST'>";
  h += "<label for='deviceid'>新しい機器ID</label><br>";
  h += "<input type='text' id='deviceid' name='deviceid' pattern='[a-z0-9]+([a-z0-9-]*[a-z0-9])?' maxlength='32' value='" +
       htmlEscape(deviceId()) + "' required>";
  h += "<br><small>半角英小文字(a-z)、数字(0-9)、ハイフン(-)のみ（先頭・末尾のハイフンは使用不可）。"
       "管理画面の名前の URL が http://&lt;機器ID&gt;.local/ になります。HPX-note に出るカメラの名前は、HPX-note のカメラのページで変えます</small><br><br>";
  h += "<input type='submit' value='保存'></form><br>";
  h += "<a href='/'>管理画面へ戻る</a></body></html>";
  sendHtml(h);
}

void handleSaveDeviceId()
{
  if (!netConfigured()) { redirectTo("/"); return; }
  if (!requireAuth()) return;
  String id = server.arg("deviceid");
  id.trim();
  if (id.length() == 0) { server.send(400, "text/plain; charset=utf-8", "機器IDを入力してください。"); return; }
  if (id.length() > 32) { server.send(400, "text/plain; charset=utf-8", "機器IDは32文字以内にしてください。"); return; }
  if (id.startsWith("-") || id.endsWith("-")) { server.send(400, "text/plain; charset=utf-8", "機器IDの先頭・末尾にハイフンは使用できません。"); return; }
  for (unsigned int i = 0; i < id.length(); i++)
  {
    char c = id.charAt(i);
    if (!((c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') || c == '-'))
    {
      server.send(400, "text/plain; charset=utf-8", "機器IDは半角英小文字・数字・ハイフンのみ使用できます。");
      return;
    }
  }
  cfgDevId = id;
  saveConfig();
  const String url = "http://" + mdnsHost() + ".local/";
  String h = pageHead("機器ID保存");
  h += "<h1>機器IDを保存しました</h1>";
  h += "<p><b>機器ID : " + htmlEscape(deviceId()) + "</b></p>";
  h += "<p>HPX-CAM は約2秒後に自動再起動します。</p>";
  h += "<p><b>新しい管理画面</b><br><a href='" + url + "'>" + url + "</a></p></body></html>";
  sendHtml(h);
  delay(2000);
  ESP.restart();
}

// ---- ファームウェア更新 (手で .bin を書き込む。HPX-Core と同じ) ----
bool upReady = false, upOk = false;
String upMsg;

void handleUpdatePage()
{
  if (!netConfigured()) { redirectTo("/"); return; }
  if (!requireAuth()) return;
  String h = pageHead("ファームウェア更新");
  h += "<h1>ファームウェア更新</h1>";
  h += "<p>現在のバージョン: " + String(FW_VERSION) + " (build " + String(FW_BUILD) + ")</p>";
  h += "<form method='POST' action='/update' enctype='multipart/form-data'>";
  h += "<input type='file' name='update' accept='.bin' required><br>";
  h += "<small>※ *.ino.bin ファイルのみ選択できます(bootloader・merged・partitions は不可)</small><br><br>";
  h += "<input type='submit' value='更新'></form><br>";
  // v1.08: 自動更新サーバーから今すぐ
  h += "<h2>自動更新サーバーから</h2>";
  h += "<p>自動更新の確認先: " + (cfgOta.length() == 0 ? otaOffReason() : htmlEscape(cfgOta)) + "</p>";
  h += "<form action='/otanow' method='POST'><button type='submit'>今すぐ更新を確認</button></form><br>";
  h += "<a href='/'>管理画面へ戻る</a></body></html>";
  sendHtml(h);
}

void handleUpdateUpload()
{
  HTTPUpload& up = server.upload();
  touch();
  if (up.status == UPLOAD_FILE_START)
  {
    upReady = false; upOk = false; upMsg = "";
    if (cfgAdmin.length() > 0 && !server.authenticate("admin", cfgAdmin.c_str())) { upMsg = "認証に失敗しました。"; return; }
    if (!up.filename.endsWith(".ino.bin")) { upMsg = "ファイル名が *.ino.bin ではありません。"; return; }
    camStop();
    upReady = Update.begin(UPDATE_SIZE_UNKNOWN);
    if (!upReady) upMsg = "更新を始められません。";
  }
  else if (up.status == UPLOAD_FILE_WRITE)
  {
    if (upReady && Update.write(up.buf, up.currentSize) != up.currentSize)
    {
      upMsg = "書き込みに失敗しました。";
      upReady = false;
      Update.abort();
    }
  }
  else if (up.status == UPLOAD_FILE_END)
  {
    if (upReady) upOk = Update.end(true);
    if (upReady && !upOk) upMsg = "書き込みを終えられませんでした。";
  }
  else if (up.status == UPLOAD_FILE_ABORTED)
  {
    if (upReady) Update.abort();
    upReady = false;
    upMsg = "受け取りが途中で止まりました。";
  }
}

void handleUpdateFinish()
{
  if (!requireAuth()) return;
  String h = pageHead("ファームウェア更新");
  if (upOk)
  {
    h += "<h1>更新しました</h1><p>HPX-CAM は約2秒後に自動再起動します。約30秒後に管理画面を開き直してください。</p></body></html>";
    sendHtml(h);
    delay(2000);
    ESP.restart();
    return;
  }
  h += "<h1>更新できませんでした</h1><p>" + htmlEscape(upMsg.length() ? upMsg : String("ファイルを確かめてください。")) + "</p>";
  h += "<p><a href='/update'>もう一度</a> ・ <a href='/'>管理画面へ戻る</a></p></body></html>";
  sendHtml(h);
}

// v1.08: 今すぐ更新を確認 (マニフェストを読み、新しい版があれば画面を返してから更新する)
void handleOtaCheckNow()
{
  if (!netConfigured()) { redirectTo("/"); return; }
  if (!requireAuth()) return;
  touch();
  String h = pageHead("更新の確認");
  h += "<h1>今すぐ更新を確認</h1>";
  int build = 0;
  String binUrl;
  const float vbat = readVbat();
  if (cfgOta.length() == 0)
  {
    h += "<p>確認できません: " + otaOffReason() + "</p>";
    h += "<p><a href='/settings'>動作設定へ</a></p>";
  }
  else if (otaNowPending)
  {
    h += "<p>いま更新しています。終わると自動で再起動します。</p>";
  }
  else if (apMode || WiFi.status() != WL_CONNECTED)
  {
    h += "<p>ルーターにつながっていない (設定用 Wi-Fi で開いている) ので、確認できません。</p>";
  }
  else if (vbat > 1.0f && vbat < OTA_MIN_VBAT)
  {
    h += "<p>電池が少ない (" + String(vbat, 2) + " V) ので、更新しません。電池を新しくするか、USB をつないでください。</p>";
  }
  else if (!otaManifest(build, binUrl))
  {
    otaSetResult("マニフェストを読めませんでした");
    h += "<p style='color:#c00'>マニフェストを読めませんでした。</p>";
    h += "<p>URL: " + htmlEscape(cfgOta) + "</p>";
    h += "<p>サーバーの firmware フォルダに manifest_cam.txt が置いてあるか、ファイルの1行目がビルド番号 (例: " + String(FW_BUILD) + ")、2行目が https:// で始まる .bin の URL になっているかを確かめてください。"
         "HPX-CAM のマニフェストは HPX-Core の manifest.txt とは別のファイルです。</p>";
  }
  else if (build <= FW_BUILD)
  {
    otaSetResult("最新です (サーバー " + String(build) + " / この機器 " + String(FW_BUILD) + ")");
    h += "<p>最新です。更新はありません。</p>";
    h += "<p>サーバーの版: " + String(build) + " ／ この機器: " + String(FW_BUILD) + " (" + String(FW_VERSION) + ")</p>";
  }
  else
  {
    h += "<p>新しい版 " + String(build) + " があります (この機器は " + String(FW_BUILD) + ")。</p>";
    h += "<p><b>いまからダウンロードして書き込み、自動で再起動します。</b>1〜2分かかります。そのあいだ電源を切らないでください。</p>";
    h += "<p>しばらくしてから管理画面を開き直して、バージョンを確かめてください (再起動のあと約10分開けます)。</p>";
    otaNowPending = true;
    otaNowBuild = build;
    otaNowUrl = binUrl;
  }
  h += "<p><a href='/'>管理画面へ戻る</a></p></body></html>";
  sendHtml(h);
}

void handleTest();

void handleSettingsSave()
{
  if (!netConfigured()) { redirectTo("/"); return; }
  if (!requireAuth()) return;
  String url = server.arg("url"), tok = server.arg("tok");
  url.trim(); tok.trim();
  if (!url.startsWith("http://") && !url.startsWith("https://")) { server.send(400, "text/plain; charset=utf-8", "送信先のURLは https:// から入れてください。"); return; }
  if (tok.length() == 0 && cfgToken.length() == 0) { server.send(400, "text/plain; charset=utf-8", "トークンを入力してください。"); return; }
  cfgUrl = url;
  if (tok.length()) cfgToken = tok;
  cfgName = server.arg("name");
  cfgName.trim();
  cfgSize = (uint8_t)constrain(server.arg("size").toInt(), 0, 3);
  cfgFlip = server.arg("flip") == "1";
  cfgMirror = server.arg("mir") == "1";
  cfgSend = server.arg("send") == "1" ? 1 : 0;
  cfgAutoOta = server.hasArg("ota");   // v1.09: URL の入力欄の代わり
  updateOtaUrl();
  rtcLastOta = 0;   // 次に撮影で起きたときに確かめる
  saveConfig();
  camStop();   // 次の試し撮りで新しい大きさ・向きにする
  // 保存したら、試しに写真を1枚送って確かめる
  handleTest();
}

void handleSnap()
{
  if (!requireAuth()) return;
  if (!camStart()) { server.send(500, "text/plain; charset=utf-8", "カメラを始められません。カメラの差し込みと PSRAM の設定を確かめてください。"); return; }
  camera_fb_t* fb = camShot(2);
  if (!fb) { server.send(500, "text/plain; charset=utf-8", "撮れませんでした。もう一度押してください。"); return; }
  server.sendHeader("Cache-Control", "no-store");
  server.send_P(200, "image/jpeg", (const char*)fb->buf, fb->len);
  esp_camera_fb_return(fb);
}

// 接続テスト: 設定の Wi-Fi につないで、試しに1枚送る
// (設定用の Wi-Fi はつないだ先と同じチャンネルに移るので、スマートフォンが一度切れることがある。
//  そのときはもう一度 HPX-Setup-xxxx につないで http://192.168.4.1/result を開く)
void runTrial()
{
  trialRunning = true;
  trialMsg = "";
  if (WiFi.status() != WL_CONNECTED) WiFi.begin(cfgSsid.c_str(), cfgPass.c_str());
  uint32_t t0 = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - t0 < WIFI_TIMEOUT_MS) { dns.processNextRequest(); server.handleClient(); delay(50); }
  if (WiFi.status() != WL_CONNECTED)
  {
    trialMsg = "<b style='color:#c00'>Wi-Fi「" + htmlEscape(cfgSsid) + "」につながりませんでした。</b><br>"
               "名前とパスワード、2.4GHz の Wi-Fi か (5GHz にはつなげません)、ルーターが近いかを確かめてください。";
    WiFi.disconnect(false);
    trialRunning = false;
    return;
  }
  timeSync();
  if (!camStart()) { trialMsg = "<b style='color:#c00'>カメラを始められません。</b>"; trialRunning = false; return; }
  camera_fb_t* fb = camShot(2);
  if (!fb) { trialMsg = "<b style='color:#c00'>撮れませんでした。</b>"; trialRunning = false; return; }
  String r;
  time_t t = timeValid() ? time(nullptr) : 0;
  int code = postPhoto(t, readVbat(), nullptr, fb->buf, fb->len, r);
  esp_camera_fb_return(fb);
  if (code == 200 && r.indexOf("\"ok\":true") >= 0)
  {
    applyReply(r);
    trialMsg = "<b style='color:#2e7d32'>送れました。</b> HPX-note のカメラのページに写真が出ているか確かめてください。<br>"
               "Wi-Fi の電波: " + String(WiFi.RSSI()) + " dBm (−80 より弱いと送れないことがあります)<br>"
               "撮影: " + String(curIv) + "分ごと、" + String(curH0) + "時〜" + String(curH1) + "時";
  }
  else
  {
    trialMsg = "<b style='color:#c00'>サーバーへ送れませんでした (結果 " + String(code) + ")。</b><br>";
    if (code == 401) trialMsg += "機器用トークンが違います。HPX-note のマイアカウントのトークンを入れ直してください。";
    else if (code == 404) trialMsg += "サーバーのアドレスが違うか、サーバーが古く cam_upload.php がありません。";
    else if (code == 403) trialMsg += "このアカウントにつなげるカメラの台数の上限です。";
    else if (code == 503) trialMsg += "サーバーにまだアカウントがありません。";
    else if (code < 0) trialMsg += "サーバーにつながりません。アドレスを確かめてください。";
    trialMsg += "<br><small>" + htmlEscape(r.substring(0, 200)) + "</small>";
  }
  trialRunning = false;
}

void handleTest()
{
  if (!requireAuth()) return;
  if (!configured()) { redirectTo("/settings"); return; }
  String h = pageHeadR("接続テスト", 5);
  h += "<h1>接続テスト中…</h1>"
       "<p>写真を1枚撮って、サーバーへ送れるか確かめています (20〜40秒)。この画面は自動で結果に変わります。</p>"
       "<p><small>画面が変わらないときは <a href='/result'>結果の画面</a> を開いてください。</small></p>"
       "<script>setTimeout(function(){location.href='/result'},5000)</script></body></html>";
  sendHtml(h);
  trialMsg = "";
  trialRunning = true;
}

void handleResult()
{
  touch();
  if (trialRunning || trialMsg.length() == 0)
  {
    String h = pageHeadR("接続テスト", 4);
    h += "<h1>接続テスト中…</h1><p>しばらくお待ちください。</p></body></html>";
    sendHtml(h);
    return;
  }
  String h = pageHead("接続テストの結果");
  h += "<h1>接続テストの結果</h1><p>" + trialMsg + "</p>";
  h += "<form action='/start' method='POST'><button class='go'>設定を終えて撮影を始める</button></form>";
  h += "<p><a href='/'>管理画面へ戻る</a></p></body></html>";
  sendHtml(h);
}

void handleStart()
{
  if (!requireAuth()) return;
  if (!configured()) { redirectTo("/"); return; }
  String h = pageHead("撮影を始めます");
  h += "<h1>撮影を始めます</h1><p>管理画面を閉じて、" + String(curIv) +
       "分ごとに撮影します。管理画面を開くときは、電源を入れなおしてください (約10分開けます)。</p></body></html>";
  sendHtml(h);
  delay(1500);
  camStop();
  WiFi.softAPdisconnect(true);
  // すぐ1枚撮って送る (届いたことを HPX-note で確かめられるように)
  rtcLastUpload = 0;
  WiFi.mode(WIFI_STA);
  startGuard();
  runCycle();
}

void handleCaptive()
{
  server.sendHeader("Location", apMode ? "http://192.168.4.1/" : "/", true);
  server.send(302, "text/plain", "");
}

void runSetup()
{
  WiFi.persistent(false);
  apMode = true;
  if (netConfigured())
  {
    // HPX-Core と同じく、ルーターにつないで、ルーター経由で管理画面を出す
    WiFi.mode(WIFI_STA);
    WiFi.setHostname(mdnsHost().c_str());
    if (wifiConnect(20000))
    {
      apMode = false;
      if (MDNS.begin(mdnsHost().c_str())) MDNS.addService("http", "tcp", 80);
      Serial.println("管理画面: http://" + WiFi.localIP().toString() + "/  http://" + mdnsHost() + ".local/");
      if (!timeValid()) timeSync();   // v1.08: NTP がだめならサーバーの時刻
    }
    else WiFi.disconnect(true);
  }
  if (apMode)
  {
    WiFi.mode(WIFI_AP_STA);
    String ssid = apName();
    WiFi.softAP(ssid.c_str(), AP_PASSWORD);
    Serial.println("設定用 Wi-Fi: " + ssid + " / " + AP_PASSWORD + " → http://192.168.4.1/");
    dns.start(53, "*", WiFi.softAPIP());
  }
  server.on("/", HTTP_GET, handleRoot);
  server.on("/net", HTTP_GET, handleNetPage);
  server.on("/save", HTTP_POST, handleNetSave);
  server.on("/nresult", HTTP_GET, handleNetResult);
  server.on("/nfinish", HTTP_POST, handleNetFinish);
  server.on("/settings", HTTP_GET, handleSettingsPage);
  server.on("/deviceid", HTTP_GET, handleDeviceIdPage);
  server.on("/saveid", HTTP_POST, handleSaveDeviceId);
  server.on("/update", HTTP_GET, handleUpdatePage);
  server.on("/update", HTTP_POST, handleUpdateFinish, handleUpdateUpload);
  server.on("/savesettings", HTTP_POST, handleSettingsSave);
  server.on("/snap", HTTP_GET, handleSnap);
  server.on("/test", HTTP_POST, handleTest);
  server.on("/result", HTTP_GET, handleResult);
  server.on("/start", HTTP_POST, handleStart);
  server.on("/otanow", HTTP_POST, handleOtaCheckNow);   // v1.08
  server.onNotFound(handleCaptive);
  server.begin();
  otaMarkValid();   // 管理画面を出せた = 新しいファームは動いている
  touch();
  bool trialPending = false;
  for (;;)
  {
    if (apMode) dns.processNextRequest();
    server.handleClient();
    netTrialLoop();
    // v1.08: 「今すぐ更新を確認」で新しい版があったとき (画面を返したあとで更新する)
    if (otaNowPending)
    {
      delay(500);   // 画面がブラウザに届くのを待つ
      camStop();
      if (WiFi.status() == WL_CONNECTED) otaApply(otaNowBuild, otaNowUrl);   // 成功すれば再起動して戻らない
      otaNowPending = false;
      touch();
    }
    if (trialRunning && trialMsg.length() == 0 && !trialPending)
    {
      trialPending = true;
      delay(300);   // 「テスト中」の画面を先に返し切る
      runTrial();
      trialPending = false;
    }
    if (apMode && WiFi.softAPgetStationNum() > 0) touch();
    uint32_t idle = millis() - lastTouch;
    if (configured() && netTrial != NT_CONNECTING && idle > SETUP_IDLE_MS_CONFIGURED)
    {
      Serial.println("設定モードを終えて撮影を始める");
      camStop();
      WiFi.softAPdisconnect(true);
      WiFi.mode(WIFI_STA);
      rtcLastUpload = 0;
      startGuard();
      runCycle();
    }
    if (!configured() && netTrial != NT_CONNECTING && idle > SETUP_IDLE_MS_NEW)
    {
      Serial.println("設定されないまま時間がたったので眠る");
      goSleep(SETUP_NEW_SLEEP_S);
    }
    delay(2);
  }
}

// 撮影モードで止まってしまったときの見張り (AWAKE_MAX_MS たっても眠っていなければ、むりやり眠る)
void guardFire(void*)
{
  esp_sleep_enable_timer_wakeup((uint64_t)curIv * 60ULL * 1000000ULL);
  esp_deep_sleep_start();
}

void startGuard()
{
  esp_timer_create_args_t a = {};
  a.callback = guardFire;
  a.name = "guard";
  if (!guardTimer && esp_timer_create(&a, &guardTimer) != ESP_OK) { guardTimer = nullptr; return; }
  esp_timer_stop(guardTimer);
  esp_timer_start_once(guardTimer, (uint64_t)AWAKE_MAX_MS * 1000ULL);
}

// 電源を入れなおし、3秒以内に BOOT ボタンを5秒長押しすると、設定を消して初めの状態に戻す
void checkFactoryReset()
{
  pinMode(PIN_BOOT, INPUT_PULLUP);
  Serial.println("初めの状態に戻すときは、今から3秒以内に BOOT ボタンを5秒長押し");
  uint32_t t0 = millis();
  while (millis() - t0 < 3000)
  {
    if (digitalRead(PIN_BOOT) == LOW)
    {
      uint32_t p0 = millis();
      while (digitalRead(PIN_BOOT) == LOW && millis() - p0 < 5000) delay(20);
      if (millis() - p0 >= 5000)
      {
        Serial.println("設定を消して初めの状態に戻します");
        prefs.begin("hpxcam", false);
        prefs.clear();
        prefs.end();
        delay(500);
        ESP.restart();
      }
      return;   // 5秒より短く離したときは何もしない
    }
    delay(20);
  }
}

// ====================================================================

void setup()
{
  bootMs = millis();
  bootTempC = temperatureRead();   // いちばん初めに測る (Wi-Fi・カメラの熱が入る前)
  if (bootTempC < -40 || bootTempC > 125) bootTempC = NAN;
  Serial.begin(115200);
  delay(300);
  rtcBoot++;
  setenv("TZ", "JST-9", 1);
  tzset();
  camId = makeCamId();
  loadConfig();
  otaCheckState();
  esp_sleep_wakeup_cause_t wc = esp_sleep_get_wakeup_cause();
  if (wc != ESP_SLEEP_WAKEUP_TIMER) checkFactoryReset();
  Serial.printf("\n%s  ID %s  起きた理由 %d\n", FW_VERSION, camId.c_str(), (int)wc);

  if (wc == ESP_SLEEP_WAKEUP_TIMER && configured())
  {
    startGuard();
    WiFi.mode(WIFI_STA);
    runCycle();   // 中で眠る
  }
  else if (wc == ESP_SLEEP_WAKEUP_TIMER && !configured())
  {
    // 設定前に眠った → また設定モード
  }
  sdStart();
  runSetup();     // 中で撮影モードへ移るか眠る
}

void loop()
{
  // ここには来ない。来たときは止まらないように眠る
  if (millis() - bootMs > AWAKE_MAX_MS) goSleep((uint32_t)curIv * 60);
  delay(1000);
}
