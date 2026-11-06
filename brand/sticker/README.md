# 機器用 正方形ステッカー案 v2 (2026-11-06)

v2: TARO の指示で C・D・F を修正、E は不採用で削除。A・B・C・D・F で確定 (2026-11-06)。A・B・C・D・F で確定 (2026-11-06)。

hive+x ロゴ (brand/logo/) と機器名ロゴ (brand/note-and-devices/) をそのまま使った、正方形・角丸のステッカー案です。
文字はすべてアウトライン化済み (フォントのインストール不要)。SVG は 50mm 角で作っていますが、拡大・縮小しても形は崩れません。

| 案 | 内容 | ファイル |
|---|---|---|
| A | 緑背景に hive+x と hiveplusx.com (機器名なし) | svg/A_forest_logo.svg |
| B | 白背景に hive+x と hiveplusx.com (機器名なし) | svg/B_white_logo.svg |
| C | 緑背景。hive+x (A と同じ大きさ)、ハチミツ色の線、機器名 (v1 の半分) | svg/C_forest_HPX-*.svg |
| D | 上が緑 (hive+x、A と同じ大きさ)、下の細い白帯に機器名 (v1 の半分、白帯は v1 の半分以下) | svg/D_split_HPX-*.svg |
| F | ファビコン (E2) の h+x を大きく、下に機器名 (v1 の7割) | svg/F_icon_HPX-*.svg |

機器名は HPX-Core / HPX-TH / HPX-W / HPX-A の4種。PNG (1000px) は png/、一覧は preview_all.png。
作り直すときは `python3 mk_sticker.py` (fonttools と cairosvg が必要)。

## 印刷のヒント
- 印刷屋さんには SVG を渡してください。B と D の灰色の線は切り取り位置の目安です。
- 屋外の巣箱に貼るなら、耐候性 (UVカット・防水) の用紙やラミネートがおすすめです。
- HPX-TH のような小さい機器には 小さく作ると機器名の字も小さくなるので、30mm 角より小さくする場合は一度印刷して読めるか確かめてください。
