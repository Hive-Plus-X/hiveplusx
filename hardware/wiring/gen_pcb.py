#!/usr/bin/env python3
# 基板 (ユニバーサル基板 2.54mm ピッチ) に部品を並べた配線図の SVG を作る
import sys, os
OUT = sys.argv[1]
os.makedirs(OUT, exist_ok=True)

FONT = "'Hiragino Kaku Gothic ProN','Hiragino Sans','Meiryo','Yu Gothic','Noto Sans CJK JP','IPAGothic',sans-serif"
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

# ================================================================== HPX-Core 有線LAN（6×8cm 基板）
OX, OY = 260, 200
s = S(1500, 3000)
title(s, "HPX-Core（親機）＋有線LAN 基板の配線図", "ユニバーサル基板 6×8cm（穴 A〜Y × 1〜33）縦置き ／ ELEGOO ESP-32 ＋ W5500 ／ ファーム HPX_Core v1.20")
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
pe_l = pins(1, 14, EL, "L", {"GND"})
pe_r = pins(11, 14, ER, "R", {"D23", "D18", "D5", "TX2", "RX2", "D4", "3V3"})

# W5500（LAN 差し込み口が下）。ピン列は N 列と V 列（8穴離れている）、24〜29 行
x1, y1, x2, y2 = module(13 - 0.53, 21 + 0.53, R(24) - 0.5, R(24) - 0.5 + 9.84, "#1565c0", "#0d3c7a", "W5500")
s.top.append(f'<rect x="{(x1+x2)/2-34}" y="{y1+30}" width="68" height="68" rx="3" fill="#212121" fill-opacity="0.85"/>')
s.text((x1 + x2) / 2, y1 + 70, "W5500", 13, "middle", "bold", "#eee", layer=s.top)
s.top.append(f'<rect x="{(x1+x2)/2-80}" y="{y2-150}" width="160" height="170" rx="6" fill="#cfd8dc" fill-opacity="0.75" stroke="#607d8b" stroke-width="2"/>')
s.text((x1 + x2) / 2, y2 - 60, "LAN 差し込み口", 13, "middle", "bold", "#37474f", layer=s.top)
s.text((x1 + x2) / 2, y2 + 46, "LAN ケーブル（下へ）", 13, "middle", "bold", "#333", layer=s.top)
s.text((x1 + x2) / 2, y1 + 120, "W5500 モジュール", 14, "middle", "bold", "#fff", layer=s.top)
s.text((x1 + x2) / 2, y1 + 140, "ピンソケット 6ピン×2 に差す", 12, "middle", "bold", "#eee", layer=s.top)
WL = ["MI", "RST", "NC", "V", "V2", "G"]       # 上から（下から G, V, V, NC, RST, MI）
WR = ["INT", "CS", "SCK", "MO", "G2", "G3"]     # 上から（下から G, G, MO, SCK, CS, INT）
pw_l = pins(13, 24, WL, "L", {"MI", "RST", "V2", "G"})
pw_r = pins(21, 24, WR, "R", {"INT", "CS", "SCK", "MO"})

def Lc(ch):
    return COLS.index(ch)

def w(color, *holes):
    wireh(s, [(Lc(h[0]), R(int(h[1:]))) for h in holes], color)

w("blue",   "L14", "Y14", "Y27", "V27")          # D23(MOSI) → MO
w("yellow", "L20", "X20", "X26", "V26")          # D18(SCK) → SCK
w("orange", "L21", "W21", "W25", "V25")          # D5(CS) → CS
w("purple", "L22", "V22", "V24")                 # TX2(GPIO17) → INT
w("white",  "L23", "O23", "O25", "N25")          # RX2(GPIO16) → RST
w("green",  "L24", "N24")                        # D4(GPIO4, MISO) → MI
w("red",    "L28", "N28")                        # 3V3 → V
w("black",  "B27", "A27", "A30", "N30", "N29")   # GND → G
yb = back_section(s, OY + (NR - 1) * P + 160, "HPX-Core_LAN_pcb.svg")
y = table(s, 60, yb + 40, [
    ["線", "ESP-32 のピン（穴）", "通る穴", "W5500 のピン（穴）"],
    ["blue", "D23 = GPIO23・MOSI（L14）", "L14 → Y14 → Y27 → V27", "MO（V27）"],
    ["yellow", "D18 = GPIO18・SCK（L20）", "L20 → X20 → X26 → V26", "SCK（V26）"],
    ["orange", "D5 = GPIO5・CS（L21）", "L21 → W21 → W25 → V25", "CS（V25）"],
    ["purple", "TX2 = GPIO17（L22）", "L22 → V22 → V24", "INT（V24）"],
    ["white", "RX2 = GPIO16（L23）", "L23 → O23 → O25 → N25", "RST（N25）"],
    ["green", "D4 = GPIO4・MISO（L24）", "L24 → N24", "MI（N24）"],
    ["red", "3V3（L28）", "L28 → N28", "V（N28）"],
    ["black", "GND（左の列 B27）", "B27 → A27 → A30 → N30 → N29", "G（N29）"],
], [100, 320, 420, 380], "つなぐ線（穴の位置は 列の文字＋行の数字）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["ELEGOO ESP-32 開発ボード（USB-C・CP2102、30ピン）", "1", "B14〜B28・L14〜L28。USB-C を下に向ける"],
    ["ピンソケット（メス）15ピン", "2", "B14〜B28、L14〜L28 にはんだ付けして ESP-32 を差す"],
    ["W5500 イーサネットモジュール（ACEIRMC、WIZ820io と同じピン）", "1", "N24〜N29・V24〜V29。LAN 差し込み口を下に向ける"],
    ["ピンソケット（メス）6ピン", "2", "N24〜N29、V24〜V29 にはんだ付けして W5500 を差す"],
    ["ユニバーサル基板 6×8cm（両面スズメッキ、穴 25×33）・すずめっき線", "1", "縦に置く。線はすべて裏"],
], [620, 60, 520], "部品表")
finish(s, y, [
    "・上の図は部品を載せる面（表）から見たところ。ピンソケットは表から差し、足を裏ではんだ付けする。",
    "・線はすべて裏（はんだ面）で、すずめっき線を穴から穴へはんだ付けしてつなぐ。上の図では点線で透かして描き、下の「裏から見た図」が実際に配線する向き（左右が逆）。",
    "・裏の線どうしは交わらず、ほかのピンの穴の上も通らない。ESP-32・W5500 の下を通る線は、裏なので部品には触れない。",
    "・灰色のピンは使わない（W5500 の NC、もう1本の V、右の列の G 2本）。",
    "・線を交わらせないため、ファームのピンを変えた: MISO＝GPIO4（D4）、INT＝GPIO17（TX2）、RST＝GPIO16（RX2）。HPX_Core v1.20 はこのピンに合わせてある。",
    "・ESP-32 の左右のピン列は10穴、W5500 の左右のピン列は8穴離れているとして描いた（ESP-32 は写真から、W5500 は幅23mm から計算）。差す前に実物で確かめる。",
    "・W5500 のピンがモジュールの上下のどこにあるかは分からないので、ピンを ESP-32 の D4 と同じ 24 行から下に置いた。下の端に合わせたいときは、全部の線を同じ数だけ下へずらせばよい。",
], "HPX-Core_LAN_pcb.svg")

