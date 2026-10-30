// ====================================================================
// HPX_Core_v1_16.ino   Hive Plus X  (ESP32 養蜂IoT コア)
//
// 対象   : Freenove ESP32 (ESP32-WROOM-32E, 4MB) / ボード "ESP32 Dev Module"
//          arduino-esp32 3.3.x / Partition "Minimal SPIFFS (1.9MB APP with OTA/128KB SPIFFS)"
//
// v1.16 の変更
//   * 温湿度センサ HPX-TH(Seeed XIAO nRF52840 + SHT40 + サーミスター。広告の名前 "HPXT")のデータも受け取れるようにした。
//       - 温湿度計と同じ「温湿度計の登録」画面で、周辺検索 → 名前を付けて登録する([温湿度センサ HPX-TH] と表示される)
//       - 広告(メーカーデータ16バイト): [0..1]0xFFFF [2]'T' [3]版(1) [4..5]通し番号 [6..7]SHT40の温度×100(int16)
//           [8..9]湿度×100(uint16) [10..11]サーミスターの温度×100(int16) [12..13]ノード内温度×100 [14..15]電池 mV
//           (温度は 0x7FFF、湿度は 0xFFFF のとき「測れなかった」)
//       - サーバーへは温度 "t"・湿度 "h" に加えて、サーミスターの温度 "t2" と電池電圧 "bv" を送る(receive.php の対応が必要)
//   * 記録の大きさ(20バイト)は変わらないので、v1.15で溜めた記録はそのまま引き継ぐ。
//
// v1.15 の変更
//   * SwitchBot の温湿度計(防水温湿度計・温湿度計・温湿度計プラス)のデータも受け取れるようにした。
//       - Inkbird と同じ「温湿度計の登録」画面で、周辺検索 → 名前を付けて登録する([SwitchBot 防水温湿度計] などと表示される)
//       - 記録は Inkbird と同じ「温度・湿度・電池%」なので、サーバーは変更不要。温度は0.1℃、湿度は1%刻み
//       - 広告の読み方(SwitchBot公式のBLE仕様 OpenWonderLabs/SwitchBotAPI-BLE と pySwitchbot に合わせた):
//           メーカーデータ(会社ID 0x0969): [2..7]MAC [10]bit3:0=温度の小数 [11]bit7=1で0℃以上・bit6:0=温度の整数 [12]bit6:0=湿度
//           サービスデータ(UUID 0xFD3D、旧 0x0D00。スキャン応答): [0]bit6:0=機種('w'防水 'T'温湿度計 'i'プラス) [2]bit6:0=電池%
//                                              温湿度計・プラスは [3..5] にも同じ形で温湿度が入っている
//       - SwitchBot アプリでファームウェアを新しくしておくこと(古い版は会社IDやUUIDが違い、防水温湿度計は読めないことがある)
//
// v1.14 の変更
//   * 管理画面に「重量センサの較正」を追加した。パソコンをつながずに、ここで較正できる。
//       - 重量センサ(HPX-WeightNode v1.01 以降)は、電池を入れてから10分間だけ2秒ごとに測る。その間にこの画面で
//         「ゼロ点を取る」(何も載せない)→ 重りを載せて重さ(g)を入れて「較正する」を押す。押すと約10秒(WCAL_COLLECT_MS)
//         値を集めて平均する
//       - 重りを使わず、全台共通の「1kgあたりの値」とゼロ点だけで較正することもできる(共通の値は較正済みの平均から作れる)
//       - 較正の結果は本体(NVS)に保存し、次の送信でサーバーへ "wcal" として送る(サーバーが kg に換算する)。
//         保存した直後にも1回送信する。送れるまで毎回送り直す
//
// v1.13 の変更
//   * 重量ノード→重量センサ
// v1.12 の変更
//   * HPX-A(Seeed XIAO nRF52840 + 光センサ。広告の名前 "HPX-alert")のデータも受け取れるようにした。
//       - StaFIX フェンスアラートの LED の点滅(電柵の電圧低下)をノードが数え、状態を広告で出している
//       - 温湿度計・重量センサと同じ「温湿度計の登録」画面で、周辺検索 → 名前を付けて登録する([電柵アラート] と表示される)
//       - 記録の間隔は温湿度計と共通(15分にすると、電圧低下は最大15分ほどでサーバーに届く)
//       - サーバーへは sensors.ble の記録に "al"(状態) "ev"(電圧低下の回数) "bl"(直近の点滅回数) "ago"(最後の点滅からの秒数) を付けて送る。
//         メールでの通知はサーバー側(hpx_alert.php)で行う
//   * 記録の大きさ(20バイト)は変わらないので、v1.11で溜めた記録はそのまま引き継ぐ。
//
// v1.11 の変更
//   * 巣箱の重量センサ(Seeed XIAO nRF52840 + HX711 + ロードセル。広告の名前 "HPXW")のデータも受け取れるようにした。
//       - 温湿度計と同じ「温湿度計の登録」画面で、周辺検索 → 名前を付けて登録する(重量センサは [重量センサ] と表示される)
//       - 重量は「生の値(HX711のカウント)」のまま記録・送信する。kgへの換算(ゼロ点・校正・温度補正)はサーバーの閲覧画面で行う
//       - 重量センサの記録: 温度=ノード内部の温度、ばらつき=読み取りの最大-最小、電池電圧=ノードの電池
//   * 登録できる台数を 4 → 8 台に増やした。
//   * 記録を 16 → 20 バイトにした(生の値の分)。保存ファイルが新しくなるため、v1.10で溜めていた未送信の記録は破棄される。
//
// v1.10 の変更
//   * 温湿度計を1台の機器につき最大4台まで登録できるようにした(名前は全角12文字まで)。
//       - 管理画面「温湿度計の登録」で、周辺を探して(温度とRSSIが見える)名前を付けて登録する
//       - 1回の受信で登録した全台を取る(全台そろえば早く終わる。最長 BLE_SCAN_MS)。全台とも同じ時刻(区切り)で記録する
//       - 保存間隔は全台共通(15分/30分/1時間)
//   * 記録の保存先を NVS から LittleFS(「Minimal SPIFFS」の128KB領域)に変えた。
//       - 3日分(BLE_KEEP_DAYS)を残し、通信が途切れていても溜めておいて、つながったときにまとめて送る
//       - サーバーへは BLE_UPLOAD_CHUNK 件ずつ分けて送る(送れた分から「送信済み」にする)
//       - 旧版(v1.09以前)のNVSの記録は引き継がない(破棄する)。旧版で温湿度計を1台指定していた場合は、1台目として自動登録する
//   * Wi-Fiにつながらない起動でも、保存間隔になっていれば温湿度計の記録は取る(オフラインのまま3日分たまる)
//   * 初めて動かすときは、LittleFSの初期化(数秒)が一度だけ走る
//
// v1.09 の変更
//   * BLEセンサの記録を、保存間隔に合わせた「時計の区切り」(30分なら毎時00分・30分、15分なら00・15・30・45分、
//     1時間なら毎時00分)で取るようにした。起動した時刻からの間隔ではなくなる。
//       - ディープスリープは、次の区切りの時刻に起きる(眠る秒数を「次の区切りまで」で計算する)
//       - 記録の時刻は区切りの時刻(例 10:30:00)にそろえる(区切りから5分以内に取れたとき)
//       - 時計のずれを抑えるため、保存の起床ごとにNTPで時刻を合わせる
//       - 電源投入・RESET直後の記録(動作確認用)だけは、その時刻のまま1件保存する
//   * 詳しくは BLE_ALIGN_TO_CLOCK / BLE_SAVE_ON_BOOT / BLE_SNAP_LATE_S の説明を参照
//
// v1.08 の変更
// Wi-Fiの初期パスワードを全機共通の12345678に変更
//
// v1.06 の変更
//   * BLE記録の保存件数を 10 → 100 件に増やした(BLE_LOG_MAX / BLE_LOG_CAP)。
//   * 記録を扱う配列(1.6KB)を、スタックではなくヒープに取り、std::unique_ptr で自動的に解放するようにした。
//     メモリが足りないときはリセットせずに、その処理だけをやめる。
//
// v1.05 の変更
//   * BLEの実装を、Arduino標準のBLEライブラリ(Bluedroid)から NimBLE-Arduino に変更した(プログラムサイズを大きく減らすため)。
//     ★ライブラリマネージャーで「NimBLE-Arduino」(h2zero 作、バージョン 2.x)をインストールしてからコンパイルすること。
//     機能は v1.04 と同じ。
//
// v1.04 の追加機能
//   * 保存したBLE温湿度計の記録のうち、まだ送っていないものを、定期レポートのJSON(sensors.ble)に載せてサーバーへ送る。
//     送信が成功(HTTP 2xx)した記録は「送信済み」として次回から送らない。失敗したら次回に再送する(サーバー側で重複を除く)。
//
// v1.03 の追加機能
//   * BLE温湿度計(Inkbird IBS-TH2 / IBS-TH2 PLUS)の広告データを受信し、フラッシュ(NVS)に保存する
//       保存間隔 15分 / 30分 / 1時間 を管理画面「動作設定」で選ぶ。ペアリング・接続は不要(広告を聞くだけ)
//       保存は最新 BLE_LOG_MAX 件(初期値10件)。超えたら古いものから消す
//       保存内容は管理画面の「BLE温湿度計の記録」で表示・CSV取得できる
//
// v1.01 からの主な変更
//   * 動作モードを2つ持つ(管理画面の「設定」で切替)
//       常時稼働(商用電源)       : 管理画面を常時公開し、一定間隔で計測・送信する
//       ディープスリープ(電池)   : 通常は「計測 → 送信 → スリープ」だけを繰り返す。
//                                  管理画面は保守モード(RESETボタン後の約10分間)だけ公開する
//   * 設定用APを機器ごとのSSID/パスワードにし、キャプティブポータル化
//   * 管理画面にBASIC認証(ユーザー名 admin)。初期設定で管理パスワードを決める
//   * 停電復帰などでWi-Fiに繋がらなくても、設定用APから自動で元の動作に戻る
//   * OTAロールバック(新ファームが正常と確認できなければ前のバージョンに戻る)
//   * ウォッチドッグ、連続クラッシュ時のスリープ
//   * 計測データ(ハートビート)のHTTPS送信、HTTPS経由の遠隔ファーム更新(OTA)
//   * 機器IDの初期値を MAC 由来にし、固有ID(MAC)をデータに含める
//
// 保守のしかた(ディープスリープ中の機器)
//   RESET(EN)ボタンを押す → 保守モードで起動し、管理画面(http://<機器ID>.local/)が約10分間使える。
//   画面で「保守を終了してスリープ」を押すか、操作が10分なければ自動でスリープに戻る。
//   工場出荷リセット: RESETを押して離し、3秒以内にBOOTボタンを5秒間長押し。
//
// 遠隔更新(HTTPS OTA)の手順
//   1. FW_BUILD を +1、FW_VERSION を更新する
//   2. [スケッチ]>[コンパイル済みバイナリをエクスポート] で *.ino.bin を作る
//   3. その .bin を HTTPS で取得できる場所に置く
//   4. マニフェスト(2行のテキスト)を更新する
//        1行目: ビルド番号(整数)   2行目: .bin の URL(https)
//   5. 管理画面「設定」にマニフェストのURLを入れておく。端末は電池なら1日1回、商用電源なら6時間ごとに確認する
// ====================================================================

#include <WiFi.h>
#include <WebServer.h>
#include <DNSServer.h>
#include <Preferences.h>
#include <Update.h>
#include <ESPmDNS.h>
#include <HTTPClient.h>
#include <HTTPUpdate.h>
#include <WiFiClientSecure.h>
#include <esp_ota_ops.h>
#include <esp_task_wdt.h>
#include <esp_sleep.h>
#include <esp_system.h>
#include <esp_sntp.h>
#include <NimBLEDevice.h>   // ライブラリ「NimBLE-Arduino」(2.x)
#include <LittleFS.h>        // 記録の保存先(パーティション「spiffs」)
#include <strings.h>
#include <time.h>
#include <memory>
#include <new>

// ====================================================================
// 0. 定数
// ====================================================================

// 変更時は必ず両方を更新すること(FW_BUILDは遠隔更新の新旧判定に使う)
#define FW_VERSION "HPX-Core.1.16"
#define FW_BUILD 116

constexpr uint8_t BOOT_BUTTON_PIN = 0;
constexpr uint8_t STATUS_LED = 2;
constexpr unsigned long FACTORY_RESET_HOLD_MS = 5000;
constexpr unsigned long FACTORY_RESET_WINDOW_MS = 3000;

constexpr uint8_t MODE_ALWAYS_ON = 0;   // 常時稼働(商用電源)
constexpr uint8_t MODE_DEEPSLEEP = 1;   // ディープスリープ(バッテリー)
constexpr uint16_t DEFAULT_INTERVAL_MIN = 15;

// Wi-Fi
constexpr uint32_t WIFI_CONNECT_TIMEOUT_MS = 20000;  // 保守・常時稼働での接続待ち
constexpr uint32_t WIFI_MEASURE_TIMEOUT_MS = 15000;  // スリープ復帰時の接続待ち
constexpr uint32_t WIFI_FAST_TIMEOUT_MS = 6000;      // 前回のチャンネル/BSSID指定での高速接続の待ち
constexpr uint32_t WIFI_DOWN_RESTART_MS = 30UL * 60 * 1000;  // 常時稼働で切れっぱなしなら再起動

// 設定用AP
constexpr uint32_t AP_FALLBACK_TIMEOUT_MS = 5UL * 60 * 1000;   // 元の設定がある状態でのAP(誰も来なければ戻る)
constexpr uint32_t AP_SETUP_TIMEOUT_MS = 30UL * 60 * 1000;     // 未設定でのAP(電池なら長時間スリープ)
constexpr uint32_t AP_SETUP_SLEEP_S = 6UL * 3600;

// 保守モード(スリープ機が管理画面を出している時間)
constexpr uint32_t MAINT_IDLE_MS = 10UL * 60 * 1000;

// 時刻
constexpr uint32_t NTP_WAIT_MS = 8000;
constexpr uint32_t NTP_RESYNC_S = 6UL * 3600;

// 遠隔OTA
constexpr uint32_t OTA_INTERVAL_BATT_S = 24UL * 3600;
constexpr uint32_t OTA_INTERVAL_MAINS_S = 6UL * 3600;
constexpr uint32_t OTA_MIN_UPTIME_MS = 10UL * 60 * 1000;  // 保守起動直後はOTAしない

// ロールバック判定
constexpr uint32_t VALIDATE_STABLE_MS = 30UL * 1000;
constexpr uint32_t VALIDATE_DEADLINE_MS = 5UL * 60 * 1000;

constexpr uint32_t WDT_TIMEOUT_MS = 60UL * 1000;

// BLEセンサ(Inkbird IBS-TH2 / TH2 PLUS)
constexpr uint8_t BLE_MAX_SENSORS = 8;            // 登録できる温湿度計・重量センサの台数(8まで。マスクが8ビット)(変えるときは BleRec/設定の保存キーも確認)
constexpr uint8_t BLE_NAME_MAX = 36;              // 名前の最大バイト数(UTF-8。全角12文字)
constexpr uint32_t BLE_KEEP_DAYS = 3;             // 記録を残す日数
constexpr uint32_t BLE_COMPACT_DAYS_X10 = 35;     // 一番古い記録がこの日数(×0.1日。35=3.5日)を超えたら古い分を整理する
constexpr uint32_t BLE_LOG_HARD_MAX = 2400;       // 記録の件数の上限(フラッシュ保護。16バイト×2400=約38KB)
constexpr uint16_t BLE_UPLOAD_CHUNK = 50;         // 1回のPOSTに載せる記録の件数
constexpr uint32_t BLE_UPLOAD_MAX_MS = 45000;     // 1回の起床で分割送信にかけてよい最長時間
constexpr uint32_t BLE_VIEW_MAX = 120;            // 管理画面の記録一覧に出す件数(新しい側)
constexpr uint32_t BLE_DISC_MS = 8000;            // 登録用の周辺検索の時間
constexpr uint8_t BLE_DISC_MAX = 12;              // 周辺検索で一覧にする最大台数
constexpr uint32_t BLE_SCAN_MS = 10000;           // 1回の受信待ち時間(見つかり次第すぐ終了)
constexpr uint32_t BLE_DUE_SLACK_S = 90;          // 起床時刻のぶれを吸収する余裕(区切りの何秒前に起きても、その区切りとみなす)
constexpr bool BLE_ALIGN_TO_CLOCK = true;         // true: 時計の区切り(毎時00分・30分など)で記録 / false: 起動からの間隔
constexpr uint32_t BLE_SNAP_LATE_S = 300;         // 区切りから何秒以内に取れた記録なら、時刻を区切りの時刻にそろえるか
constexpr bool BLE_SAVE_ON_BOOT = true;           // true: 電源投入・RESET直後にも1件保存する(動作確認用。時刻はその時のまま)
constexpr uint16_t BLE_DEFAULT_INTERVAL_MIN = 15; // 15 / 30 / 60 のいずれか
constexpr uint32_t WCAL_COLLECT_MS = 10000;       // 重量センサの較正で値を集める時間(重量センサは較正モードで2秒ごとに測る)
constexpr uint8_t WCAL_MIN_SAMPLES = 3;           // 較正に使うのに要る測定の回数(これ未満なら較正しない)
constexpr uint8_t WCAL_MAX_SAMPLES = 16;
constexpr int32_t WCAL_WARN_RANGE = 2000;         // 集めた値の最大-最小がこれを超えたら「揺れています」と出す(約50g)
constexpr uint32_t BLE_MIN_HEAP = 40000;          // BLE停止後の空きヒープがこれ未満なら(常時稼働のとき)再起動

// 設定用APのパスワード。nullptr なら機器ごとにランダムな8桁を自動生成して保存する。
// 全台共通の固定値にしたい場合は 8文字以上の文字列を入れる。
static const char* FIXED_AP_PASSWORD = "12345678";

// 電池電圧(ADC1のピンのみ使用可: 32〜39)。-1 なら電池電圧は測らない。
// 分圧抵抗の比(Vbat / ADC入力電圧)を BATT_DIV_RATIO に入れる。
constexpr int BATT_ADC_PIN = -1;
constexpr float BATT_DIV_RATIO = 2.0f;
constexpr float BATT_CRIT_V = 3.30f;     // これ以下は通信せず長時間スリープ(電池の種類に合わせる)
constexpr float BATT_OTA_MIN_V = 3.70f;  // これ未満ならOTAをしない
constexpr uint32_t BATT_CRIT_SLEEP_S = 6UL * 3600;

// TLSのルートCA(PEM)。定義すると証明書を検証する。未定義の間は検証しない(開発用)。
// 本番運用の前に必ず設定すること。
// #define SERVER_ROOT_CA R"(-----BEGIN CERTIFICATE-----
// ...
// -----END CERTIFICATE-----)"
// #define OTA_ROOT_CA R"(...)"
#ifdef SERVER_ROOT_CA
static const char* const kServerCa = SERVER_ROOT_CA;
#else
static const char* const kServerCa = nullptr;
#endif
#ifdef OTA_ROOT_CA
static const char* const kOtaCa = OTA_ROOT_CA;
#else
static const char* const kOtaCa = nullptr;
#endif

// ====================================================================
// 1. グローバル変数
// ====================================================================

WebServer server(80);
DNSServer dnsServer;
Preferences preferences;

// 設定(NVS "wifi-config")
String cfgSsid;
String cfgPass;
String deviceID;       // 利用者が決める名前(mDNS名にもなる)
String adminPass;      // 管理画面のパスワード(空=未設定)
String apPass;         // 設定用APのパスワード
String serverUrl;      // データ送信先
String serverToken;    // 送信時の Bearer トークン
String otaUrl;         // OTAマニフェストのURL
uint8_t powerMode = MODE_DEEPSLEEP;
uint16_t intervalMin = DEFAULT_INTERVAL_MIN;
bool bleEnabled = false;                           // BLEセンサの取得
uint16_t bleIntervalMin = BLE_DEFAULT_INTERVAL_MIN;

String deviceUid;      // MAC由来の固有ID(12桁・大文字)
String mdnsHost = "hpx";
bool mdnsStarted = false;

// 起動の種類
bool timerWake = false;
bool powerOnBoot = false;
bool maintenanceBoot = false;

// 実行状態
bool apMode = false;
bool apFallback = false;
uint32_t apLastBusyMs = 0;
uint32_t lastActivityMs = 0;
uint32_t nextCycleMs = 0;
bool sleepRequested = false;
bool pendingVerify = false;
uint32_t wifiDownSinceMs = 0;
uint32_t lastReconnectMs = 0;

bool otaReady = false;
bool otaSuccess = false;
volatile bool otaBusy = false;
String otaMessage;

String lastReportText = "未実行";
String bleStatusText = "未実行";

