# 配線図

各機器の配線図です (ブレッドボード風)。PNG はそのまま見る用、SVG は拡大しても荒れない元データです。
図は `gen_wiring.py` で作っています (`python3 gen_wiring.py <出力フォルダ>` で SVG を作り直せます)。

| 機器 | 図 | ピン (ファーム) |
|---|---|---|
| HPX-W (重量) | [HPX-W_wiring.png](HPX-W_wiring.png) | HX711 DT=D0, SCK=D1 (HPX_WeightNode v1.01)。電源 CR123A |
| HPX-TH (温度) | [HPX-TH_wiring.png](HPX-TH_wiring.png) | D3 → 10kΩ → A2、サーミスター A2–GND (試験スケッチ HPX_Thermistor_Test)。電源 CR123A |
| HPX-A (電柵アラート) | [HPX-A_wiring.png](HPX-A_wiring.png) | D1 → 10kΩ → D0(A0)、CdS セル D0–GND (HPX_Alert v1.02)。電源 CR123A |
| HPX-Core (親機) | [HPX-Core_wiring.png](HPX-Core_wiring.png) | CR123A をボードの 3V3 ピンへ (試験用の構成、HPX_Core v1.15) |

| HPX-Core 18650 案 | [HPX-Core_18650_wiring.png](HPX-Core_18650_wiring.png) | 18650 → 3.3V LDO → ボードの 3V3。ディープスリープ・15分ごとで約1〜2か月の見込み (目安、未実測) |

部品表は [部品表.md](部品表.md) にあります。

## まだ確かめていないこと

- ロードセル SC601 の線の色の割り当て (図は一般的な 赤=E+ 黒=E- 白=A- 緑=A+)
- HPX-A の CdS セルで警報器の点滅を数えられるか (CdS は反応が遅め)
- HPX-Core の図はボードのイメージで、3V3・GND ピンやボタンの位置は実物と違うことがあります
- HPX-Core を CR123A (約3V) で動かせるか (ESP32 の推奨は 3.0〜3.6V)
