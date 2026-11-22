#!/usr/bin/env python3
# 基板 (ユニバーサル基板 2.54mm ピッチ) に部品を並べた配線図の SVG を作る
import sys, os
OUT = sys.argv[1]
os.makedirs(OUT, exist_ok=True)

FONT = "'Hiragino Kaku Gothic ProN','Hiragino Sans','Meiryo','Yu Gothic','Noto Sans CJK JP','IPAGothic',sans-serif"
COL = {
    "red": "#e53935", "black": "#212121", "yellow": "#fdd835", "blue": "#1e88e5",
    "white": "#fafafa", "green": "#43a047", "orange": "#fb8c00", "purple": "#8e24aa", "gray": "#9e9e9e",
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
        # ウェブサイト用: 図だけの SVG (表と注意の上で切る) と、表・注意の文字 (JSON)
        stem = os.path.splitext(name)[0]
        cut = int(INFO["cut"] or s.h)
        fig = (f'<svg xmlns="http://www.w3.org/2000/svg" width="{s.w}" height="{cut}" viewBox="0 0 {s.w} {cut}">\n'
               f'<rect width="100%" height="100%" fill="#fffdf6"/>\n{body}\n</svg>\n')
        open(os.path.join(OUT, stem + "_fig.svg"), "w", encoding="utf-8").write(fig)
        import json
        json.dump({"tables": INFO["tables"], "notes": INFO["notes"]},
                  open(os.path.join(OUT, stem + ".json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)
        INFO.update(cut=None, tables=[], notes=[])

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

INFO = {"cut": None, "tables": [], "notes": []}   # 表と注意の中身 (ウェブサイト用に JSON でも出す)

def notes(s, x, y, lines, head="注意"):
    INFO["notes"] += [l.lstrip("・") for l in lines]
    INFO["cut"] = INFO["cut"] or y - 20
    s.rect(x, y, s.w - 2 * x, 34 + 26 * len(lines), "#fff3cd", "#e0b84a", 10, 2)
    s.text(x + 18, y + 26, head, 17, weight="bold", fill="#7a5a00")
    for i, l in enumerate(lines):
        s.text(x + 18, y + 54 + i * 26, l, 15, fill="#4a3a00")

def table(s, x, y, rows, widths, head):
    INFO["tables"].append({"head": head, "rows": rows})
    INFO["cut"] = INFO["cut"] or y - 40
    s.text(x, y - 12, head, 17, weight="bold")
    yy = y
    for r, row in enumerate(rows):
        bg = "#1f3a2a" if r == 0 else ("#ffffff" if r % 2 else "#f4f1e6")
        s.rect(x, yy, sum(widths), 30, bg, "#d8d2bd", 0, 1)
        xx = x
        for c, cell in enumerate(row):
            if r and c == 0 and cell in COL:
                s.add(f'<rect x="{xx+10}" y="{yy+9}" width="34" height="12" rx="3" fill="{COL[cell]}" stroke="#555" stroke-width="1"/>')
                cell = {"red": "赤", "black": "黒", "yellow": "黄", "blue": "青", "white": "白", "green": "緑", "orange": "橙", "purple": "紫", "gray": "灰"}[cell]
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




# ------------------------------------------------------------------ 小さな基板版
P = 40                      # 穴の間隔 (px) = 2.54mm
OX, OY = 600, 200           # 穴 A1 の位置
COLS = "ABCDEFGHIJKLMNOPQRSTUVWXY"
NC, NR = 12, 11             # 図ごとに変える


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

def H(c, r):
    return (OX + c * P, OY + r * P)

def hn(c, r):
    return f"{COLS[c]}{r+1}"

def mm(n):
    return round((n - 1) * 2.54 + 5)

def board(s, nc, nr, label=None):
    global NC, NR
    rec_reset(); i0 = len(s.items)
    NC, NR = nc, nr
    x0, y0 = OX - 26, OY - 26
    w, h = (nc - 1) * P + 52, (nr - 1) * P + 52
    s.rect(x0 - 6, y0 - 6, w + 12, h + 12, "#c8a165", "#8d6e3f", 10, 2)
    s.rect(x0, y0, w, h, "#e8d3a8", "#b08d57", 6, 1.5)
    for c in range(nc):
        s.text(OX + c * P, y0 - 12, COLS[c], 13, "middle", fill="#8d6e3f", weight="bold")
        for r in range(nr):
            x, y = H(c, r)
            s.add(f'<circle cx="{x}" cy="{y}" r="7" fill="#d9a441" stroke="#a87b24" stroke-width="1"/>')
            s.add(f'<circle cx="{x}" cy="{y}" r="2.6" fill="#5d4520"/>')
    for r in range(nr):
        s.text(x0 + w + 14, OY + r * P + 5, str(r + 1), 13, "start", fill="#8d6e3f", weight="bold")
    REC["board"] = s.items[i0:]
    s.text(x0 + w / 2, y0 - 40, label or f"ユニバーサル基板 穴 {nc}×{nr}（約 {mm(nc)}×{mm(nr)}mm）", 15, "middle", "bold", "#5d4520")

def wireh(s, pts, color):
    draw_back_wire(s, [H(*p) for p in pts], color)

def xiao_pcb(s, c0, r0, sub="nRF52840"):
    """XIAO をピンソケットに差す。左列 c0 (D0〜D6)、右列 c0+6 (5V,GND,3V3,D10〜D7)"""
    x1, y1 = H(c0, r0); x2, y2 = H(c0 + 6, r0 + 6)
    s.top.append(f'<rect x="{x1-14}" y="{y1-24}" width="{x2-x1+28}" height="{y2-y1+48}" rx="8" fill="#1b5e20" fill-opacity="0.92" stroke="#0d3311" stroke-width="2"/>')
    s.top.append(f'<rect x="{(x1+x2)/2-24}" y="{y1-32}" width="48" height="18" rx="5" fill="#b0b0b0" stroke="#777"/>')
    cx, cy = (x1 + x2) / 2, (y1 + y2) / 2
    s.top.append(f'<rect x="{cx-44}" y="{cy-56}" width="88" height="76" rx="3" fill="#cfd8dc" stroke="#90a4ae"/>')
    s.text(cx, cy - 22, "XIAO", 16, "middle", "bold", "#37474f", layer=s.top)
    s.text(cx, cy - 2, sub, 12, "middle", fill="#37474f", layer=s.top)
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
    rec_pin(x1, y1, label); rec_pin(x2, y2, label)
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

def cable_in(s, holes, ytext, name, sub, shape):
    """基板の外から 2本の線を穴に直接はんだ付け"""
    for i, h in enumerate(holes):
        x, y = H(*h)
        s.add(f'<path d="M{x},{y} L{OX-60},{y} L{OX-90},{ytext+40+i*12} L150,{ytext+40+i*12}" fill="none" stroke="#9e9e9e" stroke-width="4" stroke-linejoin="round"/>')
        solder_mark(s, *h)
    shape(130, ytext + 46)
    s.text(60, ytext, name, 15, weight="bold")
    s.text(60, ytext + 18, sub, 12, fill="#666")

def standing(s, rows, names, title, sub, color, width):
    """モジュールをピンヘッダで A 列に立てて付ける（図では左に倒して描く）。ピンは A 列"""
    xa, ytop = H(0, rows[0]); _, ybot = H(0, rows[-1])
    s.top.append(f'<rect x="{xa-width}" y="{ytop-22}" width="{width+16}" height="{ybot-ytop+44}" rx="6" fill="{color}" fill-opacity="0.88" stroke="#333" stroke-width="2"/>')
    for r, n in zip(rows, names):
        x, y = H(0, r)
        rec_pin(x, y, n)
        s.top.append(f'<circle cx="{x}" cy="{y}" r="8" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
        s.text(x - 14, y + 5, n, 12, "end", "bold", "#fff", layer=s.top)
    return xa - width, ytop - 22, ybot + 22

def battery_below(s, cp, cm, r):
    """CR123A ケースを基板の下に置く（＋を左、−を右に向ける）。線を ＋の列・−の列の端に直接はんだ付け"""
    tp, tm = H(cp, r), H(cm, r)
    x, y = tp[0] - 190, OY + (NR - 1) * P + 90
    s.rect(x, y, 260, 70, "#37474f", "#222", 10, 3)
    s.rect(x + 20, y + 14, 220, 42, "#b0bec5", "#607d8b", 20, 2)
    s.rect(x + 14, y + 26, 10, 18, "#90a4ae", "#607d8b", 2, 1)
    s.text(x + 130, y + 41, "CR123A（3V）", 15, "middle", "bold", "#263238")
    s.text(x + 10, y + 22, "＋", 14, "start", "bold", "#fff")
    s.text(x + 250, y + 22, "−", 18, "end", "bold", "#fff")
    s.text(x + 130, y + 96, "CR123A 用電池ケース ＋ CR123A 電池", 14, "middle", "bold")
    bp, bm = (x, y + 35), (x + 260, y + 35)
    s.wire([bp, (x - 30, bp[1]), (x - 30, y - 30), (tp[0], y - 30), tp], "red")
    s.wire([bm, (x + 290, bm[1]), (x + 290, y - 50), (tm[0], y - 50), tm], "black")
    solder_mark(s, cp, r); solder_mark(s, cm, r)

def back_section(s, ytop, fname):
    """裏から見た図を描き、線の交わりを調べる。下の端を返す"""
    check_back(fname)
    return backview(s, ytop, OX + (NC - 1) * P / 2, OY - 26 - 50, OY + (NR - 1) * P + 36)

def finish(s, y, L, fname):
    notes(s, 60, y + 20, L)
    s.h = int(y + 20 + 34 + 26 * len(L) + 40)
    s.save(fname)

COMMON_NOTES = [
    "・上の図は部品を載せる面（表）から見たところ。部品はすべて表から差し、足を裏ではんだ付けする。",
    "・線はすべて裏（はんだ面）で、すずめっき線を穴から穴へはんだ付けしてつなぐ。上の図では点線で透かして描き、下の「裏から見た図」が実際に配線する向き（左右が逆）。",
    "・裏の線どうしは交わらず、ほかの線の穴の上も通らないように引いてある。すずめっき線は被覆がないので、となりの線と触れないよう基板に沿わせてはんだ付けする。",
    "・XIAO にピンヘッダ（オス）をはんだ付けし、基板にはピンソケット（メス、7ピン×2）をはんだ付けして差し込む。XIAO を抜いて交換できる。",
    "・赤丸の穴は、基板の外からの線（電池ケース・センサーのケーブル）を直接はんだ付けするところ。",
    "・USB で書き込むときは、必ず電池を外す（3V3 に電池を直結しているため）。",
]

def rail_rows(cp, cm):
    return [
        ["red", "XIAO 3V3（" + hn(*P3) + "）", f"{hn(cp,P3[1])} → {hn(cp,10)}（＋の列）", "電池＋・コンデンサーもこの列"],
        ["black", "XIAO GND（" + hn(*PG) + "）", f"{hn(cm,PG[1])}（−の列 {hn(cm,0)}〜{hn(cm,10)}）", "電池−・コンデンサーもこの列"],
    ]

# ================================================================== HPX-W
s = S(1280, 1660)
title(s, "HPX-W（重量センサ）基板の配線図", "小さい基板版 ／ HX711 DT=D0, SCK=D1 ／ 電源 CR123A")
board(s, 12, 11)
p = xiao_pcb(s, 2, 2)
P3, PG = p["3V3"], p["GND"]
wireh(s, [(0, 1), (0, 0), (11, 0), (11, 10)], "black")
wireh(s, [p["GND"], (11, 3)], "black")
wireh(s, [p["3V3"], (9, 4), (9, 10)], "red")
wireh(s, [(0, 4), (0, 9), (9, 9)], "red")
wireh(s, [p["D0"], (0, 2)], "yellow")
wireh(s, [p["D1"], (0, 3)], "blue")
cap_flat(s, (9, 6), (11, 6), "100µF", True, (H(11, 6)[0] + 80, H(11, 6)[1] + 5))
cap_flat(s, (9, 8), (11, 8), "0.1µF", False, (H(11, 8)[0] + 80, H(11, 8)[1] + 5))
lx, ty, by = standing(s, [1, 2, 3, 4], ["GND", "DT", "SCK", "VCC"], "", "", "#2e7d32", 230)
s.top.append(f'<rect x="{lx+60}" y="{(ty+by)/2-24}" width="48" height="48" fill="#212121"/>')
s.text(lx + 84, (ty + by) / 2 + 5, "HX711", 11, "middle", "bold", "#eee", layer=s.top)
for i, n in enumerate(["E+", "E-", "A-", "A+"]):
    y = ty + 26 + i * 30
    s.top.append(f'<circle cx="{lx+16}" cy="{y}" r="6" fill="#d4af37" stroke="#8a6d1d" stroke-width="1.5"/>')
    s.text(lx + 28, y + 4, n, 11, "start", "bold", "#fff", layer=s.top)
s.text(OX - 36, by + 22, "HX711 モジュール", 14, "end", "bold", "#1b5e20", layer=s.top)
s.text(OX - 36, by + 40, "A 列に立てて付ける（図では倒して描いた）", 11, "end", fill="#555", layer=s.top)
s.rect(40, 230, 200, 130, "#b0bec5", "#78909c", 6, 3)
s.text(140, 290, "ロードセル", 15, "middle", "bold", "#263238")
s.text(140, 312, "SC601（120kg）", 12, "middle", fill="#263238")
s.text(140, 384, "線の色は説明書で確認", 12, "middle", fill="#b23", weight="bold")
for i, c in enumerate(["red", "black", "white", "green"]):
    y = ty + 26 + i * 30
    if c == "white":
        s.add(f'<path d="M{lx+16},{y} L240,{y}" fill="none" stroke="#777" stroke-width="7"/><path d="M{lx+16},{y} L240,{y}" fill="none" stroke="#fafafa" stroke-width="4.5"/>')
    else:
        s.add(f'<path d="M{lx+16},{y} L240,{y}" fill="none" stroke="{COL[c]}" stroke-width="5"/>')
battery_below(s, 9, 11, 10)
yb = back_section(s, OY + (NR - 1) * P + 230, "HPX-W_pcb.svg")
y = table(s, 60, yb + 40, [
    ["線", "どこから（穴）", "どこへ（穴）", "役目"],
    ["yellow", f"XIAO D0（{hn(2,2)}）", f"HX711 DT（{hn(0,2)}）", "重さのデータ"],
    ["blue", f"XIAO D1（{hn(2,3)}）", f"HX711 SCK（{hn(0,3)}）", "読み取りの合図"],
    ["black", f"HX711 GND（{hn(0,1)}）", f"{hn(0,0)} → {hn(11,0)} → {hn(11,10)}（−の列）", "上の端を回って −の列へ"],
    ["red", f"HX711 VCC（{hn(0,4)}）", f"{hn(0,9)} → {hn(9,9)}（＋の列）", "下を回って ＋の列へ"],
] + rail_rows(9, 11), [100, 280, 400, 380], "つなぐ線（穴の位置は 列の文字＋行の数字）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["Seeed XIAO BLE nRF52840 ＋ ピンヘッダ 7ピン×2（XIAO 側）＋ ピンソケット 7ピン×2（基板側）", "1", f"{hn(2,2)}〜{hn(8,8)}"],
    ["HX711 モジュール ＋ ピンヘッダ 4ピン", "1", f"{hn(0,1)}〜{hn(0,4)}（GND・DT・SCK・VCC）に立てて付ける"],
    ["ロードセル SC601 120kg", "1", "HX711 の E+・E-・A-・A+ に直接はんだ付け"],
    ["絶縁ラジアルリード型積層セラミックコンデンサー 100µF 6.3V 5mm", "1", f"{hn(9,6)}・{hn(11,6)}（向きなし）"],
    ["積層セラミックコンデンサー 0.1µF 50V C0G 5mm", "1", f"{hn(9,8)}・{hn(11,8)}"],
    ["CR123A 用電池ケース ／ CR123A 電池", "各1", f"赤線を {hn(9,10)}（＋）、黒線を {hn(11,10)}（−）にはんだ付け"],
    ["ユニバーサル基板（穴 12×11 以上）・すずめっき線", "", "大きい基板を切って使ってもよい"],
], [560, 60, 540], "部品表")
finish(s, y, COMMON_NOTES + [
    "・HX711 は基板に寝かせず、A 列にピンヘッダで立てて付ける（場所を取らないため）。倒れないよう、ケースの中で軽く固定するとよい。",
], "HPX-W_pcb.svg")

# ================================================================== HPX-TH
s = S(1280, 1860)
title(s, "HPX-TH（温湿度センサ）基板の配線図", "小さい基板版 ／ SHT40: SDA=D4, SCL=D5 ／ サーミスター: D3→10kΩ→A2 ／ 電源 CR123A")
board(s, 13, 11)
p = xiao_pcb(s, 3, 2)
P3, PG = p["3V3"], p["GND"]
wireh(s, [(0, 3), (0, 0), (12, 0), (12, 10)], "black")
wireh(s, [p["GND"], (12, 3)], "black")
wireh(s, [(0, 8), (1, 8), (1, 9), (4, 9), (4, 0)], "black")
wireh(s, [p["3V3"], (10, 4), (10, 10)], "red")
wireh(s, [(0, 9), (0, 10), (10, 10)], "red")
wireh(s, [p["D4"], (0, 6)], "green")
wireh(s, [p["D5"], (0, 7)], "blue")
wireh(s, [p["D2"], (2, 4)], "yellow")
wireh(s, [p["D3"], (2, 5)], "purple")
wireh(s, [(2, 4), (2, 2)], "yellow")
res_stand(s, (2, 4), (2, 5), "10kΩ 1%", ["#8d5524", "#212121", "#212121"], OX - 50, H(0, 4)[1] + 4, "end")
s.top.append(f'<line x1="{H(2,4)[0]-10}" y1="{H(2,4)[1]+4}" x2="{OX-46}" y2="{H(0,4)[1]}" stroke="#999" stroke-width="1.5" stroke-dasharray="3 3"/>')
cap_flat(s, (0, 2), (2, 2), "0.1µF")
s.text(H(1, 2)[0], H(1, 2)[1] - 18, "0.1µF", 12, "middle", "bold", "#1a237e", layer=s.top)
cap_flat(s, (10, 6), (12, 6), "100µF", True, (H(12, 6)[0] + 80, H(12, 6)[1] + 5))
def thermo(x, y):
    s.add(f'<ellipse cx="{x-50}" cy="{y}" rx="18" ry="13" fill="#5d4037" stroke="#3e2723" stroke-width="2"/>')
cable_in(s, [(0, 3), (2, 3)], 214, "サーミスター 10kΩ", "（向きなし・巣箱の中へ）", thermo)
lx, ty, by = standing(s, [6, 7, 8, 9], ["SDA", "SCL", "GND", "VIN"], "", "", "#5e35b1", 150)
s.top.append(f'<rect x="{lx+30}" y="{(ty+by)/2-16}" width="32" height="32" rx="2" fill="#cfd8dc" stroke="#78909c"/>')
s.text(OX - 36, by + 22, "SHT40 モジュール", 14, "end", "bold", "#311b92", layer=s.top)
s.text(OX - 36, by + 40, "A 列に立てて付ける（図では倒して描いた）", 11, "end", fill="#555", layer=s.top)
s.text(OX - 36, by + 56, "ピンの並びは基板の印字で確認", 11, "end", fill="#b23", weight="bold", layer=s.top)
battery_below(s, 10, 12, 10)
yb = back_section(s, OY + (NR - 1) * P + 230, "HPX-TH_pcb.svg")
y = table(s, 60, yb + 40, [
    ["線", "どこから（穴）", "どこへ（穴）", "役目"],
    ["purple", f"XIAO D3（{hn(3,5)}）", f"10kΩ の下の足（{hn(2,5)}）", "測る瞬間だけ電気を流す"],
    ["yellow", f"XIAO D2/A2（{hn(3,4)}）", f"10kΩ の上の足（{hn(2,4)}）→ {hn(2,2)}", "測る点（C3〜C5）"],
    ["green", f"XIAO D4（{hn(3,6)}）", f"SHT40 SDA（{hn(0,6)}）", "データ（I2C）"],
    ["blue", f"XIAO D5（{hn(3,7)}）", f"SHT40 SCL（{hn(0,7)}）", "合図（I2C）"],
    ["black", f"{hn(0,3)}（サーミスター）", f"{hn(0,0)} → {hn(12,0)} → {hn(12,10)}（−の列）", f"0.1µF（{hn(0,2)}）もこの線"],
    ["black", f"SHT40 GND（{hn(0,8)}）", f"{hn(1,8)} → {hn(1,9)} → {hn(4,9)} → {hn(4,0)}", "XIAO の真下（裏）を通って上の黒線へ"],
    ["red", f"SHT40 VIN（{hn(0,9)}）", f"{hn(0,10)} → {hn(10,10)}（＋の列）", "SHT40 の電源"],
] + rail_rows(10, 12), [100, 260, 440, 360], "つなぐ線（穴の位置は 列の文字＋行の数字）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["Seeed XIAO BLE nRF52840 ＋ ピンヘッダ 7ピン×2（XIAO 側）＋ ピンソケット 7ピン×2（基板側）", "1", f"{hn(3,2)}〜{hn(9,8)}"],
    ["SHT40 モジュール（温湿度、I2C）＋ ピンヘッダ 4ピン", "1", f"{hn(0,6)}〜{hn(0,9)}（SDA・SCL・GND・VIN）に立てて付ける"],
    ["サーミスター 10kΩ（平行線タイプ）", "1", f"線を {hn(0,3)}（GND）・{hn(2,3)}（測る点）に直接はんだ付け"],
    ["抵抗 10kΩ 1%（金属皮膜）", "1", f"{hn(2,4)}・{hn(2,5)}（立てて付ける）"],
    ["積層セラミックコンデンサー 0.1µF 50V C0G 5mm", "1", f"{hn(0,2)}・{hn(2,2)}（測る点と GND の間）"],
    ["絶縁ラジアルリード型積層セラミックコンデンサー 100µF 6.3V 5mm", "1", f"{hn(10,6)}・{hn(12,6)}"],
    ["CR123A 用電池ケース ／ CR123A 電池", "各1", f"赤線を {hn(10,10)}（＋）、黒線を {hn(12,10)}（−）にはんだ付け"],
    ["ユニバーサル基板（穴 13×11 以上）・すずめっき線", "", "大きい基板を切って使ってもよい"],
], [560, 60, 540], "部品表")
finish(s, y, COMMON_NOTES + [
    f"・黒線 {hn(4,0)}〜{hn(4,9)} は XIAO の真下を通る。XIAO のピンは {COLS[3]} 列と {COLS[9]} 列だけなので触れない。",
    "・ファームは HPX_TH v1.01（D4/D5 に SHT40、D3→10kΩ→A2 にサーミスター）。SHT40 は 3.6V まで（5V にはつながない）。",
    "・SHT40 は印字の SDA が上、VIN が下になる向きに立てる。基板ごと巣箱の中の湿度を測りたい場所に置く。風の通るケースに。",
], "HPX-TH_pcb.svg")


# ================================================================== HPX-F (2026-11-08 に HPX-A から改名)
OX, OY = 300, 330           # 5×7cm 基板は大きいので左へ寄せる
s = S(1280, 2010)
title(s, "HPX-F（電柵アラート）基板の配線図", "ユニバーサル基板 5×7cm 版 ／ D1→10kΩ→D0(A0)、CdS セル D0–GND ／ 電源 単3リチウム×2")
board(s, 24, 18, "ユニバーサル基板 5×7cm（穴 A〜X × 1〜18）")
p = xiao_pcb(s, 2, 2)
P3, PG = p["3V3"], p["GND"]
wireh(s, [(2, 0), (11, 0), (11, 10)], "black")
wireh(s, [p["GND"], (11, 3)], "black")
wireh(s, [p["3V3"], (9, 4), (9, 10)], "red")
wireh(s, [p["D0"], (1, 2), (1, 0)], "yellow")
wireh(s, [p["D1"], (1, 3)], "purple")
res_stand(s, (1, 2), (1, 3), "10kΩ", ["#8d5524", "#212121", "#f57c00"], OX - 50, H(1, 4)[1] + 4, "end")
s.top.append(f'<line x1="{H(1,2)[0]-10}" y1="{H(1,2)[1]+10}" x2="{OX-46}" y2="{H(1,4)[1]}" stroke="#999" stroke-width="1.5" stroke-dasharray="3 3"/>')
def tag(x, y, t, size=13, anchor="start", fill="#333", w=None):
    w = w or len(t) * size + 10
    bx = x - 5 if anchor == "start" else x - w / 2
    s.top.append(f'<rect x="{bx}" y="{y-size-2}" width="{w}" height="{size+9}" rx="4" fill="#fffdf6" fill-opacity="0.95"/>')
    s.text(x, y, t, size, anchor, "bold", fill, layer=s.top)
cap_flat(s, (9, 6), (11, 6), "100µF", True)
cap_flat(s, (9, 8), (11, 8), "0.1µF")
tag(H(11, 6)[0] + 26, H(11, 6)[1] + 5, "100µF", w=52)
tag(H(11, 8)[0] + 26, H(11, 8)[1] + 5, "0.1µF", w=46)
# CdS セルの線は基板の上から B1・C1 へ（白→B1、黒→C1。向きはないので逆でも動く）
cx, cy = 120, 175
s.add(f'<circle cx="{cx}" cy="{cy}" r="20" fill="#f3e5ab" stroke="#8d6e63" stroke-width="3"/>')
s.add(f'<path d="M{cx-14},{cy-8} Q{cx},{cy-2} {cx+14},{cy-8} M{cx-14},{cy} Q{cx},{cy+6} {cx+14},{cy} M{cx-14},{cy+8} Q{cx},{cy+14} {cx+14},{cy+8}" stroke="#c62828" stroke-width="2" fill="none"/>')
for i, (h, col) in enumerate([((1, 0), "#fafafa"), ((2, 0), "#212121")]):
    x, y = H(*h)
    yy = cy - 6 + i * 12
    s.add(f'<path d="M{cx+20},{yy} L{x},{yy} L{x},{y}" fill="none" stroke="#555" stroke-width="6" stroke-linejoin="round"/>')
    s.add(f'<path d="M{cx+20},{yy} L{x},{yy} L{x},{y}" fill="none" stroke="{col}" stroke-width="4" stroke-linejoin="round"/>')
    solder_mark(s, *h)
s.text(60, 120, "CdS セル 5506", 15, weight="bold")
s.text(60, 138, "（向きなし・シールド線で延長）", 12, fill="#666")
s.text(60, 222, "白い線 → B1、黒い線 → C1", 12, fill="#333", weight="bold")
s.text(60, 240, "警報器の赤いカバーに向け、黒いもので遮光", 12, fill="#666")
x0, y0 = H(12, 1)
for c in (1, 2):   # CdS の線で隠れる列の文字を上に描き直す
    tag(H(c, 0)[0], OY - 26 - 12, COLS[c], 13, "middle", "#8d6e3f", w=16)
# 電池はケース WH145-25-M2 の電池ボックス（単3×2）。線を ＋の列・−の列の端に直接はんだ付け
tp, tm = H(9, 10), H(11, 10)
bx, by = tp[0] - 230, OY + 17 * P + 100
s.rect(bx, by, 300, 80, "#455a64", "#263238", 10, 3)
for i in range(2):
    s.rect(bx + 18 + i * 140, by + 18, 124, 44, "#c0ca33", "#827717", 20, 2)
    s.text(bx + 80 + i * 140, by + 46, "単3", 15, "middle", "bold", "#333")
s.text(bx + 14, by + 24, "＋", 16, "start", "bold", "#fff")
s.text(bx + 286, by + 24, "−", 18, "end", "bold", "#fff")
s.text(bx + 150, by + 106, "ケース WH145-25-M2 の電池ボックス ＋ 単3リチウム電池 ×2（直列 約3V）", 14, "middle", "bold")
s.wire([(bx, by + 40), (bx - 30, by + 40), (bx - 30, by - 30), (tp[0], by - 30), tp], "red")
s.wire([(bx + 300, by + 40), (bx + 330, by + 40), (bx + 330, by - 50), (tm[0], by - 50), tm], "black")
solder_mark(s, 9, 10); solder_mark(s, 11, 10)
yb = back_section(s, by + 140, "HPX-F_pcb.svg")
y = table(s, 60, yb + 40, [
    ["線", "どこから（穴）", "どこへ（穴）", "役目"],
    ["purple", f"XIAO D1（{hn(2,3)}）", f"10kΩ の下の足（{hn(1,3)}）", "読む瞬間だけ電気を流す"],
    ["yellow", f"XIAO D0/A0（{hn(2,2)}）", f"10kΩ の上の足（{hn(1,2)}）→ {hn(1,1)} → CdS（{hn(1,0)}）", "測る点"],
    ["black", f"CdS のもう片方（{hn(2,0)}）", f"{hn(11,0)} → {hn(11,10)}（−の列）", "上の端を通って −の列へ"],
] + rail_rows(9, 11), [100, 300, 420, 340], "つなぐ線（穴の位置は 列の文字＋行の数字）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["Seeed XIAO BLE nRF52840 ＋ ピンヘッダ 7ピン×2（XIAO 側）＋ ピンソケット 7ピン×2（基板側）", "1", f"{hn(2,2)}〜{hn(8,8)}"],
    ["CdS セル（フォトレジスタ）5506 φ5mm", "1", f"シールド線で延長して {hn(1,0)}（白）・{hn(2,0)}（黒）に直接はんだ付け"],
    ["抵抗 10kΩ", "1", f"{hn(1,2)}・{hn(1,3)}（立てて付ける）"],
    ["積層セラミックコンデンサー 0.1µF 50V C0G 5mm", "1", f"{hn(9,8)}・{hn(11,8)}（電源側。測る点には付けない）"],
    ["絶縁ラジアルリード型積層セラミックコンデンサー 100µF 6.3V 5mm", "1", f"{hn(9,6)}・{hn(11,6)}"],
    ["ケース WH145-25-M2（単3×2 電池ボックス付き）／ 単3リチウム電池", "1／2", f"電池ボックスの＋の線を {hn(9,10)}、−の線を {hn(11,10)} にはんだ付け"],
    ["ユニバーサル基板 5×7cm（穴 24×18）・すずめっき線", "1", "使うのは左上の A〜L・1〜11 だけ"],
], [560, 60, 540], "部品表")
finish(s, y, COMMON_NOTES + [
    "・フェンスアラート（StaFIX）の回路と XIAO を電線でつながない。光だけで読む（柵線の高電圧が回り込むため）。",
], "HPX-F_pcb.svg")

# ================================================================== HPX-TH 単三版（5×6cm 基板）
# TARO 2026-11-13: 秋月の両面スルーホール基板 5×7cm (穴 24×18) を 5×6cm に切って使う (穴 A〜U × 1〜18)。
# ケースは LC115H-M2-D、電池は単3リチウム×2 (ケースの電池室)。回路は小さい基板版と同じ。
OX, OY = 300, 270
s = S(1280, 2100)
title(s, "HPX-TH（温湿度センサ）単三版 基板の配線図", "基板 5×6cm（穴 A〜U × 1〜18）／ SHT40: D4・D5 ／ D3→10kΩ→A2 ／ 単3リチウム×2")
board(s, 21, 18, "ユニバーサル基板 5×6cm（秋月 5×7cm を切る。穴 A〜U × 1〜18）")
p = xiao_pcb(s, 9, 4)
P3, PG = p["3V3"], p["GND"]
CP, CM = 17, 19             # ＋の列 R、−の列 T
# 電源の列
wireh(s, [p["3V3"], (CP, 6), (CP, 8), (CP, 13)], "red")
wireh(s, [p["GND"], (CM, 5)], "black")
wireh(s, [(5, 10), (5, 1), (6, 1), (CM, 1), (CM, 5), (CM, 8), (CM, 13)], "black")
wireh(s, [(6, 10), (5, 10)], "black")
wireh(s, [(6, 4), (6, 3), (6, 2), (6, 1)], "black")
wireh(s, [(6, 11), (6, 12), (CP, 12)], "red")
# サーミスター・10kΩ・0.1µF (測る点は I 列)
wireh(s, [p["D2"], (8, 6), (8, 4), (8, 3)], "yellow")
wireh(s, [p["D3"], (8, 7)], "purple")
wireh(s, [p["D4"], (6, 8)], "green")
wireh(s, [p["D5"], (6, 9)], "blue")
res_stand(s, (8, 6), (8, 7), "10kΩ 1%", ["#8d5524", "#212121", "#212121"], H(8, 13)[0] + 24, H(8, 13)[1] + 4, "middle")
s.top.append(f'<line x1="{H(8,7)[0]}" y1="{H(8,7)[1]+6}" x2="{H(8,13)[0]+20}" y2="{H(8,13)[1]-14}" stroke="#999" stroke-width="1.5" stroke-dasharray="3 3"/>')
cap_flat(s, (6, 4), (8, 4), "0.1µF")
s.text(H(7, 4)[0], H(7, 4)[1] - 18, "0.1µF", 12, "middle", "bold", "#1a237e", layer=s.top)
cap_flat(s, (CP, 8), (CM, 8), "100µF", True)
s.text(H(18, 8)[0], H(18, 8)[1] - 22, "100µF", 13, "middle", "bold", "#333", layer=s.top)
# サーミスターの線 (基板の外から): GND=G3、測る点=I4
def thermo2(x, y):
    s.add(f'<ellipse cx="{x-50}" cy="{y}" rx="18" ry="13" fill="#5d4037" stroke="#3e2723" stroke-width="2"/>')
cable_in(s, [(6, 2), (8, 3)], 300, "サーミスター 10kΩ", "（向きなし・巣箱の中へ）", thermo2)
# SHT40 は寝かせて付ける (ピン G9〜G12、本体は左)
x6, y8 = H(6, 8); _, y11 = H(6, 11)
s.top.append(f'<rect x="{x6-170}" y="{y8-24}" width="186" height="{y11-y8+48}" rx="6" fill="#5e35b1" fill-opacity="0.88" stroke="#333" stroke-width="2"/>')
s.top.append(f'<rect x="{x6-130}" y="{(y8+y11)/2-16}" width="32" height="32" rx="2" fill="#cfd8dc" stroke="#78909c"/>')
rec_part(x6 - 170, y8 - 24, 186, y11 - y8 + 48, "SHT40")
for i, n in enumerate(["SDA", "SCL", "GND", "VIN"]):
    x, y = H(6, 8 + i)
    rec_pin(x, y, n)
    s.top.append(f'<circle cx="{x}" cy="{y}" r="8" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
    s.text(x - 14, y + 5, n, 12, "end", "bold", "#fff", layer=s.top)
s.text(x6 - 77, y11 + 46, "SHT40 モジュール", 14, "middle", "bold", "#311b92", layer=s.top)
s.text(x6 - 77, y11 + 64, "寝かせて付ける。ピンの並びは印字で確認", 11, "middle", fill="#b23", weight="bold", layer=s.top)
# 電池は LC115H-M2-D の電池室 (単3×2)。線を ＋の列・−の列の端に直接はんだ付け
tp, tm = H(CP, 13), H(CM, 13)
bx, by = tp[0] - 330, OY + 17 * P + 100
s.rect(bx, by, 300, 80, "#455a64", "#263238", 10, 3)
for i in range(2):
    s.rect(bx + 18 + i * 140, by + 18, 124, 44, "#c0ca33", "#827717", 20, 2)
    s.text(bx + 80 + i * 140, by + 46, "単3", 15, "middle", "bold", "#333")
s.text(bx + 14, by + 24, "＋", 16, "start", "bold", "#fff")
s.text(bx + 286, by + 24, "−", 18, "end", "bold", "#fff")
s.text(bx + 150, by + 106, "ケース LC115H-M2-D の電池室（単3×2）＋ 単3リチウム電池 ×2（直列 約3V）", 14, "middle", "bold")
s.wire([(bx, by + 40), (bx - 30, by + 40), (bx - 30, by - 30), (tp[0], by - 30), tp], "red")
s.wire([(bx + 300, by + 40), (bx + 330, by + 40), (bx + 330, by - 50), (tm[0], by - 50), tm], "black")
solder_mark(s, CP, 13); solder_mark(s, CM, 13)
yb = back_section(s, by + 140, "HPX-TH_AA_pcb.svg")
y = table(s, 60, yb + 40, [
    ["線", "どこから（穴）", "どこへ（穴）", "役目"],
    ["purple", f"XIAO D3（{hn(*p['D3'])}）", f"10kΩ の下の足（{hn(8,7)}）", "測る瞬間だけ電気を流す"],
    ["yellow", f"XIAO D2/A2（{hn(*p['D2'])}）", f"10kΩ の上の足（{hn(8,6)}）→ {hn(8,4)} → {hn(8,3)}", f"測る点（0.1µF {hn(8,4)}・サーミスター {hn(8,3)}）"],
    ["green", f"XIAO D4（{hn(*p['D4'])}）", f"SHT40 SDA（{hn(6,8)}）", "データ（I2C）"],
    ["blue", f"XIAO D5（{hn(*p['D5'])}）", f"SHT40 SCL（{hn(6,9)}）", "合図（I2C）"],
    ["black", f"SHT40 GND（{hn(6,10)}）", f"{hn(5,10)} → {hn(5,1)} → {hn(CM,1)} → {hn(CM,13)}（−の列）", "SHT40 の下を通り、上の端から −の列へ"],
    ["black", f"0.1µF（{hn(6,4)}）", f"{hn(6,3)} → {hn(6,1)}（上の黒線）", f"サーミスター（{hn(6,2)}）の GND もこの線"],
    ["red", f"SHT40 VIN（{hn(6,11)}）", f"{hn(6,12)} → {hn(CP,12)}（＋の列）", "SHT40 の電源。XIAO の下側を通る"],
    ["red", f"XIAO 3V3（{hn(*P3)}）", f"{hn(CP,6)} → {hn(CP,13)}（＋の列）", "電池＋・コンデンサーもこの列"],
    ["black", f"XIAO GND（{hn(*PG)}）", f"{hn(CM,5)}（−の列 {hn(CM,1)}〜{hn(CM,13)}）", "電池−・コンデンサーもこの列"],
], [100, 260, 440, 360], "つなぐ線（穴の位置は 列の文字＋行の数字）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["Seeed XIAO BLE nRF52840（足付き）＋ 分割ロングピンソケット 7ピン×2（基板側）", "1", f"{hn(9,4)}〜{hn(15,10)}"],
    ["SHT40 モジュール（温湿度、I2C）＋ ピンヘッダ 4ピン", "1", f"{hn(6,8)}〜{hn(6,11)}（SDA・SCL・GND・VIN）。本体は左へ寝かせる"],
    ["サーミスター 10kΩ（平行線タイプ）", "1", f"線を {hn(6,2)}（GND）・{hn(8,3)}（測る点）に直接はんだ付け"],
    ["抵抗 10kΩ 1%（金属皮膜）", "1", f"{hn(8,6)}・{hn(8,7)}（立てて付ける）"],
    ["積層セラミックコンデンサー 0.1µF 50V C0G 5mm", "1", f"{hn(6,4)}・{hn(8,4)}（測る点と GND の間）"],
    ["絶縁ラジアルリード型積層セラミックコンデンサー 100µF 6.3V 5mm", "1", f"{hn(CP,8)}・{hn(CM,8)}"],
    ["ケース LC115H-M2-D の電池室 ／ 単3リチウム電池", "1／2", f"赤線を {hn(CP,13)}（＋）、黒線を {hn(CM,13)}（−）にはんだ付け"],
    ["両面スルーホールユニバーサル基板 5×7cm（秋月）・すずめっき線", "1", "5×6cm（穴 A〜U × 1〜18）に切って使う"],
    ["基板スペーサー タカチ ULCK-6.4（クイックロック貼付スペーサー）", "4", "基板の四すみに差し、底の両面テープでケースに貼る"],
], [560, 60, 540], "部品表")
XIAO_NOTE_LONG = "・基板には分割ロングピンソケット（7ピン×2、1×42 を切る）をはんだ付けし、XIAO の長い足を差し込む。XIAO を抜いて交換できる。"
finish(s, y, [XIAO_NOTE_LONG if "ピンヘッダ（オス）" in n else n for n in COMMON_NOTES] + [
    "・基板は秋月の 5×7cm（穴 24×18）の端の 3列（V・W・X）を切り落として 5×6cm にする。切り口はやすりでなめらかに。",
    "・ケースへは、基板の四すみに貼付スペーサー ULCK-6.4 を差し込み、底の両面テープでケースの底に貼って留める（ケースのネジの柱は使わない）。差す穴の大きさは実物で確認。",
    f"・黒線 {hn(5,1)}〜{hn(5,10)} は SHT40 の下（裏）を通る。SHT40 のピンは {COLS[6]} 列だけなので触れない。",
    "・ファームは HPX_TH v1.01。SHT40 は 3.6V まで（5V にはつながない）。SHT40 の上は、ケースの中でも空気が通るようにする。",
], "HPX-TH_AA_pcb.svg")

# ================================================================== HPX-Core 有線LAN（6×8cm 基板）
OX, OY = 260, 200
s = S(1500, 3000)
title(s, "【ELEGOO で作るとき】HPX-Core ＋有線LAN 基板の配線図", "6×8cm 縦置き ／ ELEGOO ESP-32 ＋ W5500 ／ ファーム v1.25")
board(s, 25, 33, "ユニバーサル基板 6×8cm を縦に置いたところ（穴 A〜Y × 1〜33。写真の向きから右へ90度回す）")

def R(n):   # 行の番号（1〜33）→ 図の行（0〜）
    return n - 1

def module(c1, c2, rtop, rbot, fill, stroke, label, sub=None):
    """モジュールの外形（穴の単位。半端な値でよい）"""
    x1, y1 = OX + c1 * P, OY + rtop * P
    x2, y2 = OX + c2 * P, OY + rbot * P
    s.top.append(f'<rect x="{x1}" y="{y1}" width="{x2-x1}" height="{y2-y1}" rx="10" fill="{fill}" fill-opacity="0.62" stroke="{stroke}" stroke-width="3"/>')
    rec_part(x1, y1, x2 - x1, y2 - y1, label)
    return x1, y1, x2, y2

def pins(col, first_row, names, side, used):
    p = {}
    for i, n in enumerate(names):
        c, r = col, R(first_row + i)
        x, y = H(c, r)
        p[n] = (c, r)
        rec_pin(x, y, n)
        on = n in used
        s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="{"#d4af37" if on else "#9e9e9e"}" stroke="#5d4520" stroke-width="2"/>')
        dx = -14 if side == "R" else 14
        s.text(x + dx, y + 5, n.rstrip("0123456789") if len(n) <= 3 and n[0] in "VG" else n, 13, "end" if side == "R" else "start", "bold", "#fff" if on else "#ddd", layer=s.top)
    return p

# ESP-32（USB-C が下）。ピン列は B 列と L 列（10穴離れている）、14〜28 行
EL = ["EN", "VP", "VN", "D34", "D35", "D32", "D33", "D25", "D26", "D27", "D14", "D12", "D13", "GND", "VIN"]
ER = ["D23", "D22", "TX0", "RX0", "D21", "D19", "D18", "D5", "TX2", "RX2", "D4", "D2", "D15", "GND", "3V3"]
x1, y1, x2, y2 = module(1 - 0.47, 11 + 0.47, R(14) - 2.3, R(28) + 4.6, "#212121", "#000", "ESP-32")
s.top.append(f'<rect x="{(x1+x2)/2-60}" y="{y1+30}" width="120" height="150" rx="4" fill="#b0bec5" fill-opacity="0.8" stroke="#78909c"/>')
s.text((x1 + x2) / 2, y1 + 100, "ESP32-", 15, "middle", "bold", "#263238", layer=s.top)
s.text((x1 + x2) / 2, y1 + 120, "WROOM-32", 15, "middle", "bold", "#263238", layer=s.top)
s.top.append(f'<rect x="{(x1+x2)/2-34}" y="{y2-20}" width="68" height="44" rx="8" fill="#bdbdbd" stroke="#616161" stroke-width="2"/>')
s.text((x1 + x2) / 2, y2 + 46, "USB-C（下向き）", 13, "middle", "bold", "#333", layer=s.top)
s.text((x1 + x2) / 2, y1 + 230, "ELEGOO ESP-32", 16, "middle", "bold", "#fff", layer=s.top)
s.text((x1 + x2) / 2, y1 + 252, "ピンソケット 15ピン×2 に差す", 12, "middle", "bold", "#eee", layer=s.top)
pe_l = pins(1, 14, EL, "L", {"D25", "D26", "D14", "D13"})
pe_r = pins(11, 14, ER, "R", {"D23", "D19", "GND", "3V3"})

# W5500（LAN 差し込み口が下）。ピン列は N 列と V 列（8穴離れている）、22〜27 行（TARO 2026-11-13: ファーム v1.25 のピンに合わせて FireBeetle の図と同じ行に）
x1, y1, x2, y2 = module(13 - 0.53, 21 + 0.53, R(22) - 0.5, R(22) - 0.5 + 9.84, "#1565c0", "#0d3c7a", "W5500")
s.top.append(f'<rect x="{(x1+x2)/2-34}" y="{y1+30}" width="68" height="68" rx="3" fill="#212121" fill-opacity="0.85"/>')
s.text((x1 + x2) / 2, y1 + 70, "W5500", 13, "middle", "bold", "#eee", layer=s.top)
s.top.append(f'<rect x="{(x1+x2)/2-80}" y="{y2-150}" width="160" height="170" rx="6" fill="#cfd8dc" fill-opacity="0.75" stroke="#607d8b" stroke-width="2"/>')
s.text((x1 + x2) / 2, y2 - 60, "LAN 差し込み口", 13, "middle", "bold", "#37474f", layer=s.top)
s.text((x1 + x2) / 2, y2 + 46, "LAN ケーブル（下へ）", 13, "middle", "bold", "#333", layer=s.top)
s.text((x1 + x2) / 2, y1 + 120, "W5500 モジュール", 14, "middle", "bold", "#fff", layer=s.top)
s.text((x1 + x2) / 2, y1 + 140, "ピンソケット 6ピン×2 に差す", 12, "middle", "bold", "#eee", layer=s.top)
WL = ["MI", "RST", "NC", "V", "V2", "G"]       # 上から（下から G, V, V, NC, RST, MI）
WR = ["INT", "CS", "SCK", "MO", "G2", "G3"]     # 上から（下から G, G, MO, SCK, CS, INT）
pw_l = pins(13, 22, WL, "L", {"MI", "RST", "V2", "G"})
pw_r = pins(21, 22, WR, "R", {"INT", "CS", "SCK", "MO"})

def Lc(ch):
    return COLS.index(ch)

def w(color, *holes):
    wireh(s, [(Lc(h[0]), R(int(h[1:]))) for h in holes], color)

# TARO 2026-11-13: ファーム v1.25（FireBeetle と同じピン）に合わせた線。左の列の 4本は ESP-32 の下（裏）を通って下へ回る
EL_LAN = [
    ("purple", "D25 = GPIO25（B21）", ["B21", "F21", "F29", "P29", "P22", "V22"], "INT（V22）"),
    ("orange", "D26 = GPIO26（B22）", ["B22", "E22", "E30", "Q30", "Q23", "V23"], "CS（V23）"),
    ("yellow", "D14 = GPIO14・SCK（B24）", ["B24", "D24", "D31", "R31", "R24", "V24"], "SCK（V24）"),
    ("blue", "D13 = GPIO13・MOSI（B26）", ["B26", "C26", "C32", "S32", "S25", "V25"], "MO（V25）"),
    ("green", "D19 = GPIO19・MISO（L19）", ["L19", "M19", "M22", "N22"], "MI（N22）"),
    ("white", "D23 = GPIO23（L14）", ["L14", "O14", "O23", "N23"], "RST（N23）"),
    ("black", "GND（右の列 L27）", ["L27", "M27", "N27"], "G（N27）"),
    ("red", "3V3（L28）", ["L28", "M28", "N28", "O28", "O26", "N26"], "V（N26）"),
]
for c, a_, hs, b_ in EL_LAN:
    w(c, *hs)
yb = back_section(s, OY + (NR - 1) * P + 160, "HPX-Core_LAN_elegoo_pcb.svg")
y = table(s, 60, yb + 40, [["線", "ESP-32 のピン（穴）", "通る穴", "W5500 のピン（穴）"]] + [[c, a_, " → ".join(hs), b_] for c, a_, hs, b_ in EL_LAN], [100, 320, 420, 380], "つなぐ線（穴の位置は 列の文字＋行の数字）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["ELEGOO ESP-32 開発ボード（USB-C・CP2102、30ピン）", "1", "B14〜B28・L14〜L28。USB-C を下に向ける"],
    ["ピンソケット（メス）15ピン", "2", "B14〜B28、L14〜L28 にはんだ付けして ESP-32 を差す"],
    ["W5500 イーサネットモジュール（ACEIRMC、WIZ820io と同じピン）", "1", "N22〜N27・V22〜V27。LAN 差し込み口を下に向ける"],
    ["ピンソケット（メス）6ピン", "2", "N22〜N27、V22〜V27 にはんだ付けして W5500 を差す"],
    ["ユニバーサル基板 6×8cm（両面スズメッキ、穴 25×33）・すずめっき線", "1", "縦に置く。線はすべて裏"],
], [620, 60, 520], "部品表")
finish(s, y, [
    "・上の図は部品を載せる面（表）から見たところ。ピンソケットは表から差し、足を裏ではんだ付けする。",
    "・線はすべて裏（はんだ面）で、すずめっき線を穴から穴へはんだ付けしてつなぐ。上の図では点線で透かして描き、下の「裏から見た図」が実際に配線する向き（左右が逆）。",
    "・裏の線どうしは交わらず、ほかのピンの穴の上も通らない。ESP-32・W5500 の下を通る線は、裏なので部品には触れない。",
    "・灰色のピンは使わない（W5500 の NC、もう1本の V、右の列の G 2本）。",
    "・ピンは FireBeetle 2 の図と同じ（ファーム HPX_Core v1.25 以降）: INT＝D25、CS＝D26、SCK＝D14、MOSI＝D13、MISO＝D19、RST＝D23。",
    "・左の列の 4本（D25・D26・D14・D13）は ESP-32 の下（裏）を通って下の 29〜32 行へ回し、W5500 の右の列へつなぐ。",
    "・2026-11-12 までのこの図（W5500 が 24〜29 行、MISO＝D4 など）で組んだものは、ファーム v1.24 までを使う。",
    "・ESP-32 の左右のピン列は10穴、W5500 の左右のピン列は8穴離れているとして描いた（ESP-32 は写真から、W5500 は幅23mm から計算）。差す前に実物で確かめる。",
    "・W5500 のピンがモジュールの上下のどこにあるかは分からない（実物で確かめる）。",
], "HPX-Core_LAN_elegoo_pcb.svg")

# ================================================================== HPX-Core 基本モデル（＋有線LAN から W5500 を外したもの）
# TARO 2026-11-10: 基本モデル (Wi-Fi＋USB給電) は「＋有線LAN」の基板から W5500 とその線を外して作る。ESP-32 の位置は同じ。
OX, OY = 260, 200
s = S(1500, 3000)
title(s, "【ELEGOO で作るとき】HPX-Core 基本モデル 基板の配線図", "6×8cm 縦置き ／ ELEGOO ESP-32 ／ Wi-Fi ＋ USB 給電")
board(s, 25, 33, "ユニバーサル基板 6×8cm を縦に置いたところ（「＋有線LAN」と同じ基板・同じ位置。W5500 は付けない）")
x1, y1, x2, y2 = module(1 - 0.47, 11 + 0.47, R(14) - 2.3, R(28) + 4.6, "#212121", "#000", "ESP-32")
s.top.append(f'<rect x="{(x1+x2)/2-60}" y="{y1+30}" width="120" height="150" rx="4" fill="#b0bec5" fill-opacity="0.8" stroke="#78909c"/>')
s.text((x1 + x2) / 2, y1 + 100, "ESP32-", 15, "middle", "bold", "#263238", layer=s.top)
s.text((x1 + x2) / 2, y1 + 120, "WROOM-32", 15, "middle", "bold", "#263238", layer=s.top)
s.top.append(f'<rect x="{(x1+x2)/2-34}" y="{y2-20}" width="68" height="44" rx="8" fill="#bdbdbd" stroke="#616161" stroke-width="2"/>')
s.text((x1 + x2) / 2, y2 + 46, "USB-C（下向き）", 13, "middle", "bold", "#333", layer=s.top)
s.text((x1 + x2) / 2, y1 + 230, "ELEGOO ESP-32", 16, "middle", "bold", "#fff", layer=s.top)
s.text((x1 + x2) / 2, y1 + 252, "ピンソケット 15ピン×2 に差す", 12, "middle", "bold", "#eee", layer=s.top)
pins(1, 14, EL, "L", set())
pins(11, 14, ER, "R", set())
# W5500 を付けない場所 (将来 ＋有線LAN にするときはここに差す)
_x1, _y1 = OX + (13 - 0.53) * P, OY + (R(22) - 0.5) * P
s.top.append(f'<rect x="{_x1}" y="{_y1}" width="{9.06*P}" height="{9.84*P}" rx="10" fill="none" stroke="#9e9e9e" stroke-width="2.5" stroke-dasharray="10 7"/>')
s.text(_x1 + 4.53 * P, _y1 + 4.4 * P, "空き（＋有線LAN では", 13, "middle", "bold", "#757575", layer=s.top)
s.text(_x1 + 4.53 * P, _y1 + 5.2 * P, "ここに W5500 を差す）", 13, "middle", "bold", "#757575", layer=s.top)
yb = back_section(s, OY + (NR - 1) * P + 160, "HPX-Core_BASIC_elegoo_pcb.svg")
y = table(s, 60, yb + 40, [
    ["部品", "数", "置き場所・メモ"],
    ["ELEGOO ESP-32 開発ボード（USB-C・CP2102、30ピン）", "1", "B14〜B28・L14〜L28。USB-C を下に向ける"],
    ["ピンソケット（メス）15ピン", "2", "B14〜B28、L14〜L28 にはんだ付けして ESP-32 を差す"],
    ["ユニバーサル基板 6×8cm（両面スズメッキ、穴 25×33）", "1", "縦に置く。ケース PFF10-4-10W に AST2.5-5B で留める"],
], [620, 60, 520], "部品表")
finish(s, y, [
    "・基本モデルは、ルーターへ Wi-Fi でつなぎ、ESP-32 の USB-C から電気をもらいます。基板の上の線はありません（ピンソケットのはんだ付けだけ）。",
    "・「＋有線LAN」と同じ基板・同じ位置です。あとから W5500 とピンソケット 6ピン×2・線8本を足せば「＋有線LAN」にできます（HPX-Core_LAN_pcb.png）。",
    "・ファームウェアは HPX_Core v1.25 を使い、初回設定で接続方法を「Wi-Fi」にします。",
    "・ESP-32 の左右のピン列は10穴離れているとして描いた（写真から）。差す前に実物で確かめる。",
    "・雨のかかる屋外に置くときは、防水の版（＋防水）を使ってください。",
], "HPX-Core_BASIC_elegoo_pcb.svg")

# ================================================================== HPX-Core ＋防水＆電池駆動（タカチ PCB36-47）
# TARO 2026-11-08: PCB36-47 に Olimex ESP32-DEVKIT-LIPO を載せ、単三リチウム3本（ケース WH145-25-M3 の電池ボックス）から 5V 端子へ。
# Olimex のピンの並びは公式資料（GitHub OLIMEX/ESP32-DevKit-LiPo Rev.D、ESP32-DevKitC と同じ）。左右のピン列は 25.4mm（10穴）離れている。
# TARO 2026-11-14: 基板を横に置く（47×36mm、穴 17×13 の見込み）。Olimex は縦（USB が下）。
#   穴の名前は「行の文字（下から A〜M）＋列の数字（左から 1〜17）」。Olimex の 5V（左の列のいちばん下）を A4 に置く。
#   右の取付穴2つでケースに留める。Olimex の上（ピン1〜6）は基板の上へはみ出す。
OX, OY = 430, 480
s = S(1500, 3000)
title(s, "【Olimex で作るとき】HPX-Core ＋防水＆電池駆動の配線図", "PCB36-47（横置き）／ Olimex ESP32-DEVKIT-LIPO（縦）／ 単三リチウム ×3 → 5V 端子")
BC, BR = 17, 13          # 列（横）17・行（縦）13
def bh(name):            # "A4" → (列, 図の行)。行の文字 A がいちばん下
    return (int(name[1:]) - 1, BR - 1 - (ord(name[0]) - 65))
def bname(c, r):
    return f"{chr(65 + BR - 1 - r)}{c + 1}"
board(s, BC, BR, " ")
# board() が付けた見出し（上の文字・右の数字）を、この図の呼び方（左に行の文字・下に列の数字）に描き直す
REC["board"] = [e for e in REC["board"] if not e.startswith("<text")]
s.items = [e for e in s.items if not (e.startswith("<text") and float(_re.search(r'y="([-\d.]+)"', e).group(1)) > 100)]
for r in range(BR):
    s.text(OX - 48, OY + r * P + 5, chr(65 + BR - 1 - r), 13, "end", fill="#8d6e3f", weight="bold")
for c in range(BC):
    s.text(OX + c * P, OY + (BR - 1) * P + 56, str(c + 1), 13, "middle", fill="#8d6e3f", weight="bold")
REC["board"] = REC["board"] + s.items[-(BR + BC):]
s.text(OX + 8 * P, OY - 300, "ケース用基板 PCB36-47（47×36mm）を横に置いたところ。穴 17×13 は見込み、四隅は取付穴（右の2つでケースに留める）", 15, "middle", "bold", "#5d4520")
i0 = len(s.items)
for c, r in ((0, 0), (16, 0), (0, 12), (16, 12)):   # 四隅は取付穴（その位置の穴は使わない）
    x, y = H(c, r)
    use = c == 16
    s.add(f'<circle cx="{x}" cy="{y}" r="15" fill="#fffdf6" stroke="{"#1565c0" if use else "#8d6e3f"}" stroke-width="{3 if use else 2}"/>')
    s.add(f'<circle cx="{x}" cy="{y}" r="7" fill="{"#90caf9" if use else "#c8a165"}" stroke="#8d6e3f" stroke-width="1.5"/>')
REC["board"] = REC["board"] + s.items[i0:]
for r in (0, 12):
    x, y = H(16, r)
    s.text(x + 60, y + 5, "ネジでケースに留める", 13, "start", "bold", "#1565c0")
    x, y = H(0, r)
    s.text(x - 76, y + 5, "使わない", 12, "end", "bold", "#8d6e3f")

# Olimex（縦、USB が下）。左の列は 4 列目、右の列は 14 列目。ピン19（5V）を A4 に置く → ピン1〜6 は基板の上の外にはみ出す
OL = ["3V3", "EN", "IO36", "IO39", "IO34", "IO35", "IO32", "IO33", "IO25", "IO26", "IO27", "IO14", "IO12", "GND", "IO13", "IO9", "IO10", "IO11", "5V"]
OR = ["GND", "IO23", "IO22", "IO1", "IO3", "IO21", "GND", "IO19", "IO18", "IO5", "IO17", "IO16", "IO4", "IO0", "IO2", "IO15", "IO8", "IO7", "IO6"]
c5, r5 = bh("A4")
rtop = r5 - 18                          # ピン1 の図の行（負＝基板の外）
x1, y1, x2, y2 = module(c5 - 0.5, c5 + 10.5, rtop - 0.5, r5 + 0.5, "#c62828", "#7f0000", "Olimex")
cx = (x1 + x2) / 2
s.top.append(f'<rect x="{cx-80}" y="{y1-34}" width="160" height="60" rx="4" fill="#212121" fill-opacity="0.85"/>')
s.text(cx, y1 - 8, "アンテナ（上）", 12, "middle", "bold", "#eee", layer=s.top)
s.top.append(f'<rect x="{cx-70}" y="{y1+30}" width="140" height="160" rx="4" fill="#b0bec5" fill-opacity="0.85" stroke="#78909c"/>')
s.text(cx, y1 + 105, "ESP32-", 15, "middle", "bold", "#263238", layer=s.top)
s.text(cx, y1 + 125, "WROOM-32E", 15, "middle", "bold", "#263238", layer=s.top)
s.text(cx, y1 + 250, "Olimex", 16, "middle", "bold", "#fff", layer=s.top)
s.text(cx, y1 + 272, "ESP32-DEVKIT-LIPO", 14, "middle", "bold", "#fff", layer=s.top)
s.text(cx, y1 + 294, "ピンソケット 13ピン×2 に差す", 12, "middle", "bold", "#eee", layer=s.top)
yedge = OY - 26 - 6
s.top.append(f'<path d="M{x1-150},{yedge-40} L{x1-30},{yedge-40} L{x1-30},{yedge} L{x1-6},{yedge}" fill="none" stroke="#5d4520" stroke-width="2"/>')
s.text(x1 - 36, yedge - 66, "ここから上は基板の外", 12, "end", "bold", "#5d4520", layer=s.top)
s.text(x1 - 36, yedge - 48, "（ピン1〜6・約17mm はみ出す）", 12, "end", "bold", "#5d4520", layer=s.top)
s.top.append(f'<rect x="{cx-30}" y="{y2-26}" width="60" height="40" rx="6" fill="#bdbdbd" stroke="#616161" stroke-width="2"/>')
s.text(cx, y2 + 64, "micro USB（下向き。電池版では使わない）", 13, "middle", "bold", "#333", layer=s.top)
bx = x1 + (151.384 - 129.54) / 2.54 * P
s.top.append(f'<rect x="{bx-26}" y="{y2-44}" width="52" height="40" rx="4" fill="#fafafa" stroke="#9e9e9e" stroke-width="2"/>')
s.text(bx, y2 - 52, "BAT", 12, "middle", "bold", "#fff", layer=s.top)
s.text(bx + 60, y2 + 88, "↑ BAT コネクタ：乾電池はつながない", 13, "middle", "bold", "#c62828", layer=s.top)
def opins(col, side):
    names = OL if side == "L" else OR
    for i, n in enumerate(names):
        r = rtop + i
        x, y = H(col, r)
        on = side == "L" and (n == "5V" or (n == "GND" and i == 13))
        if r >= 0:
            rec_pin(x, y, n)
        s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="{"#d4af37" if on else "#9e9e9e"}" stroke="#5d4520" stroke-width="2"/>')
        dx = 14 if side == "L" else -14
        s.text(x + dx, y + 5, n, 12, "start" if side == "L" else "end", "bold", "#fff" if on else "#eee", layer=s.top)
opins(c5, "L"); opins(c5 + 10, "R")
P5V, PGND = "A4", bname(c5, rtop + 13)        # 5V（ピン19）と GND（左の列のピン14）
LP, LM = "A3", bname(c5 - 1, rtop + 13)       # 電池の線をはんだ付けする穴（Olimex の左どなり）

# 電池ボックス（ケース WH145-25-M3 の単3×3）。＋の線を A3、−の線を F3 に直接はんだ付けし、裏で A4（5V）・F4（GND）へ
tp, tm = H(*bh(LP)), H(*bh(LM))
bx0, by0 = 60, OY + (BR - 1) * P + 170
s.rect(bx0, by0, 440, 80, "#455a64", "#263238", 10, 3)
for i in range(3):
    s.rect(bx0 + 18 + i * 140, by0 + 18, 124, 44, "#c0ca33", "#827717", 20, 2)
    s.text(bx0 + 80 + i * 140, by0 + 46, "単3", 15, "middle", "bold", "#333")
s.text(bx0 + 426, by0 + 24, "＋", 16, "end", "bold", "#fff")
s.text(bx0 + 14, by0 + 24, "−", 18, "start", "bold", "#fff")
s.text(bx0 + 220, by0 + 106, "ケース WH145-25-M3 の電池ボックス ＋ 単三リチウム電池 ×3（直列 約4.5〜5.4V）", 14, "middle", "bold")
s.wire([(bx0 + 440, by0 + 40), (bx0 + 470, by0 + 40), (bx0 + 470, tp[1] + 70), (tp[0], tp[1] + 70), tp], "red")
s.wire([(bx0, by0 + 40), (bx0 - 30, by0 + 40), (bx0 - 30, tm[1]), tm], "black")
solder_mark(s, *bh(LP)); solder_mark(s, *bh(LM))
wireh(s, [bh(LP), bh(P5V)], "red")      # 電池＋ → 5V（ピン19）
wireh(s, [bh(LM), bh(PGND)], "black")   # 電池− → GND（左の列のピン14）
check_back("HPX-Core_BAT_olimex_pcb.svg")
yb = backview(s, by0 + 140, OX + (BC - 1) * P / 2, y1 - 90, OY + (BR - 1) * P + 70)
y = table(s, 60, yb + 40, [
    ["線", "どこから（穴）", "どこへ（穴）", "役目"],
    ["red", f"電池ボックスの＋の線（{LP} に直接はんだ付け）", f"裏で {LP} → {P5V}（Olimex の 5V、左の列のいちばん下）", "電池の＋を 5V 端子へ"],
    ["black", f"電池ボックスの−の線（{LM} に直接はんだ付け）", f"裏で {LM} → {PGND}（Olimex の GND、左の列の下から6本目）", "電池の−を GND へ"],
], [100, 400, 460, 240], "つなぐ線（穴の位置は 行の文字＋列の数字。行は下から A〜M、列は左から 1〜17）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["Olimex ESP32-DEVKIT-LIPO（micro USB、ピンヘッダ付き）", "1", "A4〜M4・A14〜M14 のソケットに差す。USB を下、アンテナを上に向ける（縦）"],
    ["ピンソケット（メス）13ピン（20ピンを切る）", "2", "A4〜M4、A14〜M14 にはんだ付け。Olimex のピン7〜19 が入る"],
    ["ケース WH145-25-M3-BR（単3×3 電池ボックス付き）／ 単三リチウム電池", "1／3", f"＋の線を {LP}、−の線を {LM} にはんだ付け"],
    ["ケース用基板 PCB36-47・基板スペーサー AST2.5-5B・すずめっき線", "1", "横に置き、右の取付穴2つでケースに留める。線は裏の2本だけ"],
], [600, 60, 540], "部品表")
finish(s, y, [
    "・上の図は部品を載せる面（表）から見たところ。ピンソケットは表から差し、足を裏ではんだ付けする。下の「裏から見た図」が実際に配線する向き（左右が逆）。",
    "・基板は横向き（47mm が横）。穴の名前は「行の文字（下から A〜M）＋列の数字（左から 1〜17）」。Olimex の 5V（左の列のいちばん下）が A4 に来るように差す。",
    "・基板の右の取付穴2つだけでケースに留める。左の2つは使わない。",
    "・Olimex は長さ 48mm で、基板の高さ（36mm）より長い。ピン1〜6（3V3・EN・IO36・IO39・IO34・IO35 と GND・IO23・IO22・IO1・IO3・IO21）とアンテナの側は、",
    "　基板の上の端から約17mm はみ出す。ケースの中でぶつからないか確かめる。",
    "・電池は Olimex の「5V」端子（左の列のいちばん下）と「GND」へつなぐ。基板の「BAT」コネクタは充電式リチウムイオン電池用なので、乾電池は絶対につながない。",
    "・電池を入れたまま micro USB をつながない（書き込みは電池を外してから）。USB の 5V と電池がぶつかるおそれがある。",
    "・新品の単三リチウム3本は約5.4V。5V 端子の先は、ダイオード（約0.3V 下がる）を通って 3.3V を作る部品（HX6211、入力の上限 6V と販売店の資料）へ行く。上限の内側だが余裕は少ない。",
    "・PCB36-47 の穴の数（17×13）は見込み。実物で穴の数がちがうときは、5V が「左から4つ目・下から1つ目」の穴に来るように合わせる。",
    "・ケースの深さ（内寸13mm の見込み）に、スペーサー5mm＋基板＋ソケット＋Olimex が収まるか未確認。足りないときは、ソケットを使わずに Olimex のピンを基板へ直接はんだ付けする。",
    "・管理画面の動作モードは「ディープスリープ」にする。電池のもちは保存間隔30分で 2〜4か月の見込み（未実測）。",
], "HPX-Core_BAT_olimex_pcb.svg")

