# 配線図

各機器の配線図です。HPX-W・HPX-TH・HPX-F は、小さなユニバーサル基板 (2.54mm ピッチ、HPX-W は穴 12×11 ≒ 33×30mm、HPX-TH は穴 13×11 ≒ 35×30mm、HPX-F は 5×7cm の基板 (穴 A〜X × 1〜18) の左上) に部品を載せてはんだ付けする図です。穴の位置は「列の文字＋行の数字」(例: M4) で書いてあります。PNG はそのまま見る用、SVG は拡大しても荒れない元データです。

**作り方 (すべての図で共通、2026-11-04 から)**: 部品はすべて表 (部品面) から差し、線はすべて裏 (はんだ面) で、すずめっき線を穴から穴へはんだ付けしてつなぎます。図の上半分は表から見たところ (裏の線は点線で透かして描く)、下半分の「裏から見た図」は基板を左右に裏返した向きで、実際に配線するときに見る図です。裏の線どうしは交わらず、ほかの線の穴の上も通りません (`gen_pcb.py` が図を作るときに自動で確かめます。同じ線 (＋の列・−の列) がコンデンサーの足の穴を通るのは、そこではんだ付けするためで問題ありません)。

| 機器 | 図 | ピン (ファーム) |
|---|---|---|
| HPX-W (重量) | [HPX-W_pcb.png](HPX-W_pcb.png) | HX711 DT=D0, SCK=D1 (HPX_WeightNode v1.01)。電源 CR123A |
| HPX-TH (温湿度) | [HPX-TH_pcb.png](HPX-TH_pcb.png) | SHT40 (SDA=D4, SCL=D5、ピンは上から SDA・SCL・GND・VIN) + サーミスター (D3 → 10kΩ → A2、A2–GND)。電源 CR123A |
| HPX-F (電柵アラート) | [HPX-F_pcb.png](HPX-F_pcb.png) | D1 → 10kΩ → D0(A0)、CdS セル D0–GND (HPX_F v1.04)。電源 単3リチウム×2 (ケース WH145-25-M2 の電池ボックス) |
| HPX-Core ＋防水 | [HPX-Core_wiring.png](HPX-Core_wiring.png) | Olimex ESP32-DEVKIT-LIPO。外付けの配線なし。防水USBカプラー (USB-C) から USB-C to MicroUSB ケーブルで給電 |
| HPX-Core ＋有線LAN | [HPX-Core_LAN_pcb.png](HPX-Core_LAN_pcb.png) (6×8cm 基板に組む図)・[HPX-Core_LAN_wiring.png](HPX-Core_LAN_wiring.png) (線の対応) | ELEGOO ESP-32 + W5500 (ACEIRMC): SCK=D18, MI=D4, MO=D23, CS=D5, INT=TX2(GPIO17), RST=RX2(GPIO16), V=3V3, G=GND (HPX_Core v1.20 以降、接続方法を「有線LAN」に)。USB 電源 |
| HPX-Core ＋有線LAN＆ソーラーシステムチェック | [HPX-Core_LANV_pcb.png](HPX-Core_LANV_pcb.png) (6×8cm 基板、ケース PFF10-4-10W) | 「＋有線LAN」と同じ ESP-32・W5500 に、パネル＋ → 100kΩ/8.2kΩ → D34、バッテリー＋ → 100kΩ/15kΩ → D35、BP5293-50 (バッテリー → 5V → VIN)、端子台 3極 (マイナス・バッテリー＋・パネル＋) を足す。USB ではなくバッテリーで動かす (HPX_Core v1.22 以降、動作設定で「バッテリー・パネルの電圧を測る」) |
| HPX-Core ＋防水＆電池駆動 | [HPX-Core_BAT_pcb.png](HPX-Core_BAT_pcb.png) (ケース用基板 PCB36-47 に組む図) | Olimex ESP32-DEVKIT-LIPO の 5V (左の列のピン19) ← 単三リチウム×3 の＋、GND (左の列のピン14) ← −。BAT コネクタは使わない。動作モードはディープスリープ |
| HPX-Core 基本モデル | [HPX-Core_BASIC_pcb.png](HPX-Core_BASIC_pcb.png) (6×8cm 基板に ELEGOO ESP-32 をピンソケットで差すだけ。＋有線LAN から W5500 を外したもの、ケース PFF10-4-10W) | Wi-Fi ＋ USB 給電。線なし |
| HPX-Core ＋LTE-M (試作中) | [HPX-Core_LTE_pcb.png](HPX-Core_LTE_pcb.png) (タカチ PCB72-95 に Olimex・M5Stamp CatM・抵抗、ケース タカチ OB10-17-5W)・[HPX-Core_LTE_wiring.png](HPX-Core_LTE_wiring.png) (HPX-Solar との線の対応) | Olimex ESP32-DEVKIT-LIPO + M5Stamp CatM (SIM7080G、TX→D16・RX←D17)。電源は HPX-Solar から6本 (BAT＋・BAT− → Olimex BAT、5V・GND → CatM、EN←D25 (送るときだけ ON)、PV)。電池＋ → 100kΩ/100kΩ → D34、パネル＋ → 100kΩ/20kΩ → D36。ファーム HPX-Core v1.24 |
| HPX-Solar (試作中、小型ソーラー電源) | [HPX-Solar_pcb.png](HPX-Solar_pcb.png) (タカチ PCB72-95 に USB-C メス基板・DFR0559・18650 ホルダー×2、ケース タカチ OB10-17-5W) | 10W/5V パネル (USB-C のまま USB-C メス基板 (5.1kΩ 付き) で受けて DFR0559 SOLAR へ) + 18650×2 並列 (DFR0559 BAT へ)。HPX-Core ＋LTE-M へ6本のケーブル。DFR0559 の 5V 出力はディープスリープで動く機器 (＋防水・HPX-Camera) にも使える見込み |
| HPX-B (バッテリー電圧、試作中) | [HPX-B_pcb.png](HPX-B_pcb.png) (6×8cm 基板、ケース タカチ PFF10-4-10W) | バッテリー＋ → 100kΩ/20kΩ → D1(A1)、パネル＋ → 100kΩ/10kΩ → D0(A0)、三端子 DCDC レギュレーター BP5293-50 (ROHM、入力 7〜26V) → 5V、入力側 10µF。端子台 3極 (パネル＋・マイナス・バッテリー＋)、ヒューズ 1A×2 は線の途中。ファームはまだ無い |
| HPX-Camera (巣門カメラ、試作中・仮の名前) | [HPX-Camera_pcb.png](HPX-Camera_pcb.png) (穴 12×11 の基板、ケース タカチ BCAP091207T) | XIAO ESP32S3 Sense (カメラ・microSD は Sense 基板)。単三リチウム×3 の＋ → ショットキーダイオード 1N5819 → 5V、−→GND。電池の電圧は ダイオードの前で 100kΩ/100kΩ (1/2) → D0(A0)、0.1µF。100µF を 5V–GND に。D8〜D10 は microSD が使う。ファームはまだ無い |

