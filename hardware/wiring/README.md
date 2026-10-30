# 配線図

各機器の配線図です (ブレッドボード風)。PNG はそのまま見る用、SVG は拡大しても荒れない元データです。
図は `gen_wiring.py` で作っています (`python3 gen_wiring.py <出力フォルダ>` で SVG を作り直せます)。

| 機器 | 図 | ピン (ファーム) |
|---|---|---|
| HPX-W (重量) | [HPX-W_wiring.png](HPX-W_wiring.png) | HX711 DT=D0, SCK=D1 (HPX_WeightNode v1.01) |
| HPX-A (電柵アラート) | [HPX-A_wiring.png](HPX-A_wiring.png) | D1 → 10kΩ → D0(A0) → フォトトランジスタ C、E → GND (HPX_Alert v1.02) |
| HPX-Core (親機) | [HPX-Core_wiring.png](HPX-Core_wiring.png) | 外付けの配線なし。USB 電源のみ (HPX_Core v1.15) |

HPX-TH は今は市販の Inkbird IBS-TH2 を使っているので、配線図はありません。

## まだ確かめていないこと

- ロードセル SC601 の線の色の割り当て (図は一般的な 赤=E+ 黒=E- 白=A- 緑=A+)
- フォトトランジスタの C・E の見分け方 (部品ごとにデータシートで確認)
- HPX-Core の図はボードのイメージで、ボタンの位置は実物と違うことがあります
