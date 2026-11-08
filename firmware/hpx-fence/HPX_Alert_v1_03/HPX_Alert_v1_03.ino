// ====================================================================
// HPX_Alert_v1_03.ino   Hive Plus X  電柵アラートノード (HPX-alert)
//
// 対象   : Seeed XIAO nRF52840 (通常版)  /  ボードパッケージ「Seeed nRF52 Boards」(Bluefruit系)
//          Arduino IDE のボード: "Seeed XIAO nRF52840"
// 構成   : StaFIX フェンスアラート(電圧降下警告灯)の LED → 光センサ(フォトトランジスタ) → このノード。
//          電池は単3リチウム ×2 または CR123A を 3V3 ピンへ直接(重量ノードと同じ)。
// 役割   : フェンスアラートの LED の点滅(電柵の電圧低下)を数え、BLEの広告で状態を出し続ける(接続・ペアリング不要)。
//          ハブ(HPX_Core v1.12 以降のESP32)が受信してサーバーへ送り、サーバーがメールで知らせる。
//
// 変更履歴:
//   1.03  電池電圧を測ったあと、光センサの読み取りがずっと 0 (=「明るすぎる」) になる不具合を直した。
//         電池電圧の測定が SAADC の完了の合図(イベント)を消さずに終わっていたため、次の analogRead が
//         変換を待たずに 0 を返していた。測定のあとで合図を消し、入力を元に戻すようにした。
//         光センサは CdS セル(5506 など)でも使える(D1 → 10kΩ → D0 ─ CdS ─ GND)。
//   1.02  光センサをアナログで読むようにした(日光対策)。まわりの明るさ(背景)を覚えておき、
//         そこから短く急に明るくなった分だけを点滅として数える。センサには読む瞬間だけ通電する(省電力)。
//         点滅を数えるたびに XIAO の赤LEDを短く光らせる。光センサが明るすぎる(飽和)ときは青LEDで知らせる。
//         配線が変わった(D1 → 抵抗 → D0)。フォトカプラ方式(方式B)はやめた(アース無しの警報器は柵線の電位に浮くため)。
//   1.01  点滅中に「常時点灯」と誤判定することがあったのを直した
//
// 配線(電気的には警報器とつながない):
//   フォトトランジスタ(例 TPS601A, PT204-6B など)を警報器の赤いカバーに向けて貼り、カバーごと黒いキャップ等で遮光する。
//     XIAO D1 ── 抵抗 SENSOR_R(10kΩ) ── XIAO D0 ── フォトトランジスタのコレクタ
//     フォトトランジスタのエミッタ ── XIAO GND
//   明るいほど D0 の電圧が下がる。D1 は読む瞬間だけ HIGH にする(暗いとき以外もずっと電流が流れないように)。
//   抵抗の目安: 晴れた日にシリアルで「light」が 3900 近くに張り付く(飽和)なら 4.7kΩ に下げる。
//              点滅の「peak」が 200 に届かないなら 22k〜47kΩ に上げる。
//   ※ フェンスアラートの回路と XIAO の電線を直接つながないこと(柵線の高電圧パルスが回り込む)。
//
// 判定   : 光の量 light(0=暗い〜4095=明るい)を SAMPLE_MS ごとに読み、背景 bg(ゆっくり追いかける平均)と比べる。
//          light - bg が PULSE_DELTA 以上になり、MAX_PULSE_MS 以内に戻ったら点滅1回。
//          (それより長く明るいままなら、日が差したなどの背景の変化とみなして bg を合わせ直す)
//          背景より暗くなった(葉や雲の影)直後 SHADOW_HOLD_MS の間に明るくなったのは、影が抜けただけとみなして数えない。
//          直近 ALERT_WINDOW_S 秒に ALERT_MIN_BLINKS 回以上点滅 → 「電圧低下」
//          最後の点滅から CLEAR_S 秒たつ → 「正常」に戻る
//          light が SAT_LIGHT 以上のまま STEADY_S 秒続く → 状態2「明るすぎる(飽和)」。点滅が見えないので遮光を確認する。
//          (サーバー・ハブの表示は従来どおり「常時点灯(遮光・配線を確認)」)
//          「正常 → 電圧低下」になった回数を数えて送る(ハブが受信する間の短い低下も、サーバーが回数の増加で気付ける)
//
// 調整   : DEBUG_PLOT を 1 にすると、毎回の light と bg をシリアルに出す(Arduino IDE のシリアルプロッタで見られる)。
//          点滅1回ごとの「幅(ms)」と「peak(背景からの増え方)」は DEBUG_PLOT が 0 でも出る。
//          点滅の幅が SAMPLE_MS の2倍より短いと取りこぼすことがある。その場合は SAMPLE_MS を小さくする(電池は減る)。
//
// 広告データ(メーカーデータ16バイト。v1.01 と同じ。ハブ側 HPX_Core v1.12 の bleParseA と同じ):
//   [0..1]   0xFF 0xFF   (会社ID。試験用の値)
//   [2]      'A'
//   [3]      版 = 1
//   [4..5]   通し番号 (uint16, LE)  広告データを作り直すたびに +1
//   [6]      状態 0=正常 1=電圧低下(点滅中) 2=光センサが明るすぎる(遮光不良)
//   [7]      直近 ALERT_WINDOW_S 秒の点滅回数 (uint8, 255で頭打ち)
//   [8..9]   電圧低下になった回数 (uint16, LE)  起動からの通算
//   [10..11] ノード内の温度 ×100 (int16, LE)
//   [12..13] 最後の点滅からの秒数 (uint16, LE)  0xFFFF = 起動から一度も点滅していない(65534で頭打ち)
//   [14..15] 電池電圧 mV (uint16, LE)  0 のときは測れなかった
//   広告の名前(スキャン応答)は "HPX-alert"。ハブの登録画面に [電柵アラート] と出る。
//
// 注意   : このコードはコンパイル・実機での確認をしていない(作成環境にツールチェーンが無いため)。
//          書き込み後はシリアルモニター(115200)で、LEDの点滅が数えられることを確認すること。
// ====================================================================

