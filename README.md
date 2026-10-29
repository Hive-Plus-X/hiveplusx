# Hive Plus X

養蜂のための、オープンソースIoT。 / Open-source IoT for beekeeping.

巣箱の重量・温湿度の計測と、電柵の電圧低下アラートを、手づくりできる機器と小さなサーバーで実現します。
趣味とプロの養蜂家には実用的な道具として、学生・研究者には仕組みを学び、改良するための土台として使ってもらうことを目指しています。

ウェブサイト: https://hiveplusx.com

## 中身

| フォルダ | 内容 | ライセンス |
|---|---|---|
| `firmware/hpx-core/` | HPX-core (ESP32)。センサのBLE広告を受けて、Wi-Fi でサーバーへ送るハブ | MIT |
| `firmware/hpx-weight/` | 重量センサ HPXW (XIAO nRF52840 + HX711 + ロードセル) | MIT |
| `firmware/hpx-alert/` | HPX-alert (XIAO nRF52840)。電柵アラームのLED点滅を光センサで読み、BLEで知らせる | MIT |
| `firmware/experiments/` | 試作・検討中のもの | MIT |
| `server/` | 蜂場管理アプリ (PHP。蜂場・巣箱・メモ・作業記録・センサ・チーム・アカウント) | MIT |
| `site/` | hiveplusx.com のページ | MIT |
| `hardware/` | 回路図・基板・ケースの図面 (これから追加) | CERN-OHL-P v2 |
| `docs/` | 説明書・検討メモ | CC BY 4.0 |
| `brand/` | ロゴ・ファビコン | 下の「名前とロゴ」を参照 |

## ライセンス

- ソフトウェア (`firmware/`・`server/`・`site/`): [MIT](LICENSES/MIT.txt)
- ハードウェア (`hardware/`): [CERN-OHL-P v2](LICENSES/CERN-OHL-P-2.0.txt)
- 文書・写真 (`docs/` など): [CC BY 4.0](LICENSES/CC-BY-4.0.txt)

著作権: Copyright (c) 2026 Taro Maeda and Hive Plus X contributors (「contributors」は、改良を寄せてくれた人たちを含みます)

どれも、改造・再配布・販売を含めて自由に使えます。使うときは「Hive Plus X」の名前と元のライセンスの表示を残してください。無保証です。

## 名前とロゴ

「Hive Plus X」「hive+x」の名前とロゴは、上のライセンスの対象外です。改造したものを配るときは別の名前にしてください。
「Hive Plus X 互換」「Hive Plus X をもとに作りました」のような書き方は自由です。ロゴの使い方は [brand/logo/README.md](brand/logo/README.md) にあります。

## 安全について

電柵・電池・ソーラーパネルを扱います。電柵本体の高電圧部には触れない・つながない作りになっていますが、作る・設置するときは各機器の説明書と安全上の注意に従ってください。

## はじめかた

- サーバー (蜂場管理アプリ) の置き方: [docs/server-setup.md](docs/server-setup.md)。`server/config.sample.php` を `config.php` にコピーして書き換えます
- ファームウェアは Arduino IDE で書き込みます。必要なボードとライブラリは各 `.ino` の先頭に書いてあります

## 参加のしかた

不具合の報告・質問・改良の提案は GitHub の Issues へ、直したものはプルリクエストでどうぞ。GitHub を使わない人は support@hiveplusx.com へ。
詳しくは [CONTRIBUTING.md](CONTRIBUTING.md) (コミットに `Signed-off-by` の一行を付ける DCO 方式) と [行動規範](CODE_OF_CONDUCT.md) を見てください。