// ディープスリープをまたいで保持する値(RTCメモリ)
constexpr uint32_t RTC_MAGIC = 0x48505803;  // レイアウトを変えたら値を変える
RTC_DATA_ATTR uint32_t rtcMagic = 0;
RTC_DATA_ATTR uint32_t rtcBootCount = 0;
RTC_DATA_ATTR uint8_t rtcCrashCount = 0;
RTC_DATA_ATTR uint8_t rtcFailStreak = 0;
RTC_DATA_ATTR uint8_t rtcNtpFail = 0;
RTC_DATA_ATTR bool rtcChanValid = false;
RTC_DATA_ATTR uint8_t rtcChannel = 0;
RTC_DATA_ATTR uint8_t rtcBssid[6] = {0, 0, 0, 0, 0, 0};
RTC_DATA_ATTR int64_t rtcLastNtp = 0;
RTC_DATA_ATTR int64_t rtcLastOta = 0;
RTC_DATA_ATTR int64_t rtcLastBle = 0;   // 最後にBLEの記録を保存した時刻(UNIX秒)

// BLEセンサの記録の型(Arduino IDEの自動プロトタイプ生成より前に置く必要があるため、ここで定義する)
struct BleRec
{
  uint32_t seq;     // 通し番号
  uint32_t ts;      // UNIX時刻(0=時刻不明)
  int16_t t100;     // 温度 ×100 [℃]
  uint16_t h100;    // 湿度 ×100 [%]
  int8_t rssi;      // 受信強度 [dBm]
  uint8_t bat;      // 電池残量 [%]
  uint8_t flags;    // bit0:湿度あり bit1:電池あり
  uint8_t sid;      // 温湿度計の固有番号(登録時に付ける。消しても再利用しない)
  int32_t raw;      // 重量センサの生の値(HX711のカウント)。温湿度計では 0
};
static_assert(sizeof(BleRec) == 20, "BleRec size");


constexpr uint8_t BLE_F_HUM = 0x01;
constexpr uint8_t BLE_F_BAT = 0x02;
constexpr uint8_t BLE_F_WGT = 0x04;   // 重量センサの記録(t100=ノード内温度, h100=ばらつき, bat=(電池mV-2000)/10, raw=生の値)
constexpr uint8_t BLE_F_ALR = 0x08;   // 電柵アラートの記録(t100=ノード内温度, h100=最後の点滅からの秒数, bat=(電池mV-2000)/10,
                                      //   raw=状態 | 直近の点滅回数<<8 | 電圧低下の回数<<16)
constexpr uint8_t BLE_F_THX = 0x10;   // 温湿度センサ HPX-TH の記録(t100=SHT40の温度(BLE_NO_TEMP=測れず), h100=湿度,
                                      //   bat=(電池mV-2000)/10, raw=サーミスターの温度×100(BLE_NO_TEMP=測れず))
constexpr int16_t BLE_NO_TEMP = 0x7FFF;


struct BleSample
{
  int16_t t100;
  uint16_t h100;
  uint8_t bat;
  uint8_t flags;
  int rssi;
  int32_t raw;
  char mac[18];
};

// 登録した温湿度計(sid=0 は空き)
struct BleSensor
{
  uint8_t sid;
  char mac[18];     // "AA:BB:CC:DD:EE:FF"
  char name[40];    // UTF-8
};

// 周辺検索の結果1台分
struct BleDisc
{
  char mac[18];
  int8_t rssi;
  int16_t t100;
  uint16_t h100;
  uint8_t flags;
  uint8_t kind;     // 0=温湿度計(Inkbird) 1=重量センサ 2=電柵アラート 3=SwitchBot 温湿度計 4=SwitchBot 防水温湿度計 5=HPX-TH
  int32_t raw;
};

// 重量センサの較正(重量センサごとに NVS の "hpxwcal" に保存。キーは "m" + アドレスの12桁)
constexpr uint8_t WCAL_F_ZERO = 0x01;     // ゼロ点あり
constexpr uint8_t WCAL_F_SPAN = 0x02;     // 1kgあたりの値あり
constexpr uint8_t WCAL_F_PENDING = 0x04;  // サーバーへまだ送っていない
constexpr uint8_t WCAL_F_COMMON = 0x08;   // 1kgあたりの値は全台共通の値を使った
struct WCal
{
  int32_t zero;     // 何も載せないときの生の値
  int32_t calRaw;   // 重りを載せたときの生の値(共通の値のときは 0)
  uint32_t calG;    // 重りの重さ [g](共通の値のときは 0)
  float perKg;      // 1kgあたりの生の値
  uint32_t ts;      // 較正した時刻(UNIX秒。時刻不明なら 0)
  uint8_t flags;
  uint8_t pad[3];
};

BleSensor bleSens[BLE_MAX_SENSORS];
uint8_t bleSensNext = 1;   // 次に付ける sid

// 記録用のバッファはヒープに取り、使い終わると自動で解放する(確保できなければ nullptr)。
// Arduino IDEの自動プロトタイプ生成より前に型を置く必要があるため、ここで定義する。
typedef std::unique_ptr<BleRec[]> BleBuf;
static inline BleBuf bleAlloc(size_t n)
{
  return BleBuf(new (std::nothrow) BleRec[n]);
}

// OTAの正常確認は自分で行う(Arduinoコアの自動確定を止める)
extern "C" bool verifyRollbackLater() { return true; }

// 関数プロトタイプ
void handleSetupPage();
void handleSetupSave();
void handleCaptive();
void handleMainPage();
void handleSettingsPage();
void handleSettingsSave();
void handleDeviceIDPage();
void handleSaveDeviceID();
void handleUpdatePage();
void handleUpdateUpload();
void handleUpdateFinish();
void handleWiFiResetPage();
void handleWiFiReset();
void handleFactoryResetPage();
void handleFactoryReset();
void handleRunNow();
void handleSleepNow();
bool runCycle(bool allowOta);
void goToSleep(uint32_t sec);

// ====================================================================
// 2. ユーティリティ
// ====================================================================

const char* resetReasonStr()
{
  switch (esp_reset_reason())
  {
    case ESP_RST_POWERON:   return "poweron";
    case ESP_RST_EXT:       return "ext";
    case ESP_RST_SW:        return "sw";
    case ESP_RST_PANIC:     return "panic";
    case ESP_RST_INT_WDT:   return "int_wdt";
    case ESP_RST_TASK_WDT:  return "task_wdt";
    case ESP_RST_WDT:       return "wdt";
    case ESP_RST_DEEPSLEEP: return "deepsleep";
    case ESP_RST_BROWNOUT:  return "brownout";
    default:                return "other";
  }
}

String htmlEscape(const String& s)
{
  String o;
  o.reserve(s.length() + 8);
  for (unsigned int i = 0; i < s.length(); i++)
  {
    char c = s.charAt(i);
    switch (c)
    {
      case '&':  o += "&amp;";  break;
      case '<':  o += "&lt;";   break;
      case '>':  o += "&gt;";   break;
      case '"':  o += "&quot;"; break;
      case '\'': o += "&#39;";  break;
      default:   o += c;        break;
    }
  }
  return o;
}

bool timeValid()
{
  return time(nullptr) > 1700000000;  // 2023-11以降なら時刻設定済み
}

String nowText()
{
  if (!timeValid()) return "未同期";
  time_t t = time(nullptr);
  struct tm tmv;
  localtime_r(&t, &tmv);
  char buf[32];
  strftime(buf, sizeof(buf), "%Y-%m-%d %H:%M:%S", &tmv);
  return String(buf);
}

void initDeviceUid()
{
  uint64_t m = ESP.getEfuseMac();
  char buf[13];
  snprintf(buf, sizeof(buf), "%02X%02X%02X%02X%02X%02X",
           (uint8_t)(m), (uint8_t)(m >> 8), (uint8_t)(m >> 16),
           (uint8_t)(m >> 24), (uint8_t)(m >> 32), (uint8_t)(m >> 40));
  deviceUid = String(buf);
}

String defaultDeviceId()
{
  String tail = deviceUid.substring(6);
  tail.toLowerCase();
  return "hpx-" + tail;
}

// 機器IDからmDNSホスト名を作成
String makeMdnsHost(const String& source)
{
  String result = source;
  if (result.length() == 0 || result == "unset") return "hpx";
  if (result.length() > 63) result = result.substring(0, 63);
  return result;
}

void touch()
{
  lastActivityMs = millis();
}

void initRtc(bool force)
{
  if (force || rtcMagic != RTC_MAGIC)
  {
    rtcMagic = RTC_MAGIC;
    rtcBootCount = 0;
    rtcCrashCount = 0;
    rtcFailStreak = 0;
    rtcNtpFail = 0;
    rtcChanValid = false;
    rtcChannel = 0;
    memset(rtcBssid, 0, sizeof(rtcBssid));
    rtcLastNtp = 0;
    rtcLastOta = 0;
    rtcLastBle = 0;
  }
}

void setupWdt()
{
  esp_task_wdt_config_t cfg;
  memset(&cfg, 0, sizeof(cfg));
  cfg.timeout_ms = WDT_TIMEOUT_MS;
  cfg.idle_core_mask = 0;
  cfg.trigger_panic = true;
  if (esp_task_wdt_reconfigure(&cfg) != ESP_OK) esp_task_wdt_init(&cfg);
  esp_task_wdt_add(NULL);
}

// ====================================================================
// 3. 設定(NVS)
// ====================================================================

void loadConfig()
{
  preferences.begin("wifi-config", false);
  cfgSsid = preferences.getString("ssid", "");
  cfgPass = preferences.getString("password", "");
  deviceID = preferences.getString("deviceID", "");
  adminPass = preferences.getString("adminpw", "");
  apPass = preferences.getString("appw", "");
  serverUrl = preferences.getString("surl", "");
  serverToken = preferences.getString("stoken", "");
  otaUrl = preferences.getString("murl", "");
  powerMode = preferences.getUChar("mode", MODE_DEEPSLEEP);
  intervalMin = preferences.getUShort("intv", DEFAULT_INTERVAL_MIN);
  bleEnabled = preferences.getUChar("bleon", 0) != 0;
  bleIntervalMin = preferences.getUShort("bleiv", BLE_DEFAULT_INTERVAL_MIN);
  bleSensLoad(preferences);   // 登録済みの温湿度計(旧版の blemac は1台目として引き継ぐ)
  preferences.end();

  if (deviceID.length() == 0 || deviceID == "unset") deviceID = defaultDeviceId();
  if (powerMode != MODE_ALWAYS_ON) powerMode = MODE_DEEPSLEEP;
  if (intervalMin < 1) intervalMin = 1;
  if (intervalMin > 1440) intervalMin = 1440;
  if (bleIntervalMin != 15 && bleIntervalMin != 30 && bleIntervalMin != 60) bleIntervalMin = BLE_DEFAULT_INTERVAL_MIN;
  mdnsHost = makeMdnsHost(deviceID);

  if (FIXED_AP_PASSWORD != nullptr && strlen(FIXED_AP_PASSWORD) >= 8) apPass = String(FIXED_AP_PASSWORD);
}

// 設定用APのパスワードを用意する(無ければ生成して保存)。Wi-Fi起動後に呼ぶと乱数の質が良い。
void ensureApPass()
{
  if (apPass.length() >= 8) return;
  char b[9];
  snprintf(b, sizeof(b), "%08lu", (unsigned long)(esp_random() % 100000000UL));
  apPass = String(b);
  preferences.begin("wifi-config", false);
  preferences.putString("appw", apPass);
  preferences.end();
}

// 起床(または計測サイクル)の間隔[秒]。BLE取得が有効なら、送信間隔とBLE保存間隔の短いほうに合わせる。
// 時計の区切りに合わせる場合は、保存間隔を割り切れる値にする(例: 保存30分・送信20分 → 10分ごとに起きる)。
uint32_t cycleSec()
{
  uint32_t m = intervalMin;
  if (bleEnabled)
  {
    if (bleIntervalMin < m) m = bleIntervalMin;
    if (BLE_ALIGN_TO_CLOCK)
    {
      while (m > 1 && (bleIntervalMin % m) != 0) m--;
    }
  }
  return m * 60UL;
}

// 区切りの何秒前までを「その区切り」とみなすか(周期が短いときは周期の1/4まで)
uint32_t alignEarlyS(uint32_t periodS)
{
  uint32_t e = BLE_DUE_SLACK_S;
  if (e > periodS / 4) e = periodS / 4;
  return e;
}

// 時計の区切りに合わせて動かすか
bool bleAlignedNow()
{
  return BLE_ALIGN_TO_CLOCK && bleEnabled && timeValid();
}

// 次の区切りまでの秒数(今が区切りの少し前でも、その区切りは済んだものとして次の区切りを返す)
uint32_t secToNextBoundary()
{
  const int64_t p = (int64_t)cycleSec();
  const int64_t t = (int64_t)time(nullptr);
  const int64_t k = (t + (int64_t)alignEarlyS((uint32_t)p)) / p;
  return (uint32_t)((k + 1) * p - t);
}

// 次の計測サイクルまでの秒数(区切りに合わせるときは次の区切りまで、そうでなければ一定間隔)
uint32_t secToNextCycle()
{
  if (bleAlignedNow()) return secToNextBoundary();
  return cycleSec();
}

// Wi-Fi設定・機器ID・各種設定を消す(設定用APのパスワードは残す)
void doFactoryReset()
{
  preferences.begin("wifi-config", false);
  String keep = preferences.getString("appw", "");
  preferences.clear();
  if (keep.length() > 0) preferences.putString("appw", keep);
  preferences.end();
}

bool validUrl(const String& u, bool httpsOnly)
{
  if (u.length() == 0) return true;
  if (u.length() > 200) return false;
  if (u.startsWith("https://")) return true;
  if (!httpsOnly && u.startsWith("http://")) return true;
  return false;
}

// 起動直後の救済時間内にBOOTボタンが5秒長押しされたか確認
// 注意：BOOTを押したまま電源投入・RESETすると書込みモードに入ることがあるため、
// 通常起動後、シリアルに案内が出てからBOOTを長押しする。
void checkBootFactoryReset()
{
  pinMode(BOOT_BUTTON_PIN, INPUT_PULLUP);
  pinMode(STATUS_LED, OUTPUT);
  digitalWrite(STATUS_LED, LOW);

  Serial.println("Factory reset: press BOOT within 3 seconds.");

  unsigned long windowStart = millis();
  unsigned long pressStart = 0;
  bool counting = false;

  while (millis() - windowStart < FACTORY_RESET_WINDOW_MS)
  {
    esp_task_wdt_reset();
    bool pressed = (digitalRead(BOOT_BUTTON_PIN) == LOW);

    if (pressed)
    {
      if (!counting)
      {
        counting = true;
        pressStart = millis();
        Serial.println("BOOT detected. Hold for 5 seconds.");
      }

      // 受付時間内に押し始めた場合、受付時間を過ぎても5秒間の長押しを認める
      while (counting)
      {
        esp_task_wdt_reset();
        if (digitalRead(BOOT_BUTTON_PIN) != LOW)
        {
          Serial.println("Factory reset canceled.");
          counting = false;
          digitalWrite(STATUS_LED, LOW);
          break;
        }

        bool blink = ((millis() / 250) % 2) == 0;
        digitalWrite(STATUS_LED, blink ? HIGH : LOW);

        if (millis() - pressStart >= FACTORY_RESET_HOLD_MS)
        {
          Serial.println("Factory reset started.");
          doFactoryReset();
          digitalWrite(STATUS_LED, HIGH);
          Serial.println("Factory reset complete. Restarting...");
          delay(1000);
          ESP.restart();
        }
        delay(10);
      }
    }
    delay(10);
  }

  digitalWrite(STATUS_LED, LOW);
  Serial.println("Factory reset window closed.");
}

// ====================================================================
// 4. OTAの正常確認(ロールバック)
// ====================================================================

void checkPendingVerify()
{
  const esp_partition_t* running = esp_ota_get_running_partition();
  esp_ota_img_states_t st;
  if (running != nullptr && esp_ota_get_state_partition(running, &st) == ESP_OK)
  {
    pendingVerify = (st == ESP_OTA_IMG_PENDING_VERIFY);
    Serial.printf("OTA: running=%s state=%d pending_verify=%d\n",
                  running->label, (int)st, (int)pendingVerify);
  }
  else
  {
    Serial.println("OTA: state not available (rollback not enabled?)");
  }
}

void markValid()
{
  if (!pendingVerify) return;
  esp_ota_mark_app_valid_cancel_rollback();
  pendingVerify = false;
  Serial.println("OTA: new firmware validated");
}

// 保守・常時稼働モードでの確認(起動後30秒Wi-Fi接続またはAP稼働なら正常)
void validateLoop()
{
  if (!pendingVerify) return;
  uint32_t now = millis();
  bool stable = apMode || (WiFi.status() == WL_CONNECTED);
  if (stable && now >= VALIDATE_STABLE_MS)
  {
    markValid();
  }
  else if (now >= VALIDATE_DEADLINE_MS)
  {
    Serial.println("OTA: validation failed, rolling back");
    esp_ota_mark_app_invalid_rollback_and_reboot();
  }
}

// ====================================================================
// 5. 電池・センサー
// ====================================================================

float readBatteryV()
{
  if (BATT_ADC_PIN < 0) return NAN;
  uint32_t sum = 0;
  for (int i = 0; i < 8; i++)
  {
    sum += analogReadMilliVolts((uint8_t)BATT_ADC_PIN);
    delay(2);
  }
  return (sum / 8.0f) * BATT_DIV_RATIO / 1000.0f;
}

// ここに重量(HX711)・温湿度・電気柵電圧などの読み取りを追加し、JSONオブジェクト文字列で返す。
// 例: {"w_kg":42.35,"t_in":34.5,"h_in":62.0,"fence_v":7.8}
String bleSensorsJson();   // 5b. BLEセンサの未送信記録
String collectSensorsJson()
{
  return bleSensorsJson();   // ここに重量・電柵などを足すときは、キーを増やして {"ble":[...],"w_kg":..} の形にする
}


// ====================================================================
// 5b. BLEセンサ(Inkbird IBS-TH2 / IBS-TH2 PLUS) 最大 BLE_MAX_SENSORS 台
//   温湿度計は一定間隔で「広告パケット」を送っており、その中に温度・湿度・電池残量が入っている。
//   ESP32は接続せずに広告を聞くだけなので、ペアリングも温湿度計側の設定も不要。
//   広告データ(メーカーデータ全9バイト。先頭2バイトも温度の一部として使われる):
//     [0..1] 温度 ×100 (int16, リトルエンディアン)
//     [2..3] 湿度 ×100 (uint16, リトルエンディアン)  ※湿度なしの機種・状態では 0
//     [7]    電池残量 % (0〜100)
//   このレイアウトは公開されているオープンソース(inkbird-ble)の実装に基づく。
//   実機で値が合わないときは、シリアルモニターの "BLE raw:" の行を確認すること。
//
//   記録は LittleFS(フラッシュの「spiffs」領域)の1つのファイルに、16バイトの記録を追記していく。
//   古い記録は BLE_KEEP_DAYS 日分だけ残し、BLE_COMPACT_DAYS_X10/10 日を超えたらまとめて整理する。
//   送信済みの印(通し番号)は NVS("blelog" の "sent")に持つ。
// ====================================================================

// ---- 登録センサー(スロット番号 0〜3。sid は消しても再利用しない固有番号で、記録に付ける) ----

int bleSensIndexByMac(const char* mac)
{
  for (int i = 0; i < BLE_MAX_SENSORS; i++)
  {
    if (bleSens[i].sid != 0 && strcasecmp(bleSens[i].mac, mac) == 0) return i;
  }
  return -1;
}

int bleSensIndexBySid(uint8_t sid)
{
  if (sid == 0) return -1;
  for (int i = 0; i < BLE_MAX_SENSORS; i++)
  {
    if (bleSens[i].sid == sid) return i;
  }
  return -1;
}

uint8_t bleSensCount()
{
  uint8_t n = 0;
  for (int i = 0; i < BLE_MAX_SENSORS; i++)
  {
    if (bleSens[i].sid != 0) n++;
  }
  return n;
}

uint8_t bleWantMaskAll()
{
  uint8_t m = 0;
  for (int i = 0; i < BLE_MAX_SENSORS; i++)
  {
    if (bleSens[i].sid != 0) m = (uint8_t)(m | (1u << i));
  }
  return m;
}

// 名前から、JSON/CSV/HTMLで困る文字と制御文字を除き、BLE_NAME_MAX バイト以内(UTF-8の文字の途中では切らない)にする
String bleCleanName(const String& src)
{
  String o;
  for (unsigned int i = 0; i < src.length(); i++)
  {
    char c = src.charAt(i);
    if ((uint8_t)c < 0x20 || c == 0x7F) continue;
    if (c == '"' || c == '\\' || c == '<' || c == '>' || c == '&' || c == '\'' || c == ',') continue;
    o += c;
  }
  o.trim();
  if (o.length() > BLE_NAME_MAX)
  {
    int n = BLE_NAME_MAX;
    while (n > 0 && (((uint8_t)o.charAt(n)) & 0xC0) == 0x80) n--;
    o = o.substring(0, n);
    o.trim();
  }
  return o;
}