# ================================================================== HPX-B バッテリー電圧（6×8cm 基板、ケース PFF10-4-10W）
OX, OY = 260, 200
s = S(1500, 3200)
title(s, "HPX-B（バッテリー電圧）基板の配線図", "ユニバーサル基板 6×8cm（穴 A〜Y × 1〜33）縦置き ／ XIAO nRF52840 ＋ BP5293-50 ／ ケース タカチ PFF10-4-10W")
board(s, 25, 33, "ユニバーサル基板 6×8cm を縦に置いたところ（穴 A〜Y × 1〜33）")

def hole(h):
    return (Lc(h[0]), R(int(h[1:])))

def res_flat(a, b, label, bands, lpos, anchor="middle"):
    """抵抗を寝かせて付ける（足の間 4穴＝10mm）。本体は 2つの穴の間"""
    (x1, y1), (x2, y2) = H(*hole(a)), H(*hole(b))
    rec_pin(x1, y1, label); rec_pin(x2, y2, label)
    cx, cy = (x1 + x2) / 2, (y1 + y2) / 2
    horiz = y1 == y2
    ln = min(78, abs(x2 - x1) + abs(y2 - y1) - 24)   # 足の間 2穴 (3穴に差す) のときは本体を短く (TARO 2026-11-21 HPX-CAM)
    bw, bh = (ln, 24) if horiz else (24, ln)
    s.top.append(f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="#888" stroke-width="3"/>')
    s.top.append(f'<rect x="{cx-bw/2}" y="{cy-bh/2}" width="{bw}" height="{bh}" rx="10" fill="#e8c88f" stroke="#a07c3c" stroke-width="2"/>')
    for i, c in enumerate(bands):
        o = -24 + i * 12
        if horiz:
            s.top.append(f'<rect x="{cx+o-3}" y="{cy-bh/2}" width="6" height="{bh}" fill="{c}"/>')
        else:
            s.top.append(f'<rect x="{cx-bw/2}" y="{cy+o-3}" width="{bw}" height="6" fill="{c}"/>')
    for x, y in ((x1, y1), (x2, y2)):
        s.top.append(f'<circle cx="{x}" cy="{y}" r="4" fill="#666"/>')
    tag(lpos[0], lpos[1], label, 13, anchor)

def cap_b(a, b, lpos, anchor="start"):
    cap_flat(s, hole(a), hole(b), "0.1µF")
    tag(lpos[0], lpos[1], "0.1µF", 13, anchor, w=50)

# XIAO（USB-C が上）。左の列 J4〜J10（D0〜D6）、右の列 P4〜P10（5V, GND, 3V3, D10〜D7）
p = xiao_pcb(s, Lc("J"), R(4))

# 三端子 DCDC レギュレーター ROHM BP5293-50（7〜26V → 5V 1A）。足は R18・S18・T18（印字面を下＝手前に向けて左から VIN・GND・VOUT）。立てて付ける
x1, y1 = H(Lc("R"), R(18)); x2, _ = H(Lc("T"), R(18))
s.top.append(f'<rect x="{x1-26}" y="{y1+12}" width="{x2-x1+52}" height="22" rx="4" fill="#263238" fill-opacity="0.9" stroke="#000" stroke-width="2"/>')
rec_part(x1 - 26, y1 + 12, x2 - x1 + 52, 22, "")
s.text((x1 + x2) / 2, y1 + 28, "BP5293-50", 12, "middle", "bold", "#fff", layer=s.top)
tag(x2 + 34, y1 + 30, "← 印字面を手前（下）に", 11, "start", w=150)
for i, n in enumerate(["VIN", "GND", "VOUT"]):
    x, y = H(Lc("R") + i, R(18))
    rec_pin(x, y, n)
    s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="#d4af37" stroke="#5d4520" stroke-width="2"/>')
    tag(x, y - 16, n, 11, "middle", w=len(n) * 8 + 8)
s.text((x1 + x2) / 2, H(0, R(22))[1] + 26, "10µF（入力側）", 12, "middle", "bold", "#333", layer=s.top)
cap_flat(s, hole("R21"), hole("T21"), "10µF", True)

# 端子台 3極（5.08mm）。K32 = パネル＋、M32 = マイナス、O32 = バッテリー＋。線を入れる口は下向き
x1, y1 = H(Lc("K"), R(32)); x2, _ = H(Lc("O"), R(32))
s.top.append(f'<rect x="{x1-36}" y="{y1-30}" width="{x2-x1+72}" height="{P+58}" rx="5" fill="#2e7d32" fill-opacity="0.9" stroke="#1b5e20" stroke-width="2"/>')
rec_part(x1 - 36, y1 - 30, x2 - x1 + 72, P + 58, "端子台")
for h, n, col in (("K32", "パネル＋", "blue"), ("M32", "マイナス", "black"), ("O32", "バッテリー＋", "red")):
    x, y = H(*hole(h))
    rec_pin(x, y, n)
    s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="#d4af37" stroke="#5d4520" stroke-width="2"/>')
    s.top.append(f'<circle cx="{x}" cy="{y+P}" r="13" fill="#bdbdbd" stroke="#424242" stroke-width="2"/>')
    s.top.append(f'<line x1="{x-8}" y1="{y+P}" x2="{x+8}" y2="{y+P}" stroke="#424242" stroke-width="3"/>')
    # 外から来る線（ヒューズを通る）
    ybot = OY + (NR - 1) * P + 26
    s.add(f'<path d="M{x},{y+P+16} L{x},{ybot+70}" fill="none" stroke="{COL[col]}" stroke-width="7"/>')
s.text(H(Lc("K"), 0)[0] - 200, OY + (NR - 1) * P + 70, "端子台 3極（ねじで線を留める）", 14, "start", "bold", "#1b5e20")
yy = OY + (NR - 1) * P + 120
s.text(H(Lc("K"), 0)[0], yy, "パネル＋", 13, "middle", "bold", "#1565c0")
s.text(H(Lc("K"), 0)[0], yy + 18, "1A ヒューズ経由", 11, "middle", fill="#555")
s.text(H(Lc("M"), 0)[0], yy + 40, "マイナス", 13, "middle", "bold", "#212121")
s.text(H(Lc("M"), 0)[0], yy + 58, "バッテリー−へ", 11, "middle", fill="#555")
s.text(H(Lc("O"), 0)[0], yy, "バッテリー＋", 13, "middle", "bold", "#c62828")
s.text(H(Lc("O"), 0)[0], yy + 18, "1A ヒューズ経由", 11, "middle", fill="#555")

# 抵抗とコンデンサー
res_flat("K14", "O14", "100kΩ", ["#8d5524", "#212121", "#f9a825"], (H(Lc("M"), R(14))[0], H(0, R(14))[1] - 22))
res_flat("I14", "I18", "20kΩ", ["#e53935", "#212121", "#f57c00"], (H(Lc("J"), 0)[0] - 8, H(0, R(17))[1] + 5), "start")
cap_b("H14", "H16", (H(Lc("D"), 0)[0], H(0, R(15))[1] + 5), "middle")
res_flat("G20", "K20", "100kΩ", ["#8d5524", "#212121", "#f9a825"], (H(Lc("I"), 0)[0], H(0, R(20))[1] - 22))
res_flat("H24", "L24", "10kΩ", ["#8d5524", "#212121", "#f57c00"], (H(Lc("J"), 0)[0], H(0, R(24))[1] - 22))
cap_b("J26", "L26", (H(Lc("K"), 0)[0], H(0, R(27))[1] + 18), "middle")

# 裏の線
w("black",  "M32", "M26", "M24", "M18", "M16", "M5", "P5", "S5", "S18")   # マイナス（真ん中の背骨）→ XIAO GND → BP5293 GND
w("black",  "S18", "S21", "T21")                                    # BP5293 GND → 10µF
w("black",  "H16", "M16")                                           # 0.1µF（バッテリー側）→ マイナス
w("black",  "I18", "M18")                                           # 20kΩ → マイナス
w("black",  "L24", "M24")                                           # 10kΩ → マイナス
w("black",  "L26", "M26")                                           # 0.1µF（パネル側）→ マイナス
w("red",    "O32", "O20", "O14")                                    # バッテリー＋ → 100kΩ
w("red",    "O20", "R20", "R18")                                    # バッテリー＋ → BP5293 VIN
w("red",    "R20", "R21")                                           # → 10µF
w("orange", "T18", "T4", "P4")                                      # BP5293 VOUT → XIAO 5V
w("green",  "J5", "G5", "G14", "H14", "I14", "K14")                 # バッテリーの測る点 → XIAO D1(A1)
w("blue",   "K32", "K20")                                           # パネル＋ → 100kΩ
w("yellow", "J4", "F4", "F20", "F24", "F26", "J26")                 # パネルの測る点 → XIAO D0(A0)
w("yellow", "F20", "G20")
w("yellow", "F24", "H24")
yb = back_section(s, OY + (NR - 1) * P + 220, "HPX-B_pcb.svg")
y = table(s, 60, yb + 40, [
    ["線", "どこから（穴）", "通る穴", "どこへ（穴）"],
    ["red", "端子台 バッテリー＋（O32）", "O32 → O20 → O14", "100kΩ の右の足（O14）"],
    ["red", "（とちゅうの O20）", "O20 → R20 → R18（R20 → R21 も）", "BP5293 VIN（R18）・10µF（R21）"],
    ["orange", "BP5293 VOUT（T18）", "T18 → T4 → P4", "XIAO 5V（P4）"],
    ["black", "端子台 マイナス（M32）", "M32 → M5 → P5 → S5 → S18 → S21 → T21", "XIAO GND（P5）・BP5293 GND（S18）・10µF（T21）"],
    ["black", "0.1µF・20kΩ・10kΩ・0.1µF の下の足", "H16→M16、I18→M18、L24→M24、L26→M26", "マイナスの線（M 列）"],
    ["green", "XIAO D1/A1（J5）", "J5 → G5 → G14 → K14", "0.1µF（H14）・20kΩ（I14）・100kΩ（K14）"],
    ["blue", "端子台 パネル＋（K32）", "K32 → K20", "100kΩ の右の足（K20）"],
    ["yellow", "XIAO D0/A0（J4）", "J4 → F4 → F26 → J26（F20→G20、F24→H24 も）", "100kΩ（G20）・10kΩ（H24）・0.1µF（J26）"],
], [100, 330, 470, 420], "つなぐ線（穴の位置は 列の文字＋行の数字）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["Seeed XIAO BLE nRF52840 ＋ ピンヘッダ 7ピン×2（XIAO 側）＋ ピンソケット 7ピン×2（基板側）", "1", "J4〜J10・P4〜P10。USB-C を上に向ける"],
    ["三端子 DCDC レギュレーター ROHM BP5293-50（入力 7〜26V、5V 1A）", "1", "R18・S18・T18（印字面を手前（下）に向けて左から VIN・GND・VOUT）。立てて付ける"],
    ["積層セラミックコンデンサー 10µF 50V 5mm", "1", "R21・T21（BP5293 の入力側。なくても動くが付けると安定する）"],
    ["金属皮膜抵抗 100kΩ 1%", "2", "K14〜O14（バッテリー用）、G20〜K20（パネル用）。寝かせて付ける"],
    ["金属皮膜抵抗 20kΩ 1%", "1", "I14〜I18（バッテリー用）"],
    ["金属皮膜抵抗 10kΩ 1%", "1", "H24〜L24（パネル用）"],
    ["積層セラミックコンデンサー 0.1µF 50V 5mm", "2", "H14〜H16（バッテリー用）、J26〜L26（パネル用）"],
    ["端子台 3極 5.08mm（基板用、ねじ式）", "1", "K32・M32・O32（左から パネル＋・マイナス・バッテリー＋）"],
    ["ミニ平型ヒューズホルダー（線の途中に入れる）＋ 1A ミニ平型ヒューズ", "2", "バッテリー＋とパネル＋の線に1つずつ。コントローラーの端子のすぐ近くに"],
    ["線 0.3〜0.5mm²（AWG20、赤・青・黒）", "少し", "コントローラーのバッテリー＋・パネル＋・バッテリー− から端子台まで"],
    ["ユニバーサル基板 6×8cm（穴 25×33）・すずめっき線", "1", "縦に置く。線はすべて裏"],
    ["ケース タカチ PFF10-4-10W（壁掛け、内側 約 67×90mm）", "1", "基板はスペーサーで浮かせて留める。線を通す穴をあける"],
], [640, 60, 520], "部品表")
finish(s, y, [
    "・上の図は部品を載せる面（表）から見たところ。部品はすべて表から差し、足を裏ではんだ付けする。",
    "・線はすべて裏（はんだ面）で、すずめっき線を穴から穴へはんだ付けしてつなぐ。下の「裏から見た図」が実際に配線する向き（左右が逆）。",
    "・裏の線どうしは交わらない。100kΩ（K14〜O14）・10kΩ・0.1µF（J26〜L26）は、裏の線（黒・青）の上をまたいで付ける（表と裏なので触れない）。",
    "・バッテリーは 100kΩ と 20kΩ で 1/6 に（18V で 3.0V）、パネルは 100kΩ と 10kΩ で 1/11 に（33V で 3.0V）下げて XIAO で読む。",
    "・ヒューズはコントローラーの端子のすぐ近くに入れる（線がショートしたときの火事よけ）。基板の上には載せない。",
    "・マイナスはコントローラーの「バッテリー−」につなぐ。「パネル−」と「バッテリー−」がつながっている（共通マイナス）コントローラーでだけ、パネル電圧を正しく測れる。",
    "・USB で書き込むときは、端子台の線を外すかバッテリー＋のヒューズを抜く（5V が USB とぶつからないように）。",
    "・BP5293-50 の足の並び（VIN・GND・VOUT）は、差す前に秋月の資料（データシート）の図で確かめる。逆に差すと壊れる。",
    "・BP5293-50 の入力は 26V まで。バッテリー（充電中でも 15V くらい）にはつないでよいが、パネル＋には絶対につながない（開放電圧 23.5V、寒い日はもっと上がる）。",
], "HPX-B_pcb.svg")
# ================================================================== HPX-Core ＋有線LAN＆ソーラーシステムチェック（6×8cm 基板、TARO 2026-11-08）
# 「＋有線LAN」と同じ ESP-32・W5500 の並びに、上の空いた所（1〜9行）へ 12V バッテリー・パネルの電圧を測る部品と BP5293-50 を足す
OX, OY = 260, 200
s = S(1700, 3600)
title(s, "【ELEGOO で作るとき】HPX-Core ＋有線LAN＆ソーラーシステムチェック 基板", "6×8cm 縦置き ／ ELEGOO ESP-32 ＋ W5500 ／ ファーム v1.25")
board(s, 25, 33, "ユニバーサル基板 6×8cm を縦に置いたところ（穴 A〜Y × 1〜33）")