部品表は [部品表.md](部品表.md) にあります。

ウェブサイトの機器ページには、図だけの画像 (プログラムが出す `<名前>_fig.svg` を PNG にしたもの) を `site/img/wiring/<名前>.png` として載せ、つなぐ線の表・部品の置き場所・注意は `<名前>.json` から `hardware/parts/gen_device_pages.py` が HTML で作ります。

図は `gen_pcb.py` (基板の図) と `gen_wiring.py` (HPX-Core の図) で作っています。`python3 gen_pcb.py <出力フォルダ>` で SVG を作り直せます。

## まだ確かめていないこと
、SIM7080G の UART を 921600bps にできるか (AT+IPR)
- HPX-Core ＋LTE-M・HPX-Solar: DFR0559 の EN の向き (High で ON か) と初期状態、DFR0559 の 5V 出力に USB の口があるか、M5Stamp CatM の信号電圧 (3.3V で使えるか) とピンの並び・電源を入れたら自動で起動するか、Olimex の BAT コネクタの＋−の向き、DFR0559 の GND が SOLAR−・BAT− と共通か、2つをつなぐケーブルのコネクタ、ケース (あとで考え直す)、DFR0559 と 18650 ホルダーの実寸
- ロードセル SC601 の線の色の割り当て (図は一般的な 赤=E+ 黒=E- 白=A- 緑=A+)
- HPX-F の CdS セルで警報器の点滅を数えられるか (CdS は反応が遅め)
- SHT40 モジュール・HX711 モジュールのピンの並び (基板の印字で確認)
- 基板の図は実際に組んで確かめていません
- HPX-B: BP5293-50 の足の並び (データシートで確認)、PFF10-4-10W の内側の寸法と基板の留め方、使うソーラーコントローラーが共通マイナスか (違うとパネル電圧は測れない) は未確認
- HPX-Core の有線LAN (W5500) は実機で確かめていません (ファーム v1.20 はコンパイルだけ確認)。ESP-32 の左右のピン列 10穴・W5500 の左右のピン列 8穴は計算で、実物では未確認。W5500 のピンがモジュールの上下のどこにあるかも未確認
- HPX-Camera: XIAO ESP32S3 Sense の眠り中の電流、1N5819 を入れたときの Wi-Fi 送信中の電圧、カメラがケースのふたに届く高さ (電池ボックスの上に重ねる案) は未確認
- HPX-Core 電池版: PCB36-47 の穴の数 (13×17) は見込み。ケースの深さに収まるか、Olimex の 5V 端子の先の HX6211 の入力上限 (販売店の資料で 6V) は未確認

ケースに合わせたタカチ基板 (TNF39-70・TNF34-124・TNF44-69) の図は [case/](case/) にあります。