# ================================================================== HPX-Core ＋防水＆電池駆動（タカチ PCB36-47）
# TARO 2026-11-08: PCB36-47 に Olimex ESP32-DEVKIT-LIPO を載せ、単三リチウム3本（ケース WH145-25-M3 の電池ボックス）から 5V 端子へ。
# Olimex のピンの並びは公式資料（GitHub OLIMEX/ESP32-DevKit-LiPo Rev.D、ESP32-DevKitC と同じ）。左右のピン列は 25.4mm（10穴）離れている。
# PCB36-47 は 36×47mm（取付穴 30×41mm）。穴は 13×17（A〜M × 1〜17）と見込んで描いた（実物で未確認）。
OX, OY = 520, 420
s = S(1500, 3000)
title(s, "HPX-Core（親機）＋防水＆電池駆動の配線図", "タカチ PCB36-47（穴 A〜M × 1〜17 の見込み）／ Olimex ESP32-DEVKIT-LIPO ／ 単三リチウム ×3 → 5V 端子")
board(s, 13, 17, " ")
s.text(OX + 6 * P, 120, "ケース用基板 PCB36-47（36×47mm）を縦に置いたところ。穴 13×17 は見込み、四隅は取付穴", 15, "middle", "bold", "#5d4520")
for c, r in ((0, 0), (12, 0), (0, 16), (12, 16)):   # 四隅は取付穴（その位置の穴は使わない）
    x, y = H(c, r)
    s.add(f'<circle cx="{x}" cy="{y}" r="15" fill="#fffdf6" stroke="#8d6e3f" stroke-width="2"/>')
    s.add(f'<circle cx="{x}" cy="{y}" r="7" fill="#c8a165" stroke="#8d6e3f" stroke-width="1.5"/>')
REC["board"] = REC["board"] + s.items[-8:]

