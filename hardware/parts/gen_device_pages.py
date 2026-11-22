# 機器のページ (site/devices.html と site/device-*.html) を parts.csv から作る。
#   使い方: python3 hardware/parts/gen_device_pages.py   (リポジトリの一番上で)
#   部品や数を変えるときは parts.csv を直してから、これを動かす。
#   parts.csv の列: id (代わりの品から指すときだけ), url (部品名のリンク), pack・unit・price (入数・単位・価格),
#   alt_of (その id の部品の「代わりに使えるもの」), 残りは機器の版ごとの数 (空ならその版では使わない)。
#   ヘッダー・フッター・色は site/app.html から取るので、サイトの見た目を変えたらこれも動かし直す。
import csv, html, json, re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SITE = ROOT / 'site'

# HPX-Core の選択チャート (TARO 2026-11-10)。電源 × 通信方法 → (印, 版の列名 または None, 説明)
#   印: ◎ = ぴったりの版がある、○ = 近い版を少し変えて作る、✕ = 今は版がない
CORE_POWER = [('home', '家庭用電源（USB）'), ('car', 'ソーラーパネル＋チャージャー＋カーバッテリー'),
              ('aa', '単三リチウム電池'), ('li', 'HPX-Solar（小型ソーラーパネル＋18650）')]
CORE_NET = [('lan', '有線ルーター（LAN ケーブル）'), ('wifi', 'Wi-Fi ルーター'), ('lte', 'LTE-M（ルーターなし）')]
#   4つめに True があるものは HPX-Solar (別の機器、TARO 2026-11-10) もいっしょに作る
CORE_CHOOSER = {
    ('home', 'lan'): ('◎', 'core', '「＋有線LAN」を作ります。USB 電源で動かします。'),
    ('home', 'wifi'): ('◎', 'core_basic', '「基本モデル」を作ります。Wi-Fi でルーターにつなぎ、USB 電源で動かします。雨のかかる屋外に置くときは「＋防水」を作ります。'),
    ('home', 'lte'): ('◎', 'core_lte', '「＋LTE-M」を作り、USB 充電器（5V・1A 以上）を USB-C 口に差します。動作設定の「電圧の測り方」は「測らない」にします。'),
    ('car', 'lan'): ('◎', 'core_lanv', '「＋有線LAN＆ソーラーシステムチェック」を作ります。カーバッテリーから 5V を作って動かし、バッテリーとパネルの電圧も見守れます。'),
    ('car', 'wifi'): ('○', 'core_lanv', '「＋有線LAN＆ソーラーシステムチェック」を作り、有線LANモジュール（W5500）を付けずに、接続方法を Wi-Fi にします。'),
    ('car', 'lte'): ('○', 'core_lte', '「＋LTE-M」を作り、チャージャーの USB 口か 12V→USB アダプターの 5V を USB-C 口に差します。電圧は「測らない」にして、バッテリーとパネルの電圧は HPX-B で見守ります。'),
    ('aa', 'lan'): ('✕', None, '有線LANモジュール（W5500）は眠っている間も電気を使う（約0.1A）ので、電池では動かせません。'),
    ('aa', 'wifi'): ('◎', 'core_bat', '「＋防水＆電池駆動」を作ります。単三リチウム電池3本で、ディープスリープで動かします。電源のコードも LAN ケーブルもないので、Wi-Fi の届く所ならどこにでも置けます（電池の交換は 2〜3か月ごとの見込み）。'),
    ('aa', 'lte'): ('✕', None, 'LTE-M は送るときに大きな電気を使うので、単三電池では長くもちません。「＋LTE-M」と電源の「HPX-Solar」を作ってください。'),
    ('li', 'lan'): ('✕', None, '有線LANモジュール（W5500）は電気を多く使うので、HPX-Solar（小型ソーラーパネル＋18650）には向きません。'),
    ('li', 'wifi'): ('○', 'core_wp', '「＋防水」と電源の「HPX-Solar」を作り、USB-C ケーブルで電気をもらいます。ケースの中を USB-C メス基板で 4本に分けると、電池とパネルの電圧も測れます。動作モードはディープスリープにします（ずっと動かすと冬に電気が足りない見込み）。', True),
    ('li', 'lte'): ('◎', 'core_lte', '「＋LTE-M」と電源の「HPX-Solar」を作り、USB-C ケーブル1本でつなぎます。ルーターがいらず、ソーラーパネルで 18650 を充電して動かし、電池とパネルの電圧も測れます。', True),
}