def R(n):   # 行の番号（1〜33）→ 図の行（0〜）
    return n - 1

def module(c1, c2, rtop, rbot, fill, stroke, label, sub=None):
    """モジュールの外形（穴の単位。半端な値でよい）"""
    x1, y1 = OX + c1 * P, OY + rtop * P
    x2, y2 = OX + c2 * P, OY + rbot * P
    s.top.append(f'<rect x="{x1}" y="{y1}" width="{x2-x1}" height="{y2-y1}" rx="10" fill="{fill}" fill-opacity="0.62" stroke="{stroke}" stroke-width="3"/>')
    rec_part(x1, y1, x2 - x1, y2 - y1, label)
    return x1, y1, x2, y2

def pins(col, first_row, names, side, used):
    p = {}
    for i, n in enumerate(names):
        c, r = col, R(first_row + i)
        x, y = H(c, r)
        p[n] = (c, r)
        rec_pin(x, y, n)
        on = n in used
        s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="{"#d4af37" if on else "#9e9e9e"}" stroke="#5d4520" stroke-width="2"/>')
        dx = -14 if side == "R" else 14
        s.text(x + dx, y + 5, n.rstrip("0123456789") if len(n) <= 3 and n[0] in "VG" else n, 13, "end" if side == "R" else "start", "bold", "#fff" if on else "#ddd", layer=s.top)
    return p

# ESP-32（USB-C が下）。ピン列は B 列と L 列（10穴離れている）、14〜28 行
# TARO 2026-11-13: ファーム v1.25（FireBeetle と同じピン）に合わせて描き直した。W5500 と 8本の線は「＋有線LAN」(ELEGOO) と同じ。
# パネル＝D39（VN）、バッテリー＝D35、5V は VIN（B28）へ。5V の線は ESP-32 の下から基板の下の端（33行）・右（W 列）を回る
EL = ["EN", "VP", "VN", "D34", "D35", "D32", "D33", "D25", "D26", "D27", "D14", "D12", "D13", "GND", "VIN"]
ER = ["D23", "D22", "TX0", "RX0", "D21", "D19", "D18", "D5", "TX2", "RX2", "D4", "D2", "D15", "GND", "3V3"]
x1, y1, x2, y2 = module(1 - 0.47, 11 + 0.47, R(14) - 2.3, R(28) + 4.6, "#212121", "#000", "ESP-32")
s.top.append(f'<rect x="{(x1+x2)/2-60}" y="{y1+30}" width="120" height="150" rx="4" fill="#b0bec5" fill-opacity="0.8" stroke="#78909c"/>')
s.text((x1 + x2) / 2, y1 + 100, "ESP32-", 15, "middle", "bold", "#263238", layer=s.top)
s.text((x1 + x2) / 2, y1 + 120, "WROOM-32", 15, "middle", "bold", "#263238", layer=s.top)
s.top.append(f'<rect x="{(x1+x2)/2-34}" y="{y2-20}" width="68" height="44" rx="8" fill="#bdbdbd" stroke="#616161" stroke-width="2"/>')
s.text((x1 + x2) / 2, y2 + 46, "USB-C（下向き）", 13, "middle", "bold", "#333", layer=s.top)
s.text((x1 + x2) / 2, y1 + 230, "ELEGOO ESP-32", 16, "middle", "bold", "#fff", layer=s.top)
s.text((x1 + x2) / 2, y1 + 252, "ピンソケット 15ピン×2 に差す", 12, "middle", "bold", "#eee", layer=s.top)
pins(1, 14, EL, "L", {"VN", "D35", "D25", "D26", "D14", "D13", "GND", "VIN"})
pins(11, 14, ER, "R", {"D23", "D19", "GND", "3V3"})
x1, y1, x2, y2 = module(13 - 0.53, 21 + 0.53, R(22) - 0.5, R(22) - 0.5 + 9.84, "#1565c0", "#0d3c7a", "W5500")
s.top.append(f'<rect x="{(x1+x2)/2-34}" y="{y1+30}" width="68" height="68" rx="3" fill="#212121" fill-opacity="0.85"/>')
s.text((x1 + x2) / 2, y1 + 70, "W5500", 13, "middle", "bold", "#eee", layer=s.top)
s.top.append(f'<rect x="{(x1+x2)/2-80}" y="{y2-150}" width="160" height="170" rx="6" fill="#cfd8dc" fill-opacity="0.75" stroke="#607d8b" stroke-width="2"/>')
s.text((x1 + x2) / 2, y2 - 60, "LAN 差し込み口", 13, "middle", "bold", "#37474f", layer=s.top)
s.text((x1 + x2) / 2, y1 + 120, "W5500 モジュール", 14, "middle", "bold", "#fff", layer=s.top)
pins(13, 22, ["MI", "RST", "NC", "V", "V2", "G"], "L", {"MI", "RST", "V2", "G"})
pins(21, 22, ["INT", "CS", "SCK", "MO", "G2", "G3"], "R", {"INT", "CS", "SCK", "MO"})
def Lc(ch):
    return COLS.index(ch)
def w(color, *holes):
    wireh(s, [(Lc(h[0]), R(int(h[1:]))) for h in holes], color)
def hole(h):
    return (Lc(h[0]), R(int(h[1:])))
for c, a_, hs, b_ in EL_LAN:
    w(c, *hs)
# ---- 電圧チェックの部品（上の 1〜11 行。部品の位置は FireBeetle の図と同じ）----
B100 = ["#8d5524", "#212121", "#f9a825"]
res_flat("I1", "I5", "100kΩ", B100, (-2000, -2000))
res_stand(s, hole("H5"), hole("H3"), "8.2kΩ", ["#9e9e9e", "#e53935", "#e53935"], -2000, -2000)
cap_flat(s, hole("G3"), hole("G5"), "0.1µF")
res_flat("L3", "L7", "15kΩ", ["#8d5524", "#4caf50", "#f57c00"], (-2000, -2000))
cap_flat(s, hole("M5"), hole("M7"), "0.1µF")
res_flat("N7", "N11", "100kΩ", B100, (-2000, -2000))
x1, y1 = H(*hole("R9")); x2, _ = H(*hole("T9"))
s.top.append(f'<rect x="{x1-26}" y="{y1-34}" width="{x2-x1+52}" height="22" rx="4" fill="#263238" fill-opacity="0.9" stroke="#000" stroke-width="2"/>')
rec_part(x1 - 26, y1 - 34, x2 - x1 + 52, 22, "")
s.text((x1 + x2) / 2, y1 - 18, "BP5293-50", 12, "middle", "bold", "#fff", layer=s.top)
for i, n in enumerate(["VOUT", "GND", "VIN"]):
    x, y = H(Lc("R") + i, 8)
    rec_pin(x, y, n)
    s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="#d4af37" stroke="#5d4520" stroke-width="2"/>')
    tag(x, y + 24, n, 10, "middle", w=len(n) * 7 + 8)
cap_flat(s, hole("V9"), hole("V11"), "10µF", True)
x1, y1 = H(*hole("X1")); _, y2 = H(*hole("X5"))
s.top.append(f'<rect x="{x1-14}" y="{y1-30}" width="{P+36}" height="{y2-y1+60}" rx="5" fill="#2e7d32" fill-opacity="0.9" stroke="#1b5e20" stroke-width="2"/>')
rec_part(x1 - 14, y1 - 30, P + 36, y2 - y1 + 60, "")
for h, n, col in (("X1", "パネル＋", "blue"), ("X3", "マイナス", "black"), ("X5", "バッテリー＋", "red")):
    x, y = H(*hole(h))
    rec_pin(x, y, n)
    s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="#d4af37" stroke="#5d4520" stroke-width="2"/>')
    s.top.append(f'<circle cx="{x+P}" cy="{y}" r="13" fill="#bdbdbd" stroke="#424242" stroke-width="2"/>')
    s.add(f'<path d="M{x+P+16},{y} L{x+P+100},{y}" fill="none" stroke="{COL[col]}" stroke-width="7"/>')
    s.text(x + P + 108, y + 5, n, 13, "start", "bold", COL[col] if col != "black" else "#212121")
s.text(H(*hole("X5"))[0] + P + 108, H(*hole("X5"))[1] + 26, "（＋は 1A ヒューズ経由）", 11, "start", fill="#555")
lx, ly = OX + 24 * P + 110, OY + 9 * P
s.text(lx, ly, "上の部品（穴）", 14, weight="bold")
for i, (a_, b_) in enumerate([("I1〜I5", "100kΩ（パネル＋ → 測る点）"), ("H3・H5", "8.2kΩ（立てる。H5 が本体）"), ("G3・G5", "0.1µF（パネル）"),
                             ("L3〜L7", "15kΩ（GND → 測る点）"), ("M5・M7", "0.1µF（バッテリー）"), ("N7〜N11", "100kΩ（測る点 → バッテリー＋）"),
                             ("R9・S9・T9", "BP5293-50（VOUT・GND・VIN）"), ("V9・V11", "10µF（BP5293 の入力側）"), ("X1・X3・X5", "端子台 3極")]):
    s.text(lx, ly + 30 + i * 44, a_, 13, weight="bold", fill="#5d4520")
    s.text(lx, ly + 47 + i * 44, b_, 12, fill="#333")
EL_V = [
    ("black", "GND（左の列 B27）", ["B27", "A27", "A3", "G3", "H3", "L3"], "0.1µF（G3）・8.2kΩ（H3）・15kΩ（L3）"),
    ("black", "（とちゅうの L3）", ["L3", "L5", "M5", "S5", "S8", "S9"], "0.1µF（M5）・BP5293 GND（S9）"),
    ("black", "（とちゅうの S8）", ["S8", "V8", "V9"], "10µF（V9）"),
    ("black", "（とちゅうの V8）", ["V8", "V3", "X3"], "端子台 マイナス（X3）"),
    ("blue", "端子台 パネル＋（X1）", ["X1", "I1"], "100kΩ（I1）"),
    ("yellow", "D39 = VN（B16）", ["B16", "G16", "G5", "H5", "I5"], "0.1µF（G5）・8.2kΩ（H5）・100kΩ（I5）"),
    ("green", "D35（B18）", ["B18", "K18", "K7", "L7", "M7", "N7"], "15kΩ（L7）・0.1µF（M7）・100kΩ（N7）"),
    ("orange", "VIN（B28）", ["B28", "B33", "W33", "W12", "M12", "M9", "R9"], "BP5293 VOUT（R9）"),
    ("red", "端子台 バッテリー＋（X5）", ["X5", "X11", "V11", "T11", "N11"], "10µF（V11）・100kΩ（N11）"),
    ("red", "", ["T11", "T9"], "BP5293 VIN（T9）"),
]
for c, a_, hs, b_ in EL_V:
    w(c, *hs)
yb = back_section(s, OY + (NR - 1) * P + 160, "HPX-Core_LANV_elegoo_pcb.svg")
y = table(s, 60, yb + 40, [["線", "どこから（穴）", "通る穴", "どこへ（穴）"]] + [[c, a_, " → ".join(hs), "W5500 " + b_] for c, a_, hs, b_ in EL_LAN]
          + [[c, a_, " → ".join(hs), b_] for c, a_, hs, b_ in EL_V], [100, 300, 520, 500], "つなぐ線（穴の位置は 列の文字＋行の数字。上の 8本は「＋有線LAN」(ELEGOO) と同じ）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["ELEGOO ESP-32 開発ボード（USB-C・CP2102、30ピン）＋ ピンソケット 15ピン×2", "1", "B14〜B28・L14〜L28。USB-C を下に向ける"],
    ["W5500 イーサネットモジュール ＋ ピンソケット 6ピン×2", "1", "N22〜N27・V22〜V27"],
    ["三端子 DCDC レギュレーター ROHM BP5293-50（入力 7〜26V、5V 1A）", "1", "R9・S9・T9（左から VOUT・GND・VIN。印字面を奥（上）に向けて立てる）"],
    ["積層セラミックコンデンサー 10µF 50V 5mm", "1", "V9・V11（BP5293 の入力側）"],
    ["金属皮膜抵抗 100kΩ 1%", "2", "I1〜I5（パネル用）、N7〜N11（バッテリー用）。寝かせて付ける"],
    ["金属皮膜抵抗 15kΩ 1%", "1", "L3〜L7（バッテリー用、寝かせる）"],
    ["金属皮膜抵抗 8.2kΩ 1%", "1", "H3・H5（パネル用、立てて付ける）"],
    ["積層セラミックコンデンサー 0.1µF 50V 5mm", "2", "G3・G5（パネル用）、M5・M7（バッテリー用）"],
    ["端子台 3極 5.08mm（基板用、ねじ式）", "1", "X1・X3・X5（上から パネル＋・マイナス・バッテリー＋）"],
    ["ミニ平型ヒューズホルダー ＋ 1A ミニ平型ヒューズ", "2", "バッテリー＋とパネル＋の線の途中（コントローラーの近く）"],
    ["ユニバーサル基板 6×8cm（穴 25×33）・すずめっき線", "1", "縦に置く。線はすべて裏"],
], [640, 60, 560], "部品表")
finish(s, y, [
    "・上の図は部品を載せる面（表）から見たところ。部品はすべて表から差し、足を裏ではんだ付けする。線はすべて裏のすずめっき線。",
    "・ピンは FireBeetle 2 の図と同じ（ファーム HPX_Core v1.25 以降）。パネルは D39（VN）、バッテリーは D35 で読む。W5500 の 8本は「＋有線LAN」(ELEGOO) と同じ。",
    "・ESP-32 の電源は USB ではなく、バッテリーから BP5293-50 で作った 5V を VIN（B28）に入れる。5V の線（橙）は B 列の下・基板の下の端・W 列を回る。",
    "・USB で書き込むときは、端子台の線を外すかバッテリー＋のヒューズを抜く（VIN の 5V と USB の 5V がぶつからないように）。",
    "・バッテリーは 100kΩ と 15kΩ で 約1/7.7 に（18V で 2.35V）、パネルは 100kΩ と 8.2kΩ で 約1/13 に（30V で 2.27V）下げて読む。",
    "・ファーム v1.25 の動作設定で「バッテリー・パネルの電圧を測る」にチェックし、テスターで測った値を入れて1回合わせる。",
    "・BP5293-50 の入力は 26V まで。パネル＋にはつながない。足の並びは秋月の資料（データシート）で確かめてから差す。",
    "・マイナスはコントローラーの「バッテリー−」へ。「パネル−」と「バッテリー−」がつながっている（共通マイナス）コントローラーでだけ、パネル電圧を正しく測れる。",
    "・2026-11-12 までのこの図（パネル＝D34、W5500 が 24〜29 行）で組んだものは、ファーム v1.24 までを使う。",
], "HPX-Core_LANV_elegoo_pcb.svg")


