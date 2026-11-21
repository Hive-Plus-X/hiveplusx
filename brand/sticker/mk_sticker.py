# 機器用 正方形ステッカーの生成スクリプト (2026-11-06)
# 使い方: python3 mk_sticker.py  (fonttools と cairosvg が必要)
# ロゴは brand/final (リポジトリでは brand/logo)/hivex_main.svg、機器名は brand/note-and-devices/HPX-*.svg の形をそのまま使う。
# 小さな文字 (hiveplusx.com など) は Inter をアウトライン化して埋め込む (フォント不要)。
import re, os
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.boundsPen import BoundsPen
import cairosvg

HERE = os.path.dirname(os.path.abspath(__file__))
BRAND = os.path.dirname(HERE)
CHAR, HONEY, WHITE, FOREST, LINE = '#1F2328', '#F2A900', '#FFFFFF', '#2F5D45', '#C9D3CC'
S = 1000          # 正方形の一辺 (50mm で印刷する想定。1単位=0.05mm)
RX = 110          # 角丸

def find(*cands):
    # プロジェクトファイル (brand/final・favicon_v2) とリポジトリ (brand/logo・favicon) の両方で動くように
    for c in cands:
        p = os.path.join(BRAND, c)
        if os.path.exists(p):
            return p
    raise FileNotFoundError(cands[0])

def clean(path):
    s = open(path, encoding='utf-8').read()
    return re.sub(r'<metadata>.*?</metadata>', '', s, flags=re.S)

# --- hive+x ロゴ (文字の黒い部分 / ハチミツ色の部分) ---
logo = clean(find('final/hivex_main.svg', 'logo/hivex_main.svg'))
LOGO_DARK = re.search(r'<path fill="#1F2328" d="([^"]+)"', logo).group(1)
LOGO_HONEY = re.search(r'<path fill="#F2A900" d="([^"]+)"', logo).group(1)
LOGO_BOX = (0, -1876, 6699, 21)   # x0,y0,x1,y1

def logo_g(cx, cy, width, text=WHITE):
    x0, y0, x1, y1 = LOGO_BOX
    k = width / (x1 - x0)
    tx = cx - (x0 + x1) / 2 * k
    ty = cy - (y0 + y1) / 2 * k
    return (f'<g transform="translate({tx:.2f} {ty:.2f}) scale({k:.5f})">'
            f'<path fill="{text}" d="{LOGO_DARK}"/><path fill="{HONEY}" d="{LOGO_HONEY}"/></g>')

# --- 機器名 (HPX-Core など) ---
DEV = {}
for n in ['Core', 'TH', 'W', 'F']:
    s = clean(os.path.join(BRAND, 'note-and-devices', f'HPX-{n}.svg'))
    vbw = float(re.search(r'viewBox="[-\d.]+ [-\d.]+ ([\d.]+)', s).group(1))
    dark = re.search(r'<path fill="#1F2328" d="([^"]+)"', s).group(1)
    honey = re.search(r'<path fill="#F2A900" d="([^"]+)"', s).group(1)
    DEV[n] = (dark, honey, (0, -1490, vbw - 808, 0))   # 字の高さ (大文字の高さ) で上下中央

def dev_from_font(n):
    # note-and-devices に無い機器名 (HPX-B・HPX-CAM・HPX-Solar) は、同じ書体 (Inter ExtraBold) から作る。
    # 作り方は note-and-devices/HPX-*.svg と同じ (字間なし、「-」だけ Honey、右端は最後の字の端 + 116)
    f = TTFont('/usr/share/fonts/opentype/inter/Inter-ExtraBold.otf')
    gs = f.getGlyphSet(); cmap = f.getBestCmap()
    x = 0; dark = []; honey = []; right = 0
    for ch in f'HPX-{n}':
        g = gs[cmap[ord(ch)]]
        pen = SVGPathPen(gs, lambda v: f'{v:g}')
        from fontTools.pens.transformPen import TransformPen
        g.draw(TransformPen(pen, (1, 0, 0, -1, x, 0)))
        bp = BoundsPen(gs); g.draw(bp)
        if bp.bounds:
            right = x + bp.bounds[2]
        (honey if ch == '-' else dark).append(pen.getCommands())
        x += g.width
    return (' '.join(dark), ' '.join(honey), (0, -1490, right + 116, 0))

for n in ['B', 'CAM', 'Solar']:
    DEV[n] = dev_from_font(n)