// "aa:bb:cc:dd:ee:ff" → 大文字にそろえる。形式が正しくなければ false
bool bleNormalizeMac(String& m)
{
  m.trim();
  m.toUpperCase();
  if (m.length() != 17) return false;
  for (int i = 0; i < 17; i++)
  {
    char c = m.charAt(i);
    if (i % 3 == 2) { if (c != ':') return false; }
    else if (!((c >= '0' && c <= '9') || (c >= 'A' && c <= 'F'))) return false;
  }
  return true;
}

void bleSensPersist(Preferences& p)
{
  for (int i = 0; i < BLE_MAX_SENSORS; i++)
  {
    p.putUChar(("bi" + String(i)).c_str(), bleSens[i].sid);
    p.putString(("bm" + String(i)).c_str(), String(bleSens[i].mac));
    p.putString(("bn" + String(i)).c_str(), String(bleSens[i].name));
  }
  p.putUChar("bnext", bleSensNext);
}

void bleSensSave()
{
  preferences.begin("wifi-config", false);
  bleSensPersist(preferences);
  preferences.end();
}

// 設定を読み込んだ直後に呼ぶ(preferences が開いている間に)。旧版の1台指定(blemac)は1台目として引き継ぐ。
void bleSensLoad(Preferences& p)
{
  memset(bleSens, 0, sizeof(bleSens));
  bleSensNext = p.getUChar("bnext", 1);
  if (bleSensNext == 0) bleSensNext = 1;
  for (int i = 0; i < BLE_MAX_SENSORS; i++)
  {
    bleSens[i].sid = p.getUChar(("bi" + String(i)).c_str(), 0);
    String m = p.getString(("bm" + String(i)).c_str(), "");
    String n = p.getString(("bn" + String(i)).c_str(), "");
    if (bleSens[i].sid != 0 && !bleNormalizeMac(m)) bleSens[i].sid = 0;
    if (bleSens[i].sid == 0) continue;
    strncpy(bleSens[i].mac, m.c_str(), sizeof(bleSens[i].mac) - 1);
    strncpy(bleSens[i].name, n.c_str(), sizeof(bleSens[i].name) - 1);
  }
  if (bleSensCount() == 0)
  {
    String old = p.getString("blemac", "");
    if (bleNormalizeMac(old))
    {
      bleSens[0].sid = bleSensNext++;
      if (bleSensNext == 0) bleSensNext = 1;
      strncpy(bleSens[0].mac, old.c_str(), sizeof(bleSens[0].mac) - 1);
      strncpy(bleSens[0].name, "センサー1", sizeof(bleSens[0].name) - 1);
      bleSensPersist(p);
      p.remove("blemac");
    }
  }
}

uint8_t bleNextSid()
{
  for (int guard = 0; guard < 300; guard++)
  {
    uint8_t s = bleSensNext++;
    if (bleSensNext == 0) bleSensNext = 1;
    if (s != 0 && bleSensIndexBySid(s) < 0) return s;
  }
  return 1;
}

// 追加、または(登録済みなら)名前の変更。失敗したら理由を err に入れる。
bool bleSensAdd(String mac, const String& nameIn, String& err)
{
  if (!bleNormalizeMac(mac))
  {
    err = "Bluetoothアドレスの形式が正しくありません。";
    return false;
  }
  int idx = bleSensIndexByMac(mac.c_str());
  if (idx < 0)
  {
    for (int i = 0; i < BLE_MAX_SENSORS; i++)
    {
      if (bleSens[i].sid == 0) { idx = i; break; }
    }
    if (idx < 0)
    {
      err = "登録できるのは最大 " + String((int)BLE_MAX_SENSORS) + " 台です。先に不要なものを削除してください。";
      return false;
    }
    memset(&bleSens[idx], 0, sizeof(bleSens[idx]));
    bleSens[idx].sid = bleNextSid();
    strncpy(bleSens[idx].mac, mac.c_str(), sizeof(bleSens[idx].mac) - 1);
  }
  String nm = bleCleanName(nameIn);
  if (nm.length() == 0) nm = "温湿度計" + String(idx + 1);
  memset(bleSens[idx].name, 0, sizeof(bleSens[idx].name));
  strncpy(bleSens[idx].name, nm.c_str(), sizeof(bleSens[idx].name) - 1);
  bleSensSave();
  return true;
}

bool bleSensDel(const String& mac)
{
  int idx = bleSensIndexByMac(mac.c_str());
  if (idx < 0) return false;
  memset(&bleSens[idx], 0, sizeof(bleSens[idx]));
  bleSensSave();
  return true;
}

// 記録に付いた sid から名前を引く(削除済みなら nullptr)
const char* bleSensNameBySid(uint8_t sid)
{
  int i = bleSensIndexBySid(sid);
  return (i >= 0) ? bleSens[i].name : nullptr;
}

// ---- 受信 ----

static volatile uint8_t bleFoundMask = 0;   // 受信タスクが書き、メインが読む(登録スロットごとのビット)
static uint8_t bleWantMask = 0;
static BleSample bleHits[BLE_MAX_SENSORS];
static bool bleDiscMode = false;            // true: 周辺検索(登録用)
static BleDisc bleDisc[BLE_DISC_MAX];
static volatile uint8_t bleDiscN = 0;
static bool bleCalMode = false;             // true: 重量センサの較正(1台の値を集める)
static char bleCalMac[18];
static int32_t bleCalRaw[WCAL_MAX_SAMPLES];
static uint16_t bleCalLastSeq = 0;
static volatile uint8_t bleCalN = 0;        // 集めた測定の回数(較正を始めた後に測った分だけ)
static volatile bool bleCalSeen = false;    // 電波を受けたか
static volatile bool bleCalHaveSeq = false;
static volatile uint8_t bleCalFail = 0;     // 測定停止の印を受けた(1=電池電圧が低い 2=HX711の読み取り失敗)
static volatile int8_t bleCalRssi = 0;
static volatile uint16_t bleCalMv = 0;

// 電柵アラートの記録の raw から取り出す
// (Arduino IDEの自動プロトタイプ生成で型より前に関数が来ないよう、型の定義より後に置く)
inline uint8_t bleAlrState(int32_t raw) { return (uint8_t)((uint32_t)raw & 0xFF); }           // 0=正常 1=電圧低下 2=常時点灯
inline uint8_t bleAlrBlinks(int32_t raw) { return (uint8_t)(((uint32_t)raw >> 8) & 0xFF); }
inline uint16_t bleAlrEvents(int32_t raw) { return (uint16_t)(((uint32_t)raw >> 16) & 0xFFFF); }
inline const char* bleAlrText(uint8_t st) { return st == 0 ? "正常" : st == 1 ? "電圧低下" : st == 2 ? "常時点灯(遮光・配線を確認)" : "不明"; }

// メーカーデータ(9バイト)から温度・湿度・電池を読む。範囲外(壊れたパケット)なら false
static bool bleParse(const std::string& md, BleSample& s)
{
  if (md.length() != 9) return false;
  const uint8_t b0 = (uint8_t)md[0], b1 = (uint8_t)md[1], b2 = (uint8_t)md[2], b3 = (uint8_t)md[3], b7 = (uint8_t)md[7];
  const int16_t t = (int16_t)((uint16_t)b0 | ((uint16_t)b1 << 8));
  const uint16_t h = (uint16_t)((uint16_t)b2 | ((uint16_t)b3 << 8));
  if (t < -4000 || t > 12000) return false;   // -40〜120℃の範囲外
  s.t100 = t;
  s.flags = 0;
  s.h100 = 0;
  if (h != 0 && h <= 10000)
  {
    s.h100 = h;
    s.flags |= BLE_F_HUM;
  }
  s.bat = 0;
  if (b7 <= 100)
  {
    s.bat = b7;
    s.flags |= BLE_F_BAT;
  }
  return true;
}

// 重量センサの広告(メーカーデータ16バイト)を読む。
//   [0..1] 0xFFFF  [2] 'W'  [3] 版(1)  [4..5] 通し番号  [6..9] 生の値(int32)
//   [10..11] ノード内温度×100(int16)  [12..13] ばらつき(uint16)  [14..15] 電池 mV(uint16)
static bool bleParseW(const std::string& md, BleSample& s)
{
  if (md.length() != 16) return false;
  if ((uint8_t)md[2] != 'W' || (uint8_t)md[3] != 1) return false;
  const uint32_t u = (uint32_t)(uint8_t)md[6] | ((uint32_t)(uint8_t)md[7] << 8) | ((uint32_t)(uint8_t)md[8] << 16) | ((uint32_t)(uint8_t)md[9] << 24);
  s.raw = (int32_t)u;
  s.t100 = (int16_t)((uint16_t)(uint8_t)md[10] | ((uint16_t)(uint8_t)md[11] << 8));
  s.h100 = (uint16_t)((uint16_t)(uint8_t)md[12] | ((uint16_t)(uint8_t)md[13] << 8));
  const uint16_t mv = (uint16_t)((uint16_t)(uint8_t)md[14] | ((uint16_t)(uint8_t)md[15] << 8));
  s.flags = BLE_F_WGT;
  s.bat = 0;
  if (mv >= 2000)
  {
    uint32_t v = (uint32_t)(mv - 2000) / 10;
    s.bat = (uint8_t)(v > 255 ? 255 : v);
    s.flags |= BLE_F_BAT;
  }
  return true;
}

// 電柵アラートの広告(メーカーデータ16バイト)を読む。
//   [0..1] 0xFFFF  [2] 'A'  [3] 版(1)  [4..5] 通し番号  [6] 状態(0=正常 1=電圧低下 2=常時点灯)
//   [7] 直近の点滅回数  [8..9] 電圧低下の回数(uint16)  [10..11] ノード内温度×100(int16)
//   [12..13] 最後の点滅からの秒数(uint16, 0xFFFF=点滅なし)  [14..15] 電池 mV(uint16)
static bool bleParseA(const std::string& md, BleSample& s)
{
  if (md.length() != 16) return false;
  if ((uint8_t)md[2] != 'A' || (uint8_t)md[3] != 1) return false;
  const uint8_t st = (uint8_t)md[6];
  if (st > 2) return false;
  const uint8_t blinks = (uint8_t)md[7];
  const uint16_t ev = (uint16_t)((uint16_t)(uint8_t)md[8] | ((uint16_t)(uint8_t)md[9] << 8));
  s.raw = (int32_t)((uint32_t)st | ((uint32_t)blinks << 8) | ((uint32_t)ev << 16));
  s.t100 = (int16_t)((uint16_t)(uint8_t)md[10] | ((uint16_t)(uint8_t)md[11] << 8));
  s.h100 = (uint16_t)((uint16_t)(uint8_t)md[12] | ((uint16_t)(uint8_t)md[13] << 8));
  const uint16_t mv = (uint16_t)((uint16_t)(uint8_t)md[14] | ((uint16_t)(uint8_t)md[15] << 8));
  s.flags = BLE_F_ALR;
  s.bat = 0;
  if (mv >= 2000)
  {
    uint32_t v = (uint32_t)(mv - 2000) / 10;
    s.bat = (uint8_t)(v > 255 ? 255 : v);
    s.flags |= BLE_F_BAT;
  }
  return true;
}

// 温湿度センサ HPX-TH の広告(メーカーデータ16バイト)を読む。
//   [0..1] 0xFFFF  [2] 'T'  [3] 版(1)  [4..5] 通し番号  [6..7] SHT40の温度×100(int16, 0x7FFF=測れず)
//   [8..9] 湿度×100(uint16, 0xFFFF=測れず)  [10..11] サーミスターの温度×100(int16, 0x7FFF=測れず)
//   [12..13] ノード内温度×100(int16、記録しない)  [14..15] 電池 mV(uint16)
static bool bleParseT(const std::string& md, BleSample& s)
{
  if (md.length() != 16) return false;
  if ((uint8_t)md[2] != 'T' || (uint8_t)md[3] != 1) return false;
  const int16_t t = (int16_t)((uint16_t)(uint8_t)md[6] | ((uint16_t)(uint8_t)md[7] << 8));
  const uint16_t h = (uint16_t)((uint16_t)(uint8_t)md[8] | ((uint16_t)(uint8_t)md[9] << 8));
  const int16_t t2 = (int16_t)((uint16_t)(uint8_t)md[10] | ((uint16_t)(uint8_t)md[11] << 8));
  const uint16_t mv = (uint16_t)((uint16_t)(uint8_t)md[14] | ((uint16_t)(uint8_t)md[15] << 8));
  s.flags = BLE_F_THX;
  s.t100 = (t >= -4000 && t <= 12000) ? t : BLE_NO_TEMP;
  s.raw = (t2 >= -4000 && t2 <= 12000) ? t2 : BLE_NO_TEMP;
  s.h100 = 0;
  if (s.t100 != BLE_NO_TEMP && h <= 10000)
  {
    s.h100 = h;
    s.flags |= BLE_F_HUM;
  }
  s.bat = 0;
  if (mv >= 2000)
  {
    uint32_t v = (uint32_t)(mv - 2000) / 10;
    s.bat = (uint8_t)(v > 255 ? 255 : v);
    s.flags |= BLE_F_BAT;
  }
  return true;
}

// 温湿度計(9バイト)・重量センサ/電柵アラート/HPX-TH(16バイト。[2]が 'W' か 'A' か 'T')のどれでも読む
static bool bleParseAny(const std::string& md, BleSample& s)
{
  s.raw = 0;
  if (md.length() == 9) return bleParse(md, s);
  if (md.length() == 16 && (uint8_t)md[2] == 'W') return bleParseW(md, s);
  if (md.length() == 16 && (uint8_t)md[2] == 'A') return bleParseA(md, s);
  if (md.length() == 16 && (uint8_t)md[2] == 'T') return bleParseT(md, s);
  return false;
}

// SwitchBot 温湿度計の温度・湿度の3バイト(小数 / 符号+整数 / 湿度)を読む。範囲外なら false
static bool bleSbTempHum(uint8_t b0, uint8_t b1, uint8_t b2, BleSample& s)
{
  const uint8_t dec = (uint8_t)(b0 & 0x0F);
  const uint8_t ip = (uint8_t)(b1 & 0x7F);
  const uint8_t hum = (uint8_t)(b2 & 0x7F);   // bit7 は本体の表示単位(℃/℉)。値はいつも℃
  if (dec > 9 || ip > 120 || hum > 100) return false;
  int t = (int)ip * 100 + (int)dec * 10;
  if (!(b1 & 0x80)) t = -t;                    // bit7=1 で0℃以上、0 で氷点下
  if (t < -4000) return false;
  s.t100 = (int16_t)t;
  s.h100 = (uint16_t)(hum * 100);
  s.flags = BLE_F_HUM;
  s.bat = 0;
  s.raw = 0;
  return true;
}

// SwitchBot 温湿度計の広告を読む。SwitchBot でなければ 0、読めたら kind(3=温湿度計 4=防水温湿度計)を返す。
//   md: メーカーデータ(無ければ空)。requireType=true なら、サービスデータの機種が温湿度計のときだけ読む(周辺検索用)。
//   登録済みのアドレスに対しては、スキャン応答を取りこぼしてもメーカーデータだけで読む(電池%は無しになる)。
static uint8_t bleParseSB(const NimBLEAdvertisedDevice* dev, const std::string& md, bool requireType, BleSample& s)
{
  std::string sd = dev->getServiceData(NimBLEUUID((uint16_t)0xFD3D));
  if (sd.length() < 3) sd = dev->getServiceData(NimBLEUUID((uint16_t)0x0D00));
  uint8_t type = 0;
  if (sd.length() >= 3)
  {
    type = (uint8_t)(sd[0] & 0x7F);
    if (type != 'T' && type != 't' && type != 'i' && type != 'I' && type != 'w' && type != 'W') return 0;
  }
  else if (requireType)
  {
    return 0;
  }
  const bool outdoor = (type == 'w' || type == 'W');

  // 会社ID 0x0969 のメーカーデータ(13バイト以上)。Inkbird の9バイト(先頭が温度)と取り違えないよう長さも見る
  const bool sbMd = md.length() >= 13 && (uint8_t)md[0] == 0x69 && (uint8_t)md[1] == 0x09;
  bool ok = false;
  if (sbMd) ok = bleSbTempHum((uint8_t)md[10], (uint8_t)md[11], (uint8_t)md[12], s);
  if (!ok && !outdoor && sd.length() >= 6) ok = bleSbTempHum((uint8_t)sd[3], (uint8_t)sd[4], (uint8_t)sd[5], s);
  if (!ok) return 0;

  if (sd.length() >= 3)
  {
    const uint8_t b = (uint8_t)(sd[2] & 0x7F);
    if (b <= 100)
    {
      s.bat = b;
      s.flags |= BLE_F_BAT;
    }
  }
  return outdoor ? 4 : 3;
}

class HpxBleCb : public NimBLEScanCallbacks
{
 public:
  // 1台分の受信(スキャン応答を含む)が完了するたびに呼ばれる
  void onResult(const NimBLEAdvertisedDevice* dev) override
  {
    std::string md;
    if (dev->haveManufacturerData()) md = dev->getManufacturerData(0);
    const bool sbCand = dev->haveServiceData() || (md.length() >= 13 && (uint8_t)md[0] == 0x69 && (uint8_t)md[1] == 0x09);
    if (md.length() != 9 && md.length() != 16 && !sbCand) return;
    std::string addr = dev->getAddress().toString();

    if (bleCalMode)
    {
      // 重量センサの較正: 指定の1台の、新しい測定(通し番号が変わったもの)だけを集める
      if (strcasecmp(addr.c_str(), bleCalMac) != 0) return;
      if (md.length() != 16 || (uint8_t)md[2] != 'W') return;
      BleSample s;
      if (!bleParseW(md, s)) return;
      const uint16_t seq = (uint16_t)((uint16_t)(uint8_t)md[4] | ((uint16_t)(uint8_t)md[5] << 8));
      bleCalSeen = true;
      bleCalRssi = (int8_t)dev->getRSSI();
      bleCalMv = (s.flags & BLE_F_BAT) ? (uint16_t)(2000 + s.bat * 10) : 0;
      if (!bleCalHaveSeq)
      {
        // 最初に受けた値は、ボタンを押す前に測ったものかもしれないので使わない
        bleCalLastSeq = seq;
        bleCalHaveSeq = true;
        return;
      }
      if (seq == bleCalLastSeq) return;   // 同じ測定の広告の繰り返し
      bleCalLastSeq = seq;
      if (s.h100 == 0xFFFF)               // 測定停止の印
      {
        bleCalFail = (uint8_t)s.raw;
        return;
      }
      const uint8_t n = bleCalN;
      if (n >= WCAL_MAX_SAMPLES) return;
      bleCalRaw[n] = s.raw;
      bleCalN = (uint8_t)(n + 1);
      return;
    }

    if (bleDiscMode)
    {
      // 周辺検索: 名前が tps / sps(温湿度計)・HPXW(重量ノード)・HPX-alert(電柵アラート)・HPXT(HPX-TH)の機器と、
      //           SwitchBot の温湿度計(サービスデータの機種で見分ける)を一覧にする
      BleSample s;
      uint8_t kind = bleParseSB(dev, md, true, s);
      if (kind == 0)
      {
        if (!dev->haveName()) return;
        std::string nm = dev->getName();
        if (nm != "tps" && nm != "sps" && nm != "HPXW" && nm != "HPX-alert" && nm != "HPXT") return;
        if (!bleParseAny(md, s)) return;
        kind = (s.flags & BLE_F_ALR) ? 2 : (s.flags & BLE_F_WGT) ? 1 : (s.flags & BLE_F_THX) ? 5 : 0;
      }
      int idx = -1;
      const uint8_t n = bleDiscN;
      for (int i = 0; i < (int)n; i++)
      {
        if (strcasecmp(bleDisc[i].mac, addr.c_str()) == 0) { idx = i; break; }
      }
      if (idx < 0)
      {
        if (n >= BLE_DISC_MAX) return;
        idx = n;
        memset(&bleDisc[idx], 0, sizeof(bleDisc[idx]));
        strncpy(bleDisc[idx].mac, addr.c_str(), sizeof(bleDisc[idx].mac) - 1);
        for (char* p = bleDisc[idx].mac; *p; p++) *p = (char)toupper((unsigned char)*p);
        bleDisc[idx].t100 = s.t100;
        bleDisc[idx].h100 = s.h100;
        bleDisc[idx].flags = s.flags;
        bleDisc[idx].kind = kind;
        bleDisc[idx].raw = s.raw;
        bleDisc[idx].rssi = dev->getRSSI();
        bleDiscN = (uint8_t)(n + 1);   // 中身を埋めてから件数を増やす
        return;
      }
      bleDisc[idx].t100 = s.t100;
      bleDisc[idx].h100 = s.h100;
      bleDisc[idx].flags = s.flags;
      bleDisc[idx].raw = s.raw;
      bleDisc[idx].rssi = dev->getRSSI();
      return;
    }

    // 通常: 登録済みで、まだ受け取っていない機器だけを拾う
    for (int i = 0; i < BLE_MAX_SENSORS; i++)
    {
      if (!(bleWantMask & (1u << i))) continue;
      if (bleFoundMask & (1u << i)) continue;
      if (strcasecmp(addr.c_str(), bleSens[i].mac) != 0) continue;
      BleSample s;
      if (bleParseSB(dev, md, false, s) == 0 && !bleParseAny(md, s)) continue;
      Serial.printf("BLE raw: %s", addr.c_str());
      for (size_t k = 0; k < md.length() && k < 16; k++) Serial.printf(" %02X", (uint8_t)md[k]);
      Serial.println();
      s.rssi = dev->getRSSI();
      strncpy(s.mac, addr.c_str(), sizeof(s.mac) - 1);
      s.mac[sizeof(s.mac) - 1] = 0;
      bleHits[i] = s;
      bleFoundMask = (uint8_t)(bleFoundMask | (1u << i));   // スキャンの停止はメイン側で行う
    }
  }
};