# ================================================================== HPX-Core ＋LTE-M（タカチ PCB72-95、ケース タカチ OB10-17-5W）
# TARO 2026-11-10: 電源（ソーラーパネル・DFR0559・18650）は HPX-Solar として別の機器にした。HPX-Solar とは USB-C ケーブル1本でつなぐ（案2）。
# 5V で Olimex を動かし、CatM の 5V は基板の P-MOSFET で入り切りする（IO4）。D+・D− で HPX-Solar の電池とパネルの電圧を受ける（IO34・IO36）。
# TARO 2026-11-10: 基板を PCB72-95（72×95mm）に。穴は TARO の寸法図から: 横 27（66.04mm）× 縦 36（88.9mm）。
# 上の 3行と下の 3行は四隅の取付穴（φ3.2、62×85mm）のため D〜X の 21穴だけ。列は A〜Z・AA。縦に置く。
# Olimex は縦向き（アンテナが上、micro USB が下）で左端に寄せる。左の列（EXT1）を A 列、右の列（EXT2）を K 列、ピン1 を 5 行に置く。
# 左の列の信号は Olimex の下（裏）を B〜D 列で下へ通す（TARO 2026-11-10）。
# M5Stamp CatM は基板に貼る。HPX-Solar からの線は赤丸の穴へ。
import re as _re2
COLS = [chr(65 + i) for i in range(26)] + ["AA"]
def hole(h):
    m = _re2.match(r"([A-Z]+)(\d+)$", h)
    return (COLS.index(m.group(1)), R(int(m.group(2))))
def w(color, *holes):
    wireh(s, [hole(h) for h in holes], color)
OX, OY = 200, 300
s = S(1500, 4400)
title(s, "【Olimex で作るとき】HPX-Core ＋LTE-M 基板の配線図", "PCB72-95 縦置き ／ Olimex ESP32-DEVKIT-LIPO ／ ファーム v1.25")
board(s, 27, 36, "タカチ PCB72-95 を縦に置いたところ（穴 A〜AA × 1〜36。上下 3行は D〜X だけ）")
_i0 = len(s.items)
for r in (0, 1, 2, 33, 34, 35):
    for c in (0, 1, 2, 24, 25, 26):
        x, y = H(c, r)
        s.add(f'<circle cx="{x}" cy="{y}" r="9" fill="#e8d3a8"/>')
for c, r in ((0.9, 0.9), (25.1, 0.9), (0.9, 34.1), (25.1, 34.1)):   # 取付穴 φ3.2
    x, y = OX + c * P, OY + r * P
    s.add(f'<circle cx="{x}" cy="{y}" r="26" fill="#fffdf6" stroke="#8d6e3f" stroke-width="2"/>')
REC["board"] = REC["board"] + s.items[_i0:]
OLL = ["3V3", "EN", "IO36", "IO39", "IO34", "IO35", "IO32", "IO33", "IO25", "IO26", "IO27", "IO14", "IO12", "GND", "IO13", "IO9", "IO10", "IO11", "5V"]
ORR = ["GND", "IO23", "IO22", "IO1", "IO3", "IO21", "GND", "IO19", "IO18", "IO5", "IO17", "IO16", "IO4", "IO0", "IO2", "IO15", "IO8", "IO7", "IO6"]
x1, y1, x2, y2 = module(0 - 0.55, 10 + 0.55, R(5) - 1.2, R(23) + 0.5, "#c62828", "#7f0000", "Olimex")
cx_ = (x1 + x2) / 2
s.top.append(f'<rect x="{cx_-80}" y="{y1+6}" width="160" height="54" rx="4" fill="#212121" fill-opacity="0.85"/>')
s.text(cx_, y1 + 38, "アンテナ（上）", 12, "middle", "bold", "#eee", layer=s.top)
s.top.append(f'<rect x="{cx_-70}" y="{y1+70}" width="140" height="160" rx="4" fill="#b0bec5" fill-opacity="0.85" stroke="#78909c"/>')
s.text(cx_, y1 + 145, "ESP32-", 15, "middle", "bold", "#263238", layer=s.top)
s.text(cx_, y1 + 165, "WROOM-32E", 15, "middle", "bold", "#263238", layer=s.top)
s.text(cx_, y1 + 300, "Olimex", 16, "middle", "bold", "#fff", layer=s.top)
s.text(cx_, y1 + 322, "ESP32-DEVKIT-LIPO", 14, "middle", "bold", "#fff", layer=s.top)
s.text(cx_, y1 + 344, "ピンソケット 19ピン×2 に差す", 12, "middle", "bold", "#eee", layer=s.top)
s.top.append(f'<rect x="{cx_-30}" y="{y2-26}" width="60" height="40" rx="6" fill="#bdbdbd" stroke="#616161" stroke-width="2"/>')
s.text(cx_, y2 + 34, "micro USB（下）", 12, "middle", "bold", "#333", layer=s.top)
s.top.append(f'<rect x="{x2-80}" y="{y2-60}" width="52" height="40" rx="4" fill="#fafafa" stroke="#9e9e9e" stroke-width="2"/>')
s.text(x2 - 54, y2 - 36, "BAT", 12, "middle", "bold", "#333", layer=s.top)
def vpins(names, col, used, left):
    for i, n in enumerate(names):
        x, y = H(col, R(5 + i))
        rec_pin(x, y, n)
        on = (n, i) in used
        s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="{"#d4af37" if on else "#9e9e9e"}" stroke="#5d4520" stroke-width="2"/>')
        s.text(x + (14 if left else -14), y + 5, n, 12, "start" if left else "end", "bold", "#fff" if on else "#eee", layer=s.top)
vpins(OLL, 0, {("IO36", 2), ("IO39", 3), ("5V", 18)}, True)
vpins(ORR, 10, {("GND", 0), ("IO17", 10), ("IO16", 11), ("IO4", 12)}, False)
B100 = ["#795548", "#212121", "#fdd835", "#c9a227"]
B10 = ["#795548", "#212121", "#fb8c00", "#c9a227"]
OFF = (-2000, -2000)   # 部品の名前は図の右の一覧に書く
def tr3(hs, names, label, fill):
    """3本足の部品（トランジスタ・MOSFET）。hs は横に並んだ 3つの穴"""
    pts = [H(*hole(h)) for h in hs]
    x1, x2, y = pts[0][0], pts[-1][0], pts[0][1]
    s.top.append(f'<rect x="{x1-16}" y="{y-34}" width="{x2-x1+32}" height="30" rx="6" fill="{fill}" stroke="#222" stroke-width="2"/>')
    s.text((x1 + x2) / 2, y - 14, label, 11, "middle", "bold", "#fff", layer=s.top)
    for (x, yy), n in zip(pts, names):
        rec_pin(x, yy, n)
        s.top.append(f'<circle cx="{x}" cy="{yy}" r="7" fill="#9e9e9e" stroke="#333" stroke-width="1.5"/>')
        s.text(x, yy + 22, n, 11, "middle", "bold", "#5d4520", layer=s.top)
# CatM の 5V のスイッチ（D4 が High で ON）: IO4 → 10kΩ → NPN のベース（100kΩ で GND へ）、NPN のコレクタ → P-MOSFET のゲート（100kΩ で 5V へ）
res_flat("L17", "P17", "10kΩ", B10, OFF)        # IO4 → ベース
tr3(["Q17", "R17", "S17"], ["B", "C", "E"], "NPN", "#455a64")
res_stand(s, hole("Q14"), hole("R14"), "100kΩ", B100, -2000, -2000)   # ベース → GND（立てて付ける）
tr3(["V18", "W18", "X18"], ["D", "G", "S"], "P-MOSFET", "#37474f")
res_flat("W19", "W23", "100kΩ", B100, OFF)      # ゲート → 5V
lx = OX + 26 * P + 70
s.text(lx, OY + 15 * P, "基板の上の部品", 14, weight="bold")
for i, (a_, b_) in enumerate([("L17〜P17", "10kΩ（IO4 → ベース）"), ("Q14・R14", "100kΩ（ベース → GND、立てる）"), ("Q17・R17・S17", "NPN トランジスタ（B・C・E）"),
                             ("V18・W18・X18", "P-MOSFET（D・G・S）"), ("W19〜W23", "100kΩ（ゲート → 5V）")]):
    s.text(lx, OY + 15 * P + 30 + i * 46, a_, 13, weight="bold", fill="#5d4520")
    s.text(lx, OY + 15 * P + 48 + i * 46, b_, 12, fill="#333")
# M5Stamp CatM（30×20mm）は Olimex の右上（P〜AA 列、5〜12 行）に両面テープで貼る
mx1, my1 = OX + 15 * P - 14, OY + R(5) * P - 10
mx2, my2 = OX + 26 * P + 14, OY + R(12) * P + 10
s.top.append(f'<rect x="{mx1}" y="{my1}" width="{mx2-mx1}" height="{my2-my1}" rx="8" fill="#eceff1" fill-opacity="0.9" stroke="#90a4ae" stroke-width="3"/>')
rec_part(mx1, my1, mx2 - mx1, my2 - my1, "CatM")
s.text((mx1 + mx2) / 2, my1 + 90, "M5Stamp CatM", 15, "middle", "bold", "#263238", layer=s.top)
s.text((mx1 + mx2) / 2, my1 + 112, "SIM7080G・microSIM", 12, "middle", fill="#455a64", layer=s.top)
s.text((mx1 + mx2) / 2, my1 + 140, "両面テープで貼る", 12, "middle", "bold", "#455a64", layer=s.top)
s.text((mx1 + mx2) / 2, my1 + 158, "TX・RX は M16・M15、5V は V16、GND は Y14 へ", 11, "middle", fill="#455a64", layer=s.top)
s.text((mx1 + mx2) / 2, my1 + 176, "アンテナ（IPEX）は上へ", 11, "middle", fill="#455a64", layer=s.top)
# USB-C メス基板（データ対応）はケースの壁に付け、4本の線を赤丸の穴へ
ux, uy = OX + 2 * P, OY + R(33) * P + 30
LAND = [("P27", "USB-C D+", "end"), ("Q27", "USB-C D−", "start"), ("X30", "USB-C VBUS（5V）", "end"), ("Y30", "USB-C GND", "start"),
        ("M15", "CatM RX", "start"), ("M16", "CatM TX", "start"), ("V16", "CatM 5V", "end"), ("Y14", "CatM GND", "start")]
for h, txt, an in LAND:
    solder_mark(s, *hole(h))
    x, y = H(*hole(h))
    tag(x + (16 if an == "start" else -16), y - 14, txt, 12, an, "#c62828")
w("black", "K5", "K4", "Z4", "Z30")               # GND（右の列のピン1）→ 右の端
w("black", "Y30", "Z30")                          # USB-C GND
w("black", "S17", "S14", "Y14")                   # NPN のエミッタ → CatM GND の穴
w("black", "Y14", "Z14")                          # → 右の端の GND
w("black", "R14", "S14")                          # 100kΩ（ベース側の下）→ GND
w("red", "X30", "X18")                            # USB-C VBUS → P-MOSFET の S
w("red", "X30", "X31", "A31", "A23")              # USB-C VBUS → Olimex 5V（左の列のいちばん下）
w("red", "W23", "X23")                            # 100kΩ → 5V
w("orange", "V18", "V16")                         # P-MOSFET の D → CatM 5V
w("purple", "K17", "L17")                         # IO4 → 10kΩ
w("purple", "P17", "Q17")                         # 10kΩ → ベース
w("purple", "Q17", "Q14")                         # ベース → 100kΩ
w("gray", "R17", "R19", "W19")                    # コレクタ → ゲート
w("gray", "W18", "W19")                           # ゲート
w("white", "A8", "C8", "C25", "P25", "P27")       # IO39 ← USB-C D+（電池の電圧。v1.25 で IO34 から変更）
w("blue", "A7", "D7", "D24", "Q24", "Q27")        # IO36 ← USB-C D−（パネルの電圧）
w("yellow", "K16", "M16")                         # IO16（受信）← CatM TX
w("green", "K15", "M15")                          # IO17（送信）→ CatM RX
yb = back_section(s, OY + (NR - 1) * P + 160, "HPX-Core_LTE_olimex_pcb.svg")
y = table(s, 60, yb + 40, [
    ["線", "どこから（穴）", "通る穴", "どこへ（穴）"],
    ["black", "Olimex GND（右の列のいちばん上 K5）", "K5 → K4 → Z4 → Z30（右の端）", "USB-C GND（Y30）・CatM GND（Y14 → Z14）"],
    ["black", "NPN の E（S17）", "S17 → S14 → Y14（R14 も）", "CatM GND の穴（Y14）・100kΩ（R14）"],
    ["red", "USB-C VBUS の線（X30 にはんだ付け）", "X30 → X18（W23 → X23 も）", "P-MOSFET の S（X18）・100kΩ（W23）"],
    ["red", "USB-C VBUS（X30）", "X30 → X31 → A31 → A23", "Olimex 5V（左の列のいちばん下 A23）"],
    ["orange", "P-MOSFET の D（V18）", "V18 → V16", "CatM 5V の線（V16 にはんだ付け）"],
    ["purple", "IO4（K17）", "K17 → L17、P17 → Q17 → Q14", "10kΩ（L17〜P17）・NPN の B（Q17）・100kΩ（Q14）"],
    ["gray", "NPN の C（R17）", "R17 → R19 → W19、W18 → W19", "P-MOSFET の G（W18）・100kΩ（W19）"],
    ["white", "IO39（A8）", "A8 → C8 → C25 → P25 → P27（Olimex の下を通る）", "USB-C D+ の線（P27 にはんだ付け、電池の電圧）"],
    ["blue", "IO36（A7）", "A7 → D7 → D24 → Q24 → Q27（Olimex の下を通る）", "USB-C D− の線（Q27 にはんだ付け、パネルの電圧）"],
    ["yellow", "IO16（K16、受信）", "K16 → M16", "M5Stamp CatM TX の線（M16 にはんだ付け）"],
    ["green", "IO17（K15、送信）", "K15 → M15", "M5Stamp CatM RX の線（M15 にはんだ付け）"],
], [100, 340, 560, 420], "つなぐ線（穴の位置は 列の文字＋行の数字）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["Olimex ESP32-DEVKIT-LIPO ＋ ピンソケット 19ピン×2（20ピンを切る）", "1", "A5〜A23・K5〜K23（左端に寄せる）。アンテナを上、micro USB を下に向ける"],
    ["M5Stamp CatM（SIM7080G）", "1", "P5〜AA12 の上に両面テープで貼る。TX・RX・5V・GND を赤丸の穴へ短い線で"],
    ["USB-C メス基板（データ対応、VBUS・GND・D+・D− が出ているもの）", "1", "ケースの壁に付け、4本の線を X30・Y30・P27・Q27 へ"],
    ["P-MOSFET（ロジックレベル、1A 以上）", "1", "V18・W18・X18（D・G・S）。足の並びは部品の資料で確かめて合わせる"],
    ["NPN トランジスタ（2SC1815 など）", "1", "Q17・R17・S17（B・C・E）。足の並びは部品の資料で確かめて合わせる"],
    ["金属皮膜抵抗 100kΩ", "2", "W19〜W23（ゲート → 5V、縦）・Q14〜R14（ベース → GND、立てる）"],
    ["金属皮膜抵抗 10kΩ", "1", "L17〜P17（IO4 → ベース、横）"],
    ["ユニバーサル基板 タカチ PCB72-95（穴 27×36）・すずめっき線", "1", "縦に置く。線はすべて裏"],
    ["ケース OB10-17-5W ＋ 取り付けベース OBP10-17 ＋ 基板スペーサー AST3-6B", "1", "四隅の取付穴（φ3.2）を AST3-6B に留める"],
    ["電源: HPX-Solar（別の機器）と USB-C ケーブル（データ対応）", "1", "USB-C ケーブル1本でつなぐ（HPX-Solar_pcb.png）。家では USB 充電器でもよい"],
], [640, 60, 620], "部品表")
finish(s, y, [
    "・上の図は部品を載せる面（表）から見たところ。部品はすべて表から差し、足を裏ではんだ付けする。線はすべて裏のすずめっき線。",
    "・赤丸の穴は、ほかの部品からの線（USB-C メス基板の 4本、M5Stamp CatM の TX・RX・5V・GND）を直接はんだ付けするところ。",
    "・電源は USB-C から（HPX-Solar か USB 充電器）。Olimex は 5V ピンから、CatM は P-MOSFET を通した 5V から動かす。Olimex の BAT コネクタは使わない。",
    "・CatM の 5V は IO4 が High のときだけ入る（NPN が P-MOSFET のゲートを GND へ引く）。眠っている間と起動のときは 100kΩ で切れたまま。",
    "・HPX-Solar のときは、D+ に電池の電圧（1/2）、D− にパネルの電圧（1/6）が来る（分圧の抵抗とコンデンサーは HPX-Solar の中）。動作設定の「電圧の測り方」で「HPX-Solar」を選ぶ。",
    "・USB 充電器につなぐときは「測らない」にする（充電器は D+・D− に独自の電圧を出すことがある）。",
    "・USB-C ケーブルは「データ対応」のものを使う（充電専用のケーブルには D+・D− の線がない）。",
    "・IO36・IO39 の線は、Olimex の下（C〜D 列、裏）を通して下へつなぐ。Olimex はピンソケットで浮いているので触れない。",
    "・穴の数（27×36、上下 3行は D〜X）は TARO さんの寸法図から読んだ。Olimex のピンの並びは公式資料から。差す前に実物で確かめる。",
    "・M5Stamp CatM は基板に貼るだけで、穴には差さない（ピンの位置を実物で確かめてから、ピンヘッダで差す形にもできる）。",
    "・アンテナのケーブル（SMA）と USB-C メス基板は、ケースの穴から防水して付ける。穴あけの位置は部品が届いてから決める。",
], "HPX-Core_LTE_olimex_pcb.svg")
COLS = "ABCDEFGHIJKLMNOPQRSTUVWXY"
# ================================================================== HPX-CAM（巣門カメラ、試作）TARO 2026-11-09
# XIAO ESP32S3 Sense（カメラ・microSD は上に重ねた Sense 基板）。単三リチウム×3 → ショットキーダイオード → 5V。
# 電池の電圧は ダイオードの前で 100kΩ/100kΩ（1/2）に下げて D0(A0) で読む。ケース タカチ BCAP091207T（内寸 66×96×56.6mm）
OX, OY = 600, 200
s = S(1280, 2300)
title(s, "HPX-CAM（巣門カメラ・試作）基板の配線図", "XIAO ESP32S3 Sense ／ 電池電圧 D0(A0) ／ 電源 単三リチウム×3（ダイオード経由で 5V）")
board(s, 12, 18)   # TARO 2026-11-21: 行を 1〜18 に (抵抗・コンデンサーをチップの下に置くため)
p = xiao_pcb(s, 2, 2, "ESP32S3 Sense")
cx_ = (H(2, 2)[0] + H(8, 2)[0]) / 2
s.text(cx_, H(0, 8)[1] + 2, "カメラは上（ふた）向き", 11, "middle", "bold", "#e8f5e9", layer=s.top)

# ショットキーダイオード 1N5819（寝かせて付ける、D2＝アノード、H2＝カソード（帯の側）。TARO 2026-11-21 に E1〜I1 から移動）
(x1, y1), (x2, y2) = H(*hole("D2")), H(*hole("H2"))
rec_pin(x1, y1, "1N5819 A"); rec_pin(x2, y2, "1N5819 K")
s.top.append(f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="#888" stroke-width="3"/>')
s.top.append(f'<rect x="{(x1+x2)/2-40}" y="{y1-12}" width="80" height="24" rx="6" fill="#212121" stroke="#000" stroke-width="2"/>')
s.top.append(f'<rect x="{(x1+x2)/2+24}" y="{y1-12}" width="9" height="24" fill="#cfd8dc"/>')
for x, y in ((x1, y1), (x2, y2)):
    s.top.append(f'<circle cx="{x}" cy="{y}" r="4" fill="#666"/>')
rec_part((x1 + x2) / 2 - 40, y1 - 12, 80, 24, "")
tag((x1 + x2) / 2, y1 - 30, "ダイオード 1N5819（帯は右）", 12, "middle", w=190)   # チップと重ならないよう上に

# TARO 2026-11-21 (2回目): 100kΩ 2本と 0.1µF はチップの下 (縦向き)。電池＋の線はチップの下を通さず A2 から。電池−は K11、−の列は K
res_flat("E3", "E5", "100kΩ", ["#8d5524", "#212121", "#f9a825"], (OX - 40, H(0, R(4))[1] + 5), "end")
res_flat("E7", "E9", "100kΩ", ["#8d5524", "#212121", "#f9a825"], (OX - 40, H(0, R(8))[1] + 5), "end")
for rr in (4, 8):   # ラベルからチップの下の抵抗への引き出し線
    s.top.append(f'<line x1="{OX - 36}" y1="{H(0, R(rr))[1]}" x2="{H(Lc("E"), 0)[0] - 14}" y2="{H(0, R(rr))[1]}" stroke="#6d4c41" stroke-width="1.5" stroke-dasharray="4 3"/>')
cap_flat(s, hole("F7"), hole("F9"), "0.1µF")
tag(H(Lc("F"), 0)[0] + 18, H(0, R(8))[1] + 5, "0.1µF", 12, "start", w=46)
cap_flat(s, hole("I2"), hole("K2"), "100µF", True)
tag(H(Lc("L"), 0)[0] + 26, H(0, R(2))[1] + 5, "100µF", w=52)

w("red",    "B11", "B2", "D2")                     # 電池＋（B11）→ ダイオードのアノード（TARO 2026-11-21）
w("red",    "D2", "E2", "E3")                      # → 上の 100kΩ（E2 はダイオードの下の穴、裏で通す）
w("orange", "H2", "I2", "I3")                      # ダイオードのカソード → 100µF → XIAO 5V
w("yellow", "E5", "E6", "E7")                      # 100kΩ と 100kΩ の間（測る点）
w("yellow", "E7", "F7")                            # → 0.1µF
w("yellow", "E6", "D6", "D3", "C3")                # → XIAO D0(A0)
w("black",  "E9", "F9")                            # 0.1µF の下
w("black",  "E9", "E10", "K10")                    # 下の 100kΩ → −の列
w("black",  "K11", "K4", "K2")                     # −の列 → 100µF
w("black",  "I4", "K4")                            # XIAO GND

# 電池ボックス（単3×3、スイッチ付き）。＋の線を B11、−の線を K11 に直接はんだ付け
bx, by = OX - 40, OY + 17 * P + 110
s.rect(bx, by, 400, 80, "#455a64", "#263238", 10, 3)
for i in range(3):
    s.rect(bx + 18 + i * 124, by + 18, 116, 44, "#c0ca33", "#827717", 20, 2)
    s.text(bx + 76 + i * 124, by + 46, "単3", 15, "middle", "bold", "#333")
s.text(bx + 14, by + 24, "＋", 16, "start", "bold", "#fff")
s.text(bx + 386, by + 24, "−", 18, "end", "bold", "#fff")
s.text(bx + 200, by + 106, "単3×3 電池ボックス（スイッチ付き）＋ 単三リチウム電池 ×3（直列 約4.5〜5.4V）", 14, "middle", "bold")
ta, tl = H(*hole("B11")), H(*hole("K11"))
s.wire([(bx, by + 40), (bx - 60, by + 40), (bx - 60, ta[1]), ta], "red")
s.wire([(bx + 400, by + 40), (bx + 460, by + 40), (bx + 460, tl[1]), tl], "black")
solder_mark(s, *hole("B11")); solder_mark(s, *hole("K11"))

yb = back_section(s, by + 140, "HPX-CAM_pcb.svg")
y = table(s, 60, yb + 40, [
    ["線", "どこから（穴）", "どこへ（穴）", "役目"],
    ["red", "電池ボックスの＋（B11 に直接はんだ付け）", "B11 → B2 → D2（ダイオードのアノード）→ E2 → E3（上の 100kΩ）", "電池＋"],
    ["orange", "ダイオードのカソード（H2）", "H2 → I2（100µF）→ I3（XIAO 5V）", "ダイオードを通った電池を 5V 端子へ"],
    ["yellow", "100kΩ と 100kΩ の間（E5・E7、チップの下）", "E5 → E6 → E7 → F7（0.1µF）、E6 → D6 → D3 → C3（XIAO D0）", "電池の電圧を半分にして読む点"],
    ["black", "下の 100kΩ（E9、チップの下）", "E9 → F9（0.1µF）、E9 → E10 → K10", "−の列へ"],
    ["black", "電池ボックスの−（K11 に直接はんだ付け）", "K11 → K10 → K4 → K2（100µF）、I4（XIAO GND）→ K4", "−の列"],
], [100, 320, 420, 320], "つなぐ線（穴の位置は 列の文字＋行の数字）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["Seeed Studio XIAO ESP32S3 Sense（カメラ OV3660・microSD 枠付き。付属の外付けアンテナを付ける。秋月電子 2,660円）", "1", "C3〜I9 のソケットに差す。USB-C を上に向ける"],
    ["ピンヘッダ 7ピン×2（XIAO 付属）＋ 分割ロングピンソケット 7ピン×2（基板側）", "1組", "C3〜C9・I3〜I9"],
    ["（交換用・任意）OV5640 カメラ（XIAO ESP32S3 Sense 用、自動ピント）", "1", "近い巣門にピントを合わせたいとき。カメラの差し込み口で付け替える"],
    ["microSD カード 32GB 以下（FAT32。例: LAZOS 8GB）", "1", "Sense 基板の枠に差す。写真をためる"],
    ["ショットキーバリアダイオード 40V1A 1N5819（秋月電子 100円/10本）", "1", "D2（アノード）〜H2（カソード、帯の側）。寝かせて付ける"],
    ["金属皮膜抵抗 100kΩ 1%", "2", "E3〜E5、E7〜E9（チップの下。縦に寝かせて付ける）"],
    ["積層セラミックコンデンサー 0.1µF 50V C0G 5mm", "1", "F7・F9（チップの下。測る点のノイズよけ）"],
    ["絶縁ラジアルリード型積層セラミックコンデンサー 100µF 6.3V 5mm", "1", "I2・K2（Wi-Fi で送る瞬間の電圧の下がりよけ）"],
    ["単3×3 電池ボックス（リード線・フタ・スイッチ付、秋月電子 160円）／ 単三リチウム電池", "1／3", "＋の線を B11、−の線を K11 にはんだ付け"],
    ["ユニバーサル基板（穴 12×18 以上）・すずめっき線", "1", "大きい基板を切って使ってもよい"],
    ["ケース タカチ BCAP091207T（透明ふた、内寸 66×96×56.6mm。モノタロウ 1,398円）", "1", "カメラをふたに近づける"],
    ["ポール取付金具 タカチ SSK-S1（モノタロウ 558円）", "1", "ケースを杭やポールに留める"],
    ["屋外用ルーフ タカチ BRF-90G（モノタロウ 539円）", "1", "ケースの上に付ける雨・日よけ（中が熱くなりにくい）"],
    ["乾燥剤（小袋）", "少し", "くもり止め"],
], [620, 60, 480], "部品表")
finish(s, y, [
    "・上の図は部品を載せる面（表）から見たところ。部品はすべて表から差し、足を裏ではんだ付けする。線はすべて裏のすずめっき線。",
    "・下の「裏から見た図」が実際に配線する向き（左右が逆）。裏の線どうしは交わらない。",
    "・電池は ダイオード 1N5819 を通して XIAO の「5V」端子へ入れる（USB をつないだとき、USB の 5V が電池へ流れ込まないように）。",
    "・XIAO 裏の「BAT」パッドは充電式リチウムイオン電池用。乾電池は絶対につながない。",
    "・100kΩ 2本と 0.1µF はチップ（XIAO）の下に置く。基板にぴったり寝かせ、0.1µF の足は短く切る。XIAO の裏の金属（パッド）に当たらないようにする。",
    "・電池の電圧は ダイオードの前で 100kΩ・100kΩ で半分にして D0(A0) で読む（新品 5.4V で 2.7V）。この抵抗には いつも約27µA 流れる（1日 約0.65mAh）。",
    "・D8・D9・D10 は Sense 基板の microSD が使うので、ほかの部品につながない。",
    "・USB で書き込むときは、電池ボックスのスイッチを切る（ダイオードがあるので切り忘れても壊れにくいが、念のため）。",
    "・付属の Wi-Fi アンテナ（シール状）は XIAO の U.FL に差し、ケースの壁（金属のそば以外）に貼る。",
    "・ケースの中で、カメラ（Sense 基板）をふたのすぐ下に来るようにする（ふたの反射よけ）。",
    "・電池のもち（10分おきに撮影・未実測）: 24時間で 約3週間〜1か月半、昼12時間だけで 約1〜3か月。眠り中の電流しだい。",
    "・まだ組んで確かめていない。XIAO ESP32S3 Sense の眠り中の電流、1N5819 を入れたときの Wi-Fi 送信中の電圧は実物で測る。",
], "HPX-CAM_pcb.svg")

# ================================================================== HPX-CAM（KP-1228D 縦置き版）TARO 2026-11-22
# 共立プロダクツ KP-1228D（穴 12×28、2.54mm）を縦に置き、XIAO を一番上に。四隅の 2×2 はねじ止めで使えない。
# 部品はチップの下でなく空いている所でもよい（TARO）。D0 への線と GND の線だけ XIAO の下（裏）を通す。
COLS = "ABCDEFGHIJKLMNOPQRSTUVWXY"
def R(n): return n - 1
def Lc(ch): return COLS.index(ch)
def hole(h):
    m = _re.match(r"([A-Z]+)(\d+)$", h)
    return (COLS.index(m.group(1)), int(m.group(2)) - 1)
def w(color, *holes):
    wireh(s, [hole(h) for h in holes], color)
OX, OY = 600, 200
s = S(1280, 3200)
title(s, "HPX-CAM（巣門カメラ）KP-1228D 版", "XIAO ESP32S3 Sense ／ 電池電圧 D0(A0) ／ 電源 単三リチウム×3（ダイオード経由で 5V）")
board(s, 12, 28, "共立 KP-1228D を縦に置いたところ（穴 A〜L × 1〜28。四隅の 2×2 はねじ止めで使えない）")
_i0 = len(s.items)
for r in (0, 1, 26, 27):
    for c in (0, 1, 10, 11):
        x, y = H(c, r)
        s.add(f'<circle cx="{x}" cy="{y}" r="9" fill="#e8d3a8"/>')
for c, r in ((0.5, 0.5), (10.5, 0.5), (0.5, 26.5), (10.5, 26.5)):   # ねじ穴
    x, y = OX + c * P, OY + r * P
    s.add(f'<circle cx="{x}" cy="{y}" r="22" fill="#fffdf6" stroke="#8d6e3f" stroke-width="2"/>')
