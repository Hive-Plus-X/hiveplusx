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
    draw_back_wire(s, [H(*p) for p in pts], color)

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
    rec_part(x1 - 14, y1 - 24, x2 - x1 + 28, y2 - y1 + 48, "XIAO")
    p = {}
    for i, n in enumerate(XL):
        x, y = H(c0, r0 + i); p[n] = (c0, r0 + i)
        rec_pin(x, y, n)
        s.top.append(f'<circle cx="{x}" cy="{y}" r="8" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
        s.text(x + 14, y + 5, n, 13, "start", "bold", "#fff", layer=s.top)
    for i, n in enumerate(XR):
        x, y = H(c0 + 6, r0 + i); p[n] = (c0 + 6, r0 + i)
        rec_pin(x, y, n)
        s.top.append(f'<circle cx="{x}" cy="{y}" r="8" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
        s.text(x - 14, y + 5, n, 13, "end", "bold", "#fff", layer=s.top)
    return p

def res_stand(s, a, b, label, bands, lx, ly, anchor="end"):
    """抵抗を立てて付ける。本体は a の穴の上、もう一方の足を b へ折り返す"""
    (x1, y1), (x2, y2) = H(*a), H(*b)
    rec_pin(x1, y1, "10kΩ"); rec_pin(x2, y2, "10kΩ")
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
    rec_pin(x1, y1, label); rec_pin(x2, y2, label)
    w = 30 if big else 22
    s.top.append(f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="#888" stroke-width="3"/>')
    s.top.append(f'<rect x="{cx-w/2}" y="{cy-w/2}" width="{w}" height="{w}" rx="7" fill="{"#f0c419" if big else "#3f6fb5"}" stroke="#555" stroke-width="1.5"/>')
    for x, y in ((x1, y1), (x2, y2)):
        s.top.append(f'<circle cx="{x}" cy="{y}" r="4" fill="#666"/>')
    if lpos:
        s.text(lpos[0], lpos[1], label, 13, anchor, "bold", "#333", layer=s.top)

def solder_mark(s, c, r):
    x, y = H(c, r)
    rec_pin(x, y, "", "cable")
    s.top.append(f'<circle cx="{x}" cy="{y}" r="10" fill="none" stroke="#d32f2f" stroke-width="2.5"/>')


# ------------------------------------------------------------------ 裏 (はんだ面) から見た図
# 部品は表から差し、線はすべて裏ですずめっき線を穴から穴へはんだ付けする。
# 表の図を描くときに、線・部品の足・部品の外形を REC に記録しておき、
# 左右を反転した「裏から見た図」を描く。線が交わる所・ほかの足の上を通る所も調べる。
import re as _re

REC = {"wires": [], "pins": [], "parts": [], "board": []}

def rec_reset():
    REC["wires"], REC["pins"], REC["parts"], REC["board"] = [], [], [], []

def rec_wire(pts, color):
    REC["wires"].append(([(float(x), float(y)) for x, y in pts], color))

def rec_pin(x, y, label="", kind="pin"):
    REC["pins"].append((float(x), float(y), label, kind))

def rec_part(x, y, w, h, label=""):
    REC["parts"].append((x, y, w, h, label))

def draw_back_wire(s, pts, color):
    """表の図では、裏の線を点線で描く (表から透かして見た位置)"""
    d = "M" + " L".join(f"{x},{y}" for x, y in pts)
    c = COL[color]
    edge = "#555" if color in ("white", "yellow") else c
    s.add(f'<path d="{d}" fill="none" stroke="#fff" stroke-width="7" stroke-linejoin="round" opacity="0.8"/>')
    s.add(f'<path d="{d}" fill="none" stroke="{edge}" stroke-width="4" stroke-dasharray="9 6" stroke-linecap="round" stroke-linejoin="round" opacity="0.95"/>')
    if color == "yellow":
        s.add(f'<path d="{d}" fill="none" stroke="{c}" stroke-width="2.5" stroke-dasharray="9 6" stroke-linecap="round" stroke-linejoin="round"/>')
    for x, y in (pts[0], pts[-1]):
        s.top.append(f'<circle cx="{x}" cy="{y}" r="5" fill="#fff" stroke="{edge}" stroke-width="2.5"/>')
    rec_wire(pts, color)

# ---- 調べる ----
def _seg_inter(a, b, c, d):
    """線分 ab と cd が、端点以外で交わる (または重なる) か"""
    def cross(o, p, q):
        return (p[0] - o[0]) * (q[1] - o[1]) - (p[1] - o[1]) * (q[0] - o[0])
    d1, d2, d3, d4 = cross(c, d, a), cross(c, d, b), cross(a, b, c), cross(a, b, d)
    if ((d1 > 0 > d2) or (d1 < 0 < d2)) and ((d3 > 0 > d4) or (d3 < 0 < d4)):
        return True
    # 重なり (同じ直線上)
    if d1 == 0 and d2 == 0:
        if a[0] == b[0] == c[0] == d[0]:
            lo, hi = sorted((a[1], b[1])); lo2, hi2 = sorted((c[1], d[1]))
            return min(hi, hi2) - max(lo, lo2) > 1
        if a[1] == b[1] == c[1] == d[1]:
            lo, hi = sorted((a[0], b[0])); lo2, hi2 = sorted((c[0], d[0]))
            return min(hi, hi2) - max(lo, lo2) > 1
    return False

def _holes_on(a, b):
    """線分 ab が通る穴 (端を除く)"""
    n = int(round(max(abs(b[0] - a[0]), abs(b[1] - a[1])) / P))
    out = []
    for i in range(1, n):
        out.append((round(a[0] + (b[0] - a[0]) * i / n, 1), round(a[1] + (b[1] - a[1]) * i / n, 1)))
    return out

def check_back(name):
    probs = []
    W = REC["wires"]
    for i in range(len(W)):
        for j in range(i + 1, len(W)):
            (p1, c1), (p2, c2) = W[i], W[j]
            if c1 == c2 or {c1, c2} <= {"red"} or {c1, c2} <= {"black"}:
                continue
            for a, b in zip(p1, p1[1:]):
                for c, d in zip(p2, p2[1:]):
                    if _seg_inter(a, b, c, d):
                        probs.append(f"交差 {c1}{[round(v) for v in a]}-{[round(v) for v in b]} × {c2}")
    joints = [(round(x, 1), round(y, 1), lab) for x, y, lab, k in REC["pins"]]
    for pts, c in W:
        for q in pts:
            joints.append((round(q[0], 1), round(q[1], 1), "線の端:" + c))
    for pts, c in W:
        ends = {(round(q[0], 1), round(q[1], 1)) for q in pts}
        for a, b in zip(pts, pts[1:]):
            for h in _holes_on(a, b):
                if h in ends:
                    continue
                for jx, jy, lab in joints:
                    if abs(jx - h[0]) < 1 and abs(jy - h[1]) < 1 and lab != "線の端:" + c:
                        probs.append(f"{c} の線が {lab or '足'} の穴の上を通る ({h})")
    print(f"[check] {name}: {len(probs)} 件")
    for p_ in sorted(set(probs)):
        print("   ", p_)
    return probs

# ---- 裏から見た図 ----
def _fix_text(e, cx):
    """反転グループの中の文字を、読める向きに戻す (位置だけ反転)"""
    m = _re.search(r'<text x="([-\d.]+)" y="([-\d.]+)"', e)
    if not m:
        return e
    x = float(m.group(1))
    e = e.replace("<text ", f'<text transform="matrix(-1,0,0,1,{2*x},0)" ', 1)
    if 'text-anchor="start"' in e:
        e = e.replace('text-anchor="start"', 'text-anchor="end"')
    elif 'text-anchor="end"' in e:
        e = e.replace('text-anchor="end"', 'text-anchor="start"')
    return e

def backview(s, ytop, cx, btop, bbot, title_txt="裏（はんだ面）から見た図　― 基板を左右に裏返した向き。線はすべて裏ですずめっき線"):
    """表の図 (中心 cx、上端 btop、下端 bbot) を左右反転して ytop から描く。描いた下端を返す"""
    dy = ytop + 60 - btop
    g = []
    for e in REC["board"]:
        if e.startswith("<text") and ('font-size="16"' in e or 'font-size="15"' in e):
            continue
        g.append(_fix_text(e, cx) if e.startswith("<text") else e)
    for x, y, w, h, lab in REC["parts"]:
        g.append(f'<rect x="{x}" y="{y}" width="{w}" height="{h}" rx="6" fill="#ffffff" fill-opacity="0.35" stroke="#78909c" stroke-width="2" stroke-dasharray="6 4"/>')
        if lab:
            g.append(_fix_text(f'<text x="{x+w/2}" y="{y+h/2+5}" font-size="13" text-anchor="middle" font-weight="bold" fill="#607d8b" font-family="{FONT}">{lab}</text>', cx))
    for pts, c in REC["wires"]:
        d = "M" + " L".join(f"{x},{y}" for x, y in pts)
        g.append(f'<path d="{d}" fill="none" stroke="#9e9e9e" stroke-width="9" stroke-linejoin="round" stroke-linecap="round"/>')
        g.append(f'<path d="{d}" fill="none" stroke="{COL[c]}" stroke-width="5" stroke-linejoin="round" stroke-linecap="round"/>')
    for x, y, lab, k in REC["pins"]:
        g.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="#cfd8dc" stroke="#546e7a" stroke-width="2"/>')
        if k == "cable":
            g.append(f'<circle cx="{x}" cy="{y}" r="12" fill="none" stroke="#d32f2f" stroke-width="2.5"/>')
        if lab:
            g.append(_fix_text(f'<text x="{x+11}" y="{y-9}" font-size="11" text-anchor="start" font-weight="bold" fill="#263238" font-family="{FONT}">{lab}</text>', cx))
    for pts, c in REC["wires"]:
        for x, y in pts:
            g.append(f'<circle cx="{x}" cy="{y}" r="4" fill="{COL[c]}" stroke="#333" stroke-width="1.2"/>')
    s.add(f'<rect x="20" y="{ytop}" width="{s.w-40}" height="{bbot-btop+110}" rx="12" fill="#eef3f5" stroke="#b0bec5" stroke-width="2"/>')
    s.text(40, ytop + 32, title_txt, 17, weight="bold", fill="#263238")
    s.add(f'<g transform="translate({2*cx},{dy}) scale(-1,1)">' + "\n".join(g) + "</g>")
    return ytop + bbot - btop + 110

# ------------------------------------------------------------------ タカチ基板版
P = 40                      # 穴の間隔 (px) = 2.54mm
ROWS = "ABCDEFGHIJKLMNOPQRSTUVWX"
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
    rec_reset(); _i0 = len(s.items)
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
    REC["board"] = s.items[_i0:]
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
    rec_part(x, y, w, h, "電池ボックス（表側に貼る）")
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

MODNAME = [""]
def flat_module(s, rows, names, color, cols, label, lab2, col=0):
    """モジュールをピンヘッダで寝かせて付ける (基板と平行)。ピンはローカル列 0、本体は左へ cols 穴分"""
    xa, ytop = H(col, rows[0]); _, ybot = H(col, rows[-1])
    w = cols * P
    s.top.append(f'<rect x="{xa-w}" y="{ytop-22}" width="{w+18}" height="{ybot-ytop+44}" rx="6" fill="{color}" fill-opacity="0.82" stroke="#333" stroke-width="2"/>')
    rec_part(xa - w, ytop - 22, w + 18, ybot - ytop + 44, MODNAME[0])
    for r, n in zip(rows, names):
        x, y = H(col, r)
        rec_pin(x, y, n)
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
    "・上の図は部品を載せる面（表）から見たところ。部品はすべて表から差し、足を裏ではんだ付けする。",
    "・線はすべて裏（はんだ面）で、すずめっき線を穴から穴へはんだ付けしてつなぐ。上の図では点線で透かして描き、下の「裏から見た図」が実際に配線する向き（左右が逆）。",
    "・裏の線どうしは交わらず、ほかの線の穴の上も通らないように引いてある。すずめっき線は被覆がないので、となりの線と触れないよう基板に沿わせてはんだ付けする。",
    "・穴の位置は「行の文字＋列の数字」（例: C14 は C 行の 14 列目）。",
    "・XIAO にピンヘッダ（オス）をはんだ付けし、基板にはピンソケット（メス、7ピン×2）をはんだ付けして差し込む。XIAO を抜いて交換できる。",
    "・赤丸の穴は、基板の外からの線（電池ケース・センサーのケーブル）を直接はんだ付けするところ。",
    "・USB で書き込むときは、必ず電池を外す（3V3 に電池を直結しているため）。",
    "・四すみの点線の丸は取付穴（位置は目安。実物で確認）。すぐ内側の × の穴は、ネジやスペーサーに当たらないよう使わない。",
]

def back_section(s, ytop, fname):
    """裏から見た図を描き、線の交わりを調べる。下の端を返す"""
    x0, y0, bw, bh, k = GEO
    check_back(fname)
    return backview(s, ytop, x0 + bw / 2, y0 - 50, BOT + 10)

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
    yb = back_section(s, yb + 10, fname)
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
        [f"タカチ ユニバーサル基板 {model}・すずめっき線", "1", ""],
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
def make_TH(fname, model, wmm, hmm, nc, nr, cx, cy, batt, shtcols, board_fn=None):
    global CX, CY
    CX, CY = 0, 0
    width = max(1280, OX + (nc - 1) * P + 260)
    s = S(width, 1960)
    title(s, f"HPX-TH（温湿度センサ）{batt} 版の配線図", f"基板 タカチ {model} ／ SHT40: SDA=D4, SCL=D5 ／ サーミスター: D3→10kΩ→A2 ／ 電源 {batt}")
    (board_fn or board_tnf)(s, model, wmm, hmm, nc, nr)
    CX, CY = cx, cy
    p = xiao_pcb(s, 3, 2)
    wireh(s, [(0, 8), (-1, 8), (-1, 0), (12, 0), (12, 10)], "black")
    wireh(s, [(0, 0), (0, 3)], "black")
    wireh(s, [p["GND"], (12, 3)], "black")
    wireh(s, [p["3V3"], (10, 4), (10, 10)], "red")
    wireh(s, [(0, 9), (10, 9)], "red")
    wireh(s, [p["D4"], (0, 6)], "green")
    wireh(s, [p["D5"], (0, 7)], "blue")
    wireh(s, [p["D2"], (2, 4)], "yellow")
    wireh(s, [p["D3"], (2, 5)], "purple")
    wireh(s, [(2, 4), (2, 2)], "yellow")
    res_stand(s, (2, 4), (2, 5), "10kΩ 1%", ["#8d5524", "#212121", "#212121"], H(5, 11)[0], H(5, 11)[1] + 6, "middle")
    s.top.append(f'<line x1="{H(2,5)[0]}" y1="{H(2,5)[1]+12}" x2="{H(5,11)[0]-20}" y2="{H(5,11)[1]-12}" stroke="#999" stroke-width="1.5" stroke-dasharray="3 3"/>')
    cap_flat(s, (0, 2), (2, 2), "0.1µF")
    s.text(H(1, 2)[0], H(1, 2)[1] - 18, "0.1µF", 12, "middle", "bold", "#1a237e", layer=s.top)
    cap_flat(s, (10, 6), (12, 6), "100µF", True, (H(12, 6)[0] + 22, H(12, 6)[1] - 16))
    def thermo(x, y):
        s.add(f'<ellipse cx="{x-60}" cy="{y}" rx="18" ry="13" fill="#5d4037" stroke="#3e2723" stroke-width="2"/>')
    cable_left(s, [(0, 3), (2, 3)], H(0, 3)[1] - 70, "サーミスター 10kΩ", "（向きなし・巣箱の中へ）", thermo)
    lx, ty, by = flat_module(s, [6, 7, 8, 9], ["SDA", "SCL", "GND", "VIN"], "#5e35b1", shtcols,
                             "SHT40 モジュール", "寝かせて付ける。ピンの並びは印字で確認")
    s.top.append(f'<rect x="{lx+30}" y="{(ty+by)/2-16}" width="32" height="32" rx="2" fill="#cfd8dc" stroke="#78909c"/>')
    yb = battery_case_below(s, 10, 12, 10, batt)
    yb = back_section(s, yb + 10, fname)
    bname = "CR123A 用電池ケース ／ CR123A 電池" if batt == "CR123A" else "単3×2本 直列の電池ケース ／ 単3リチウム電池"
    y = table(s, 60, yb + 40, [
        ["線", "どこから（穴）", "どこへ（穴）", "役目"],
        ["purple", f"XIAO D3（{hn(3,5)}）", f"10kΩ の下の足（{hn(2,5)}）", "測る瞬間だけ電気を流す"],
        ["yellow", f"XIAO D2/A2（{hn(3,4)}）", f"10kΩ の上の足（{hn(2,4)}）→ {hn(2,2)}", f"測る点（サーミスター {hn(2,3)}・0.1µF {hn(2,2)}）"],
        ["green", f"XIAO D4（{hn(3,6)}）", f"SHT40 SDA（{hn(0,6)}）", "データ（I2C）"],
        ["blue", f"XIAO D5（{hn(3,7)}）", f"SHT40 SCL（{hn(0,7)}）", "合図（I2C）"],
        ["black", f"SHT40 GND（{hn(0,8)}）", f"{hn(-1,8)} → {hn(-1,0)} → {hn(12,0)} → {hn(12,10)}（−の列）", "SHT40 の下を通って −の列へ"],
        ["black", f"{hn(0,0)}", f"{hn(0,3)}", f"0.1µF（{hn(0,2)}）とサーミスター（{hn(0,3)}）の GND"],
        ["red", f"SHT40 VIN（{hn(0,9)}）", f"{hn(10,9)}（＋の列）", "SHT40 の電源"],
    ] + rail_rows(10, 12, p), [100, 280, 500, 380], "つなぐ線（穴の位置は 行の文字＋列の数字）")
    y = table(s, 60, y + 50, [
        ["部品", "数", "置き場所・メモ"],
        ["Seeed XIAO BLE nRF52840 ＋ ピンヘッダ 7ピン×2（XIAO 側）＋ ピンソケット 7ピン×2（基板側）", "1", f"{hn(3,2)}〜{hn(9,8)}"],
        ["SHT40 モジュール（温湿度、I2C）＋ ピンヘッダ 4ピン", "1", f"{hn(0,6)}〜{hn(0,9)}（SDA・SCL・GND・VIN）。本体は左へ寝かせる"],
        ["サーミスター 10kΩ（平行線タイプ）", "1", f"線を {hn(0,3)}（GND）・{hn(2,3)}（測る点）に直接はんだ付け"],
        ["抵抗 10kΩ 1%（金属皮膜）", "1", f"{hn(2,4)}・{hn(2,5)}（立てて付ける）"],
        ["積層セラミックコンデンサー 0.1µF 50V C0G 5mm", "1", f"{hn(0,2)}・{hn(2,2)}（測る点と GND の間）"],
        ["絶縁ラジアルリード型積層セラミックコンデンサー 100µF 6.3V 5mm", "1", f"{hn(10,6)}・{hn(12,6)}"],
        [bname, "各1", f"赤線を {hn(10,10)}（＋）、黒線を {hn(12,10)}（−）にはんだ付け"],
        [f"タカチ ユニバーサル基板 {model}・すずめっき線", "1", ""],
    ], [560, 60, 540], "部品表")
    L = NOTES + [
        f"・0.1µF は {hn(1,2)} の上に寝かせて {hn(0,2)}・{hn(2,2)} に付ける。サーミスターの線は {hn(0,3)}・{hn(2,3)} に表から差してはんだ付け。",
        f"・SHT40 は寝かせて付ける。印字の SDA が上（{hn(0,6)}）、VIN が下（{hn(0,9)}）になる向き。黒線（{hn(-1,0)}〜{hn(-1,8)}）は SHT40 の下の裏を通る。",
        "・ファームは HPX_TH v1.01。SHT40 は 3.6V まで（5V にはつながない）。SHT40 の上は、ケースの中でも空気が通るようにする。",
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
    yb = back_section(s, BOT + 140, fname)
    y = table(s, 60, yb + 40, [
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
        ["タカチ ユニバーサル基板 TNF39-70・すずめっき線", "1", "左下 44×22mm を切り取る"],
    ], [560, 60, 540], "部品表")
    L = NOTES + [
        "・左下の 44×22mm を、のこぎりやニッパーで切り取る（切り口はやすりで平らに）。電池ケース（43×18mm）が入る大きさ。左下の取付穴はなくなるので、残りの 3つで留める。",
        f"・＋の線は裏の {hn(22,3)}〜{hn(22,8)} を通る。ここは XIAO の真下だが、XIAO のピンは 18 列と 24 列だけなので触れない。",
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
    """基板の裏側を通す線 (点線)。今はすべての線が裏なので wireh と同じ"""
    draw_back_wire(s, [H(*p) for p in pts], color)

def make_TH_on(fname):
    """HPX-TH CR123A 版 (TNF44-69、ケース SW-75B)。電池ケースのピンは 15 穴はなれている (TARO 実測)。回路は基板の真ん中へ (CX=2, CY=1)。SHT40 は寝かせて付ける
    SHT40 のピンは上から SDA・SCL・GND・VIN (F6〜I6)、本体は左へ"""
    global CX, CY
    CX, CY = 2, 1
    s = S(1440, 2100)
    title(s, "HPX-TH（温湿度センサ）CR123A 版の配線図", "基板 TNF44-69・ケース SW-75B ／ SHT40: D4・D5 ／ サーミスター: D3→10kΩ→A2")
    board_tnf(s, "TNF44-69", 69, 44, 25, 16)
    x0, y0, bw, bh, k = GEO
    p = xiao_pcb(s, 7, 0)
    # ---- 裏の線 (先に描く) ----
    wire_back(s, [(2, 1), (2, 11), (3, 11)], "black")              # B3 → K3 → K4 (電池−)
    wire_back(s, [(2, 6), (3, 6)], "black")                        # F3 → F4 (SHT40 GND)
    wire_back(s, [(2, 11), (2, 12), (19, 12), (19, 6)], "black")   # K4 → L4 → L20 → G20
    wire_back(s, [(18, 11), (17, 11), (17, 6)], "red")             # K19 (電池＋) → K18 → G18
    wire_back(s, [(17, 8), (3, 8), (3, 7)], "red")                 # I18 → I4 → G4 (SHT40 VIN)
    # ---- 表の線 ----
    wireh(s, [p["D4"], (3, 4)], "green")
    wireh(s, [p["D5"], (3, 5)], "blue")
    wireh(s, [p["D2"], (6, 2), (6, 0)], "yellow")
    wireh(s, [p["D3"], (6, 3)], "purple")
    wireh(s, [(4, 0), (2, 0), (2, 1)], "black")
    wireh(s, [p["3V3"], (17, 2), (17, 6)], "red")
    wireh(s, [p["GND"], (19, 1), (19, 6)], "black")
    res_stand(s, (6, 2), (6, 3), f"10kΩ 1%（{hn(6,2)}・{hn(6,3)}）", ["#8d5524", "#212121", "#212121"], x0 - 30, H(0, 2)[1] + 4, "end")
    cap_flat(s, (4, 0), (6, 0), "0.1µF")
    s.text(H(5, 0)[0], H(5, 0)[1] - 18, "0.1µF", 12, "middle", "bold", "#1a237e", layer=s.top)
    cap_flat(s, (17, 4), (19, 4), "100µF", True, (H(18, 4)[0], H(18, 4)[1] - 22), "middle")
    # SHT40 を寝かせて付ける (ピンは 4 列、本体は左へ)
    flat_module(s, [4, 5, 6, 7], ["SDA", "SCL", "GND", "VIN"], "#5e35b1", 3, "", "", col=3)
    # SHT40 の下を通る裏の線を、本体の上に重ねて見せる
    for pts, c in (([(2, 1), (2, 11)], "black"), ([(2, 6), (3, 6)], "black"), ([(3, 8), (3, 7)], "red")):
        d = "M" + " L".join(f"{H(*q)[0]},{H(*q)[1]}" for q in pts)
        s.top.append(f'<path d="{d}" fill="none" stroke="{COL[c] if c == "red" else "#fff"}" stroke-width="4" stroke-dasharray="9 6" stroke-linecap="round" opacity="0.95"/>')
    x5, y5 = H(2, 6)
    s.top.append(f'<circle cx="{x5}" cy="{y5}" r="6" fill="#fff" stroke="#212121" stroke-width="2.5"/>')
    sx, sy = H(1.0, 5.0)
    s.top.append(f'<rect x="{sx-14}" y="{sy-14}" width="28" height="28" rx="2" fill="#cfd8dc" stroke="#78909c"/>')
    s.text(H(1.0, 7)[0], H(1.0, 7)[1] - 18, "SHT40", 12, "middle", "bold", "#fff", layer=s.top)
    s.text(H(1.0, 7)[0], H(1.0, 7)[1] - 4, "（寝かせる）", 10, "middle", "bold", "#fff", layer=s.top)
    # サーミスター (上の端から A4・B7)
    for i, h in enumerate([(2, 0), (6, 1)]):
        x, y = H(*h)
        jx = x + 20 if i else x
        s.add(f'<path d="M{x},{y} L{jx},{y-20 if i else y} L{jx},{y0-50-i*12} L180,{y0-50-i*12}" fill="none" stroke="#9e9e9e" stroke-width="4" stroke-linejoin="round"/>')
        solder_mark(s, *h)
    s.add(f'<ellipse cx="150" cy="{y0-56}" rx="18" ry="13" fill="#5d4037" stroke="#3e2723" stroke-width="2"/>')
    s.text(40, y0 - 92, "サーミスター 10kΩ", 15, weight="bold")
    s.text(40, y0 - 76, "（向きなし・巣箱の中へ）", 12, fill="#666")
    # 電池ケース: ピンを K4 (−) と K19 (＋) に差す
    (px1, py), (px2, _) = H(3, 11), H(18, 11)
    bw2, bh2 = 43 * k, 18 * k
    bx, by = (px1 + px2) / 2 - bw2 / 2, py - bh2 / 2
    s.top.append(f'<rect x="{bx}" y="{by}" width="{bw2}" height="{bh2}" rx="8" fill="#37474f" fill-opacity="0.78" stroke="#222" stroke-width="3"/>')
    rec_part(bx, by, bw2, bh2, "CR123A 電池ケース（表側）")
    s.top.append(f'<rect x="{bx+22}" y="{by+14}" width="{bw2-44}" height="{bh2-28}" rx="30" fill="#b0bec5" fill-opacity="0.6" stroke="#607d8b" stroke-width="2"/>')
    s.text(bx + bw2 / 2, by + 34, "CR123A 用電池ケース（秋月 110192、43×18×14mm）", 14, "middle", "bold", "#fff", layer=s.top)
    s.text(bx + bw2 / 2, by + bh2 - 16, f"裏のピンを {hn(3, 11)}（−）と {hn(18, 11)}（＋）に差してはんだ付け", 12, "middle", "bold", "#fff", layer=s.top)
    s.text(px1 + 22, py + 6, "−", 22, "start", "bold", "#fff", layer=s.top)
    s.text(px2 - 22, py + 6, "＋", 18, "end", "bold", "#fff", layer=s.top)
    for h in ((3, 11), (18, 11)):
        x, y = H(*h)
        s.top.append(f'<rect x="{x-7}" y="{y-7}" width="14" height="14" fill="#cfd8dc" stroke="#222" stroke-width="2"/>')
        solder_mark(s, *h)
    yb = back_section(s, BOT + 40, fname)
    y = table(s, 60, yb + 40, [
        ["線", "どこから（穴）", "どこへ（穴）", "役目"],
        ["green", f"XIAO D4（{hn(7,4)}）", f"SHT40 SDA（{hn(3,4)}）", "データ（I2C）"],
        ["blue", f"XIAO D5（{hn(7,5)}）", f"SHT40 SCL（{hn(3,5)}）", "合図（I2C）"],
        ["purple", f"XIAO D3（{hn(7,3)}）", f"10kΩ の下の足（{hn(6,3)}）", "測る瞬間だけ電気を流す"],
        ["yellow", f"XIAO D2/A2（{hn(7,2)}）", f"10kΩ の上の足（{hn(6,2)}）→ {hn(6,1)} → {hn(6,0)}", "測る点（サーミスターと 0.1µF）"],
        ["black", f"0.1µF の足（{hn(4,0)}）", f"{hn(2,0)}（サーミスター）→ {hn(2,1)}", "左側の GND（サーミスターも）"],
        ["black", f"{hn(2,1)}", f"{hn(2, 11)} → 電池ケースの −（{hn(3, 11)}）", f"電池 − を左側の GND へ（{hn(2,6)} を通る）"],
        ["black", f"{hn(2,6)}", f"SHT40 GND（{hn(3,6)}）", f"{hn(2,6)} で上の線とはんだ付け"],
        ["black", f"{hn(2, 11)}", f"{hn(2, 12)} → {hn(3, 12)} → {hn(19, 12)} → {hn(19,6)}", "電池 − を右側の −の列へ"],
        ["black", f"XIAO GND（{hn(13,1)}）", f"{hn(19,1)} → {hn(19,6)}", "−の列"],
        ["red", f"XIAO 3V3（{hn(13,2)}）", f"{hn(17,2)} → {hn(17,6)}", "＋の列"],
        ["red", f"電池ケースの ＋（{hn(18, 11)}）", f"{hn(17, 11)} → {hn(17,6)}", "電池 ＋ を ＋の列へ"],
        ["red", f"{hn(17,8)}", f"{hn(3,8)} → SHT40 VIN（{hn(3,7)}）", "SHT40 の電源"],
    ], [100, 300, 470, 390], "つなぐ線（穴の位置は 行の文字＋列の数字）")
    y = table(s, 60, y + 50, [
        ["部品", "数", "置き場所・メモ"],
        ["Seeed XIAO BLE nRF52840 ＋ ピンヘッダ 7ピン×2（XIAO 側）＋ ピンソケット 7ピン×2（基板側）", "1", f"{hn(7,0)}〜{hn(13,6)}"],
        ["SHT40 モジュール（温湿度、I2C）＋ ピンヘッダ 4ピン", "1", f"ピンを {hn(3,4)}〜{hn(3,7)}（SDA・SCL・GND・VIN）、本体を左へ寝かせる"],
        ["サーミスター 10kΩ（平行線タイプ）", "1", f"線を {hn(2,0)}（GND）・{hn(6,1)}（測る点）に直接はんだ付け"],
        ["抵抗 10kΩ 1%（金属皮膜）", "1", f"{hn(6,2)}・{hn(6,3)}（立てて付ける）"],
        ["積層セラミックコンデンサー 0.1µF 50V C0G 5mm", "1", f"{hn(4,0)}・{hn(6,0)}（測る点と GND の間）"],
        ["絶縁ラジアルリード型積層セラミックコンデンサー 100µF 6.3V 5mm", "1", f"{hn(17,4)}・{hn(19,4)}"],
        ["CR123A 用電池ケース（秋月 110192）／ CR123A 電池", "各1", f"ピンを {hn(3, 11)}（−）・{hn(18, 11)}（＋）に差してはんだ付け"],
        ["タカチ ユニバーサル基板 TNF44-69（44×69mm、穴 16×25）・すずめっき線", "1", "ケース タカチ SW-75B"],
    ], [560, 60, 540], "部品表")
    L = NOTES + [
        f"・電池ケースは、裏のピンを {hn(3, 11)} と {hn(18, 11)} に差す（ピンの間隔が 15 穴分なのは TARO さんの実測）。＋の印が右（{hn(18, 11)}）、−が左（{hn(3, 11)}）になる向きにする。",
        f"・電池ケースのピンが穴の列のちょうど真ん中にない場合、ケースが上下にずれる。{hn(0,6)[0]} 行より上の部品に当たらないか、実物を置いて確かめる。",
        f"・電池ケースの下になる線（{hn(2, 11)}・{hn(2, 12)}・{hn(3, 12)} のあたり）も裏なので、ケースを付けたあとでもはんだ付けできる。{hn(2,6)} は 2本の黒線をはんだでつなぐ。",
        f"・SHT40 は寝かせて付ける。印字の SDA が上（{hn(3,4)}）、VIN が下（{hn(3,7)}）になる向き。本体が {hn(0,4)}〜{hn(2,7)} のあたりに収まるか、すぐ下の電池ケースに当たらないか実物で確かめる。",
        "・ファームは HPX_TH v1.01。SHT40 は 3.6V まで（5V にはつながない）。",
    ]
    notes(s, 60, y + 20, L)
    s.h = int(y + 20 + 34 + 26 * len(L) + 40)
    s.save(fname)

make_TH_on("HPX-TH_CR123A_TNF44-69.svg")
#make_TH("HPX-TH_CR123A_TNF39-59.svg", "TNF39-59", 59, 39, 21, 14, 6, 2, "CR123A", 6)
print("ok")

def board_lc115(s, model, wmm, hmm, nc, nr):
    """LC115H-M2 の推奨基板 (本体 60×54.5mm、上に 46×8.5mm の出っぱり、取付穴 φ2.5 ×4: 53×40mm 間隔)。
    ユニバーサル基板をこの形に切る。穴の並びは 23×24 (列 1〜23、行 A〜X)"""
    global BW, BH, BOT, GEO
    rec_reset(); _i0 = len(s.items)
    BW, BH = nc, nr
    k = P / 2.54
    gx0, gy0 = 2.06, 1.75                     # 穴 A1 の位置 (mm、基板の左上から)
    x0, y0 = OX - gx0 * k, OY - gy0 * k
    BOT = y0 + 63 * k
    GEO = (x0, y0, 60 * k, 63 * k, k)
    X = lambda m: x0 + m * k
    Y = lambda m: y0 + m * k
    r = 3.5 * k
    d = (f"M{X(7)},{Y(0)} L{X(53)},{Y(0)} L{X(53)},{Y(8.5)-r} Q{X(53)},{Y(8.5)} {X(53)+r},{Y(8.5)} "
         f"L{X(60)},{Y(8.5)} L{X(60)},{Y(63)} L{X(0)},{Y(63)} L{X(0)},{Y(8.5)} L{X(7)-r},{Y(8.5)} "
         f"Q{X(7)},{Y(8.5)} {X(7)},{Y(8.5)-r} Z")
    s.add(f'<path d="{d}" fill="#e8d3a8" stroke="#8d6e3f" stroke-width="2.5"/>')
    holes = [(3.5, 15), (56.5, 15), (3.5, 55), (56.5, 55)]
    def inside(xm, ym):
        mg = 1.3
        if ym < 8.5 + mg:
            ok = 7 + mg < xm < 53 - mg and ym > mg
        else:
            ok = mg < xm < 60 - mg and ym < 63 - mg
        return ok and all((xm - hx) ** 2 + (ym - hy) ** 2 > 3.0 ** 2 for hx, hy in holes)
    for c in range(nc):
        for rr in range(nr):
            xm, ym = gx0 + c * 2.54, gy0 + rr * 2.54
            if not inside(xm, ym):
                continue
            x, y = OX + c * P, OY + rr * P
            s.add(f'<circle cx="{x}" cy="{y}" r="7" fill="#d9a441" stroke="#a87b24" stroke-width="1"/>')
            s.add(f'<circle cx="{x}" cy="{y}" r="2.6" fill="#5d4520"/>')
    for hx, hy in holes:
        s.add(f'<circle cx="{X(hx)}" cy="{Y(hy)}" r="{1.25*k}" fill="#fffdf6" stroke="#8d6e3f" stroke-width="2"/>')
    for c in range(nc):
        if c % 2 == 0:
            s.text(OX + c * P, y0 - 10, str(c + 1), 12, "middle", fill="#8d6e3f", weight="bold")
    for rr in range(nr):
        s.text(x0 - 10, OY + rr * P + 5, ROWS[rr], 13, "end", fill="#8d6e3f", weight="bold")
    REC["board"] = s.items[_i0:]
    # 寸法
    s.add(f'<path d="M{X(0)},{Y(63)+24} L{X(60)},{Y(63)+24}" stroke="#2e7d32" stroke-width="1.5"/>')
    s.text(X(30), Y(63) + 44, "60mm", 13, "middle", "bold", "#2e7d32")
    s.add(f'<path d="M{X(60)+40},{Y(0)} L{X(60)+40},{Y(63)}" stroke="#2e7d32" stroke-width="1.5"/>')
    s.text(X(60) + 48, Y(32), "63mm", 13, "start", "bold", "#2e7d32")
    s.text(X(30), y0 - 34, "LC115H-M2-D の推奨基板の形（本体 60×54.5mm ＋ 上の出っぱり 46×8.5mm）。ユニバーサル基板を切って作る", 15, "middle", "bold", "#5d4520")

make_TH("HPX-TH_AA_LC115H.svg", "LC115H-M2-D 用", 60, 63, 23, 24, 7, 5, "単3リチウム×2", 6, board_fn=board_lc115)

# LC115H 版の文言を差し替える
_p = os.path.join(OUT, "HPX-TH_AA_LC115H.svg")
_s = open(_p, encoding="utf-8").read()
for _a, _b in [
    ("基板 タカチ LC115H-M2-D 用 ／ SHT40: SDA=D4, SCL=D5 ／ サーミスター: D3→10kΩ→A2 ／ 電源 単3リチウム×2", "ケース タカチ LC115H-M2-D（推奨基板の形）／ SHT40: D4・D5 ／ サーミスター: D3→10kΩ→A2"),
    ("単3×2本 直列の電池ケース ＋ 単3リチウム電池 2本", "LC115H-M2-D の電池室（単3×2本）＋ 単3リチウム電池 2本"),
    ("単3×2本 直列の電池ケース ／ 単3リチウム電池", "ケースの電池室の端子 ／ 単3リチウム電池 2本"),
    ("タカチ ユニバーサル基板 LC115H-M2-D 用・すずめっき線", "ユニバーサル基板（推奨基板の形 60×63mm に切る）・すずめっき線"),
    ("・四すみの点線の丸は取付穴（位置は目安。実物で確認）。すぐ内側の × の穴は、ネジやスペーサーに当たらないよう使わない。",
     "・白い丸は取付穴 φ2.5（4つ、左右 53mm・上下 40mm 間隔）。ケースのボスにネジで留める。穴のまわりの穴は使わない。"),
]:
    assert _a in _s, _a
    _s = _s.replace(_a, _b)
open(_p, "w", encoding="utf-8").write(_s)