static HpxBleCb bleCb;

// BLEを使い終わったら止めてメモリを返す。常時稼働でヒープが減っていたら再起動する。
static void bleShutdown(NimBLEScan* sc)
{
  if (sc != nullptr)
  {
    sc->stop();
    sc->clearResults();
  }
  NimBLEDevice::deinit(true);
  uint32_t heap = ESP.getFreeHeap();
  Serial.printf("BLE: free_heap=%lu\n", (unsigned long)heap);
  if (heap < BLE_MIN_HEAP && powerMode == MODE_ALWAYS_ON)
  {
    Serial.println("BLE: low heap. Restarting.");
    delay(200);
    ESP.restart();
  }
}

// 登録済みの want 台を ms ミリ秒まで待って受信する。全部そろったらすぐ終わる。1台でも取れれば true。
bool bleScanMulti(uint8_t want, uint32_t ms)
{
  bleWantMask = want;
  bleFoundMask = 0;
  bleDiscMode = false;
  memset(bleHits, 0, sizeof(bleHits));

  if (!NimBLEDevice::init(""))
  {
    Serial.println("BLE: init failed");
    return false;
  }
  NimBLEScan* sc = NimBLEDevice::getScan();
  sc->setScanCallbacks(&bleCb, false);
  sc->setActiveScan(true);   // 名前などのスキャン応答も受け取る
  sc->setInterval(100);
  sc->setWindow(99);
  sc->start(ms, false, true);   // 非同期で開始。終了はこちらで時間を計って止める(時間の単位に依存しない)

  const uint32_t t0 = millis();
  while ((bleFoundMask & want) != want && (millis() - t0) < ms)
  {
    delay(50);
    esp_task_wdt_reset();
  }
  bleShutdown(sc);
  Serial.printf("BLE: found mask=0x%02X want=0x%02X\n", (unsigned)bleFoundMask, (unsigned)want);
  return (bleFoundMask & want) != 0;
}

// 周辺の tps / sps を ms ミリ秒探して bleDisc に入れる(登録画面用)。電波の強い順に並べて件数を返す。
uint8_t bleDiscover(uint32_t ms)
{
  bleDiscN = 0;
  memset(bleDisc, 0, sizeof(bleDisc));
  bleDiscMode = true;

  if (!NimBLEDevice::init(""))
  {
    bleDiscMode = false;
    Serial.println("BLE: init failed");
    return 0;
  }
  NimBLEScan* sc = NimBLEDevice::getScan();
  sc->setScanCallbacks(&bleCb, true);   // 同じ機器の最新の値を何度も受け取る
  sc->setActiveScan(true);
  sc->setInterval(100);
  sc->setWindow(99);
  sc->start(ms, false, true);

  const uint32_t t0 = millis();
  while ((millis() - t0) < ms)
  {
    delay(50);
    esp_task_wdt_reset();
  }
  bleShutdown(sc);
  bleDiscMode = false;

  uint8_t n = bleDiscN;
  for (int i = 1; i < (int)n; i++)   // 挿入ソート(RSSIの大きい順)
  {
    BleDisc t = bleDisc[i];
    int j = i - 1;
    while (j >= 0 && bleDisc[j].rssi < t.rssi)
    {
      bleDisc[j + 1] = bleDisc[j];
      j--;
    }
    bleDisc[j + 1] = t;
  }
  return n;
}

// 重量センサ1台(mac)の新しい測定を ms ミリ秒集めて bleCalRaw に入れる。集めた回数を返す。
uint8_t bleCalCollect(const String& mac, uint32_t ms)
{
  memset(bleCalMac, 0, sizeof(bleCalMac));
  strncpy(bleCalMac, mac.c_str(), sizeof(bleCalMac) - 1);
  bleCalN = 0;
  bleCalSeen = false;
  bleCalHaveSeq = false;
  bleCalFail = 0;
  bleCalMv = 0;
  bleCalMode = true;

  if (!NimBLEDevice::init(""))
  {
    bleCalMode = false;
    Serial.println("BLE: init failed");
    return 0;
  }
  NimBLEScan* sc = NimBLEDevice::getScan();
  sc->setScanCallbacks(&bleCb, true);   // 同じ機器の広告を何度も受け取る(通し番号で新しい測定を見分ける)
  sc->setActiveScan(false);              // 名前は要らないので、スキャン応答を待たない
  sc->setInterval(100);
  sc->setWindow(99);
  sc->start(ms, false, true);

  const uint32_t t0 = millis();
  while ((millis() - t0) < ms)
  {
    delay(50);
    esp_task_wdt_reset();
  }
  bleShutdown(sc);
  bleCalMode = false;
  Serial.printf("WCAL: %s samples=%u seen=%d fail=%u\n", bleCalMac, (unsigned)bleCalN, (int)bleCalSeen, (unsigned)bleCalFail);
  return bleCalN;
}

// ---- フラッシュ(LittleFS)への保存 ----

static const char* const BLE_LOG_PATH = "/ble20.bin";   // v1.11 から20バイト記録
static const char* const BLE_TMP_PATH = "/ble20.tmp";
static bool bleFsReady = false;

uint32_t bleNvsGet(const char* key)
{
  Preferences bp;
  bp.begin("blelog", false);
  uint32_t v = bp.getUInt(key, 0);
  bp.end();
  return v;
}

void bleNvsPut(const char* key, uint32_t v)
{
  Preferences bp;
  bp.begin("blelog", false);
  bp.putUInt(key, v);
  bp.end();
}

bool bleFsBegin()
{
  if (bleFsReady) return true;
  if (!LittleFS.begin(true))   // 初回は自動で初期化する
  {
    Serial.println("BLE: LittleFS mount failed");
    return false;
  }
  bleFsReady = true;
  // v1.10までの16バイト記録のファイルは引き継がずに消す
  LittleFS.remove("/blelog.bin");
  LittleFS.remove("/blelog.tmp");
  // 整理の途中で電源が切れたときの回復
  if (!LittleFS.exists(BLE_LOG_PATH) && LittleFS.exists(BLE_TMP_PATH)) LittleFS.rename(BLE_TMP_PATH, BLE_LOG_PATH);
  // 旧版(v1.09以前)がNVSに置いた記録は引き継がずに消す
  Preferences bp;
  bp.begin("blelog", false);
  if (bp.isKey("recs")) bp.remove("recs");
  bp.end();
  return true;
}

uint32_t bleLogCount()
{
  if (!bleFsBegin()) return 0;
  File f = LittleFS.open(BLE_LOG_PATH, FILE_READ);
  if (!f) return 0;
  uint32_t n = (uint32_t)(f.size() / sizeof(BleRec));
  f.close();
  return n;
}

uint32_t bleLogLastSeq()
{
  if (!bleFsBegin()) return 0;
  File f = LittleFS.open(BLE_LOG_PATH, FILE_READ);
  if (!f) return 0;
  uint32_t seq = 0;
  size_t n = f.size() / sizeof(BleRec);
  if (n > 0)
  {
    BleRec r;
    f.seek((n - 1) * sizeof(BleRec));
    if (f.read((uint8_t*)&r, sizeof(r)) == sizeof(r)) seq = r.seq;
  }
  f.close();
  return seq;
}

// 次に使う通し番号(ファイルの最後・NVSの控え・送信済みの印のうち最大のものの次)
uint32_t bleNextSeq()
{
  uint32_t a = bleLogLastSeq();
  uint32_t b = bleNvsGet("seq");
  uint32_t c = bleNvsGet("sent");
  if (b > a) a = b;
  if (c > a) a = c;
  return a + 1;
}

bool bleLogAppendRec(const BleRec& r)
{
  if (!bleFsBegin()) return false;
  File f = LittleFS.open(BLE_LOG_PATH, FILE_APPEND);
  if (!f) return false;
  size_t w = f.write((const uint8_t*)&r, sizeof(r));
  f.close();
  return w == sizeof(r);
}

// minTs より古い記録(時刻不明は残す)を捨て、新しい側 keepMax 件だけを残してファイルを作り直す。
// 端数バイト(書き込み中の電源断)もここで取り除かれる。
bool bleLogCompact(uint32_t minTs, uint32_t keepMax)
{
  if (!bleFsBegin()) return false;
  File in = LittleFS.open(BLE_LOG_PATH, FILE_READ);
  if (!in) return false;

  BleRec r;
  uint32_t eligible = 0;
  uint32_t lastSeq = 0;
  while (in.read((uint8_t*)&r, sizeof(r)) == sizeof(r))
  {
    lastSeq = r.seq;
    if (r.ts == 0 || r.ts >= minTs) eligible++;
  }
  uint32_t skip = (eligible > keepMax) ? (eligible - keepMax) : 0;

  in.seek(0);
  File out = LittleFS.open(BLE_TMP_PATH, FILE_WRITE);
  if (!out)
  {
    in.close();
    return false;
  }
  bool wok = true;
  uint32_t kept = 0;
  while (in.read((uint8_t*)&r, sizeof(r)) == sizeof(r))
  {
    if (!(r.ts == 0 || r.ts >= minTs)) continue;
    if (skip > 0) { skip--; continue; }
    if (out.write((const uint8_t*)&r, sizeof(r)) != sizeof(r)) { wok = false; break; }
    if ((++kept & 0xFF) == 0) esp_task_wdt_reset();
  }
  in.close();
  out.close();
  if (!wok)
  {
    LittleFS.remove(BLE_TMP_PATH);
    Serial.println("BLE: compact failed (flash full?)");
    return false;
  }
  if (lastSeq != 0) bleNvsPut("seq", lastSeq);   // 全部消えても通し番号が戻らないよう控えておく
  LittleFS.remove(BLE_LOG_PATH);
  LittleFS.rename(BLE_TMP_PATH, BLE_LOG_PATH);
  Serial.printf("BLE: compacted, %lu records kept\n", (unsigned long)kept);
  return true;
}

// 保存のたびに呼ぶ。古くなりすぎた(または多すぎる)ときだけ整理する。
void bleLogMaintain(uint32_t nowTs)
{
  if (!bleFsBegin()) return;
  File f = LittleFS.open(BLE_LOG_PATH, FILE_READ);
  if (!f) return;
  size_t sz = f.size();
  uint32_t n = (uint32_t)(sz / sizeof(BleRec));
  uint32_t firstTs = 0;
  if (n > 0)
  {
    BleRec r;
    if (f.read((uint8_t*)&r, sizeof(r)) == sizeof(r)) firstTs = r.ts;
  }
  f.close();

  const uint32_t keepS = BLE_KEEP_DAYS * 86400UL;
  const uint32_t limitS = (BLE_COMPACT_DAYS_X10 * 86400UL) / 10UL;
  uint32_t minTs = (nowTs > keepS) ? (nowTs - keepS) : 0;

  if (sz % sizeof(BleRec) != 0)
  {
    bleLogCompact(0, 0xFFFFFFFFUL);   // 端数の取り除き
  }
  else if (n > BLE_LOG_HARD_MAX)
  {
    bleLogCompact(minTs, (BLE_LOG_HARD_MAX * 3) / 4);
  }
  else if (nowTs > limitS && firstTs != 0 && firstTs < nowTs - limitS)
  {
    bleLogCompact(minTs, BLE_LOG_HARD_MAX);
  }
}

void bleLogClear()
{
  if (!bleFsBegin()) return;
  uint32_t last = bleLogLastSeq();
  if (last != 0) bleNvsPut("seq", last);
  LittleFS.remove(BLE_LOG_PATH);
}

// 新しい側から最大 maxN 件を古い順に out へ読む(画面表示用)。読めた件数を返す。
uint32_t bleLogTail(BleRec* out, uint32_t maxN)
{
  if (!bleFsBegin()) return 0;
  File f = LittleFS.open(BLE_LOG_PATH, FILE_READ);
  if (!f) return 0;
  uint32_t n = (uint32_t)(f.size() / sizeof(BleRec));
  uint32_t start = (n > maxN) ? (n - maxN) : 0;
  f.seek((size_t)start * sizeof(BleRec));
  uint32_t got = 0;
  while (got < maxN && f.read((uint8_t*)&out[got], sizeof(BleRec)) == sizeof(BleRec)) got++;
  f.close();
  return got;
}

// ---- 記録のタイミング ----

// 記録の保存時刻になっているか
bool bleDue()
{
  if (!timeValid()) return true;

  if (BLE_ALIGN_TO_CLOCK)
  {
    const int64_t now = (int64_t)time(nullptr);
    const int64_t p = (int64_t)bleIntervalMin * 60;
    const int64_t e = (int64_t)alignEarlyS((uint32_t)p);
    if (rtcLastBle <= 0)
    {
      if (BLE_SAVE_ON_BOOT) return true;
      rtcLastBle = now;   // 起動直後は保存せず、次の区切りから記録する
      return false;
    }
    if (now < rtcLastBle) return true;   // 時計が戻った
    // 前回の記録と、今が、別の区切りに入っていれば保存する
    return ((now + e) / p) > ((rtcLastBle + e) / p);
  }

  if (rtcLastBle <= 0) return true;
  int64_t el = (int64_t)time(nullptr) - rtcLastBle;
  return el < 0 || el >= (int64_t)bleIntervalMin * 60 - (int64_t)BLE_DUE_SLACK_S;
}

// 記録に付ける時刻。区切りの近く(区切りの少し前〜BLE_SNAP_LATE_S秒後)に取れたときは、区切りの時刻にそろえる。
uint32_t bleRecordTs()
{
  if (!timeValid()) return 0;
  const int64_t now = (int64_t)time(nullptr);
  if (!BLE_ALIGN_TO_CLOCK) return (uint32_t)now;
  const int64_t p = (int64_t)bleIntervalMin * 60;
  const int64_t e = (int64_t)alignEarlyS((uint32_t)p);
  const int64_t slotStart = ((now + e) / p) * p;
  if (now - slotStart <= (int64_t)BLE_SNAP_LATE_S) return (uint32_t)slotStart;
  return (uint32_t)now;
}

// 保存時刻なら、登録済みの温湿度計をまとめて受信してフラッシュに保存する。force=true なら時刻に関係なく実行。
// 全台が同じ時刻(区切り)の記録になる。
bool bleCollectIfDue(bool force)
{
  if (!bleEnabled) return false;
  const uint8_t want = bleWantMaskAll();
  if (want == 0)
  {
    bleStatusText = "温湿度計が未登録です(「温湿度計の登録」から追加してください)";
    return false;
  }
  if (!force && !bleDue())
  {
    bleStatusText = "保存間隔待ち(" + String(bleIntervalMin) + "分ごと)";
    return true;
  }

  esp_task_wdt_reset();
  bool any = bleScanMulti(want, BLE_SCAN_MS);
  esp_task_wdt_reset();

  if (!any)
  {
    bleStatusText = "温湿度計が見つかりません(" + nowText() + ")";
    Serial.println("BLE: no sensor found.");
    return false;
  }

  const uint32_t ts = bleRecordTs();
  uint32_t seq = bleNextSeq();
  String okText, missText;
  uint8_t saved = 0;
  for (int i = 0; i < BLE_MAX_SENSORS; i++)
  {
    if (!(want & (1u << i))) continue;
    if (!(bleFoundMask & (1u << i)))
    {
      if (missText.length() > 0) missText += "、";
      missText += String(bleSens[i].name);
      continue;
    }
    const BleSample& s = bleHits[i];
    BleRec r;
    memset(&r, 0, sizeof(r));
    r.seq = seq;
    r.ts = ts;
    r.t100 = s.t100;
    r.h100 = s.h100;
    r.rssi = (int8_t)s.rssi;
    r.bat = s.bat;
    r.flags = s.flags;
    r.sid = bleSens[i].sid;
    r.raw = s.raw;
    if (bleLogAppendRec(r))
    {
      seq++;
      saved++;
      if (okText.length() > 0) okText += " / ";
      if (s.flags & BLE_F_WGT)
      {
        okText += String(bleSens[i].name) + " 生" + String((long)s.raw);
      }
      else if (s.flags & BLE_F_ALR)
      {
        okText += String(bleSens[i].name) + " " + bleAlrText(bleAlrState(s.raw));
      }
      else if (s.flags & BLE_F_THX)
      {
        okText += String(bleSens[i].name) + " " + (s.t100 != BLE_NO_TEMP ? String(String(s.t100 / 100.0f, 1) + "℃") : String("温湿度-"));
        if (s.flags & BLE_F_HUM) okText += " " + String(s.h100 / 100.0f, 0) + "%";
        if (s.raw != BLE_NO_TEMP) okText += " サーミスター" + String(s.raw / 100.0f, 1) + "℃";
      }
      else
      {
        okText += String(bleSens[i].name) + " " + String(s.t100 / 100.0f, 1) + "℃";
        if (s.flags & BLE_F_HUM) okText += " " + String(s.h100 / 100.0f, 0) + "%";
      }
    }
  }
  bleLogMaintain(timeValid() ? (uint32_t)time(nullptr) : 0);
  if (saved > 0 && ts != 0) rtcLastBle = (int64_t)ts;
  Serial.printf("BLE: saved %u record(s), total %lu\n", (unsigned)saved, (unsigned long)bleLogCount());

  bleStatusText = String((int)saved) + "/" + String((int)bleSensCount()) + "台 保存: " + okText;
  if (missText.length() > 0) bleStatusText += " (未検出: " + missText + ")";
  bleStatusText += " (" + nowText() + ")";
  return saved > 0;
}

// ---- サーバーへの送信(未送信の記録を BLE_UPLOAD_CHUNK 件ずつ) ----
static uint32_t blePendingMaxSeq = 0;   // 今回のレポートに載せた(読み飛ばしを含む)最大の通し番号
static bool blePendingMore = false;     // まだ送っていない記録が残っているか

void bleMarkSent()
{
  if (blePendingMaxSeq == 0) return;
  bleNvsPut("sent", blePendingMaxSeq);
  Serial.printf("BLE: marked sent up to #%lu\n", (unsigned long)blePendingMaxSeq);
  blePendingMaxSeq = 0;
}

String bleSensorsJson()
{
  blePendingMaxSeq = 0;
  blePendingMore = false;
  if (!bleEnabled || !bleFsBegin()) return "{}";

  const uint32_t sent = bleNvsGet("sent");
  File f = LittleFS.open(BLE_LOG_PATH, FILE_READ);
  if (!f) return "{}";

  String a;
  a.reserve((size_t)BLE_UPLOAD_CHUNK * 110 + 64);
  uint16_t cnt = 0;
  BleRec r;
  while (f.read((uint8_t*)&r, sizeof(r)) == sizeof(r))
  {
    if (r.seq <= sent) continue;
    if (cnt >= BLE_UPLOAD_CHUNK)
    {
      blePendingMore = true;
      break;
    }
    blePendingMaxSeq = r.seq;
    if (bleSensIndexBySid(r.sid) < 0) continue;   // 削除済みの温湿度計の記録は送らずに捨てる
    if (cnt > 0) a += ",";
    a += "{\"seq\":" + String((unsigned long)r.seq);
    a += ",\"ts\":" + String((unsigned long)r.ts);
    a += ",\"s\":" + String((int)r.sid);
    if (!((r.flags & BLE_F_THX) && r.t100 == BLE_NO_TEMP)) a += ",\"t\":" + String(r.t100 / 100.0f, 2);   // HPX-TH の SHT40 が測れなかったときは送らない
    if (r.flags & BLE_F_WGT)
    {
      a += ",\"raw\":" + String((long)r.raw);
      a += ",\"sp\":" + String((unsigned)r.h100);
      if (r.flags & BLE_F_BAT) a += ",\"bv\":" + String(2.0f + r.bat * 0.01f, 2);
    }
    else if (r.flags & BLE_F_ALR)
    {
      a += ",\"al\":" + String((int)bleAlrState(r.raw));
      a += ",\"ev\":" + String((unsigned)bleAlrEvents(r.raw));
      a += ",\"bl\":" + String((unsigned)bleAlrBlinks(r.raw));
      if (r.h100 != 0xFFFF) a += ",\"ago\":" + String((unsigned)r.h100);
      if (r.flags & BLE_F_BAT) a += ",\"bv\":" + String(2.0f + r.bat * 0.01f, 2);
    }
    else if (r.flags & BLE_F_THX)
    {
      if (r.flags & BLE_F_HUM) a += ",\"h\":" + String(r.h100 / 100.0f, 2);
      if (r.raw != BLE_NO_TEMP) a += ",\"t2\":" + String(r.raw / 100.0f, 2);
      if (r.flags & BLE_F_BAT) a += ",\"bv\":" + String(2.0f + r.bat * 0.01f, 2);
    }
    else
    {
      if (r.flags & BLE_F_HUM) a += ",\"h\":" + String(r.h100 / 100.0f, 2);
      if (r.flags & BLE_F_BAT) a += ",\"bat\":" + String((int)r.bat);
    }
    a += ",\"rssi\":" + String((int)r.rssi) + "}";
    cnt++;
  }
  f.close();

  if (cnt == 0)
  {
    if (blePendingMaxSeq != 0) bleMarkSent();   // 読み飛ばした分だけなら、送らずに済みにする
    return "{}";
  }

  String j = "{\"ble\":[" + a + "],\"ble_sensors\":[";
  bool first = true;
  for (int i = 0; i < BLE_MAX_SENSORS; i++)
  {
    if (bleSens[i].sid == 0) continue;
    if (!first) j += ",";
    first = false;
    j += "{\"s\":" + String((int)bleSens[i].sid) + ",\"mac\":\"" + String(bleSens[i].mac) + "\",\"name\":\"" + String(bleSens[i].name) + "\"}";
  }
  j += "]}";
  return j;
}