# 機器ごとの版 (parts.csv の数の列名, 画面に出す名前)
DEVICES = [
    dict(key='core', file='device-core.html', tag='ハブ', name='HPX-Core',
         lead='センサの記録を Bluetooth で集め、Wi-Fi・有線LAN・LTE のどれかでサーバーへ送る中心的な機器です。',
         # 版の名前は TARO 2026-11-07 に 通常版→有線LAN版、防水版→防水Wi-Fi版 (列名・?v= はそのまま)
         # TARO 2026-11-08: 防水Wi-Fi版を USB給電版 (core_wp、基板を Olimex に) と電池版 (core_bat) の2つに分けた
         # TARO 2026-11-08: 有線LAN＋電圧チェック版 (core_lanv) を追加 (12V バッテリーとソーラーパネルの電圧を測る)
         # TARO 2026-11-09: ＋LTE-M＆ソーラー版 (core_lte) を追加 (M5Stamp CatM で LTE-M、DFR0559＋18650×2＋5V パネル、ルーター不要)
         # TARO 2026-11-10: 電源の部分を HPX-Solar (別の機器) に分け、版の名前を ＋LTE-M に
         # TARO 2026-11-08: 版の名前を ＋有線LAN / ＋有線LAN＆ソーラーシステムチェック / ＋防水 / ＋防水＆電池駆動 に (基本モデル=Wi-Fi＋USB給電にオプションを足す考え方)
        # TARO 2026-11-10: 基本モデル (core_basic) = ＋有線LAN から W5500 を外したもの (Wi-Fi＋USB給電)
        variants=[('core_basic', '基本モデル'), ('core', '＋有線LAN'), ('core_lanv', '＋有線LAN＆ソーラーシステムチェック'), ('core_wp', '＋防水'), ('core_bat', '＋防水＆電池駆動'), ('core_lte', '＋LTE-M')],
         # 防水版の部品表は TARO 2026-11-06 の順 (ケーブルを最後に)
         # TARO 2026-11-10: 通信モジュール (チップボードの次) と その他 (最後) を追加
         cats=['チップボード', '通信モジュール', '電子部品', '電源', 'ケース', 'ケース用基板', '部材', 'ケーブル', 'その他'],
         no_cost_note=True,   # 部品代の目安の説明文を出さない (TARO 2026-11-06)
         # TARO 2026-11-13: 配線図を全部 FireBeetle 2 ESP32-E (DFR0654) に描き直した。ほかのボード (ELEGOO・Olimex・Freenove) の図は 5つ目の欄からリンクする (TARO 2026-11-13、どれも v1.25 のピンに描き直した)
         wiring=[('HPX-Core_BASIC_pcb.png', '基本モデルの配線図', 'FireBeetle 2 ESP32-E を 6×8cm のユニバーサル基板にピンソケットで差すだけの図です (線はありません)。「＋有線LAN」と同じ基板・同じ位置なので、あとから W5500 を足して「＋有線LAN」にできます。ルーターへは Wi-Fi でつなぎ、USB-C から電気をもらいます。', 'core_basic',
                 [('HPX-Core_BASIC_olimex_pcb.png', 'Olimex ESP32-DEVKIT-LIPO'), ('HPX-Core_BASIC_freenove_pcb.png', 'Freenove ESP32 WROOM (FNK0090)'), ('HPX-Core_BASIC_elegoo_pcb.png', 'ELEGOO ESP-32')]),
                ('HPX-Core_LAN_pcb.png', '＋有線LANの配線図', 'FireBeetle 2 ESP32-E と有線LANモジュール W5500 を 6×8cm のユニバーサル基板に並べて差す図です (表と裏、線はすべて裏)。ファームウェアは v1.25 以降を使います (FireBeetle に合わせて W5500 のピンを変えたため)。', 'core',
                 [('HPX-Core_LAN_olimex_pcb.png', 'Olimex ESP32-DEVKIT-LIPO'), ('HPX-Core_LAN_freenove_pcb.png', 'Freenove ESP32 WROOM (FNK0090)'), ('HPX-Core_LAN_elegoo_pcb.png', 'ELEGOO ESP-32')]),
                ('HPX-Core_LANV_pcb.png', '＋有線LAN＆ソーラーシステムチェックの配線図', '「＋有線LAN」と同じ並びの上の空いた所に、ソーラーで充電する 12V バッテリーとパネルの電圧を測る抵抗と、バッテリーから 5V を作る BP5293-50、端子台を足した図です (表と裏、線はすべて裏)。FireBeetle は USB ではなくバッテリーから VCC ピンへ 5V を入れて動かします。ファームウェアは v1.25 以降を使い、動作設定で「バッテリー・パネルの電圧を測る」にチェックします。', 'core_lanv',
                 [('HPX-Core_LANV_olimex_pcb.png', 'Olimex ESP32-DEVKIT-LIPO'), ('HPX-Core_LANV_elegoo_pcb.png', 'ELEGOO ESP-32')]),
                ('HPX-Core_wiring.png', '＋防水の配線図', 'ケースの中で FireBeetle 2 ESP32-E の USB-C と防水USBカプラーを短い USB-C ケーブルでつなぎ、外から USB 電源をつなぎます。外付けの配線はありません。HPX-Solar から電気をもらって電池とパネルの電圧も測るときは、右の枠のように USB-C メス基板で 4本に分けます (試作中、ファーム v1.25 以降)。', 'core_wp',
                 [('HPX-Core_olimex_wiring.png', 'Olimex ESP32-DEVKIT-LIPO'), ('HPX-Core_freenove_wiring.png', 'Freenove ESP32 WROOM (FNK0090)'), ('HPX-Core_elegoo_wiring.png', 'ELEGOO ESP-32')]),
                ('HPX-Core_BAT_pcb.png', '＋防水＆電池駆動の配線図', 'ケース用基板 PCB36-47 に FireBeetle 2 ESP32-E をピンソケットで差し、ケース WH145-25-M3 の電池ボックス (単三リチウム3本、約4.5〜5.4V) の線を FireBeetle の VCC と GND へつなぎます (表と裏、線は裏の2本だけ)。FireBeetle の電池コネクタは充電式リチウムイオン電池用なので、乾電池はつながないでください。FireBeetle は基板より長く、ケースに収まるかはまだ確かめていません。管理画面の動作モードは「ディープスリープ」にします。', 'core_bat',
                 [('HPX-Core_BAT_olimex_pcb.png', 'Olimex ESP32-DEVKIT-LIPO'), ('HPX-Core_BAT_freenove_pcb.png', 'Freenove ESP32 WROOM (FNK0090)'), ('HPX-Core_BAT_elegoo_pcb.png', 'ELEGOO ESP-32')]),
                ('HPX-Core_LTE_pcb.png', '＋LTE-M の基板の配線図', 'ユニバーサル基板 タカチ PCB72-95 の左端に FireBeetle 2 ESP32-E をピンソケットで差し、右に CatM の 5V を入り切りするスイッチ (P-MOSFET・NPN トランジスタ・抵抗)、下に M5Stamp CatM を貼る図です (表と裏、線はすべて裏)。電源はケースの壁の USB-C メス基板から (HPX-Solar か USB 充電器)、その 4本の線を赤丸の穴へはんだ付けします。ケースはタカチ OB10-17-5W です (ケースはあとで考え直します)。', 'core_lte',
                 [('HPX-Core_LTE_olimex_pcb.png', 'Olimex ESP32-DEVKIT-LIPO'), ('HPX-Core_LTE_freenove_pcb.png', 'Freenove ESP32 WROOM (FNK0090)'), ('HPX-Core_LTE_elegoo_pcb.png', 'ELEGOO ESP-32')]),
                ('HPX-Core_LTE_wiring.png', '＋LTE-M の線の対応図', 'LTE-M 通信モジュール M5Stamp CatM で直接サーバーへ送るので、LTE ルーターがいりません。電源は USB-C ケーブル1本で HPX-Solar か USB 充電器から。FireBeetle は VCC ピンから、CatM は送るときだけスイッチを通した 5V で動かします。HPX-Solar のときは D+・D− で電池とパネルの電圧も受け取ります。ファームウェアは HPX-Core v1.25 です。', 'core_lte',
                 [('HPX-Core_LTE_olimex_wiring.png', 'Olimex ESP32-DEVKIT-LIPO')])],
         # TARO 2026-11-10: どの版を作ればよいかの選択チャート (電源 × 通信方法)
         chooser=CORE_CHOOSER,
         note='基本モデルは、ルーターへの接続をWi-Fiで行い、USBケーブルで電力を供給します。さらに、有線LANでの接続、ソーラーシステムの電圧計測、防水機能、単三リチウム電池駆動、LTE での直接送信（ルーター不要）などのオプション実装が可能です。LTE-M の版の電源は、別の機器「HPX-Solar」（小型ソーラー電源）を使います。'),
    dict(key='w', file='device-w.html', tag='重量', name='HPX-W',
         lead='巣箱の下に置いたロードセルで重さを測り、Bluetooth で発信します。',
         variants=[('w_cr', 'CR123A 版'), ('w_aa', '単三リチウム電池版')],
         wiring=[('HPX-W_CR123A_TNF39-70.png', 'CR123A 版の配線図', '基板 TNF39-70 の左下 20×45mm を切り取り、CR123A 電池ケースを置きます。ケース TW5-3-9B の左下のスペーサー台座も切り取り、基板は残りの3か所 (2.5mm に広げた穴) をビスで留めます。', 'w_cr'),
                 ('HPX-W_AA_TNF34-124.png', '単三リチウム電池版の配線図', '基板 TNF34-124 の上に、単3×2本の電池ボックスを貼ります。', 'w_aa')],
         # TARO 2026-11-15: CR123A 電池ケースの説明を足す、100μF の代わりに PX は出さない (MH5 は残す)
         rename={'CR123A 電池ケース': ('CR123A 電池ケース', 'CR123A用電池ケース（裏面の出っ張りを切除。プラスチックと電極２つ）')},
         hide_alts=['電解コンデンサー 100μF16V 105℃ ルビコン PX'],
         note='電池は CR123A と単三リチウムの2通りから選べます。違うのはケースと電池まわりだけです。'),
    dict(key='th', file='device-th.html', tag='温湿度', name='HPX-TH',
         lead='巣箱の中の温度と湿度を測り、Bluetooth で発信します。',
         variants=[('th_cr', 'CR123A 版'), ('th_aa', '単三リチウム電池版')],
         wiring=[('HPX-TH_CR123A_TNF44-69.png', 'CR123A 版の配線図', 'ケース SW-75B 用の基板 TNF44-69 に、CR123A 電池ケースのピンを差して載せます。', 'th_cr'),
                 ('HPX-TH_AA_pcb.png', '単三リチウム電池版の配線図', '秋月の両面スルーホールユニバーサル基板 5×7cm を 5×6cm (穴 A〜U × 1〜18) に切り、部品を載せる図です。ケースは LC115H-M2-D です。', 'th_aa')],
         # このページだけの表示の変更 (TARO 2026-11-04): 部品名の書きかえ (名前→(表示する名前, 下の説明 または None))、
         # 「代わりに」を出さない、分類の順 (ケースをチップボードの下へ)
         rename={'SHT40': ('温湿度センサ', None),
                 '絶縁ラジアルリード型積層セラミックコンデンサー 100μF6.3V 5mm': ('コンデンサー100μF', '絶縁ラジアルリード型積層セラミックコンデンサー 100μF6.3V 5mm')},
         no_alts=True,
         cats=['チップボード', 'ケース', 'ケース用基板', '電源', '電子部品', 'ケーブル', '部材'],
         # 分類の中の並び (表示する名前で。ないものは後ろ)
         order=['温湿度センサ', 'サーミスター', 'コンデンサー100μF', 'コンデンサー0.1μF', '抵抗 10kΩ',
                'ピンソケット（メス）', 'ピンヘッダー（オス）', 'ジャンパー線'],
         note='電池は CR123A と単三リチウムの2通りから選べます。違うのはケースと電池まわりだけです。'),
    # HPX-F (TARO 2026-11-08 に HPX-A から改名。列名・ページも a → f)
    dict(key='f', file='device-f.html', tag='電柵アラート', name='HPX-F',
         lead='電柵のフェンスアラートの点滅を光センサで読み取り、電圧の低下を Bluetooth で知らせます。',
         variants=[('f', '数')], note='',
         wiring=[('HPX-F_pcb.png', '配線図', '5×7cm のユニバーサル基板の左上に載せる図です。')],
         # 部品表の下の「その他」: (説明, [(商品の例, 販売店, URL)])
         extra=('※HPX-F本体とは別に、電柵の電圧低下を光で知らせる市販のフェンスアラート（電圧降下警告灯）が必要です。',
                [('フェンスアラート (電圧降下警告灯)', 'amazon', 'https://www.amazon.co.jp/dp/B00N3MUG1A')])),
    # HPX-B (TARO 2026-11-08): ソーラー＋12V バッテリー運用の電源を見守る。ケース・基板は HPX-Core 有線LAN版と同じ
    dict(key='b', file='device-b.html', tag='バッテリー電圧', name='HPX-B',
         lead='ソーラーパネルで充電する 12V バッテリーとパネルの電圧を測り、Bluetooth で発信します。電源はそのバッテリーから取ります。',
         variants=[('b', '数')], note='',
         cats=['チップボード', '電子部品', '電源', 'ケース', 'ケース用基板', '部材', 'ケーブル'],
         no_cost_note=True,   # Core と同じく部品代の目安の説明文は出さない
         order=['抵抗 100kΩ', '抵抗 20kΩ', '抵抗 10kΩ', 'コンデンサー0.1μF', 'コンデンサー 10μF', '端子台 3極',
                'ピンソケット（メス）', 'ピンヘッダー（オス）', 'ジャンパー線'],
         wiring=[('HPX-B_pcb.png', '配線図', '6×8cm のユニバーサル基板を縦に置き、XIAO・三端子 DCDC レギュレーター・抵抗・端子台を並べる図です (表と裏、線はすべて裏)。ケースは HPX-Core「＋有線LAN」と同じ PFF10-4-10W です。')],
         extra=('※ソーラーチャージャー（充電コントローラー）の「パネル−」と「バッテリー−」がつながっている（共通マイナス）機種でだけ、パネル電圧を正しく測れます。コントローラーの線を外し、テスターの導通モードで確かめてください。ファームウェアは準備中です。',
                [])),
    # HPX-CAM (TARO 2026-11-09、2026-11-11 HPX-CAM から改名): 巣門の前で写真を撮って Wi-Fi で HPX-Server へ送る (試作)
    dict(key='cam', file='device-cam.html', tag='巣門カメラ', name='HPX-CAM',
         lead='巣門の前で決まった時間ごとに写真を撮り、Wi-Fi でサーバーへ送ります。スズメバチの襲来や蜂の出入りの様子を記録します。',
         variants=[('cam', '数')], note='',
         cats=['チップボード', '電子部品', '電源', 'ケース', 'ケース用基板', '部材', 'ケーブル'],
         no_cost_note=True,
         # TARO 2026-11-09: 100μF は「コンデンサー100μF」を上に、正式名を下に。「代わりに」は出さない
         rename={'絶縁ラジアルリード型積層セラミックコンデンサー 100μF6.3V 5mm': ('コンデンサー100μF', '絶縁ラジアルリード型積層セラミックコンデンサー 100μF6.3V 5mm')},
         no_alts=True,
         order=['ショットキーバリアダイオード', '抵抗 100kΩ', 'コンデンサー0.1μF',
                'コンデンサー100μF', 'microSDカード',
                'ピンソケット（メス）', 'ピンヘッダー（オス）', 'ユニバーサル基板 5×7', 'ジャンパー線'],
         wiring=[('HPX-CAM_pcb.png', '配線図', '小さなユニバーサル基板 (穴 12×11) に XIAO ESP32S3 Sense・ダイオード・抵抗・コンデンサーを載せる図です (表と裏、線はすべて裏)。単三リチウム3本の電気をダイオードを通して XIAO の 5V 端子へ入れ、電池の電圧を D0 で読みます。ケースは透明ふたの BCAP091207T で、基板を電池ボックスの上に重ねてカメラをふたのすぐ下に置きます。')],
         extra=('※試作中です。ファームウェアは試作版 (v1.00、実機ではまだ確かめていません) で、GitHub の firmware/hpx-cam にあります。写真を送るには、蜂場に Wi-Fi の親機 (ルーターやアクセスポイント) が必要です。近い巣門にピントを合わせたいときは、別売の OV5640 カメラ (自動ピント) に付け替えられます。',
                [])),
    # HPX-Solar (TARO 2026-11-10): ＋LTE-M＆ソーラー版の電源の部分を別の機器にした (小型ソーラーパネル＋DFR0559＋18650×2)
    dict(key='solar', file='device-solar.html', tag='電源', name='HPX-Solar',
         lead='小型ソーラーパネルで 18650 電池2本を充電し、HPX-Core「＋LTE-M」などへ電気を送る電源です。',
         variants=[('solar', '数')], note='',
         cats=['電子部品', '電源', 'ケース', 'ケース用基板', '部材', 'ケーブル'],
         no_cost_note=True,
         wiring=[('HPX-Solar_pcb.png', '配線図', 'ユニバーサル基板 タカチ PCB72-95 に、入力の USB-C メス基板・DFR0559 ソーラーパワーマネージャー・18650 電池ホルダー2個・電圧を測る抵抗を載せる図です。ソーラーパネルの USB-C を差し、18650 2本 (並列) を充電します。HPX-Core へは、ケースの壁の出力 USB-C から USB-C ケーブル (データ対応) 1本でつなぎます (5V・GND と、D+ に電池の電圧、D− にパネルの電圧)。ケースはタカチ OB10-17-5W です (ケースはあとで考え直します)。')],
         extra=('※試作中です。実機では確かめていません。電池とパネルの電圧を測れるのは、HPX-Core「＋LTE-M」と「＋防水」(USB-C メス基板で 4本に分けたとき) です。ほかの機器 (HPX-Core 基本モデル・HPX-CAM など) には 5V の電源として使えます (眠りながら動く機器向け)。出力の USB-C はパソコンなど普通の USB 機器には差さないでください。ずっと動かす機器 (＋有線LAN・LTE ルーター) には向きません。',
                [])),
]
# 表に出す分類の順
CATS = ['チップボード', '電子部品', '電源', 'ケース', 'ケース用基板', 'ケーブル', '部材']


