// ====================================================================
// HPX_WeightNode_v1_02.ino   Hive Plus X  巣箱の重量センサ
//
// v1.02 の変更
//   * 電波を強くして(送信出力 0 → +8 dBm)、広告の間隔を 1秒 → 2秒 にした。届く距離は 1.5〜2倍の見込みで、
//     電池の減りは前の版とほぼ同じ (計算だけで未実測)。広告の形式は前の版と同じ (ハブの変更は要らない)。
//     較正モード (起動後10分・広告 100ms) はそのまま。
//
// v1.01 の変更
//   * 較正しやすくするため、電池を入れて(またはリセットして)から CAL_WINDOW_MS(10分)の間は、CAL_INTERVAL_MS(2秒)ごとに
//     測って広告を更新するようにした(較正モード)。HPX-Core の管理画面「重量センサの較正」は、この間の値を10秒ほど集めて平均する。
//       - 較正モードの間は HX711 の電源を入れたままにし、1回の読み取り回数を HX_CAL_SAMPLES(8回)に減らして約1秒で測る
//       - 広告の間隔も 100ms にして、HPX-Core が2秒ごとの値を取りこぼさないようにする
//       - 10分たったら、これまで通り30分ごと・広告1秒間隔に戻る(電池の減りは10分間だけ増える)
//   * 広告の形式は v1.00 と同じ(ハブ側の変更は要らない。較正画面は HPX-Core v1.14 以降)
//
// 対象   : Seeed XIAO nRF52840 (通常版)  /  ボードパッケージ「Seeed nRF52 Boards」(Bluefruit系)
//          Arduino IDE のボード: "Seeed XIAO nRF52840"
// 構成   : ロードセル SC601 → HX711 → このノード。電池は単3リチウム ×2 を 3V3 ピンへ直接(手順書を参照)
// 役割   : BLEの広告で「重量の生の値」を出し続けるだけ(接続・ペアリング不要)。
//          ハブ(HPX_Core v1.11 以降のESP32)が受信してサーバーへ送る。
//          kgへの換算(ゼロ点・校正・温度補正)はサーバーの閲覧画面で行うので、このノードに設定は無い。
//
// 動作   : 起動時と、その後 MEASURE_INTERVAL_MS(30分)ごとに、HX711で重量を測って広告データを更新する。
//          それ以外の時間は広告(2秒間隔)を出しながらスリープする。測定中だけ広告を止める(HX711の読み取りが
//          無線の割り込みで乱れないようにするため)。
//
// 広告データ(メーカーデータ16バイト。ハブ側 HPX_Core v1.11 の bleParseW と同じ):
//   [0..1]   0xFF 0xFF   (会社ID。試験用の値)
//   [2]      'W'
//   [3]      版 = 1
//   [4..5]   通し番号 (uint16, LE)
//   [6..9]   重量の生の値 (int32, LE)  HX711の24bit値の平均(外れ値を除く)
//   [10..11] ノード内の温度 ×100 (int16, LE)  nRF52840内部の温度センサー(大まかな温度補正用)
//   [12..13] ばらつき (uint16, LE)  採用した読み取り値の 最大-最小
//   [14..15] 電池電圧 mV (uint16, LE)  0 のときは測れなかった
//   広告の名前(スキャン応答)は "HPXW"。ハブの「温湿度計の登録」画面に [重量センサ] と出る。
//
// 低電圧時: 電池電圧(測定中)が LOW_VDD_MV 未満なら重量を測らず、測定停止の印(ばらつき=0xFFFF、生の値=1)を送る。
//
// 注意   : このコードはコンパイル・実機での確認をしていない(作成環境にツールチェーンが無いため)。
//          書き込み後はシリアルモニター(115200)で値を確認すること。
// ====================================================================

#include <bluefruit.h>

#define NODE_VERSION "HPX-WeightNode.1.02"