// ---- 重量センサの較正(本体に保存し、サーバーへ送る) ----

bool wcalSendSoon = false;   // 較正を保存した直後に1回送信する(loop で行う)

static String wcalKey(const String& mac)
{
  String k = "m";
  for (unsigned int i = 0; i < mac.length(); i++) if (mac[i] != ':') k += mac[i];
  return k;   // "m" + 12桁(NVS のキーは15文字まで)
}

bool wcalGet(const String& mac, WCal& c)
{
  memset(&c, 0, sizeof(c));
  Preferences p;
  if (!p.begin("hpxwcal", true)) return false;
  const bool ok = p.getBytes(wcalKey(mac).c_str(), &c, sizeof(c)) == sizeof(c);
  p.end();
  if (!ok) memset(&c, 0, sizeof(c));
  return ok;
}

void wcalPut(const String& mac, const WCal& c)
{
  Preferences p;
  if (!p.begin("hpxwcal", false)) return;
  p.putBytes(wcalKey(mac).c_str(), &c, sizeof(c));
  p.end();
}

// 全台共通の「1kgあたりの値」(0 = 未設定)
float wcalCommon()
{
  Preferences p;
  if (!p.begin("hpxwcal", true)) return 0;
  const float v = p.getFloat("common", 0);
  p.end();
  return v;
}

void wcalSetCommon(float v)
{
  Preferences p;
  if (!p.begin("hpxwcal", false)) return;
  p.putFloat("common", v);
  p.end();
}

// 登録済みセンサの種類を記録から調べる(0=温湿度計 1=重量センサ 2=電柵アラート 0xFF=まだ記録がない)
void bleKindsFromLog(uint8_t kinds[BLE_MAX_SENSORS])
{
  memset(kinds, 0xFF, BLE_MAX_SENSORS);
  if (!bleFsBegin()) return;
  File f = LittleFS.open(BLE_LOG_PATH, FILE_READ);
  if (!f) return;
  BleRec r;
  while (f.read((uint8_t*)&r, sizeof(r)) == sizeof(r))
  {
    const int i = bleSensIndexBySid(r.sid);
    if (i < 0) continue;
    kinds[i] = (r.flags & BLE_F_WGT) ? 1 : (r.flags & BLE_F_ALR) ? 2 : 0;
  }
  f.close();
}

// サーバーへまだ送っていない較正を JSON の配列にする(無ければ空文字)。送った分は wcalMarkSent() で済みにする
static uint32_t wcalSentTs[BLE_MAX_SENSORS];
static bool wcalSentHas[BLE_MAX_SENSORS];

String wcalJson()
{
  String a;
  for (int i = 0; i < BLE_MAX_SENSORS; i++)
  {
    wcalSentHas[i] = false;
    if (bleSens[i].sid == 0) continue;
    WCal c;
    if (!wcalGet(String(bleSens[i].mac), c)) continue;
    if (!(c.flags & WCAL_F_PENDING) || !(c.flags & WCAL_F_ZERO) || !(c.flags & WCAL_F_SPAN) || c.perKg == 0) continue;
    if (a.length() > 0) a += ",";
    a += "{\"mac\":\"" + String(bleSens[i].mac) + "\"";
    a += ",\"zero\":" + String((long)c.zero);
    a += ",\"per_kg\":" + String(c.perKg, 3);
    if (!(c.flags & WCAL_F_COMMON))
    {
      a += ",\"cal_raw\":" + String((long)c.calRaw);
      a += ",\"cal_g\":" + String((unsigned long)c.calG);
    }
    a += ",\"common\":" + String((c.flags & WCAL_F_COMMON) ? 1 : 0);
    a += ",\"ts\":" + String((unsigned long)c.ts) + "}";
    wcalSentTs[i] = c.ts;
    wcalSentHas[i] = true;
  }
  return a;
}

// 送れた較正を「送信済み」にする(送った後に較正し直したものは、次の送信でまた送る)
void wcalMarkSent()
{
  for (int i = 0; i < BLE_MAX_SENSORS; i++)
  {
    if (!wcalSentHas[i] || bleSens[i].sid == 0) continue;
    wcalSentHas[i] = false;
    WCal c;
    if (!wcalGet(String(bleSens[i].mac), c)) continue;
    if (c.ts != wcalSentTs[i] || !(c.flags & WCAL_F_PENDING)) continue;
    c.flags &= (uint8_t)~WCAL_F_PENDING;
    wcalPut(String(bleSens[i].mac), c);
  }
}

// ====================================================================
// 6. Wi-Fi接続・時刻
// ====================================================================

bool waitConnected(uint32_t ms)
{
  uint32_t t0 = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - t0 < ms)
  {
    delay(100);
    esp_task_wdt_reset();
  }
  return WiFi.status() == WL_CONNECTED;
}

void rememberAp()
{
  rtcChannel = (uint8_t)WiFi.channel();
  memcpy(rtcBssid, WiFi.BSSID(), 6);
  rtcChanValid = true;
}

// 保存済みSSIDへ接続。前回のチャンネル/BSSIDがあれば先にそれで高速接続を試す。
bool connectWifi(uint32_t totalMs)
{
  WiFi.setHostname(deviceID.c_str());
  WiFi.mode(WIFI_STA);
  WiFi.setAutoReconnect(true);

  uint32_t t0 = millis();
  if (rtcChanValid)
  {
    WiFi.begin(cfgSsid.c_str(), cfgPass.c_str(), rtcChannel, rtcBssid, true);
    uint32_t fast = (totalMs < WIFI_FAST_TIMEOUT_MS) ? totalMs : WIFI_FAST_TIMEOUT_MS;
    if (waitConnected(fast))
    {
      rememberAp();
      return true;
    }
    WiFi.disconnect();
    rtcChanValid = false;
    delay(100);
  }

  uint32_t used = millis() - t0;
  uint32_t left = (used < totalMs) ? (totalMs - used) : 3000;
  WiFi.begin(cfgSsid.c_str(), cfgPass.c_str());
  if (waitConnected(left))
  {
    rememberAp();
    return true;
  }
  return false;
}

volatile bool ntpSynced = false;

void onNtpSync(struct timeval* tv)
{
  (void)tv;
  ntpSynced = true;
}

void syncNtp(bool force)
{
  time_t now = time(nullptr);
  bool need = force || !timeValid() || rtcLastNtp == 0 ||
              (now - (time_t)rtcLastNtp) >= (time_t)NTP_RESYNC_S;
  if (!need) return;
  // NTPが通らない回線でも毎回待たないよう、失敗が続いたら8回に1回だけ試す
  if (!force && rtcNtpFail > 0 && (rtcBootCount % 8) != 0) return;

  ntpSynced = false;
  sntp_set_time_sync_notification_cb(onNtpSync);
  configTzTime("JST-9", "ntp.nict.jp", "pool.ntp.org");

  uint32_t t0 = millis();
  while (!ntpSynced && millis() - t0 < NTP_WAIT_MS)
  {
    delay(100);
    esp_task_wdt_reset();
  }

  if (ntpSynced)
  {
    rtcLastNtp = (int64_t)time(nullptr);
    rtcNtpFail = 0;
    Serial.println("NTP: synced");
  }
  else
  {
    if (rtcNtpFail < 200) rtcNtpFail++;
    Serial.println("NTP: timeout");
  }
}

// ====================================================================
// 7. データ送信・遠隔OTA
// ====================================================================

int postJson(const String& url, const String& body)
{
  HTTPClient http;
  http.setConnectTimeout(8000);
  http.setTimeout(8000);
  int code = -1;

  if (url.startsWith("https://"))
  {
    WiFiClientSecure client;
    if (kServerCa != nullptr) client.setCACert(kServerCa);
    else client.setInsecure();
    if (http.begin(client, url))
    {
      http.addHeader("Content-Type", "application/json");
      if (serverToken.length() > 0) http.addHeader("Authorization", "Bearer " + serverToken);
      code = http.POST(body);
      http.end();
    }
  }
  else
  {
    WiFiClient client;
    if (http.begin(client, url))
    {
      http.addHeader("Content-Type", "application/json");
      if (serverToken.length() > 0) http.addHeader("Authorization", "Bearer " + serverToken);
      code = http.POST(body);
      http.end();
    }
  }
  return code;
}

bool sendReport(float vbat, const String& sensorsJson)
{
  String j;
  j.reserve(360);
  j += "{\"id\":\"" + deviceUid + "\"";
  j += ",\"name\":\"" + deviceID + "\"";
  j += ",\"fw\":\"" + String(FW_VERSION) + "\"";
  j += ",\"build\":" + String(FW_BUILD);
  j += ",\"boot\":" + String((unsigned long)rtcBootCount);
  j += ",\"wake\":\"" + String(timerWake ? "timer" : resetReasonStr()) + "\"";
  j += ",\"rssi\":" + String((int)WiFi.RSSI());
  if (!isnan(vbat)) j += ",\"vbat\":" + String(vbat, 2);
  j += ",\"awake_ms\":" + String((unsigned long)millis());
  j += ",\"ts\":" + String((unsigned long)(timeValid() ? time(nullptr) : 0));
  j += ",\"sensors\":" + sensorsJson;
  const String wc = wcalJson();
  if (wc.length() > 0) j += ",\"wcal\":[" + wc + "]";
  j += "}";

  if (serverUrl.length() == 0)
  {
    Serial.println("REPORT (no server): " + j);
    lastReportText = "送信先未設定";
    return true;
  }

  int code = postJson(serverUrl, j);
  bool ok = (code >= 200 && code < 300);
  lastReportText = ok ? ("成功 (HTTP " + String(code) + ")") : ("失敗 (" + String(code) + ")");
  Serial.println("REPORT -> " + lastReportText);
  return ok;
}

// マニフェスト(1行目:ビルド番号 2行目:binのURL)を取得
bool fetchManifest(int& build, String& binUrl)
{
  WiFiClientSecure client;
  if (kOtaCa != nullptr) client.setCACert(kOtaCa);
  else client.setInsecure();

  HTTPClient http;
  http.setConnectTimeout(8000);
  http.setTimeout(10000);
  http.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
  if (!http.begin(client, otaUrl)) return false;

  int code = http.GET();
  if (code != HTTP_CODE_OK)
  {
    Serial.printf("OTA: manifest GET failed (%d)\n", code);
    http.end();
    return false;
  }
  String body = http.getString();
  http.end();

  int nl = body.indexOf('\n');
  if (nl < 0) return false;
  String l1 = body.substring(0, nl);
  String l2 = body.substring(nl + 1);
  int nl2 = l2.indexOf('\n');
  if (nl2 >= 0) l2 = l2.substring(0, nl2);
  l1.trim();
  l2.trim();

  build = l1.toInt();
  binUrl = l2;
  if (build <= 0 || !binUrl.startsWith("https://"))
  {
    Serial.println("OTA: manifest format invalid");
    return false;
  }
  return true;
}

// マニフェストを確認できたら true(更新が始まって成功した場合は再起動するので戻らない)
bool checkHttpsOta()
{
  int build = 0;
  String binUrl;
  if (!fetchManifest(build, binUrl)) return false;
  Serial.printf("OTA: manifest build=%d, running=%d\n", build, FW_BUILD);
  if (build <= FW_BUILD) return true;

  Serial.println("OTA: downloading " + binUrl);
  otaBusy = true;
  esp_task_wdt_delete(NULL);  // ダウンロード中はWDT対象から外す

  WiFiClientSecure client;
  if (kOtaCa != nullptr) client.setCACert(kOtaCa);
  else client.setInsecure();
  httpUpdate.rebootOnUpdate(true);
  httpUpdate.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
  t_httpUpdate_return ret = httpUpdate.update(client, binUrl);

  // 成功すると再起動するので、ここに来るのは失敗のとき
  Serial.printf("OTA: result=%d (%s)\n", (int)ret, httpUpdate.getLastErrorString().c_str());
  esp_task_wdt_add(NULL);
  otaBusy = false;
  return true;
}

void maybeCheckOta(float vbat)
{
  if (otaUrl.length() == 0) return;
  if (!timeValid()) return;
  if (!isnan(vbat) && vbat < BATT_OTA_MIN_V) return;

  time_t now = time(nullptr);
  uint32_t intervalS = (powerMode == MODE_DEEPSLEEP) ? OTA_INTERVAL_BATT_S : OTA_INTERVAL_MAINS_S;
  if (rtcLastOta != 0 && (now - (time_t)rtcLastOta) < (time_t)intervalS) return;

  rtcLastOta = (int64_t)now;
  if (!checkHttpsOta())
  {
    // 取得に失敗したら1時間後に再試行
    rtcLastOta = (int64_t)now - (int64_t)intervalS + 3600;
  }
}

bool allowOtaNow()
{
  return !(maintenanceBoot && millis() < OTA_MIN_UPTIME_MS);
}

// 計測→送信→(必要なら)OTA確認 を1回行う。Wi-Fi接続済みであること。
bool runCycle(bool allowOta)
{
  if (WiFi.status() != WL_CONNECTED)
  {
    lastReportText = "Wi-Fi未接続";
    bleCollectIfDue(false);   // つながらなくても温湿度計の記録は取って溜めておく
    return false;
  }
  // 区切りに合わせて記録するときは、スリープ中の時計のずれを直すため、起きるたびにNTPで合わせる
  // (NTPが通らない回線では、これまで通り間引く)
  syncNtp(bleEnabled && BLE_ALIGN_TO_CLOCK && powerMode == MODE_DEEPSLEEP && rtcNtpFail == 0);
  float vbat = readBatteryV();
  bleCollectIfDue(false);   // BLEセンサ(保存間隔に達していれば)。送信より前に行い、最新の記録も一緒に送る
  // 溜まった記録は BLE_UPLOAD_CHUNK 件ずつ分けて送り、送れた分から「送信済み」にする。
  // 失敗したらそこで止め、残りは次の起床で送る。
  bool ok = false;
  const uint32_t upT0 = millis();
  for (;;)
  {
    String sensors = collectSensorsJson();
    ok = sendReport(vbat, sensors);
    esp_task_wdt_reset();
    if (!ok || serverUrl.length() == 0) break;
    wcalMarkSent();
    bleMarkSent();
    if (!blePendingMore) break;
    if (millis() - upT0 > BLE_UPLOAD_MAX_MS) break;
  }
  if (allowOta) maybeCheckOta(vbat);
  return ok;
}

// ====================================================================
// 8. スリープ
// ====================================================================

// 次の計測時刻までのスリープ秒数(起きていた時間を差し引く)
uint32_t normalSleepSec()
{
  uint32_t full = cycleSec();
  uint32_t awake = millis() / 1000 + 1;
  uint32_t s = (full > awake + 10) ? (full - awake) : 10;
  if (bleAlignedNow()) s = secToNextBoundary();   // 次の時計の区切り(毎時00分・30分など)まで眠る
  if (!bleEnabled && rtcFailStreak >= 6 && s < 1800) s = 1800;  // 失敗が続くときは間隔を空ける(BLE記録中は区切りごとに起きる)
  return s;
}

void goToSleep(uint32_t sec)
{
  if (sec < 10) sec = 10;
  Serial.printf("SLEEP %lu s (awake %lu ms)\n", (unsigned long)sec, (unsigned long)millis());
  Serial.flush();
  WiFi.disconnect(true, false);
  WiFi.mode(WIFI_OFF);
  esp_sleep_enable_timer_wakeup((uint64_t)sec * 1000000ULL);
  esp_deep_sleep_start();
}

// スリープ復帰時の動作: 計測して送信し、すぐ眠る(管理画面は出さない)
void measureOnlyBoot()
{
  float vbat = readBatteryV();
  if (!isnan(vbat) && vbat < BATT_CRIT_V)
  {
    Serial.println("Battery critical. Skipping.");
    goToSleep(BATT_CRIT_SLEEP_S);
  }

  // 通信できない状態が続いているときは、BLEの記録だけ毎回取り、Wi-Fiへの接続は4回に1回に間引く(電池節約)
  const bool skipWifi = bleEnabled && rtcFailStreak >= 6 && (rtcBootCount % 4) != 0;
  bool ok = skipWifi ? false : connectWifi(WIFI_MEASURE_TIMEOUT_MS);
  if (ok)
  {
    ok = runCycle(allowOtaNow());
    markValid();  // Wi-Fiに繋がったので新ファームは正常とみなす
  }
  else
  {
    lastReportText = skipWifi ? "Wi-Fi接続を間引き中(通信できない状態が続いています)" : "Wi-Fi接続失敗";
    Serial.println(skipWifi ? "WiFi skipped (offline streak)." : "WiFi connect failed.");
    WiFi.disconnect(true, false);
    WiFi.mode(WIFI_OFF);
    bleCollectIfDue(false);   // つながらなくても温湿度計の記録は取って溜めておく(次につながったときにまとめて送る)
  }

  if (ok)
  {
    rtcFailStreak = 0;
  }
  else if (rtcFailStreak < 250)
  {
    rtcFailStreak++;
  }
  rtcCrashCount = 0;
  goToSleep(normalSleepSec());
}

// ====================================================================
// 9. 設定用AP(キャプティブポータル)
// ====================================================================

// fallback=true: Wi-Fi設定はあるが接続できなかったとき
void startConfigAP(bool fallback)
{
  Serial.println(fallback ? "WiFi connection failed. Starting fallback SoftAP..."
                          : "No saved WiFi. Starting configuration SoftAP...");

  apMode = true;
  apFallback = fallback;
  apLastBusyMs = millis();

  if (mdnsStarted)
  {
    MDNS.end();
    mdnsStarted = false;
  }

  WiFi.disconnect(true);
  delay(100);
  WiFi.mode(WIFI_AP);
  delay(100);
  ensureApPass();

  String apSsid = "HPX-Setup-" + deviceUid.substring(8);
  bool apStarted = WiFi.softAP(apSsid.c_str(), apPass.c_str());

  if (apStarted)
  {
    Serial.println("Config AP started");
    Serial.println("AP SSID     = " + apSsid);
    Serial.println("AP PASSWORD = " + apPass);
    Serial.print("AP IP       = ");
    Serial.println(WiFi.softAPIP());
  }
  else
  {
    Serial.println("Config AP start failed");
  }

  dnsServer.start(53, "*", WiFi.softAPIP());

  server.on("/", HTTP_GET, handleSetupPage);
  server.on("/save", HTTP_POST, handleSetupSave);
  server.onNotFound(handleCaptive);
  server.begin();
  Serial.println("WebServer started (setup)");
}

void apLoop()
{
  dnsServer.processNextRequest();
  server.handleClient();
  const uint32_t now = millis();  // handleClient() の後に取得する

  if (WiFi.softAPgetStationNum() > 0) apLastBusyMs = now;

  uint32_t timeout = apFallback ? AP_FALLBACK_TIMEOUT_MS : AP_SETUP_TIMEOUT_MS;
  if (now - apLastBusyMs > timeout)
  {
    if (powerMode == MODE_DEEPSLEEP)
    {
      goToSleep(apFallback ? normalSleepSec() : AP_SETUP_SLEEP_S);
    }
    else if (apFallback)
    {
      ESP.restart();  // 元のWi-Fiへもう一度つなぎに行く
    }
    else
    {
      apLastBusyMs = now;  // 未設定の常時稼働機は待ち続ける
    }
  }
}

// ====================================================================
// 10. Webページ共通
// ====================================================================

String pageHead(const String& title)
{
  String h;
  h += "<!DOCTYPE html><html lang='ja'><head>";
  h += "<meta charset='UTF-8'>";
  h += "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
  h += "<title>" + title + "</title></head><body>";
  return h;
}

