#!/usr/bin/env python3
# 基板 (ユニバーサル基板 2.54mm ピッチ) に部品を並べた配線図の SVG を作る
import sys, os
OUT = sys.argv[1]
os.makedirs(OUT, exist_ok=True)

FONT = "'Noto Sans CJK JP','Hiragino Sans','Yu Gothic',sans-serif"
COL = {
    "red": "#e53935", "black": "#212121", "yellow": "#fdd835", "blue": "#1e88e5",
    "white": "#fafafa", "green": "#43a047", "orange": "#fb8c00", "purple": "#8e24aa",
}

class S:
    def __init__(s, w, h):
        s.w, s.h, s.items, s.top = w, h, [], []
    def add(s, x): s.items.append(x)
    def text(s, x, y, t, size=16, anchor="start", weight="normal", fill="#333", layer=None):
        e = f'<text x="{x}" y="{y}" font-size="{size}" text-anchor="{anchor}" font-weight="{weight}" fill="{fill}" font-family="{FONT}">{t}</text>'
        (layer if layer is not None else s.items).append(e)
    def rect(s, x, y, w, h, fill, stroke="none", rx=8, sw=2):
        s.add(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="{rx}" fill="{fill}" stroke="{stroke}" stroke-width="{sw}"/>')
    def wire(s, pts, color, label=None, lpos=None):
        d = "M" + " L".join(f"{x},{y}" for x, y in pts)
        c = COL[color]
        s.add(f'<path d="{d}" fill="none" stroke="#ffffff" stroke-width="11" stroke-linejoin="round" stroke-linecap="round"/>')
        edge = "#555" if color in ("white", "yellow") else "#00000055"
        s.add(f'<path d="{d}" fill="none" stroke="{edge}" stroke-width="7.5" stroke-linejoin="round" stroke-linecap="round"/>')
        s.add(f'<path d="{d}" fill="none" stroke="{c}" stroke-width="5" stroke-linejoin="round" stroke-linecap="round"/>')
        for x, y in (pts[0], pts[-1]):
            s.top.append(f'<circle cx="{x}" cy="{y}" r="5" fill="{c}" stroke="#333" stroke-width="1.5"/>')
        if label:
            x, y = lpos
            s.top.append(f'<rect x="{x-4}" y="{y-15}" width="{len(label)*14+8}" height="21" rx="4" fill="#ffffffdd"/>')
            s.text(x, y, label, 14, fill="#333", weight="bold", layer=s.top)
    def dot(s, x, y, color):
        s.top.append(f'<circle cx="{x}" cy="{y}" r="7" fill="{COL[color]}" stroke="#333" stroke-width="1.5"/>')
    def pad(s, x, y, name, side):
        s.add(f'<circle cx="{x}" cy="{y}" r="8" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
        s.add(f'<circle cx="{x}" cy="{y}" r="3" fill="#5a4a14"/>')
        dx = 14 if side == "L" else -14
        s.text(x + dx, y + 5, name, 15, "start" if side == "L" else "end", "bold", "#fff")
    def save(s, name):
        body = "\n".join(s.items + s.top)
        svg = (f'<svg xmlns="http://www.w3.org/2000/svg" width="{s.w}" height="{s.h}" viewBox="0 0 {s.w} {s.h}">\n'
               f'<rect width="100%" height="100%" fill="#fffdf6"/>\n{body}\n</svg>\n')
        open(os.path.join(OUT, name), "w", encoding="utf-8").write(svg)

XL = ["D0", "D1", "D2", "D3", "D4", "D5", "D6"]
XR = ["5V", "GND", "3V3", "D10", "D9", "D8", "D7"]

def xiao(s, x, y):
    """XIAO nRF52840 を描く。ピン座標の dict を返す (表から見た向き、USB-C が上)"""
    w, h = 200, 330
    s.rect(x, y, w, h, "#1b5e20", "#0d3311", 10, 3)
    s.rect(x + 70, y - 14, 60, 30, "#b0b0b0", "#777", 6)          # USB-C
    s.text(x + 100, y - 22, "USB-C", 13, "middle", fill="#666")
    s.rect(x + 55, y + 120, 90, 90, "#cfd8dc", "#90a4ae", 4)      # シールド缶
    s.text(x + 100, y + 160, "XIAO", 16, "middle", "bold", "#37474f")
    s.text(x + 100, y + 180, "nRF52840", 13, "middle", fill="#37474f")
    p = {}
    for i, n in enumerate(XL):
        py = y + 40 + i * 42; s.pad(x, py, n, "L"); p[n] = (x, py)
    for i, n in enumerate(XR):
        py = y + 40 + i * 42; s.pad(x + w, py, n, "R"); p[n] = (x + w, py)
    s.text(x + 100, y + h + 26, "Seeed XIAO nRF52840（表）", 15, "middle", "bold")
    return p

def battery(s, x, y, label="単3リチウム電池 ×2（直列 約3V）"):
    """電池ボックス。(+端子, -端子) の座標を返す"""
    s.rect(x, y, 300, 80, "#455a64", "#263238", 10, 3)
    for i in range(2):
        bx = x + 18 + i * 140
        s.rect(bx, y + 18, 124, 44, "#c0ca33", "#827717", 20, 2)
        s.text(bx + 62, y + 46, "単3", 15, "middle", "bold", "#333")
    s.text(x + 14, y + 24, "−", 18, "start", "bold", "#fff")
    s.text(x + 286, y + 24, "＋", 16, "end", "bold", "#fff")
    s.text(x + 150, y + 108, label, 15, "middle", "bold")
    return (x + 300, y + 30), (x, y + 30)

def title(s, t, sub):
    s.rect(0, 0, s.w, 70, "#1f3a2a", rx=0)
    s.text(30, 45, t, 26, weight="bold", fill="#ffffff")
    s.text(s.w - 30, 45, sub, 15, "end", fill="#f5c542")

def notes(s, x, y, lines, head="注意"):
    s.rect(x, y, s.w - 2 * x, 34 + 26 * len(lines), "#fff3cd", "#e0b84a", 10, 2)
    s.text(x + 18, y + 26, head, 17, weight="bold", fill="#7a5a00")
    for i, l in enumerate(lines):
        s.text(x + 18, y + 54 + i * 26, l, 15, fill="#4a3a00")

def table(s, x, y, rows, widths, head):
    s.text(x, y - 12, head, 17, weight="bold")
    yy = y
    for r, row in enumerate(rows):
        bg = "#1f3a2a" if r == 0 else ("#ffffff" if r % 2 else "#f4f1e6")
        s.rect(x, yy, sum(widths), 30, bg, "#d8d2bd", 0, 1)
        xx = x
        for c, cell in enumerate(row):
            if r and c == 0 and cell in COL:
                s.add(f'<rect x="{xx+10}" y="{yy+9}" width="34" height="12" rx="3" fill="{COL[cell]}" stroke="#555" stroke-width="1"/>')
                cell = {"red": "赤", "black": "黒", "yellow": "黄", "blue": "青", "white": "白", "green": "緑", "orange": "橙", "purple": "紫"}[cell]
                s.text(xx + 52, yy + 21, cell, 14, fill="#333")
            else:
                s.text(xx + 10, yy + 21, cell, 14, weight="bold" if r == 0 else "normal", fill="#fff" if r == 0 else "#333")
            xx += widths[c]
        yy += 30
    return yy

def rails(s, x1, x2, ym, yp):
    """ブレッドボードの電源レール (上が −、下が ＋)"""
    s.rect(x1 - 20, ym - 22, x2 - x1 + 40, yp - ym + 44, "#f1efe6", "#cfc8b0", 8, 2)
    s.add(f'<line x1="{x1}" y1="{ym-14}" x2="{x2}" y2="{ym-14}" stroke="#1e88e5" stroke-width="2"/>')
    s.add(f'<line x1="{x1}" y1="{yp+14}" x2="{x2}" y2="{yp+14}" stroke="#e53935" stroke-width="2"/>')
    for xx in range(x1, x2 + 1, 22):
        for yy in (ym, yp):
            s.add(f'<rect x="{xx-3}" y="{yy-3}" width="6" height="6" fill="#bdb6a0"/>')
    s.text(x1 - 12, ym + 5, "−", 18, "end", "bold", "#1e88e5")
    s.text(x1 - 12, yp + 5, "＋", 16, "end", "bold", "#e53935")
    s.text(250, yp + 46, "電源レール: 上の列 − ＝GND（青線）、下の列 ＋＝3V（赤線）", 13, fill="#555", weight="bold")

def cap(s, x, ytop, y1, y2, name, sub, big=False, right=False, left=False):
    """ラジアルリードのコンデンサー。足は (x-6,y1) と (x+6,y2) に刺さる。向きなし"""
    s.add(f'<line x1="{x-6}" y1="{ytop+20}" x2="{x-6}" y2="{y1}" stroke="#888" stroke-width="3"/>')
    s.add(f'<line x1="{x+6}" y1="{ytop+20}" x2="{x+6}" y2="{y2}" stroke="#888" stroke-width="3"/>')
    w = 30 if big else 22
    s.rect(x - w / 2, ytop - (8 if big else 0), w, 28 if big else 22, "#f0c419" if big else "#3f6fb5", "#555", 8, 1.5)
    s.top.append(f'<circle cx="{x-6}" cy="{y1}" r="4" fill="#666"/>')
    s.top.append(f'<circle cx="{x+6}" cy="{y2}" r="4" fill="#666"/>')
    lx, ly, an = (x + 24, ytop + 2, "start") if right else ((x - 24, ytop + 2, "end") if left else (x, ytop - 34, "middle"))
    s.text(lx, ly, name, 14, an, "bold", layer=s.top)
    s.text(lx, ly + 18, sub, 12, an, fill="#666", layer=s.top)

def cr123a(s, x, y):
    """CR123A 電池ケース。(+端子, -端子) を返す"""
    s.rect(x, y, 260, 70, "#37474f", "#222", 10, 3)
    s.rect(x + 20, y + 14, 220, 42, "#b0bec5", "#607d8b", 20, 2)
    s.rect(x + 236, y + 26, 10, 18, "#90a4ae", "#607d8b", 2, 1)
    s.text(x + 130, y + 41, "CR123A（3V）", 15, "middle", "bold", "#263238")
    s.text(x + 14, y + 22, "−", 18, "start", "bold", "#fff")
    s.text(x + 252, y + 22, "＋", 14, "end", "bold", "#fff")
    s.text(x + 130, y + 96, "CR123A 用電池ケース ＋ CR123A 電池", 14, "middle", "bold")
    return (x + 260, y + 35), (x, y + 35)



P = 30                      # 穴の間隔 (px) = 2.54mm
NC, NR = 24, 16             # 穴の数 (列 A〜X、行 1〜16) ≒ 61 × 41mm
OX, OY = 330, 190           # 穴 A1 の位置
COLS = "ABCDEFGHIJKLMNOPQRSTUVWX"

def H(c, r):
    return (OX + c * P, OY + r * P)

def hn(c, r):
    return f"{COLS[c]}{r+1}"

def board(s):
    x0, y0 = OX - 22, OY - 22
    w, h = (NC - 1) * P + 44, (NR - 1) * P + 44
    s.rect(x0 - 6, y0 - 6, w + 12, h + 12, "#c8a165", "#8d6e3f", 10, 2)
    s.rect(x0, y0, w, h, "#e8d3a8", "#b08d57", 6, 1.5)
    for c in range(NC):
        s.text(OX + c * P, y0 - 12, COLS[c], 11, "middle", fill="#8d6e3f", weight="bold")
        for r in range(NR):
            x, y = H(c, r)
            s.add(f'<circle cx="{x}" cy="{y}" r="6" fill="#d9a441" stroke="#a87b24" stroke-width="1"/>')
            s.add(f'<circle cx="{x}" cy="{y}" r="2.2" fill="#5d4520"/>')
    for r in range(NR):
        s.text(x0 - 12, OY + r * P + 4, str(r + 1), 11, "end", fill="#8d6e3f", weight="bold")
    s.text(x0, y0 - 34, "ユニバーサル基板（2.54mm ピッチ、穴 24×16 ≒ 61×41mm 以上。図は部品の面から見たところ）", 13, fill="#555", weight="bold")

def wireh(s, pts, color, label=None, lpos=None):
    s.wire([H(*p) for p in pts], color, label, lpos)

def xiao_pcb(s, c0, r0):
    """XIAO をピンヘッダで基板に付ける。左列 c0 (D0〜D6)、右列 c0+6 (5V,GND,3V3,D10〜D7)"""
    x1, y1 = H(c0, r0); x2, y2 = H(c0 + 6, r0 + 6)
    s.top.append(f'<rect x="{x1-14}" y="{y1-26}" width="{x2-x1+28}" height="{y2-y1+52}" rx="8" fill="#1b5e20" fill-opacity="0.92" stroke="#0d3311" stroke-width="2"/>')
    s.top.append(f'<rect x="{(x1+x2)/2-26}" y="{y1-38}" width="52" height="22" rx="5" fill="#b0b0b0" stroke="#777"/>')
    s.top.append(f'<rect x="{(x1+x2)/2-34}" y="{(y1+y2)/2-30}" width="68" height="60" rx="3" fill="#cfd8dc" stroke="#90a4ae"/>')
    s.text((x1 + x2) / 2, (y1 + y2) / 2 - 2, "XIAO", 14, "middle", "bold", "#37474f", layer=s.top)
    s.text((x1 + x2) / 2, (y1 + y2) / 2 + 16, "nRF52840", 11, "middle", fill="#37474f", layer=s.top)
    p = {}
    for i, n in enumerate(XL):
        x, y = H(c0, r0 + i); p[n] = (c0, r0 + i)
        s.top.append(f'<circle cx="{x}" cy="{y}" r="7" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
        s.text(x + 12, y + 5, n, 12, "start", "bold", "#fff", layer=s.top)
    for i, n in enumerate(XR):
        x, y = H(c0 + 6, r0 + i); p[n] = (c0 + 6, r0 + i)
        s.top.append(f'<circle cx="{x}" cy="{y}" r="7" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
        s.text(x - 12, y + 5, n, 12, "end", "bold", "#fff", layer=s.top)
    s.text((x1 + x2) / 2, y2 + 44, "XIAO（ピンソケットに差す）", 12, "middle", "bold", "#1b5e20", layer=s.top)
    return p

def res_pcb(s, a, b, label, bands):
    (x1, y1), (x2, y2) = H(*a), H(*b)
    s.top.append(f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="#888" stroke-width="3"/>')
    cx = (x1 + x2) / 2
    s.top.append(f'<rect x="{cx-30}" y="{y1-10}" width="60" height="20" rx="9" fill="#e8c88f" stroke="#a07c3c" stroke-width="1.5"/>')
    for i, c in enumerate(bands):
        s.top.append(f'<rect x="{cx-22+i*11}" y="{y1-10}" width="5" height="20" fill="{c}"/>')
    for x, y in ((x1, y1), (x2, y2)):
        s.top.append(f'<circle cx="{x}" cy="{y}" r="4" fill="#666"/>')
    s.text(cx, y1 - 16, label, 12, "middle", "bold", "#333", layer=s.top)

def cap_pcb(s, a, b, label, big=False):
    """足 2本 (5mm = 穴 2つ分)。本体は上に描く"""
    (x1, y1), (x2, y2) = H(*a), H(*b)
    cx = (x1 + x2) / 2
    w = 26 if big else 20
    s.top.append(f'<line x1="{x1}" y1="{y1}" x2="{cx-4}" y2="{y1-14}" stroke="#888" stroke-width="2.5"/>')
    s.top.append(f'<line x1="{x2}" y1="{y2}" x2="{cx+4}" y2="{y2-14}" stroke="#888" stroke-width="2.5"/>')
    s.top.append(f'<rect x="{cx-w/2}" y="{y1-14-w}" width="{w}" height="{w}" rx="7" fill="{"#f0c419" if big else "#3f6fb5"}" stroke="#555" stroke-width="1.5"/>')
    for x, y in ((x1, y1), (x2, y2)):
        s.top.append(f'<circle cx="{x}" cy="{y}" r="4" fill="#666"/>')
    ty = y1 - 14 - w - 6
    s.top.append(f'<rect x="{cx-len(label)*4-4}" y="{ty-12}" width="{len(label)*8+8}" height="16" rx="3" fill="#fffdf6e6"/>')
    s.text(cx, ty, label, 12, "middle", "bold", "#333", layer=s.top)

def conn_pcb(s, holes, names, title, color="#fafafa", tpos="below"):
    xs = [H(*h)[0] for h in holes]; ys = [H(*h)[1] for h in holes]
    s.top.append(f'<rect x="{min(xs)-13}" y="{min(ys)-13}" width="{max(xs)-min(xs)+26}" height="{max(ys)-min(ys)+26}" rx="4" fill="{color}" stroke="#555" stroke-width="1.5"/>')
    for (x, y), n in zip(zip(xs, ys), names):
        s.top.append(f'<circle cx="{x}" cy="{y}" r="5" fill="#d4af37" stroke="#555"/>')
    return xs, ys

def power_block(s, p):
    """3V3 と GND を右の 2列 (U列=赤、W列=黒) にまとめ、電池ケースの線とコンデンサーを付ける"""
    wireh(s, [p["3V3"], (20, 5), (20, 14)], "red")
    wireh(s, [p["GND"], (22, 4), (22, 15)], "black")
    cap_pcb(s, (20, 9), (22, 9), "100µF", big=True)
    for c in (20, 22):
        x, y = H(c, 14)
        s.top.append(f'<circle cx="{x}" cy="{y}" r="8" fill="none" stroke="#d32f2f" stroke-width="2.5"/>')
    s.text(H(20, 14)[0] - 22, H(20, 14)[1] + 6, "＋", 15, "end", "bold", "#e53935", layer=s.top)
    s.text(H(22, 14)[0] + 22, H(22, 14)[1] + 6, "−", 16, "start", "bold", "#333", layer=s.top)
    s.text(H(23, 14)[0] + 30, H(23, 14)[1] - 8, "電池ケースの", 12, "start", "bold", layer=s.top)
    s.text(H(23, 14)[0] + 30, H(23, 14)[1] + 8, "線をはんだ付け", 12, "start", "bold", layer=s.top)

def battery_off(s):
    """基板の下に CR123A ケース。線を U15・W15 に直接はんだ付けする"""
    bp, bm = cr123a(s, 700, 740)
    tp, tm = H(20, 14), H(22, 14)
    s.wire([bp, (tp[0] + 30, bp[1]), (tp[0] + 30, 700), (tp[0], 700), tp], "red")
    s.wire([bm, (680, bm[1]), (680, 880), (1060, 880), (1060, 700), (tm[0], 700), tm], "black")

COMMON_NOTES = [
    "・図は部品を載せる面から見たところ。部品の足は穴に差して裏ではんだ付けし、線（被覆のあるすずめっき線やジャンパー線）でつなぐ。",
    "・線が重なって見えるところは、被覆のある線どうしなので交差してかまわない。隣の穴とはんだでつながないよう注意。",
    "・XIAO にピンヘッダ（オス）をはんだ付けし、基板にはピンソケット（メス、7ピン×2）をはんだ付けして差し込む。XIAO を抜いて交換できる。",
    "・USB で書き込むときは、必ず電池を外す（3V3 に電池を直結しているため）。",
]

# ================================================================== HPX-W
s = S(1280, 1740)
title(s, "HPX-W（重量センサ）基板の配線図", "ユニバーサル基板版 ／ HX711 DT=D0, SCK=D1 ／ 電源 CR123A")
board(s)
p = xiao_pcb(s, 12, 3)
power_block(s, p)
cap_pcb(s, (20, 11), (22, 11), "0.1µF")
# HX711 モジュール (ピンヘッダで H列に付ける)
x1, y1 = H(0, 2); x2, y2 = H(7, 7)
s.top.append(f'<rect x="{x1-14}" y="{y1-14}" width="{x2-x1+28}" height="{y2-y1+28}" rx="6" fill="#2e7d32" fill-opacity="0.93" stroke="#1b5e20" stroke-width="2"/>')
s.top.append(f'<rect x="{(x1+x2)/2-26}" y="{(y1+y2)/2-26}" width="52" height="52" fill="#212121"/>')
s.text((x1 + x2) / 2, (y1 + y2) / 2 + 5, "HX711", 12, "middle", "bold", "#eee", layer=s.top)
hx = {}
for i, n in enumerate(["GND", "DT", "SCK", "VCC"]):
    x, y = H(7, 3 + i); hx[n] = (7, 3 + i)
    s.top.append(f'<circle cx="{x}" cy="{y}" r="7" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
    s.text(x - 12, y + 5, n, 12, "end", "bold", "#fff", layer=s.top)
lc = {}
for i, n in enumerate(["E+", "E-", "A-", "A+", "B-", "B+"]):
    x, y = H(0, 2 + i); lc[n] = (x, y)
    s.top.append(f'<circle cx="{x}" cy="{y}" r="6" fill="#d4af37" stroke="#8a6d1d" stroke-width="1.5"/>')
    s.text(x + 11, y + 4, n, 11, "start", "bold", "#fff", layer=s.top)
s.text((x1 + x2) / 2, y2 + 34, "HX711 モジュール（ピンヘッダで付ける）", 12, "middle", "bold", "#1b5e20", layer=s.top)
# ロードセル (基板の外)
s.rect(30, 200, 200, 150, "#b0bec5", "#78909c", 6, 3)
s.text(130, 270, "ロードセル", 15, "middle", "bold", "#263238")
s.text(130, 292, "SC601（120kg）", 12, "middle", fill="#263238")
s.text(130, 380, "線の色は説明書で確認", 12, "middle", fill="#b23", weight="bold")
for n, c in [("E+", "red"), ("E-", "black"), ("A-", "white"), ("A+", "green")]:
    x, y = lc[n]
    s.top.append(f'<path d="M{x},{y} L{x-40},{y} L230,{y}" fill="none" stroke="{COL[c]}" stroke-width="5"/>' if c != "white" else
                 f'<path d="M{x},{y} L230,{y}" fill="none" stroke="#777" stroke-width="7"/><path d="M{x},{y} L230,{y}" fill="none" stroke="#fafafa" stroke-width="4.5"/>')
# 配線
wireh(s, [p["D0"], (10, 3), (10, 4), hx["DT"]], "yellow")
wireh(s, [p["D1"], (11, 4), (11, 5), hx["SCK"]], "blue")
wireh(s, [(22, 4), (22, 1), (7, 1), hx["GND"]], "black")
wireh(s, [(20, 12), (9, 12), (9, 6), hx["VCC"]], "red")
battery_off(s)
y = table(s, 60, 940, [
    ["線", "どこから（穴）", "どこへ（穴）", "役目"],
    ["yellow", f"XIAO D0（{hn(12,3)}）", f"HX711 DT（{hn(7,4)}）", "重さのデータ"],
    ["blue", f"XIAO D1（{hn(12,4)}）", f"HX711 SCK（{hn(7,5)}）", "読み取りの合図"],
    ["red", f"XIAO 3V3（{hn(18,5)}）", f"{hn(20,5)} → {hn(20,14)}（＋の列）", "3V の列。電池＋・100µF・0.1µF もこの列"],
    ["black", f"XIAO GND（{hn(18,4)}）", f"{hn(22,4)} → {hn(22,15)}（−の列）", "GND の列。電池−・コンデンサーもこの列"],
    ["red", f"＋の列（{hn(20,12)}）", f"HX711 VCC（{hn(7,6)}）", "HX711 の電源"],
    ["black", f"−の列（{hn(22,1)}）", f"HX711 GND（{hn(7,3)}）", ""],
], [100, 300, 330, 430], "つなぐ線（穴の位置は 列の文字＋行の数字）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["Seeed XIAO BLE nRF52840 ＋ ピンヘッダ 7ピン×2（XIAO 側）＋ ピンソケット 7ピン×2（基板側）", "1", f"{hn(12,3)}〜{hn(18,9)}"],
    ["HX711 モジュール ＋ ピンヘッダ 4ピン", "1", f"{hn(7,3)}〜{hn(7,6)}（GND・DT・SCK・VCC）"],
    ["ロードセル SC601 120kg", "1", "HX711 の E+・E-・A-・A+ に直接はんだ付け"],
    ["絶縁ラジアルリード型積層セラミックコンデンサー 100µF 6.3V 5mm", "1", f"{hn(20,9)}・{hn(22,9)}（向きなし）"],
    ["積層セラミックコンデンサー 0.1µF 50V C0G 5mm", "1", f"{hn(20,11)}・{hn(22,11)}"],
    ["CR123A 用電池ケース ／ CR123A 電池", "各1", f"赤線を {hn(20,14)}（＋）、黒線を {hn(22,14)}（−）にはんだ付け"],
    ["ユニバーサル基板（穴 24×16 以上）・すずめっき線・被覆線", "", "手持ちに合わせて"],
], [560, 60, 540], "部品表")
notes(s, 60, y + 20, COMMON_NOTES + [
    "・多くの HX711 モジュールには電源用のコンデンサーが載っているので、0.1µF は XIAO の電源の近くにまとめた。",
])
s.save("HPX-W_pcb.svg")

# ================================================================== HPX-TH
s = S(1280, 1940)
title(s, "HPX-TH（温湿度センサ）基板の配線図", "ユニバーサル基板版 ／ SHT40: SDA=D4, SCL=D5 ／ サーミスター: D3→10kΩ→A2 ／ 電源 CR123A")
board(s)
p = xiao_pcb(s, 12, 3)
power_block(s, p)
res_pcb(s, (6, 3), (10, 3), "10kΩ 1%", ["#8d5524", "#212121", "#212121", "#e53935", "#8d5524"])
wireh(s, [p["D2"], (10, 5), (10, 3)], "yellow")
wireh(s, [p["D3"], (6, 6), (6, 3)], "purple")
wireh(s, [(10, 3), (10, 2), (2, 2), (2, 9)], "yellow")
wireh(s, [(2, 7), (3, 7)], "yellow")
cap_pcb(s, (3, 7), (5, 7), "0.1µF")
wireh(s, [(5, 7), (5, 15)], "black")
wireh(s, [(2, 10), (2, 15), (22, 15)], "black")
conn_pcb(s, [(2, 9), (2, 10)], ["", ""], "")
# サーミスター (基板の外、左)
tx, ty = H(2, 9)[0], H(2, 9)[1]
s.add(f'<path d="M{tx},{ty} L{tx-60},{ty} L{tx-60},{ty+10} L70,{ty+10}" fill="none" stroke="#9e9e9e" stroke-width="4"/>')
s.add(f'<path d="M{tx},{ty+30} L{tx-60},{ty+30} L{tx-60},{ty+20} L70,{ty+20}" fill="none" stroke="#9e9e9e" stroke-width="4"/>')
s.add(f'<ellipse cx="56" cy="{ty+15}" rx="18" ry="13" fill="#5d4037" stroke="#3e2723" stroke-width="2"/>')
s.text(40, ty - 30, "サーミスター 10kΩ", 14, weight="bold")
s.text(40, ty - 12, "（向きなし・巣箱の中へ）", 12, fill="#666")
wireh(s, [(6, 13), (6, 15)], "black")
wireh(s, [(7, 13), (7, 14), (20, 14)], "red")
wireh(s, [p["D4"], (10, 7), (10, 11), (8, 11), (8, 13)], "green")
wireh(s, [p["D5"], (11, 8), (11, 12), (9, 12), (9, 13)], "blue")
# SHT40 モジュールをピンヘッダで基板に直付け (G14〜J14)。本体は下側に張り出す
x1, y1 = H(6, 13); x2, _ = H(9, 13)
s.top.append(f'<rect x="{x1-16}" y="{y1-16}" width="{x2-x1+32}" height="110" rx="6" fill="#5e35b1" fill-opacity="0.5" stroke="#311b92" stroke-width="2"/>')
s.top.append(f'<rect x="{(x1+x2)/2-16}" y="{y1+40}" width="32" height="32" rx="2" fill="#cfd8dc" stroke="#78909c"/>')
s.text((x1 + x2) / 2, y1 + 88, "SHT40", 12, "middle", "bold", "#fff", layer=s.top)
for i, n in enumerate(["GND", "VIN", "SDA", "SCL"]):
    x, y = H(6 + i, 13)
    s.top.append(f'<circle cx="{x}" cy="{y}" r="7" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
    s.text(x, y + 24, n, 10, "middle", "bold", "#fff", layer=s.top)
s.text((x1 + x2) / 2, y1 + 118, "SHT40 モジュール（ピンヘッダで直付け）", 12, "middle", "bold", "#311b92", layer=s.top)
s.text((x1 + x2) / 2, y1 + 134, "ピンの並びは基板の印字で確認", 11, "middle", fill="#b23", weight="bold", layer=s.top)
battery_off(s)
y = table(s, 60, 1000, [
    ["線", "どこから（穴）", "どこへ（穴）", "役目"],
    ["purple", f"XIAO D3（{hn(12,6)}）", f"10kΩ の片方（{hn(6,3)}）", "測る瞬間だけ電気を流す"],
    ["yellow", f"XIAO D2/A2（{hn(12,5)}）", f"10kΩ のもう片方（{hn(10,3)}）", "測る点"],
    ["yellow", f"測る点（{hn(10,3)}）", f"サーミスター端子（{hn(2,9)}）・0.1µF（{hn(3,7)}）", ""],
    ["black", f"サーミスター端子（{hn(2,10)}）・0.1µF（{hn(5,7)}）", f"GND の行（{hn(2,15)}〜{hn(22,15)}）", ""],
    ["green", f"XIAO D4（{hn(12,7)}）", f"SHT40 SDA（{hn(8,13)}）", "データ（I2C）"],
    ["blue", f"XIAO D5（{hn(12,8)}）", f"SHT40 SCL（{hn(9,13)}）", "合図（I2C）"],
    ["red", f"＋の列（{hn(20,14)}）", f"SHT40 VIN（{hn(7,13)}）", "SHT40 の電源"],
    ["black", f"GND の行（{hn(6,15)}）", f"SHT40 GND（{hn(6,13)}）", ""],
    ["red", f"XIAO 3V3（{hn(18,5)}）", f"{hn(20,5)} → {hn(20,14)}（＋の列）", "電池＋・100µF もこの列"],
    ["black", f"XIAO GND（{hn(18,4)}）", f"{hn(22,4)} → {hn(22,15)}（−の列）", "電池−・100µF もこの列"],
], [100, 360, 380, 320], "つなぐ線（穴の位置は 列の文字＋行の数字）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["Seeed XIAO BLE nRF52840 ＋ ピンヘッダ 7ピン×2（XIAO 側）＋ ピンソケット 7ピン×2（基板側）", "1", f"{hn(12,3)}〜{hn(18,9)}"],
    ["SHT40 モジュール（温湿度、I2C）", "1", f"ピンヘッダ 4ピンで {hn(6,13)}〜{hn(9,13)} に直付け"],
    ["サーミスター 10kΩ（平行線タイプ）", "1", f"2ピン（{hn(2,9)}・{hn(2,10)}）へ"],
    ["抵抗 10kΩ 1%（金属皮膜） ★リストに無い・必要", "1", f"{hn(6,3)}・{hn(10,3)}"],
    ["積層セラミックコンデンサー 0.1µF 50V C0G 5mm", "1", f"{hn(3,7)}・{hn(5,7)}（測る点と GND の間）"],
    ["絶縁ラジアルリード型積層セラミックコンデンサー 100µF 6.3V 5mm", "1", f"{hn(20,9)}・{hn(22,9)}"],
    ["ピンヘッダかコネクター 2ピン・ピンヘッダ 4ピン", "各1", "サーミスター・SHT40 用"],
    ["CR123A 用電池ケース ／ CR123A 電池", "各1", f"赤線を {hn(20,14)}（＋）、黒線を {hn(22,14)}（−）にはんだ付け"],
    ["ユニバーサル基板（穴 24×16 以上）・すずめっき線・被覆線", "", "手持ちに合わせて"],
], [560, 60, 540], "部品表")
notes(s, 60, y + 20, COMMON_NOTES + [
    "・HPX-TH のファームはまだありません。この配線に合わせて作ります。SHT40 は 3.6V まで（5V にはつながない）。",
    "・SHT40 は基板に直付けなので、基板ごと巣箱の中の湿度を測りたい場所に置く。電池やケースで空気がこもらないよう、風の通るケースに。",
])
s.save("HPX-TH_pcb.svg")

# ================================================================== HPX-A
s = S(1280, 1740)
title(s, "HPX-A（電柵アラート）基板の配線図", "ユニバーサル基板版 ／ D1→10kΩ→D0(A0)、CdS セル D0–GND ／ 電源 CR123A")
board(s)
p = xiao_pcb(s, 12, 3)
power_block(s, p)
cap_pcb(s, (20, 11), (22, 11), "0.1µF")
res_pcb(s, (6, 2), (10, 2), "10kΩ", ["#8d5524", "#212121", "#f57c00", "#d4af37"])
wireh(s, [p["D1"], (10, 4), (10, 2)], "purple")
wireh(s, [p["D0"], (11, 3), (11, 1), (6, 1), (6, 2)], "yellow")
wireh(s, [(6, 2), (2, 2), (2, 9)], "yellow")
wireh(s, [(2, 10), (2, 15), (22, 15)], "black")
conn_pcb(s, [(2, 9), (2, 10)], ["", ""], "")
tx, ty = H(2, 9)
s.add(f'<path d="M{tx},{ty} L{tx-60},{ty} L{tx-60},{ty+10} L90,{ty+10}" fill="none" stroke="#9e9e9e" stroke-width="4"/>')
s.add(f'<path d="M{tx},{ty+30} L{tx-60},{ty+30} L{tx-60},{ty+20} L90,{ty+20}" fill="none" stroke="#9e9e9e" stroke-width="4"/>')
s.add(f'<circle cx="68" cy="{ty+15}" r="20" fill="#f3e5ab" stroke="#8d6e63" stroke-width="3"/>')
s.add(f'<path d="M54,{ty+7} Q68,{ty+13} 82,{ty+7} M54,{ty+15} Q68,{ty+21} 82,{ty+15} M54,{ty+23} Q68,{ty+29} 82,{ty+23}" stroke="#c62828" stroke-width="2" fill="none"/>')
s.text(40, ty - 34, "CdS セル 5506", 14, weight="bold")
s.text(40, ty - 16, "（向きなし・線で延長）", 12, fill="#666")
s.text(40, ty + 62, "警報器の赤いカバーに向け、", 12, fill="#666")
s.text(40, ty + 78, "黒いもので遮光", 12, fill="#666")
battery_off(s)
y = table(s, 60, 940, [
    ["線", "どこから（穴）", "どこへ（穴）", "役目"],
    ["purple", f"XIAO D1（{hn(12,4)}）", f"10kΩ の片方（{hn(10,2)}）", "読む瞬間だけ電気を流す"],
    ["yellow", f"XIAO D0/A0（{hn(12,3)}）", f"10kΩ のもう片方（{hn(6,2)}）", "測る点"],
    ["yellow", f"測る点（{hn(6,2)}）", f"CdS 端子（{hn(2,9)}）", ""],
    ["black", f"CdS 端子（{hn(2,10)}）", f"GND の行（{hn(2,15)}〜{hn(22,15)}）", ""],
    ["red", f"XIAO 3V3（{hn(18,5)}）", f"{hn(20,5)} → {hn(20,14)}（＋の列）", "電池＋・100µF・0.1µF もこの列"],
    ["black", f"XIAO GND（{hn(18,4)}）", f"{hn(22,4)} → {hn(22,15)}（−の列）", "電池−・コンデンサーもこの列"],
], [100, 330, 360, 370], "つなぐ線（穴の位置は 列の文字＋行の数字）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["Seeed XIAO BLE nRF52840 ＋ ピンヘッダ 7ピン×2（XIAO 側）＋ ピンソケット 7ピン×2（基板側）", "1", f"{hn(12,3)}〜{hn(18,9)}"],
    ["CdS セル（フォトレジスタ）5506 φ5mm", "1", f"2ピン（{hn(2,9)}・{hn(2,10)}）から線で延長"],
    ["抵抗 10kΩ ★リストに無い・必要", "1", f"{hn(6,2)}・{hn(10,2)}"],
    ["積層セラミックコンデンサー 0.1µF 50V C0G 5mm", "1", f"{hn(20,11)}・{hn(22,11)}（電源側。測る点には付けない）"],
    ["絶縁ラジアルリード型積層セラミックコンデンサー 100µF 6.3V 5mm", "1", f"{hn(20,9)}・{hn(22,9)}"],
    ["ピンヘッダかコネクター 2ピン", "1", "CdS 用"],
    ["CR123A 用電池ケース ／ CR123A 電池", "各1", f"赤線を {hn(20,14)}（＋）、黒線を {hn(22,14)}（−）にはんだ付け"],
    ["ユニバーサル基板（穴 24×16 以上）・すずめっき線・被覆線", "", "手持ちに合わせて"],
], [560, 60, 540], "部品表")
notes(s, 60, y + 20, COMMON_NOTES + [
    "・フェンスアラート（StaFIX）の回路と XIAO を電線でつながない。光だけで読む（柵線の高電圧が回り込むため）。",
])
s.save("HPX-A_pcb.svg")
print("ok")