#include <bluefruit.h>

#define NODE_VERSION "HPX-Alert.1.03"

// ---- 設定 ----
constexpr uint8_t PIN_SENSOR = A0;          // 光センサの電圧(= D0)。明るいほど下がる
constexpr uint8_t PIN_SENSOR_PWR = D1;      // 光センサの電源。読む瞬間だけ HIGH
constexpr uint32_t SENSOR_SETTLE_US = 50;   // 電源を入れてから読むまでの待ち

constexpr uint32_t SAMPLE_MS = 20;          // 光を読む間隔
constexpr uint32_t BG_TAU_MS = 3000;        // 背景がついていく速さ(この時間でおよそ6割追いつく)
constexpr int32_t PULSE_DELTA = 120;        // 背景よりこれだけ明るくなったら点滅の始まり(4095中)
constexpr uint32_t MAX_PULSE_MS = 1000;     // これより長く明るいままなら点滅ではなく背景の変化とみなす
constexpr int32_t SAT_LIGHT = 3900;         // これ以上は飽和(明るすぎて点滅が見えない)
constexpr uint32_t SHADOW_HOLD_MS = 1500;   // 背景より暗くなった(影)直後のこの時間は、明るさが戻っても点滅と数えない

constexpr uint32_t DEBOUNCE_MS = 150;       // これより短い間隔の点滅は同じ点滅とみなす
constexpr uint8_t ALERT_WINDOW_S = 60;      // 点滅を数える時間(秒。最大 60)
constexpr uint8_t ALERT_MIN_BLINKS = 3;     // この回数以上点滅したら「電圧低下」
constexpr uint32_t CLEAR_S = 120;           // 最後の点滅からこの秒数たったら「正常」に戻す
constexpr uint32_t STEADY_S = 10;           // 飽和がこの秒数以上続いたら状態2(明るすぎる)

constexpr bool USE_INDICATOR = true;        // 点滅を数えたら赤、飽和中は5秒ごとに青を光らせる。false で全部消灯
constexpr uint8_t LED_ON_LEVEL = LOW;       // XIAO nRF52840 の内蔵LEDは LOW で点灯
constexpr uint32_t SAT_BLINK_S = 5;         // 飽和中に青を光らせる間隔

#define DEBUG_PLOT 0                        // 1 にすると毎回の light と bg をシリアルに出す(調整用)

constexpr uint32_t ADV_REFRESH_S = 60;      // 状態が変わらなくても、この間隔で広告データ(秒数・電池)を作り直す
constexpr uint32_t VDD_INTERVAL_S = 600;    // 電池電圧を測る間隔