void sendHtml(const String& body)
{
  server.send(200, "text/html; charset=utf-8", body);
}

void sendText(int code, const String& msg)
{
  server.send(code, "text/plain; charset=utf-8", msg);
}

void redirectTo(const char* path)
{
  server.sendHeader("Location", path, true);
  server.send(303, "text/plain", "");
}

// 管理画面の認証。管理パスワード未設定(v1.01からの移行直後)は通す。
bool requireAuth()
{
  touch();
  if (adminPass.length() == 0) return true;
  if (server.authenticate("admin", adminPass.c_str())) return true;
  server.requestAuthentication(BASIC_AUTH, "HPX");
  return false;
}

String modeSelectHtml(uint8_t current)
{
  String h;
  h += "<select name='mode'>";
  h += "<option value='1'";
  if (current == MODE_DEEPSLEEP) h += " selected";
  h += ">ディープスリープ(バッテリー)</option>";
  h += "<option value='0'";
  if (current == MODE_ALWAYS_ON) h += " selected";
  h += ">常時稼働(商用電源)</option>";
  h += "</select>";
  return h;
}

// ====================================================================
// 11. 初回Wi-Fi設定(設定用AP)
// ====================================================================

void handleSetupPage()
{
  touch();
  String h = pageHead("Wi-Fi設定");
  h += "<h1>Wi-Fi設定</h1>";
  h += "<p>機器ID: <b>" + htmlEscape(deviceID) + "</b></p>";
  h += "<form action='/save' method='POST'>";
  h += "<label for='ssid'>SSID (LTEルーターなど)</label><br>";
  h += "<input type='text' id='ssid' name='ssid' maxlength='32' value='" + htmlEscape(cfgSsid) + "' required><br><br>";
  h += "<label for='password'>Wi-Fiパスワード</label><br>";
  h += "<input type='password' id='password' name='password' maxlength='63'><br><br>";
  h += "<label for='adminpw'>管理画面のパスワード(6〜32文字。ユーザー名は admin)</label><br>";
  h += "<input type='password' id='adminpw' name='adminpw' maxlength='32'";
  if (adminPass.length() == 0) h += " required";
  h += "><br>";
  if (adminPass.length() > 0) h += "<small>変更しない場合は空欄のまま</small><br>";
  h += "<br><label>動作モード</label><br>" + modeSelectHtml(powerMode) + "<br><br>";
  h += "<input type='submit' value='保存'>";
  h += "</form></body></html>";
  sendHtml(h);
}

void handleSetupSave()
{
  touch();
  Serial.println("SAVE WIFI CALLED");

  String ssid = server.arg("ssid");
  String pass = server.arg("password");
  String apw = server.arg("adminpw");
  ssid.trim();

  if (ssid.length() == 0 || ssid.length() > 32)
  {
    sendText(400, "SSIDは1〜32文字で入力してください。");
    return;
  }
  if (pass.length() != 0 && (pass.length() < 8 || pass.length() > 63))
  {
    sendText(400, "Wi-Fiパスワードは8〜63文字(または空欄)にしてください。");
    return;
  }
  if (adminPass.length() == 0 && apw.length() == 0)
  {
    sendText(400, "管理画面のパスワードを入力してください。");
    return;
  }
  if (apw.length() != 0 && (apw.length() < 6 || apw.length() > 32))
  {
    sendText(400, "管理画面のパスワードは6〜32文字にしてください。");
    return;
  }

  uint8_t mode = (server.arg("mode").toInt() == MODE_ALWAYS_ON) ? MODE_ALWAYS_ON : MODE_DEEPSLEEP;

  Serial.print("SSID = ");
  Serial.println(ssid);
  Serial.print("Password length = ");
  Serial.println(pass.length());

  preferences.begin("wifi-config", false);
  preferences.putString("ssid", ssid);
  preferences.putString("password", pass);
  if (apw.length() > 0) preferences.putString("adminpw", apw);
  preferences.putUChar("mode", mode);
  preferences.end();

  String h = pageHead("Wi-Fi設定保存");
  h += "<h1>設定を保存しました</h1>";
  h += "<p>SSID：" + htmlEscape(ssid) + "</p>";
  h += "<p>HPX機器を再起動します。</p>";
  h += "<p>スマートフォンまたはPCを設定したWi-Fiへ戻し、管理画面を開いてください。</p>";
  h += "<p><a href='http://" + htmlEscape(mdnsHost) + ".local/'>http://" + htmlEscape(mdnsHost) + ".local/</a></p>";
  h += "<p><small>.local が開けない端末(Androidなど)は、ルーターの接続機器一覧で機器名 ";
  h += htmlEscape(deviceID) + " のIPアドレスを確認してください。</small></p>";
  h += "</body></html>";
  sendHtml(h);
  delay(1500);
  ESP.restart();
}

// 設定画面以外のURL(OSの接続確認など)は設定画面へ誘導する
void handleCaptive()
{
  touch();
  server.sendHeader("Location", "http://" + WiFi.softAPIP().toString() + "/", true);
  server.send(302, "text/plain", "");
}

// ====================================================================
// 12. メイン管理画面
// ====================================================================

void handleMainPage()
{
  if (!requireAuth()) return;

  String h = pageHead("HPX管理画面");
  h += "<h1>HPX管理画面</h1>";

  if (adminPass.length() == 0)
  {
    h += "<p style='color:red;'><b>管理パスワードが未設定です。";
    h += "<a href='/settings'>設定</a>から必ず設定してください。</b></p>";
  }

  h += "<p><b>機器ID : " + htmlEscape(deviceID) + "</b></p>";
  h += "<p>固有ID(MAC) : " + deviceUid + "</p>";
  h += "<p><b>Firmware Version: " + String(FW_VERSION) + "</b></p>";

  if (powerMode == MODE_DEEPSLEEP)
  {
    h += "<p>動作モード: ディープスリープ(バッテリー)、" + String(intervalMin) + "分ごとに計測・送信</p>";
  }
  else
  {
    h += "<p>動作モード: 常時稼働(商用電源)、" + String(intervalMin) + "分ごとに計測・送信</p>";
  }

  h += "<p>現在時刻: " + nowText() + "</p>";
  h += "<p>Wi-Fi接続成功</p>";
  h += "<p>信号受信強度: " + String((int)WiFi.RSSI());
  h += " dBm（不安定 -75以下、実用レベル -75～-66、良好 -65以上）</p>";
  h += "<p>SSID: " + htmlEscape(WiFi.SSID()) + "</p>";
  h += "<p>IP: " + WiFi.localIP().toString() + "</p>";

  float v = readBatteryV();
  if (isnan(v)) h += "<p>電池電圧: 未計測(BATT_ADC_PIN未設定)</p>";
  else h += "<p>電池電圧: " + String(v, 2) + " V</p>";

  h += "<p>最後の送信: " + htmlEscape(lastReportText) + "</p>";
  if (bleEnabled) h += "<p>BLEセンサ: " + htmlEscape(bleStatusText) + "</p>";

  h += "<p>管理画面: ";
  if (mdnsStarted)
  {
    String mdnsUrl = "http://" + mdnsHost + ".local/";
    h += "<a href='" + mdnsUrl + "'>" + mdnsUrl + "</a>";
  }
  else
  {
    h += "mDNS起動失敗";
  }
  h += "</p>";

  if (apPass.length() >= 8)
  {
    h += "<p>設定用Wi-Fi: HPX-Setup-" + deviceUid.substring(8) + " / パスワード " + htmlEscape(apPass) + "</p>";
  }

  h += "<form action='/runnow' method='POST' style='display:inline'>";
  h += "<button type='submit'>今すぐ計測・送信</button></form> ";
  if (powerMode == MODE_DEEPSLEEP)
  {
    h += "<form action='/sleepnow' method='POST' style='display:inline'>";
    h += "<button type='submit'>保守を終了してスリープ</button></form>";
    h += "<p><small>操作がなければ約10分で自動的にスリープに戻ります。</small></p>";
  }

  h += "<p><a href='/settings'>動作設定(モード・間隔・送信先・BLEセンサ・パスワード)</a></p>";
  if (bleEnabled) h += "<p><a href='/blesensors'>温湿度計の登録</a> / <a href='/blelog'>BLEセンサの記録</a> / <a href='/wcal'>重量センサの較正</a></p>";
  h += "<p><a href='/deviceid'>機器ID変更</a></p>";
  h += "<p><a href='/update'>ファームウェア更新</a></p>";
  h += "<p><a href='/wifi-reset'>Wi-Fi再設定</a></p>";
  h += "<p><a href='/factory-reset-page'>工場出荷状態へ戻す</a></p>";
  h += "</body></html>";
  sendHtml(h);
}

void handleRunNow()
{
  if (!requireAuth()) return;
  runCycle(false);
  redirectTo("/");
}

void handleSleepNow()
{
  if (!requireAuth()) return;
  if (powerMode != MODE_DEEPSLEEP)
  {
    redirectTo("/");
    return;
  }
  String h = pageHead("スリープ");
  h += "<h1>スリープに入ります</h1>";
  h += "<p>" + String(intervalMin) + "分ごとに自動で計測・送信します。</p>";
  h += "<p>もう一度保守するには、機器のRESETボタンを押してください。</p>";
  h += "</body></html>";
  sendHtml(h);
  sleepRequested = true;
}

// ====================================================================
// 13. 動作設定
// ====================================================================

void handleSettingsPage()
{
  if (!requireAuth()) return;

  String h = pageHead("動作設定");
  h += "<h1>動作設定</h1>";
  h += "<form action='/savesettings' method='POST'>";
  h += "<label>動作モード</label><br>" + modeSelectHtml(powerMode) + "<br><br>";
  h += "<label for='interval'>計測・送信の間隔(分、1〜1440)</label><br>";
  h += "<input type='number' id='interval' name='interval' min='1' max='1440' value='" + String(intervalMin) + "'><br>";
  h += "<small>バッテリー運用では5分以上を推奨します。</small><br><br>";
  h += "<label for='surl'>データ送信先URL (http:// または https://、空欄なら送信しない)</label><br>";
  h += "<input type='text' id='surl' name='surl' maxlength='200' size='50' value='" + htmlEscape(serverUrl) + "'><br><br>";
  h += "<label for='stoken'>送信先のトークン(Bearer、不要なら空欄)</label><br>";
  h += "<input type='text' id='stoken' name='stoken' maxlength='200' size='50' value='" + htmlEscape(serverToken) + "'><br><br>";
  h += "<label for='murl'>ファーム更新マニフェストのURL (https://、空欄なら遠隔更新しない)</label><br>";
  h += "<input type='text' id='murl' name='murl' maxlength='200' size='50' value='" + htmlEscape(otaUrl) + "'><br><br>";
  h += "<hr><h3>BLEセンサ (温湿度：HPX-TH・Inkbird IBS-TH2 / TH2 PLUS・SwitchBot 温湿度計 / 防水温湿度計、重量：HPX-W、電柵アラート：HPX-A)</h3>";
  h += "<label><input type='checkbox' name='bleon' value='1'" + String(bleEnabled ? " checked" : "") + "> センサのデータを受信して保存する</label><br><br>";
  h += "<label for='bleiv'>保存間隔</label><br><select id='bleiv' name='bleiv'>";
  const uint16_t ivs[3] = {15, 30, 60};
  const char* ivn[3] = {"15分", "30分", "1時間"};
  for (int i = 0; i < 3; i++)
  {
    h += "<option value='" + String(ivs[i]) + "'" + String(bleIntervalMin == ivs[i] ? " selected" : "") + ">" + ivn[i] + "</option>";
  }
  h += "</select><br><br>";
  h += "<p>登録済みのセンサ: " + String((int)bleSensCount()) + " / " + String((int)BLE_MAX_SENSORS) + " 台 ";
  h += "<a href='/blesensors'>センサの登録・名前の変更</a></p>";
  h += "<small>BLE取得をオンにすると、計測の周期は「送信間隔」と「保存間隔」の短いほうになります(送信間隔を保存間隔以下にすると電池に優しい)。記録は時計の区切り(30分なら毎時00分・30分)に取ります。送信間隔は保存間隔を割り切れる値(5・10・15・30分など)にしてください。</small><br><br>";
  h += "<hr>";
  h += "<label for='adminpw'>管理画面のパスワード(6〜32文字。変更しない場合は空欄)</label><br>";
  h += "<input type='password' id='adminpw' name='adminpw' maxlength='32'><br><br>";
  h += "<input type='submit' value='保存して再起動'></form><br>";
  h += "<a href='/'>管理画面へ戻る</a>";
  h += "</body></html>";
  sendHtml(h);
}

void handleSettingsSave()
{
  if (!requireAuth()) return;
  Serial.println("SAVE SETTINGS CALLED");

  uint8_t mode = (server.arg("mode").toInt() == MODE_ALWAYS_ON) ? MODE_ALWAYS_ON : MODE_DEEPSLEEP;
  int interval = server.arg("interval").toInt();
  if (interval < 1) interval = 1;
  if (interval > 1440) interval = 1440;

  String surl = server.arg("surl");
  String stoken = server.arg("stoken");
  String murl = server.arg("murl");
  String apw = server.arg("adminpw");
  surl.trim();
  stoken.trim();
  murl.trim();

  if (!validUrl(surl, false))
  {
    sendText(400, "送信先URLは http:// か https:// で始まる200文字以内にしてください。");
    return;
  }
  if (!validUrl(murl, true))
  {
    sendText(400, "更新マニフェストのURLは https:// で始まる200文字以内にしてください。");
    return;
  }
  if (stoken.length() > 200)
  {
    sendText(400, "トークンは200文字以内にしてください。");
    return;
  }
  if (apw.length() != 0 && (apw.length() < 6 || apw.length() > 32))
  {
    sendText(400, "管理画面のパスワードは6〜32文字にしてください。");
    return;
  }

  const bool bleOnNew = server.hasArg("bleon");
  int bleIv = server.arg("bleiv").toInt();
  if (bleIv != 15 && bleIv != 30 && bleIv != 60) bleIv = BLE_DEFAULT_INTERVAL_MIN;

  preferences.begin("wifi-config", false);
  preferences.putUChar("mode", mode);
  preferences.putUChar("bleon", bleOnNew ? 1 : 0);
  preferences.putUShort("bleiv", (uint16_t)bleIv);
  preferences.putUShort("intv", (uint16_t)interval);
  preferences.putString("surl", surl);
  preferences.putString("stoken", stoken);
  preferences.putString("murl", murl);
  if (apw.length() > 0) preferences.putString("adminpw", apw);
  preferences.end();

  String h = pageHead("設定保存");
  h += "<h1>設定を保存しました</h1>";
  h += "<p>HPX機器は約2秒後に再起動します。</p>";
  if (apw.length() > 0) h += "<p>管理パスワードを変更したので、再起動後は新しいパスワードで入ってください。</p>";
  h += "<p><a href='/'>管理画面へ</a></p>";
  h += "</body></html>";
  sendHtml(h);
  delay(1500);
  ESP.restart();
}


// ====================================================================
// 13b. BLEセンサの登録と記録
// ====================================================================

String bleTsText(uint32_t ts)
{
  if (ts < 100000) return "時刻不明";
  time_t t = (time_t)ts;
  struct tm tmv;
  localtime_r(&t, &tmv);
  char buf[24];
  strftime(buf, sizeof(buf), "%Y-%m-%d %H:%M", &tmv);
  return String(buf);
}

// 登録画面(登録済み一覧と、周辺検索ボタン)
void handleBleSensors()
{
  if (!requireAuth()) return;

  String h = pageHead("温湿度計の登録");
  h += "<h1>センサの登録</h1>";
  h += "<p>登録済み: " + String((int)bleSensCount()) + " / " + String((int)BLE_MAX_SENSORS) + " 台";
  h += "(名前は全角" + String((int)(BLE_NAME_MAX / 3)) + "文字まで)</p>";
  if (!bleEnabled)
  {
    h += "<p style='color:red'>BLEセンサの受信がオフです。<a href='/settings'>動作設定</a>でオンにしてください。</p>";
  }

  h += "<table border='1' cellpadding='4' style='border-collapse:collapse'>";
  h += "<tr><th>名前</th><th>Bluetoothアドレス</th><th>操作</th></tr>";
  for (int i = 0; i < BLE_MAX_SENSORS; i++)
  {
    if (bleSens[i].sid == 0) continue;
    String mac = String(bleSens[i].mac);
    h += "<tr><td><form action='/bleadd' method='POST' style='margin:0'>";
    h += "<input type='hidden' name='mac' value='" + mac + "'>";
    h += "<input type='text' name='name' maxlength='" + String((int)(BLE_NAME_MAX / 3)) + "' size='16' value='" + htmlEscape(String(bleSens[i].name)) + "'> ";
    h += "<button type='submit'>名前を変更</button></form></td>";
    h += "<td>" + mac + "</td>";
    h += "<td><form action='/bledel' method='POST' style='margin:0' onsubmit=\"return confirm('この温湿度計の登録を外しますか?(保存済みの記録は残りますが、サーバーへは送られなくなります)');\">";
    h += "<input type='hidden' name='mac' value='" + mac + "'><button type='submit'>登録を外す</button></form></td></tr>";
  }
  if (bleSensCount() == 0) h += "<tr><td colspan='3'>(まだ登録がありません)</td></tr>";
  h += "</table><br>";

  h += "<form action='/blescan' method='POST'><button type='submit'>周辺のセンサを探す(約" + String((int)(BLE_DISC_MS / 1000)) + "秒)</button></form>";
  h += "<p><small>探す間は管理画面が止まります。センサを機器の近くに置いて実行してください。";
  h += "一覧に出たセンサに名前を付けて登録します。複数あるときは、温度の値と電波の強さ(RSSI)で見分けてください。";
  h += "手で温めると、そのセンサの温度が上がります。</small></p>";
  h += "<p><a href='/blelog'>各種BLEセンサの記録</a></p>";
  h += "<p><a href='/'>管理画面へ戻る</a></p>";
  h += "</body></html>";
  sendHtml(h);
}

// 周辺検索の実行と結果表示
void handleBleScan()
{
  if (!requireAuth()) return;

  uint8_t n = bleDiscover(BLE_DISC_MS);

  String h = pageHead("周辺の温湿度計");
  h += "<h1>周辺の温湿度計</h1>";
  if (n == 0)
  {
    h += "<p>温湿度計が見つかりませんでした。電源・電池と、機器との距離を確認してください。</p>";
  }
  else
  {
    h += "<table border='1' cellpadding='4' style='border-collapse:collapse'>";
    h += "<tr><th>Bluetoothアドレス</th><th>温度(℃)</th><th>湿度(%)</th><th>RSSI(dBm)</th><th>登録</th></tr>";
    for (int i = 0; i < (int)n; i++)
    {
      String mac = String(bleDisc[i].mac);
      const uint8_t kd = bleDisc[i].kind;
      h += "<tr><td>" + mac + (kd == 1 ? String(" <b>[重量センサ]</b>") : kd == 2 ? String(" <b>[電柵アラート]</b>") : kd == 3 ? String(" <b>[SwitchBot 温湿度計]</b>") : kd == 4 ? String(" <b>[SwitchBot 防水温湿度計]</b>") : kd == 5 ? String(" <b>[温湿度センサ HPX-TH]</b>") : String("")) + "</td><td>"
           + (bleDisc[i].t100 == BLE_NO_TEMP && kd == 5 ? String("-") : String(bleDisc[i].t100 / 100.0f, 1))
           + (kd == 5 && bleDisc[i].raw != BLE_NO_TEMP ? String("<br><small>サーミスター " + String(bleDisc[i].raw / 100.0f, 1) + "</small>") : String("")) + "</td>";
      if (kd == 1) h += "<td>生の値 " + String((long)bleDisc[i].raw) + "</td>";
      else if (kd == 2) h += "<td>" + String(bleAlrText(bleAlrState(bleDisc[i].raw))) + "</td>";
      else h += "<td>" + String((bleDisc[i].flags & BLE_F_HUM) ? String(bleDisc[i].h100 / 100.0f, 1) : String("-")) + "</td>";
      h += "<td>" + String((int)bleDisc[i].rssi) + "</td><td>";
      int idx = bleSensIndexByMac(mac.c_str());
      if (idx >= 0)
      {
        h += "登録済み: " + htmlEscape(String(bleSens[idx].name));
      }
      else
      {
        h += "<form action='/bleadd' method='POST' style='margin:0'>";
        h += "<input type='hidden' name='mac' value='" + mac + "'>";
        h += "<input type='text' name='name' maxlength='" + String((int)(BLE_NAME_MAX / 3)) + "' size='14' placeholder='名前'> ";
        h += "<button type='submit'>登録</button></form>";
      }
      h += "</td></tr>";
    }
    h += "</table>";
    h += "<p><small>RSSIは0に近いほど(例 -50 は -80 より)近くにあります。</small></p>";
  }
  h += "<form action='/blescan' method='POST'><button type='submit'>もう一度探す</button></form>";
  h += "<p><a href='/blesensors'>登録画面へ戻る</a></p>";
  h += "</body></html>";
  sendHtml(h);
}

