// ====================================================================
// HPX_TH_v1_00.ino   Hive Plus X  巣箱の温湿度センサ (HPX-TH)
//
// 対象   : Seeed XIAO nRF52840 (通常版)  /  ボードパッケージ「Seeed nRF52 Boards」(Bluefruit系)
//          Arduino IDE のボード: "Seeed XIAO nRF52840"
// 構成   : SHT40 モジュール (温度・湿度、I2C: SDA=D4, SCL=D5, VIN=3V3)
//          サーミスター 10kΩ (SEMITEC 103AT-11, B=3435): D3 ──[10kΩ 1%]──┬── A2
//                                                                      ├── 0.1µF ── GND
//                                                                      └── サーミスター ── GND
//          電池は CR123A を 3V3 ピンへ直接 (配線図 hardware/wiring/HPX-TH_pcb.png)。
// 役割   : BLEの広告で「温度・湿度・サーミスター温度・電池電圧」を出し続けるだけ(接続・ペアリング不要)。
//          ハブ(HPX_Core v1.16 以降)が受信してサーバーへ送る。
//
// 動作   : 起動時と、その後 MEASURE_INTERVAL_MS(10分)ごとに測って広告データを更新する。
//          それ以外の時間は広告(1秒間隔)を出しながらスリープする。
//          電池を入れて(またはリセットして)から START_WINDOW_MS(5分)の間は、取り付け確認しやすいよう
//          START_INTERVAL_MS(10秒)ごとに測る。
//
// 広告データ(メーカーデータ16バイト。ハブ側 HPX_Core v1.16 の bleParseT と同じ):
//   [0..1]   0xFF 0xFF   (会社ID。試験用の値。HPX-W・HPX-F と同じ)
//   [2]      'T'
//   [3]      版 = 1
//   [4..5]   通し番号 (uint16, LE)
//   [6..7]   SHT40 の温度 ×100 (int16, LE)      0x7FFF = 測れなかった
//   [8..9]   SHT40 の湿度 ×100 (uint16, LE)     0xFFFF = 測れなかった
//   [10..11] サーミスターの温度 ×100 (int16, LE) 0x7FFF = 測れなかった(外れている・ショート)
//   [12..13] ノード内の温度 ×100 (int16, LE)    nRF52840内部の温度センサー(参考)
//   [14..15] 電池電圧 mV (uint16, LE)  0 のときは測れなかった
//   広告の名前(スキャン応答)は "HPXT"。ハブの「BLEセンサの登録」画面に [温湿度センサ HPX-TH] と出る。
//
// 注意   : このコードは実機での確認をしていない。書き込み後はシリアルモニター(115200)で値を確認すること。
//          USB で書き込むときは電池を外す。
// ====================================================================

#include <bluefruit.h>
#include <Wire.h>

#define NODE_VERSION "HPX-TH.1.00"

// ---- 設定 ----
// 試験用に短くしたいときは 30 * 1000UL などに変える(電池が早く減る)
constexpr uint32_t MEASURE_INTERVAL_MS = 10UL * 60UL * 1000UL;

// 起動直後の確認モード: この時間は短い間隔で測る
constexpr uint32_t START_WINDOW_MS = 5UL * 60UL * 1000UL;
constexpr uint32_t START_INTERVAL_MS = 10UL * 1000UL;

// SHT40
constexpr uint8_t SHT40_ADDR = 0x44;  // SHT40-AD1B(よくあるモジュール)。0x45 の品種もある
constexpr uint8_t SHT40_CMD_MEASURE = 0xFD;   // 高精度で測る(約8.3ms)

// サーミスター
constexpr uint8_t PIN_TH_PWR = D3;    // 分圧の電源。測るときだけ HIGH
constexpr uint8_t PIN_TH_ADC = A2;    // 分圧の中点
constexpr float R_FIXED = 10000.0f;   // 上側の固定抵抗(実測値があれば入れる)
constexpr uint32_t TH_SETTLE_MS = 10; // 通電してから読むまでの待ち
constexpr float TH_OFFSET_C = 0.0f;   // 1点校正のオフセット(表示 − 正しい値 を入れる)
// Steinhart-Hart 係数(SEMITEC AT 標準表の 0℃=27.28k, 25℃=10.00k, 50℃=4.160k から計算)
constexpr float SH_A = 8.880739e-4f;
constexpr float SH_B = 2.514252e-4f;
constexpr float SH_C = 1.922794e-7f;

constexpr uint16_t ADV_INTERVAL = 1600;   // 広告間隔(0.625ms単位。1600 = 1秒)
constexpr int8_t TX_POWER_DBM = 0;        // 送信出力。巣箱とハブの距離が遠いときは 4 まで上げられる(電池は減る)

#define USE_VDD_MEASURE 1   // 電池電圧をSAADCの内部チャンネルで測る。コンパイルが通らなければ 0 にする(電池電圧は0=不明で送る)

constexpr int16_t NO_TEMP = 0x7FFF;
constexpr uint16_t NO_HUM = 0xFFFF;

// ---- 状態 ----
static uint16_t gSeq = 0;
static uint8_t gAdv[16];

// ====================================================================
// SHT40
// ====================================================================

// SHT40 の CRC-8 (多項式 0x31、初期値 0xFF)
static uint8_t shtCrc(const uint8_t* p)
{
  uint8_t c = 0xFF;
  for (int i = 0; i < 2; i++)
  {
    c ^= p[i];
    for (int b = 0; b < 8; b++) c = (c & 0x80) ? (uint8_t)((c << 1) ^ 0x31) : (uint8_t)(c << 1);
  }
  return c;
}