REC["board"] = REC["board"] + s.items[_i0:]
p = xiao_pcb(s, 2, 0, "ESP32S3 Sense")
cx_ = (H(2, 0)[0] + H(8, 0)[0]) / 2
s.text(cx_, H(0, 6)[1] + 2, "カメラは上（ふた）向き", 11, "middle", "bold", "#e8f5e9", layer=s.top)

# ダイオード 1N5819（寝かせて付ける、F10＝アノード、J10＝カソード（帯の側））
(x1, y1), (x2, y2) = H(*hole("F10")), H(*hole("J10"))
rec_pin(x1, y1, "1N5819 A"); rec_pin(x2, y2, "1N5819 K")
s.top.append(f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="#888" stroke-width="3"/>')
s.top.append(f'<rect x="{(x1+x2)/2-40}" y="{y1-12}" width="80" height="24" rx="6" fill="#212121" stroke="#000" stroke-width="2"/>')
s.top.append(f'<rect x="{(x1+x2)/2+24}" y="{y1-12}" width="9" height="24" fill="#cfd8dc"/>')
for x, y in ((x1, y1), (x2, y2)):
    s.top.append(f'<circle cx="{x}" cy="{y}" r="4" fill="#666"/>')
rec_part((x1 + x2) / 2 - 40, y1 - 12, 80, 24, "")
tag(H(Lc("L"), 0)[0] + 26, y1 + 5, "ダイオード 1N5819（帯は右）", 12, "start", w=190)

res_flat("E10", "E12", "100kΩ", ["#8d5524", "#212121", "#f9a825"], (OX - 40, H(0, R(11))[1] + 5), "end")
res_flat("E14", "E16", "100kΩ", ["#8d5524", "#212121", "#f9a825"], (OX - 40, H(0, R(15))[1] + 5), "end")
cap_flat(s, hole("F13"), hole("F15"), "0.1µF")
tag(H(Lc("F"), 0)[0] + 18, H(0, R(14))[1] + 5, "0.1µF", 12, "start", w=46)
cap_flat(s, hole("H9"), hole("J9"), "100µF", True)
tag(H(Lc("L"), 0)[0] + 26, H(0, R(9))[1] + 5, "100µF", w=52)

# TARO 2026-11-22: 電池は端子台 (F19＝＋、H19＝−) から。＋は F17 → 表の被覆線 (E9) → E10
w("red",    "F19", "F18", "F17")                   # 端子台＋ → 被覆線の下の端（F17）
w("red",    "E9", "E10")                           # 被覆線の上の端（E9）→ 上の 100kΩ
w("red",    "F10", "E10")                          # ダイオードのアノード → 上の 100kΩ
w("orange", "J10", "J9")                           # ダイオードのカソード → 100µF
w("orange", "J9", "J1", "I1")                      # → XIAO 5V（J 列を上へ）
w("yellow", "E12", "E13", "E14")                   # 100kΩ と 100kΩ の間（測る点）
w("yellow", "E13", "F13")                          # → 0.1µF
w("yellow", "E13", "D13", "D1", "C1")              # → XIAO D0(A0)（D 列、XIAO の下を通す）
w("black",  "I2", "H2", "H9")                      # XIAO GND → XIAO の下を通して 100µF へ
w("black",  "H9", "H16")                           # −の列（H10 はダイオードの下の穴）
w("black",  "F15", "F16")                          # 0.1µF の下
w("black",  "E16", "F16", "H16")                   # 下の 100kΩ → −の列
w("black",  "H16", "H19")                          # → 端子台−（H19）

# 表の被覆ジャンパー線（E9 → D9 → D17 → F17 と曲げて這わせる。足は E9 と F17 だけ）
jp = [H(*hole(h)) for h in ("E9", "D9", "D17", "F17")]
for x, y in (jp[0], jp[-1]):
    rec_pin(x, y, "被覆線")
d_ = "M" + " L".join(f"{x},{y}" for x, y in jp)
s.top.append(f'<path d="{d_}" fill="none" stroke="#7f0000" stroke-width="9" stroke-linejoin="round"/>')
s.top.append(f'<path d="{d_}" fill="none" stroke="#e53935" stroke-width="6" stroke-linejoin="round"/>')
for x, y in (jp[0], jp[-1]):
    s.top.append(f'<circle cx="{x}" cy="{y}" r="6" fill="#e53935" stroke="#7f0000" stroke-width="2"/>')
tag(OX - 40, H(0, R(17))[1] + 5, "被覆線（表）", 12, "end", w=90)

# ターミナルブロック 2P（5.08mm）。F19＝電池＋、H19＝電池−。線を入れる口は下向き
x1, y1 = H(*hole("F19")); x2, _ = H(*hole("H19"))
s.top.append(f'<rect x="{x1-30}" y="{y1-26}" width="{x2-x1+60}" height="{P+50}" rx="5" fill="#2e7d32" fill-opacity="0.9" stroke="#1b5e20" stroke-width="2"/>')
rec_part(x1 - 30, y1 - 26, x2 - x1 + 60, P + 50, "端子台")
for h_, n_ in (("F19", "＋"), ("H19", "−")):
    x, y = H(*hole(h_))
    rec_pin(x, y, "端子台" + n_)
    s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="#d4af37" stroke="#5d4520" stroke-width="2"/>')
    s.top.append(f'<circle cx="{x}" cy="{y+P}" r="13" fill="#bdbdbd" stroke="#424242" stroke-width="2"/>')
    s.top.append(f'<line x1="{x-8}" y1="{y+P}" x2="{x+8}" y2="{y+P}" stroke="#424242" stroke-width="3"/>')
tag(H(Lc("L"), 0)[0] + 26, y1 + 5, "ターミナルブロック 2P", 12, "start", w=150)

# 電池ボックス（単3×3、スイッチ付き）。＋の線を F19、−の線を H19 の端子台へ
bx, by = OX - 40, OY + 27 * P + 110
s.rect(bx, by, 400, 80, "#455a64", "#263238", 10, 3)
for i in range(3):
    s.rect(bx + 18 + i * 124, by + 18, 116, 44, "#c0ca33", "#827717", 20, 2)
    s.text(bx + 76 + i * 124, by + 46, "単3", 15, "middle", "bold", "#333")
s.text(bx + 14, by + 24, "＋", 16, "start", "bold", "#fff")
s.text(bx + 386, by + 24, "−", 18, "end", "bold", "#fff")
s.text(bx + 200, by + 106, "単3×3 電池ボックス（スイッチ付き）＋ 単三リチウム電池 ×3（直列 約4.5〜5.4V）", 14, "middle", "bold")
ta, tl = H(*hole("F19")), H(*hole("H19"))
s.top.append(f'<path d="M{bx+30},{by} L{bx+30},{by-40} L{ta[0]},{by-40} L{ta[0]},{ta[1]+P+14}" fill="none" stroke="#e53935" stroke-width="7" stroke-linejoin="round"/>')
s.top.append(f'<path d="M{bx+370},{by} L{bx+370},{by-40} L{tl[0]},{by-40} L{tl[0]},{tl[1]+P+14}" fill="none" stroke="#212121" stroke-width="7" stroke-linejoin="round"/>')

yb = back_section(s, by + 140, "HPX-CAM_KP1228D_pcb.svg")
y = table(s, 60, yb + 40, [
    ["線", "どこから（穴）", "どこへ（穴）", "役目"],
    ["red", "端子台の＋（F19）", "F19 → F18 → F17（被覆線の下の端）", "電池＋"],
    ["red", "表の被覆ジャンパー線", "F17 → D17 → D9 → E9（表を這わせる。足は F17 と E9 だけ）", "電池＋を上へ（裏の線をまたぐため表を通す）"],
    ["red", "被覆線の上の端（E9）", "E9 → E10（上の 100kΩ）、F10（ダイオードのアノード）→ E10", "電池＋"],
    ["orange", "ダイオードのカソード（J10）", "J10 → J9（100µF）→ J1 → I1（XIAO 5V）", "ダイオードを通った電池を 5V 端子へ"],
    ["yellow", "100kΩ と 100kΩ の間（E12・E14）", "E12 → E13 → E14、E13 → F13（0.1µF）、E13 → D13 → D1 → C1（XIAO D0、XIAO の下）", "電池の電圧を半分にして読む点"],
    ["black", "XIAO GND（I2）", "I2 → H2 → H9（100µF、XIAO の下）→ H16 → H19（端子台の−）", "−の列"],
    ["black", "下の 100kΩ（E16）・0.1µF（F15）", "E16 → F16 → H16、F15 → F16", "−の列へ"],
], [100, 320, 440, 300], "つなぐ線（穴の位置は 列の文字＋行の数字）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["Seeed Studio XIAO ESP32S3 Sense（カメラ OV3660・microSD 枠付き。付属の外付けアンテナを付ける）", "1", "C1〜I7 のソケットに差す。USB-C を上（基板の外）に向ける"],
    ["ピンヘッダ 7ピン×2（XIAO 付属）＋ 分割ロングピンソケット 7ピン×2（基板側）", "1組", "C1〜C7・I1〜I7"],
    ["（交換用・任意）OV5640 カメラ（XIAO ESP32S3 Sense 用、自動ピント）", "1", "近い巣門にピントを合わせたいとき。カメラの差し込み口で付け替える"],
    ["microSD カード 32GB 以下（FAT32。例: LAZOS 8GB）", "1", "Sense 基板の枠に差す。写真をためる"],
    ["ショットキーバリアダイオード 40V1A 1N5819", "1", "F10（アノード）〜J10（カソード、帯の側）。寝かせて付ける"],
    ["金属皮膜抵抗 100kΩ 1%", "2", "E10〜E12、E14〜E16（縦に寝かせて付ける）"],
    ["積層セラミックコンデンサー 0.1µF 50V C0G 5mm", "1", "F13・F15（測る点のノイズよけ）"],
    ["絶縁ラジアルリード型積層セラミックコンデンサー 100µF 6.3V 5mm", "1", "H9・J9（Wi-Fi で送る瞬間の電圧の下がりよけ）"],
    ["ターミナルブロック 2P 緑（5.08mm、秋月電子 20円）", "1", "F19（電池＋）・H19（電池−）。線を入れる口を下に向ける"],
    ["被覆線（ジャンパー線）", "少し", "表で E9 → D9 → D17 → F17 と這わせる"],
    ["単3×3 電池ボックス（リード線・フタ・スイッチ付）／ 単三リチウム電池", "1／3", "＋の線を端子台の F19、−の線を H19 にねじで留める"],
    ["ユニバーサル基板 共立プロダクツ KP-1228D（穴 12×28、2.54mm。モノタロウ 159円）・すずめっき線", "1", "縦に置く。四隅の 2×2 はねじ止めで使わない"],
    ["ケース タカチ BCAP091207T（透明ふた、内寸 66×96×56.6mm）", "1", "カメラをふたに近づける"],
    ["ポール取付金具 タカチ SSK-S1", "1", "ケースを杭やポールに留める"],
    ["屋外用ルーフ タカチ BRF-90G", "1", "ケースの上に付ける雨・日よけ（中が熱くなりにくい）"],
    ["乾燥剤（小袋）", "少し", "くもり止め"],
], [620, 60, 480], "部品表")
finish(s, y, [
    "・上の図は部品を載せる面（表）から見たところ。部品はすべて表から差し、足を裏ではんだ付けする。線はすべて裏のすずめっき線。",
    "・下の「裏から見た図」が実際に配線する向き（左右が逆）。裏の線どうしは交わらない。",
    "・XIAO は一番上（1〜7 行）。USB-C は基板の上の端からはみ出す。D0 への線（D 列）と GND の線（H 列）は XIAO の下（裏）を通す。",
    "・D 列の線は XIAO のピン（C 列）のすぐとなりを通るので、はんだがピンの穴とくっつかないように気をつける。",
    "・電池は ダイオード 1N5819 を通して XIAO の「5V」端子へ入れる（USB をつないだとき、USB の 5V が電池へ流れ込まないように）。",
    "・XIAO 裏の「BAT」パッドは充電式リチウムイオン電池用。乾電池は絶対につながない。",
    "・電池の電圧は ダイオードの前で 100kΩ・100kΩ で半分にして D0(A0) で読む（新品 5.4V で 2.7V）。",
    "・電池＋は端子台 F19 から裏で F17 へ上がり、表の被覆線で E9 へ渡る（裏の黒・黄の線をまたぐため）。被覆線の足は E9 と F17 だけで、D9・D17 では曲げるだけ。",
    "・20〜26 行は空いている。KP-1228D の穴の数は商品写真から数えた（12×28）。差す前に実物で確かめる。",
    "・まだ組んで確かめていない。",
], "HPX-CAM_KP1228D_pcb.svg")

# ================================================================== HPX-Core を FireBeetle 2 ESP32-E（DFRobot DFR0654）で（TARO 2026-11-13）
# TARO 2026-11-13: HPX-Core の配線図を全部 FireBeetle 2 ESP32-E に描き直す。前の図（ELEGOO・Olimex）は *_elegoo_* / *_olimex_* の名前で残す。
# FireBeetle 2 ESP32-E のピンの並びは DFRobot の資料（wiki・回路図）から。25.4×60mm、左右のピン列は 9穴（22.86mm）離れている（TARO 確認: 1列目と10列目に差さる）。
#   USB-C を上にしたとき 左の列 18ピン（上から RST…RX）、右の列 14ピン（上から VCC…IO17。左の列の 5本目 EN の高さから）。
#   RX は USB-C と反対の端から 5.5mm、RST は USB-C の端から 11.3mm。
# ピンヘッダ（オス）は付いている（TARO 2026-11-13）。基板にはピンソケット（メス）18ピン・14ピンをはんだ付けして差す。
import re as _re3
FB18 = ["RST", "3V3", "NC", "GND", "EN", "IO18", "IO23", "IO19", "IO22", "IO21", "IO2", "IO13", "IO14", "IO0", "IO26", "IO25", "TX", "RX"]
FB14 = ["VCC", "3V3", "GND", "SCL", "SDA", "IO15", "IO35", "IO34", "IO39", "IO36", "IO12", "IO4", "IO16", "IO17"]
COLS = [chr(65 + i) for i in range(26)] + ["AA"]
def hole(h):
    m = _re3.match(r"([A-Z]+)(\d+)$", h)
    return (COLS.index(m.group(1)), int(m.group(2)) - 1)
def w(color, *holes):
    wireh(s, [hole(h) for h in holes], color)
def fb(cl, cr, r1, usb_down, used, sock):
    """FireBeetle 2 ESP32-E を描く。cl・cr は列の文字、r1 は 18ピンの列のいちばん上の行（1から）。
    USB-C が下: 左の列に 14ピン（上から IO17…VCC、r1〜r1+13）、右の列に 18ピン（上から RX…RST、r1〜r1+17）
    USB-C が上: 左の列に 18ピン（上から RST…RX、r1〜r1+17）、右の列に 14ピン（上から VCC…IO17、r1+4〜r1+17）"""
    cl, cr = COLS.index(cl), COLS.index(cr)
    if usb_down:
        cols = [(cl, r1, list(reversed(FB14)), "L"), (cr, r1, list(reversed(FB18)), "R")]
        top, bot = r1 - 1 - 2.17, r1 - 1 + 17 + 4.45
    else:
        cols = [(cl, r1, FB18, "L"), (cr, r1 + 4, FB14, "R")]
        top, bot = r1 - 1 - 4.45, r1 - 1 + 17 + 2.17
    x1, y1 = OX + (cl - 0.5) * P, OY + top * P
    x2, y2 = OX + (cr + 0.5) * P, OY + bot * P
    cx = (x1 + x2) / 2
    s.top.append(f'<rect x="{x1}" y="{y1}" width="{x2-x1}" height="{y2-y1}" rx="10" fill="#37474f" fill-opacity="0.72" stroke="#102027" stroke-width="3"/>')
    rec_part(x1, y1, x2 - x1, y2 - y1, "FireBeetle 2")
    # ESP32-WROOM-32E（18×25.5mm）は USB-C と反対の端。アンテナがいちばん端
    mw, mh = 18 / 2.54 * P, 25.5 / 2.54 * P
    my = y1 + 4 if usb_down else y2 - 4 - mh
    s.top.append(f'<rect x="{cx-mw/2}" y="{my}" width="{mw}" height="{mh}" rx="4" fill="#b0bec5" fill-opacity="0.85" stroke="#78909c" stroke-width="2"/>')
    ay = my if usb_down else my + mh - 90
    s.top.append(f'<rect x="{cx-mw/2}" y="{ay}" width="{mw}" height="90" rx="4" fill="#212121" fill-opacity="0.8"/>')
    s.text(cx, ay + 52, "アンテナ", 12, "middle", "bold", "#eee", layer=s.top)
    ty = my + 150 if usb_down else my + 70
    s.text(cx, ty, "ESP32-", 15, "middle", "bold", "#263238", layer=s.top)
    s.text(cx, ty + 20, "WROOM-32E", 15, "middle", "bold", "#263238", layer=s.top)
    uy = y2 - 24 if usb_down else y1 - 20
    s.top.append(f'<rect x="{cx-36}" y="{uy}" width="72" height="44" rx="10" fill="#bdbdbd" stroke="#616161" stroke-width="2"/>')
    s.text(cx, (y2 + 46) if usb_down else (y1 - 30), "USB-C（下向き）" if usb_down else "USB-C（上向き）", 13, "middle", "bold", "#333", layer=s.top)
    ly = (my + mh + 50) if usb_down else (y1 + 70)
    s.text(cx, ly, "FireBeetle 2", 16, "middle", "bold", "#fff", layer=s.top)
    s.text(cx, ly + 20, "ESP32-E", 15, "middle", "bold", "#fff", layer=s.top)
    s.text(cx, ly + 42, sock, 12, "middle", "bold", "#eee", layer=s.top)
    for c, r0, names, side in cols:
        for i, n in enumerate(names):
            r = r0 - 1 + i
            x, y = H(c, r)
            hname = f"{COLS[c]}{r+1}"
            if 0 <= r < NR:
                rec_pin(x, y, n)
            on = hname in used
            s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="{"#d4af37" if on else "#9e9e9e"}" stroke="#5d4520" stroke-width="2"/>')
            dx = 14 if side == "L" else -14
            s.text(x + dx, y + 5, n, 12, "start" if side == "L" else "end", "bold", "#fff" if on else "#ddd", layer=s.top)
    return x1, y1, x2, y2
def fb_w5500(r):
    """W5500（LAN 差し込み口が下）。ピン列は N 列と V 列、r〜r+5 行"""
    x1, y1, x2, y2 = module(13 - 0.53, 21 + 0.53, r - 1 - 0.5, r - 1 - 0.5 + 9.84, "#1565c0", "#0d3c7a", "W5500")
    s.top.append(f'<rect x="{(x1+x2)/2-34}" y="{y1+30}" width="68" height="68" rx="3" fill="#212121" fill-opacity="0.85"/>')
    s.text((x1 + x2) / 2, y1 + 70, "W5500", 13, "middle", "bold", "#eee", layer=s.top)
    s.top.append(f'<rect x="{(x1+x2)/2-80}" y="{y2-150}" width="160" height="170" rx="6" fill="#cfd8dc" fill-opacity="0.75" stroke="#607d8b" stroke-width="2"/>')
    s.text((x1 + x2) / 2, y2 - 60, "LAN 差し込み口", 13, "middle", "bold", "#37474f", layer=s.top)
    s.text((x1 + x2) / 2, y2 + 46, "LAN ケーブル（下へ）", 13, "middle", "bold", "#333", layer=s.top)
    s.text((x1 + x2) / 2, y1 + 120, "W5500 モジュール", 14, "middle", "bold", "#fff", layer=s.top)
    s.text((x1 + x2) / 2, y1 + 140, "ピンソケット 6ピン×2 に差す", 12, "middle", "bold", "#eee", layer=s.top)
    pins(13, r, ["MI", "RST", "NC", "V", "V2", "G"], "L", {"MI", "RST", "V2", "G"})
    pins(21, r, ["INT", "CS", "SCK", "MO", "G2", "G3"], "R", {"INT", "CS", "SCK", "MO"})

FB_PARTS = ["DFRobot FireBeetle 2 ESP32-E（DFR0654、ピンヘッダ付き）", "1"]
FB_NOTE_PITCH = "・FireBeetle 2 の左右のピン列は 9穴離れている（TARO さん確認）。ピンの並びは DFRobot の資料から。差す前に、基板の印字とピンの名前を見くらべる。"
FB_NOTE_LED = "・FireBeetle 2 の GPIO5 は基板の上の RGB LED が使っていて、ピンは出ていない。GPIO2 は基板の青い LED、GPIO27 は基板のボタン（ファーム v1.25 では工場出荷リセットに使える）。"
LAN_WIRES = [
    ("purple", "IO25（K14）", ["K14", "U14", "U22", "V22"], "INT（V22）"),
    ("orange", "IO26（K15）", ["K15", "T15", "T23", "V23"], "CS（V23）"),
    ("yellow", "IO14・SCK（K17）", ["K17", "S17", "S24", "V24"], "SCK（V24）"),
    ("blue", "IO13・MOSI（K18）", ["K18", "R18", "R25", "V25"], "MO（V25）"),
    ("green", "IO19・MISO（K22）", ["K22", "N22"], "MI（N22）"),
    ("white", "IO23（K23）", ["K23", "N23"], "RST（N23）"),
    ("black", "GND（K26）", ["K26", "L26", "L27", "M27", "N27"], "G（N27）"),
    ("red", "3V3（K28）", ["K28", "O28", "O26", "N26"], "V（N26）"),
]
LAN_USED = {"K14", "K15", "K17", "K18", "K22", "K23", "K26", "K28"}
def lan_rows():
    return [[c, a, " → ".join(hs), b] for c, a, hs, b in LAN_WIRES]

# ------------------------------------------------------------------ ＋有線LAN
OX, OY = 260, 200
s = S(1500, 3000)
title(s, "HPX-Core（親機）＋有線LAN 基板の配線図", "ユニバーサル基板 6×8cm（穴 A〜Y × 1〜33）縦置き ／ FireBeetle 2 ESP32-E ＋ W5500 ／ ファーム HPX_Core v1.25")
board(s, 25, 33, "ユニバーサル基板 6×8cm を縦に置いたところ（穴 A〜Y × 1〜33）")
fb("B", "K", 12, True, LAN_USED, "ピンソケット 18ピン・14ピンに差す")
fb_w5500(22)
for c, a, hs, b in LAN_WIRES:
    w(c, *hs)
yb = back_section(s, OY + (NR - 1) * P + 160, "HPX-Core_LAN_pcb.svg")
y = table(s, 60, yb + 40, [["線", "FireBeetle のピン（穴）", "通る穴", "W5500 のピン（穴）"]] + lan_rows(), [100, 320, 420, 380], "つなぐ線（穴の位置は 列の文字＋行の数字）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    FB_PARTS + ["B12〜B25（14ピン）・K12〜K29（18ピン）。USB-C を下に向ける"],
    ["ピンソケット（メス）18ピン・14ピン", "各1", "K12〜K29、B12〜B25 にはんだ付けして FireBeetle を差す"],
    ["W5500 イーサネットモジュール（ACEIRMC、WIZ820io と同じピン）", "1", "N22〜N27・V22〜V27。LAN 差し込み口を下に向ける"],
    ["ピンソケット（メス）6ピン", "2", "N22〜N27、V22〜V27 にはんだ付けして W5500 を差す"],
    ["ユニバーサル基板 6×8cm（両面スズメッキ、穴 25×33）・すずめっき線", "1", "縦に置く。線はすべて裏"],
], [620, 60, 520], "部品表")
finish(s, y, [
    "・上の図は部品を載せる面（表）から見たところ。ピンソケットは表から差し、足を裏ではんだ付けする。",
    "・線はすべて裏（はんだ面）で、すずめっき線を穴から穴へはんだ付けしてつなぐ。上の図では点線で透かして描き、下の「裏から見た図」が実際に配線する向き（左右が逆）。",
    "・裏の線どうしは交わらず、ほかのピンの穴の上も通らない。FireBeetle・W5500 の下を通る線は、裏なので部品には触れない。",
    "・W5500 につなぐ 6本の信号は、FireBeetle の右の列（18ピンの列）にまとめた: INT＝IO25、CS＝IO26、SCK＝IO14、MOSI＝IO13、MISO＝IO19、RST＝IO23。",
    "・このピンはファーム HPX_Core v1.25 から。v1.24 までは ELEGOO ESP-32 用のピンなので、FireBeetle では有線LAN が動かない。",
    "・灰色のピンは使わない（W5500 の NC、もう1本の V、右の列の G 2本）。",
    FB_NOTE_PITCH,
    "・W5500 のピンがモジュールの上下のどこにあるかは分からない（実物で確かめる）。ずれていたら、W5500 と線の端を同じ数だけ上下にずらせばよい。",
    FB_NOTE_LED,
], "HPX-Core_LAN_pcb.svg")

# ------------------------------------------------------------------ 基本モデル（＋有線LAN から W5500 を外したもの）
OX, OY = 260, 200
s = S(1500, 3000)
title(s, "HPX-Core（親機）基本モデル 基板の配線図", "ユニバーサル基板 6×8cm（穴 A〜Y × 1〜33）縦置き ／ FireBeetle 2 ESP32-E ／ Wi-Fi ＋ USB 給電")
board(s, 25, 33, "ユニバーサル基板 6×8cm を縦に置いたところ（「＋有線LAN」と同じ基板・同じ位置。W5500 は付けない）")
fb("B", "K", 12, True, set(), "ピンソケット 18ピン・14ピンに差す")
_x1, _y1 = OX + (13 - 0.53) * P, OY + (22 - 1 - 0.5) * P
s.top.append(f'<rect x="{_x1}" y="{_y1}" width="{9.06*P}" height="{9.84*P}" rx="10" fill="none" stroke="#9e9e9e" stroke-width="2.5" stroke-dasharray="10 7"/>')
s.text(_x1 + 4.53 * P, _y1 + 4.4 * P, "空き（＋有線LAN では", 13, "middle", "bold", "#757575", layer=s.top)
s.text(_x1 + 4.53 * P, _y1 + 5.2 * P, "ここに W5500 を差す）", 13, "middle", "bold", "#757575", layer=s.top)
yb = back_section(s, OY + (NR - 1) * P + 160, "HPX-Core_BASIC_pcb.svg")
y = table(s, 60, yb + 40, [
    ["部品", "数", "置き場所・メモ"],
    FB_PARTS + ["B12〜B25（14ピン）・K12〜K29（18ピン）。USB-C を下に向ける"],
    ["ピンソケット（メス）18ピン・14ピン", "各1", "K12〜K29、B12〜B25 にはんだ付けして FireBeetle を差す"],
    ["ユニバーサル基板 6×8cm（両面スズメッキ、穴 25×33）", "1", "縦に置く。ケース PFF10-4-10W に AST2.5-5B で留める"],
], [620, 60, 520], "部品表")
finish(s, y, [
    "・基本モデルは、ルーターへ Wi-Fi でつなぎ、FireBeetle の USB-C から電気をもらいます。基板の上の線はありません（ピンソケットのはんだ付けだけ）。",
    "・「＋有線LAN」と同じ基板・同じ位置です。あとから W5500 とピンソケット 6ピン×2・線8本を足せば「＋有線LAN」にできます（HPX-Core_LAN_pcb.png）。",
    "・ファームウェアは HPX_Core v1.25 以降を使い、初回設定で接続方法を「Wi-Fi」にします（Wi-Fi だけなら v1.24 でも動く）。",
    FB_NOTE_PITCH,
    "・FireBeetle の電池コネクタ（PH2.0）は充電式リチウムイオン電池用。基本モデルでは何もつながない。",
    "・雨のかかる屋外に置くときは、防水の版（＋防水）を使ってください。",
], "HPX-Core_BASIC_pcb.svg")

# ------------------------------------------------------------------ ＋有線LAN＆ソーラーシステムチェック
# 「＋有線LAN」と同じ FireBeetle・W5500・8本の線に、上の空いた所（1〜11行）へ 12V バッテリー・パネルの電圧を測る部品と BP5293-50 を足す。
# パネル＝IO39（v1.25 で 34 から変更。FireBeetle の IO34 は基板の上で電池コネクタにつながっているため）、バッテリー＝IO35。5V は VCC へ。
OX, OY = 260, 200
s = S(1700, 3500)
title(s, "HPX-Core（親機）＋有線LAN＆ソーラーシステムチェック 基板の配線図", "6×8cm（穴 A〜Y × 1〜33）縦置き ／ FireBeetle 2 ESP32-E ＋ W5500 ＋ 電圧チェック ／ ファーム HPX_Core v1.25")
board(s, 25, 33, "ユニバーサル基板 6×8cm を縦に置いたところ（穴 A〜Y × 1〜33）")
fb("B", "K", 12, True, LAN_USED | {"B17", "B19", "B23", "B25"}, "ピンソケット 18ピン・14ピンに差す")
fb_w5500(22)
for c, a, hs, b in LAN_WIRES:
    w(c, *hs)
B100 = ["#8d5524", "#212121", "#f9a825"]
res_flat("I1", "I5", "100kΩ", B100, (-2000, -2000))         # パネル＋ → 測る点
res_stand(s, hole("H5"), hole("H3"), "8.2kΩ", ["#9e9e9e", "#e53935", "#e53935"], -2000, -2000)   # 測る点 → GND（立てる）
cap_flat(s, hole("G3"), hole("G5"), "0.1µF")
res_flat("L3", "L7", "15kΩ", ["#8d5524", "#4caf50", "#f57c00"], (-2000, -2000))   # GND → バッテリーの測る点
cap_flat(s, hole("M5"), hole("M7"), "0.1µF")
res_flat("N7", "N11", "100kΩ", B100, (-2000, -2000))         # 測る点 → バッテリー＋
# BP5293-50（足 R9・S9・T9 = 左から VOUT・GND・VIN。印字面を奥（上）に向けて立てる）
x1, y1 = H(*hole("R9")); x2, _ = H(*hole("T9"))
s.top.append(f'<rect x="{x1-26}" y="{y1-34}" width="{x2-x1+52}" height="22" rx="4" fill="#263238" fill-opacity="0.9" stroke="#000" stroke-width="2"/>')
rec_part(x1 - 26, y1 - 34, x2 - x1 + 52, 22, "")
s.text((x1 + x2) / 2, y1 - 18, "BP5293-50", 12, "middle", "bold", "#fff", layer=s.top)
for i, n in enumerate(["VOUT", "GND", "VIN"]):
    x, y = H(COLS.index("R") + i, 8)
    rec_pin(x, y, n)
    s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="#d4af37" stroke="#5d4520" stroke-width="2"/>')
    tag(x, y + 24, n, 10, "middle", w=len(n) * 7 + 8)
cap_flat(s, hole("V9"), hole("V11"), "10µF", True)
# 端子台 3極（5.08mm）を右の端に縦に。X1 = パネル＋、X3 = マイナス、X5 = バッテリー＋。線を入れる口は右向き
x1, y1 = H(*hole("X1")); _, y2 = H(*hole("X5"))
s.top.append(f'<rect x="{x1-14}" y="{y1-30}" width="{P+36}" height="{y2-y1+60}" rx="5" fill="#2e7d32" fill-opacity="0.9" stroke="#1b5e20" stroke-width="2"/>')
rec_part(x1 - 14, y1 - 30, P + 36, y2 - y1 + 60, "")
for h, n, col in (("X1", "パネル＋", "blue"), ("X3", "マイナス", "black"), ("X5", "バッテリー＋", "red")):
    x, y = H(*hole(h))
    rec_pin(x, y, n)
    s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="#d4af37" stroke="#5d4520" stroke-width="2"/>')
    s.top.append(f'<circle cx="{x+P}" cy="{y}" r="13" fill="#bdbdbd" stroke="#424242" stroke-width="2"/>')
    s.add(f'<path d="M{x+P+16},{y} L{x+P+100},{y}" fill="none" stroke="{COL[col]}" stroke-width="7"/>')
    s.text(x + P + 108, y + 5, n, 13, "start", "bold", COL[col] if col != "black" else "#212121")