constexpr uint16_t ADV_INTERVAL = 1600;     // 広告間隔(0.625ms単位。1600 = 1秒)
constexpr int8_t TX_POWER_DBM = 0;          // 送信出力。ハブまで届きにくいときは 4 まで上げられる(電池は減る)

#define USE_VDD_MEASURE 1   // 電池電圧をSAADCの内部チャンネルで測る。コンパイルが通らなければ 0 にする(電池電圧は0=不明で送る)

constexpr uint8_t ST_OK = 0;
constexpr uint8_t ST_LOW = 1;      // 電圧低下(点滅中)
constexpr uint8_t ST_STEADY = 2;   // 光センサが明るすぎる(飽和)

static_assert(ALERT_WINDOW_S >= 1 && ALERT_WINDOW_S <= 60, "ALERT_WINDOW_S");
static_assert(SAMPLE_MS >= 5 && SAMPLE_MS <= 100, "SAMPLE_MS");
constexpr int32_t BG_SAMPLES = (int32_t)(BG_TAU_MS / SAMPLE_MS);

// ---- 状態 ----
static uint32_t gBlinkCount = 0;   // 起動からの点滅回数
static uint32_t gLastBlinkMs = 0;
static bool gEverBlinked = false;

static int32_t gBg16 = -1;         // 背景の明るさ ×16(-1 = まだ読んでいない)
static bool gInPulse = false;
static uint32_t gPulseStartMs = 0;
static int32_t gPulsePeak = 0;
static bool gPulseAfterShadow = false;
static uint32_t gShadowMs = 0;     // 最後に背景より暗かった時刻
static bool gEverShadow = false;
static uint32_t gSatStartMs = 0;   // 飽和が始まった時刻(0 = 飽和していない)
static bool gIndicatorOn = false;

static uint8_t gWin[60];          // 1秒ごとの点滅回数(リングバッファ)
static uint8_t gWinPos = 0;
static uint32_t gPrevCount = 0;
static uint8_t gState = ST_OK;
static uint16_t gEvents = 0;
static uint16_t gVddMv = 0;
static uint16_t gSeq = 0;
static uint8_t gAdv[16];

// ====================================================================
// 表示LED
// ====================================================================

static void indicator(uint8_t pin)
{
  if (!USE_INDICATOR) return;
  digitalWrite(pin, LED_ON_LEVEL);
  gIndicatorOn = true;   // 次に光を読む前に消す(1回 SAMPLE_MS だけ光る)
}

static void indicatorsOff()
{
  if (!gIndicatorOn) return;
  digitalWrite(LED_RED, !LED_ON_LEVEL);
  digitalWrite(LED_GREEN, !LED_ON_LEVEL);
  digitalWrite(LED_BLUE, !LED_ON_LEVEL);
  gIndicatorOn = false;
}

// ====================================================================
// 点滅の検出
// ====================================================================

// 光の量を読む(0=暗い〜4095=明るい)。センサには読む間だけ通電する。
static int32_t readLight()
{
  digitalWrite(PIN_SENSOR_PWR, HIGH);
  delayMicroseconds(SENSOR_SETTLE_US);
  const int32_t raw = (int32_t)analogRead(PIN_SENSOR);   // 基準は VDD(電池電圧が変わっても比率で読める)
  digitalWrite(PIN_SENSOR_PWR, LOW);
  return 4095 - raw;
}

static void countBlink(uint32_t now, uint32_t widthMs, int32_t peak)
{
  if (gEverBlinked && (now - gLastBlinkMs) < DEBOUNCE_MS) return;
  gLastBlinkMs = now;
  gEverBlinked = true;
  gBlinkCount++;
  indicator(LED_RED);
  Serial.printf("blink  width=%ums peak=%d\n", (unsigned)widthMs, (int)peak);
}