def dev_g(n, cx, cy, height=None, width=None, text=WHITE):
    dark, honey, (x0, y0, x1, y1) = DEV[n]
    k = height / (y1 - y0) if height else width / (x1 - x0)
    if width and (x1 - x0) * k > width:
        k = width / (x1 - x0)
    tx = cx - (x0 + x1) / 2 * k
    ty = cy - (y0 + y1) / 2 * k
    return (f'<g transform="translate({tx:.2f} {ty:.2f}) scale({k:.5f})">'
            f'<path fill="{text}" d="{dark}"/><path fill="{honey}" d="{honey}"/></g>').replace(
            f'<path fill="{honey}" d="{honey}"/>', f'<path fill="{HONEY}" d="{honey}"/>')

# --- 小さな文字 (Inter をアウトライン化) ---
FONTS = {}
def font(weight):
    if weight not in FONTS:
        FONTS[weight] = TTFont(f'/usr/share/fonts/opentype/inter/Inter-{weight}.otf')
    return FONTS[weight]

def text_g(txt, cx, cy, size, fill, weight='SemiBold', spacing=0):
    f = font(weight); gs = f.getGlyphSet(); cmap = f.getBestCmap()
    upm = f['head'].unitsPerEm; k = size / upm
    x = 0; parts = []
    for ch in txt:
        g = cmap[ord(ch)]
        pen = SVGPathPen(gs); gs[g].draw(pen)
        d = pen.getCommands()
        if d:
            parts.append(f'<path transform="translate({x:.1f} 0)" d="{d}"/>')
        x += gs[g].width + spacing * upm
    x -= spacing * upm
    capH = f['OS/2'].sCapHeight
    tx = cx - x * k / 2
    ty = cy + capH * k / 2
    return (f'<g fill="{fill}" transform="translate({tx:.2f} {ty:.2f}) scale({k:.5f} {-k:.5f})">'
            + ''.join(parts) + '</g>')

def hexagon(cx, cy, r, **attr):
    import math
    pts = [(cx + r * math.cos(math.radians(a)), cy + r * math.sin(math.radians(a))) for a in range(-90, 270, 60)]
    a = ' '.join(f'{k.replace("_", "-")}="{v}"' for k, v in attr.items())
    return f'<polygon points="{" ".join(f"{x:.1f},{y:.1f}" for x, y in pts)}" {a}/>'

def frame(body, bg, border=False):
    b = f' stroke="{LINE}" stroke-width="6"' if border else ''
    inset = 3 if border else 0
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {S} {S}" width="50mm" height="50mm">'
            f'<rect x="{inset}" y="{inset}" width="{S-2*inset}" height="{S-2*inset}" rx="{RX}" fill="{bg}"{b}/>'
            + body + '</svg>')

def clip_top(bg, h):
    # 上だけ角丸の帯
    return (f'<path fill="{bg}" d="M0 {h}V{RX}A{RX} {RX} 0 0 1 {RX} 0H{S-RX}A{RX} {RX} 0 0 1 {S} {RX}V{h}Z"/>')

# ==== デザイン ====
def A_forest():
    return frame(logo_g(500, 470, 760) + text_g('hiveplusx.com', 500, 800, 52, WHITE, spacing=0.02), FOREST)

def B_white():
    return frame(logo_g(500, 470, 760, CHAR) + text_g('hiveplusx.com', 500, 800, 52, FOREST, spacing=0.02), WHITE, border=True)

def C_forest(n):
    # hive+x は A と同じ幅 (760)、機器名は前の案の半分の高さ (85)
    body = (logo_g(500, 400, 760)
            + f'<rect x="440" y="575" width="120" height="12" rx="6" fill="{HONEY}"/>'
            + dev_g(n, 500, 690, height=85)
            + text_g('hiveplusx.com', 500, 860, 40, '#CFE0D5', spacing=0.02))
    return frame(body, FOREST)

def D_split(n):
    # 上 = 緑に hive+x (A と同じ幅)、下 = 白に機器名 (前の案の半分)。白い部分は前の 400 から 180 へ
    top = 820
    body = (clip_top(FOREST, top) + logo_g(500, 420, 760)
            + f'<rect x="0" y="{top-4}" width="{S}" height="12" fill="{HONEY}"/>'
            + dev_g(n, 500, top + 92, height=75, text=CHAR))
    return frame(body, WHITE, border=True)

