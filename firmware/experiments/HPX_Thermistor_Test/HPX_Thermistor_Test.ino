// ====================================================================
// HPX_Thermistor_Test.ino   Hive Plus X  サーミスター温度の動作確認
//
// 対象   : Seeed XIAO nRF52840 (通常版)  /  ボードパッケージ「Seeed nRF52 Boards」(Bluefruit系)
// 部品   : 秋月 107257 サーミスター 10kΩ (SEMITEC 103AT-11, B=3435)
//          固定抵抗 10kΩ 1% 金属皮膜、0.1µF
//
// 配線   : D3 ──[10kΩ]──┬── A2(D2)
//                        ├── 0.1µF ── GND
//                        └── サーミスター ── GND
//          D0・D1 は HX711 用に空けてある(HPXW と同じ割り当て)。
//
// 動作   : 2秒ごとに測って、シリアル(115200)へ ADC値・抵抗値・温度(Steinhart-Hart と B定数式の両方)を出す。
//          ADCの基準はVDD(AR_VDD4)なので、電池電圧が変わっても読み値は変わらない(レシオメトリック)。
//
// 注意   : このコードはコンパイル・実機での確認をしていない(作成環境にツールチェーンが無いため)。
// ====================================================================

#include <Arduino.h>

constexpr uint8_t PIN_TH_PWR = D3;   // 分圧の電源。測るときだけ HIGH
constexpr uint8_t PIN_TH_ADC = A2;   // 分圧の中点

constexpr float R_FIXED = 10000.0f;  // 上側の固定抵抗(実測値があれば入れる)
constexpr uint32_t SETTLE_MS = 10;   // 通電してから読むまでの待ち(0.1µF × 5kΩ の時定数 約0.5ms の20倍)
constexpr float OFFSET_C = 0.0f;     // 1点校正のオフセット(氷水やIBS-TH2との差。表示 − 正しい値 を入れる)

// Steinhart-Hart 係数(SEMITEC AT 標準表の 0℃=27.28k, 25℃=10.00k, 50℃=4.160k から計算)
constexpr float SH_A = 8.880739e-4f;
constexpr float SH_B = 2.514252e-4f;
constexpr float SH_C = 1.922794e-7f;

// B定数の式(比較用。0℃以下で1〜2℃高く出る)
constexpr float B_VALUE = 3435.0f;
constexpr float R25 = 10000.0f;

// 測る。ok=false はサーミスターが外れている(開放)かショートしている
static bool readThermistor(uint16_t& adc, float& rTh)
{
  digitalWrite(PIN_TH_PWR, HIGH);
  delay(SETTLE_MS);
  adc = (uint16_t)analogRead(PIN_TH_ADC);
  digitalWrite(PIN_TH_PWR, LOW);

  if (adc < 20 || adc > 4075) return false;   // 約-50℃より低い/105℃より高い相当 → 配線異常とみなす
  rTh = R_FIXED * (float)adc / (4095.0f - (float)adc);
  return true;
}

static float tempSteinhart(float r)
{
  const float L = logf(r);
  return 1.0f / (SH_A + SH_B * L + SH_C * L * L * L) - 273.15f - OFFSET_C;
}

static float tempBeta(float r)
{
  return 1.0f / (1.0f / 298.15f + logf(r / R25) / B_VALUE) - 273.15f;
}

void setup()
{
  Serial.begin(115200);
  pinMode(PIN_TH_PWR, OUTPUT);
  digitalWrite(PIN_TH_PWR, LOW);

  analogReference(AR_VDD4);    // 基準 = VDD(分圧の電源と同じなので電池電圧の影響を受けない)
  analogReadResolution(12);    // 0〜4095
  analogOversampling(8);       // ハードで8回平均
}

void loop()
{
  uint16_t adc = 0;
  float r = 0;
  if (readThermistor(adc, r))
  {
    Serial.printf("adc=%u  R=%.0f ohm  T=%.2f C  (B式 %.2f C)\n", adc, r, tempSteinhart(r), tempBeta(r));
  }
  else
  {
    Serial.printf("adc=%u  サーミスターの配線を確認(%s)\n", adc, adc < 20 ? "ショート" : "外れている");
  }
  delay(2000);
}