// SAMPLE_MS ごとに呼ぶ
static void sampleLight(uint32_t now)
{
  const int32_t light = readLight();

  if (light >= SAT_LIGHT) { if (gSatStartMs == 0) gSatStartMs = now | 1; }
  else gSatStartMs = 0;

  if (gBg16 < 0) { gBg16 = light << 4; return; }
  const int32_t bg = gBg16 >> 4;
  const int32_t delta = light - bg;

#if DEBUG_PLOT
  Serial.printf("light:%d bg:%d\n", (int)light, (int)bg);
#endif

  if (!gInPulse)
  {
    if (delta >= PULSE_DELTA)
    {
      gInPulse = true;
      gPulseStartMs = now;
      gPulsePeak = delta;
      gPulseAfterShadow = gEverShadow && (now - gShadowMs) < SHADOW_HOLD_MS;   // 葉や雲の影が抜けて明るさが戻っただけ
    }
    else if (delta <= -PULSE_DELTA)
    {
      gShadowMs = now;
      gEverShadow = true;
      gBg16 += ((light << 4) - gBg16) / BG_SAMPLES;
    }
    else
    {
      gBg16 += ((light << 4) - gBg16) / BG_SAMPLES;   // 点滅中でないときだけ背景を追いかける
    }
    return;
  }

  if (delta > gPulsePeak) gPulsePeak = delta;
  const uint32_t width = now - gPulseStartMs;
  if (delta < PULSE_DELTA / 2)
  {
    gInPulse = false;
    if (width <= MAX_PULSE_MS && !gPulseAfterShadow) countBlink(now, width, gPulsePeak);
  }
  else if (width > MAX_PULSE_MS)
  {
    gInPulse = false;
    gBg16 = light << 4;   // 明るいままなので、日が差したなどの背景の変化とみなす
    Serial.printf("background changed  light=%d\n", (int)light);
  }
}

// 1秒ごとに呼ぶ。直近 ALERT_WINDOW_S 秒の点滅回数を返す。
static uint16_t tickWindow()
{
  const uint32_t d = gBlinkCount - gPrevCount;
  gPrevCount = gBlinkCount;
  gWin[gWinPos] = (uint8_t)(d > 255 ? 255 : d);
  gWinPos = (uint8_t)((gWinPos + 1) % ALERT_WINDOW_S);
  uint16_t sum = 0;
  for (int i = 0; i < ALERT_WINDOW_S; i++) sum += gWin[i];
  return sum;
}

// 最後の点滅からの秒数。一度も点滅していなければ 0xFFFF
static uint16_t secSinceBlink()
{
  if (!gEverBlinked) return 0xFFFF;
  const uint32_t s = (millis() - gLastBlinkMs) / 1000UL;
  return (uint16_t)(s > 65534 ? 65534 : s);
}

// ====================================================================
// 電池電圧・ノード内温度(重量ノードと同じ)
// ====================================================================