// ---- 設定 ----
// 試験用に短くしたいときは 10 * 1000UL などに変える(電池が早く減る)
constexpr uint32_t MEASURE_INTERVAL_MS = 30UL * 60UL * 1000UL;

// 較正モード: 起動(電池を入れる・リセット)からこの時間は、短い間隔で測る
constexpr uint32_t CAL_WINDOW_MS = 10UL * 60UL * 1000UL;
constexpr uint32_t CAL_INTERVAL_MS = 2000;
constexpr int HX_CAL_SAMPLES = 8;     // 較正モードの1回の読み取り回数(10SPSなら約0.8秒)
constexpr uint16_t CAL_ADV_INTERVAL = 160;   // 較正モードの広告間隔(0.625ms単位。160 = 100ms)

constexpr uint8_t PIN_HX_DOUT = D0;   // HX711 DT  → XIAO D0
constexpr uint8_t PIN_HX_SCK = D1;    // HX711 SCK → XIAO D1

constexpr int HX_DISCARD = 3;         // 電源投入直後の捨てる読み取り回数
constexpr int HX_SAMPLES = 16;        // 平均に使う読み取り回数(10SPSなら約1.6秒)
constexpr uint32_t HX_TIMEOUT_MS = 1000;

// 電池電圧(測定中の電圧)がこれ未満なら、重量を測らずに「測定停止」の印を送る。
// HX711は約2.6V未満で正確に動かず、重量がずれるため、間違った値を送らないようにする。サーバーの閲覧ページはこの印で「電池交換」と表示する。
// (USE_VDD_MEASURE が 0 のときは電池電圧が測れないので、この保護は働かない)
constexpr uint16_t LOW_VDD_MV = 2700;

constexpr uint16_t ADV_INTERVAL = 3200;   // 広告間隔(0.625ms単位。3200 = 2秒。v1.01 までは 1600 = 1秒)
constexpr int8_t TX_POWER_DBM = 8;        // 送信出力 (dBm)。+8 が最大。電池をもたせたいときは 4・0 に下げる (使える値: -40,-20,-16,-12,-8,-4,0,2〜8)

#define USE_VDD_MEASURE 1   // 電池電圧をSAADCの内部チャンネルで測る。コンパイルが通らなければ 0 にする(電池電圧は0=不明で送る)

// ---- 状態 ----
static uint16_t gSeq = 0;
static uint8_t gAdv[16];
static bool gCalMode = true;     // 起動直後は較正モード
static bool gHxOn = false;       // HX711 の電源が入っているか(較正モード中は入れたまま)

// ====================================================================
// HX711
// ====================================================================

static void hxPowerDown()
{
  digitalWrite(PIN_HX_SCK, HIGH);   // SCK を 60µs 以上 HIGH にすると HX711 は電源断状態になる
  delayMicroseconds(100);
  gHxOn = false;
}

static void hxPowerUp()
{
  digitalWrite(PIN_HX_SCK, LOW);
  gHxOn = true;
}

static bool hxWaitReady(uint32_t timeoutMs)
{
  const uint32_t t0 = millis();
  while (digitalRead(PIN_HX_DOUT) == HIGH)
  {
    if (millis() - t0 > timeoutMs) return false;
    delay(1);
  }
  return true;
}

// 1回分(24bit、チャンネルA・ゲイン128)を読む
static bool hxReadOnce(int32_t& out)
{
  if (!hxWaitReady(HX_TIMEOUT_MS)) return false;
  uint32_t v = 0;
  for (int i = 0; i < 24; i++)
  {
    digitalWrite(PIN_HX_SCK, HIGH);
    delayMicroseconds(1);
    v = (v << 1) | (digitalRead(PIN_HX_DOUT) ? 1u : 0u);
    digitalWrite(PIN_HX_SCK, LOW);
    delayMicroseconds(1);
  }
  digitalWrite(PIN_HX_SCK, HIGH);   // 25発目: 次の変換をチャンネルA・ゲイン128にする
  delayMicroseconds(1);
  digitalWrite(PIN_HX_SCK, LOW);
  delayMicroseconds(1);
  if (v & 0x800000u) v |= 0xFF000000u;   // 符号拡張
  out = (int32_t)v;
  return true;
}