s.text(H(*hole("X5"))[0] + P + 108, H(*hole("X5"))[1] + 26, "（＋は 1A ヒューズ経由）", 11, "start", fill="#555")
lx, ly = OX + 24 * P + 110, OY + 9 * P
s.text(lx, ly, "上の部品（穴）", 14, weight="bold")
for i, (a_, b_) in enumerate([("I1〜I5", "100kΩ（パネル＋ → 測る点）"), ("H3・H5", "8.2kΩ（立てる。H5 が本体）"), ("G3・G5", "0.1µF（パネル）"),
                             ("L3〜L7", "15kΩ（GND → 測る点）"), ("M5・M7", "0.1µF（バッテリー）"), ("N7〜N11", "100kΩ（測る点 → バッテリー＋）"),
                             ("R9・S9・T9", "BP5293-50（VOUT・GND・VIN）"), ("V9・V11", "10µF（BP5293 の入力側）"), ("X1・X3・X5", "端子台 3極")]):
    s.text(lx, ly + 30 + i * 44, a_, 13, weight="bold", fill="#5d4520")
    s.text(lx, ly + 47 + i * 44, b_, 12, fill="#333")
LANV_WIRES = [
    ("black", "GND（左の列 B23）", ["B23", "A23", "A3", "G3", "H3", "L3", "M3", "S3", "V3", "X3"], "0.1µF（G3）・8.2kΩ（H3）・15kΩ（L3）・端子台 マイナス（X3）"),
    ("black", "（とちゅうの M3・S3・V3）", ["M3", "M5"], "0.1µF（M5）"),
    ("black", "", ["S3", "S9"], "BP5293 GND（S9）"),
    ("black", "", ["V3", "V9"], "10µF（V9）"),
    ("blue", "端子台 パネル＋（X1）", ["X1", "I1"], "100kΩ（I1）"),
    ("yellow", "IO39（B17）", ["B17", "C17", "C5", "G5", "H5", "I5"], "0.1µF（G5）・8.2kΩ（H5）・100kΩ（I5）"),
    ("green", "IO35（B19）", ["B19", "D19", "D7", "L7", "M7", "N7"], "15kΩ（L7）・0.1µF（M7）・100kΩ（N7）"),
    ("orange", "VCC（B25）", ["B25", "F25", "F9", "R9"], "BP5293 VOUT（R9）"),
    ("red", "端子台 バッテリー＋（X5）", ["X5", "X11", "V11", "T11", "N11"], "10µF（V11）・100kΩ（N11）"),
    ("red", "", ["T11", "T9"], "BP5293 VIN（T9）"),
]
for c, a, hs, b in LANV_WIRES:
    w(c, *hs)
yb = back_section(s, OY + (NR - 1) * P + 160, "HPX-Core_LANV_pcb.svg")
y = table(s, 60, yb + 40, [["線", "どこから（穴）", "通る穴", "どこへ（穴）"]] + [[c, a, b_, d] for c, a, b_, d in
          [[c, a, " → ".join(hs), "W5500 " + b] for c, a, hs, b in LAN_WIRES] + [[c, a, " → ".join(hs), b] for c, a, hs, b in LANV_WIRES]],
          [100, 300, 520, 500], "つなぐ線（穴の位置は 列の文字＋行の数字。上の 8本は「＋有線LAN」と同じ）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["DFRobot FireBeetle 2 ESP32-E（DFR0654、ピンヘッダ付き）＋ ピンソケット 18ピン・14ピン", "1", "B12〜B25・K12〜K29。USB-C を下に向ける（「＋有線LAN」と同じ）"],
    ["W5500 イーサネットモジュール ＋ ピンソケット 6ピン×2", "1", "N22〜N27・V22〜V27（「＋有線LAN」と同じ）"],
    ["三端子 DCDC レギュレーター ROHM BP5293-50（入力 7〜26V、5V 1A）", "1", "R9・S9・T9（左から VOUT・GND・VIN。印字面を奥（上）に向けて立てる）"],
    ["積層セラミックコンデンサー 10µF 50V 5mm", "1", "V9・V11（BP5293 の入力側）"],
    ["金属皮膜抵抗 100kΩ 1%", "2", "I1〜I5（パネル用）、N7〜N11（バッテリー用）。寝かせて付ける"],
    ["金属皮膜抵抗 15kΩ 1%", "1", "L3〜L7（バッテリー用、寝かせる）"],
    ["金属皮膜抵抗 8.2kΩ 1%", "1", "H3・H5（パネル用、立てて付ける）"],
    ["積層セラミックコンデンサー 0.1µF 50V 5mm", "2", "G3・G5（パネル用）、M5・M7（バッテリー用）"],
    ["端子台 3極 5.08mm（基板用、ねじ式）", "1", "X1・X3・X5（上から パネル＋・マイナス・バッテリー＋）。線を入れる口を右に向ける"],
    ["ミニ平型ヒューズホルダー ＋ 1A ミニ平型ヒューズ", "2", "バッテリー＋とパネル＋の線の途中（コントローラーの近く）"],
    ["ユニバーサル基板 6×8cm（穴 25×33）・すずめっき線", "1", "縦に置く。線はすべて裏"],
], [640, 60, 560], "部品表")
finish(s, y, [
    "・上の図は部品を載せる面（表）から見たところ。部品はすべて表から差し、足を裏ではんだ付けする。線はすべて裏のすずめっき線。",
    "・FireBeetle・W5500 と、その 8本の線は「＋有線LAN」と同じ。上の空いた所（1〜11行）に電圧チェックの部品を足した。",
    "・FireBeetle の電源は USB ではなく、バッテリーから BP5293-50 で作った 5V を VCC（B25）に入れる（マイナスをバッテリーとそろえるため）。",
    "・バッテリーは 100kΩ と 15kΩ で 約1/7.7 に（18V で 2.35V）して IO35 で、パネルは 100kΩ と 8.2kΩ で 約1/13 に（30V で 2.27V）して IO39 で読む。",
    "・パネルのピンは v1.25 から IO39（v1.24 までは IO34）。FireBeetle の IO34 は基板の上で電池コネクタの電圧を測る抵抗がつながっているので使わない。",
    "・ファーム v1.25 の動作設定で「バッテリー・パネルの電圧を測る」にチェックし、テスターで測った値を入れて1回合わせる。",
    "・USB で書き込むときは、端子台の線を外すかバッテリー＋のヒューズを抜く（念のため。FireBeetle は USB の 5V の後ろにダイオードがある）。",
    "・FireBeetle の電池コネクタ（PH2.0）には何もつながない。VCC に 5V を入れると、基板の中のスイッチを通って電池コネクタにも 5V が出る（FireBeetle の回路図から。充電の部品に悪くないかは未確認）。",
    "・BP5293-50 の入力は 26V まで。パネル＋にはつながない。足の並びは秋月の資料（データシート）で確かめてから差す。",
    "・マイナスはコントローラーの「バッテリー−」へ。「パネル−」と「バッテリー−」がつながっている（共通マイナス）コントローラーでだけ、パネル電圧を正しく測れる。",
    FB_NOTE_PITCH,
], "HPX-Core_LANV_pcb.svg")

# ------------------------------------------------------------------ ＋防水＆電池駆動（タカチ PCB36-47）
# FireBeetle（USB-C が下）。右の列（18ピン）の RX を K1 に置くと RST（18本目）は基板の下の端からはみ出す → ソケットは 17ピン。
# 電池の＋は VCC（B14）、−は GND（B12）。FireBeetle の VCC は 3.3V を作る部品（RT9080）の入口。
OX, OY = 520, 420
s = S(1500, 3000)
title(s, "HPX-Core（親機）＋防水＆電池駆動の配線図", "タカチ PCB36-47（穴 A〜M × 1〜17 の見込み）／ FireBeetle 2 ESP32-E ／ 単三リチウム ×3 → VCC")
board(s, 13, 17, " ")
s.text(OX + 6 * P, 120, "ケース用基板 PCB36-47（36×47mm）を縦に置いたところ。穴 13×17 は見込み、四隅は取付穴", 15, "middle", "bold", "#5d4520")
for c, r in ((0, 0), (12, 0), (0, 16), (12, 16)):
    x, y = H(c, r)
    s.add(f'<circle cx="{x}" cy="{y}" r="15" fill="#fffdf6" stroke="#8d6e3f" stroke-width="2"/>')
    s.add(f'<circle cx="{x}" cy="{y}" r="7" fill="#c8a165" stroke="#8d6e3f" stroke-width="1.5"/>')
REC["board"] = REC["board"] + s.items[-8:]
fx1, fy1, fx2, fy2 = fb("B", "K", 1, True, {"B12", "B14"}, "ソケット 17ピン・14ピンに差す")
tp, tm = H(*hole("A14")), H(*hole("A12"))
bx0, by0 = 60, fy2 + 110
s.rect(bx0, by0, 440, 80, "#455a64", "#263238", 10, 3)
for i in range(3):
    s.rect(bx0 + 18 + i * 140, by0 + 18, 124, 44, "#c0ca33", "#827717", 20, 2)
    s.text(bx0 + 80 + i * 140, by0 + 46, "単3", 15, "middle", "bold", "#333")
s.text(bx0 + 426, by0 + 24, "＋", 16, "end", "bold", "#fff")
s.text(bx0 + 14, by0 + 24, "−", 18, "start", "bold", "#fff")
s.text(bx0 + 220, by0 + 106, "ケース WH145-25-M3 の電池ボックス ＋ 単三リチウム電池 ×3（直列 約4.5〜5.4V）", 14, "middle", "bold")
s.wire([(bx0 + 440, by0 + 40), (bx0 + 470, by0 + 40), (bx0 + 470, fy2 + 50), (OX - 120, fy2 + 50), (OX - 120, tp[1]), tp], "red")
s.wire([(bx0, by0 + 40), (bx0 - 30, by0 + 40), (bx0 - 30, tm[1]), tm], "black")
solder_mark(s, *hole("A14")); solder_mark(s, *hole("A12"))
w("red", "A14", "B14")      # 電池＋ → VCC
w("black", "A12", "B12")    # 電池− → GND
check_back("HPX-Core_BAT_pcb.svg")
yb = backview(s, by0 + 140, OX + (NC - 1) * P / 2, fy1 - 90, fy2 + 60)
y = table(s, 60, yb + 40, [
    ["線", "どこから（穴）", "どこへ（穴）", "役目"],
    ["red", "電池ボックスの＋の線（A14 に直接はんだ付け）", "裏で A14 → B14（FireBeetle の VCC、左の列のいちばん下）", "電池の＋を VCC へ"],
    ["black", "電池ボックスの−の線（A12 に直接はんだ付け）", "裏で A12 → B12（FireBeetle の GND、左の列の下から3本目）", "電池の−を GND へ"],
], [100, 400, 480, 220], "つなぐ線（穴の位置は 列の文字＋行の数字）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    FB_PARTS + ["B1〜B14・K1〜K17 のソケットに差す。USB-C を下に向ける"],
    ["ピンソケット（メス）17ピン・14ピン", "各1", "K1〜K17、B1〜B14 にはんだ付け。右の列の 18本目（RST）は基板の外"],
    ["ケース WH145-25-M3-BR（単3×3 電池ボックス付き）／ 単三リチウム電池", "1／3", "＋の線を A14、−の線を A12 にはんだ付け"],
    ["ケース用基板 PCB36-47・基板スペーサー AST2.5-5B・すずめっき線", "1", "線は裏の2本だけ"],
], [600, 60, 540], "部品表")
finish(s, y, [
    "・上の図は部品を載せる面（表）から見たところ。ピンソケットは表から差し、足を裏ではんだ付けする。下の「裏から見た図」が実際に配線する向き（左右が逆）。",
    "・電池は FireBeetle の「VCC」（左の列のいちばん下）と「GND」へつなぐ。電池コネクタ（PH2.0）は充電式リチウムイオン電池用なので、乾電池は絶対につながない。",
    "・電池を入れたまま USB-C をつながない（書き込みは電池を外してから）。",
    "・新品の単三リチウム3本は約5.4V。VCC の先の 3.3V を作る部品（RT9080）の入力の上限は 5.5V と覚えているが、資料ではまだ確かめていない。余裕は少ない。",
    "・VCC に電池をつなぐと、基板の中のスイッチを通って電池コネクタ（PH2.0）と IO34 の抵抗（1/2 にする）にも電池の電圧がかかる（回路図から）。IO34 で電池の電圧を読めるかもしれない（ファームはまだ使っていない）。",
    "・FireBeetle は長さ 60mm で基板（47mm）より長い。上の端は約2mm、USB-C の側は約11mm 基板からはみ出す。ケースに収まるかは未確認。",
    "・PCB36-47 の穴の数（13×17）は見込み。ケースの深さ（内寸13mm の見込み）に、スペーサー5mm＋基板＋ソケット＋FireBeetle が収まるかも未確認。足りないときはソケットを使わずに直接はんだ付けする。",
    "・眠っている間の電流を減らすには、FireBeetle の裏の「低消費電力パッド」を切る（約0.5mA 減る。切ると電源の LED が消える）。",
    "・管理画面の動作モードは「ディープスリープ」にする。電池のもちは未実測。",
], "HPX-Core_BAT_pcb.svg")

# ------------------------------------------------------------------ ＋LTE-M（タカチ PCB72-95、ケース タカチ OB10-17-5W）
# FireBeetle（USB-C が上）を左端に。左の列（18ピン）A9〜A26、右の列（14ピン）J13〜J26。
# 5V（HPX-Solar から USB-C）→ FireBeetle VCC。CatM の 5V は P-MOSFET で入り切り（IO4）。D+ → IO39（電池 1/2）、D− → IO36（パネル 1/6）。
OX, OY = 200, 300
s = S(1600, 4400)
title(s, "HPX-Core（親機）＋LTE-M 基板の配線図", "タカチ PCB72-95（穴 A〜AA × 1〜36）縦置き ／ FireBeetle 2 ESP32-E ＋ M5Stamp CatM ／ ファーム HPX_Core v1.25")
board(s, 27, 36, "タカチ PCB72-95 を縦に置いたところ（穴 A〜AA × 1〜36。上下 3行は D〜X だけ）")
_i0 = len(s.items)
for r in (0, 1, 2, 33, 34, 35):
    for c in (0, 1, 2, 24, 25, 26):
        x, y = H(c, r)
        s.add(f'<circle cx="{x}" cy="{y}" r="9" fill="#e8d3a8"/>')
for c, r in ((0.9, 0.9), (25.1, 0.9), (0.9, 34.1), (25.1, 34.1)):
    x, y = OX + c * P, OY + r * P
    s.add(f'<circle cx="{x}" cy="{y}" r="26" fill="#fffdf6" stroke="#8d6e3f" stroke-width="2"/>')
REC["board"] = REC["board"] + s.items[_i0:]
fb("A", "J", 9, False, {"J13", "J15", "J21", "J22", "J24", "J25", "J26"}, "ピンソケット 18ピン・14ピンに差す")
B100 = ["#795548", "#212121", "#fdd835", "#c9a227"]
B10 = ["#795548", "#212121", "#fb8c00", "#c9a227"]
def tr3(hs, names, label, fill):
    pts = [H(*hole(h)) for h in hs]
    x1, x2, y = pts[0][0], pts[-1][0], pts[0][1]
    s.top.append(f'<rect x="{x1-16}" y="{y-34}" width="{x2-x1+32}" height="30" rx="6" fill="{fill}" stroke="#222" stroke-width="2"/>')
    s.text((x1 + x2) / 2, y - 14, label, 11, "middle", "bold", "#fff", layer=s.top)
    for (x, yy), n in zip(pts, names):
        rec_pin(x, yy, n)
        s.top.append(f'<circle cx="{x}" cy="{yy}" r="7" fill="#9e9e9e" stroke="#333" stroke-width="1.5"/>')
        s.text(x, yy + 22, n, 11, "middle", "bold", "#5d4520", layer=s.top)
res_flat("L24", "P24", "10kΩ", B10, (-2000, -2000))                    # IO4 → ベース
tr3(["Q24", "R24", "S24"], ["B", "C", "E"], "NPN", "#455a64")
res_stand(s, hole("Q23"), hole("Q22"), "100kΩ", B100, -2000, -2000)    # ベース → GND（立てる）
tr3(["U19", "V19", "W19"], ["D", "G", "S"], "P-MOSFET", "#37474f")
res_flat("V14", "V18", "100kΩ", B100, (-2000, -2000))                  # 5V → ゲート
lx = OX + 26 * P + 70
s.text(lx, OY + 13 * P, "基板の上の部品", 14, weight="bold")
for i, (a_, b_) in enumerate([("L24〜P24", "10kΩ（IO4 → ベース）"), ("Q23・Q22", "100kΩ（ベース → GND、立てる）"), ("Q24・R24・S24", "NPN トランジスタ（B・C・E）"),
                             ("U19・V19・W19", "P-MOSFET（D・G・S）"), ("V14〜V18", "100kΩ（5V → ゲート）")]):
    s.text(lx, OY + 13 * P + 30 + i * 46, a_, 13, weight="bold", fill="#5d4520")
    s.text(lx, OY + 13 * P + 48 + i * 46, b_, 12, fill="#333")
# M5Stamp CatM（30×20mm）は下の空いた所（M〜X 列、27〜33 行）に両面テープで貼る
mx1, my1 = OX + 12 * P - 14, OY + 26 * P - 10
mx2, my2 = OX + 23 * P + 14, OY + 32 * P + 10
s.top.append(f'<rect x="{mx1}" y="{my1}" width="{mx2-mx1}" height="{my2-my1}" rx="8" fill="#eceff1" fill-opacity="0.9" stroke="#90a4ae" stroke-width="3"/>')
rec_part(mx1, my1, mx2 - mx1, my2 - my1, "CatM")
s.text((mx1 + mx2) / 2, my1 + 70, "M5Stamp CatM", 15, "middle", "bold", "#263238", layer=s.top)
s.text((mx1 + mx2) / 2, my1 + 92, "SIM7080G・microSIM", 12, "middle", fill="#455a64", layer=s.top)
s.text((mx1 + mx2) / 2, my1 + 120, "両面テープで貼る", 12, "middle", "bold", "#455a64", layer=s.top)
s.text((mx1 + mx2) / 2, my1 + 140, "TX・RX は M25・M26、5V は U21、GND は X21 へ", 11, "middle", fill="#455a64", layer=s.top)
s.text((mx1 + mx2) / 2, my1 + 158, "アンテナ（IPEX）は右へ", 11, "middle", fill="#455a64", layer=s.top)
LAND = [("L13", "USB-C VBUS（5V）", "start"), ("L15", "USB-C GND", "start"), ("L21", "USB-C D+", "start"), ("L22", "USB-C D−", "start"),
        ("M25", "CatM TX", "start"), ("M26", "CatM RX", "start"), ("U21", "CatM 5V", "end"), ("X21", "CatM GND", "start")]
for h, txt, an in LAND:
    solder_mark(s, *hole(h))
    x, y = H(*hole(h))
    tag(x + (16 if an == "start" else -16), y - 14, txt, 12, an, "#c62828")
LTE_WIRES = [
    ("red", "FireBeetle VCC（J13）", ["J13", "L13", "V13", "W13", "W19"], "USB-C VBUS の線（L13）・P-MOSFET の S（W19）"),
    ("red", "（とちゅうの V13）", ["V13", "V14"], "100kΩ（V14）"),
    ("black", "FireBeetle GND（J15）", ["J15", "L15", "O15", "O22", "O25", "S25", "S24"], "USB-C GND の線（L15）・NPN の E（S24）"),
    ("black", "（とちゅうの O22）", ["O22", "Q22"], "100kΩ（Q22）"),
    ("black", "（とちゅうの S25）", ["S25", "X25", "X21"], "CatM GND の線（X21）"),
    ("white", "IO39（J21）", ["J21", "L21"], "USB-C D+ の線（L21、電池の電圧）"),
    ("blue", "IO36（J22）", ["J22", "L22"], "USB-C D− の線（L22、パネルの電圧）"),
    ("purple", "IO4（J24）", ["J24", "L24"], "10kΩ（L24）"),
    ("purple", "10kΩ（P24）", ["P24", "Q24", "Q23"], "NPN の B（Q24）・100kΩ（Q23）"),
    ("gray", "NPN の C（R24）", ["R24", "R22", "V22", "V19", "V18"], "P-MOSFET の G（V19）・100kΩ（V18）"),
    ("orange", "P-MOSFET の D（U19）", ["U19", "U21"], "CatM 5V の線（U21）"),
    ("yellow", "IO16（J25、受信）", ["J25", "M25"], "CatM TX の線（M25）"),
    ("green", "IO17（J26、送信）", ["J26", "M26"], "CatM RX の線（M26）"),
]
for c, a, hs, b in LTE_WIRES:
    w(c, *hs)
yb = back_section(s, OY + (NR - 1) * P + 160, "HPX-Core_LTE_pcb.svg")
y = table(s, 60, yb + 40, [["線", "どこから（穴）", "通る穴", "どこへ（穴）"]] + [[c, a, " → ".join(hs), b] for c, a, hs, b in LTE_WIRES],
          [100, 300, 480, 560], "つなぐ線（穴の位置は 列の文字＋行の数字）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["DFRobot FireBeetle 2 ESP32-E（DFR0654、ピンヘッダ付き）＋ ピンソケット 18ピン・14ピン", "1", "A9〜A26・J13〜J26（左端に寄せる）。USB-C を上に向ける"],
    ["M5Stamp CatM（SIM7080G）", "1", "M27〜X33 のあたりに両面テープで貼る。TX・RX・5V・GND を赤丸の穴へ短い線で"],
    ["USB-C メス基板（データ対応、VBUS・GND・D+・D− が出ているもの）", "1", "ケースの壁に付け、4本の線を L13・L15・L21・L22 へ"],
    ["P-MOSFET（ロジックレベル、1A 以上）", "1", "U19・V19・W19（D・G・S）。足の並びは部品の資料で確かめて合わせる"],
    ["NPN トランジスタ（2SC1815 など）", "1", "Q24・R24・S24（B・C・E）。足の並びは部品の資料で確かめて合わせる"],
    ["金属皮膜抵抗 100kΩ", "2", "V14〜V18（5V → ゲート、縦）・Q23・Q22（ベース → GND、立てる）"],
    ["金属皮膜抵抗 10kΩ", "1", "L24〜P24（IO4 → ベース、横）"],
    ["ユニバーサル基板 タカチ PCB72-95（穴 27×36）・すずめっき線", "1", "縦に置く。線はすべて裏"],
    ["ケース OB10-17-5W ＋ 取り付けベース OBP10-17 ＋ 基板スペーサー AST3-6B", "1", "四隅の取付穴（φ3.2）を AST3-6B に留める"],
    ["電源: HPX-Solar（別の機器）と USB-C ケーブル（データ対応）", "1", "USB-C ケーブル1本でつなぐ（HPX-Solar_pcb.png）。家では USB 充電器でもよい"],
], [640, 60, 620], "部品表")
finish(s, y, [
    "・上の図は部品を載せる面（表）から見たところ。部品はすべて表から差し、足を裏ではんだ付けする。線はすべて裏のすずめっき線。",
    "・赤丸の穴は、ほかの部品からの線（USB-C メス基板の 4本、M5Stamp CatM の TX・RX・5V・GND）を直接はんだ付けするところ。",
    "・電源は USB-C から（HPX-Solar か USB 充電器）。FireBeetle は VCC ピンから、CatM は P-MOSFET を通した 5V から動かす。FireBeetle の電池コネクタ（PH2.0）には何もつながない。",
    "・CatM の 5V は IO4 が High のときだけ入る（NPN が P-MOSFET のゲートを GND へ引く）。眠っている間と起動のときは 100kΩ で切れたまま。",
    "・HPX-Solar のときは、D+ に電池の電圧（1/2）、D− にパネルの電圧（1/6）が来る。FireBeetle では D+ を IO39 で読む（v1.25 から。v1.24 までは IO34）。動作設定の「電圧の測り方」で「HPX-Solar」を選ぶ。",
    "・USB 充電器につなぐときは「測らない」にする（充電器は D+・D− に独自の電圧を出すことがある）。USB-C ケーブルは「データ対応」のものを使う。",
    "・CatM の TX・RX（IO16・IO17）と 5V のスイッチ（IO4）のピンは v1.24 と同じ。",
    "・VCC に 5V を入れると、FireBeetle の中のスイッチを通って電池コネクタにも 5V が出る（回路図から。充電の部品に悪くないかは未確認）。",
    "・穴の数（27×36、上下 3行は D〜X）は TARO さんの寸法図から読んだ。FireBeetle のピンの並びは DFRobot の資料から。差す前に実物で確かめる。",
    "・M5Stamp CatM は基板に貼るだけで、穴には差さない。アンテナのケーブル（SMA）と USB-C メス基板は、ケースの穴から防水して付ける。",
    FB_NOTE_PITCH,
], "HPX-Core_LTE_pcb.svg")

# ================================================================== ほかの開発ボード（Olimex・Freenove・ELEGOO）で作るときの図（TARO 2026-11-13）
# TARO 2026-11-13: HPX-Core のすべての版を、Olimex・Freenove・ELEGOO のどれでも作れるように図をそろえる。ピンはファーム v1.25（FireBeetle と同じ）。
# Olimex ESP32-DEVKIT-LIPO と Freenove ESP32 WROOM（FNK0090）は ESP32-DevKitC と同じピンの並び（Freenove は 1列 20ピン）。
# Freenove のピンの並びは Freenove の GitHub のピン配置図から。左右のピン列の間は 10穴と見込んだ（実物で未確認）。
DK_PINS = {
    "olimex": (["3V3", "EN", "IO36", "IO39", "IO34", "IO35", "IO32", "IO33", "IO25", "IO26", "IO27", "IO14", "IO12", "GND", "IO13", "IO9", "IO10", "IO11", "5V"],
               ["GND", "IO23", "IO22", "IO1", "IO3", "IO21", "GND", "IO19", "IO18", "IO5", "IO17", "IO16", "IO4", "IO0", "IO2", "IO15", "IO8", "IO7", "IO6"]),
    "freenove": (["3V3", "EN", "IO36", "IO39", "IO34", "IO35", "IO32", "IO33", "IO25", "IO26", "IO27", "IO14", "IO12", "GND", "IO13", "3V3", "3V3", "3V3", "5V", "5V"],
                 ["GND", "IO23", "IO22", "IO1", "IO3", "IO21", "GND", "IO19", "IO18", "IO5", "IO17", "IO16", "IO4", "IO0", "IO2", "IO15", "GND", "GND", "GND", "GND"]),
    "elegoo": (["EN", "VP", "VN", "D34", "D35", "D32", "D33", "D25", "D26", "D27", "D14", "D12", "D13", "GND", "VIN"],
               ["D23", "D22", "TX0", "RX0", "D21", "D19", "D18", "D5", "TX2", "RX2", "D4", "D2", "D15", "GND", "3V3"]),
}
DK_NAME = {"olimex": "Olimex ESP32-DEVKIT-LIPO", "freenove": "Freenove ESP32 WROOM（FNK0090）", "elegoo": "ELEGOO ESP-32"}
DK_TAG = {"olimex": "【Olimex で作るとき】", "freenove": "【Freenove で作るとき】", "elegoo": "【ELEGOO で作るとき】"}
DK_NOTE = {
    "olimex": "・Olimex のピンの並びは公式資料（GitHub OLIMEX/ESP32-DevKit-LiPo Rev.D）から。左右のピン列は 10穴（25.4mm）離れている。差す前に実物で確かめる。",
    "freenove": "・Freenove のピンの並びは Freenove の GitHub のピン配置図から。左右のピン列は 10穴離れていると見込んで描いた（実物で未確認）。差す前に、基板の印字とピンの名前を見くらべる。",
    "elegoo": "・ELEGOO の左右のピン列は 10穴離れているとして描いた（写真から）。差す前に実物で確かめる。",
}
DK_PWR = {"olimex": "家庭の電源でも電池でも使える（眠っている間の電流が少ない）", "freenove": "省電力に向かないので家庭電源向き", "elegoo": "省電力に向かないので家庭電源向き"}

def dk(kind, c0, r0, used, sock, rot=False):
    """DevKitC 型の開発ボードを描く。c0 は左の列（数字）、r0 は 1本目のピンの行（1から）。ピンの列は c0 と c0+10。
    rot=False: アンテナが上・USB が下。used は金色にする穴の名前"""
    L, Rr = DK_PINS[kind]
    if rot:   # 180度回す（USB が上・アンテナが下）。左右の列も入れかわる
        L, Rr = Rr[::-1], L[::-1]
    n = len(L)
    ext = {"olimex": (0.5, 0.5), "freenove": (2.2, 3.2), "elegoo": (2.3, 4.6)}[kind]
    if rot:
        ext = ext[::-1]
    top, bot = r0 - 1 - ext[0], r0 - 1 + n - 1 + ext[1]
    fill, stroke = {"olimex": ("#c62828", "#7f0000"), "freenove": ("#0d47a1", "#062a63"), "elegoo": ("#212121", "#000")}[kind]
    x1, y1 = OX + (c0 - 0.5) * P, OY + top * P
    x2, y2 = OX + (c0 + 10.5) * P, OY + bot * P
    cx = (x1 + x2) / 2
    s.top.append(f'<rect x="{x1}" y="{y1}" width="{x2-x1}" height="{y2-y1}" rx="10" fill="{fill}" fill-opacity="0.62" stroke="{stroke}" stroke-width="3"/>')
    rec_part(x1, y1, x2 - x1, y2 - y1, kind)
    my = y2 - 180 if rot else y1 + 30
    ay = my + 106 if rot else my
    s.top.append(f'<rect x="{cx-70}" y="{my}" width="140" height="150" rx="4" fill="#b0bec5" fill-opacity="0.85" stroke="#78909c"/>')
    s.top.append(f'<rect x="{cx-70}" y="{ay}" width="140" height="44" rx="4" fill="#212121" fill-opacity="0.8"/>')
    s.text(cx, ay + 28, "アンテナ（下）" if rot else "アンテナ（上）", 12, "middle", "bold", "#eee", layer=s.top)
    s.text(cx, my + (52 if rot else 82), "ESP32-", 15, "middle", "bold", "#263238", layer=s.top)
    s.text(cx, my + (72 if rot else 102), "WROOM-32", 15, "middle", "bold", "#263238", layer=s.top)
    nm = {"olimex": ("Olimex", "ESP32-DEVKIT-LIPO"), "freenove": ("Freenove", "ESP32 WROOM"), "elegoo": ("ELEGOO", "ESP-32")}[kind]
    ty = y1 + 130 if rot else y1 + 230
    s.text(cx, ty, nm[0], 16, "middle", "bold", "#fff", layer=s.top)
    s.text(cx, ty + 22, nm[1], 14, "middle", "bold", "#fff", layer=s.top)
    s.text(cx, ty + 44, sock, 12, "middle", "bold", "#eee", layer=s.top)
    usb = ("micro USB" if kind == "olimex" else "USB-C") + ("（上）" if rot else "（下）")
    uy = y1 - 20 if rot else y2 - 24
    s.top.append(f'<rect x="{cx-34}" y="{uy}" width="68" height="44" rx="8" fill="#bdbdbd" stroke="#616161" stroke-width="2"/>')
    s.text(cx, (y1 + 46) if rot else (y2 + 42), usb, 13, "middle", "bold", "#333", layer=s.top)
    if kind == "olimex" and not rot:
        s.top.append(f'<rect x="{x2-80}" y="{y2-64}" width="52" height="40" rx="4" fill="#fafafa" stroke="#9e9e9e" stroke-width="2"/>')
        s.text(x2 - 54, y2 - 38, "BAT", 12, "middle", "bold", "#333", layer=s.top)
    for c, names, side in ((c0, L, "L"), (c0 + 10, Rr, "R")):
        for i, nme in enumerate(names):
            r = r0 - 1 + i
            x, y = H(c, r)
            if 0 <= r < NR:
                rec_pin(x, y, nme)
            on = f"{COLS[c]}{r+1}" in used
            s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="{"#d4af37" if on else "#9e9e9e"}" stroke="#5d4520" stroke-width="2"/>')
            s.text(x + (14 if side == "L" else -14), y + 5, nme, 12, "start" if side == "L" else "end", "bold", "#fff" if on else "#ddd", layer=s.top)
    return x1, y1, x2, y2