#if USE_VDD_MEASURE
// SAADCの内部チャンネルでVDDを測る(ゲイン1/6、基準0.6V → 満点3.6V、12bit)
static uint16_t readVddMv()
{
  volatile int16_t res = 0;
  NRF_SAADC->ENABLE = (SAADC_ENABLE_ENABLE_Enabled << SAADC_ENABLE_ENABLE_Pos);
  NRF_SAADC->RESOLUTION = (SAADC_RESOLUTION_VAL_12bit << SAADC_RESOLUTION_VAL_Pos);
  NRF_SAADC->OVERSAMPLE = (SAADC_OVERSAMPLE_OVERSAMPLE_Bypass << SAADC_OVERSAMPLE_OVERSAMPLE_Pos);
  NRF_SAADC->CH[0].PSELP = (SAADC_CH_PSELP_PSELP_VDD << SAADC_CH_PSELP_PSELP_Pos);
  NRF_SAADC->CH[0].PSELN = (SAADC_CH_PSELN_PSELN_NC << SAADC_CH_PSELN_PSELN_Pos);
  NRF_SAADC->CH[0].CONFIG =
      (SAADC_CH_CONFIG_RESP_Bypass << SAADC_CH_CONFIG_RESP_Pos) |
      (SAADC_CH_CONFIG_RESN_Bypass << SAADC_CH_CONFIG_RESN_Pos) |
      (SAADC_CH_CONFIG_GAIN_Gain1_6 << SAADC_CH_CONFIG_GAIN_Pos) |
      (SAADC_CH_CONFIG_REFSEL_Internal << SAADC_CH_CONFIG_REFSEL_Pos) |
      (SAADC_CH_CONFIG_TACQ_10us << SAADC_CH_CONFIG_TACQ_Pos) |
      (SAADC_CH_CONFIG_MODE_SE << SAADC_CH_CONFIG_MODE_Pos) |
      (SAADC_CH_CONFIG_BURST_Disabled << SAADC_CH_CONFIG_BURST_Pos);
  NRF_SAADC->RESULT.PTR = (uint32_t)(uintptr_t)&res;
  NRF_SAADC->RESULT.MAXCNT = 1;

  NRF_SAADC->EVENTS_STARTED = 0;
  NRF_SAADC->EVENTS_END = 0;
  NRF_SAADC->EVENTS_STOPPED = 0;
  NRF_SAADC->TASKS_START = 1;
  uint32_t guard = 0;
  while (NRF_SAADC->EVENTS_STARTED == 0 && ++guard < 100000) {}
  NRF_SAADC->TASKS_SAMPLE = 1;
  guard = 0;
  while (NRF_SAADC->EVENTS_END == 0 && ++guard < 100000) {}
  NRF_SAADC->TASKS_STOP = 1;
  guard = 0;
  while (NRF_SAADC->EVENTS_STOPPED == 0 && ++guard < 100000) {}
  // 次の analogRead() が古い合図を見て変換を待たずに 0 を返さないよう、合図を消して入力を外す
  NRF_SAADC->EVENTS_STARTED = 0;
  NRF_SAADC->EVENTS_END = 0;
  NRF_SAADC->EVENTS_STOPPED = 0;
  NRF_SAADC->CH[0].PSELP = (SAADC_CH_PSELP_PSELP_NC << SAADC_CH_PSELP_PSELP_Pos);
  NRF_SAADC->ENABLE = (SAADC_ENABLE_ENABLE_Disabled << SAADC_ENABLE_ENABLE_Pos);

  int32_t r = res;
  if (r < 0) r = 0;
  return (uint16_t)(((uint32_t)r * 3600UL) / 4095UL);
}
#else
static uint16_t readVddMv() { return 0; }
#endif

// nRF52840内部の温度(0.25℃単位)を ×100 の整数で返す
static int16_t readDieTemp100()
{
  int32_t t4 = 0;   // 0.25℃単位
  if (sd_temp_get(&t4) != NRF_SUCCESS) return 0;
  return (int16_t)(t4 * 25);
}

// ====================================================================
// 広告
// ====================================================================

static void putU16(uint8_t* p, uint16_t v) { p[0] = (uint8_t)v; p[1] = (uint8_t)(v >> 8); }

static void startAdvertising()
{
  Bluefruit.Advertising.stop();
  Bluefruit.Advertising.clearData();
  Bluefruit.Advertising.addFlags(BLE_GAP_ADV_FLAGS_LE_ONLY_GENERAL_DISC_MODE);
  Bluefruit.Advertising.addManufacturerData(gAdv, sizeof(gAdv));
  Bluefruit.Advertising.setType(BLE_GAP_ADV_TYPE_NONCONNECTABLE_SCANNABLE_UNDIRECTED);
  Bluefruit.Advertising.setInterval(ADV_INTERVAL, ADV_INTERVAL);
  Bluefruit.Advertising.restartOnDisconnect(false);
  Bluefruit.Advertising.start(0);   // 0 = 止めるまで出し続ける
}

static void updateAdv(uint16_t blinks)
{
  const int16_t temp100 = readDieTemp100();
  const uint16_t ago = secSinceBlink();
  gSeq++;

  gAdv[0] = 0xFF; gAdv[1] = 0xFF;
  gAdv[2] = 'A';  gAdv[3] = 1;
  putU16(&gAdv[4], gSeq);
  gAdv[6] = gState;
  gAdv[7] = (uint8_t)(blinks > 255 ? 255 : blinks);
  putU16(&gAdv[8], gEvents);
  putU16(&gAdv[10], (uint16_t)temp100);
  putU16(&gAdv[12], ago);
  putU16(&gAdv[14], gVddMv);

  static const char* const kStateText[] = {"OK", "LOW VOLTAGE (blinking)", "TOO BRIGHT (check light shield)"};
  Serial.printf("#%u state=%s blinks/%us=%u events=%u last_blink=%us temp=%.2fC vdd=%umV\n",
                (unsigned)gSeq, kStateText[gState], (unsigned)ALERT_WINDOW_S, (unsigned)blinks, (unsigned)gEvents,
                (unsigned)ago, temp100 / 100.0f, (unsigned)gVddMv);
  startAdvertising();
}

