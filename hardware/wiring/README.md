# 配線図

各機器の配線図です。HPX-W・HPX-TH・HPX-A は、小さなユニバーサル基板 (2.54mm ピッチ、HPX-W は穴 12×11 ≒ 33×30mm、HPX-TH は穴 13×11 ≒ 35×30mm、HPX-A は 5×7cm の基板 (穴 A〜X × 1〜18) の左上) に部品を載せてはんだ付けする図です。穴の位置は「列の文字＋行の数字」(例: M4) で書いてあります。PNG はそのまま見る用、SVG は拡大しても荒れない元データです。

**作り方 (すべての図で共通、2026-11-04 から)**: 部品はすべて表 (部品面) から差し、線はすべて裏 (はんだ面) で、すずめっき線を穴から穴へはんだ付けしてつなぎます。図の上半分は表から見たところ (裏の線は点線で透かして描く)、下半分の「裏から見た図」は基板を左右に裏返した向きで、実際に配線するときに見る図です。裏の線どうしは交わらず、ほかの線の穴の上も通りません (`gen_pcb.py` が図を作るときに自動で確かめます。同じ線 (＋の列・−の列) がコンデンサーの足の穴を通るのは、そこではんだ付けするためで問題ありません)。

| 機器 | 図 | ピン (ファーム) |
|---|---|---|
| HPX-W (重量) | [HPX-W_pcb.png](HPX-W_pcb.png) | HX711 DT=D0, SCK=D1 (HPX_WeightNode v1.01)。電源 CR123A |
| HPX-TH (温湿度) | [HPX-TH_pcb.png](HPX-TH_pcb.png) | SHT40 (SDA=D4, SCL=D5、ピンは上から SDA・SCL・GND・VIN) + サーミスター (D3 → 10kΩ → A2、A2–GND)。電源 CR123A |
| HPX-A (電柵アラート) | [HPX-A_pcb.png](HPX-A_pcb.png) | D1 → 10kΩ → D0(A0)、CdS セル D0–GND (HPX_Alert v1.03)。電源 単3リチウム×2 (ケース WH145-25-M2 の電池ボックス) |
| HPX-Core (親機) | [HPX-Core_wiring.png](HPX-Core_wiring.png) | 外付けの配線なし。USB 電源 (HPX_Core v1.15) |
| HPX-Core 有線LAN (付けたいときだけ) | [HPX-Core_LAN_pcb.png](HPX-Core_LAN_pcb.png) (6×8cm 基板に組む図)・[HPX-Core_LAN_wiring.png](HPX-Core_LAN_wiring.png) (線の対応) | ELEGOO ESP-32 + W5500 (ACEIRMC): SCK=D18, MI=D4, MO=D23, CS=D5, INT=TX2(GPIO17), RST=RX2(GPIO16), V=3V3, G=GND (HPX_Core v1.20 以降、接続方法を「有線LAN」に)。USB 電源 |

部品表は [部品表.md](部品表.md) にあります。

ウェブサイトの機器ページには、図だけの画像 (プログラムが出す `<名前>_fig.svg` を PNG にしたもの) を `site/img/wiring/<名前>.png` として載せ、つなぐ線の表・部品の置き場所・注意は `<名前>.json` から `hardware/parts/gen_device_pages.py` が HTML で作ります。

図は `gen_pcb.py` (基板の図) と `gen_wiring.py` (HPX-Core の図) で作っています。`python3 gen_pcb.py <出力フォルダ>` で SVG を作り直せます。

## まだ確かめていないこと

- ロードセル SC601 の線の色の割り当て (図は一般的な 赤=E+ 黒=E- 白=A- 緑=A+)
- HPX-A の CdS セルで警報器の点滅を数えられるか (CdS は反応が遅め)
- SHT40 モジュール・HX711 モジュールのピンの並び (基板の印字で確認)
- 基板の図は実際に組んで確かめていません
- HPX-Core の有線LAN (W5500) は実機で確かめていません (ファーム v1.20 はコンパイルだけ確認)。ESP-32 の左右のピン列 10穴・W5500 の左右のピン列 8穴は計算で、実物では未確認。W5500 のピンがモジュールの上下のどこにあるかも未確認

ケースに合わせたタカチ基板 (TNF39-70・TNF34-124・TNF44-69) の図は [case/](case/) にあります。