static uint16_t readVddMv();   // 下で定義

// 重量の生の値を測る。外れ値を除いた平均を avg に、採用した値の最大-最小を spread に入れる。
// vddMv には、ブリッジに電流を流している最中の電池電圧(負荷時で、実際の状態に近い)を入れる。
// 電池電圧が LOW_VDD_MV 未満なら、測らずに lowBatt=true で false を返す。
// samples: 読み取り回数(HX_SAMPLES 以下)。keepOn: true なら測った後も HX711 の電源を切らない(較正モード用)
static bool measureRaw(int32_t& avg, uint16_t& spread, uint16_t& vddMv, bool& lowBatt, int samples, bool keepOn)
{
  int32_t buf[HX_SAMPLES];
  int n = 0;
  lowBatt = false;
  const bool wasOn = gHxOn;
  if (!wasOn)
  {
    hxPowerUp();
    delay(100);
  }
  vddMv = readVddMv();
  if (vddMv != 0 && vddMv < LOW_VDD_MV)
  {
    hxPowerDown();
    lowBatt = true;
    return false;
  }
  bool ok = true;
  for (int i = 0; i < (wasOn ? 1 : HX_DISCARD) && ok; i++)   // 電源を入れたままなら、前の変換の残り1回だけ捨てる
  {
    int32_t d;
    ok = hxReadOnce(d);
  }
  while (ok && n < samples)
  {
    int32_t v;
    if (!hxReadOnce(v)) { ok = false; break; }
    if (v == 0x7FFFFF || v == (int32_t)0xFF800000) continue;   // 飽和・異常値は捨てる
    buf[n++] = v;
  }
  if (!ok || !keepOn) hxPowerDown();
  if (n < samples / 2) return false;

  // 並べ替えて、上下 1/4 ずつを捨てた中央部分を平均する
  for (int i = 1; i < n; i++)
  {
    int32_t t = buf[i];
    int j = i - 1;
    while (j >= 0 && buf[j] > t) { buf[j + 1] = buf[j]; j--; }
    buf[j + 1] = t;
  }
  const int lo = n / 4;
  const int hi = n - n / 4;   // [lo, hi)
  int64_t sum = 0;
  for (int i = lo; i < hi; i++) sum += buf[i];
  avg = (int32_t)(sum / (hi - lo));
  int32_t sp = buf[hi - 1] - buf[lo];
  spread = (uint16_t)(sp > 65535 ? 65535 : sp);
  return true;
}

// ====================================================================
// 電池電圧・ノード内温度
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
static void putI32(uint8_t* p, int32_t v)
{
  const uint32_t u = (uint32_t)v;
  p[0] = (uint8_t)u; p[1] = (uint8_t)(u >> 8); p[2] = (uint8_t)(u >> 16); p[3] = (uint8_t)(u >> 24);
}

static void startAdvertising()
{
  const uint16_t iv = gCalMode ? CAL_ADV_INTERVAL : ADV_INTERVAL;
  Bluefruit.Advertising.stop();
  Bluefruit.Advertising.clearData();
  Bluefruit.Advertising.addFlags(BLE_GAP_ADV_FLAGS_LE_ONLY_GENERAL_DISC_MODE);
  Bluefruit.Advertising.addManufacturerData(gAdv, sizeof(gAdv));
  Bluefruit.Advertising.setType(BLE_GAP_ADV_TYPE_NONCONNECTABLE_SCANNABLE_UNDIRECTED);
  Bluefruit.Advertising.setInterval(iv, iv);
  Bluefruit.Advertising.restartOnDisconnect(false);
  Bluefruit.Advertising.start(0);   // 0 = 止めるまで出し続ける
}