# Olimex（USB が下）。左の列 B（EXT1）、右の列 L（EXT2）。ピン19（5V）を 16 行に置く → ピン1〜3 は基板の上の外にはみ出す
OL = ["3V3", "EN", "IO36", "IO39", "IO34", "IO35", "IO32", "IO33", "IO25", "IO26", "IO27", "IO14", "IO12", "GND", "IO13", "IO9", "IO10", "IO11", "5V"]
OR = ["GND", "IO23", "IO22", "IO1", "IO3", "IO21", "GND", "IO19", "IO18", "IO5", "IO17", "IO16", "IO4", "IO0", "IO2", "IO15", "IO8", "IO7", "IO6"]
x1, y1, x2, y2 = module(0.5, 11.5, R(16) - 18.5, R(16) + 0.5, "#c62828", "#7f0000", "Olimex")
s.top.append(f'<rect x="{(x1+x2)/2-80}" y="{y1-34}" width="160" height="60" rx="4" fill="#212121" fill-opacity="0.85"/>')
s.text((x1 + x2) / 2, y1 - 8, "アンテナ（上）", 12, "middle", "bold", "#eee", layer=s.top)
s.top.append(f'<rect x="{(x1+x2)/2-70}" y="{y1+30}" width="140" height="160" rx="4" fill="#b0bec5" fill-opacity="0.85" stroke="#78909c"/>')
s.text((x1 + x2) / 2, y1 + 105, "ESP32-", 15, "middle", "bold", "#263238", layer=s.top)
s.text((x1 + x2) / 2, y1 + 125, "WROOM-32E", 15, "middle", "bold", "#263238", layer=s.top)
s.text((x1 + x2) / 2, y1 + 250, "Olimex", 16, "middle", "bold", "#fff", layer=s.top)
s.text((x1 + x2) / 2, y1 + 272, "ESP32-DEVKIT-LIPO", 14, "middle", "bold", "#fff", layer=s.top)
s.text((x1 + x2) / 2, y1 + 294, "ピンソケット 16ピン×2 に差す", 12, "middle", "bold", "#eee", layer=s.top)
ux = (x1 + x2) / 2
s.top.append(f'<rect x="{ux-30}" y="{y2-26}" width="60" height="40" rx="6" fill="#bdbdbd" stroke="#616161" stroke-width="2"/>')
s.text(ux, y2 + 40, "micro USB（下向き。電池版では使わない）", 13, "middle", "bold", "#333", layer=s.top)
bx = x1 + (151.384 - 129.54) / 2.54 * P
s.top.append(f'<rect x="{bx-26}" y="{y2-44}" width="52" height="40" rx="4" fill="#fafafa" stroke="#9e9e9e" stroke-width="2"/>')
s.text(bx, y2 - 52, "BAT", 12, "middle", "bold", "#fff", layer=s.top)
s.text(bx + 40, y2 + 84, "↑ BAT コネクタ：乾電池はつながない", 13, "middle", "bold", "#c62828", layer=s.top)
# ピン（1〜3 は基板の外、4〜19 がソケットに入る）
def opins(col, side):
    names = OL if side == "L" else OR
    used = {"5V", "GND"} if side == "L" else set()
    out = {}
    for i, n in enumerate(names):
        r = R(16) - 18 + i
        x, y = H(col, r)
        on = side == "L" and (n == "5V" or (n == "GND" and i == 13))
        if r >= 0:
            rec_pin(x, y, n)
        s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="{"#d4af37" if on else "#9e9e9e"}" stroke="#5d4520" stroke-width="2"/>')
        dx = 14 if side == "L" else -14
        s.text(x + dx, y + 5, n, 12, "start" if side == "L" else "end", "bold", "#fff" if on else "#eee", layer=s.top)
        out[(n, i)] = (col, r)
    return out
opins(1, "L"); opins(11, "R")

# 電池ボックス（ケース WH145-25-M3 の単3×3）。＋の線を A16、−の線を A11 に直接はんだ付けし、裏で B16（5V）・B11（GND）へ
tp, tm = H(0, R(16)), H(0, R(11))
bx0, by0 = 60, OY + 17 * P + 110
s.rect(bx0, by0, 440, 80, "#455a64", "#263238", 10, 3)
for i in range(3):
    s.rect(bx0 + 18 + i * 140, by0 + 18, 124, 44, "#c0ca33", "#827717", 20, 2)
    s.text(bx0 + 80 + i * 140, by0 + 46, "単3", 15, "middle", "bold", "#333")