def load_parts():
    """部品の行と、代わりに使えるもの {id: [行]} を返す"""
    rows, alts = [], {}
    with open(Path(__file__).with_name('parts.csv'), encoding='utf-8') as f:
        for r in csv.DictReader(f):
            r = {k: (v or '').strip() for k, v in r.items()}
            if r['name'].endswith('用基板'):
                r['category'] = 'ケース用基板'   # 表の上ではケースの基板をまとめる
            if r.get('alt_of'):
                alts.setdefault(r['alt_of'], []).append(r)
            else:
                rows.append(r)
    for r in rows:
        r['alts'] = alts.get(r.get('id'), [])
    return rows


def page_parts():
    """site/app.html からヘッダー・フッター・共通の CSS を取り出す"""
    src = (SITE / 'app.html').read_text(encoding='utf-8')
    style = src[src.index('<style>') + 7:src.index('</style>')]
    base = style[:style.index('  /* ページの見出し */')]
    foot = style[style.index('  /* フッター */'):]
    hero = style[style.index('  /* ページの見出し */'):style.index('  /* 画面ごとのトピック')]
    sec = style[style.index('  .eyebrow'):style.index('  section h2, section .lead')]
    header = src[src.index('<header>'):src.index('</header>') + 9]
    footer = src[src.index('<footer>'):src.index('</footer>') + 9]
    js = src[src.index('<script>\n(function () {\n  var items'):]
    js = js[:js.index('</script>') + 9]
    return base + hero + sec, foot, header, footer, js


