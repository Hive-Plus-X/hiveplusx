// ====================================================================
// HPX_CAM_v1_01.ino   Hive Plus X  HPX-CAM (巣箱・巣門を撮るカメラ)
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
//   1. 電池を入れたとき (または RESET ボタンを押したとき) は「設定モード」になり、
//      Wi-Fi「HPX-Setup-xxxx」(パスワード 12345678、HPX-Core と同じ) を出す。スマートフォンでつないで http://192.168.4.1/ を開くと
//      設定の画面が出る (Wi-Fi・サーバーのアドレス・機器用トークン・写真の大きさなど、試し撮り、接続テスト)。
//      設定が済んでいれば、だれもつながないまま 3分たつと撮影を始める (設定前は 10分たつと眠って待つ)。
//   2. 撮影のたびに目を覚まし、写真を撮って microSD にためる → Wi-Fi でサーバー (cam_upload.php) へ送る → 眠る。
//      送れなかった写真は microSD に残し、次に起きたときに送り直す (新しい写真から順に)。
//      送れた写真は microSD の日付のフォルダへ移して残す (いっぱいに近くなったら古い日から消す)。
//   3. 撮影の間隔 (5・10・15・30・60分) と撮影する時間帯 (例 5時〜19時) は、HPX-note のカメラのページで決め、
//      サーバーの返事でカメラに届く (次の撮影から使う)。時間帯の外は、次の時間帯の始めまで眠る。
//      時計もサーバーの返事 (と NTP) で合わせる。時刻は日本時間。
//
// サーバーへの送り方 (server/cam_upload.php に合わせた):
//   POST <サーバー>/cam_upload.php?id=<カメラのID 12けた16進>&t=<撮影時刻 epoch秒>&bv=<電池V>&rssi=<dBm>&name=<名前>
//   Authorization: Bearer <機器用トークン> (HPX-Core と同じ。HPX-note のマイアカウントに出る)
//   Content-Type: image/jpeg、本文は JPEG そのもの
//   返事: {"ok":true,"saved":1,"iv":10,"h0":5,"h1":19,"now":...}
//
// v1.01 (2026-11-22 TARO: Wi-Fi の設定の手順を HPX-Core とそろえる)
//   * 設定用 Wi-Fi の名前を HPX-Core と同じ「HPX-Setup-xxxx」にした (v1.00 は HPX-CAM-xxxx)
//   * 「保存」を押したら、HPX-Core と同じく、すぐ試しにルーターへつなぐ (そのまま写真も1枚送って確かめる)。
//     つながらなかったときは、その場で入れ直せる
//   * HPX-Core と同じく、RESET ボタンを押して離し、3秒以内に BOOT ボタンを5秒長押しすると、設定が消えて初めの状態に戻る
//   * 送り先 (サーバーのアドレス・トークン) も同じ画面で入れるのは v1.00 のまま
//     (HPX-CAM はほとんど眠っているので、HPX-Core のようにルーター経由で開く管理画面は持たない)
//
// v1.00 (2026-11-22 TARO 依頼: HPX-CAM のファームウェアを作る)
//   * 初めての版。実機ではまだ確かめていない (クラウドでコンパイルだけ確認)
//   * LTE-M の蜂場で HPX-Core に中継してもらう送り方 (&core=) はまだ無い (HPX-Core 側の中継もまだ無いため)
// ====================================================================

#include <Arduino.h>
#include <WiFi.h>
#include <WebServer.h>
#include <DNSServer.h>
#include <Preferences.h>
#include <HTTPClient.h>
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

static const char* FW_VERSION = "HPX-CAM.1.01";

// ---- 設定モードの Wi-Fi ----
static const char* AP_PASSWORD = "12345678";          // HPX-Core と同じ初期パスワード
static const uint32_t SETUP_IDLE_MS_CONFIGURED = 3UL * 60 * 1000;   // 設定済み: 3分だれも触らなければ撮影を始める
static const uint32_t SETUP_IDLE_MS_NEW = 10UL * 60 * 1000;         // 設定前: 10分で眠る (電池を守る)
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
String cfgSsid, cfgPass, cfgUrl, cfgToken, cfgName;
uint8_t cfgSize = 0;      // 0=SVGA 800×600, 1=XGA 1024×768, 2=UXGA 1600×1200, 3=QXGA 2048×1536
bool cfgFlip = false;     // 上下・左右を逆に (カメラを逆さに付けたとき)
uint8_t cfgSend = 0;      // 0=撮るたびに送る, 1=1時間に1回まとめて送る
int curIv = IV_DEFAULT, curH0 = H0_DEFAULT, curH1 = H1_DEFAULT;   // サーバーから届いた値 (NVS にも保存)