def dk_pin(kind, c0, r0, hname, rot=False):
    """穴の名前 → そこに来るピンの名前"""
    c, r = hole(hname)
    L, Rr = DK_PINS[kind]
    if rot:
        L, Rr = Rr[::-1], L[::-1]
    names = L if c == c0 else Rr
    return names[r - (r0 - 1)]

def w5500_space(r):
    _x1, _y1 = OX + (13 - 0.53) * P, OY + (r - 1 - 0.5) * P
    s.top.append(f'<rect x="{_x1}" y="{_y1}" width="{9.06*P}" height="{9.84*P}" rx="10" fill="none" stroke="#9e9e9e" stroke-width="2.5" stroke-dasharray="10 7"/>')
    s.text(_x1 + 4.53 * P, _y1 + 4.4 * P, "空き（＋有線LAN では", 13, "middle", "bold", "#757575", layer=s.top)
    s.text(_x1 + 4.53 * P, _y1 + 5.2 * P, "ここに W5500 を差す）", 13, "middle", "bold", "#757575", layer=s.top)

W_NAME = {"INT": "INT", "CS": "CS", "SCK": "SCK", "MOSI": "MO", "MISO": "MI", "RST": "RST", "GND": "G", "3V3": "V"}
def dk_lan_rows(kind, r0, wires, rw):
    rows = []
    for c, net, hs in wires:
        a = f"{dk_pin(kind, 1, r0, hs[0])}（{hs[0]}）"
        wc, wr = hole(hs[-1])
        b = f"W5500 {W_NAME[net]}（{hs[-1]}）"
        rows.append([c, a, " → ".join(hs), b])
    return rows

# 線（ルーターで探した。裏で交わらず、ほかのピンの穴の上も通らない）
DK_LAN = {
    "olimex": (9, 24, [
        ("purple", "INT", ["B17", "A17", "A8", "V8", "V24"]),
        ("orange", "CS", ["B18", "J18", "J30", "U30", "U25", "V25"]),
        ("yellow", "SCK", ["B20", "A20", "A32", "X32", "X26", "V26"]),
        ("blue", "MOSI", ["B23", "I23", "I31", "W31", "W27", "V27"]),
        ("green", "MISO", ["L16", "N16", "N24"]),
        ("white", "RST", ["L10", "O10", "O25", "N25"]),
        ("black", "GND", ["L9", "P9", "P29", "N29"]),
        ("red", "3V3", ["B9", "K9", "K28", "N28"]),
    ]),
    "freenove": (12, 22, [
        ("purple", "INT", ["B20", "J20", "J10", "V10", "V22"]),
        ("orange", "CS", ["B21", "K21", "K11", "U11", "U23", "V23"]),
        ("yellow", "SCK", ["B23", "A23", "A9", "W9", "W24", "V24"]),
        ("blue", "MOSI", ["B26", "A26", "A33", "U33", "U25", "V25"]),
        ("green", "MISO", ["L19", "N19", "N22"]),
        ("white", "RST", ["L13", "O13", "O23", "N23"]),
        ("black", "GND", ["L12", "P12", "P27", "N27"]),
        ("red", "3V3", ["B29", "K29", "K32", "M32", "M26", "N26"]),
    ]),
}
DK_SOCK = {"olimex": "ピンソケット 19ピン×2 に差す", "freenove": "ピンソケット 20ピン×2 に差す"}
DK_SOCK_ROW = {"olimex": ["ピンソケット（メス）19ピン（20ピンを切る）", "2"], "freenove": ["ピンソケット（メス）20ピン", "2"]}

for kind in ("olimex", "freenove"):
    r0, rw, wires = DK_LAN[kind]
    n = len(DK_PINS[kind][0])
    pos = f"B{r0}〜B{r0+n-1}・L{r0}〜L{r0+n-1}"
    usb = "micro USB" if kind == "olimex" else "USB-C"
    # ---------------------------------------------------------------- ＋有線LAN
    OX, OY = 260, 200
    s = S(1500, 3000)
    title(s, f"{DK_TAG[kind]}HPX-Core ＋有線LAN 基板の配線図", f"6×8cm 縦置き ／ {DK_NAME[kind]} ＋ W5500 ／ ファーム v1.25")
    board(s, 25, 33, "ユニバーサル基板 6×8cm を縦に置いたところ（穴 A〜Y × 1〜33）")
    dk(kind, 1, r0, {h for _, _, hs in wires for h in (hs[0],)}, DK_SOCK[kind])
    fb_w5500(rw)
    for c, net, hs in wires:
        w(c, *hs)
    fname = f"HPX-Core_LAN_{kind}_pcb.svg"
    yb = back_section(s, OY + (NR - 1) * P + 160, fname)
    y = table(s, 60, yb + 40, [["線", "ESP32 のピン（穴）", "通る穴", "W5500 のピン（穴）"]] + dk_lan_rows(kind, r0, wires, rw), [100, 300, 480, 340], "つなぐ線（穴の位置は 列の文字＋行の数字）")
    y = table(s, 60, y + 50, [
        ["部品", "数", "置き場所・メモ"],
        [DK_NAME[kind] + "（ピンヘッダ付き）", "1", f"{pos}。{usb} を下に向ける"],
        DK_SOCK_ROW[kind] + [f"{pos} にはんだ付けして差す"],
        ["W5500 イーサネットモジュール（ACEIRMC、WIZ820io と同じピン）", "1", f"N{rw}〜N{rw+5}・V{rw}〜V{rw+5}。LAN 差し込み口を下に向ける"],
        ["ピンソケット（メス）6ピン", "2", f"N{rw}〜N{rw+5}、V{rw}〜V{rw+5} にはんだ付けして W5500 を差す"],
        ["ユニバーサル基板 6×8cm（両面スズメッキ、穴 25×33）・すずめっき線", "1", "縦に置く。線はすべて裏"],
    ], [620, 60, 520], "部品表")
    finish(s, y, [
        "・上の図は部品を載せる面（表）から見たところ。ピンソケットは表から差し、足を裏ではんだ付けする。",
        "・線はすべて裏（はんだ面）で、すずめっき線を穴から穴へはんだ付けしてつなぐ。下の「裏から見た図」が実際に配線する向き（左右が逆）。",
        "・裏の線どうしは交わらず、ほかのピンの穴の上も通らない。開発ボード・W5500 の下を通る線は、裏なので部品には触れない。",
        "・ピンは FireBeetle 2 の図と同じ（ファーム HPX_Core v1.25 以降）: INT＝IO25、CS＝IO26、SCK＝IO14、MOSI＝IO13、MISO＝IO19、RST＝IO23。",
        "・灰色のピンは使わない（W5500 の NC、もう1本の V、右の列の G 2本）。",
        f"・電源は {usb} から（USB 充電器）。",
        DK_NOTE[kind],
        "・W5500 のピンがモジュールの上下のどこにあるかは分からない（実物で確かめる）。ずれていたら、W5500 と線の端を同じ数だけ上下にずらせばよい。",
    ] + (["・Freenove の IO16 は基板の上の RGB LED（WS2812）につながっていると資料にある。有線LAN では使わないので問題ない。"] if kind == "freenove" else []), fname)

    # ---------------------------------------------------------------- 基本モデル
    OX, OY = 260, 200
    s = S(1500, 3000)
    title(s, f"{DK_TAG[kind]}HPX-Core 基本モデル 基板の配線図", f"6×8cm 縦置き ／ {DK_NAME[kind]} ／ Wi-Fi ＋ USB 給電")
    board(s, 25, 33, "ユニバーサル基板 6×8cm を縦に置いたところ（「＋有線LAN」と同じ基板・同じ位置。W5500 は付けない）")
    dk(kind, 1, r0, set(), DK_SOCK[kind])
    w5500_space(rw)
    fname = f"HPX-Core_BASIC_{kind}_pcb.svg"
    yb = back_section(s, OY + (NR - 1) * P + 160, fname)
    y = table(s, 60, yb + 40, [
        ["部品", "数", "置き場所・メモ"],
        [DK_NAME[kind] + "（ピンヘッダ付き）", "1", f"{pos}。{usb} を下に向ける"],
        DK_SOCK_ROW[kind] + [f"{pos} にはんだ付けして差す"],
        ["ユニバーサル基板 6×8cm（両面スズメッキ、穴 25×33）", "1", "縦に置く。ケース PFF10-4-10W に AST2.5-5B で留める"],
    ], [620, 60, 520], "部品表")
    finish(s, y, [
        f"・基本モデルは、ルーターへ Wi-Fi でつなぎ、開発ボードの {usb} から電気をもらいます。基板の上の線はありません（ピンソケットのはんだ付けだけ）。",
        f"・「＋有線LAN」と同じ基板・同じ位置です。あとから W5500 とピンソケット 6ピン×2・線8本を足せば「＋有線LAN」にできます（HPX-Core_LAN_{kind}_pcb.png）。",
        "・ファームウェアは HPX_Core v1.25 を使い、初回設定で接続方法を「Wi-Fi」にします。",
        DK_NOTE[kind],
        "・雨のかかる屋外に置くときは、防水の版（＋防水）を使ってください。",
    ], fname)

# ---------------------------------------------------------------- ＋防水＆電池駆動（タカチ PCB36-47）を Freenove・ELEGOO で
# Freenove: 5V（20本目）を B16 に置く → ピン1〜4 は基板の上の外。電池＋ → B16（5V）、− → B10（左の列の GND）。
# ELEGOO: 15ピンが B1〜B15 に収まる。電池＋ → B15（VIN）、− → B14（GND）。
# どちらも USB と 3.3V の部品が電気を食うので、電池は長くもたない（TARO: 省電力に向かないので家庭電源向き）。
for kind, r0, plus, minus, sockn in (("freenove", -3, "B16", "B10", 16), ("elegoo", 1, "B15", "B14", 15)):
    OX, OY = 520, 420
    s = S(1500, 3000)
    title(s, f"{DK_TAG[kind]}HPX-Core ＋防水＆電池駆動の配線図", f"PCB36-47 ／ {DK_NAME[kind]} ／ 単三リチウム ×3 → {'5V' if kind == 'freenove' else 'VIN'} 端子")
    board(s, 13, 17, " ")
    s.text(OX + 6 * P, 120, "ケース用基板 PCB36-47（36×47mm）を縦に置いたところ。穴 13×17 は見込み、四隅は取付穴", 15, "middle", "bold", "#5d4520")
    for c, r in ((0, 0), (12, 0), (0, 16), (12, 16)):
        x, y = H(c, r)
        s.add(f'<circle cx="{x}" cy="{y}" r="15" fill="#fffdf6" stroke="#8d6e3f" stroke-width="2"/>')
        s.add(f'<circle cx="{x}" cy="{y}" r="7" fill="#c8a165" stroke="#8d6e3f" stroke-width="1.5"/>')
    REC["board"] = REC["board"] + s.items[-8:]
    fx1, fy1, fx2, fy2 = dk(kind, 1, r0, {plus, minus}, f"ピンソケット {sockn}ピン×2 に差す")
    ap, am = "A" + plus[1:], "A" + minus[1:]
    tp, tm = H(*hole(ap)), H(*hole(am))
    bx0, by0 = 60, fy2 + 110
    s.rect(bx0, by0, 440, 80, "#455a64", "#263238", 10, 3)
    for i in range(3):
        s.rect(bx0 + 18 + i * 140, by0 + 18, 124, 44, "#c0ca33", "#827717", 20, 2)
        s.text(bx0 + 80 + i * 140, by0 + 46, "単3", 15, "middle", "bold", "#333")
    s.text(bx0 + 426, by0 + 24, "＋", 16, "end", "bold", "#fff")
    s.text(bx0 + 14, by0 + 24, "−", 18, "start", "bold", "#fff")
    s.text(bx0 + 220, by0 + 106, "ケース WH145-25-M3 の電池ボックス ＋ 単三リチウム電池 ×3（直列 約4.5〜5.4V）", 14, "middle", "bold")
    s.wire([(bx0 + 440, by0 + 40), (bx0 + 470, by0 + 40), (bx0 + 470, fy2 + 50), (OX - 120, fy2 + 50), (OX - 120, tp[1]), tp], "red")
    s.wire([(bx0, by0 + 40), (bx0 - 30, by0 + 40), (bx0 - 30, tm[1]), tm], "black")
    solder_mark(s, *hole(ap)); solder_mark(s, *hole(am))
    w("red", ap, plus)
    w("black", am, minus)
    fname = f"HPX-Core_BAT_{kind}_pcb.svg"
    check_back(fname)
    yb = backview(s, by0 + 140, OX + (NC - 1) * P / 2, fy1 - 90, fy2 + 60)
    pn, mn = dk_pin(kind, 1, r0, plus), dk_pin(kind, 1, r0, minus)
    y = table(s, 60, yb + 40, [
        ["線", "どこから（穴）", "どこへ（穴）", "役目"],
        ["red", f"電池ボックスの＋の線（{ap} に直接はんだ付け）", f"裏で {ap} → {plus}（{pn}）", f"電池の＋を {pn} へ"],
        ["black", f"電池ボックスの−の線（{am} に直接はんだ付け）", f"裏で {am} → {minus}（GND）", "電池の−を GND へ"],
    ], [100, 400, 460, 240], "つなぐ線（穴の位置は 列の文字＋行の数字）")
    y = table(s, 60, y + 50, [
        ["部品", "数", "置き場所・メモ"],
        [DK_NAME[kind] + "（ピンヘッダ付き）", "1", f"B1〜B{sockn}・L1〜L{sockn} のソケットに差す。USB-C を下、アンテナを上に向ける"],
        [f"ピンソケット（メス）{sockn}ピン", "2", f"B1〜B{sockn}、L1〜L{sockn} にはんだ付け"],
        ["ケース WH145-25-M3-BR（単3×3 電池ボックス付き）／ 単三リチウム電池", "1／3", f"＋の線を {ap}、−の線を {am} にはんだ付け"],
        ["ケース用基板 PCB36-47・基板スペーサー AST2.5-5B・すずめっき線", "1", "線は裏の2本だけ"],
    ], [600, 60, 540], "部品表")
    extra = {
        "freenove": [
            "・Freenove は1列 20ピンで基板（47mm）より長い。ピン1〜4（3V3・EN・IO36・IO39 と GND・IO23・IO22・IO1）は基板の上の端からはみ出す。USB-C の側も下へはみ出す。",
            "・電池は「5V」端子（左の列のいちばん下）へ。5V の先の 3.3V を作る部品（AMS1117 の見込み）は、入力が 4.3V くらいより下がると 3.3V を保てない。電池が減ってくると止まりやすい。",
        ],
        "elegoo": [
            "・ELEGOO は 15ピンなので B1〜B15・L1〜L15 に収まる。長さ（約51mm）は基板（47mm）より長く、USB-C の側が下へはみ出す。",
            "・電池は「VIN」（左の列のいちばん下）へ。VIN の先の 3.3V を作る部品（AMS1117）は、入力が 4.3V くらいより下がると 3.3V を保てない。電池が減ってくると止まりやすい。",
        ],
    }[kind]
    finish(s, y, [
        "・上の図は部品を載せる面（表）から見たところ。ピンソケットは表から差し、足を裏ではんだ付けする。下の「裏から見た図」が実際に配線する向き（左右が逆）。",
        f"・この開発ボードは {DK_PWR[kind]}。USB の部品・電源の LED・3.3V を作る部品が眠っている間も電気を使うので、電池は FireBeetle や Olimex よりずっと早くなくなる（未実測）。電池で使うなら FireBeetle か Olimex をすすめる。",
    ] + extra + [
        "・電池を入れたまま USB-C をつながない（書き込みは電池を外してから）。",
        "・PCB36-47 の穴の数（13×17）は見込み。ケースの深さに、スペーサー＋基板＋ソケット＋開発ボードが収まるかは未確認。",
        "・管理画面の動作モードは「ディープスリープ」にする。",
        DK_NOTE[kind],
    ], fname)

# ---------------------------------------------------------------- ＋LTE-M（タカチ PCB72-95）を Freenove・ELEGOO で
# 部品の位置・赤丸の穴は「Olimex で作るとき」の図と同じ。開発ボードは左端（A 列と K 列）。
DK_LTE = {
    "freenove": (5, "A24", [
        ("black", "GND（右の列のいちばん上 K5）", ["K5", "K4", "Z4", "Z30"], "右の端 → USB-C GND（Y30）"),
        ("black", "USB-C GND（Y30）", ["Y30", "Z30"], "右の端の GND"),
        ("black", "NPN の E（S17）", ["S17", "S14", "Y14"], "CatM GND の穴（Y14）"),
        ("black", "CatM GND（Y14）", ["Y14", "Z14"], "右の端の GND"),
        ("black", "100kΩ（R14）", ["R14", "S14"], "GND"),
        ("red", "USB-C VBUS（X30）", ["X30", "X18"], "P-MOSFET の S（X18）"),
        ("red", "USB-C VBUS（X30）", ["X30", "X31", "A31", "A24"], "5V（左の列のいちばん下 A24）"),
        ("red", "100kΩ（W23）", ["W23", "X23"], "5V"),
        ("orange", "P-MOSFET の D（V18）", ["V18", "V16"], "CatM 5V の線（V16）"),
        ("purple", "IO4（K17）", ["K17", "L17"], "10kΩ（L17）"),
        ("purple", "10kΩ（P17）", ["P17", "Q17", "Q14"], "NPN の B（Q17）・100kΩ（Q14）"),
        ("gray", "NPN の C（R17）", ["R17", "R19", "W19"], "100kΩ（W19）"),
        ("gray", "P-MOSFET の G（W18）", ["W18", "W19"], "100kΩ（W19）"),
        ("white", "IO39（A8）", ["A8", "C8", "C26", "P26", "P27"], "USB-C D+ の線（P27、電池の電圧）"),
        ("blue", "IO36（A7）", ["A7", "D7", "D25", "Q25", "Q27"], "USB-C D− の線（Q27、パネルの電圧）"),
        ("yellow", "IO16（K16、受信）", ["K16", "M16"], "CatM TX の線（M16）"),
        ("green", "IO17（K15、送信）", ["K15", "M15"], "CatM RX の線（M15）"),
    ]),
    "elegoo": (7, "A21", [
        ("black", "GND（右の列 K20）", ["K20", "O20", "O13", "Y13", "Y14"], "CatM GND の穴（Y14）"),
        ("black", "CatM GND（Y14）", ["Y14", "Y30"], "USB-C GND の線（Y30）"),
        ("black", "（とちゅうの S13）", ["S13", "S17"], "NPN の E（S17）"),
        ("black", "（とちゅうの R13）", ["R13", "R14"], "100kΩ（R14）"),
        ("red", "VIN（A21）", ["A21", "A30", "X30"], "USB-C VBUS の線（X30）"),
        ("red", "USB-C VBUS（X30）", ["X30", "X18"], "P-MOSFET の S（X18）"),
        ("red", "100kΩ（W23）", ["W23", "X23"], "5V"),
        ("orange", "P-MOSFET の D（V18）", ["V18", "V16"], "CatM 5V の線（V16）"),
        ("purple", "D4 = IO4（K17）", ["K17", "L17"], "10kΩ（L17）"),
        ("purple", "10kΩ（P17）", ["P17", "Q17", "Q14"], "NPN の B（Q17）・100kΩ（Q14）"),
        ("gray", "NPN の C（R17）", ["R17", "R19", "W19", "W18"], "100kΩ（W19）・P-MOSFET の G（W18）"),
        ("white", "VN = IO39（A9）", ["A9", "I9", "I27", "P27"], "USB-C D+ の線（P27、電池の電圧）"),
        ("blue", "VP = IO36（A8）", ["A8", "J8", "J26", "Q26", "Q27"], "USB-C D− の線（Q27、パネルの電圧）"),
        ("yellow", "RX2 = IO16（K16、受信）", ["K16", "M16"], "CatM TX の線（M16）"),
        ("green", "TX2 = IO17（K15、送信）", ["K15", "M15"], "CatM RX の線（M15）"),
    ]),
}
for kind in ("freenove", "elegoo"):
    r0, v5, LTE_W = DK_LTE[kind]
    n = len(DK_PINS[kind][0])
    OX, OY = 200, 300
    s = S(1500, 4400)
    title(s, f"{DK_TAG[kind]}HPX-Core ＋LTE-M 基板の配線図", f"PCB72-95 縦置き ／ {DK_NAME[kind]} ／ ファーム v1.25")
    board(s, 27, 36, "タカチ PCB72-95 を縦に置いたところ（穴 A〜AA × 1〜36。上下 3行は D〜X だけ）")
    _i0 = len(s.items)
    for r in (0, 1, 2, 33, 34, 35):
        for c in (0, 1, 2, 24, 25, 26):
            x, y = H(c, r)
            s.add(f'<circle cx="{x}" cy="{y}" r="9" fill="#e8d3a8"/>')
    for c, r in ((0.9, 0.9), (25.1, 0.9), (0.9, 34.1), (25.1, 34.1)):
        x, y = OX + c * P, OY + r * P
        s.add(f'<circle cx="{x}" cy="{y}" r="26" fill="#fffdf6" stroke="#8d6e3f" stroke-width="2"/>')
    REC["board"] = REC["board"] + s.items[_i0:]
    dk(kind, 0, r0, {hs[0] for _, a, hs, _ in LTE_W if hs[0][0] in "AK" and hs[0][1:].isdigit()}, f"ピンソケット {n}ピン×2 に差す")
    B100 = ["#795548", "#212121", "#fdd835", "#c9a227"]
    B10 = ["#795548", "#212121", "#fb8c00", "#c9a227"]
    OFF = (-2000, -2000)
    res_flat("L17", "P17", "10kΩ", B10, OFF)
    tr3(["Q17", "R17", "S17"], ["B", "C", "E"], "NPN", "#455a64")
    res_stand(s, hole("Q14"), hole("R14"), "100kΩ", B100, -2000, -2000)
    tr3(["V18", "W18", "X18"], ["D", "G", "S"], "P-MOSFET", "#37474f")
    res_flat("W19", "W23", "100kΩ", B100, OFF)
    lx = OX + 26 * P + 70
    s.text(lx, OY + 15 * P, "基板の上の部品", 14, weight="bold")
    for i, (a_, b_) in enumerate([("L17〜P17", "10kΩ（IO4 → ベース）"), ("Q14・R14", "100kΩ（ベース → GND、立てる）"), ("Q17・R17・S17", "NPN トランジスタ（B・C・E）"),
                                 ("V18・W18・X18", "P-MOSFET（D・G・S）"), ("W19〜W23", "100kΩ（ゲート → 5V）")]):
        s.text(lx, OY + 15 * P + 30 + i * 46, a_, 13, weight="bold", fill="#5d4520")
        s.text(lx, OY + 15 * P + 48 + i * 46, b_, 12, fill="#333")
    mx1, my1 = OX + 15 * P - 14, OY + R(5) * P - 10
    mx2, my2 = OX + 26 * P + 14, OY + R(12) * P + 10
    s.top.append(f'<rect x="{mx1}" y="{my1}" width="{mx2-mx1}" height="{my2-my1}" rx="8" fill="#eceff1" fill-opacity="0.9" stroke="#90a4ae" stroke-width="3"/>')
    rec_part(mx1, my1, mx2 - mx1, my2 - my1, "CatM")
    s.text((mx1 + mx2) / 2, my1 + 90, "M5Stamp CatM", 15, "middle", "bold", "#263238", layer=s.top)
    s.text((mx1 + mx2) / 2, my1 + 112, "SIM7080G・microSIM", 12, "middle", fill="#455a64", layer=s.top)
    s.text((mx1 + mx2) / 2, my1 + 140, "両面テープで貼る", 12, "middle", "bold", "#455a64", layer=s.top)
    s.text((mx1 + mx2) / 2, my1 + 158, "TX・RX は M16・M15、5V は V16、GND は Y14 へ", 11, "middle", fill="#455a64", layer=s.top)
    s.text((mx1 + mx2) / 2, my1 + 176, "アンテナ（IPEX）は上へ", 11, "middle", fill="#455a64", layer=s.top)
    LAND = [("P27", "USB-C D+", "end"), ("Q27", "USB-C D−", "start"), ("X30", "USB-C VBUS（5V）", "end"), ("Y30", "USB-C GND", "start"),
            ("M15", "CatM RX", "start"), ("M16", "CatM TX", "start"), ("V16", "CatM 5V", "end"), ("Y14", "CatM GND", "start")]
    for h, txt, an in LAND:
        solder_mark(s, *hole(h))
        x, y = H(*hole(h))
        tag(x + (16 if an == "start" else -16), y - 14, txt, 12, an, "#c62828")
    for c, a, hs, b in LTE_W:
        w(c, *hs)
    fname = f"HPX-Core_LTE_{kind}_pcb.svg"
    yb = back_section(s, OY + (NR - 1) * P + 160, fname)
    y = table(s, 60, yb + 40, [["線", "どこから（穴）", "通る穴", "どこへ（穴）"]] + [[c, a, " → ".join(hs), b] for c, a, hs, b in LTE_W],
              [100, 340, 480, 500], "つなぐ線（穴の位置は 列の文字＋行の数字）")
    y = table(s, 60, y + 50, [
        ["部品", "数", "置き場所・メモ"],
        [f"{DK_NAME[kind]}（ピンヘッダ付き）＋ ピンソケット {n}ピン×2", "1", f"A{r0}〜A{r0+n-1}・K{r0}〜K{r0+n-1}（左端に寄せる）。アンテナを上、USB-C を下に向ける"],
        ["M5Stamp CatM（SIM7080G）", "1", "P5〜AA12 の上に両面テープで貼る。TX・RX・5V・GND を赤丸の穴へ短い線で"],
        ["USB-C メス基板（データ対応、VBUS・GND・D+・D− が出ているもの）", "1", "ケースの壁に付け、4本の線を X30・Y30・P27・Q27 へ"],
        ["P-MOSFET（ロジックレベル、1A 以上）", "1", "V18・W18・X18（D・G・S）。足の並びは部品の資料で確かめて合わせる"],
        ["NPN トランジスタ（2SC1815 など）", "1", "Q17・R17・S17（B・C・E）。足の並びは部品の資料で確かめて合わせる"],
        ["金属皮膜抵抗 100kΩ", "2", "W19〜W23（ゲート → 5V、縦）・Q14〜R14（ベース → GND、立てる）"],
        ["金属皮膜抵抗 10kΩ", "1", "L17〜P17（IO4 → ベース、横）"],
        ["ユニバーサル基板 タカチ PCB72-95（穴 27×36）・すずめっき線", "1", "縦に置く。線はすべて裏"],
        ["ケース OB10-17-5W ＋ 取り付けベース OBP10-17 ＋ 基板スペーサー AST3-6B", "1", "四隅の取付穴（φ3.2）を AST3-6B に留める"],
        ["電源: HPX-Solar（別の機器）と USB-C ケーブル（データ対応）", "1", "USB-C ケーブル1本でつなぐ（HPX-Solar_pcb.png）。家では USB 充電器でもよい"],
    ], [640, 60, 620], "部品表")
    extra = {
        "freenove": [
            "・Freenove の IO16 は基板の上の RGB LED（WS2812）にもつながっていると資料にある。CatM からの受信で LED が光ることがあるが、受信はできる見込み（未確認）。",
            f"・5V は左の列のいちばん下（{v5}）へ。Freenove は 5V ピンが2本（A23・A24）ある。",
        ],
        "elegoo": [
            "・ELEGOO の 5V は VIN（A21）へ。ピンの印字は D4・RX2（IO16）・TX2（IO17）・VP（IO36）・VN（IO39）。",
        ],
    }[kind]
    finish(s, y, [
        "・上の図は部品を載せる面（表）から見たところ。部品はすべて表から差し、足を裏ではんだ付けする。線はすべて裏のすずめっき線。",
        "・部品の位置と赤丸の穴（USB-C メス基板の 4本、M5Stamp CatM の TX・RX・5V・GND）は「Olimex で作るとき」の図と同じ。",
        "・電源は USB-C から（HPX-Solar か USB 充電器）。開発ボードは 5V ピンから、CatM は P-MOSFET を通した 5V から動かす。",
        "・CatM の 5V は IO4 が High のときだけ入る（NPN が P-MOSFET のゲートを GND へ引く）。",
        "・HPX-Solar のときは、D+ に電池の電圧（1/2）を IO39 で、D− にパネルの電圧（1/6）を IO36 で読む（ファーム v1.25）。動作設定の「電圧の測り方」で「HPX-Solar」を選ぶ。USB 充電器のときは「測らない」。",
        f"・この開発ボードは {DK_PWR[kind]}。HPX-Solar の小さな電池で動かすと、眠っている間も電気を使うので電池が早く減る（未実測）。HPX-Solar と組むなら FireBeetle か Olimex をすすめる。",
    ] + extra + [
        "・USB-C ケーブルは「データ対応」のものを使う。アンテナのケーブル（SMA）と USB-C メス基板は、ケースの穴から防水して付ける。",
        DK_NOTE[kind],
    ], fname)

# ---------------------------------------------------------------- ＋有線LAN＆ソーラーシステムチェック を Olimex・Freenove で
# 上の部品（1〜11 行）は FireBeetle・ELEGOO の図と同じ位置。線が交わらないように、開発ボードは 180度回して（USB が上）差す。
# 回すと右の列に 5V・IO13・IO14・IO26・IO25・IO35・IO39・3V3 が並び、W5500 と上の部品へ近くなる。
def w5500_used(r, left_used):
    x1, y1, x2, y2 = module(13 - 0.53, 21 + 0.53, r - 1 - 0.5, r - 1 - 0.5 + 9.84, "#1565c0", "#0d3c7a", "W5500")
    s.top.append(f'<rect x="{(x1+x2)/2-34}" y="{y1+30}" width="68" height="68" rx="3" fill="#212121" fill-opacity="0.85"/>')
    s.text((x1 + x2) / 2, y1 + 70, "W5500", 13, "middle", "bold", "#eee", layer=s.top)
    s.top.append(f'<rect x="{(x1+x2)/2-80}" y="{y2-150}" width="160" height="170" rx="6" fill="#cfd8dc" fill-opacity="0.75" stroke="#607d8b" stroke-width="2"/>')
    s.text((x1 + x2) / 2, y2 - 60, "LAN 差し込み口", 13, "middle", "bold", "#37474f", layer=s.top)
    s.text((x1 + x2) / 2, y1 + 120, "W5500 モジュール", 14, "middle", "bold", "#fff", layer=s.top)
    s.text((x1 + x2) / 2, y1 + 140, "ピンソケット 6ピン×2 に差す", 12, "middle", "bold", "#eee", layer=s.top)
    pins(13, r, ["MI", "RST", "NC", "V", "V2", "G"], "L", left_used)
    pins(21, r, ["INT", "CS", "SCK", "MO", "G2", "G3"], "R", {"INT", "CS", "SCK", "MO"})

