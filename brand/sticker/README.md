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

機器名は HPX-Core / HPX-TH / HPX-W / HPX-A の4種類です。PNG (1000px) は png/、一覧は preview_all.png です。
作り直すときは `python3 mk_sticker.py` を実行します (fonttools と cairosvg が必要)。

## 印刷のヒント
- 印刷屋さんには SVG を渡してください。B と D の灰色の線は、切り取り位置の目安です。
- 屋外の巣箱に貼るなら、耐候性 (UVカット・防水) の用紙かラミネートがおすすめです。
- 30mm 角より小さく作ると機器名の字も小さくなるので、一度印刷して読めるか確かめてください。
