# 機器のページ (site/devices.html と site/device-*.html) を parts.csv から作る。
#   使い方: python3 hardware/parts/gen_device_pages.py   (リポジトリの一番上で)
#   部品や数を変えるときは parts.csv を直してから、これを動かす。
#   parts.csv の列: id (代わりの品から指すときだけ), url (部品名のリンク), pack・unit・price (入数・単位・価格),
#   alt_of (その id の部品の「代わりに使えるもの」), 残りは機器の版ごとの数 (空ならその版では使わない)。
#   ヘッダー・フッター・色は site/app.html から取るので、サイトの見た目を変えたらこれも動かし直す。
import csv, html, re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SITE = ROOT / 'site'

# 機器ごとの版 (parts.csv の数の列名, 画面に出す名前)
DEVICES = [
    dict(key='core', file='device-core.html', tag='ハブ', name='HPX-Core',
         lead='センサの記録を Bluetooth で集め、Wi-Fi でサーバーへ送る中心的な機器です。',
         variants=[('core', '通常版'), ('core_wp', '防水版')],
         wiring=[('HPX-Core_wiring.png', '配線図 (通常版・防水版とも同じ)', 'USB 電源をつなぐだけで、外付けの配線はありません。')],
         note='屋内や軒下に置く通常版と、屋外に置ける防水版があります。'),
    dict(key='w', file='device-w.html', tag='重量', name='HPX-W',
         lead='巣箱の下に置いたロードセルで重さを測り、Bluetooth で発信します。',
         variants=[('w_cr', 'CR123A 版'), ('w_aa', '単三リチウム版')],
         wiring=[('HPX-W_CR123A_TNF39-70.png', 'CR123A 版の配線図', '基板 TNF39-70 の左下を切り取り、CR123A 電池ケースを置きます。'),
                 ('HPX-W_AA_TNF34-124.png', '単三リチウム版の配線図', '基板 TNF34-124 の上に、単3×2本の電池ボックスを貼ります。')],
         note='電池は CR123A と単三リチウムの2通りから選べます。違うのはケースと電池まわりだけです。'),
    dict(key='th', file='device-th.html', tag='温湿度', name='HPX-TH',
         lead='巣箱の中の温度と湿度を測り、Bluetooth で発信します。',
         variants=[('th_cr', 'CR123A 版'), ('th_aa', '単三リチウム版')],
         wiring=[('HPX-TH_CR123A_TNF44-69.png', 'CR123A 版の配線図', 'ケース SW-75B 用の基板 TNF44-69 に、CR123A 電池ケースのピンを差して載せます。'),
                 ('HPX-TH_AA_LC115H.png', '単三リチウム版の配線図', 'ケース LC115H-M2-D の推奨基板に部品を載せる図です。')],
         note='電池は CR123A と単三リチウムの2通りから選べます。違うのはケースと電池まわりだけです。'),
    dict(key='a', file='device-a.html', tag='電柵アラート', name='HPX-A',
         lead='電柵のフェンスアラートの点滅を光センサで読み取り、電圧の低下を Bluetooth で知らせます。',
         variants=[('a', '数')], note='',
         wiring=[('HPX-A_pcb.png', '配線図', '5×7cm のユニバーサル基板の左上に載せる図です。')],
         # 部品表の下の「その他」: (説明, [(商品の例, 販売店, URL)])
         extra=('※HPX-A本体とは別に、電柵の電圧低下を光で知らせる市販のフェンスアラート（電圧降下警告灯）が必要です。',
                [('フェンスアラート (電圧降下警告灯)', 'amazon', 'https://www.amazon.co.jp/dp/B00N3MUG1A')])),
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


CSS = '''  section { margin-top:200px; scroll-margin-top:80px; }
  main { padding-bottom:300px; }
  @media (max-width:760px) { section { margin-top:140px; } main { padding-bottom:200px; } }
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
  .extra { margin-top:28px; padding:18px 22px; background:var(--panel); border:1px solid var(--line); border-radius:14px; font-size:14px; }
  .extra h3 { font-size:16px; margin-bottom:6px; }
  .extra .ex { margin-top:6px; color:var(--sub); }
  .extra a { color:var(--forest); text-decoration:underline; text-decoration-color:var(--honey); text-underline-offset:3px; }
  @media (prefers-color-scheme: dark) { .extra a { color:var(--honey); } }
  .soonbox { margin-top:24px; padding:22px 24px; border:1px dashed var(--line); border-radius:14px; color:var(--sub); background:var(--panel); }
  .back { display:inline-block; margin-top:16px; font-size:14px; text-decoration:none; border-bottom:2px solid var(--honey); font-weight:600; }
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
    if pk == '1' and r.get('unit') in ('個', '本', '枚', ''):
        return yen(int(r['price']))
    return f'{yen(int(r["price"]))} / {pk}{r.get("unit", "")}入り' if r.get('unit') not in ('cm', 'm') else f'{yen(int(r["price"]))} / {pk}{r["unit"]}'


def cost(r, n):
    """その数を使ったときの部品代 (数が「適宜」などなら None)"""
    if not r.get('price') or not n:
        return None
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


def parts_table(dev, rows):
    vs = dev['variants']
    use = [r for r in rows if any(r[k] for k, _ in vs)]
    head = ''.join(f'<th class="n">{esc(v)}</th>' for _, v in vs)
    out = [f'<table class="parts">\n        <thead><tr><th>部品</th><th>購入店</th>{head}</tr></thead>\n        <tbody>']
    ncol = 2 + len(vs)
    for cat in CATS + sorted({r['category'] for r in use} - set(CATS)):
        rs = [r for r in use if r['category'] == cat]
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
    notes = ['部品代の目安は、まとめ買いの値段を1台に使う分で割ったものです。「適宜」の部品と送料は入っていません。']
    if len(vs) > 1:
        notes.insert(0, '<i></i>色の付いた行は、版によって違う部品です。')
    t += ''.join(f'\n      <p class="legend">{n}</p>' for n in notes)
    if dev.get('extra'):
        text, items = dev['extra']
        ex = '、'.join(f'{link(n, u)}（{esc(sh)}）' if sh else link(n, u) for n, sh, u in items)
        t += (f'\n      <div class="extra"><h3>その他</h3><p>{esc(text)}</p>'
              f'<p class="ex">商品の例: {ex}</p></div>')
    return t


def wiring_html(dev):
    """配線図 (site/img/wiring/ の PNG)。押すと大きな画像が開く"""
    figs = []
    for f, cap, note in dev.get('wiring', []):
        figs.append(f'''      <figure class="wiring">
        <figcaption><b>{esc(cap)}</b>{esc(note)}</figcaption>
        <a href="img/wiring/{f}" target="_blank" rel="noopener"><img src="img/wiring/{f}" alt="{esc(dev["name"])} {esc(cap)}" loading="lazy"></a>
      </figure>''')
    if not figs:
        return ''
    return ('      <p class="note">図を押すと、大きな画像が開きます。どの図もまだ実物で組んで確かめていません。'
            '取付穴の位置は目安なので、部品と基板の実物で確かめてから組み立ててください。</p>\n' + '\n'.join(figs))


def device_page(dev, rows, parts):
    vs = ' / '.join(v for _, v in dev['variants']) if len(dev['variants']) > 1 else ''
    body = f'''  <div class="hero wrap">
    <p class="eyebrow">Devices</p>
    <h1>{esc(dev["name"])}</h1>
    <p class="lead">{esc(dev["lead"])}</p>
    {f'<p class="lead">{esc(dev["note"])}</p>' if dev["note"] else ''}
  </div>

  <section id="parts">
    <div class="reveal wrap">
      <span class="tag">{esc(dev["tag"])}</span>
      <h2>部品表</h2>
      <p class="note">必要な部品と数です。{('版ごとの数を並べています (' + esc(vs) + ')。') if vs else ''}</p>
      {parts_table(dev, rows)}
    </div>
  </section>

  <section id="build">
    <div class="reveal wrap">
      <h2>作り方</h2>
{wiring_html(dev)}
      <div class="soonbox">組み立ての手順は、準備ができしだい掲載します。</div>
      <a class="back" href="devices.html">機器の一覧へ戻る</a>
    </div>
  </section>'''
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
    return doc('機器をつくる', 'Hive Plus X の機器 (HPX-Core・HPX-W・HPX-TH・HPX-A) の作り方と部品表です。', body, parts)


def main():
    rows = load_parts()
    parts = page_parts()
    (SITE / 'devices.html').write_text(index_page(parts), encoding='utf-8')
    for d in DEVICES:
        (SITE / d['file']).write_text(device_page(d, rows, parts), encoding='utf-8')
    print('作成:', 'devices.html', *[d['file'] for d in DEVICES])


if __name__ == '__main__':
    main()