DK_LANV = {
    "olimex": (9, 28, {"MI", "RST", "V", "G"}, [
        ("purple", "IO25（L19）", ["L19", "V19", "V28"], "W5500 INT（V28）"),
        ("orange", "IO26（L18）", ["L18", "W18", "W29", "V29"], "W5500 CS（V29）"),
        ("yellow", "IO14・SCK（L16）", ["L16", "X16", "X30", "V30"], "W5500 SCK（V30）"),
        ("blue", "IO13・MOSI（L13）", ["L13", "Y13", "Y31", "V31"], "W5500 MO（V31）"),
        ("green", "IO19・MISO（B20）", ["B20", "D20", "D28", "N28"], "W5500 MI（N28）"),
        ("white", "IO23（B26）", ["B26", "C26", "C29", "N29"], "W5500 RST（N29）"),
        ("black", "GND（B27）", ["B27", "B33", "N33"], "W5500 G（N33）"),
        ("red", "3V3（L27）", ["L27", "O27", "O31", "N31"], "W5500 V（N31）"),
        ("black", "GND（B21）", ["B21", "A21", "A3", "G3", "H3", "L3"], "0.1µF（G3）・8.2kΩ（H3）・15kΩ（L3）"),
        ("black", "（とちゅうの L3）", ["L3", "L5", "M5", "S5", "S8", "S9"], "0.1µF（M5）・BP5293 GND（S9）"),
        ("black", "（とちゅうの S8）", ["S8", "V8", "V9"], "10µF（V9）"),
        ("black", "（とちゅうの V8）", ["V8", "V3", "X3"], "端子台 マイナス（X3）"),
        ("blue", "端子台 パネル＋（X1）", ["X1", "I1"], "100kΩ（I1）"),
        ("yellow", "IO39（L24）", ["L24", "J24", "J5", "I5", "H5", "G5"], "100kΩ（I5）・8.2kΩ（H5）・0.1µF（G5）"),
        ("green", "IO35（L22）", ["L22", "K22", "K7", "L7", "M7", "N7"], "15kΩ（L7）・0.1µF（M7）・100kΩ（N7）"),
        ("orange", "5V（L9）", ["L9", "R9"], "BP5293 VOUT（R9）"),
        ("red", "端子台 バッテリー＋（X5）", ["X5", "X11", "V11", "T11", "N11"], "10µF（V11）・100kΩ（N11）"),
        ("red", "", ["T11", "T9"], "BP5293 VIN（T9）"),
    ]),
}
for kind, (r0, rw, wused, LV) in DK_LANV.items():
    n = len(DK_PINS[kind][0])
    OX, OY = 260, 200
    s = S(1700, 3600)
    title(s, f"{DK_TAG[kind]}HPX-Core ＋有線LAN＆ソーラーシステムチェック", f"6×8cm 縦置き ／ {DK_NAME[kind]}（180度回して差す）＋ W5500 ＋ 電圧チェック ／ ファーム v1.25")
    board(s, 25, 33, "ユニバーサル基板 6×8cm を縦に置いたところ（穴 A〜Y × 1〜33）")
    dk(kind, 1, r0, {hs[0] for _, _, hs, _ in LV if hs[0][0] in "BL"}, DK_SOCK[kind], rot=True)
    w5500_used(rw, wused)
    B100 = ["#8d5524", "#212121", "#f9a825"]
    res_flat("I1", "I5", "100kΩ", B100, (-2000, -2000))
    res_stand(s, hole("H5"), hole("H3"), "8.2kΩ", ["#9e9e9e", "#e53935", "#e53935"], -2000, -2000)
    cap_flat(s, hole("G3"), hole("G5"), "0.1µF")
    res_flat("L3", "L7", "15kΩ", ["#8d5524", "#4caf50", "#f57c00"], (-2000, -2000))
    cap_flat(s, hole("M5"), hole("M7"), "0.1µF")
    res_flat("N7", "N11", "100kΩ", B100, (-2000, -2000))
    x1, y1 = H(*hole("R9")); x2, _ = H(*hole("T9"))
    s.top.append(f'<rect x="{x1-26}" y="{y1-34}" width="{x2-x1+52}" height="22" rx="4" fill="#263238" fill-opacity="0.9" stroke="#000" stroke-width="2"/>')
    rec_part(x1 - 26, y1 - 34, x2 - x1 + 52, 22, "")
    s.text((x1 + x2) / 2, y1 - 18, "BP5293-50", 12, "middle", "bold", "#fff", layer=s.top)
    for i, nn in enumerate(["VOUT", "GND", "VIN"]):
        x, y = H(COLS.index("R") + i, 8)
        rec_pin(x, y, nn)
        s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="#d4af37" stroke="#5d4520" stroke-width="2"/>')
        tag(x, y + 24, nn, 10, "middle", w=len(nn) * 7 + 8)
    cap_flat(s, hole("V9"), hole("V11"), "10µF", True)
    x1, y1 = H(*hole("X1")); _, y2 = H(*hole("X5"))
    s.top.append(f'<rect x="{x1-14}" y="{y1-30}" width="{P+36}" height="{y2-y1+60}" rx="5" fill="#2e7d32" fill-opacity="0.9" stroke="#1b5e20" stroke-width="2"/>')
    rec_part(x1 - 14, y1 - 30, P + 36, y2 - y1 + 60, "")
    for h, nn, col in (("X1", "パネル＋", "blue"), ("X3", "マイナス", "black"), ("X5", "バッテリー＋", "red")):
        x, y = H(*hole(h))
        rec_pin(x, y, nn)
        s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="#d4af37" stroke="#5d4520" stroke-width="2"/>')
        s.top.append(f'<circle cx="{x+P}" cy="{y}" r="13" fill="#bdbdbd" stroke="#424242" stroke-width="2"/>')
        s.add(f'<path d="M{x+P+16},{y} L{x+P+100},{y}" fill="none" stroke="{COL[col]}" stroke-width="7"/>')
        s.text(x + P + 108, y + 5, nn, 13, "start", "bold", COL[col] if col != "black" else "#212121")
    s.text(H(*hole("X5"))[0] + P + 108, H(*hole("X5"))[1] + 26, "（＋は 1A ヒューズ経由）", 11, "start", fill="#555")
    lx, ly = OX + 24 * P + 110, OY + 9 * P
    s.text(lx, ly, "上の部品（穴）", 14, weight="bold")
    for i, (a_, b_) in enumerate([("I1〜I5", "100kΩ（パネル＋ → 測る点）"), ("H3・H5", "8.2kΩ（立てる。H5 が本体）"), ("G3・G5", "0.1µF（パネル）"),
                                 ("L3〜L7", "15kΩ（GND → 測る点）"), ("M5・M7", "0.1µF（バッテリー）"), ("N7〜N11", "100kΩ（測る点 → バッテリー＋）"),
                                 ("R9・S9・T9", "BP5293-50（VOUT・GND・VIN）"), ("V9・V11", "10µF（BP5293 の入力側）"), ("X1・X3・X5", "端子台 3極")]):
        s.text(lx, ly + 30 + i * 44, a_, 13, weight="bold", fill="#5d4520")
        s.text(lx, ly + 47 + i * 44, b_, 12, fill="#333")
    for c, a, hs, b in LV:
        w(c, *hs)
    fname = f"HPX-Core_LANV_{kind}_pcb.svg"
    yb = back_section(s, OY + (NR - 1) * P + 160, fname)
    y = table(s, 60, yb + 40, [["線", "どこから（穴）", "通る穴", "どこへ（穴）"]] + [[c, a, " → ".join(hs), b] for c, a, hs, b in LV],
              [100, 300, 520, 500], "つなぐ線（穴の位置は 列の文字＋行の数字）")
    y = table(s, 60, y + 50, [
        ["部品", "数", "置き場所・メモ"],
        [f"{DK_NAME[kind]}（ピンヘッダ付き）＋ " + DK_SOCK_ROW[kind][0], "1／2", f"B{r0}〜B{r0+n-1}・L{r0}〜L{r0+n-1}。180度回して、USB を上・アンテナを下に向ける"],
        ["W5500 イーサネットモジュール ＋ ピンソケット 6ピン×2", "1", f"N{rw}〜N{rw+5}・V{rw}〜V{rw+5}。LAN 差し込み口を下に向ける"],
        ["三端子 DCDC レギュレーター ROHM BP5293-50（入力 7〜26V、5V 1A）", "1", "R9・S9・T9（左から VOUT・GND・VIN。印字面を奥（上）に向けて立てる）"],
        ["積層セラミックコンデンサー 10µF 50V 5mm", "1", "V9・V11（BP5293 の入力側）"],
        ["金属皮膜抵抗 100kΩ 1%", "2", "I1〜I5（パネル用）、N7〜N11（バッテリー用）。寝かせて付ける"],
        ["金属皮膜抵抗 15kΩ 1%", "1", "L3〜L7（バッテリー用、寝かせる）"],
        ["金属皮膜抵抗 8.2kΩ 1%", "1", "H3・H5（パネル用、立てて付ける）"],
        ["積層セラミックコンデンサー 0.1µF 50V 5mm", "2", "G3・G5（パネル用）、M5・M7（バッテリー用）"],
        ["端子台 3極 5.08mm（基板用、ねじ式）", "1", "X1・X3・X5（上から パネル＋・マイナス・バッテリー＋）。線を入れる口を右に向ける"],
        ["ミニ平型ヒューズホルダー ＋ 1A ミニ平型ヒューズ", "2", "バッテリー＋とパネル＋の線の途中（コントローラーの近く）"],
        ["ユニバーサル基板 6×8cm（穴 25×33）・すずめっき線", "1", "縦に置く。線はすべて裏"],
    ], [640, 60, 560], "部品表")
    finish(s, y, [
        "・上の図は部品を載せる面（表）から見たところ。部品はすべて表から差し、足を裏ではんだ付けする。線はすべて裏のすずめっき線。",
        "・上の部品（1〜11 行）の位置は FireBeetle・ELEGOO の図と同じ。開発ボードは 180度回して（USB を上、アンテナを下に）差す。こうすると線が交わらずに引ける。",
        "・開発ボードの電源は USB ではなく、バッテリーから BP5293-50 で作った 5V を 5V ピン（右の列のいちばん上 L9）に入れる。",
        "・バッテリーは 100kΩ と 15kΩ で 約1/7.7 にして IO35 で、パネルは 100kΩ と 8.2kΩ で 約1/13 にして IO39 で読む（ファーム v1.25）。",
        "・W5500 の信号は FireBeetle の図と同じピン: INT＝IO25、CS＝IO26、SCK＝IO14、MOSI＝IO13、MISO＝IO19、RST＝IO23。",
        f"・W5500（{rw}〜{rw+5} 行）の LAN 差し込み口は、基板の下の端から 1cm ほどはみ出す。ケースに収まるかは実物で確かめる。",
        "・USB の口が上の部品の近くに来るので、書き込みは開発ボードをソケットから抜いて行う。差したまま書き込むときは、端子台の線を外すかヒューズを抜く。",
        "・BP5293-50 の入力は 26V まで。足の並びは秋月の資料（データシート）で確かめてから差す。",
        "・マイナスはコントローラーの「バッテリー−」へ。「パネル−」と「バッテリー−」が共通のコントローラーでだけ、パネル電圧を正しく測れる。",
        DK_NOTE[kind],
    ], fname)

COLS = "ABCDEFGHIJKLMNOPQRSTUVWXY"

# ---------------------------------------------------------------- ＋有線LAN＆ソーラーシステムチェック を Freenove で（6×8cm 基板、TARO 2026-11-22）
# TARO 2026-11-22: Freenove と 5×7 ユニバーサル基板（秋月 両面スルーホール 112977、穴 A〜X × 1〜18）で作りたい。
# 6×8cm では線が交わらずに引けなかった（2026-11-13）。5×7cm を横に置き、Freenove も横に寝かせて（アンテナ左・USB-C 右）
# 上半分（1〜11 行）に差す。W5500 は左下（13〜18 行）。電圧を測る抵抗と 0.1µF は Freenove の下（2〜10 行）に寝かせて付ける。
# Freenove の右端の 1ピン（5V と GND）はソケットに入れない（20ピンのソケットを 1ピン切って 19ピン）。空いた W11 の穴に線を通す。
COLS = "ABCDEFGHIJKLMNOPQRSTUVWXY"
FN5_NETS = []      # (net, 穴の並び) … 色が同じでも別の線どうしの交わりを調べる
def wn(net, color, *holes):
    w(color, *holes)
    FN5_NETS.append((net, [hole(h) for h in holes]))

def check_nets(name):
    """線どうしが交わらないか、ほかの線・足の穴の上を通らないかを、線の名前（つながり）で調べる"""
    probs = []
    segs = [(net, a, b) for net, hs in FN5_NETS for a, b in zip(hs, hs[1:])]
    def cells(a, b):
        n = max(abs(b[0] - a[0]), abs(b[1] - a[1]))
        return [(a[0] + (b[0] - a[0]) * i // n, a[1] + (b[1] - a[1]) * i // n) for i in range(n + 1)]
    occ = {}
    for net, a, b in segs:
        for c in cells(a, b):
            occ.setdefault(c, set()).add(net)
    for c, nets in occ.items():
        if len(nets) > 1:
            probs.append(f"{COLS[c[0]]}{c[1]+1} に別の線 {sorted(nets)}")
    pinpos = {(round((x - OX) / P), round((y - OY) / P)): lab for x, y, lab, k in REC["pins"]}
    for net, hs in FN5_NETS:
        verts = set(hs)
        for a, b in zip(hs, hs[1:]):
            for c in cells(a, b)[1:-1]:
                if c in pinpos and c not in verts:
                    probs.append(f"{net} の線が {pinpos[c]}（{COLS[c[0]]}{c[1]+1}）の穴の上を通る")
    print(f"[check-net] {name}: {len(probs)} 件")
    for p_ in sorted(set(probs)):
        print("   ", p_)

def dk_h(kind, c0, rt, used, nosock, sock):
    """DevKitC 型の開発ボードを横に寝かせて描く（アンテナが左・USB が右）。
    c0 は 1本目のピンの列（0から）、rt は上のピン列の行（0から）。下のピン列は rt+10。
    下の列に DK_PINS の左の列（3V3・EN・IO36…5V）、上の列に右の列（GND・IO23…）が左から並ぶ。nosock はソケットに入れないピンの穴"""
    Lp, Rp = DK_PINS[kind]
    n = len(Lp)
    ext = {"olimex": (0.5, 0.5), "freenove": (2.2, 0.6), "elegoo": (2.3, 4.6)}[kind]   # TARO 2026-11-22: Freenove は USB-C 側が 5V・GND のピンのすぐ横で切れている
    x1, x2 = OX + (c0 - ext[0]) * P, OX + (c0 + n - 1 + ext[1]) * P
    y1, y2 = OY + (rt - 0.5) * P, OY + (rt + 10.5) * P
    cy = (y1 + y2) / 2
    s.top.append(f'<rect x="{x1}" y="{y1}" width="{x2-x1}" height="{y2-y1}" rx="10" fill="#0d47a1" fill-opacity="0.30" stroke="#062a63" stroke-width="3" stroke-dasharray="14 6"/>')
    rec_part(x1, y1, x2 - x1, y2 - y1, "Freenove")
    mw, mh = 25.5 / 2.54 * P, 18 / 2.54 * P      # ESP32-WROOM（25.5×18mm）は左の端
    mx = x1 + 6
    s.top.append(f'<rect x="{mx}" y="{cy-mh/2}" width="{mw}" height="{mh}" rx="4" fill="#b0bec5" fill-opacity="0.25" stroke="#78909c" stroke-width="2" stroke-dasharray="6 4"/>')
    s.top.append(f'<rect x="{mx}" y="{cy-mh/2}" width="70" height="{mh}" rx="4" fill="#212121" fill-opacity="0.22"/>')
    tag(mx + 35, cy + 5, "アンテナ", 11, "middle", "#263238", w=60)
    tag(mx + 70 + (mw - 70) / 2, cy - 82, "ESP32-WROOM-32", 11, "middle", "#263238", w=120)
    s.top.append(f'<rect x="{x2-30}" y="{cy-34}" width="48" height="68" rx="8" fill="#bdbdbd" stroke="#616161" stroke-width="2"/>')
    s.text(x2 + 26, cy - 8, "USB-C", 13, "start", "bold", "#333", layer=s.top)
    s.text(x2 + 26, cy + 10, "（右）", 13, "start", "bold", "#333", layer=s.top)
    for r, names, below in ((rt + 10, Lp, True), (rt, Rp, False)):
        for i, nme in enumerate(names):
            c = c0 + i
            x, y = H(c, r)
            hn_ = f"{COLS[c]}{r+1}"
            ns = hn_ in nosock
            if not ns:
                rec_pin(x, y, nme)
            on = hn_ in used
            fill = "#d4af37" if on else ("none" if ns else "#9e9e9e")
            s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="{fill}" stroke="{"#c62828" if ns else "#5d4520"}" stroke-width="2"{" stroke-dasharray=\'4 3\'" if ns else ""}/>')
            ty = y + 26 if below else y - 14
            if ns:
                s.text(x, ty, nme, 10, "middle", "bold", "#c62828", layer=s.top)
            else:
                s.top.append(f'<text x="{x}" y="{ty}" font-size="11" text-anchor="middle" font-weight="bold" fill="{"#1a237e" if on else "#555"}" font-family="{FONT}" transform="rotate(-90 {x} {ty-4})">{nme}</text>' if False else "")
                tag(x, ty, nme.replace("IO", ""), 10, "middle", "#1a237e" if on else "#777", w=len(nme.replace("IO", "")) * 7 + 6)
    return x1, y1, x2, y2

# 線（穴の名前）。色は FireBeetle・Olimex の図と同じ決まり
# TARO 2026-11-22: 5×7cm だと W5500 の基板が大きく Freenove と当たる。TARO の 6×8cm 基板は穴 A〜V × 1〜27（22×27）。
# W5500 は 23×25mm、ピン列は 8穴はなれ（間 7穴）、ピンは 25mm の辺の真ん中（両端から 6mm）。Freenove は USB-C 側が 5V・GND のピンのすぐ横で切れている。
# → Freenove を上の端に横に寝かせ（ピン C〜V、USB-C を右の端に）、W5500 は下（C・K 列、15〜20 行）。
#   Freenove の 3V3 の 3本（R11・S11・T11）はソケットに入れず、穴を GND・バッテリー＋・パネル＋の線の通り道にする。
#   外からの 3本の線（パネル＋・バッテリー＋・マイナス）は端子台を使わず、赤丸の穴に直接はんだ付けする（22 列では端子台まで線が交わらずに引けない）。
WR0 = 15   # W5500 のいちばん上のピンの行
FN5_W = [
    # W5500
    ("INT", "purple", "IO25（K11）", ["K11", "K15"], "W5500 INT（K15）"),
    ("CS", "orange", "IO26（L11）", ["L11", "L16", "K16"], "W5500 CS（K16）"),
    ("SCK", "yellow", "IO14・SCK（N11）", ["N11", "N17", "K17"], "W5500 SCK（K17）"),
    ("MOSI", "blue", "IO13・MOSI（Q11）", ["Q11", "Q18", "K18"], "W5500 MO（K18）"),
    ("MISO", "green", "IO19・MISO（J1）", ["J1", "J4", "B4", "B15", "C15"], "W5500 MI（C15）"),
    ("RST", "white", "IO23（D1）", ["D1", "D2", "A2", "A16", "C16"], "W5500 RST（C16）"),
    ("3V3", "red", "3V3（C11）", ["C11", "C12", "D12", "D18", "C18"], "W5500 V（C18）"),
    # 電圧を測る部品（Freenove の下）と GND
    ("GND", "black", "GND（P11）", ["P11", "P10", "P2", "M2", "K2"], "0.1µF の線（M2）・8.2kΩ（K2）"),
    ("GND", "black", "0.1µF（M4）", ["M4", "M2"], "GND の線（M2）"),
    ("GND", "black", "（とちゅうの P10）", ["P10", "K10", "I10"], "0.1µF（K10）・15kΩ（I10）"),
    ("GND", "black", "（とちゅうの P10）", ["P10", "R10", "R21", "C21", "C20"], "R11 の空いた穴を通って下へ → W5500 G（C20）"),
    ("GND", "black", "（とちゅうの R21）", ["R21", "R22", "T22", "T23"], "マイナスの線（T23）"),
    ("GND", "black", "BP5293 GND（T15）", ["T15", "T22"], "GND の線（T22）"),
    ("GND", "black", "10µF（U17）", ["U17", "T17"], "GND の線（T17）"),
    ("VP", "yellow", "IO39（F11）", ["F11", "F6", "K6", "M6", "N6"], "8.2kΩ（K6）・0.1µF（M6）・100kΩ（N6）"),
    ("VB", "green", "IO35（H11）", ["H11", "H8", "I8", "K8", "N8"], "15kΩ（I8）・0.1µF（K8）・100kΩ（N8）"),
    ("PV", "blue", "パネル＋の線（T13）", ["T13", "T6", "R6"], "100kΩ（R6、T11 の空いた穴を通る）"),
    ("BAT", "red", "バッテリー＋の線（S19）", ["S19", "S17", "S15", "S8", "R8"], "BP5293 VIN（S15）・10µF（S17）・100kΩ（R8、S11 の空いた穴を通る）"),
    ("5V", "orange", "5V（U11）", ["U11", "U15"], "BP5293 VOUT（U15）"),
]
FN5_USED = {hs[0] for _, _, _, hs, _ in FN5_W} | {"C11", "P11"}
OX, OY = 200, 300
s = S(1700, 4200)
FN5_NETS.clear()
title(s, f"{DK_TAG['freenove']}HPX-Core ＋有線LAN＆ソーラーシステムチェック", "6×8cm（穴 22×27）／ W5500 ＋ 電圧チェック ／ v1.25")
board(s, 22, 27, "ユニバーサル基板 6×8cm を縦に置いたところ（穴 A〜V × 1〜27）")
B100 = ["#8d5524", "#212121", "#f9a825"]
res_flat("K2", "K6", "8.2kΩ", ["#9e9e9e", "#e53935", "#e53935"], (-2000, -2000))
cap_flat(s, hole("M4"), hole("M6"), "0.1µF")
res_flat("N6", "R6", "100kΩ", B100, (-2000, -2000))
res_flat("N8", "R8", "100kΩ", B100, (-2000, -2000))
res_flat("I8", "I10", "15kΩ", ["#8d5524", "#4caf50", "#f57c00"], (-2000, -2000))
cap_flat(s, hole("K8"), hole("K10"), "0.1µF")
dk_h("freenove", 2, 0, FN5_USED, {"R11", "S11", "T11"}, "")
for h_, t_ in (("K4", "8.2kΩ"), ("M5", "0.1µF"), ("P6", "100kΩ"), ("P8", "100kΩ"), ("I9", "15kΩ"), ("K9", "0.1µF")):
    x_, y_ = H(*hole(h_))
    tag(x_ + 16, y_ + 5, t_, 11, "start", "#5d4520", w=len(t_) * 8 + 8)
# W5500（LAN 差し込み口が下）。基板 23×25mm、ピンは 25mm の辺の真ん中（両端から 6mm）
x1, y1, x2, y2 = module(2 - 0.53, 10 + 0.53, WR0 - 1 - 6 / 2.54, WR0 - 1 + 5 + 6 / 2.54, "#1565c0", "#0d3c7a", "W5500")
s.top.append(f'<rect x="{(x1+x2)/2-34}" y="{y1+70}" width="68" height="68" rx="3" fill="#212121" fill-opacity="0.85"/>')
s.text((x1 + x2) / 2, y1 + 110, "W5500", 13, "middle", "bold", "#eee", layer=s.top)
s.top.append(f'<rect x="{(x1+x2)/2-80}" y="{y2-120}" width="160" height="150" rx="6" fill="#cfd8dc" fill-opacity="0.75" stroke="#607d8b" stroke-width="2"/>')
s.text((x1 + x2) / 2, y2 - 40, "LAN 差し込み口", 13, "middle", "bold", "#37474f", layer=s.top)
s.text((x1 + x2) / 2, y2 + 52, "LAN ケーブル（下へ）", 13, "middle", "bold", "#333", layer=s.top)
s.text((x1 + x2) / 2, y1 + 160, "W5500 モジュール（23×25mm）", 13, "middle", "bold", "#fff", layer=s.top)
s.text((x1 + x2) / 2, y1 + 180, "ピンソケット 6ピン×2 に差す", 12, "middle", "bold", "#eee", layer=s.top)
pins(2, WR0, ["MI", "RST", "NC", "V", "V2", "G"], "L", {"MI", "RST", "V", "G"})
pins(10, WR0, ["INT", "CS", "SCK", "MO", "G2", "G3"], "R", {"INT", "CS", "SCK", "MO"})
# BP5293-50（左から VIN・GND・VOUT。印字面を手前（下）に向ける）
xa, ya = H(*hole("S15")); xb, _ = H(*hole("U15"))
s.top.append(f'<rect x="{xa-26}" y="{ya-34}" width="{xb-xa+52}" height="22" rx="4" fill="#263238" fill-opacity="0.9" stroke="#000" stroke-width="2"/>')
rec_part(xa - 26, ya - 34, xb - xa + 52, 22, "")
s.text((xa + xb) / 2, ya - 18, "BP5293-50", 12, "middle", "bold", "#fff", layer=s.top)
for h_, nn in (("S15", "VIN"), ("T15", "GND"), ("U15", "VOUT")):
    x, y = H(*hole(h_))
    rec_pin(x, y, nn)
    s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="#d4af37" stroke="#5d4520" stroke-width="2"/>')
    tag(x, y + 24, nn, 10, "middle", w=len(nn) * 7 + 8)
cap_flat(s, hole("S17"), hole("U17"), "10µF", True)
x_, y_ = H(*hole("U17")); tag(x_ + 22, y_ + 5, "10µF", 11, "start", w=40)
# 外からの線（ヒューズを通った パネル＋・バッテリー＋ と マイナス）は赤丸の穴に直接はんだ付け
for i, (h_, nn, col) in enumerate((("T13", "パネル＋（1A ヒューズ経由）", "blue"), ("S19", "バッテリー＋（1A ヒューズ経由）", "red"), ("T23", "マイナス", "black"))):
    solder_mark(s, *hole(h_))
    x, y = H(*hole(h_))
    xe = OX + 21 * P + 90
    s.add(f'<path d="M{x},{y} L{x+14},{y} L{x+14},{y+14} L{xe},{y+14}" fill="none" stroke="{COL[col]}" stroke-width="6" stroke-linejoin="round" opacity="0.85"/>')
    s.text(xe + 8, y + 19, nn, 13, "start", "bold", COL[col] if col != "black" else "#212121")
for net, c, a, hs, b in FN5_W:
    wn(net, c, *hs)
lx, ly = OX + 21 * P + 340, OY + 0 * P
s.text(lx, ly, "部品の穴", 14, weight="bold")
for i, (a_, b_) in enumerate([("K2〜K6", "8.2kΩ（パネル用、寝かせる）"), ("M4・M6", "0.1µF（パネル）"), ("N6〜R6", "100kΩ（パネル＋ → 測る点）"),
                             ("N8〜R8", "100kΩ（バッテリー＋ → 測る点）"), ("I8・I10", "15kΩ（バッテリー用）"), ("K8・K10", "0.1µF（バッテリー）"),
                             ("S15・T15・U15", "BP5293-50（VIN・GND・VOUT）"), ("S17・U17", "10µF（BP5293 の入力側）"),
                             ("T13・S19・T23", "外からの線（赤丸）"), ("C15〜C20・K15〜K20", "W5500 のソケット"),
                             ("C1〜V1", "Freenove のソケット 20ピン"), ("C11〜Q11・U11〜V11", "Freenove のソケット 15ピン＋2ピン")]):
    s.text(lx, ly + 30 + i * 44, a_, 13, weight="bold", fill="#5d4520")
    s.text(lx, ly + 47 + i * 44, b_, 12, fill="#333")
for i, t_ in enumerate(["青い点線の四角が Freenove の外形。", "2〜10 行の部品は Freenove の下に", "入る。寝かせて低く付ける", "",
                        "R11・S11・T11（赤い点線の丸）は", "Freenove の 3V3 のピン。ソケットを", "付けないので穴が空き、線を通す"]):
    s.text(lx, ly + 30 + 12 * 44 + 10 + i * 18, t_, 12, weight="bold", fill="#c62828")
fname = "HPX-Core_LANV_freenove_pcb.svg"
check_nets(fname)
yb = back_section(s, OY + (NR - 1) * P + 300, fname)
y = table(s, 60, yb + 40, [["線", "どこから（穴）", "通る穴", "どこへ（穴）"]] + [[c, a, " → ".join(hs), b] for _, c, a, hs, b in FN5_W],
          [100, 300, 520, 660], "つなぐ線（穴の位置は 列の文字＋行の数字）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["Freenove ESP32 WROOM（FNK0090、ピンヘッダ付き）", "1", "C1〜V1・C11〜V11。横に寝かせて、アンテナを左・USB-C を右の端に向ける"],
    ["ピンソケット（メス）20ピン", "2", "上の列 C1〜V1 は 20ピンのまま。下の列は 15ピン（C11〜Q11）と 2ピン（U11・V11）に切る"],
    ["W5500 イーサネットモジュール ＋ ピンソケット 6ピン×2", "1", "C15〜C20・K15〜K20。LAN 差し込み口を下に向ける"],
    ["三端子 DCDC レギュレーター ROHM BP5293-50（入力 7〜26V、5V 1A）", "1", "S15・T15・U15（左から VIN・GND・VOUT。印字面を手前（下）に向けて立てる）"],
    ["積層セラミックコンデンサー 10µF 50V 5mm", "1", "S17・U17（BP5293 の入力側）"],
    ["金属皮膜抵抗 100kΩ 1%", "2", "N6〜R6（パネル用）、N8〜R8（バッテリー用）。寝かせて付ける"],
    ["金属皮膜抵抗 15kΩ 1%（小型 1/4W）", "1", "I8・I10（バッテリー用。足の間 2穴で寝かせる）"],
    ["金属皮膜抵抗 8.2kΩ 1%", "1", "K2〜K6（パネル用、寝かせる）"],
    ["積層セラミックコンデンサー 0.1µF 50V 5mm", "2", "M4・M6（パネル用）、K8・K10（バッテリー用）"],
    ["ミニ平型ヒューズホルダー ＋ 1A ミニ平型ヒューズ", "2", "バッテリー＋とパネル＋の線の途中（コントローラーの近く）"],
    ["ユニバーサル基板 6×8cm（穴 22×27）・すずめっき線", "1", "縦に置く。線はすべて裏"],
], [680, 60, 840], "部品表")
finish(s, y, [
    "・上の図は部品を載せる面（表）から見たところ。部品はすべて表から差し、足を裏ではんだ付けする。線はすべて裏のすずめっき線（下の「裏から見た図」が実際に配線する向き）。",
    "・Freenove は横に寝かせて上の端に差す（アンテナが左、USB-C が右の端）。図の点線の四角が Freenove の外形で、2〜10 行の部品（抵抗 4本・0.1µF 2個）はその下に入る。",
    "・Freenove の下の部品は、ソケットより先にはんだ付けし、基板にぴったり寝かせて低く付ける。ソケット（約 8.5mm）とピンヘッダの台（約 2.5mm）で、すき間は 1cm くらいの見込み（未確認）。",
    "・Freenove の下の列のソケットは、15ピン（C11〜Q11）と 2ピン（U11・V11）に切って付ける。R11・S11・T11（使わない 3V3 のピン）はソケットに入らず浮く。空いた穴に GND・バッテリー＋・パネル＋の線を通すため。",
    "・W5500（23×25mm）は C・K 列の 15〜20 行。基板の上の端は 12.6 行くらいで、Freenove の下の端（11.5 行くらい）とは 3mm ほどはなれる見込み。",
    "・22 列の基板では端子台まで線が交わらずに引けないので、外からの 3本の線（パネル＋・バッテリー＋・マイナス）は赤丸の穴（T13・S19・T23）に直接はんだ付けする。",
    "・開発ボードの電源は USB ではなく、バッテリーから BP5293-50 で作った 5V を 5V ピン（U11）に入れる。",
    "・バッテリーは 100kΩ と 15kΩ で 約1/7.7 にして IO35 で、パネルは 100kΩ と 8.2kΩ で 約1/13 にして IO39 で読む（ファーム v1.25）。",
    "・W5500 の信号は FireBeetle の図と同じピン: INT＝IO25、CS＝IO26、SCK＝IO14、MOSI＝IO13、MISO＝IO19、RST＝IO23。",
    "・BP5293-50 は左から VIN・GND・VOUT（FireBeetle・Olimex の図とは左右が逆。印字面を手前（下）に向ける）。足の並びは秋月の資料（データシート）で確かめてから差す。入力は 26V まで。",
    "・USB-C で書き込むときは、バッテリー＋の線のヒューズを抜く（5V ピンにバッテリーからの 5V が来ているため）。",
    "・マイナスはコントローラーの「バッテリー−」へ。「パネル−」と「バッテリー−」が共通のコントローラーでだけ、パネル電圧を正しく測れる。",
    "・Freenove のピンの並びは Freenove の GitHub のピン配置図から。左右のピン列は 10穴はなれていると見込んで描いた（実物で未確認）。差す前に、基板の印字とピンの名前を見くらべる。",
], fname)

print("ok")
