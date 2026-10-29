// ====================================================================
// HPX_Alert_v1_00.ino   Hive Plus X  電柵アラートノード (HPX-alert)
//
// 対象   : Seeed XIAO nRF52840 (通常版)  /  ボードパッケージ「Seeed nRF52 Boards」(Bluefruit系)
//          Arduino IDE のボード: "Seeed XIAO nRF52840"
// 構成   : StaFIX フェンスアラート(電圧降下警告灯)の LED → 光センサ(フォトトランジスタ)またはフォトカプラ → このノード。
//          電池は単3リチウム ×2 を 3V3 ピンへ直接(重量ノードと同じ)。
// 役割   : フェンスアラートの LED の点滅(電柵の電圧低下)を数え、BLEの広告で状態を出し続ける(接続・ペアリング不要)。
//          ハブ(HPX_Core v1.12 以降のESP32)が受信してサーバーへ送り、サーバーがメールで知らせる。
//
// 配線(どちらの方式でも、このノードから見ると同じ「LEDが光ると LOW になる入力」になる):
//   方式A 光センサ(電気的につながない。おすすめ)
//     フォトトランジスタ(例 TPS601A, PT204-6B など)を LED に向けて貼り、周りを黒いテープ等で遮光する。
//       コレクタ → XIAO D0 / エミッタ → XIAO GND   (プルアップは内蔵を使う)
//   方式B フォトカプラ(例 PC817)
//     フェンスアラートの LED と「直列」に、フォトカプラの入力側 LED を入れる(並列にすると警告灯が暗くなる・消える)。
//       フォトカプラの出力側: コレクタ → XIAO D0 / エミッタ → XIAO GND
//   ※ フェンスアラートの回路と XIAO の電線を直接つながないこと(柵線の高電圧パルスが回り込む)。
//
// 判定   : 直近 ALERT_WINDOW_S 秒に ALERT_MIN_BLINKS 回以上点滅 → 「電圧低下」
//          最後の点滅から CLEAR_S 秒たつ → 「正常」に戻る
//          入力が LOW のまま STEADY_S 秒以上新しい点滅が無い(光りっぱなし) → 「常時点灯」(遮光不良・配線の短絡など。電圧低下とは区別する)
//          「正常 → 電圧低下」になった回数を数えて送る(ハブが受信する間の短い低下も、サーバーが回数の増加で気付ける)
//
// 広告データ(メーカーデータ16バイト。ハブ側 HPX_Core v1.12 の bleParseA と同じ):
//   [0..1]   0xFF 0xFF   (会社ID。試験用の値)
//   [2]      'A'
//   [3]      版 = 1
//   [4..5]   通し番号 (uint16, LE)  広告データを作り直すたびに +1
//   [6]      状態 0=正常 1=電圧低下(点滅中) 2=常時点灯
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

#define NODE_VERSION "HPX-Alert.1.01"   // 1.01: 点滅中に「常時点灯」と誤判定することがあったのを直した

// ---- 設定 ----
constexpr uint8_t PIN_LED_IN = D0;          // 光センサ/フォトカプラの出力(LEDが光ると LOW)

constexpr uint32_t DEBOUNCE_MS = 150;       // これより短い間隔の立ち下がりは同じ点滅とみなす
constexpr uint8_t ALERT_WINDOW_S = 60;      // 点滅を数える時間(秒。最大 60)
constexpr uint8_t ALERT_MIN_BLINKS = 3;     // この回数以上点滅したら「電圧低下」
constexpr uint32_t CLEAR_S = 120;           // 最後の点滅からこの秒数たったら「正常」に戻す
constexpr uint32_t STEADY_S = 10;           // 入力がこの秒数以上続けて LOW なら「常時点灯」

constexpr uint32_t ADV_REFRESH_S = 60;      // 状態が変わらなくても、この間隔で広告データ(秒数・電池)を作り直す
constexpr uint32_t VDD_INTERVAL_S = 600;    // 電池電圧を測る間隔

constexpr uint16_t ADV_INTERVAL = 1600;     // 広告間隔(0.625ms単位。1600 = 1秒)
constexpr int8_t TX_POWER_DBM = 0;          // 送信出力。ハブまで届きにくいときは 4 まで上げられる(電池は減る)

#define USE_VDD_MEASURE 1   // 電池電圧をSAADCの内部チャンネルで測る。コンパイルが通らなければ 0 にする(電池電圧は0=不明で送る)

constexpr uint8_t ST_OK = 0;
constexpr uint8_t ST_LOW = 1;      // 電圧低下(点滅中)
constexpr uint8_t ST_STEADY = 2;   // 常時点灯

static_assert(ALERT_WINDOW_S >= 1 && ALERT_WINDOW_S <= 60, "ALERT_WINDOW_S");