CSS = '''  section { margin-top:140px; scroll-margin-top:80px; }
  main { padding-bottom:300px; }
  @media (max-width:760px) { section { margin-top:98px; } main { padding-bottom:200px; } }
  .tag { display:inline-block; font-size:12px; font-weight:700; color:var(--forest); background:var(--honey-soft); padding:2px 10px; border-radius:999px; }
  @media (prefers-color-scheme: dark) { .tag { color:var(--honey); } }
  h2 { font-size:clamp(22px, 3vw, 28px); margin-top:6px; line-height:1.4; }
  .note { color:var(--sub); margin-top:8px; }

  /* 機器の一覧 (devices.html) */
  .devs { display:grid; grid-template-columns:repeat(2, 1fr); gap:16px; margin-top:56px; }
  @media (max-width:760px) { .devs { grid-template-columns:1fr; } }
  .dev { background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:22px 24px; text-decoration:none; display:block; scroll-margin-top:80px; }
  a.dev:hover { border-color:var(--honey); }
  .dev h3 { font-size:20px; margin-top:8px; }
  .dev p { font-size:14px; color:var(--sub); margin-top:4px; }
  .dev .vars { font-size:13px; margin-top:10px; color:var(--text); }
  .dev.soon { border-style:dashed; }

  /* 部品表 */
  .parts { width:100%; border-collapse:collapse; margin-top:24px; background:var(--panel); border:1px solid var(--line); border-radius:14px; overflow:hidden; font-size:14px; }
  .parts th, .parts td { padding:10px 14px; text-align:left; vertical-align:top; border-top:1px solid var(--line); }
  .parts thead th { border-top:0; font-size:13px; color:var(--sub); font-weight:700; white-space:nowrap; }
  .parts .cat th { background:rgba(47,93,69,.07); color:var(--forest); font-size:13px; padding:6px 14px; }
  @media (prefers-color-scheme: dark) { .parts .cat th { background:rgba(0,0,0,.18); color:var(--honey); } }
  .parts td.n { text-align:center; white-space:nowrap; font-weight:700; width:96px; }
  .parts td.n.none { color:var(--sub); font-weight:400; }
  .parts .part b { display:block; font-weight:600; }
  .parts .part a { color:var(--forest); text-decoration:underline; text-decoration-color:var(--honey); text-underline-offset:3px; }
  @media (prefers-color-scheme: dark) { .parts .part a { color:var(--honey); } }
  .parts .part span { display:block; color:var(--sub); font-size:13px; white-space:pre-line; }
  .parts .part .price { margin-top:2px; }
  .parts .part .alt { margin-top:6px; padding-left:10px; border-left:2px solid var(--line); }
  .parts .part .alt a { font-weight:400; }
  .parts td.shop { color:var(--sub); white-space:nowrap; }
  .parts tfoot td { font-weight:700; border-top:2px solid var(--line); white-space:nowrap; }
  .parts tfoot td.n { width:auto; }
  .parts tr.diff td { background:var(--honey-soft); }
  .legend { font-size:13px; color:var(--sub); margin-top:10px; }
  .legend i { display:inline-block; width:12px; height:12px; border-radius:3px; background:var(--honey-soft); border:1px solid var(--line); vertical-align:-1px; margin-right:6px; }
  /* スマホ: 1部品ずつのかたまりにする */
  @media (max-width:760px) {
    .parts, .parts tbody, .parts tr, .parts td, .parts th { display:block; width:auto; }
    .parts thead { display:none; }
    .parts tr { border-top:1px solid var(--line); padding:10px 14px; }
    .parts .cat { padding:0; }
    .parts td { border:0; padding:0; }
    .parts tr.diff { background:var(--honey-soft); }
    .parts tr.diff td { background:none; }
    .parts td.shop { margin-top:2px; font-size:13px; }
    .parts td.n { display:inline-block; text-align:left; width:auto; margin:4px 14px 0 0; font-size:13px; }
    .parts td.n::before { content:attr(data-v) " "; color:var(--sub); font-weight:400; }
    .parts tfoot tr { border-top:2px solid var(--line); }
    .parts tfoot td { border:0; }
  }
  /* 配線図 */
  .wiring { margin-top:28px; background:#fffdf5; border:1px solid var(--line); border-radius:14px; overflow:hidden; }
  .wiring img { display:block; width:100%; height:auto; }
  .wiring figcaption { padding:12px 18px; font-size:14px; color:var(--sub); background:var(--panel); border-bottom:1px solid var(--line); }
  .wiring figcaption b { display:block; color:var(--text); font-size:15px; }
  /* 配線図の下の表 (つなぐ線・部品の置き場所) と注意。hardware/wiring の JSON から作る */
  .wtab { margin-top:22px; }
  .wtab h3 { font-size:16px; margin-bottom:8px; }
  .wtab .scroll { overflow-x:auto; }
  .wtab table { width:100%; border-collapse:collapse; background:var(--panel); border:1px solid var(--line); font-size:14px; }
  .wtab th, .wtab td { padding:8px 12px; text-align:left; vertical-align:top; border-top:1px solid var(--line); }
  .wtab thead th { border-top:0; font-size:13px; color:var(--sub); white-space:nowrap; }
  .wtab td.wc { white-space:nowrap; }
  .wtab .sw { display:inline-block; width:26px; height:10px; border-radius:3px; border:1px solid #555; margin-right:8px; }
  .wnote { margin-top:22px; padding:16px 22px; background:#fff3cd; border:1px solid #e0b84a; border-radius:12px; font-size:14px; color:#4a3a00; }
  .wnote h3 { font-size:16px; margin-bottom:6px; color:#7a5a00; }
  .wnote ul { margin:0; padding-left:1.2em; }
  .wnote li { margin-top:4px; }
  @media (prefers-color-scheme: dark) { .wnote { background:rgba(242,169,0,.12); color:var(--text); } .wnote h3 { color:var(--honey); } }
  .extra { margin-top:28px; padding:18px 22px; background:var(--panel); border:1px solid var(--line); border-radius:14px; font-size:14px; }
  .extra h3 { font-size:16px; margin-bottom:6px; }
  .extra .ex { margin-top:6px; color:var(--sub); }
  .extra a { color:var(--forest); text-decoration:underline; text-decoration-color:var(--honey); text-underline-offset:3px; }
  @media (prefers-color-scheme: dark) { .extra a { color:var(--honey); } }
  /* 版の切り替えボタン */
  .vsw { display:flex; flex-wrap:wrap; justify-content:center; gap:10px; margin-top:28px; }
  .vsw button { font:inherit; font-size:15px; font-weight:700; padding:10px 24px; border-radius:999px; border:2px solid var(--forest); background:transparent; color:var(--forest); cursor:pointer; }
  .vsw button[aria-pressed="true"] { background:var(--forest); color:#fff; }
  @media (prefers-color-scheme: dark) { .vsw button { border-color:var(--honey); color:var(--honey); } .vsw button[aria-pressed="true"] { background:var(--honey); color:#1d1d1b; } }
  .vname { display:inline-block; margin-left:8px; font-size:12px; font-weight:700; color:var(--sub); }
  .chooser { margin:36px auto 0; max-width:880px; text-align:left; background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:22px 24px; }
  .chooser h2 { font-size:20px; margin:0 0 4px; }
  .chooser .q { margin-top:16px; font-weight:700; font-size:15px; }
  .chooser .opts { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
  .chooser .opts button { font:inherit; font-size:14px; padding:8px 16px; border-radius:999px; border:1.5px solid var(--line); background:transparent; color:var(--text); cursor:pointer; }
  .chooser .opts button[aria-pressed="true"] { border-color:var(--forest); background:var(--forest); color:#fff; }
  .chooser .ans { margin-top:18px; padding:14px 18px; border-radius:12px; background:var(--honey-soft); font-size:15px; }
  .chooser .ans b { font-size:16px; }
  .chooser .ans button, .chooser .ans a.btn { display:inline-block; text-decoration:none; margin-right:8px; font:inherit; font-size:14px; font-weight:700; margin-top:10px; padding:8px 18px; border-radius:999px; border:0; background:var(--forest); color:#fff; cursor:pointer; }
  .chooser details { margin-top:18px; font-size:14px; }
  .chooser summary { cursor:pointer; color:var(--forest); font-weight:700; }
  .ctab { width:100%; border-collapse:collapse; margin-top:10px; font-size:13px; }
  .ctab th, .ctab td { border:1px solid var(--line); padding:8px 10px; vertical-align:top; text-align:left; }
  .ctab thead th { background:rgba(47,93,69,.07); }
  .ctab td .m { font-size:16px; font-weight:700; margin-right:4px; }
  @media (prefers-color-scheme: dark) { .chooser .opts button[aria-pressed="true"], .chooser .ans button, .chooser .ans a.btn { background:var(--honey); border-color:var(--honey); color:#1d1d1b; } .chooser summary { color:var(--honey); } }
  .soonbox { margin-top:24px; padding:22px 24px; border:1px dashed var(--line); border-radius:14px; color:var(--sub); background:var(--panel); }
  .back { display:inline-block; margin-top:16px; font-size:14px; text-decoration:none; border-bottom:2px solid var(--honey); font-weight:600; }
  .back-top { margin-top:0; margin-bottom:18px; }
'''