// 温度×100 と 湿度×100 を測る。失敗したら false
static bool readSht40(int16_t& t100, uint16_t& h100)
{
  Wire.beginTransmission(SHT40_ADDR);
  Wire.write(SHT40_CMD_MEASURE);
  if (Wire.endTransmission() != 0) return false;
  delay(10);
  uint8_t b[6];
  if (Wire.requestFrom(SHT40_ADDR, (size_t)6) != 6) return false;
  for (int i = 0; i < 6; i++) b[i] = (uint8_t)Wire.read();
  if (shtCrc(&b[0]) != b[2] || shtCrc(&b[3]) != b[5]) return false;
  const uint32_t rt = ((uint32_t)b[0] << 8) | b[1];
  const uint32_t rh = ((uint32_t)b[3] << 8) | b[4];
  // データシートの式: T = -45 + 175 * raw / 65535、RH = -6 + 125 * raw / 65535 (0〜100% に収める)
  t100 = (int16_t)(-4500 + (int32_t)((rt * 17500UL + 32767UL) / 65535UL));
  int32_t h = -600 + (int32_t)((rh * 12500UL + 32767UL) / 65535UL);
  if (h < 0) h = 0;
  if (h > 10000) h = 10000;
  h100 = (uint16_t)h;
  return true;
}

// ====================================================================
// サーミスター
// ====================================================================

// 温度×100 を測る。外れている・ショートしているときは false
static bool readThermistor(int16_t& t100)
{
  analogReference(AR_VDD4);    // 基準 = VDD(分圧の電源と同じなので電池電圧の影響を受けない)
  analogReadResolution(12);    // 0〜4095
  digitalWrite(PIN_TH_PWR, HIGH);
  delay(TH_SETTLE_MS);
  const uint16_t adc = (uint16_t)analogRead(PIN_TH_ADC);
  digitalWrite(PIN_TH_PWR, LOW);

  if (adc < 20 || adc > 4075) return false;   // 約-50℃より低い/105℃より高い相当 → 配線異常とみなす
  const float r = R_FIXED * (float)adc / (4095.0f - (float)adc);
  const float L = logf(r);
  const float t = 1.0f / (SH_A + SH_B * L + SH_C * L * L * L) - 273.15f - TH_OFFSET_C;
  t100 = (int16_t)lroundf(t * 100.0f);
  return true;
}

// ====================================================================
// 電池電圧・ノード内温度 (HPX-W と同じ)
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

// 測って広告データを作り直す
static void measureAndUpdate()
{
  int16_t t100 = NO_TEMP;
  uint16_t h100 = NO_HUM;
  int16_t th100 = NO_TEMP;
  const bool shtOk = readSht40(t100, h100);
  if (!shtOk)
  {
    t100 = NO_TEMP;
    h100 = NO_HUM;
  }
  const bool thOk = readThermistor(th100);
  if (!thOk) th100 = NO_TEMP;
  const uint16_t mv = readVddMv();
  const int16_t die100 = readDieTemp100();
  gSeq++;

  gAdv[0] = 0xFF; gAdv[1] = 0xFF;
  gAdv[2] = 'T';  gAdv[3] = 1;
  putU16(&gAdv[4], gSeq);
  putU16(&gAdv[6], (uint16_t)t100);
  putU16(&gAdv[8], h100);
  putU16(&gAdv[10], (uint16_t)th100);
  putU16(&gAdv[12], (uint16_t)die100);
  putU16(&gAdv[14], mv);

  Serial.printf("#%u ", (unsigned)gSeq);
  if (shtOk) Serial.printf("SHT40 %.2fC %.2f%%  ", t100 / 100.0f, h100 / 100.0f);
  else Serial.print("SHT40 読めない(配線・アドレスを確認)  ");
  if (thOk) Serial.printf("サーミスター %.2fC  ", th100 / 100.0f);
  else Serial.print("サーミスター 読めない(外れている・ショート)  ");
  Serial.printf("ノード内 %.2fC  電池 %umV\n", die100 / 100.0f, (unsigned)mv);

  startAdvertising();
}

// ====================================================================
// Arduino
// ====================================================================

void setup()
{
  Serial.begin(115200);   // USBを挿していないときは待たない
  pinMode(PIN_TH_PWR, OUTPUT);
  digitalWrite(PIN_TH_PWR, LOW);
  Wire.begin();           // XIAO nRF52840 の Wire は SDA=D4, SCL=D5

  Bluefruit.begin();
  Bluefruit.setTxPower(TX_POWER_DBM);
  Bluefruit.setName("HPXT");
  Bluefruit.autoConnLed(false);   // 接続用LEDは使わない(省電力)
  Bluefruit.ScanResponse.addName();   // 名前はスキャン応答に入れる(ハブは能動スキャンで受け取る)

  ble_gap_addr_t a = Bluefruit.getAddr();
  Serial.printf("\n%s\nBLE address: %02X:%02X:%02X:%02X:%02X:%02X  (ハブの登録画面に出るアドレスと同じ)\n",
                NODE_VERSION, a.addr[5], a.addr[4], a.addr[3], a.addr[2], a.addr[1], a.addr[0]);

  measureAndUpdate();
}

void loop()
{
  const bool starting = millis() < START_WINDOW_MS;
  delay(starting ? START_INTERVAL_MS : MEASURE_INTERVAL_MS);   // FreeRTOSのティックレスアイドルでスリープする
  measureAndUpdate();
}