// ---- 状態 ----
static volatile uint32_t gBlinkCount = 0;   // 起動からの点滅回数(割り込みで増える)
static volatile uint32_t gLastEdgeMs = 0;
static volatile bool gEverBlinked = false;

static uint8_t gWin[60];          // 1秒ごとの点滅回数(リングバッファ)
static uint8_t gWinPos = 0;
static uint32_t gPrevCount = 0;
static uint8_t gState = ST_OK;
static uint16_t gEvents = 0;
static uint16_t gVddMv = 0;
static uint16_t gSeq = 0;
static uint8_t gAdv[16];

// ====================================================================
// 点滅の検出
// ====================================================================

static void onLedEdge()
{
  const uint32_t now = millis();
  if (gEverBlinked && (now - gLastEdgeMs) < DEBOUNCE_MS) return;
  gLastEdgeMs = now;
  gEverBlinked = true;
  gBlinkCount++;
}

// 1秒ごとに呼ぶ。直近 ALERT_WINDOW_S 秒の点滅回数を返す。
static uint16_t tickWindow()
{
  noInterrupts();
  const uint32_t c = gBlinkCount;
  interrupts();
  const uint32_t d = c - gPrevCount;
  gPrevCount = c;
  gWin[gWinPos] = (uint8_t)(d > 255 ? 255 : d);
  gWinPos = (uint8_t)((gWinPos + 1) % ALERT_WINDOW_S);
  uint16_t sum = 0;
  for (int i = 0; i < ALERT_WINDOW_S; i++) sum += gWin[i];
  return sum;
}

// 最後の点滅からの秒数。一度も点滅していなければ 0xFFFF
static uint16_t secSinceBlink()
{
  noInterrupts();
  const bool ever = gEverBlinked;
  const uint32_t last = gLastEdgeMs;
  interrupts();
  if (!ever) return 0xFFFF;
  const uint32_t s = (millis() - last) / 1000UL;
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

  static const char* const kStateText[] = {"OK", "LOW VOLTAGE (blinking)", "STEADY ON (check light shield)"};
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
  pinMode(PIN_LED_IN, INPUT_PULLUP);
  memset(gWin, 0, sizeof(gWin));

  Bluefruit.begin();
  Bluefruit.setTxPower(TX_POWER_DBM);
  Bluefruit.setName("HPX-alert");
  Bluefruit.autoConnLed(false);   // 接続用LEDは使わない(省電力)
  Bluefruit.ScanResponse.addName();   // 名前はスキャン応答に入れる(ハブは能動スキャンで受け取る)

  ble_gap_addr_t a = Bluefruit.getAddr();
  Serial.printf("\n%s\nBLE address: %02X:%02X:%02X:%02X:%02X:%02X  (ハブの登録画面に出るアドレスと同じ)\n",
                NODE_VERSION, a.addr[5], a.addr[4], a.addr[3], a.addr[2], a.addr[1], a.addr[0]);

  gVddMv = readVddMv();
  attachInterrupt(digitalPinToInterrupt(PIN_LED_IN), onLedEdge, FALLING);
  updateAdv(0);
}

void loop()
{
  static uint32_t nextTick = millis() + 1000;
  static uint32_t lastAdvMs = millis();
  static uint32_t lastVddMs = millis();

  const int32_t wait = (int32_t)(nextTick - millis());
  if (wait > 0) delay((uint32_t)wait);   // FreeRTOSのティックレスアイドルでスリープする(点滅は割り込みで数える)
  nextTick += 1000;
  const uint32_t now = millis();

  const uint16_t blinks = tickWindow();
  const uint16_t ago = secSinceBlink();

  // 光りっぱなしの検出: 今 LOW で、STEADY_S 秒の間に新しい点滅(立ち下がり)が一度もない
  // (1秒ごとに読むだけだと、点滅の周期によっては毎回 LOW を読んでしまい、点滅中でも「常時点灯」と誤判定するため)
  const bool steady = (digitalRead(PIN_LED_IN) == LOW) && (ago == 0xFFFF ? now >= STEADY_S * 1000UL : ago >= STEADY_S);

  uint8_t st;
  if (steady) st = ST_STEADY;
  else if (blinks >= ALERT_MIN_BLINKS) st = ST_LOW;
  else if (gState == ST_LOW && ago != 0xFFFF && ago < CLEAR_S) st = ST_LOW;
  else st = ST_OK;

  if (now - lastVddMs >= VDD_INTERVAL_S * 1000UL)
  {
    gVddMv = readVddMv();
    lastVddMs = now;
  }

  if (st != gState || (now - lastAdvMs) >= ADV_REFRESH_S * 1000UL)
  {
    if (st == ST_LOW && gState == ST_OK) gEvents++;   // 正常からの低下だけを数える(常時点灯との行き来は数えない)
    gState = st;
    updateAdv(blinks);
    lastAdvMs = now;
  }
}