s.text(bx0 + 426, by0 + 24, "＋", 16, "end", "bold", "#fff")
s.text(bx0 + 14, by0 + 24, "−", 18, "start", "bold", "#fff")
s.text(bx0 + 220, by0 + 106, "ケース WH145-25-M3 の電池ボックス ＋ 単三リチウム電池 ×3（直列 約4.5〜5.4V）", 14, "middle", "bold")
s.wire([(bx0 + 440, by0 + 40), (bx0 + 470, by0 + 40), (bx0 + 470, tp[1]), tp], "red")
s.wire([(bx0, by0 + 40), (bx0 - 30, by0 + 40), (bx0 - 30, tm[1]), tm], "black")
solder_mark(s, 0, R(16)); solder_mark(s, 0, R(11))
w("red", "A16", "B16")      # 電池＋ → 5V（ピン19）
w("black", "A11", "B11")    # 電池− → GND（左の列のピン14）
check_back("HPX-Core_BAT_pcb.svg")
yb = backview(s, by0 + 140, OX + (NC - 1) * P / 2, y1 - 90, OY + (NR - 1) * P + 36)
y = table(s, 60, yb + 40, [
    ["線", "どこから（穴）", "どこへ（穴）", "役目"],
    ["red", "電池ボックスの＋の線（A16 に直接はんだ付け）", "裏で A16 → B16（Olimex の 5V、左の列のいちばん下）", "電池の＋を 5V 端子へ"],
    ["black", "電池ボックスの−の線（A11 に直接はんだ付け）", "裏で A11 → B11（Olimex の GND、左の列の下から6本目）", "電池の−を GND へ"],
], [100, 400, 460, 240], "つなぐ線（穴の位置は 列の文字＋行の数字）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["Olimex ESP32-DEVKIT-LIPO（micro USB、ピンヘッダ付き）", "1", "B1〜B16・L1〜L16 のソケットに差す。USB を下、アンテナを上に向ける"],
    ["ピンソケット（メス）16ピン（20ピンを切る）", "2", "B1〜B16、L1〜L16 にはんだ付け。Olimex のピン4〜19 が入る"],
    ["ケース WH145-25-M3-BR（単3×3 電池ボックス付き）／ 単三リチウム電池", "1／3", "＋の線を A16、−の線を A11 にはんだ付け"],
    ["ケース用基板 PCB36-47・基板スペーサー AST2.5-5B・すずめっき線", "1", "線は裏の2本だけ"],
], [600, 60, 540], "部品表")
finish(s, y, [
    "・上の図は部品を載せる面（表）から見たところ。ピンソケットは表から差し、足を裏ではんだ付けする。下の「裏から見た図」が実際に配線する向き（左右が逆）。",
    "・電池は Olimex の「5V」端子（左の列のいちばん下）と「GND」へつなぐ。基板の「BAT」コネクタは充電式リチウムイオン電池用なので、乾電池は絶対につながない。",
    "・電池を入れたまま micro USB をつながない（書き込みは電池を外してから）。USB の 5V と電池がぶつかるおそれがある。",
    "・新品の単三リチウム3本は約5.4V。5V 端子の先は、ダイオード（約0.3V 下がる）を通って 3.3V を作る部品（HX6211、入力の上限 6V と販売店の資料）へ行く。上限の内側だが余裕は少ない。",
    "・Olimex は長さ 48mm で基板（47mm）より長く、ピンは19本ある。ピン1〜3（3V3・EN・IO36 と GND・IO23・IO22）は基板の上の端からはみ出す。",
    "・PCB36-47 の穴の数（13×17）は見込み。四隅の取付穴に近い B1・L1 の穴がなければ、ソケットを15ピンにして B2〜B16・L2〜L16 にする。",
    "・ケースの深さ（内寸13mm の見込み）に、スペーサー5mm＋基板＋ソケット＋Olimex が収まるか未確認。足りないときは、ソケットを使わずに Olimex のピンを基板へ直接はんだ付けする。",
    "・管理画面の動作モードは「ディープスリープ」にする。電池のもちは保存間隔30分で 2〜4か月の見込み（未実測）。",
], "HPX-Core_BAT_pcb.svg")

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
    bw, bh = (78, 24) if horiz else (24, 78)
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
s = S(1500, 3400)
title(s, "HPX-Core（親機）＋有線LAN＆ソーラーシステムチェック 基板の配線図", "6×8cm（穴 A〜Y × 1〜33）縦置き ／ ESP-32 ＋ W5500 ＋ 電圧チェック ／ ファーム HPX_Core v1.22")
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
pe_l = pins(1, 14, EL, "L", {"GND", "D34", "D35", "VIN"})
pe_r = pins(11, 14, ER, "R", {"D23", "D18", "D5", "TX2", "RX2", "D4", "3V3"})

# W5500（LAN 差し込み口が下）。ピン列は N 列と V 列（8穴離れている）、24〜29 行
x1, y1, x2, y2 = module(13 - 0.53, 21 + 0.53, R(24) - 0.5, R(24) - 0.5 + 9.84, "#1565c0", "#0d3c7a", "W5500")
s.top.append(f'<rect x="{(x1+x2)/2-34}" y="{y1+30}" width="68" height="68" rx="3" fill="#212121" fill-opacity="0.85"/>')
s.text((x1 + x2) / 2, y1 + 70, "W5500", 13, "middle", "bold", "#eee", layer=s.top)
s.top.append(f'<rect x="{(x1+x2)/2-80}" y="{y2-150}" width="160" height="170" rx="6" fill="#cfd8dc" fill-opacity="0.75" stroke="#607d8b" stroke-width="2"/>')
s.text((x1 + x2) / 2, y2 - 60, "LAN 差し込み口", 13, "middle", "bold", "#37474f", layer=s.top)
s.text((x1 + x2) / 2, y2 + 46, "LAN ケーブル（下へ）", 13, "middle", "bold", "#333", layer=s.top)
s.text((x1 + x2) / 2, y1 + 120, "W5500 モジュール", 14, "middle", "bold", "#fff", layer=s.top)
s.text((x1 + x2) / 2, y1 + 140, "ピンソケット 6ピン×2 に差す", 12, "middle", "bold", "#eee", layer=s.top)
WL = ["MI", "RST", "NC", "V", "V2", "G"]       # 上から（下から G, V, V, NC, RST, MI）
WR = ["INT", "CS", "SCK", "MO", "G2", "G3"]     # 上から（下から G, G, MO, SCK, CS, INT）
pw_l = pins(13, 24, WL, "L", {"MI", "RST", "V2", "G"})
pw_r = pins(21, 24, WR, "R", {"INT", "CS", "SCK", "MO"})

def Lc(ch):
    return COLS.index(ch)

def w(color, *holes):
    wireh(s, [(Lc(h[0]), R(int(h[1:]))) for h in holes], color)

w("blue",   "L14", "Y14", "Y27", "V27")          # D23(MOSI) → MO
w("yellow", "L20", "X20", "X26", "V26")          # D18(SCK) → SCK
w("orange", "L21", "W21", "W25", "V25")          # D5(CS) → CS
w("purple", "L22", "V22", "V24")                 # TX2(GPIO17) → INT
w("white",  "L23", "O23", "O25", "N25")          # RX2(GPIO16) → RST
w("green",  "L24", "N24")                        # D4(GPIO4, MISO) → MI
w("red",    "L28", "N28")                        # 3V3 → V
w("black",  "B27", "A27", "A30", "N30", "N29")   # GND → G