def esc(s):
    return html.escape(s, quote=True)


def doc(title, desc, body, parts):
    base, foot, header, footer, js = parts
    return f'''<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<script>document.documentElement.classList.add("js");</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{esc(title)} | Hive Plus X</title>
<meta name="description" content="{esc(desc)}">
<link rel="icon" href="img/favicon.svg" type="image/svg+xml">
<style>
{base}{CSS}{foot}</style>
</head>
<body>

{header}

<main>
{body}
</main>

{footer}

{js}
</body>
</html>
'''


def yen(n):
    return f'{round(n):,}円'


def price_text(r):
    """「280円 / 10個入り」"""
    if not r.get('price'):
        return ''
    pk = r.get('pack') or '1'
    if pk == '1' and r.get('unit') in ('個', '本', '枚', 'セット', ''):
        return yen(int(r['price']))
    return f'{yen(int(r["price"]))} / {pk}{r.get("unit", "")}入り' if r.get('unit') not in ('cm', 'm', 'PIN') else f'{yen(int(r["price"]))} / {pk}{r["unit"]}'


def cost(r, n):
    """その数を使ったときの部品代 (数が「適宜」などなら None)"""
    if not r.get('price') or not n:
        return None
    ms = [re.match(r'^(\d+)\s*PIN\s*×\s*(\d+)$', x.strip()) for x in n.split('・')]   # 「7PIN×2」= 7ピンを 2個、「15PIN×2・6PIN×2」も
    if all(ms):
        return sum(int(m.group(1)) * int(m.group(2)) for m in ms) * int(r['price']) / float(r.get('pack') or 1)
    m = re.match(r'^([0-9.]+)\s*(cm|m)?$', n)
    if not m:
        return None
    q = float(m.group(1))
    if m.group(2) == 'm' and r.get('unit') == 'cm':
        q *= 100
    elif m.group(2) == 'cm' and r.get('unit') == 'm':
        q /= 100
    return q * int(r['price']) / float(r.get('pack') or 1)