// ---- 眠っている間も消えない値 (RTC メモリ) ----
RTC_DATA_ATTR uint32_t rtcBoot = 0;
RTC_DATA_ATTR time_t rtcLastUpload = 0;
RTC_DATA_ATTR char rtcSdCheckDay[9] = "";
RTC_DATA_ATTR int8_t rtcLastRssi = 0;
RTC_DATA_ATTR int16_t rtcLastHttp = 0;

String camId;             // 12けた16進 (Wi-Fi の MAC アドレス)
bool sdOk = false;
bool camOk = false;
uint32_t bootMs = 0;
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
  cfgSize = prefs.getUChar("size", 0);
  cfgFlip = prefs.getBool("flip", false);
  cfgSend = prefs.getUChar("send", 0);
  curIv = prefs.getInt("iv", IV_DEFAULT);
  curH0 = prefs.getInt("h0", H0_DEFAULT);
  curH1 = prefs.getInt("h1", H1_DEFAULT);
  prefs.end();
  if (cfgSize > 3) cfgSize = 0;
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
  prefs.putUChar("size", cfgSize);
  prefs.putBool("flip", cfgFlip);
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
  sensor_t* s = esp_camera_sensor_get();
  if (s)
  {
    framesize_t want = frameSize(cfgSize);
    if (s->id.PID == OV2640_PID && want == FRAMESIZE_QXGA) want = FRAMESIZE_UXGA;
    s->set_framesize(s, want);
    s->set_vflip(s, cfgFlip ? 1 : 0);
    s->set_hmirror(s, cfgFlip ? 1 : 0);
    Serial.printf("カメラ PID=0x%04x\n", s->id.PID);
  }
  camOk = true;
  return true;
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

// 写真1枚を送る。from は Stream (microSD のファイル) か、メモリの JPEG。成功したら HTTP 200 で ok:true
int postPhoto(time_t t, float vbat, Stream* from, const uint8_t* buf, size_t len, String& reply)
{
  String url = uploadUrl() + "?id=" + camId + "&t=" + String((uint32_t)t) + "&bv=" + String(vbat, 2) +
               "&rssi=" + String((int)WiFi.RSSI());
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

void runCycle()
{
  float vbat = readVbat();
  Serial.printf("電池 %.2fV\n", vbat);
  if (vbat > 1.0f && vbat < VBAT_LOW)
  {
    Serial.println("電池が少ないので撮らずに眠る");
    goSleep(LOW_BAT_SLEEP_S);
  }

  // 時計がまだ合っていなければ、先に Wi-Fi でつないで合わせる
  if (!timeValid())
  {
    if (wifiConnect(WIFI_TIMEOUT_MS)) ntpSync();
    if (!timeValid()) goSleep((uint32_t)curIv * 60);
  }
  time_t now = time(nullptr);

  // 時間帯の外で起きた (長い眠りの途中) → 時計を合わせ直す必要はないのでまた眠る
  if (!inHours(now + 90)) sleepUntilNext();

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
    if (!timeValid() || rtcBoot % 36 == 0) ntpSync();   // サーバーの時計が届かないときの予備
  }
  if (fb) esp_camera_fb_return(fb);
  camStop();
  if (sdOk) sdTidy();
  sleepUntilNext();
}

// ====================================================================
// 設定モード (電池を入れた・RESET を押したとき)
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