void handleBleAdd()
{
  if (!requireAuth()) return;
  String err;
  if (!bleSensAdd(server.arg("mac"), server.arg("name"), err))
  {
    sendText(400, err);
    return;
  }
  redirectTo("/blesensors");
}

void handleBleDel()
{
  if (!requireAuth()) return;
  String mac = server.arg("mac");
  bleNormalizeMac(mac);
  bleSensDel(mac);
  redirectTo("/blesensors");
}

void handleBleLog()
{
  if (!requireAuth()) return;

  BleBuf recsp = bleAlloc(BLE_VIEW_MAX);
  if (!recsp)
  {
    sendText(500, "メモリ不足です。しばらくしてからもう一度開いてください。");
    return;
  }
  const BleRec* recs = recsp.get();
  uint32_t n = bleLogTail(recsp.get(), BLE_VIEW_MAX);
  uint32_t total = bleLogCount();

  String h = pageHead("BLEセンサの記録");
  h.reserve(2048 + (size_t)n * 190);   // 行数分をあらかじめ確保(再確保の繰り返しを避ける)
  h += "<h1>各種BLEセンサの記録</h1>";
  h += "<p>状態: " + htmlEscape(bleStatusText) + "</p>";
  h += "<p>取得: " + String(bleEnabled ? "オン" : "オフ") + " / 保存間隔: " + String(bleIntervalMin) + "分 / ";
  h += "登録 " + String((int)bleSensCount()) + " 台 / ";
  h += "保存件数: " + String((unsigned long)total) + " (" + String((int)BLE_KEEP_DAYS) + "日分を保存。古いものは自動で整理)</p>";
  if (total > n) h += "<p>新しい " + String((unsigned long)n) + " 件を表示しています。全件は下のCSVで取得できます。</p>";

  h += "<table border='1' cellpadding='4' style='border-collapse:collapse'>";
  h += "<tr><th>#</th><th>時刻</th><th>温湿度計</th><th>温度(℃)</th><th>湿度(%) / 重量の生の値 / 電柵</th><th>電池(% / V)</th><th>RSSI</th></tr>";
  for (int i = (int)n - 1; i >= 0; i--)   // 新しいものから
  {
    const BleRec& r = recs[i];
    const char* nm = bleSensNameBySid(r.sid);
    h += "<tr><td>" + String((unsigned long)r.seq) + "</td><td>" + bleTsText(r.ts) + "</td>";
    h += "<td>" + (nm ? htmlEscape(String(nm)) : String("(登録なし)")) + "</td>";
    if (r.flags & BLE_F_THX)
    {
      h += "<td>" + (r.t100 != BLE_NO_TEMP ? String(r.t100 / 100.0f, 2) : String("-"));
      if (r.raw != BLE_NO_TEMP) h += "<br><small>サーミスター " + String(r.raw / 100.0f, 2) + "</small>";
      h += "</td>";
    }
    else
    {
      h += "<td>" + String(r.t100 / 100.0f, 2) + "</td>";
    }
    if (r.flags & BLE_F_WGT)
    {
      if (r.h100 == 0xFFFF) h += String("<td>測定停止(") + (r.raw == 1 ? "電池低下" : "読み取り失敗") + ")</td>";
      else h += "<td>生 " + String((long)r.raw) + " (ばらつき " + String((unsigned)r.h100) + ")</td>";
      h += "<td>" + String((r.flags & BLE_F_BAT) ? (String(2.0f + r.bat * 0.01f, 2) + "V") : String("-")) + "</td>";
    }
    else if (r.flags & BLE_F_ALR)
    {
      const uint8_t st = bleAlrState(r.raw);
      h += String("<td") + (st != 0 ? " style='color:red'" : "") + ">電柵 " + bleAlrText(st);
      h += " (低下 " + String((unsigned)bleAlrEvents(r.raw)) + "回";
      if (r.h100 != 0xFFFF) h += "、最後の点滅 " + String((unsigned)(r.h100 / 60)) + "分前";
      h += ")</td>";
      h += "<td>" + String((r.flags & BLE_F_BAT) ? (String(2.0f + r.bat * 0.01f, 2) + "V") : String("-")) + "</td>";
    }
    else if (r.flags & BLE_F_THX)
    {
      h += "<td>" + String((r.flags & BLE_F_HUM) ? String(r.h100 / 100.0f, 2) : String("-")) + "</td>";
      h += "<td>" + String((r.flags & BLE_F_BAT) ? (String(2.0f + r.bat * 0.01f, 2) + "V") : String("-")) + "</td>";
    }
    else
    {
      h += "<td>" + String((r.flags & BLE_F_HUM) ? String(r.h100 / 100.0f, 2) : String("-")) + "</td>";
      h += "<td>" + String((r.flags & BLE_F_BAT) ? String((int)r.bat) : String("-")) + "</td>";
    }
    h += "<td>" + String((int)r.rssi) + "</td></tr>";
  }
  h += "</table><br>";

  h += "<form action='/blenow' method='POST' style='display:inline'><button type='submit'>今すぐ受信して保存(最長約10秒)</button></form> ";
  h += "<form action='/bleclear' method='POST' style='display:inline' onsubmit=\"return confirm('保存した記録をすべて消しますか?(送信していない分も消えます)');\">";
  h += "<button type='submit'>記録を消去</button></form>";
  h += "<p><a href='/blelog.csv'>CSVで表示</a></p>";
  h += "<p><a href='/blesensors'>温湿度計の登録</a></p>";
  h += "<p><a href='/'>管理画面へ戻る</a></p>";
  h += "</body></html>";
  sendHtml(h);
}

// CSVは件数が多くなるので、少しずつ送る
void handleBleCsv()
{
  if (!requireAuth()) return;
  if (!bleFsBegin())
  {
    sendText(500, "フラッシュ(LittleFS)を使えません。");
    return;
  }
  File f = LittleFS.open(BLE_LOG_PATH, FILE_READ);

  server.setContentLength(CONTENT_LENGTH_UNKNOWN);
  server.send(200, "text/csv; charset=utf-8", "");
  server.sendContent("seq,time,sensor,temp_c,humidity_pct,battery_pct,rssi_dbm,raw,spread,fence_state,fence_events,fence_blink_ago_s,temp2_c\n");
  if (f)
  {
    String c;
    c.reserve(1400);
    BleRec r;
    while (f.read((uint8_t*)&r, sizeof(r)) == sizeof(r))
    {
      const char* nm = bleSensNameBySid(r.sid);
      const bool w = (r.flags & BLE_F_WGT) != 0;
      const bool al = (r.flags & BLE_F_ALR) != 0;
      const bool th = (r.flags & BLE_F_THX) != 0;
      const bool volt = w || al || th;   // 電池を電圧(V)で持つノード
      c += String((unsigned long)r.seq) + "," + bleTsText(r.ts) + "," + String(nm ? nm : "(登録なし)") + ","
         + ((th && r.t100 == BLE_NO_TEMP) ? String("") : String(r.t100 / 100.0f, 2)) + ",";
      c += String(((!w && !al) && (r.flags & BLE_F_HUM)) ? String(r.h100 / 100.0f, 2) : String("")) + ",";
      c += String(volt ? ((r.flags & BLE_F_BAT) ? (String(2.0f + r.bat * 0.01f, 2) + "V") : String("")) : ((r.flags & BLE_F_BAT) ? String((int)r.bat) : String(""))) + ",";
      c += String((int)r.rssi) + ",";
      c += String(w ? String((long)r.raw) : String("")) + "," + String(w ? String((unsigned)r.h100) : String("")) + ",";
      c += String(al ? String((int)bleAlrState(r.raw)) : String("")) + "," + String(al ? String((unsigned)bleAlrEvents(r.raw)) : String("")) + ",";
      c += String((al && r.h100 != 0xFFFF) ? String((unsigned)r.h100) : String("")) + ",";
      c += String((th && r.raw != BLE_NO_TEMP) ? String(r.raw / 100.0f, 2) : String("")) + "\n";
      if (c.length() > 1200)
      {
        server.sendContent(c);
        c = "";
        esp_task_wdt_reset();
      }
    }
    if (c.length() > 0) server.sendContent(c);
    f.close();
  }
  server.sendContent("");   // 終わり
}

void handleBleNow()
{
  if (!requireAuth()) return;
  if (bleEnabled) bleCollectIfDue(true);
  redirectTo("/blelog");
}

void handleBleClear()
{
  if (!requireAuth()) return;
  bleLogClear();
  bleStatusText = "記録を消去しました";
  redirectTo("/blelog");
}

// ====================================================================
// 13b. 重量センサの較正
//   重量センサ(HPX-WeightNode v1.01 以降)は、電池を入れて(リセットして)から10分間だけ2秒ごとに測る。
//   その間に、何も載せずに「ゼロ点を取る」→ 重りを載せて「較正する」。押すと WCAL_COLLECT_MS の間、値を集めて平均する。
//   較正の結果は本体に保存し、サーバーへ送る(kg への換算はサーバーで行う)。
// ====================================================================

static const char* const WCAL_WAIT_JS =
  "<script>function wcalWait(f){var b=document.querySelectorAll('button');for(var i=0;i<b.length;i++)b[i].disabled=true;"
  "var m=document.getElementById('wait');if(m)m.style.display='block';return true;}</script>";

String wcalStatusText(const WCal& c)
{
  if (!(c.flags & WCAL_F_ZERO) && !(c.flags & WCAL_F_SPAN)) return "まだです";
  String t;
  t += (c.flags & WCAL_F_ZERO) ? "ゼロ点 " + String((long)c.zero) : String("ゼロ点 未");
  t += " / 1kgあたり ";
  t += (c.flags & WCAL_F_SPAN) ? String(c.perKg, 1) + ((c.flags & WCAL_F_COMMON) ? " (共通の値)" : " (" + String((unsigned long)c.calG) + "g で較正)") : String("未");
  return t;
}

// 較正済み(共通の値を使ったものを除く)の「1kgあたりの値」の平均。台数を n に入れる
float wcalAverage(int& n)
{
  n = 0;
  double sum = 0;
  for (int i = 0; i < BLE_MAX_SENSORS; i++)
  {
    if (bleSens[i].sid == 0) continue;
    WCal c;
    if (!wcalGet(String(bleSens[i].mac), c)) continue;
    if (!(c.flags & WCAL_F_SPAN) || (c.flags & WCAL_F_COMMON) || c.perKg == 0) continue;
    sum += c.perKg;
    n++;
  }
  return n > 0 ? (float)(sum / n) : 0.0f;
}

// 一覧(重量センサと、全台共通の値)
void handleWcalList()
{
  if (!requireAuth()) return;
  uint8_t kinds[BLE_MAX_SENSORS];
  bleKindsFromLog(kinds);

  String h = pageHead("重量センサの較正");
  h += "<h1>重量センサの較正</h1>";
  h += "<table border='1' cellpadding='4' style='border-collapse:collapse'>";
  h += "<tr><th>名前</th><th>較正</th><th>サーバー</th><th></th></tr>";
  int shown = 0;
  for (int i = 0; i < BLE_MAX_SENSORS; i++)
  {
    if (bleSens[i].sid == 0) continue;
    if (kinds[i] != 1 && kinds[i] != 0xFF) continue;   // 温湿度計・電柵アラートは出さない
    const String mac = String(bleSens[i].mac);
    WCal c;
    wcalGet(mac, c);
    h += "<tr><td>" + htmlEscape(String(bleSens[i].name)) + "<br><small>" + mac + (kinds[i] == 0xFF ? " (まだ記録がありません)" : "") + "</small></td>";
    h += "<td>" + wcalStatusText(c) + "</td>";
    h += "<td>" + String((c.flags & WCAL_F_PENDING) ? "送信待ち" : (c.flags & WCAL_F_SPAN) ? "送信済み" : "-") + "</td>";
    h += "<td><a href='/wcalsensor?mac=" + mac + "'>較正する</a></td></tr>";
    shown++;
  }
  if (shown == 0) h += "<tr><td colspan='4'>重量センサが登録されていません。<a href='/blesensors'>センサの登録</a>から登録してください。</td></tr>";
  h += "</table>";

  // 全台共通の値
  const float common = wcalCommon();
  int n = 0;
  const float avg = wcalAverage(n);
  h += "<h2>全台共通の「1kgあたりの値」</h2>";
  h += "<p>重りを使わずに、ゼロ点とこの値だけで較正できます。今の値: <b>" + (common > 0 ? String(common, 1) : String("未設定")) + "</b></p>";
  if (n > 0)
  {
    h += "<form action='/wcalcommon' method='POST'><input type='hidden' name='action' value='avg'>";
    h += "重りで較正した " + String(n) + " 台の平均: " + String(avg, 1) + " ";
    h += "<button type='submit'>平均を共通の値にする</button></form>";
  }
  h += "<form action='/wcalcommon' method='POST'><input type='hidden' name='action' value='set'>";
  h += "値を入れる: <input type='number' name='value' step='0.1' min='1' size='10'> <button type='submit'>保存</button></form>";
  if (common > 0) h += "<form action='/wcalcommon' method='POST'><input type='hidden' name='action' value='clear'><button type='submit'>共通の値を消す</button></form>";
  h += "<p><small>ロードセルの個体差があるので、共通の値では数%ずれることがあります。正確に測りたい巣箱は、重りで較正してください。</small></p>";
  h += "<p><a href='/'>管理画面へ戻る</a></p></body></html>";
  sendHtml(h);
}

void wcalSensorPage(const String& mac, const String& msgHtml)
{
  const int idx = bleSensIndexByMac(mac.c_str());
  if (idx < 0)
  {
    sendText(404, "登録されていないセンサです");
    return;
  }
  WCal c;
  wcalGet(mac, c);
  const float common = wcalCommon();

  String h = pageHead("重量センサの較正");
  h += WCAL_WAIT_JS;
  h += "<h1>重量センサの較正: " + htmlEscape(String(bleSens[idx].name)) + "</h1>";
  h += "<p><small>" + mac + "</small></p>";
  if (msgHtml.length() > 0) h += "<div style='border:1px solid #888;padding:6px 10px;margin:8px 0'>" + msgHtml + "</div>";
  h += "<p>今の較正: " + wcalStatusText(c) + (c.flags & WCAL_F_PENDING ? " (サーバーへ送信待ち)" : "") + "</p>";
  h += "<div id='wait' style='display:none;color:#c00;font-weight:bold;font-size:1.2em'>測定中です。10秒お待ちください…</div>";

  h += "<ol>";
  h += "<li>重量センサの電池を入れ直す(またはリセットボタンを押す)。そこから10分間、2秒ごとに測ります。</li>";
  h += "<li><form action='/wcalmeasure' method='POST' onsubmit='return wcalWait(this)'><input type='hidden' name='mac' value='" + mac + "'>";
  h += "<input type='hidden' name='step' value='zero'>何も載せずに <button type='submit'>ゼロ点を取る</button></form></li>";
  h += "<li><form action='/wcalmeasure' method='POST' onsubmit='return wcalWait(this)'><input type='hidden' name='mac' value='" + mac + "'>";
  h += "<input type='hidden' name='step' value='span'>重りを真ん中に載せ、揺れが止まってから、重さ ";
  h += "<input type='number' name='g' min='100' step='1' size='7' value='" + String(c.calG > 0 ? (unsigned long)c.calG : 2000UL) + "' required> g を入れて ";
  h += "<button type='submit'>較正する</button></form>";
  if (common > 0)
  {
    h += "<form action='/wcalmeasure' method='POST' onsubmit='return wcalWait(this)'><input type='hidden' name='mac' value='" + mac + "'>";
    h += "<input type='hidden' name='step' value='common'>重りが無ければ <button type='submit'>共通の値 (" + String(common, 1) + ") を使う</button></form>";
  }
  h += "</li>";
  h += "<li><form action='/wcalmeasure' method='POST' onsubmit='return wcalWait(this)'><input type='hidden' name='mac' value='" + mac + "'>";
  h += "<input type='hidden' name='step' value='check'>確かめる: <button type='submit'>今の重さを測る</button></form></li>";
  h += "</ol>";
  h += "<p><small>較正すると本体に保存し、すぐにサーバーへ送ります(ダッシュボードの kg に反映されます)。";
  h += "巣箱を載せたまま台を付け直したときは、ゼロ点を取り直さず、ダッシュボードの「ゼロ点だけ直す」を使ってください。</small></p>";
  h += "<p><a href='/wcal'>一覧へ戻る</a> / <a href='/'>管理画面へ戻る</a></p></body></html>";
  sendHtml(h);
}

void handleWcalSensor()
{
  if (!requireAuth()) return;
  String mac = server.arg("mac");
  bleNormalizeMac(mac);
  wcalSensorPage(mac, "");
}

// 測る(step=zero/span/check)か、共通の値を使う(step=common)
void handleWcalMeasure()
{
  if (!requireAuth()) return;
  String mac = server.arg("mac");
  bleNormalizeMac(mac);
  const String step = server.arg("step");
  if (bleSensIndexByMac(mac.c_str()) < 0)
  {
    sendText(404, "登録されていないセンサです");
    return;
  }
  WCal c;
  wcalGet(mac, c);
  const uint32_t ts = timeValid() ? (uint32_t)time(nullptr) : (uint32_t)(millis() / 1000 + 1);   // 送信済みの判定にも使う

  if (step == "common")
  {
    const float common = wcalCommon();
    if (!(c.flags & WCAL_F_ZERO)) { wcalSensorPage(mac, "<b style='color:#c00'>先に「ゼロ点を取る」をしてください。</b>"); return; }
    if (common <= 0) { wcalSensorPage(mac, "<b style='color:#c00'>共通の値が設定されていません。</b>"); return; }
    c.perKg = common;
    c.calRaw = 0;
    c.calG = 0;
    c.flags |= WCAL_F_SPAN | WCAL_F_COMMON | WCAL_F_PENDING;
    c.ts = ts;
    wcalPut(mac, c);
    wcalSendSoon = true;
    wcalSensorPage(mac, "共通の値で較正しました。サーバーへ送ります。");
    return;
  }
  if (step != "zero" && step != "span" && step != "check")
  {
    sendText(400, "不明な操作です");
    return;
  }
  int32_t g = 0;
  if (step == "span")
  {
    g = server.arg("g").toInt();
    if (!(c.flags & WCAL_F_ZERO)) { wcalSensorPage(mac, "<b style='color:#c00'>先に「ゼロ点を取る」をしてください。</b>"); return; }
    if (g < 100) { wcalSensorPage(mac, "<b style='color:#c00'>重りは 100g 以上にしてください(軽いと誤差が大きくなります)。</b>"); return; }
  }
  if (step == "check" && !((c.flags & WCAL_F_ZERO) && (c.flags & WCAL_F_SPAN)))
  {
    wcalSensorPage(mac, "<b style='color:#c00'>まだ較正が済んでいません。</b>");
    return;
  }

  // 値を集める(約10秒)
  const uint8_t n = bleCalCollect(mac, WCAL_COLLECT_MS);
  if (bleCalFail != 0 && n < WCAL_MIN_SAMPLES)
  {
    wcalSensorPage(mac, String("<b style='color:#c00'>") + (bleCalFail == 1 ? "重量センサの電池電圧が低いため、測定を止めています。電池を交換してください。"
                                                                        : "重量センサが HX711 を読めていません。配線を確かめてください。") + "</b>");
    return;
  }
  if (n < WCAL_MIN_SAMPLES)
  {
    String m = "<b style='color:#c00'>";
    if (!bleCalSeen) m += "重量センサの電波が見つかりませんでした。電池と、HPX-core との距離を確かめてください。";
    else m += "測定が " + String((int)n) + " 回しか取れませんでした。重量センサが較正モード(電池を入れてから10分間)ではないようです。"
              "電池を入れ直すか、リセットボタンを押してから、もう一度押してください。";
    wcalSensorPage(mac, m + "</b>");
    return;
  }

  // 並べて、4回以上なら最大と最小を除いて平均する
  int32_t v[WCAL_MAX_SAMPLES];
  for (int i = 0; i < n; i++) v[i] = bleCalRaw[i];
  for (int i = 1; i < n; i++)
  {
    int32_t t = v[i];
    int j = i - 1;
    while (j >= 0 && v[j] > t) { v[j + 1] = v[j]; j--; }
    v[j + 1] = t;
  }
  const int lo = n >= 4 ? 1 : 0;
  const int hi = n >= 4 ? n - 1 : n;
  int64_t sum = 0;
  for (int i = lo; i < hi; i++) sum += v[i];
  const int32_t avg = (int32_t)(sum / (hi - lo));
  const int32_t range = v[n - 1] - v[0];
  String info = "生の値 " + String((long)avg) + " (" + String((int)n) + "回の平均、揺れ " + String((long)range) + ")";
  if (bleCalMv > 0) info += "、電池 " + String(bleCalMv / 1000.0f, 2) + "V";
  String warn = range > WCAL_WARN_RANGE ? "<br><b style='color:#b26a00'>値が揺れています。風や手が触れていないか確かめて、もう一度押すと確実です。</b>" : "";

  if (step == "check")
  {
    const float kg = (avg - c.zero) / c.perKg;
    wcalSensorPage(mac, "今の重さ: <b>" + String(kg, 2) + " kg</b><br>" + info + warn
                        + "<br><small>サーバーで「差し引く重さ」を設定していれば、ダッシュボードの表示はその分だけ少なくなります。</small>");
    return;
  }
  if (step == "zero")
  {
    c.zero = avg;
    c.flags |= WCAL_F_ZERO;
    String m = "ゼロ点を保存しました。" + info + warn;
    if (c.flags & WCAL_F_SPAN)
    {
      c.flags |= WCAL_F_PENDING;
      c.ts = ts;
      wcalSendSoon = true;
      m += "<br>今までの「1kgあたりの値」と合わせて、サーバーへ送ります。";
    }
    else
    {
      m += "<br>次に、重りを載せて「較正する」を押してください。";
    }
    wcalPut(mac, c);
    wcalSensorPage(mac, m);
    return;
  }
  // step == "span"
  const int32_t diff = avg - c.zero;
  if (diff > -1000 && diff < 1000)
  {
    wcalSensorPage(mac, "<b style='color:#c00'>重りを載せた値がゼロ点とほとんど変わりません。重りが載っているか確かめてください。</b><br>" + info);
    return;
  }
  c.perKg = (float)diff / (g / 1000.0f);
  c.calRaw = avg;
  c.calG = (uint32_t)g;
  c.flags |= WCAL_F_SPAN | WCAL_F_PENDING;
  c.flags &= (uint8_t)~WCAL_F_COMMON;
  c.ts = ts;
  wcalPut(mac, c);
  wcalSendSoon = true;
  wcalSensorPage(mac, "較正しました。1kgあたり " + String(c.perKg, 1) + "。" + info + warn
                      + (c.perKg < 0 ? "<br><b style='color:#b26a00'>重さを載せると値が減っています(ロードセルの緑と白が逆)。このままでも kg は正しく出ます。</b>" : "")
                      + "<br>サーバーへ送ります。「今の重さを測る」で確かめられます。");
}