def link(name, url):
    name = esc(name)
    return f'<a href="{esc(url)}" target="_blank" rel="noopener">{name}</a>' if url else name


def page_rows(dev, rows):
    """機器ごとの表示の変更 (rename・no_alts) をした部品の行"""
    out = []
    for r in rows:
        r = dict(r)
        if r['name'] in dev.get('rename', {}):
            nm, dt = dev['rename'][r['name']]
            r['name'] = nm
            if dt is not None:
                r['detail'] = dt
        if dev.get('no_alts'):
            r['alts'] = []
        elif dev.get('hide_alts'):
            r['alts'] = [x for x in r['alts'] if x['name'] not in dev['hide_alts']]
        out.append(r)
    return out


def parts_table(dev, rows):
    rows = page_rows(dev, rows)
    vs = dev['variants']
    use = [r for r in rows if any(r[k] for k, _ in vs)]
    head = ''.join(f'<th class="n">{esc(v)}</th>' for _, v in vs)
    out = [f'<table class="parts">\n        <thead><tr><th>部品</th><th>購入店</th>{head}</tr></thead>\n        <tbody>']
    ncol = 2 + len(vs)
    cats = dev.get('cats', CATS)
    for cat in cats + sorted({r['category'] for r in use} - set(cats)):
        rs = [r for r in use if r['category'] == cat]
        if dev.get('order'):
            od = dev['order']
            rs.sort(key=lambda r: od.index(r['name']) if r['name'] in od else len(od))
        if not rs:
            continue
        out.append(f'        <tr class="cat"><th colspan="{ncol}">{esc(cat)}</th></tr>')
        for r in rs:
            diff = len(vs) > 1 and len({r[k] for k, _ in vs}) > 1
            ns = ''.join(f'<td class="n{"" if r[k] else " none"}" data-v="{esc(v)}">{esc(r[k]) if r[k] else "—"}</td>' for k, v in vs)
            nm = link(r['name'], r.get('url'))   # 部品のページへのリンク (parts.csv の url 列)
            sub = f'<span>{esc(r["detail"])}</span>' if r['detail'] else ''
            if price_text(r):
                sub += f'<span class="price">{esc(price_text(r))}</span>'
            for a in r['alts']:
                info = '、'.join(x for x in (a['shop'], price_text(a)) if x)
                sub += f'<span class="alt">代わりに: {link(a["name"], a.get("url"))}{"（" + esc(info) + "）" if info else ""}</span>'
            out.append(f'        <tr{" class=\"diff\"" if diff else ""}><td class="part"><b>{nm}</b>{sub}</td>'
                       f'<td class="shop">{esc(r["shop"]) or "—"}</td>{ns}</tr>')
    out.append('        </tbody>')
    # 部品代の目安: 1台に使う分だけを、入数で割って足す (適宜のもの・送料は入れない)
    tot = []
    for k, v in vs:
        c = [cost(r, r[k]) for r in use if r[k]]
        tot.append(f'<td class="n" data-v="{esc(v)}">約 {yen(sum(x for x in c if x))}</td>')
    out.append(f'        <tfoot><tr><td class="part">部品代の目安 (1台分)</td><td class="shop"></td>{"".join(tot)}</tr></tfoot>')
    out.append('      </table>')
    t = '\n'.join(out)
    notes = [] if dev.get('no_cost_note') else ['部品代の目安は、まとめ買いの値段を1台に使う分で割ったものです。「適宜」の部品と送料は入っていません。']
    if len(vs) > 1:
        notes.insert(0, '<i></i>色の付いた行は、版によって違う部品です。')
    t += ''.join(f'\n      <p class="legend">{n}</p>' for n in notes)
    if dev.get('extra'):
        text, items = dev['extra']
        ex = '、'.join(f'{link(n, u)}（{esc(sh)}）' if sh else link(n, u) for n, sh, u in items)
        t += (f'\n      <div class="extra"><h3>その他</h3><p>{esc(text)}</p>'
              + (f'<p class="ex">商品の例: {ex}</p>' if items else '') + '</div>')
    return t