// ====================================================================
// Arduino
// ====================================================================

void setup()
{
  Serial.begin(115200);   // USBを挿していないときは待たない
  pinMode(PIN_SENSOR_PWR, OUTPUT);
  digitalWrite(PIN_SENSOR_PWR, LOW);
  analogReference(AR_VDD4);    // 光センサの電源(D1)と同じ VDD を基準にする
  analogReadResolution(12);
  analogSampleTime(10);        // 抵抗 10k〜47kΩ でも正しく読めるよう、取り込み時間を長めにする
  memset(gWin, 0, sizeof(gWin));

  Bluefruit.begin();
  Bluefruit.setTxPower(TX_POWER_DBM);
  Bluefruit.setName("HPX-alert");
  Bluefruit.autoConnLed(false);   // 接続用LEDは使わない(省電力)
  Bluefruit.ScanResponse.addName();   // 名前はスキャン応答に入れる(ハブは能動スキャンで受け取る)

  pinMode(LED_RED, OUTPUT);
  pinMode(LED_GREEN, OUTPUT);
  pinMode(LED_BLUE, OUTPUT);
  digitalWrite(LED_RED, !LED_ON_LEVEL);
  digitalWrite(LED_BLUE, !LED_ON_LEVEL);
  if (USE_INDICATOR) { digitalWrite(LED_GREEN, LED_ON_LEVEL); delay(1000); }   // 起動の合図(緑を1秒)
  digitalWrite(LED_GREEN, !LED_ON_LEVEL);

  ble_gap_addr_t a = Bluefruit.getAddr();
  Serial.printf("\n%s\nBLE address: %02X:%02X:%02X:%02X:%02X:%02X  (ハブの登録画面に出るアドレスと同じ)\n",
                NODE_VERSION, a.addr[5], a.addr[4], a.addr[3], a.addr[2], a.addr[1], a.addr[0]);

  gVddMv = readVddMv();
  updateAdv(0);
}

// 1秒ごとに呼ぶ。状態を決めて、変わったら(または ADV_REFRESH_S ごとに)広告を作り直す。
static void tickSecond(uint32_t now)
{
  static uint32_t lastAdvMs = now;
  static uint32_t lastVddMs = now;
  static uint32_t lastSatBlinkMs = 0;

  const uint16_t blinks = tickWindow();
  const uint16_t ago = secSinceBlink();
  const bool steady = gSatStartMs != 0 && (now - gSatStartMs) >= STEADY_S * 1000UL;

  uint8_t st;
  if (steady) st = ST_STEADY;
  else if (blinks >= ALERT_MIN_BLINKS) st = ST_LOW;
  else if (gState == ST_LOW && ago != 0xFFFF && ago < CLEAR_S) st = ST_LOW;
  else st = ST_OK;

  if (st == ST_STEADY && (now - lastSatBlinkMs) >= SAT_BLINK_S * 1000UL)
  {
    indicator(LED_BLUE);
    lastSatBlinkMs = now;
  }

  if (now - lastVddMs >= VDD_INTERVAL_S * 1000UL)
  {
    gVddMv = readVddMv();
    lastVddMs = now;
  }

  if (st != gState || (now - lastAdvMs) >= ADV_REFRESH_S * 1000UL)
  {
    if (st == ST_LOW && gState == ST_OK) gEvents++;   // 正常からの低下だけを数える(明るすぎるとの行き来は数えない)
    gState = st;
    updateAdv(blinks);
    lastAdvMs = now;
  }
}

void loop()
{
  static uint32_t nextSample = millis();
  static uint32_t nextTick = millis() + 1000;

  const int32_t wait = (int32_t)(nextSample - millis());
  if (wait > 0) delay((uint32_t)wait);   // FreeRTOSのティックレスアイドルでスリープする
  else if (wait < -(int32_t)(SAMPLE_MS * 5)) nextSample = millis();   // 大きく遅れたら合わせ直す
  nextSample += SAMPLE_MS;
  const uint32_t now = millis();

  indicatorsOff();   // 表示LEDの光を自分のセンサで拾わないよう、読む前に消す
  sampleLight(now);

  if ((int32_t)(now - nextTick) >= 0)
  {
    nextTick += 1000;
    tickSecond(now);
  }
}