# ---- 電圧チェックの部品（上の 1〜9 行）----
def hole(h):
    return (Lc(h[0]), R(int(h[1:])))
res_flat("G1", "G5", "8.2kΩ", ["#9e9e9e", "#e53935", "#e53935"], (H(Lc("G"), 0)[0] - 18, H(0, R(3))[1] + 5), "end")
cap_b("H3", "H5", (H(Lc("I"), 0)[0] + 4, H(0, R(3))[1] + 5), "start")
res_flat("F5", "F9", "100kΩ", ["#8d5524", "#212121", "#f9a825"], (H(Lc("F"), 0)[0] - 18, H(0, R(8))[1] + 5), "end")
res_flat("N1", "N5", "15kΩ", ["#8d5524", "#4caf50", "#f57c00"], (H(Lc("N"), 0)[0] - 18, H(0, R(3))[1] + 5), "end")
cap_b("P3", "P5", (H(Lc("P"), 0)[0] - 30, H(0, R(4))[1] + 5), "end")
res_flat("Q5", "U5", "100kΩ", ["#8d5524", "#212121", "#f9a825"], (H(Lc("S"), 0)[0], H(0, R(5))[1] + 30))
# BP5293-50（足 S3・T3・U3 = 左から VOUT・GND・VIN。印字面を奥（上）に向ける）
x1, y1 = H(Lc("S"), R(3)); x2, _ = H(Lc("U"), R(3))
s.top.append(f'<rect x="{x1-26}" y="{y1-34}" width="{x2-x1+52}" height="22" rx="4" fill="#263238" fill-opacity="0.9" stroke="#000" stroke-width="2"/>')
rec_part(x1 - 26, y1 - 34, x2 - x1 + 52, 22, "")
s.text((x1 + x2) / 2, y1 - 18, "BP5293-50", 12, "middle", "bold", "#fff", layer=s.top)
for i, n in enumerate(["VOUT", "GND", "VIN"]):
    x, y = H(Lc("S") + i, R(3))
    rec_pin(x, y, n)
    s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="#d4af37" stroke="#5d4520" stroke-width="2"/>')
    tag(x, y + 24, n, 10, "middle", w=len(n) * 7 + 8)
cap_flat(s, hole("V2"), hole("V4"), "10µF", True)
tag(H(Lc("W"), 0)[0], H(0, R(3))[1] + 4, "10µF", 10, "middle", w=32)
# 端子台 3極（5.08mm）を右の端に縦に。X3 = マイナス、X5 = バッテリー＋、X7 = パネル＋。線を入れる口は右向き
x1, y1 = H(Lc("X"), R(3)); _, y2 = H(Lc("X"), R(7))
s.top.append(f'<rect x="{x1-14}" y="{y1-34}" width="{P+36}" height="{y2-y1+68}" rx="5" fill="#2e7d32" fill-opacity="0.9" stroke="#1b5e20" stroke-width="2"/>')
rec_part(x1 - 14, y1 - 34, P + 36, y2 - y1 + 68, "")
for h, n, col in (("X3", "マイナス", "black"), ("X5", "バッテリー＋", "red"), ("X7", "パネル＋", "blue")):
    x, y = H(*hole(h))
    rec_pin(x, y, n)
    s.top.append(f'<circle cx="{x}" cy="{y}" r="8.5" fill="#d4af37" stroke="#5d4520" stroke-width="2"/>')
    s.top.append(f'<circle cx="{x+P}" cy="{y}" r="13" fill="#bdbdbd" stroke="#424242" stroke-width="2"/>')
    s.add(f'<path d="M{x+P+16},{y} L{x+P+120},{y}" fill="none" stroke="{COL[col]}" stroke-width="7"/>')
    s.text(x + P + 128, y + 5, n, 13, "start", "bold", COL[col] if col != "black" else "#212121")
s.text(H(Lc("X"), 0)[0] + P + 128, H(0, R(7))[1] + 30, "（＋は 1A ヒューズ経由）", 11, "start", fill="#555")