WIRE_COL = {"red": ("#e53935", "赤"), "black": ("#212121", "黒"), "yellow": ("#fdd835", "黄"), "blue": ("#1e88e5", "青"),
            "white": ("#fafafa", "白"), "green": ("#43a047", "緑"), "orange": ("#fb8c00", "橙"), "purple": ("#8e24aa", "紫")}


def wiring_info(png):
    """配線図を作るプログラムが出した表と注意 (hardware/wiring/ の <名前>.json)。なければ None"""
    stem = png.rsplit('.', 1)[0]
    for d in (ROOT / 'hardware' / 'wiring', ROOT / 'hardware' / 'wiring' / 'case'):
        f = d / (stem + '.json')
        if f.exists():
            return json.loads(f.read_text(encoding='utf-8'))
    return None


def wiring_tables(info):
    out = []
    for tb in info['tables']:
        head, *body = tb['rows']
        th = ''.join(f'<th>{esc(h)}</th>' for h in head)
        trs = []
        for r in body:
            cells = []
            for i, c in enumerate(r):
                if i == 0 and c in WIRE_COL:
                    col, name = WIRE_COL[c]
                    cells.append(f'<td class="wc"><span class="sw" style="background:{col}"></span>{name}</td>')
                else:
                    cells.append(f'<td>{esc(c)}</td>')
            trs.append('<tr>' + ''.join(cells) + '</tr>')
        hd = '部品の置き場所' if tb['head'] == '部品表' else tb['head']   # ページ上の部品表と区別する
        out.append('      <div class="wtab"><h3>' + esc(hd) + '</h3><div class="scroll"><table>\n'
                   '        <thead><tr>' + th + '</tr></thead>\n'
                   '        <tbody>' + ''.join(trs) + '</tbody>\n'
                   '      </table></div></div>')
    if info['notes']:
        lis = ''.join(f'<li>{esc(n)}</li>' for n in info['notes'])
        out.append(f'      <div class="wnote"><h3>注意</h3><ul>{lis}</ul></div>')
    return '\n'.join(out)


def wiring_html(dev):
    """配線図 (site/img/wiring/ の PNG)。押すと大きな画像が開く"""
    figs = []
    for w in dev.get('wiring', []):
        f, cap, note = w[:3]
        dv = f' data-variant="{w[3]}"' if len(w) > 3 else ''
        if not f:   # 図がまだないときは説明だけ
            figs.append(f'''      <figure class="wiring"{dv}>
        <figcaption><b>{esc(cap)}</b>{esc(note)}</figcaption>
      </figure>''')
            continue
        figs.append(f'''      <figure class="wiring"{dv}>
        <figcaption><b>{esc(cap)}</b>{esc(note)}</figcaption>
        <a href="img/wiring/{f}" target="_blank" rel="noopener"><img src="img/wiring/{f}" alt="{esc(dev["name"])} {esc(cap)}" loading="lazy"></a>
      </figure>''')
        if len(w) > 4 and w[4]:   # ほかの開発ボードの図へのテキストリンク (TARO 2026-11-13: FireBeetle 以外はリンクで。どれもファーム v1.25 の同じピン)
            links = '、'.join(f'<a href="img/wiring/{o}" target="_blank" rel="noopener">{esc(lab)}</a>' for o, lab in w[4])
            figs.append(f'      <p class="note old-wiring"{dv}>ほかの開発ボードで作るときの図: {links}</p>')
        info = wiring_info(f)
        if info:
            tabs = wiring_tables(info)
            figs.append(f'      <div{dv}>\n{tabs}\n      </div>' if dv else tabs)
    if not figs:
        return ''
    return ('      <p class="note">図を押すと、大きな画像が開きます。どの図もまだ実物で組んで確かめていません。'
            '取付穴の位置は目安なので、部品と基板の実物で確かめてから組み立ててください。</p>\n' + '\n'.join(figs))


def variant_tables(dev, rows):
    """版が 2つ以上なら、版ごとの部品表を作り、ボタンで切り替える"""
    if len(dev['variants']) == 1:
        return '      <p class="note">必要な部品と数です。</p>\n      ' + parts_table(dev, rows)
    out = []
    for k, v in dev['variants']:
        one = dict(dev, variants=[(k, '数')])
        out.append(f'''      <div data-variant="{k}">
      <p class="note">{esc(v)}に必要な部品と数です。</p>
      {parts_table(one, rows)}
      </div>''')
    return '\n'.join(out)


SWITCH_JS = '''<script>
/* 版の切り替え: ボタンを押した版の部品表・配線図だけを見せる。?v=版 でも選べる */
(function () {
  var bs = document.querySelectorAll('.vsw button');
  if (!bs.length) return;
  function show(k) {
    bs.forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-v') === k ? 'true' : 'false'); });
    document.querySelectorAll('[data-variant]').forEach(function (e) { e.hidden = e.getAttribute('data-variant') !== k; });
  }
  bs.forEach(function (b) { b.addEventListener('click', function () {
    var k = b.getAttribute('data-v'); show(k);
    try { var u = new URL(location.href); u.searchParams.set('v', k); history.replaceState(null, '', u); } catch (e) {}
  }); });
  var k = null;
  try { k = new URL(location.href).searchParams.get('v'); } catch (e) {}
  if (!document.querySelector('.vsw button[data-v="' + k + '"]')) k = bs[0].getAttribute('data-v');
  show(k);
})();
</script>'''