void handleRoot()
{
  touch();
  String h = pageHead("HPX-CAM の設定");
  h += "<h1>HPX-CAM の設定</h1>";
  h += "<p>カメラのID: <b>" + camId + "</b><br>ファーム: " + String(FW_VERSION) + "<br>";
  float v = readVbat();
  h += "電池: " + (v > 1.0f ? String(v, 2) + " V" : String("測れません (USB だけのとき)")) + "<br>";
  if (sdOk)
  {
    h += "microSD: " + String((uint32_t)(SD.cardSize() / (1024ULL * 1024ULL))) + " MB、送る前の写真 " +
         String(countQueue()) + " 枚<br>";
  }
  else h += "<span style='color:#c00'>microSD: 読めません (無しでも動きますが、送れなかった写真は残りません)</span><br>";
  h += "撮影: " + String(curIv) + "分ごと、" + String(curH0) + "時〜" + String(curH1) + "時 (HPX-note のカメラのページで変えられます)";
  if (rtcLastHttp != 0) h += "<br>前の送信の結果: " + String(rtcLastHttp);
  h += "</p>";
  if (!configured()) h += "<p style='color:#c00'><b>まだ設定されていません。下の欄を入れて保存してください。</b></p>";

  h += "<p><a class='go sub' href='/snap' target='_blank'>試し撮り (向きとピントを見る)</a></p>";

  h += "<form action='/save' method='POST'>";
  h += "<label>Wi-Fi の名前 (SSID、2.4GHz)</label><br><input type='text' name='ssid' maxlength='32' value='" +
       htmlEscape(cfgSsid) + "'><br><br>";
  h += "<label>Wi-Fi のパスワード</label><br><input type='password' name='pass' maxlength='63'><br>";
  if (cfgPass.length()) h += "<small>変えないときは空のまま</small><br>";
  h += "<br><label>サーバーのアドレス</label><br><input type='text' name='url' maxlength='200' value='" +
       htmlEscape(cfgUrl) + "' placeholder='https://example.com/hpx/'><br>"
       "<small>HPX-note のアドレス (例 https://hiveplusx.com/hpx/)。HPX-Core の送信先 (…/receive.php) をそのまま入れてもかまいません</small><br><br>";
  h += "<label>機器用トークン</label><br><input type='password' name='tok' maxlength='100'><br>"
       "<small>HPX-note のマイアカウントに出る、HPX-Core と同じトークン。";
  if (cfgToken.length()) h += "変えないときは空のまま";
  h += "</small><br><br>";
  h += "<label>カメラの名前 (初めの1回だけ HPX-note に送ります。あとは HPX-note で変えます)</label><br>"
       "<input type='text' name='name' maxlength='30' value='" + htmlEscape(cfgName) + "'><br><br>";
  h += "<label>写真の大きさ</label><br><select name='size'>";
  for (uint8_t i = 0; i < 4; i++)
    h += "<option value='" + String(i) + "'" + (cfgSize == i ? " selected" : "") + ">" + sizeName(i) + "</option>";
  h += "</select><br><small>大きいほど電池と通信量が増えます。ふつうは SVGA で十分です</small><br><br>";
  h += "<label><input type='checkbox' name='flip' value='1'" + String(cfgFlip ? " checked" : "") +
       "> 上下・左右を逆にする (カメラを逆さに付けたとき)</label><br><br>";
  h += "<label>送り方</label><br>";
  h += "<label><input type='radio' name='send' value='0'" + String(cfgSend == 0 ? " checked" : "") +
       "> 撮るたびに送る (おすすめ。変化のお知らせが早い)</label><br>";
  h += "<label><input type='radio' name='send' value='1'" + String(cfgSend == 1 ? " checked" : "") +
       "> 1時間に1回まとめて送る (電池が長もち。お知らせは最大1時間遅れ)</label><br>";
  h += "<input type='submit' value='保存'></form>";

  if (configured())
  {
    h += "<form action='/test' method='POST'><button class='go sub'>接続テスト (試しに1枚送る)</button></form>";
    h += "<form action='/start' method='POST'><button class='go'>設定を終えて撮影を始める</button></form>";
    h += "<p><small>このまま 3分さわらなければ、自動で撮影を始めます。</small></p>";
  }
  h += "</body></html>";
  sendHtml(h);
}

void handleTest();