w("black",  "A27", "A1", "G1", "H1", "N1", "P1", "T1", "V1", "X1", "X3")   # マイナス: 左の列 A → 上の端（1行）→ 端子台
w("black",  "H1", "H3")
w("black",  "P1", "P3")
w("black",  "T1", "T3")
w("black",  "V1", "V2")
w("yellow", "B17", "C17", "C5", "F5", "G5", "H5")                   # パネルの測る点 → D34
w("green",  "B18", "C18", "D18", "D6", "N6", "N5", "P5", "Q5")      # バッテリーの測る点 → D35
w("orange", "B28", "C28", "D28", "E28", "E7", "S7", "S3")           # BP5293 VOUT → VIN（5V）
w("red",    "X5", "V5", "U5", "U3")                                 # バッテリー＋ → 100kΩ・BP5293 VIN
w("red",    "V5", "V4")
w("blue",   "X7", "X9", "F9")                                       # パネル＋ → 100kΩ
yb = back_section(s, OY + (NR - 1) * P + 160, "HPX-Core_LANV_pcb.svg")
y = table(s, 60, yb + 40, [
    ["線", "どこから（穴）", "通る穴", "どこへ（穴）"],
    ["blue", "D23 = GPIO23・MOSI（L14）", "L14 → Y14 → Y27 → V27", "W5500 MO（V27）"],
    ["yellow", "D18 = GPIO18・SCK（L20）", "L20 → X20 → X26 → V26", "W5500 SCK（V26）"],
    ["orange", "D5 = GPIO5・CS（L21）", "L21 → W21 → W25 → V25", "W5500 CS（V25）"],
    ["purple", "TX2 = GPIO17（L22）", "L22 → V22 → V24", "W5500 INT（V24）"],
    ["white", "RX2 = GPIO16（L23）", "L23 → O23 → O25 → N25", "W5500 RST（N25）"],
    ["green", "D4 = GPIO4・MISO（L24）", "L24 → N24", "W5500 MI（N24）"],
    ["red", "3V3（L28）", "L28 → N28", "W5500 V（N28）"],
    ["black", "GND（左の列 B27）", "B27 → A27 → A30 → N30 → N29", "W5500 G（N29）"],
    ["black", "（とちゅうの A27）", "A27 → A1 → X1 → X3（H1→H3、P1→P3、T1→T3、V1→V2 も）", "8.2kΩ・15kΩ・0.1µF・BP5293 GND・10µF・端子台 マイナス"],
    ["yellow", "D34（B17）", "B17 → C17 → C5 → H5", "100kΩ（F5）・8.2kΩ（G5）・0.1µF（H5）"],
    ["green", "D35（B18）", "B18 → D18 → D6 → N6 → N5 → Q5", "15kΩ（N5）・0.1µF（P5）・100kΩ（Q5）"],
    ["orange", "VIN（B28）", "B28 → E28 → E7 → S7 → S3", "BP5293 VOUT（S3）"],
    ["red", "端子台 バッテリー＋（X5）", "X5 → U5 → U3（V5→V4 も）", "100kΩ（U5）・BP5293 VIN（U3）・10µF（V4）"],
    ["blue", "端子台 パネル＋（X7）", "X7 → X9 → F9", "100kΩ（F9）"],
], [100, 300, 480, 440], "つなぐ線（穴の位置は 列の文字＋行の数字。上の 8本は「＋有線LAN」と同じ）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["ELEGOO ESP-32 開発ボード（USB-C・CP2102、30ピン）＋ ピンソケット 15ピン×2", "1", "B14〜B28・L14〜L28。USB-C を下に向ける（「＋有線LAN」と同じ）"],
    ["W5500 イーサネットモジュール ＋ ピンソケット 6ピン×2", "1", "N24〜N29・V24〜V29（「＋有線LAN」と同じ）"],
    ["三端子 DCDC レギュレーター ROHM BP5293-50（入力 7〜26V、5V 1A）", "1", "S3・T3・U3（左から VOUT・GND・VIN。印字面を奥（上）に向けて立てる）"],
    ["積層セラミックコンデンサー 10µF 50V 5mm", "1", "V2・V4（BP5293 の入力側）"],
    ["金属皮膜抵抗 100kΩ 1%", "2", "F5〜F9（パネル用、縦）、Q5〜U5（バッテリー用、横）。寝かせて付ける"],
    ["金属皮膜抵抗 15kΩ 1%", "1", "N1〜N5（バッテリー用）"],
    ["金属皮膜抵抗 8.2kΩ 1%", "1", "G1〜G5（パネル用）"],
    ["積層セラミックコンデンサー 0.1µF 50V 5mm", "2", "H3〜H5（パネル用）、P3〜P5（バッテリー用）"],
    ["端子台 3極 5.08mm（基板用、ねじ式）", "1", "X3・X5・X7（上から マイナス・バッテリー＋・パネル＋）。線を入れる口を右に向ける"],
    ["ミニ平型ヒューズホルダー ＋ 1A ミニ平型ヒューズ", "2", "バッテリー＋とパネル＋の線の途中（コントローラーの近く）"],
    ["ユニバーサル基板 6×8cm（穴 25×33）・すずめっき線", "1", "縦に置く。線はすべて裏"],
], [640, 60, 520], "部品表")
finish(s, y, [
    "・上の図は部品を載せる面（表）から見たところ。部品はすべて表から差し、足を裏ではんだ付けする。線はすべて裏のすずめっき線。",
    "・ESP-32・W5500 と、その 8本の線は「＋有線LAN」と同じ。上の空いた所（1〜9行）に電圧チェックの部品を足した。",
    "・ESP-32 の電源は USB ではなく、バッテリーから BP5293-50 で作った 5V を VIN（B28）に入れる（マイナスをバッテリーとそろえるため）。",
    "・USB で書き込むときは、端子台の線を外すかバッテリー＋のヒューズを抜く（VIN の 5V と USB の 5V がぶつからないように）。",
    "・バッテリーは 100kΩ と 15kΩ で 約1/7.7 に（18V で 2.35V）、パネルは 100kΩ と 8.2kΩ で 約1/13 に（30V で 2.27V）下げて読む。",
    "・100kΩ（F5〜F9）は裏の線（緑・橙）を、15kΩ・8.2kΩ・0.1µF は裏の線（青・緑・黄）をまたいで付ける（表と裏なので触れない）。",
    "・ファーム v1.22 の動作設定で「バッテリー・パネルの電圧を測る」にチェックし、テスターで測った値を入れて1回合わせる。",
    "・BP5293-50 の入力は 26V まで。パネル＋にはつながない。足の並びは秋月の資料（データシート）で確かめてから差す。",
    "・マイナスはコントローラーの「バッテリー−」へ。「パネル−」と「バッテリー−」がつながっている（共通マイナス）コントローラーでだけ、パネル電圧を正しく測れる。",
], "HPX-Core_LANV_pcb.svg")