def chooser_html(dev):
    """電源と通信方法を選ぶと、作る版を教えるチャート (JS なしでも下の表で分かる)"""
    ch = dev.get('chooser')
    if not ch:
        return ''
    names = dict(dev['variants'])
    def vname(k):
        return names.get(k, '')
    def vname2(v):   # 表と答えに出す名前 (HPX-Solar もいっしょに作るときは足す)
        return (vname(v[1]) + ('＋HPX-Solar' if len(v) > 3 and v[3] else '')) if v[1] else ''
    data = {f'{p}|{n}': [v[0], v[1] or '', vname2(v), v[2], 1 if len(v) > 3 and v[3] else 0] for (p, n), v in ch.items()}
    btn = lambda grp, opts: ''.join(f'<button type="button" data-{grp}="{k}" aria-pressed="false">{esc(v)}</button>' for k, v in opts)
    head = ''.join(f'<th>{esc(v.split("（")[0])}</th>' for _, v in CORE_NET)   # 表は短い名前で (スマートフォンでも読めるように)
    body = ''
    for pk, pv in CORE_POWER:
        tds = ''
        for nk, _ in CORE_NET:
            v = ch[(pk, nk)]
            tds += f'<td><span class="m">{v[0]}</span>{esc(vname2(v)) if v[1] else "版なし"}</td>'
        body += f'<tr><th>{esc(pv)}</th>{tds}</tr>'
    return f'''
    <div class="chooser" id="chooser">
      <h2>どれを作ればいい？</h2>
      <p class="note">電源と通信方法を選ぶと、作る版が分かります。</p>
      <p class="q">1. 電源は？</p><div class="opts">{btn("p", CORE_POWER)}</div>
      <p class="q">2. 通信方法は？</p><div class="opts">{btn("n", CORE_NET)}</div>
      <div class="ans" id="chooser-ans" hidden></div>
      <details><summary>組み合わせの一覧を見る</summary>
        <table class="ctab"><thead><tr><th>電源 ＼ 通信</th>{head}</tr></thead><tbody>{body}</tbody></table>
        <p class="note">◎ ぴったりの版があります　○ 近い版を少し変えて作ります　✕ 今は版がありません</p>
      </details>
      <script>
      (function () {{
        var D = {json.dumps(data, ensure_ascii=False)};
        var p = null, n = null, ans = document.getElementById('chooser-ans');
        function pick(attr, v) {{
          document.querySelectorAll('.chooser [data-' + attr + ']').forEach(function (b) {{ b.setAttribute('aria-pressed', b.getAttribute('data-' + attr) === v ? 'true' : 'false'); }});
        }}
        function upd() {{
          if (!p || !n) return;
          var r = D[p + '|' + n];
          ans.hidden = false;
          ans.textContent = '';
          var b = document.createElement('b');
          b.textContent = r[0] + ' ' + (r[2] || '今は版がありません');
          var t = document.createElement('div');
          t.textContent = r[3];
          ans.appendChild(b); ans.appendChild(t);
          if (r[1]) {{
            var go = document.createElement('button');
            go.type = 'button';
            go.textContent = 'この版の部品表を見る';
            go.addEventListener('click', function () {{
              var v = document.querySelector('.vsw button[data-v="' + r[1] + '"]');
              if (v) v.click();
              document.getElementById('parts').scrollIntoView({{behavior: 'smooth'}});
            }});
            ans.appendChild(go);
          }}
          if (r[4]) {{
            var sl = document.createElement('a');
            sl.href = 'device-solar.html';
            sl.className = 'btn';
            sl.textContent = 'HPX-Solar の作り方を見る';
            ans.appendChild(sl);
          }}
        }}
        document.querySelectorAll('.chooser [data-p]').forEach(function (b) {{ b.addEventListener('click', function () {{ p = b.getAttribute('data-p'); pick('p', p); upd(); }}); }});
        document.querySelectorAll('.chooser [data-n]').forEach(function (b) {{ b.addEventListener('click', function () {{ n = b.getAttribute('data-n'); pick('n', n); upd(); }}); }});
      }})();
      </script>
    </div>'''


def device_page(dev, rows, parts):
    multi = len(dev['variants']) > 1
    sw = ('\n    <div class="vsw" role="group" aria-label="版を選ぶ">' +
          ''.join(f'<button type="button" data-v="{k}" aria-pressed="{"true" if i == 0 else "false"}">{esc(v)}</button>'
                  for i, (k, v) in enumerate(dev['variants'])) + '</div>') if multi else ''
    body = f'''  <div class="hero wrap">
    <a class="back back-top" href="devices.html">← 機器の一覧へ戻る</a>
    <p class="eyebrow">Devices</p>
    <h1>{esc(dev["name"])}</h1>
    <p class="lead">{esc(dev["lead"])}</p>
    {f'<p class="lead">{esc(dev["note"])}</p>' if dev["note"] else ''}{chooser_html(dev)}{sw}
  </div>

  <section id="parts">
    <div class="reveal wrap">
      <span class="tag">{esc(dev["tag"])}</span>
      <h2>部品表</h2>
{variant_tables(dev, rows)}
    </div>
  </section>

  <section id="build">
    <div class="reveal wrap">
      <h2>作り方</h2>
{wiring_html(dev)}
      <div class="soonbox">組み立ての手順は、準備ができしだい掲載します。</div>
      <a class="back" href="devices.html">機器の一覧へ戻る</a>
    </div>
  </section>
''' + (SWITCH_JS if multi else '')
    return doc(dev['name'] + ' の作り方', dev['name'] + ' を作るのに必要な部品の一覧です。', body, parts)


def index_page(parts):
    cards = []
    for d in DEVICES:
        vs = '・'.join(v for _, v in d['variants']) if len(d['variants']) > 1 else ''
        cards.append(f'''      <a class="dev" id="{d["key"]}" href="{d["file"]}"><span class="tag">{esc(d["tag"])}</span><h3>{esc(d["name"])}</h3>
        <p>{esc(d["lead"])}</p>{f'<p class="vars">{esc(vs)}</p>' if vs else ''}</a>''')
    cards.append('''      <div class="dev soon" id="other"><span class="tag">その他センサー</span><h3>HPXシリーズ</h3>
        <p>各種センサを nRF52840 と組み合わせることで、様々な拡張が可能です。開発中です。</p></div>''')
    body = '''  <div class="hero wrap">
    <p class="eyebrow">Devices</p>
    <h1>機器をつくる</h1>
    <p class="lead">どの機器も、部品表・配線図・プログラムを公開しています。改造や販売も自由です。</p>
  </div>

  <section>
    <div class="reveal wrap">
      <div class="devs">
''' + '\n'.join(cards) + '''
      </div>
    </div>
  </section>'''
    return doc('機器をつくる', 'Hive Plus X の機器 (HPX-Core・HPX-W・HPX-TH・HPX-F・HPX-B・HPX-CAM・HPX-Solar) の作り方と部品表です。', body, parts)


def main():
    rows = load_parts()
    parts = page_parts()
    (SITE / 'devices.html').write_text(index_page(parts), encoding='utf-8')
    for d in DEVICES:
        (SITE / d['file']).write_text(device_page(d, rows, parts), encoding='utf-8')
    print('作成:', 'devices.html', *[d['file'] for d in DEVICES])


if __name__ == '__main__':
    main()
