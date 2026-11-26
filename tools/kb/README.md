# 資料に聞く (server/kb.php) のデータを作る

HPX-note の「資料に聞く」は、`hpx/data/kb/kb.json` の中だけから答えます。
このフォルダの `kb_build.py` で、日本養蜂協会の PDF から `kb.json` を作ります。

- `docs.json` … 取り込む資料の一覧 (資料名・年・PDF の URL)。資料を足すときはここに1つ足す。
  - `year` は発行の年 (表紙・奥付の年月から)。答えで「新しい資料を優先」するのに使います。
  - `title` は出典にそのまま出ます。
- `kb_build.py` … PDF を1ページずつ文章にして、資料名・年・ページ番号を付ける。文字が画像のページは tesseract (日本語) で読み取る。

## 作り方

```sh
pip install pymupdf
# 文字が画像の PDF (養蜂マニュアルⅡなど) も読むなら: apt install tesseract-ocr tesseract-ocr-jpn
mkdir pdf
# docs.json の url の PDF を pdf/ に file の名前 (01.pdf など) で保存する
python3 kb_build.py docs.json pdf kb.json ocr_cache
```

できた `kb.json` をサーバーの `hpx/data/kb/kb.json` にアップロードします。

## 気をつけること

- PDF と `kb.json` (PDF の文章) は協会の著作物なので、GitHub には入れません。
- 協会のサイト: 養蜂マニュアル https://beekeeping.or.jp/council/manual/ ・手引書 https://beekeeping.or.jp/council/tebikisho/
- 文字が画像のページの読み取り (OCR) には読みまちがいがあります。答えの出典のページで確かめてください。