# ================================================================== HPX-Camera（巣門カメラ、試作）TARO 2026-11-09
# XIAO ESP32S3 Sense（カメラ・microSD は上に重ねた Sense 基板）。単三リチウム×3 → ショットキーダイオード → 5V。
# 電池の電圧は ダイオードの前で 100kΩ/100kΩ（1/2）に下げて D0(A0) で読む。ケース タカチ BCAP091207T（内寸 66×96×56.6mm）
OX, OY = 600, 200
s = S(1280, 1900)
title(s, "HPX-Camera（巣門カメラ・試作）基板の配線図", "XIAO ESP32S3 Sense ／ 電池電圧 D0(A0) ／ 電源 単三リチウム×3（ダイオード経由で 5V）")
board(s, 12, 11)
p = xiao_pcb(s, 2, 2, "ESP32S3 Sense")
cx_ = (H(2, 2)[0] + H(8, 2)[0]) / 2
s.text(cx_, H(0, 8)[1] + 2, "カメラは上（ふた）向き", 11, "middle", "bold", "#e8f5e9", layer=s.top)

# ショットキーダイオード 1N5819（寝かせて付ける、E1＝アノード、I1＝カソード（帯の側））
(x1, y1), (x2, y2) = H(*hole("E1")), H(*hole("I1"))
rec_pin(x1, y1, "1N5819 A"); rec_pin(x2, y2, "1N5819 K")
s.top.append(f'<line x1="{x1}" y1="{y1}" x2="{x2}" y2="{y2}" stroke="#888" stroke-width="3"/>')
s.top.append(f'<rect x="{(x1+x2)/2-40}" y="{y1-12}" width="80" height="24" rx="6" fill="#212121" stroke="#000" stroke-width="2"/>')
s.top.append(f'<rect x="{(x1+x2)/2+24}" y="{y1-12}" width="9" height="24" fill="#cfd8dc"/>')
for x, y in ((x1, y1), (x2, y2)):
    s.top.append(f'<circle cx="{x}" cy="{y}" r="4" fill="#666"/>')
rec_part((x1 + x2) / 2 - 40, y1 - 12, 80, 24, "")
tag((x1 + x2) / 2, y1 + 34, "ダイオード 1N5819（帯は右）", 12, "middle", w=190)

res_flat("A2", "A6", "100kΩ", ["#8d5524", "#212121", "#f9a825"], (OX - 40, H(0, R(4))[1] + 5), "end")
res_flat("A6", "A10", "100kΩ", ["#8d5524", "#212121", "#f9a825"], (OX - 40, H(0, R(8))[1] + 5), "end")
cap_flat(s, hole("B8"), hole("B10"), "0.1µF")
tag(H(Lc("B"), 0)[0] + 22, H(0, R(9))[1] + 5, "0.1µF", 12, "start", w=46)
cap_flat(s, hole("J2"), hole("L2"), "100µF", True)
tag(H(Lc("L"), 0)[0] + 26, H(0, R(2))[1] + 5, "100µF", w=52)

w("red",    "A2", "A1", "E1")                      # 電池＋ → ダイオードのアノード（A2 で 100kΩ にも）
w("orange", "I1", "I2", "I3")                      # ダイオードのカソード → XIAO 5V
w("orange", "I2", "J2")                            # → 100µF
w("yellow", "A6", "B6", "B3", "C3")                # 測る点 → XIAO D0(A0)
w("yellow", "B6", "B8")                            # → 0.1µF
w("black",  "A10", "B10", "B11", "L11")            # 100kΩ・0.1µF の下 → 電池−
w("black",  "L11", "L4", "L2")                     # −の列 → 100µF
w("black",  "I4", "L4")                            # XIAO GND

# 電池ボックス（単3×3、スイッチ付き）。＋の線を A2、−の線を L11 に直接はんだ付け
bx, by = OX - 40, OY + 10 * P + 110
s.rect(bx, by, 400, 80, "#455a64", "#263238", 10, 3)
for i in range(3):
    s.rect(bx + 18 + i * 124, by + 18, 116, 44, "#c0ca33", "#827717", 20, 2)
    s.text(bx + 76 + i * 124, by + 46, "単3", 15, "middle", "bold", "#333")
s.text(bx + 14, by + 24, "＋", 16, "start", "bold", "#fff")
s.text(bx + 386, by + 24, "−", 18, "end", "bold", "#fff")
s.text(bx + 200, by + 106, "単3×3 電池ボックス（スイッチ付き）＋ 単三リチウム電池 ×3（直列 約4.5〜5.4V）", 14, "middle", "bold")
ta, tl = H(*hole("A2")), H(*hole("L11"))
s.wire([(bx, by + 40), (bx - 60, by + 40), (bx - 60, ta[1]), ta], "red")
s.wire([(bx + 400, by + 40), (tl[0], by + 40), tl], "black")
solder_mark(s, *hole("A2")); solder_mark(s, *hole("L11"))

