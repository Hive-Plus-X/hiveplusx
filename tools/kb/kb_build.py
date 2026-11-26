#!/usr/bin/env python3
"""「資料に聞く」(server/kb.php) のデータを作る。

日本養蜂協会などの PDF を 1 ページずつ文章にして、資料名・年・ページ番号を付けた
kb.json を作る。さくらのサーバーで PDF を読むのは重いので、パソコンやクラウドで1回作り、
できた kb.json を hpx/data/kb/kb.json にアップロードする。

使い方:
  pip install pymupdf          (文字が画像の PDF も読むなら tesseract と日本語データ jpn も)
  python3 kb_build.py docs.json PDFのフォルダ 出力の kb.json [OCRの控えフォルダ]

  docs.json の file が PDF のファイル名。PDF は docs.json の url から先にダウンロードしておく。
  PDF の文章そのものは協会の著作物なので、kb.json は GitHub には入れない (data/ は .gitignore 済み)。

ページ番号:
  紙に印刷されたページ番号 (ページの上か下の端の数字) を読む。前後のページと続いていれば採用、
  見開き (1枚に 16・17) は「16〜17」。読めないページは空にして、画面では「PDF の◯枚目」と出す。
文字が画像のページ (文字がほとんど取れない・文字化けしている) は tesseract で読み取る。
"""
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import unicodedata
from datetime import datetime, timezone

import fitz  # pymupdf

GOOD = re.compile(r'[　-ヿ一-鿿＀-￯ -~°±×÷'
                  r'‐-⌒①-⓿─-◿☀-⛿Α-ω]')
NUM = re.compile(r'[-ー‐–]?\s*\d{1,3}\s*[-ー‐–]?')


def bad_ratio(t: str) -> float:
    t = ''.join(t.split())
    if not t:
        return 1.0
    return 1 - len(GOOD.findall(t)) / len(t)


def need_ocr(t: str) -> bool:
    s = ''.join(t.split())
    return len(s) < 80 or bad_ratio(s) > 0.05


def ocr_page(page, cache_dir, key):
    if cache_dir:
        cp = os.path.join(cache_dir, key + '.txt')
        if os.path.exists(cp):
            return open(cp, encoding='utf-8').read()
    if not shutil.which('tesseract'):
        return None
    with tempfile.TemporaryDirectory() as td:
        png = os.path.join(td, 'p.png')
        page.get_pixmap(dpi=300).save(png)
        r = subprocess.run(['tesseract', png, '-', '-l', 'jpn', '--psm', '3'], capture_output=True, text=True)
        t = r.stdout
    if cache_dir:
        os.makedirs(cache_dir, exist_ok=True)
        open(cp, 'w', encoding='utf-8').write(t)
    return t


def clean(t: str) -> str:
    t = unicodedata.normalize('NFKC', t)
    t = t.replace('\r', '')
    # 日本語の行の折り返しはつなぐ (英数字どうしの間は空白にする)
    t = re.sub(r'(?<=[^\x00-\x7f])\n(?=[^\x00-\x7f])', '', t)
    t = re.sub(r'\n+', '\n', t)
    t = re.sub(r'[ \t　]+', ' ', t)
    return t.strip()


def num_cands(page):
    """ページの上下の端にある数字だけの行 (ページ番号の候補)"""
    h = page.rect.height
    c = []
    for b in page.get_text('dict')['blocks']:
        for l in b.get('lines', []):
            t = unicodedata.normalize('NFKC', ''.join(s['text'] for s in l['spans'])).strip()
            if NUM.fullmatch(t) and (l['bbox'][1] > h * 0.86 or l['bbox'][3] < h * 0.12):
                c.append(int(re.sub(r'\D', '', t)))
    c = sorted(set(c))
    # 1枚に1つか、見開きの2つ (続き番号) だけを候補にする
    if len(c) == 1 or (len(c) == 2 and c[1] == c[0] + 1):
        return c
    return []


def ocr_num_cands(t: str):
    """読み取った (OCR) 文の最初か最後の行が数字だけならページ番号の候補"""
    ls = [unicodedata.normalize('NFKC', x).strip() for x in t.splitlines() if x.strip()]
    for x in (ls[-1:] + ls[:1]) if ls else []:
        if NUM.fullmatch(x):
            return [int(re.sub(r'\D', '', x))]
    return []


def printed_numbers(cs):
    """cs: ページごとの番号の候補。前後のページと続いている番号だけ採用する"""
    n = len(cs)
    ok = [None] * n
    for i, c in enumerate(cs):
        if not c:
            continue
        prev = cs[i - 1] if i > 0 else []
        nxt = cs[i + 1] if i + 1 < len(cs) else []
        if (prev and c[0] == prev[-1] + 1) or (nxt and nxt[0] == c[-1] + 1):
            ok[i] = c
    # 間のページ (図だけ・番号が読めない) は、前後のページの番号から1枚1つで続いているときだけ埋める
    for i in range(n):
        if ok[i] is not None:
            continue
        j = next((k for k in range(i - 1, -1, -1) if ok[k]), None)
        k = next((k for k in range(i + 1, n) if ok[k]), None)
        if j is None or k is None:
            continue
        if len(ok[j]) == 1 and len(ok[k]) == 1 and ok[k][0] - ok[j][0] == k - j:
            ok[i] = [ok[j][0] + (i - j)]
    out = []
    for c in ok:
        if not c:
            out.append('')
        elif len(c) == 1:
            out.append(str(c[0]))
        else:
            out.append(f'{c[0]}〜{c[1]}')
    return out


def main():
    if len(sys.argv) < 4:
        print(__doc__)
        sys.exit(1)
    docs_json, pdf_dir, out = sys.argv[1:4]
    cache = sys.argv[4] if len(sys.argv) > 4 else None
    meta = json.load(open(docs_json, encoding='utf-8'))
    docs, pages = [], []
    for d in meta['docs']:
        doc = fitz.open(os.path.join(pdf_dir, d['file']))
        n_ocr = 0
        texts, cs = [], []
        for i, p in enumerate(doc):
            t = p.get_text()
            c = num_cands(p)
            if need_ocr(t):
                o = ocr_page(p, cache, f"{d['id']}_{i + 1:03d}")
                if o is not None and len(''.join(o.split())) > len(''.join(t.split())) * (0.5 if bad_ratio(t) > 0.05 else 1):
                    t = o
                    n_ocr += 1
                    c = c or ocr_num_cands(o)
            texts.append(t)
            cs.append(c)
        nums = printed_numbers(cs)
        for i, t in enumerate(texts):
            t = clean(t)
            if len(t) < 20:
                continue
            pages.append({'d': d['id'], 'p': i + 1, 'n': nums[i], 't': t})
        docs.append({k: d[k] for k in ('id', 'title', 'year', 'url', 'from')} | {'pages': len(doc)})
        print(d['id'], d['title'], f"{len(doc)}ページ OCR {n_ocr} 番号あり {sum(1 for x in nums if x)}", file=sys.stderr)
    os.makedirs(os.path.dirname(os.path.abspath(out)), exist_ok=True)
    json.dump({'built': datetime.now(timezone.utc).isoformat(timespec='seconds'), 'publisher': meta.get('publisher', ''),
               'docs': docs, 'pages': pages}, open(out, 'w', encoding='utf-8'), ensure_ascii=False, separators=(',', ':'))
    print(f'{out}: {len(docs)}資料 {len(pages)}ページ {os.path.getsize(out) // 1024}KB', file=sys.stderr)


if __name__ == '__main__':
    main()
