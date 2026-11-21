# 機器用 正方形ステッカー (2026-11-06)

hive+x ロゴ (brand/logo/) と機器名ロゴ (brand/note-and-devices/) をそのまま使った、正方形・角丸のステッカーです。
文字はすべてアウトライン化済みです (フォントのインストールは要りません)。SVG は 50mm 角で作っていますが、拡大・縮小しても形は崩れません。

| 案 | 内容 | ファイル |
|---|---|---|
| A | 緑背景に hive+x と hiveplusx.com (機器名なし) | svg/A_forest_logo.svg |
| B | 白背景に hive+x と hiveplusx.com (機器名なし) | svg/B_white_logo.svg |
| C | 緑背景。hive+x (A と同じ大きさ)、ハチミツ色の短い線、機器名、hiveplusx.com | svg/C_forest_HPX-*.svg |
| D | 上が緑 (hive+x、A と同じ大きさ)、下の細い白帯に機器名 | svg/D_split_HPX-*.svg |
| F | ファビコン (favicon_app) の h+x を大きく、下に機器名 | svg/F_icon_HPX-*.svg |
| C 白 | C の白背景版 (文字は Charcoal、URL は Forest) | svg/C_white_HPX-*.svg |
| D 白 | D の色を入れかえた版 (上が白に hive+x、下の緑の帯に白文字の機器名) | svg/D_white_HPX-*.svg |
| F 白 | F の白背景版 (h と x は Charcoal) | svg/F_white_HPX-*.svg |

機器名は HPX-Core / HPX-TH / HPX-W / HPX-F の4種類です。PNG (1000px) は png/、一覧は preview_all.png です。
作り直すときは `python3 mk_sticker.py` を実行します (fonttools と cairosvg が必要)。

## 採用したステッカー (2026-11-21)
D 白 (D_white) を採用しました。サイトのダウンロードページ (site/download.html) から、この7種類の SVG・PNG をまとめた zip (site/download/hiveplusx_stickers_YYYYMMDD.zip) をダウンロードできます。

| 機器 | ファイル | 大きさ |
|---|---|---|
| HPX-Core / HPX-TH / HPX-F | svg/D_white_HPX-Core.svg など | 50mm 角 |
| HPX-B / HPX-CAM / HPX-Solar (2026-11-21 追加) | svg/D_white_HPX-B.svg など | 50mm 角 |
| HPX-W (横長、左に hive+x・右の緑に HPX-W) | svg/D_white_HPX-W_wide.svg | 100mm × 30mm (PNG は 2000×600px) |

HPX-B・HPX-CAM・HPX-Solar の機器名は note-and-devices に無いので、mk_sticker.py が同じ書体 (Inter ExtraBold) から作ります。

## 印刷のヒント
- 印刷屋さんには SVG を渡してください。白背景の案 (B・C 白・D・D 白・F 白) の灰色の線は、切り取り位置の目安です。
- 屋外の巣箱に貼るなら、耐候性 (UVカット・防水) の用紙かラミネートがおすすめです。
- 30mm 角より小さく作ると機器名の字も小さくなるので、一度印刷して読めるか確かめてください。