void handleSave()
{
  touch();
  String ssid = server.arg("ssid"), pass = server.arg("pass"), url = server.arg("url"), tok = server.arg("tok");
  ssid.trim(); url.trim(); tok.trim();
  if (ssid.length() == 0 || ssid.length() > 32) { server.send(400, "text/plain; charset=utf-8", "Wi-Fi の名前を入れてください。"); return; }
  if (pass.length() && (pass.length() < 8 || pass.length() > 63)) { server.send(400, "text/plain; charset=utf-8", "Wi-Fi のパスワードは8〜63文字です。"); return; }
  if (!url.startsWith("http://") && !url.startsWith("https://")) { server.send(400, "text/plain; charset=utf-8", "サーバーのアドレスは https:// から入れてください。"); return; }
  if (tok.length() == 0 && cfgToken.length() == 0) { server.send(400, "text/plain; charset=utf-8", "機器用トークンを入れてください。"); return; }
  if (ssid != cfgSsid || pass.length()) cfgPass = pass;
  cfgSsid = ssid;
  cfgUrl = url;
  if (tok.length()) cfgToken = tok;
  cfgName = server.arg("name");
  cfgName.trim();
  cfgSize = (uint8_t)constrain(server.arg("size").toInt(), 0, 3);
  cfgFlip = server.arg("flip") == "1";
  cfgSend = server.arg("send") == "1" ? 1 : 0;
  saveConfig();
  camStop();   // 次の試し撮りで新しい大きさ・向きにする
  // HPX-Core と同じく、保存したらすぐ試しにつなぐ (写真も1枚送って確かめる)
  handleTest();
}

void handleSnap()
{
  touch();
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
  WiFi.begin(cfgSsid.c_str(), cfgPass.c_str());
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
  ntpSync();
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
  touch();
  String h = pageHeadR("接続テスト", 5);
  h += "<h1>Wi-Fi「" + htmlEscape(cfgSsid) + "」につないでいます…</h1>"
       "<p>設定を保存しました。つながるか、写真を1枚サーバーへ送って確かめています (20〜40秒)。この画面は自動で結果に変わります。</p>"
       "<p><small>途中でスマートフォンの Wi-Fi が切れたら、もう一度 HPX-Setup-xxxx につないで "
       "<a href='/result'>http://192.168.4.1/result</a> を開いてください。</small></p>"
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
  h += "<p><a href='/'>設定の画面へ戻る</a></p></body></html>";
  sendHtml(h);
}

void handleStart()
{
  String h = pageHead("撮影を始めます");
  h += "<h1>撮影を始めます</h1><p>設定用の Wi-Fi を止めて、" + String(curIv) +
       "分ごとに撮影します。設定を変えるときは、電池を入れ直すか RESET ボタンを押してください。</p></body></html>";
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
  server.sendHeader("Location", "http://192.168.4.1/", true);
  server.send(302, "text/plain", "");
}

void runSetup()
{
  WiFi.persistent(false);
  WiFi.mode(WIFI_AP_STA);
  String ssid = "HPX-Setup-" + camId.substring(8);
  WiFi.softAP(ssid.c_str(), AP_PASSWORD);
  Serial.println("設定用 Wi-Fi: " + ssid + " / " + AP_PASSWORD + " → http://192.168.4.1/");
  dns.start(53, "*", WiFi.softAPIP());
  server.on("/", HTTP_GET, handleRoot);
  server.on("/save", HTTP_POST, handleSave);
  server.on("/snap", HTTP_GET, handleSnap);
  server.on("/test", HTTP_POST, handleTest);
  server.on("/result", HTTP_GET, handleResult);
  server.on("/start", HTTP_POST, handleStart);
  server.onNotFound(handleCaptive);
  server.begin();
  touch();
  bool trialPending = false;
  for (;;)
  {
    dns.processNextRequest();
    server.handleClient();
    if (trialRunning && trialMsg.length() == 0 && !trialPending)
    {
      trialPending = true;
      delay(300);   // 「テスト中」の画面を先に返し切る
      runTrial();
      trialPending = false;
    }
    if (WiFi.softAPgetStationNum() > 0) touch();
    uint32_t idle = millis() - lastTouch;
    if (configured() && idle > SETUP_IDLE_MS_CONFIGURED)
    {
      Serial.println("設定モードを終えて撮影を始める");
      camStop();
      WiFi.softAPdisconnect(true);
      WiFi.mode(WIFI_STA);
      rtcLastUpload = 0;
      startGuard();
      runCycle();
    }
    if (!configured() && idle > SETUP_IDLE_MS_NEW)
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
  esp_timer_handle_t h;
  if (esp_timer_create(&a, &h) == ESP_OK) esp_timer_start_once(h, (uint64_t)AWAKE_MAX_MS * 1000ULL);
}

// RESET を押して離し、3秒以内に BOOT ボタンを5秒長押しすると、設定を消して初めの状態に戻す (HPX-Core と同じ)
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
  Serial.begin(115200);
  delay(300);
  rtcBoot++;
  setenv("TZ", "JST-9", 1);
  tzset();
  camId = makeCamId();
  loadConfig();
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