yb = back_section(s, by + 140, "HPX-Camera_pcb.svg")
y = table(s, 60, yb + 40, [
    ["線", "どこから（穴）", "どこへ（穴）", "役目"],
    ["red", "電池ボックスの＋（A2 に直接はんだ付け）", "A2 → A1 → E1（ダイオードのアノード）", "電池＋。A2 は 100kΩ の上の足も"],
    ["orange", "ダイオードのカソード（I1）", "I1 → I2 → I3（XIAO 5V）、I2 → J2（100µF）", "ダイオードを通った電池を 5V 端子へ"],
    ["yellow", "100kΩ と 100kΩ の間（A6）", "A6 → B6 → B3 → C3（XIAO D0）、B6 → B8（0.1µF）", "電池の電圧を半分にして読む点"],
    ["black", "下の 100kΩ（A10）", "A10 → B10（0.1µF）→ B11 → L11", "電池の−（L11 に直接はんだ付け）"],
    ["black", "XIAO GND（I4）", "I4 → L4、L11 → L4 → L2（100µF）", "−の列"],
], [100, 320, 420, 320], "つなぐ線（穴の位置は 列の文字＋行の数字）")
y = table(s, 60, y + 50, [
    ["部品", "数", "置き場所・メモ"],
    ["Seeed Studio XIAO ESP32S3 Sense（カメラ OV3660・microSD 枠付き。付属の外付けアンテナを付ける。秋月電子 2,660円）", "1", "C3〜I9 のソケットに差す。USB-C を上に向ける"],
    ["ピンヘッダ 7ピン×2（XIAO 側）＋ ピンソケット 7ピン×2（基板側）", "1組", "C3〜C9・I3〜I9"],
    ["（交換用・任意）OV5640 カメラ（XIAO ESP32S3 Sense 用、自動ピント）", "1", "近い巣門にピントを合わせたいとき。カメラの差し込み口で付け替える"],
    ["microSD カード 32GB 以下（FAT32）", "1", "Sense 基板の枠に差す。写真をためる"],
    ["ショットキーバリアダイオード 1N5819（40V 1A）", "1", "E1（アノード）〜I1（カソード、帯の側）。寝かせて付ける"],
    ["金属皮膜抵抗 100kΩ 1%", "2", "A2〜A6、A6〜A10（縦に寝かせて付ける）"],
    ["積層セラミックコンデンサー 0.1µF 50V C0G 5mm", "1", "B8・B10（測る点のノイズよけ）"],
    ["絶縁ラジアルリード型積層セラミックコンデンサー 100µF 6.3V 5mm", "1", "J2・L2（Wi-Fi で送る瞬間の電圧の下がりよけ）"],
    ["単3×3 電池ボックス（スイッチ付き、リード線）／ 単三リチウム電池", "1／3", "＋の線を A2、−の線を L11 にはんだ付け"],
    ["ユニバーサル基板（穴 12×11 以上）・すずめっき線", "1", "大きい基板を切って使ってもよい"],
    ["ケース タカチ BCAP091207T（透明ふた、内寸 66×96×56.6mm。モノタロウ 1,398円）", "1", "基板は電池ボックスの上に両面テープで重ね、カメラをふたに近づける"],
    ["ポール取付金具 タカチ SSK-S1（モノタロウ 558円）", "1", "ケースを杭やポールに留める"],
    ["屋外用ルーフ タカチ BRF-90G（モノタロウ 539円）", "1", "ケースの上に付ける雨・日よけ（中が熱くなりにくい）"],
    ["乾燥剤（小袋）・両面テープ（厚手）", "少し", "くもり止め・固定"],
], [620, 60, 480], "部品表")
finish(s, y, [
    "・上の図は部品を載せる面（表）から見たところ。部品はすべて表から差し、足を裏ではんだ付けする。線はすべて裏のすずめっき線。",
    "・下の「裏から見た図」が実際に配線する向き（左右が逆）。裏の線どうしは交わらない。",
    "・電池は ダイオード 1N5819 を通して XIAO の「5V」端子へ入れる（USB をつないだとき、USB の 5V が電池へ流れ込まないように）。",
    "・XIAO 裏の「BAT」パッドは充電式リチウムイオン電池用。乾電池は絶対につながない。",
    "・電池の電圧は ダイオードの前で 100kΩ・100kΩ で半分にして D0(A0) で読む（新品 5.4V で 2.7V）。この抵抗には いつも約27µA 流れる（1日 約0.65mAh）。",
    "・D8・D9・D10 は Sense 基板の microSD が使うので、ほかの部品につながない。",
    "・USB で書き込むときは、電池ボックスのスイッチを切る（ダイオードがあるので切り忘れても壊れにくいが、念のため）。",
    "・付属の Wi-Fi アンテナ（シール状）は XIAO の U.FL に差し、ケースの壁（金属のそば以外）に貼る。",
    "・ケースの中で、基板は電池ボックスの上に厚手の両面テープで重ね、カメラ（Sense 基板）をふたのすぐ下に来るようにする（ふたの反射よけ）。",
    "・電池のもち（10分おきに撮影・未実測）: 24時間で 約3週間〜1か月半、昼12時間だけで 約1〜3か月。眠り中の電流しだい。",
    "・まだ組んで確かめていない。XIAO ESP32S3 Sense の眠り中の電流、1N5819 を入れたときの Wi-Fi 送信中の電圧は実物で測る。",
], "HPX-Camera_pcb.svg")

print("ok")