// 全台共通の値: action=avg(重りで較正した台の平均にする) / set(値を入れる) / clear(消す)
void handleWcalCommon()
{
  if (!requireAuth()) return;
  const String a = server.arg("action");
  if (a == "avg")
  {
    int n = 0;
    const float avg = wcalAverage(n);
    if (n > 0) wcalSetCommon(avg);
  }
  else if (a == "set")
  {
    const float v = server.arg("value").toFloat();
    if (v > 0) wcalSetCommon(v);
  }
  else if (a == "clear")
  {
    wcalSetCommon(0);
  }
  redirectTo("/wcal");
}

// ====================================================================
// 14. 機器ID設定
// ====================================================================

void handleDeviceIDPage()
{
  if (!requireAuth()) return;

  String h = pageHead("機器ID設定");
  h += "<h1>機器ID設定</h1>";
  h += "<p>現在の機器ID：<b>" + htmlEscape(deviceID) + "</b></p>";
  h += "<form action='/saveid' method='POST'>";
  h += "<label for='deviceid'>新しい機器ID</label><br>";
  h += "<input type='text' id='deviceid' name='deviceid' ";
  h += "pattern='[a-z0-9]+([a-z0-9-]*[a-z0-9])?' maxlength='32' ";
  h += "value='" + htmlEscape(deviceID) + "' required>";
  h += "<br><small>半角英小文字(a-z)、数字(0-9)、ハイフン(-)のみ";
  h += "（先頭・末尾のハイフンは使用不可）</small><br><br>";
  h += "<input type='submit' value='保存'></form><br>";
  h += "<a href='/'>管理画面へ戻る</a>";
  h += "</body></html>";
  sendHtml(h);
}

void handleSaveDeviceID()
{
  if (!requireAuth()) return;
  Serial.println("SAVE DEVICE ID CALLED");

  if (!server.hasArg("deviceid"))
  {
    sendText(400, "機器IDが送信されていません。");
    return;
  }

  String id = server.arg("deviceid");
  id.trim();

  if (id.length() == 0)
  {
    sendText(400, "機器IDを入力してください。");
    return;
  }
  if (id.length() > 32)
  {
    sendText(400, "機器IDは32文字以内にしてください。");
    return;
  }
  if (id.startsWith("-"))
  {
    sendText(400, "機器IDの先頭にハイフンは使用できません。");
    return;
  }
  if (id.endsWith("-"))
  {
    sendText(400, "機器IDの末尾にハイフンは使用できません。");
    return;
  }

  for (unsigned int i = 0; i < id.length(); i++)
  {
    char c = id.charAt(i);
    bool ok = (c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') || (c == '-');
    if (!ok)
    {
      sendText(400, "機器IDは半角英小文字・数字・ハイフンのみ使用できます。");
      return;
    }
  }

  preferences.begin("wifi-config", false);
  size_t savedLength = preferences.putString("deviceID", id);
  preferences.end();

  if (savedLength == 0)
  {
    sendText(500, "機器IDの保存に失敗しました。");
    return;
  }

  deviceID = id;
  String newMdnsUrl = "http://" + makeMdnsHost(deviceID) + ".local/";

  String h = pageHead("機器ID保存");
  h += "<h1>機器IDを保存しました</h1>";
  h += "<p><b>機器ID : " + htmlEscape(deviceID) + "</b></p>";
  h += "<p>HPX機器は約2秒後に自動再起動します。</p>";
  h += "<p><b>新しい管理画面</b><br>";
  h += "<a href='" + newMdnsUrl + "'>" + newMdnsUrl + "</a></p>";
  h += "</body></html>";
  sendHtml(h);
  delay(1500);
  ESP.restart();
}

// ====================================================================
// 15. OTAファームウェア更新(管理画面からのアップロード)
// ====================================================================

void handleUpdatePage()
{
  if (!requireAuth()) return;

  String h = pageHead("ファームウェア更新");
  h += "<h1>ファームウェア更新</h1>";
  h += "<p>現在のバージョン: " + String(FW_VERSION) + "</p>";
  h += "<form method='POST' action='/update' enctype='multipart/form-data'>";
  h += "<input type='file' name='update' accept='.bin' required><br>";
  h += "<small>※ *.ino.bin ファイルのみ選択できます(bootloader・merged・partitions は不可)</small><br><br>";
  h += "<input type='submit' value='更新'></form><br>";
  h += "<a href='/'>管理画面へ戻る</a>";
  h += "</body></html>";
  sendHtml(h);
}

// OTAファームウェア受信・書込み処理
void handleUpdateUpload()
{
  HTTPUpload& upload = server.upload();

  if (upload.status == UPLOAD_FILE_START)
  {
    touch();
    otaReady = false;
    otaSuccess = false;
    otaMessage = "";

    Serial.println();
    Serial.print("Update: ");
    Serial.println(upload.filename);

    if (adminPass.length() > 0 && !server.authenticate("admin", adminPass.c_str()))
    {
      otaMessage = "認証に失敗しました。";
      return;
    }
    if (!upload.filename.endsWith(".ino.bin"))
    {
      otaMessage = "ファイル名が *.ino.bin ではありません。";
      return;
    }

    otaReady = Update.begin(UPDATE_SIZE_UNKNOWN);
    if (!otaReady)
    {
      otaMessage = "OTAを開始できません。";
      Serial.println("OTA開始失敗");
      Update.printError(Serial);
      return;
    }
    otaBusy = true;
    Serial.println("OTA開始成功");
  }
  else if (upload.status == UPLOAD_FILE_WRITE)
  {
    esp_task_wdt_reset();
    touch();

    static unsigned int count = 0;
    count++;
    if (count % 50 == 0) Serial.print("#");

    if (!otaReady) return;

    size_t written = Update.write(upload.buf, upload.currentSize);
    if (written != upload.currentSize)
    {
      Serial.println("OTA書き込み失敗");
      Update.printError(Serial);
      otaMessage = "書き込みに失敗しました。";
      otaReady = false;
      otaSuccess = false;
      Update.abort();
      otaBusy = false;
    }
  }
  else if (upload.status == UPLOAD_FILE_END)
  {
    if (!otaReady)
    {
      otaSuccess = false;
      otaBusy = false;
      return;
    }

    otaSuccess = Update.end(true);
    if (otaSuccess)
    {
      Serial.print("OTA成功、サイズ=");
      Serial.println(upload.totalSize);
    }
    else
    {
      otaMessage = "更新の確定に失敗しました(ファイルが正しいか確認してください)。";
      Serial.println("OTA終了処理失敗");
      Update.printError(Serial);
      otaBusy = false;
    }
  }
  else if (upload.status == UPLOAD_FILE_ABORTED)
  {
    otaReady = false;
    otaSuccess = false;
    otaBusy = false;
    Update.abort();
    Serial.println("OTAが中断されました");
  }
}

// OTA更新完了判定と再起動処理
void handleUpdateFinish()
{
  if (!requireAuth()) return;

  if (otaSuccess && !Update.hasError())
  {
    String h;
    h += "<!DOCTYPE html><html lang='ja'><head>";
    h += "<meta charset='UTF-8'>";
    h += "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
    h += "<meta http-equiv='refresh' content='25;url=/'>";
    h += "<title>OTA更新成功</title></head><body>";
    h += "<h1>ファームウェア更新成功</h1>";
    h += "<p>HPX機器を再起動します。25秒後に管理画面へ戻ります。</p>";
    h += "</body></html>";
    sendHtml(h);
    delay(1500);
    ESP.restart();
  }
  else
  {
    otaBusy = false;
    String m = (otaMessage.length() > 0) ? otaMessage : String("ファームウェア更新に失敗しました。");
    sendText(500, m + " 再起動しません。シリアルモニターを確認してください。");
    Serial.println("OTA failed. hpx will not restart.");
  }
}

// ====================================================================
// 16. Wi-Fi再設定・工場出荷
// ====================================================================

void handleWiFiResetPage()
{
  if (!requireAuth()) return;

  String h = pageHead("Wi-Fi再設定");
  h += "<h1>Wi-Fi再設定</h1>";
  h += "<p>保存済みのSSIDとWi-Fiパスワードを削除します。</p>";
  h += "<p>ルーターの設定変更や交換をする前に行ってください。</p>";
  h += "<p>機器IDと管理パスワードは削除されません。</p>";
  h += "<p>削除後HPX機器は再起動し、設定用のWi-Fi「HPX-Setup-" + deviceUid.substring(8) + "」を起動します。";
  h += "パスワードは機器のラベルに記載のものです。</p>";
  h += "<form action='/resetwifi' method='POST'>";
  h += "<button type='submit' style='color:red' ";
  h += "onclick='return confirm(\"Wi-Fi設定を削除します。よろしいですか？\")'>";
  h += "Wi-Fi設定を削除して再起動</button></form><br>";
  h += "<a href='/'>削除せずに管理画面へ戻る</a>";
  h += "</body></html>";
  sendHtml(h);
}

void handleWiFiReset()
{
  if (!requireAuth()) return;
  Serial.println("RESET WIFI SETTINGS CALLED");

  preferences.begin("wifi-config", false);
  bool ssidRemoved = preferences.remove("ssid");
  bool passwordRemoved = preferences.remove("password");
  preferences.end();

  Serial.print("SSID removed: ");
  Serial.println(ssidRemoved ? "yes" : "not found or failed");
  Serial.print("Password removed: ");
  Serial.println(passwordRemoved ? "yes" : "not found or failed");

  String h = pageHead("Wi-Fi設定削除");
  h += "<h1>Wi-Fi設定を削除しました</h1>";
  h += "<p>機器ID「" + htmlEscape(deviceID) + "」は保持されています。</p>";
  h += "<p>HPX機器は約2秒後に再起動します。</p>";
  h += "<p>再起動後、設定用Wi-Fi「HPX-Setup-" + deviceUid.substring(8) + "」(パスワードは機器のラベル)へ接続してください。</p>";
  h += "<p><a href='http://192.168.4.1/'>初期設定画面</a></p>";
  h += "</body></html>";
  sendHtml(h);
  delay(2000);
  ESP.restart();
}

void handleFactoryResetPage()
{
  if (!requireAuth()) return;

  String h = pageHead("工場出荷状態へ戻す");
  h += "<h1>工場出荷状態へ戻す</h1>";
  h += "<p>Wi-Fi設定、機器ID、管理パスワード、動作設定をすべて削除します。</p>";
  h += "<p>ファームウェアは現状のままです。</p>";
  h += "<p>再起動後は初回起動状態になります。</p>";
  h += "<form action='/factory-reset' method='POST'>";
  h += "<button type='submit' style='color:red' ";
  h += "onclick='return confirm(\"本当に工場出荷状態へ戻しますか？\")'>";
  h += "工場出荷状態へ戻す</button></form><br>";
  h += "<a href='/'>管理画面へ戻る</a>";
  h += "</body></html>";
  sendHtml(h);
}

void handleFactoryReset()
{
  if (!requireAuth()) return;
  Serial.println("FACTORY RESET CALLED");

  doFactoryReset();

  String h = pageHead("工場出荷状態");
  h += "<h1>工場出荷状態へ戻しました</h1>";
  h += "<p>HPX機器は約2秒後に再起動します。</p>";
  h += "<p>再起動後、設定用Wi-Fi「HPX-Setup-" + deviceUid.substring(8) + "」(パスワードは機器のラベル)へ接続してください。</p>";
  h += "<p><a href='http://192.168.4.1/'>初期設定画面</a></p>";
  h += "</body></html>";
  sendHtml(h);
  delay(2000);
  ESP.restart();
}

// ====================================================================
// 17. 通常時のWebサーバー
// ====================================================================

void startNormalServer()
{
  if (MDNS.begin(mdnsHost.c_str()))
  {
    mdnsStarted = true;
    MDNS.addService("http", "tcp", 80);
    Serial.print("mDNS started: http://");
    Serial.print(mdnsHost);
    Serial.println(".local/");
  }
  else
  {
    mdnsStarted = false;
    Serial.println("mDNS start failed.");
  }

  server.on("/", HTTP_GET, handleMainPage);
  server.on("/runnow", HTTP_POST, handleRunNow);
  server.on("/sleepnow", HTTP_POST, handleSleepNow);
  server.on("/settings", HTTP_GET, handleSettingsPage);
  server.on("/savesettings", HTTP_POST, handleSettingsSave);
  server.on("/blesensors", HTTP_GET, handleBleSensors);
  server.on("/blescan", HTTP_POST, handleBleScan);
  server.on("/bleadd", HTTP_POST, handleBleAdd);
  server.on("/bledel", HTTP_POST, handleBleDel);
  server.on("/blelog", HTTP_GET, handleBleLog);
  server.on("/blelog.csv", HTTP_GET, handleBleCsv);
  server.on("/blenow", HTTP_POST, handleBleNow);
  server.on("/bleclear", HTTP_POST, handleBleClear);
  server.on("/wcal", HTTP_GET, handleWcalList);
  server.on("/wcalsensor", HTTP_GET, handleWcalSensor);
  server.on("/wcalmeasure", HTTP_POST, handleWcalMeasure);
  server.on("/wcalcommon", HTTP_POST, handleWcalCommon);
  server.on("/deviceid", HTTP_GET, handleDeviceIDPage);
  server.on("/saveid", HTTP_POST, handleSaveDeviceID);
  server.on("/update", HTTP_GET, handleUpdatePage);
  server.on("/update", HTTP_POST, handleUpdateFinish, handleUpdateUpload);
  server.on("/wifi-reset", HTTP_GET, handleWiFiResetPage);
  server.on("/resetwifi", HTTP_POST, handleWiFiReset);
  server.on("/factory-reset-page", HTTP_GET, handleFactoryResetPage);
  server.on("/factory-reset", HTTP_POST, handleFactoryReset);
  server.onNotFound([]()
  {
    Serial.print("404 : ");
    Serial.println(server.uri());
    server.send(404, "text/plain", "Not Found");
  });

  server.begin();
  Serial.println("WebServer started");
}

// Wi-Fiが切れたときの復旧
void wifiWatch(uint32_t now)
{
  if (WiFi.status() == WL_CONNECTED)
  {
    wifiDownSinceMs = 0;
    return;
  }

  if (wifiDownSinceMs == 0) wifiDownSinceMs = (now == 0) ? 1 : now;

  if (now - lastReconnectMs >= 30000)
  {
    lastReconnectMs = now;
    WiFi.reconnect();
  }

  if (powerMode == MODE_DEEPSLEEP)
  {
    if (now - wifiDownSinceMs > 120000) goToSleep(normalSleepSec());  // 保守中でも繋がらなければ眠る
  }
  else if (now - wifiDownSinceMs > WIFI_DOWN_RESTART_MS)
  {
    Serial.println("WiFi down too long. Restarting.");
    ESP.restart();
  }
}

// ====================================================================
// 18. システム処理
// ====================================================================

void setup()
{
  Serial.begin(115200);

  esp_reset_reason_t reason = esp_reset_reason();
  timerWake = (esp_sleep_get_wakeup_cause() == ESP_SLEEP_WAKEUP_TIMER);
  powerOnBoot = (reason == ESP_RST_POWERON || reason == ESP_RST_EXT);
  maintenanceBoot = powerOnBoot || (reason == ESP_RST_SW);
  delay(timerWake ? 20 : 300);

  initRtc(powerOnBoot);
  rtcBootCount++;
  setenv("TZ", "JST-9", 1);
  tzset();
  WiFi.persistent(false);
  initDeviceUid();
  setupWdt();

  // RESET直後の3秒間だけ、BOOT長押しによる工場出荷リセットを受け付ける
  if (powerOnBoot) checkBootFactoryReset();

  loadConfig();

  Serial.println();
  Serial.printf("VERSION %s (build %d) / id %s / uid %s\n", FW_VERSION, FW_BUILD, deviceID.c_str(), deviceUid.c_str());
  Serial.printf("reset=%s timer_wake=%d mode=%d interval=%dmin boot#%lu\n",
                resetReasonStr(), (int)timerWake, (int)powerMode, (int)intervalMin, (unsigned long)rtcBootCount);
  checkPendingVerify();

  // 連続クラッシュ(電池切れ間際のブラウンアウト等)で電池を使い切らないよう、3回続いたら1時間眠る
  bool crashed = (reason == ESP_RST_PANIC || reason == ESP_RST_INT_WDT || reason == ESP_RST_TASK_WDT ||
                  reason == ESP_RST_WDT || reason == ESP_RST_BROWNOUT);
  if (crashed) rtcCrashCount++;
  else if (!timerWake) rtcCrashCount = 0;
  if (rtcCrashCount >= 3 && powerMode == MODE_DEEPSLEEP)
  {
    Serial.println("Repeated crashes. Sleeping 1 hour.");
    rtcCrashCount = 0;
    goToSleep(3600);
  }

  // Wi-Fi未設定 → 設定用AP
  if (cfgSsid.length() == 0)
  {
    startConfigAP(false);
    return;
  }

  // スリープ機の通常復帰: 計測して送信し、すぐ眠る
  bool wantServer = (powerMode == MODE_ALWAYS_ON) || maintenanceBoot;
  if (!wantServer)
  {
    measureOnlyBoot();  // 戻らない
    return;
  }

  // 保守モード または 常時稼働
  Serial.println("Connecting...");
  if (!connectWifi(WIFI_CONNECT_TIMEOUT_MS))
  {
    startConfigAP(true);
    return;
  }

  Serial.print("IP = ");
  Serial.println(WiFi.localIP());
  startNormalServer();
  lastActivityMs = millis();

  bool ok = runCycle(allowOtaNow());
  if (ok) rtcFailStreak = 0;
  nextCycleMs = millis() + secToNextCycle() * 1000UL;
}

void loop()
{
  esp_task_wdt_reset();

  if (apMode)
  {
    apLoop();
    validateLoop();
    delay(2);
    return;
  }

  // handleClient() の中で lastActivityMs が更新されるため、現在時刻はその後に取得する。
  // (先に取ると now < lastActivityMs になり、符号なしの引き算が巨大な値になって
  //  最初のアクセス直後にスリープしてしまう)
  server.handleClient();
  const uint32_t now = millis();
  wifiWatch(now);
  validateLoop();

  if (sleepRequested)
  {
    delay(300);
    goToSleep(normalSleepSec());
  }

  // 重量センサの較正を保存した直後は、次の区切りを待たずにサーバーへ送る
  if (wcalSendSoon && !otaBusy && WiFi.status() == WL_CONNECTED)
  {
    wcalSendSoon = false;
    runCycle(false);
  }

  if (powerMode == MODE_ALWAYS_ON)
  {
    if ((int32_t)(now - nextCycleMs) >= 0)
    {
      runCycle(allowOtaNow());
      nextCycleMs = millis() + secToNextCycle() * 1000UL;
    }
  }
  else if (!otaBusy && (now - lastActivityMs) > MAINT_IDLE_MS)
  {
    goToSleep(normalSleepSec());
  }

  delay(2);
}
