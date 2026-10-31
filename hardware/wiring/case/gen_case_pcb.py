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



def wireh(s, pts, color):
    s.wire([H(*p) for p in pts], color)

def xiao_pcb(s, c0, r0):
    """XIAO をピンソケットに差す。左列 c0 (D0〜D6)、右列 c0+6 (5V,GND,3V3,D10〜D7)"""
    x1, y1 = H(c0, r0); x2, y2 = H(c0 + 6, r0 + 6)
    s.top.append(f'<rect x="{x1-14}" y="{y1-24}" width="{x2-x1+28}" height="{y2-y1+48}" rx="8" fill="#1b5e20" fill-opacity="0.92" stroke="#0d3311" stroke-width="2"/>')
    s.top.append(f'<rect x="{(x1+x2)/2-24}" y="{y1-32}" width="48" height="18" rx="5" fill="#b0b0b0" stroke="#777"/>')
    cx, cy = (x1 + x2) / 2, (y1 + y2) / 2
    s.top.append(f'<rect x="{cx-44}" y="{cy-56}" width="88" height="76" rx="3" fill="#cfd8dc" stroke="#90a4ae"/>')
    s.text(cx, cy - 22, "XIAO", 16, "middle", "bold", "#37474f", layer=s.top)
    s.text(cx, cy - 2, "nRF52840", 12, "middle", fill="#37474f", layer=s.top)
    s.text(cx, cy + 50, "ピンソケットに差す", 12, "middle", "bold", "#e8f5e9", layer=s.top)
    p = {}
    for i, n in enumerate(XL):
        x, y = H(c0, r0 + i); p[n] = (c0, r0 + i)
        s.top.append(f'<circle cx="{x}" cy="{y}" r="8" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
        s.text(x + 14, y + 5, n, 13, "start", "bold", "#fff", layer=s.top)
    for i, n in enumerate(XR):
        x, y = H(c0 + 6, r0 + i); p[n] = (c0 + 6, r0 + i)
        s.top.append(f'<circle cx="{x}" cy="{y}" r="8" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
        s.text(x - 14, y + 5, n, 13, "end", "bold", "#fff", layer=s.top)
    return p

def res_stand(s, a, b, label, bands, lx, ly, anchor="end"):
    """抵抗を立てて付ける。本体は a の穴の上、もう一方の足を b へ折り返す"""
    (x1, y1), (x2, y2) = H(*a), H(*b)
    s.top.append(f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="#888" stroke-width="3"/>')
    s.top.append(f'<circle cx="{x1}" cy="{y1}" r="13" fill="#e8c88f" stroke="#a07c3c" stroke-width="2"/>')
    for i, c in enumerate(bands[:3]):
        s.top.append(f'<circle cx="{x1}" cy="{y1}" r="{10-i*3}" fill="none" stroke="{c}" stroke-width="2"/>')
    s.top.append(f'<circle cx="{x2}" cy="{y2}" r="4" fill="#666"/>')
    s.text(lx, ly, label, 13, anchor, "bold", "#333", layer=s.top)
    s.text(lx, ly + 16, "（立てて付ける）", 11, anchor, fill="#666", layer=s.top)

def cap_flat(s, a, b, label, big=False, lpos=None, anchor="start"):
    """足の間隔 5mm（穴 2つ分）。本体は 2つの穴の間に描く"""
    (x1, y1), (x2, y2) = H(*a), H(*b)
    cx, cy = (x1 + x2) / 2, (y1 + y2) / 2
    w = 30 if big else 22
    s.top.append(f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="#888" stroke-width="3"/>')
    s.top.append(f'<rect x="{cx-w/2}" y="{cy-w/2}" width="{w}" height="{w}" rx="7" fill="{"#f0c419" if big else "#3f6fb5"}" stroke="#555" stroke-width="1.5"/>')
    for x, y in ((x1, y1), (x2, y2)):
        s.top.append(f'<circle cx="{x}" cy="{y}" r="4" fill="#666"/>')
    if lpos:
        s.text(lpos[0], lpos[1], label, 13, anchor, "bold", "#333", layer=s.top)

def solder_mark(s, c, r):
    x, y = H(c, r)
    s.top.append(f'<circle cx="{x}" cy="{y}" r="10" fill="none" stroke="#d32f2f" stroke-width="2.5"/>')


# ------------------------------------------------------------------ タカチ基板版
P = 40                      # 穴の間隔 (px) = 2.54mm
ROWS = "ABCDEFGHIJKLMNOP"
OX, OY = 340, 230           # 基板の穴 (行A, 列1) の位置
CX, CY = 0, 0               # 回路 (ローカル座標) を基板のどこに置くか
BW = BH = 0                 # 基板の穴の数 (列, 行)
BOT = 0                     # 基板の下の端 (px)

def H(c, r):
    return (OX + (c + CX) * P, OY + (r + CY) * P)

def hn(c, r):
    return f"{ROWS[r+CY]}{c+CX+1}"

GEO = None

def board_tnf(s, model, wmm, hmm, nc, nr, cut=None):
    """タカチ TNF 基板。横が長い向きで描く。穴は 行の文字 (A〜) ＋ 列の数字 (1〜)。
    cut=(幅mm, 高さmm) なら左下を切り欠く"""
    global BW, BH, BOT, GEO
    BW, BH = nc, nr
    k = P / 2.54
    gw, gh = (nc - 1) * P, (nr - 1) * P
    bw, bh = wmm * k, hmm * k
    x0, y0 = OX + gw / 2 - bw / 2, OY + gh / 2 - bh / 2
    BOT = y0 + bh
    GEO = (x0, y0, bw, bh, k)
    def in_cut(x, y, pad=0):
        return cut and x < x0 + cut[0] * k + pad and y > y0 + bh - cut[1] * k - pad
    if cut:
        cx, cy = x0 + cut[0] * k, y0 + bh - cut[1] * k
        s.add(f'<rect x="{x0}" y="{cy}" width="{cx-x0}" height="{y0+bh-cy}" fill="none" stroke="#b71c1c" stroke-width="2" stroke-dasharray="8 5"/>')
        s.add(f'<path d="M{x0},{y0} L{x0+bw},{y0} L{x0+bw},{y0+bh} L{cx},{y0+bh} L{cx},{cy} L{x0},{cy} Z" fill="#e8d3a8" stroke="#8d6e3f" stroke-width="2.5" stroke-linejoin="round"/>')
        s.text((x0 + cx) / 2, y0 + bh + 26, f"ここを切り取る（{cut[0]}×{cut[1]}mm）", 14, "middle", "bold", "#b71c1c")
    else:
        s.rect(x0, y0, bw, bh, "#e8d3a8", "#8d6e3f", 8, 2.5)
    for c in range(nc):
        for r in range(nr):
            x, y = OX + c * P, OY + r * P
            if in_cut(x, y, 1.0 * k):
                continue
            s.add(f'<circle cx="{x}" cy="{y}" r="7" fill="#d9a441" stroke="#a87b24" stroke-width="1"/>')
            s.add(f'<circle cx="{x}" cy="{y}" r="2.6" fill="#5d4520"/>')
    for c in range(nc):
        if c % 2 == 0 or c == nc - 1:
            s.text(OX + c * P, y0 - 10, str(c + 1), 12, "middle", fill="#8d6e3f", weight="bold")
    for r in range(nr):
        s.text(x0 - 10, OY + r * P + 5, ROWS[r], 13, "end", fill="#8d6e3f", weight="bold")
    # 四すみの取付穴 (位置は目安)。すぐ内側の穴は使わない
    m = 3.5 * k
    for hx, hy in ((x0 + m, y0 + m), (x0 + bw - m, y0 + m), (x0 + m, y0 + bh - m), (x0 + bw - m, y0 + bh - m)):
        if in_cut(hx, hy):
            continue
        s.add(f'<circle cx="{hx}" cy="{hy}" r="{1.6*k}" fill="#fffdf6" stroke="#8d6e3f" stroke-width="2" stroke-dasharray="4 3"/>')
    for c, r in ((0, 0), (nc - 1, 0), (0, nr - 1), (nc - 1, nr - 1)):
        x, y = OX + c * P, OY + r * P
        if in_cut(x, y, 1.0 * k):
            continue
        s.add(f'<path d="M{x-7},{y-7} L{x+7},{y+7} M{x-7},{y+7} L{x+7},{y-7}" stroke="#9e9e9e" stroke-width="2.5"/>')
    s.text(x0 + bw / 2, y0 - 34, f"タカチ {model}（{wmm}×{hmm}mm、穴 {nc}×{nr}、2.54mm ピッチ）", 16, "middle", "bold", "#5d4520")
    return x0, y0, bw, bh

def mmxy(xm, ym):
    x0, y0, bw, bh, k = GEO
    return x0 + xm * k, y0 + ym * k

def aa_holder_on(s, xm, wm=57.1, hm=32):
    """単3×2本ボックス (秋月 117271、57.1×32mm) を基板の上に載せる。左端からリード線"""
    x0, y0, bw, bh, k = GEO
    x, y = mmxy(xm, (bh / k - hm) / 2)
    w, h = wm * k, hm * k
    s.top.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="10" fill="#455a64" fill-opacity="0.9" stroke="#263238" stroke-width="3"/>')
    for i in range(2):
        cy = y + h * (0.27 + 0.46 * i)
        s.top.append(f'<rect x="{x+18}" y="{cy-h*0.19}" width="{w-36}" height="{h*0.38}" rx="20" fill="#c0ca33" stroke="#827717" stroke-width="2"/>')
        s.text(x + w / 2, cy + 5, "単3リチウム", 14, "middle", "bold", "#333", layer=s.top)
    s.text(x + w / 2, y + h / 2 + 5, "電池ボックス 単3×2本（秋月 117271、57.1×32mm）両面テープで貼る", 14, "middle", "bold", "#fff", layer=s.top)
    return x, y, w, h

def cr123a_in_cut(s, xm, ym):
    """CR123A ケース (秋月 110192、43×18mm、ピン付き) を切り欠きに置く"""
    x0, y0, bw, bh, k = GEO
    x, y = mmxy(xm, ym)
    w, h = 43 * k, 18 * k
    s.top.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="8" fill="#37474f" stroke="#222" stroke-width="3"/>')
    s.top.append(f'<rect x="{x+22}" y="{y+16}" width="{w-44}" height="{h-32}" rx="30" fill="#b0bec5" stroke="#607d8b" stroke-width="2"/>')
    s.text(x + w / 2, y + h / 2 + 6, "CR123A（3V）", 15, "middle", "bold", "#263238", layer=s.top)
    s.text(x + 10, y + 24, "＋", 15, "start", "bold", "#fff", layer=s.top)
    s.text(x + w - 10, y + 26, "−", 18, "end", "bold", "#fff", layer=s.top)
    return (x + 6, y + h / 2), (x + w - 6, y + h / 2)

def flat_module(s, rows, names, color, cols, label, lab2, col=0):
    """モジュールをピンヘッダで寝かせて付ける (基板と平行)。ピンはローカル列 0、本体は左へ cols 穴分"""
    xa, ytop = H(col, rows[0]); _, ybot = H(col, rows[-1])
    w = cols * P
    s.top.append(f'<rect x="{xa-w}" y="{ytop-22}" width="{w+18}" height="{ybot-ytop+44}" rx="6" fill="{color}" fill-opacity="0.82" stroke="#333" stroke-width="2"/>')
    for r, n in zip(rows, names):
        x, y = H(col, r)
        s.top.append(f'<circle cx="{x}" cy="{y}" r="8" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
        s.text(x - 14, y + 5, n, 12, "end", "bold", "#fff", layer=s.top)
    s.text(xa - w / 2, ybot + 44, label, 13, "middle", "bold", "#222", layer=s.top)
    s.text(xa - w / 2, ybot + 60, lab2, 11, "middle", fill="#444", layer=s.top)
    return xa - w, ytop - 22, ybot + 22

def cable_left(s, holes, y_icon, name, sub, shape):
    """基板の左の外から 2本の線を穴に直接はんだ付け"""
    for i, h in enumerate(holes):
        x, y = H(*h)
        s.add(f'<path d="M{x},{y} L{OX-70},{y} L{OX-100},{y_icon-6+i*12} L150,{y_icon-6+i*12}" fill="none" stroke="#9e9e9e" stroke-width="4" stroke-linejoin="round"/>')
        solder_mark(s, *h)
    shape(150, y_icon)
    s.text(40, y_icon - 40, name, 15, weight="bold")
    s.text(40, y_icon - 22, sub, 12, fill="#666")

def battery_case_below(s, cp, cm, r, kind):
    """電池ケースを基板の下に置く (＋を左、−を右)。線を ＋の列・−の列の端に直接はんだ付け"""
    tp, tm = H(cp, r), H(cm, r)
    y = BOT + 70
    if kind == "CR123A":
        w = 260
        x = tp[0] - 190
        s.rect(x, y, w, 70, "#37474f", "#222", 10, 3)
        s.rect(x + 20, y + 14, 220, 42, "#b0bec5", "#607d8b", 20, 2)
        s.rect(x + 14, y + 26, 10, 18, "#90a4ae", "#607d8b", 2, 1)
        s.text(x + 130, y + 41, "CR123A（3V）", 15, "middle", "bold", "#263238")
        cap = "CR123A 用電池ケース ＋ CR123A 電池"
    else:
        w = 320
        x = tp[0] - 250
        s.rect(x, y, w, 70, "#455a64", "#263238", 10, 3)
        for i in range(2):
            bx = x + 18 + i * 146
            s.rect(bx, y + 14, 138, 42, "#c0ca33", "#827717", 20, 2)
            s.text(bx + 69, y + 41, "単3リチウム", 13, "middle", "bold", "#333")
        cap = "単3×2本 直列の電池ケース ＋ 単3リチウム電池 2本"
    s.text(x + 10, y + 22, "＋", 14, "start", "bold", "#fff")
    s.text(x + w - 10, y + 22, "−", 18, "end", "bold", "#fff")
    s.text(x + w / 2, y + 96, cap, 14, "middle", "bold")
    bp, bm = (x, y + 35), (x + w, y + 35)
    s.wire([bp, (x - 30, bp[1]), (x - 30, y - 30), (tp[0], y - 30), tp], "red")
    s.wire([bm, (x + w + 30, bm[1]), (x + w + 30, y - 50), (tm[0], y - 50), tm], "black")
    solder_mark(s, cp, r); solder_mark(s, cm, r)
    return y + 120

NOTES = [
    "・図は部品を載せる面から見たところ。部品の足は穴に差して裏ではんだ付けし、線（すずめっき線や被覆線）でつなぐ。",
    "・穴の位置は「行の文字＋列の数字」（例: C14 は C 行の 14 列目）。",
    "・XIAO にピンヘッダ（オス）をはんだ付けし、基板にはピンソケット（メス、7ピン×2）をはんだ付けして差し込む。XIAO を抜いて交換できる。",
    "・赤丸の穴は、基板の外からの線（電池ケース・センサーのケーブル）を直接はんだ付けするところ。",
    "・USB で書き込むときは、必ず電池を外す（3V3 に電池を直結しているため）。",
    "・四すみの点線の丸は取付穴（位置は目安。実物で確認）。すぐ内側の × の穴は、ネジやスペーサーに当たらないよう使わない。",
]

def rail_rows(cp, cm, p):
    return [
        ["red", f"XIAO 3V3（{hn(*p['3V3'])}）", f"{hn(cp,p['3V3'][1])} → {hn(cp,10)}（＋の列）", "電池＋・コンデンサーもこの列"],
        ["black", f"XIAO GND（{hn(*p['GND'])}）", f"{hn(cm,p['GND'][1])}（−の列 {hn(cm,0)}〜{hn(cm,10)}）", "電池−・コンデンサーもこの列"],
    ]

# ------------------------------------------------------------------ HPX-W
def make_W(fname, model, wmm, hmm, nc, nr, cx, cy, batt, hxcols, onboard=None):
    global CX, CY
    CX, CY = 0, 0
    width = max(1280, OX + (nc - 1) * P + 260)
    s = S(width, 1760)
    title(s, f"HPX-W（重量センサ）{batt} 版の配線図", f"基板 タカチ {model} ／ HX711 DT=D0, SCK=D1 ／ 電源 {batt}")
    board_tnf(s, model, wmm, hmm, nc, nr)
    CX, CY = cx, cy
    p = xiao_pcb(s, 2, 2)
    wireh(s, [(0, 1), (0, 0), (11, 0), (11, 10)], "black")
    wireh(s, [p["GND"], (11, 3)], "black")
    wireh(s, [p["3V3"], (9, 4), (9, 10)], "red")
    wireh(s, [(0, 4), (0, 9), (9, 9)], "red")
    wireh(s, [p["D0"], (0, 2)], "yellow")
    wireh(s, [p["D1"], (0, 3)], "blue")
    cap_flat(s, (9, 6), (11, 6), "100µF", True, (H(10, 6)[0], H(10, 6)[1] - 22), "middle")
    cap_flat(s, (9, 8), (11, 8), "0.1µF", False, (H(10, 8)[0], H(10, 8)[1] - 18), "middle")
    lx, ty, by = flat_module(s, [1, 2, 3, 4], ["GND", "DT", "SCK", "VCC"], "#2e7d32", hxcols,
                             "HX711 モジュール", "ピンヘッダで寝かせて付ける（基板と平行）")
    s.top.append(f'<rect x="{lx+90}" y="{(ty+by)/2-24}" width="48" height="48" fill="#212121"/>')
    s.text(lx + 114, (ty + by) / 2 + 5, "HX711", 11, "middle", "bold", "#eee", layer=s.top)
    for i, n in enumerate(["E+", "E-", "A-", "A+"]):
        y = ty + 26 + i * 30
        s.top.append(f'<circle cx="{lx+18}" cy="{y}" r="6" fill="#d4af37" stroke="#8a6d1d" stroke-width="1.5"/>')
        s.text(lx + 30, y + 4, n, 11, "start", "bold", "#fff", layer=s.top)
    s.rect(20, ty - 10, 170, 130, "#b0bec5", "#78909c", 6, 3)
    s.text(105, ty + 50, "ロードセル", 15, "middle", "bold", "#263238")
    s.text(105, ty + 72, "SC601（120kg）", 12, "middle", fill="#263238")
    s.text(105, ty + 144, "線の色は説明書で確認", 12, "middle", fill="#b23", weight="bold")
    for i, c in enumerate(["red", "black", "white", "green"]):
        y = ty + 26 + i * 30
        if c == "white":
            s.top.append(f'<path d="M{lx+18},{y} L190,{y}" fill="none" stroke="#777" stroke-width="7"/><path d="M{lx+18},{y} L190,{y}" fill="none" stroke="#fafafa" stroke-width="4.5"/>')
        else:
            s.top.append(f'<path d="M{lx+18},{y} L190,{y}" fill="none" stroke="{COL[c]}" stroke-width="5"/>')
    if onboard is None:
        yb = battery_case_below(s, 9, 11, 10, batt)
    else:
        hx, hy, hw, hh = aa_holder_on(s, onboard)
        tp, tm = H(9, 10), H(11, 10)
        s.wire([(hx + 10, tm[1]), tm], "black")
        s.wire([(hx + 10, tm[1] + 24), (tp[0], tm[1] + 24), tp], "red")
        solder_mark(s, 9, 10); solder_mark(s, 11, 10)
        yb = BOT + 30
    bname = "CR123A 用電池ケース ／ CR123A 電池" if batt == "CR123A" else "電池ボックス 単3×2本 リード線（秋月 117271）／ 単3リチウム電池 2本"
    y = table(s, 60, yb + 40, [
        ["線", "どこから（穴）", "どこへ（穴）", "役目"],
        ["yellow", f"XIAO D0（{hn(2,2)}）", f"HX711 DT（{hn(0,2)}）", "重さのデータ"],
        ["blue", f"XIAO D1（{hn(2,3)}）", f"HX711 SCK（{hn(0,3)}）", "読み取りの合図"],
        ["black", f"HX711 GND（{hn(0,1)}）", f"{hn(0,0)} → {hn(11,0)} → {hn(11,10)}（−の列）", "上を回って −の列へ"],
        ["red", f"HX711 VCC（{hn(0,4)}）", f"{hn(0,9)} → {hn(9,9)}（＋の列）", "下を回って ＋の列へ"],
    ] + rail_rows(9, 11, p), [100, 280, 420, 360], "つなぐ線（穴の位置は 行の文字＋列の数字）")
    y = table(s, 60, y + 50, [
        ["部品", "数", "置き場所・メモ"],
        ["Seeed XIAO BLE nRF52840 ＋ ピンヘッダ 7ピン×2（XIAO 側）＋ ピンソケット 7ピン×2（基板側）", "1", f"{hn(2,2)}〜{hn(8,8)}"],
        ["HX711 モジュール ＋ ピンヘッダ 4ピン", "1", f"{hn(0,1)}〜{hn(0,4)}（GND・DT・SCK・VCC）。本体は左へ寝かせる"],
        ["ロードセル SC601 120kg", "1", "HX711 の E+・E-・A-・A+ に直接はんだ付け"],
        ["絶縁ラジアルリード型積層セラミックコンデンサー 100µF 6.3V 5mm", "1", f"{hn(9,6)}・{hn(11,6)}（向きなし）"],
        ["積層セラミックコンデンサー 0.1µF 50V C0G 5mm", "1", f"{hn(9,8)}・{hn(11,8)}"],
        [bname, "各1", f"赤線を {hn(9,10)}（＋）、黒線を {hn(11,10)}（−）にはんだ付け"],
        [f"タカチ ユニバーサル基板 {model}・すずめっき線・被覆線", "1", ""],
    ], [560, 60, 540], "部品表")
    extra = ["・HX711 は、ピンヘッダの上に基板と平行に寝かせて付ける。モジュールの大きさは製品によって違うので、取付穴やロードセルの線に当たらないか確認する。"]
    if batt != "CR123A":
        extra.append(f"・電池ボックスは基板の右側（{hn(12,0)} より右、右はしから約 6mm 手前まで）に両面テープで貼る。右の取付穴 2つはネジが通るよう空けておく。")
        extra.append("・電池ボックスのリード線（赤＝＋、黒＝−）は、長さを合わせて切り、赤丸の穴にはんだ付けする。")
    L = NOTES + extra
    notes(s, 60, y + 20, L)
    s.h = int(y + 20 + 34 + 26 * len(L) + 40)
    s.save(fname)

# ------------------------------------------------------------------ HPX-TH
def make_TH(fname, model, wmm, hmm, nc, nr, cx, cy, batt, shtcols):
    global CX, CY
    CX, CY = 0, 0
    width = max(1280, OX + (nc - 1) * P + 260)
    s = S(width, 1960)
    title(s, f"HPX-TH（温湿度センサ）{batt} 版の配線図", f"基板 タカチ {model} ／ SHT40: SDA=D4, SCL=D5 ／ サーミスター: D3→10kΩ→A2 ／ 電源 {batt}")
    board_tnf(s, model, wmm, hmm, nc, nr)
    CX, CY = cx, cy
    p = xiao_pcb(s, 3, 2)
    wireh(s, [(0, 4), (0, 0), (12, 0), (12, 10)], "black")
    wireh(s, [(0, 5), (1, 5), (1, 1), (10, 1), (10, 10)], "red")
    wireh(s, [p["3V3"], (10, 4)], "red")
    wireh(s, [p["GND"], (12, 3)], "black")
    x, y = H(10, 3)
    s.top.append(f'<circle cx="{x}" cy="{y}" r="13" fill="none" stroke="#7b1fa2" stroke-width="2" stroke-dasharray="4 3"/>')
    s.text(x + 4, y - 18, "またぐ", 11, "middle", "bold", "#7b1fa2", layer=s.top)
    wireh(s, [p["D4"], (0, 6)], "green")
    wireh(s, [p["D5"], (0, 7)], "blue")
    wireh(s, [p["D2"], (2, 4)], "yellow")
    wireh(s, [p["D3"], (2, 5)], "purple")
    wireh(s, [(2, 4), (2, 2)], "yellow")
    res_stand(s, (2, 4), (2, 5), "10kΩ 1%", ["#8d5524", "#212121", "#212121"], H(2, 9)[0] + 10, H(2, 9)[1] + 6, "middle")
    s.top.append(f'<line x1="{H(2,5)[0]}" y1="{H(2,5)[1]+12}" x2="{H(2,9)[0]+10}" y2="{H(2,9)[1]-12}" stroke="#999" stroke-width="1.5" stroke-dasharray="3 3"/>')
    cap_flat(s, (0, 2), (2, 2), "0.1µF")
    s.text(H(1, 2)[0], H(1, 2)[1] + 30, "0.1µF", 12, "middle", "bold", "#1a237e", layer=s.top)
    cap_flat(s, (10, 6), (12, 6), "100µF", True, (H(12, 6)[0] + 22, H(12, 6)[1] - 16))
    def thermo(x, y):
        s.add(f'<ellipse cx="{x-60}" cy="{y}" rx="18" ry="13" fill="#5d4037" stroke="#3e2723" stroke-width="2"/>')
    cable_left(s, [(0, 3), (2, 3)], H(0, 3)[1] - 70, "サーミスター 10kΩ", "（向きなし・巣箱の中へ）", thermo)
    lx, ty, by = flat_module(s, [4, 5, 6, 7], ["GND", "VIN", "SDA", "SCL"], "#5e35b1", shtcols,
                             "SHT40 モジュール", "寝かせて付ける。ピンの並びは印字で確認")
    s.top.append(f'<rect x="{lx+30}" y="{(ty+by)/2-16}" width="32" height="32" rx="2" fill="#cfd8dc" stroke="#78909c"/>')
    yb = battery_case_below(s, 10, 12, 10, batt)
    bname = "CR123A 用電池ケース ／ CR123A 電池" if batt == "CR123A" else "単3×2本 直列の電池ケース ／ 単3リチウム電池"
    y = table(s, 60, yb + 40, [
        ["線", "どこから（穴）", "どこへ（穴）", "役目"],
        ["purple", f"XIAO D3（{hn(3,5)}）", f"10kΩ の下の足（{hn(2,5)}）", "測る瞬間だけ電気を流す"],
        ["yellow", f"XIAO D2/A2（{hn(3,4)}）", f"10kΩ の上の足（{hn(2,4)}）→ {hn(2,2)}", f"測る点（{hn(2,2)}〜{hn(2,4)}）"],
        ["green", f"XIAO D4（{hn(3,6)}）", f"SHT40 SDA（{hn(0,6)}）", "データ（I2C）"],
        ["blue", f"XIAO D5（{hn(3,7)}）", f"SHT40 SCL（{hn(0,7)}）", "合図（I2C）"],
        ["black", f"SHT40 GND（{hn(0,4)}）", f"{hn(0,0)} → {hn(12,0)} → {hn(12,10)}（−の列）", f"{hn(0,0)}〜{hn(0,4)} も GND"],
        ["red", f"SHT40 VIN（{hn(0,5)}）", f"{hn(1,5)} → {hn(1,1)} → {hn(10,1)} → {hn(10,10)}（＋の列）", "SHT40 の電源"],
    ] + rail_rows(10, 12, p), [100, 280, 470, 310], "つなぐ線（穴の位置は 行の文字＋列の数字）")
    y = table(s, 60, y + 50, [
        ["部品", "数", "置き場所・メモ"],
        ["Seeed XIAO BLE nRF52840 ＋ ピンヘッダ 7ピン×2（XIAO 側）＋ ピンソケット 7ピン×2（基板側）", "1", f"{hn(3,2)}〜{hn(9,8)}"],
        ["SHT40 モジュール（温湿度、I2C）＋ ピンヘッダ 4ピン", "1", f"{hn(0,4)}〜{hn(0,7)}（GND・VIN・SDA・SCL）。本体は左へ寝かせる"],
        ["サーミスター 10kΩ（平行線タイプ）", "1", f"線を {hn(0,3)}（GND）・{hn(2,3)}（測る点）に直接はんだ付け"],
        ["抵抗 10kΩ 1%（金属皮膜）", "1", f"{hn(2,4)}・{hn(2,5)}（立てて付ける）"],
        ["積層セラミックコンデンサー 0.1µF 50V C0G 5mm", "1", f"{hn(0,2)}・{hn(2,2)}（測る点と GND の間）"],
        ["絶縁ラジアルリード型積層セラミックコンデンサー 100µF 6.3V 5mm", "1", f"{hn(10,6)}・{hn(12,6)}"],
        [bname, "各1", f"赤線を {hn(10,10)}（＋）、黒線を {hn(12,10)}（−）にはんだ付け"],
        [f"タカチ ユニバーサル基板 {model}・すずめっき線・被覆線", "1", ""],
    ], [560, 60, 540], "部品表")
    L = NOTES + [
        f"・{hn(10,3)} の紫の点線の丸: 黒線（XIAO GND → −の列）が赤い ＋の列をまたぐ。被覆線を使い、{hn(10,3)} の穴には通さない。",
        f"・0.1µF は {hn(1,2)} の赤線をまたいで {hn(0,2)}・{hn(2,2)} に付ける。サーミスターの線も {hn(1,3)} をまたぐので、被覆のある線を使う。",
        "・ファームは HPX_TH v1.00。SHT40 は 3.6V まで（5V にはつながない）。SHT40 の上は、ケースの中でも空気が通るようにする。",
    ]
    notes(s, 60, y + 20, L)
    s.h = int(y + 20 + 34 + 26 * len(L) + 40)
    s.save(fname)


# ------------------------------------------------------------------ HPX-W CR123A 切り欠き版
def make_W_cut(fname):
    global CX, CY
    CX, CY = 0, 0
    s = S(1400, 1900)
    title(s, "HPX-W（重量センサ）CR123A 版の配線図", "基板 タカチ TNF39-70（左下を切り欠いて電池ケースを置く）／ HX711 DT=D0, SCK=D1")
    board_tnf(s, "TNF39-70", 70, 39.5, 25, 14, cut=(44, 22))
    p = xiao_pcb(s, 17, 1)
    wireh(s, [(15, 0), (24, 0), (24, 8), (23, 8), (23, 13)], "black")
    wireh(s, [p["GND"], (24, 2)], "black")
    wireh(s, [p["3V3"], (22, 3), (22, 8), (21, 8), (21, 13)], "red")
    x1, y1 = H(22, 3); _, y2 = H(22, 8)
    s.top.append(f'<path d="M{x1},{y1} L{x1},{y2}" stroke="#e53935" stroke-width="4" stroke-dasharray="7 5"/>')
    s.text(x1 - 8, (y1 + y2) / 2 + 30, "XIAO の下", 11, "end", "bold", "#ffcdd2", layer=s.top)
    s.text(x1 - 8, (y1 + y2) / 2 + 45, "を通す", 11, "end", "bold", "#ffcdd2", layer=s.top)
    wireh(s, [(15, 3), (16, 3), (16, 9), (21, 9)], "red")
    wireh(s, [p["D0"], (15, 1)], "yellow")
    wireh(s, [p["D1"], (15, 2)], "blue")
    cap_flat(s, (21, 10), (23, 10), "100µF", True, (H(24, 10)[0] + 30, H(24, 10)[1] + 5))
    cap_flat(s, (21, 12), (23, 12), "0.1µF", False, (H(24, 12)[0] + 30, H(24, 12)[1] + 5))
    lx, ty, by = flat_module(s, [0, 1, 2, 3], ["GND", "DT", "SCK", "VCC"], "#2e7d32", 11,
                             "HX711 モジュール", "ピンヘッダで寝かせて付ける（基板と平行）", col=15)
    s.top.append(f'<rect x="{lx+120}" y="{(ty+by)/2-24}" width="48" height="48" fill="#212121"/>')
    s.text(lx + 144, (ty + by) / 2 + 5, "HX711", 11, "middle", "bold", "#eee", layer=s.top)
    for i, n in enumerate(["E+", "E-", "A-", "A+"]):
        y = ty + 26 + i * 30
        s.top.append(f'<circle cx="{lx+18}" cy="{y}" r="6" fill="#d4af37" stroke="#8a6d1d" stroke-width="1.5"/>')
        s.text(lx + 30, y + 4, n, 11, "start", "bold", "#fff", layer=s.top)
    s.rect(20, ty - 10, 170, 130, "#b0bec5", "#78909c", 6, 3)
    s.text(105, ty + 50, "ロードセル", 15, "middle", "bold", "#263238")
    s.text(105, ty + 72, "SC601（120kg）", 12, "middle", fill="#263238")
    s.text(105, ty + 144, "線の色は説明書で確認", 12, "middle", fill="#b23", weight="bold")
    for i, c in enumerate(["red", "black", "white", "green"]):
        y = ty + 26 + i * 30
        if c == "white":
            s.top.append(f'<path d="M{lx+18},{y} L190,{y}" fill="none" stroke="#777" stroke-width="7"/><path d="M{lx+18},{y} L190,{y}" fill="none" stroke="#fafafa" stroke-width="4.5"/>')
        else:
            s.top.append(f'<path d="M{lx+18},{y} L190,{y}" fill="none" stroke="{COL[c]}" stroke-width="5"/>')
    # 電池ケースを切り欠きに置く (− を左、＋を右に向ける)
    bp_left, bp_right = cr123a_in_cut(s, 0.5, 19.5)
    x0, y0, bw, bh, k = GEO
    s.top[-2] = s.top[-2].replace(">＋<", ">−<").replace('font-size="15"', 'font-size="18"')
    s.top[-1] = s.top[-1].replace(">−<", ">＋<").replace('font-size="18"', 'font-size="15"')
    mneg, mpos = bp_left, bp_right
    tp, tm = H(21, 13), H(23, 13)
    s.wire([mpos, (mpos[0] + 30, mpos[1]), (mpos[0] + 30, BOT + 40), (tp[0], BOT + 40), tp], "red")
    s.wire([mneg, (mneg[0] - 30, mneg[1]), (mneg[0] - 30, BOT + 70), (tm[0], BOT + 70), tm], "black")
    solder_mark(s, 21, 13); solder_mark(s, 23, 13)
    s.text(mmxy(21.5, 0)[0], BOT + 110, "CR123A 用電池ケース（秋月 110192、43×18×14mm、ピン付き）を切り欠きに置く", 14, "middle", "bold", "#263238")
    y = table(s, 60, BOT + 170, [
        ["線", "どこから（穴）", "どこへ（穴）", "役目"],
        ["yellow", f"XIAO D0（{hn(17,1)}）", f"HX711 DT（{hn(15,1)}）", "重さのデータ"],
        ["blue", f"XIAO D1（{hn(17,2)}）", f"HX711 SCK（{hn(15,2)}）", "読み取りの合図"],
        ["black", f"HX711 GND（{hn(15,0)}）", f"{hn(24,0)} → {hn(24,8)} → {hn(23,8)} → {hn(23,13)}", f"−の線。XIAO GND（{hn(23,2)}）も {hn(24,2)} でつなぐ"],
        ["red", f"XIAO 3V3（{hn(23,3)}）", f"{hn(22,3)} → {hn(22,8)}（XIAO の下）→ {hn(21,8)} → {hn(21,13)}", "＋の線"],
        ["red", f"HX711 VCC（{hn(15,3)}）", f"{hn(16,3)} → {hn(16,9)} → {hn(21,9)}（＋の線）", "HX711 の電源"],
        ["red", "電池ケースの ＋ ピン", f"{hn(21,13)}", "線ではんだ付け"],
        ["black", "電池ケースの − ピン", f"{hn(23,13)}", "線ではんだ付け"],
    ], [100, 260, 470, 450], "つなぐ線（穴の位置は 行の文字＋列の数字）")
    y = table(s, 60, y + 50, [
        ["部品", "数", "置き場所・メモ"],
        ["Seeed XIAO BLE nRF52840 ＋ ピンヘッダ 7ピン×2（XIAO 側）＋ ピンソケット 7ピン×2（基板側）", "1", f"{hn(17,1)}〜{hn(23,7)}"],
        ["HX711 モジュール ＋ ピンヘッダ 4ピン", "1", f"{hn(15,0)}〜{hn(15,3)}（GND・DT・SCK・VCC）。本体は左へ寝かせる"],
        ["ロードセル SC601 120kg", "1", "HX711 の E+・E-・A-・A+ に直接はんだ付け"],
        ["絶縁ラジアルリード型積層セラミックコンデンサー 100µF 6.3V 5mm", "1", f"{hn(21,10)}・{hn(23,10)}（向きなし）"],
        ["積層セラミックコンデンサー 0.1µF 50V C0G 5mm", "1", f"{hn(21,12)}・{hn(23,12)}"],
        ["CR123A 用電池ケース（秋月 110192）／ CR123A 電池", "各1", "切り欠きに置き、ピンと基板を線でつなぐ"],
        ["タカチ ユニバーサル基板 TNF39-70・すずめっき線・被覆線", "1", "左下 44×22mm を切り取る"],
    ], [560, 60, 540], "部品表")
    L = NOTES + [
        "・左下の 44×22mm を、のこぎりやニッパーで切り取る（切り口はやすりで平らに）。電池ケース（43×18mm）が入る大きさ。左下の取付穴はなくなるので、残りの 3つで留める。",
        f"・＋の線は {hn(22,3)}〜{hn(22,8)} で XIAO の下を通す（ピンソケットの高さの分、すき間がある）。XIAO を差す前に配線する。",
        "・電池ケースのピンには、ケースを置いてから長さを合わせた線をはんだ付けする。ケースは両面テープなどでケースの底に固定。",
        "・HX711 の大きさは製品によって違う。幅が広いと切り欠きの上に張り出すので、電池ケース（高さ 14mm）に当たらないか確認する。",
    ]
    notes(s, 60, y + 20, L)
    s.h = int(y + 20 + 34 + 26 * len(L) + 40)
    s.save(fname)

make_W_cut("HPX-W_CR123A_TNF39-70.svg")
#make_W("HPX-W_CR123A_TNF39-70.svg", "TNF39-70", 70, 39.5, 25, 14, 11, 2, "CR123A", 10)
make_W("HPX-W_AA_TNF34-124.svg", "TNF34-124", 124, 34, 47, 12, 11, 1, "単3リチウム×2", 10, onboard=60.5)

# ------------------------------------------------------------------ HPX-TH CR123A 基板の上に電池ケース版
def wire_back(s, pts, color):
    """基板の裏側を通す線 (点線)"""
    d = "M" + " L".join(f"{H(*p)[0]},{H(*p)[1]}" for p in pts)
    c = COL[color]
    s.add(f'<path d="{d}" fill="none" stroke="{c}" stroke-width="4" stroke-dasharray="9 6" stroke-linecap="round" stroke-linejoin="round" opacity="0.9"/>')
    for p in (pts[0], pts[-1]):
        x, y = H(*p)
        s.top.append(f'<circle cx="{x}" cy="{y}" r="5" fill="#fff" stroke="{c}" stroke-width="2.5"/>')

def make_TH_on(fname):
    global CX, CY
    CX, CY = 0, 0
    s = S(1400, 2100)
    title(s, "HPX-TH（温湿度センサ）CR123A 版の配線図", "基板 タカチ TNF39-59（電池ケースを基板の上に載せる）／ SHT40: SDA=D4, SCL=D5 ／ サーミスター: D3→10kΩ→A2")
    board_tnf(s, "TNF39-59", 59, 39, 21, 14)
    p = xiao_pcb(s, 4, 0)
    # 表の線
    wireh(s, [p["D4"], (0, 4)], "green")
    wireh(s, [p["D5"], (0, 5)], "blue")
    wireh(s, [p["D2"], (3, 2)], "yellow")
    wireh(s, [p["D3"], (3, 3)], "purple")
    wireh(s, [(3, 2), (3, 0)], "yellow")
    wireh(s, [(0, 2), (1, 2), (1, 0)], "black")
    wireh(s, [(0, 3), (1, 3), (2, 3)], "red")
    wireh(s, [p["GND"], (19, 1), (19, 10)], "black")
    wireh(s, [p["3V3"], (17, 2), (17, 6)], "red")
    # 裏の線
    wire_back(s, [(1, 2), (1, 9), (19, 9)], "black")
    wire_back(s, [(2, 3), (2, 8), (17, 8), (17, 6)], "red")
    res_stand(s, (3, 2), (3, 3), "10kΩ 1%", ["#8d5524", "#212121", "#212121"], H(3, 0)[0] + 60, H(3, 0)[1] - 34, "middle")
    cap_flat(s, (1, 0), (3, 0), "0.1µF")
    s.text(H(2, 0)[0], H(2, 0)[1] - 18, "0.1µF", 12, "middle", "bold", "#1a237e", layer=s.top)
    cap_flat(s, (17, 4), (19, 4), "100µF", True, (H(18, 4)[0], H(18, 4)[1] - 22), "middle")
    # サーミスター (上の端から)
    for i, h in enumerate([(1, 1), (3, 1)]):
        x, y = H(*h)
        x0, y0, bw, bh, k = GEO
        s.add(f'<path d="M{x},{y} L{x},{y0-50-i*12} L180,{y0-50-i*12}" fill="none" stroke="#9e9e9e" stroke-width="4" stroke-linejoin="round"/>')
        solder_mark(s, *h)
    x0, y0, bw, bh, k = GEO
    s.add(f'<ellipse cx="150" cy="{y0-56}" rx="18" ry="13" fill="#5d4037" stroke="#3e2723" stroke-width="2"/>')
    s.text(40, y0 - 90, "サーミスター 10kΩ", 15, weight="bold")
    s.text(40, y0 - 72, "（向きなし・巣箱の中へ）", 12, fill="#666")
    # SHT40 を A 列に立てる (図では左に倒す)
    xa, ytop = H(0, 2); _, ybot = H(0, 5)
    w = 150
    s.top.append(f'<rect x="{xa-w}" y="{ytop-22}" width="{w+16}" height="{ybot-ytop+44}" rx="6" fill="#5e35b1" fill-opacity="0.88" stroke="#333" stroke-width="2"/>')
    s.top.append(f'<rect x="{xa-w+24}" y="{(ytop+ybot)/2-16}" width="32" height="32" rx="2" fill="#cfd8dc" stroke="#78909c"/>')
    for r, n in zip([2, 3, 4, 5], ["GND", "VIN", "SDA", "SCL"]):
        x, y = H(0, r)
        s.top.append(f'<circle cx="{x}" cy="{y}" r="8" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
        s.text(x - 14, y + 5, n, 12, "end", "bold", "#fff", layer=s.top)
    s.text(xa - w / 2, ybot + 44, "SHT40 モジュール", 14, "middle", "bold", "#311b92", layer=s.top)
    s.text(xa - w / 2, ybot + 60, "A 列に立てて付ける", 11, "middle", fill="#444", layer=s.top)
    s.text(xa - w / 2, ybot + 75, "（図では倒して描いた）", 11, "middle", fill="#444", layer=s.top)
    # 電池ケースを基板の上に (＋を左、−を右)
    bx, by = mmxy(8, 21)
    bw2, bh2 = 43 * k, 18 * k
    s.top.append(f'<rect x="{bx}" y="{by}" width="{bw2}" height="{bh2}" rx="8" fill="#37474f" fill-opacity="0.82" stroke="#222" stroke-width="3"/>')
    s.top.append(f'<rect x="{bx+22}" y="{by+16}" width="{bw2-44}" height="{bh2-32}" rx="30" fill="#b0bec5" fill-opacity="0.85" stroke="#607d8b" stroke-width="2"/>')
    s.text(bx + bw2 / 2, by + bh2 / 2 - 4, "CR123A 用電池ケース（秋月 110192、43×18×14mm）", 14, "middle", "bold", "#263238", layer=s.top)
    s.text(bx + bw2 / 2, by + bh2 / 2 + 16, "両面テープで基板に貼る。下を裏の点線が通る", 12, "middle", "bold", "#263238", layer=s.top)
    s.text(bx + 10, by + 24, "＋", 15, "start", "bold", "#fff", layer=s.top)
    s.text(bx + bw2 - 10, by + 26, "−", 18, "end", "bold", "#fff", layer=s.top)
    tp, tm = H(2, 6), H(19, 10)
    s.top.append(f'<path d="M{bx+8},{by+bh2/2} L{bx-14},{by+bh2/2} L{bx-14},{tp[1]+16} L{tp[0]},{tp[1]+16} L{tp[0]},{tp[1]}" fill="none" stroke="#e53935" stroke-width="5" stroke-linejoin="round"/>')
    s.top.append(f'<path d="M{bx+bw2-8},{by+bh2/2} L{tm[0]},{by+bh2/2} L{tm[0]},{tm[1]}" fill="none" stroke="#212121" stroke-width="5" stroke-linejoin="round"/>')
    solder_mark(s, 2, 6); solder_mark(s, 19, 10)
    # 凡例
    ly = BOT + 40
    s.add(f'<path d="M80,{ly} L160,{ly}" stroke="#e53935" stroke-width="5"/>')
    s.text(170, ly + 5, "実線＝表（部品の面）の線", 14, weight="bold")
    s.add(f'<path d="M480,{ly} L560,{ly}" stroke="#e53935" stroke-width="4" stroke-dasharray="9 6"/>')
    s.text(570, ly + 5, "点線＝裏（はんだ面）の線。電池ケースの下を通す", 14, weight="bold")
    y = table(s, 60, BOT + 110, [
        ["線", "どこから（穴）", "どこへ（穴）", "役目"],
        ["green", f"XIAO D4（{hn(4,4)}）", f"SHT40 SDA（{hn(0,4)}）", "データ（I2C）"],
        ["blue", f"XIAO D5（{hn(4,5)}）", f"SHT40 SCL（{hn(0,5)}）", "合図（I2C）"],
        ["purple", f"XIAO D3（{hn(4,3)}）", f"10kΩ の下の足（{hn(3,3)}）", "測る瞬間だけ電気を流す"],
        ["yellow", f"XIAO D2/A2（{hn(4,2)}）", f"10kΩ の上の足（{hn(3,2)}）→ {hn(3,0)}", f"測る点（{hn(3,0)}〜{hn(3,2)}）"],
        ["black", f"SHT40 GND（{hn(0,2)}）", f"{hn(1,2)} → {hn(1,0)}", f"GND（{hn(1,0)}〜{hn(1,2)}）"],
        ["black", f"{hn(1,2)}（裏）", f"{hn(1,9)} → {hn(19,9)}（裏）", "GND を右へ。電池ケースの下"],
        ["black", f"XIAO GND（{hn(10,1)}）", f"{hn(19,1)} → {hn(19,10)}", "−の列"],
        ["red", f"SHT40 VIN（{hn(0,3)}）", f"{hn(1,3)} → {hn(2,3)}", f"{hn(1,3)} は裏の黒線の上をまたぐ"],
        ["red", f"{hn(2,3)}（裏）", f"{hn(2,8)} → {hn(17,8)} → {hn(17,6)}（裏）", "＋を右へ。電池ケースの下"],
        ["red", f"XIAO 3V3（{hn(10,2)}）", f"{hn(17,2)} → {hn(17,6)}", "＋の列"],
        ["red", "電池ケースの ＋", f"{hn(2,6)}", "線ではんだ付け"],
        ["black", "電池ケースの −", f"{hn(19,10)}", "線ではんだ付け"],
    ], [100, 270, 420, 470], "つなぐ線（穴の位置は 行の文字＋列の数字）")
    y = table(s, 60, y + 50, [
        ["部品", "数", "置き場所・メモ"],
        ["Seeed XIAO BLE nRF52840 ＋ ピンヘッダ 7ピン×2（XIAO 側）＋ ピンソケット 7ピン×2（基板側）", "1", f"{hn(4,0)}〜{hn(10,6)}"],
        ["SHT40 モジュール（温湿度、I2C）＋ ピンヘッダ 4ピン", "1", f"{hn(0,2)}〜{hn(0,5)}（GND・VIN・SDA・SCL）に立てて付ける"],
        ["サーミスター 10kΩ（平行線タイプ）", "1", f"線を {hn(1,1)}（GND）・{hn(3,1)}（測る点）に直接はんだ付け"],
        ["抵抗 10kΩ 1%（金属皮膜）", "1", f"{hn(3,2)}・{hn(3,3)}（立てて付ける）"],
        ["積層セラミックコンデンサー 0.1µF 50V C0G 5mm", "1", f"{hn(1,0)}・{hn(3,0)}（測る点と GND の間）"],
        ["絶縁ラジアルリード型積層セラミックコンデンサー 100µF 6.3V 5mm", "1", f"{hn(17,4)}・{hn(19,4)}"],
        ["CR123A 用電池ケース（秋月 110192）／ CR123A 電池", "各1", f"基板の下側（{hn(2,7)}〜{hn(18,13)} のあたり）に貼る"],
        ["タカチ ユニバーサル基板 TNF39-59・すずめっき線・被覆線", "1", "ケース タカチ SW-65B"],
    ], [560, 60, 540], "部品表")
    L = NOTES + [
        "・電池ケース（43×18mm）は、基板の下の辺にそろえ、左右の取付穴のあいだ（左はしから約 8mm〜51mm）に両面テープで貼る。",
        "・電池ケースのピンの位置は確認できていない。ピンが基板の穴に入るなら差してもよいが、裏の点線（I 行・J 行）に当たらない穴にする。入らなければ、ピンに線をはんだ付けしてつなぐ。",
        f"・裏の点線は、電池ケースを貼る前に配線する。{hn(1,3)} では、表の赤線が裏の黒線の上を通る（穴の中ではつながない）。",
        "・SHT40 は A 列に立てて付ける。印字の GND が上（C 行）、SCL が下（F 行）になる向き。",
        "・ファームは HPX_TH v1.00。SHT40 は 3.6V まで（5V にはつながない）。ケースの高さ（電池ケース 14mm、立てた SHT40）は実物で確認。",
    ]
    notes(s, 60, y + 20, L)
    s.h = int(y + 20 + 34 + 26 * len(L) + 40)
    s.save(fname)

make_TH_on("HPX-TH_CR123A_TNF39-59.svg")
#make_TH("HPX-TH_CR123A_TNF39-59.svg", "TNF39-59", 59, 39, 21, 14, 6, 2, "CR123A", 6)
print("ok")