def F_icon(n):
    # ファビコン (E2) の h + x を大きく、下に機器名 (v1 の7割の大きさ)
    fav = clean(find('favicon_v2/E2_hpx_diag_big.svg', 'favicon/favicon_app.svg'))
    inner = ''.join(re.findall(r'<path [^>]+/>', fav))
    body = (f'<g transform="translate(160 20) scale(7.0)">{inner}</g>'
            + dev_g(n, 500, 830, height=77))
    return frame(body, FOREST)

# ---- 白背景版 (C・D・F) ----
def C_white(n):
    body = (logo_g(500, 400, 760, CHAR)
            + f'<rect x="440" y="575" width="120" height="12" rx="6" fill="{HONEY}"/>'
            + dev_g(n, 500, 690, height=85, text=CHAR)
            + text_g('hiveplusx.com', 500, 860, 40, FOREST, spacing=0.02))
    return frame(body, WHITE, border=True)

def D_white(n):
    # 上 = 白に hive+x、下 = 緑の細い帯に白文字の機器名 (D の色を入れかえたもの)
    top = 820
    body = (logo_g(500, 420, 760, CHAR)
            + f'<path fill="{FOREST}" d="M3 {top}H{S-3}V{S-RX}A{RX-3} {RX-3} 0 0 1 {S-RX} {S-3}H{RX}A{RX-3} {RX-3} 0 0 1 3 {S-RX}Z"/>'
            + f'<rect x="3" y="{top-4}" width="{S-6}" height="12" fill="{HONEY}"/>'
            + dev_g(n, 500, top + 92, height=75))
    return frame(body, WHITE, border=True)

def W_wide():
    # HPX-W 用の横長 (100mm × 30mm 想定): 左の白に hive+x、ハチミツ色の縦線、右の緑に白文字の HPX-W。
    # D_white を横に並べかえた形
    W, H, R = 2000, 600, 110
    cut = 1300   # 白と緑の境目
    body = (f'<rect x="3" y="3" width="{W-6}" height="{H-6}" rx="{R}" fill="{WHITE}" stroke="{LINE}" stroke-width="6"/>'
            + logo_g(cut / 2, H / 2 + 10, 980, CHAR)
            + f'<path fill="{FOREST}" d="M{cut} 3H{W-R}A{R-3} {R-3} 0 0 1 {W-3} {R}V{H-R}A{R-3} {R-3} 0 0 1 {W-R} {H-3}H{cut}Z"/>'
            + f'<rect x="{cut-4}" y="3" width="12" height="{H-6}" fill="{HONEY}"/>'
            + dev_g('W', (cut + 6 + W - 3) / 2, H / 2, height=100, width=W - cut - 140))
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" width="100mm" height="30mm">'
            + body + '</svg>')

def F_white(n):
    fav = clean(find('favicon_v2/E2_hpx_diag_big.svg', 'favicon/favicon_app.svg'))
    inner = ''.join(re.findall(r'<path [^>]+/>', fav)).replace('#FFFFFF', CHAR)
    body = (f'<g transform="translate(160 20) scale(7.0)">{inner}</g>'
            + dev_g(n, 500, 830, height=77, text=CHAR))
    return frame(body, WHITE, border=True)

out = {
    'A_forest_logo': A_forest(),
    'B_white_logo': B_white(),
}
for n in ['Core', 'TH', 'W', 'F']:
    out[f'C_forest_HPX-{n}'] = C_forest(n)
    out[f'D_split_HPX-{n}'] = D_split(n)
    out[f'F_icon_HPX-{n}'] = F_icon(n)
    out[f'C_white_HPX-{n}'] = C_white(n)
    out[f'D_white_HPX-{n}'] = D_white(n)
    out[f'F_white_HPX-{n}'] = F_white(n)

# 2026-11-21 追加: D_white に HPX-B・HPX-CAM・HPX-Solar、HPX-W の横長
for n in ['B', 'CAM', 'Solar']:
    out[f'D_white_HPX-{n}'] = D_white(n)
out['D_white_HPX-W_wide'] = W_wide()

os.makedirs(os.path.join(HERE, 'svg'), exist_ok=True)
os.makedirs(os.path.join(HERE, 'png'), exist_ok=True)
for name, svg in out.items():
    p = os.path.join(HERE, 'svg', name + '.svg')
    open(p, 'w', encoding='utf-8').write('<?xml version="1.0" encoding="UTF-8"?>\n' + svg)
    w = 2000 if name.endswith('_wide') else 1000   # 横長は 2000×600px
    cairosvg.svg2png(bytestring=svg.encode(), write_to=os.path.join(HERE, 'png', name + '.png'),
                     output_width=w)
print(len(out), 'files')