// 測って広告データを作り直す
static void measureAndUpdate()
{
  Bluefruit.Advertising.stop();   // 測定中は無線を止める

  int32_t raw = 0;
  uint16_t spread = 0xFFFF;
  uint16_t mv = 0;
  bool lowBatt = false;
  bool ok = gCalMode ? measureRaw(raw, spread, mv, lowBatt, HX_CAL_SAMPLES, true)
                     : measureRaw(raw, spread, mv, lowBatt, HX_SAMPLES, false);
  if (mv == 0) mv = readVddMv();   // 測定前に測れていなければ、ここで測る
  const int16_t temp100 = readDieTemp100();

  if (!ok)
  {
    // 測定停止の印: ばらつき=0xFFFF。生の値の欄に理由を入れる(1=電池電圧が低い 2=HX711の読み取り失敗)。
    // ハブは電池電圧つきでサーバーに送り、サーバーは記録(重量)には入れず、状態(電池交換の表示)だけ更新する。
    Serial.println(lowBatt ? "Battery low: measurement skipped. Replace the batteries." : "HX711: read failed (wiring? power?)");
    raw = lowBatt ? 1 : 2;
    spread = 0xFFFF;
  }
  gSeq++;

  gAdv[0] = 0xFF; gAdv[1] = 0xFF;
  gAdv[2] = 'W';  gAdv[3] = 1;
  putU16(&gAdv[4], gSeq);
  putI32(&gAdv[6], raw);
  putU16(&gAdv[10], (uint16_t)temp100);
  putU16(&gAdv[12], spread);
  putU16(&gAdv[14], mv);

  Serial.printf("#%u raw=%ld spread=%u temp=%.2fC vdd=%umV%s%s\n",
                (unsigned)gSeq, (long)raw, (unsigned)spread, temp100 / 100.0f, (unsigned)mv, ok ? "" : " [FAILED]",
                gCalMode ? " [CAL]" : "");
  startAdvertising();
}

// ====================================================================
// Arduino
// ====================================================================

void setup()
{
  Serial.begin(115200);   // USBを挿していないときは待たない
  pinMode(PIN_HX_DOUT, INPUT);
  pinMode(PIN_HX_SCK, OUTPUT);
  hxPowerDown();

  Bluefruit.begin();
  Bluefruit.setTxPower(TX_POWER_DBM);
  Bluefruit.setName("HPXW");
  Bluefruit.autoConnLed(false);   // 接続用LEDは使わない(省電力)
  Bluefruit.ScanResponse.addName();   // 名前はスキャン応答に入れる(ハブは能動スキャンで受け取る)

  ble_gap_addr_t a = Bluefruit.getAddr();
  Serial.printf("\n%s\nBLE address: %02X:%02X:%02X:%02X:%02X:%02X  (ハブの登録画面に出るアドレスと同じ)\n",
                NODE_VERSION, a.addr[5], a.addr[4], a.addr[3], a.addr[2], a.addr[1], a.addr[0]);

  measureAndUpdate();
}

void loop()
{
  if (gCalMode)
  {
    // 較正モード: 2秒ごとに測る。10分たったら通常(30分ごと)に戻す
    static uint32_t last = 0;
    const uint32_t now = millis();
    if (now >= CAL_WINDOW_MS)
    {
      gCalMode = false;
      if (gHxOn) hxPowerDown();
      Serial.println("Calibration window ended. Measuring every 30 minutes.");
      measureAndUpdate();   // 広告の間隔も通常に戻す
      return;
    }
    if (now - last < CAL_INTERVAL_MS)
    {
      delay(CAL_INTERVAL_MS - (now - last));
      return;
    }
    last = millis();
    measureAndUpdate();
    return;
  }
  delay(MEASURE_INTERVAL_MS);   // FreeRTOSのティックレスアイドルでスリープする
  measureAndUpdate();
}
