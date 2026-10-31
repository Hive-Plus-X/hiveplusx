#!/usr/bin/env python3
# 配線図 (ブレッドボード風) の SVG を作る
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

# ------------------------------------------------------------------ HPX-W
s = S(1280, 1750)
title(s, "HPX-W（重量センサ）部品表と配線図", "ファーム HPX_WeightNode v1.01 ／ DT=D0, SCK=D1 ／ 電源 CR123A")
p = xiao(s, 190, 200)
hx, hy = 640, 200
s.rect(hx, hy, 210, 260, "#2e7d32", "#1b5e20", 8, 3)
s.rect(hx + 70, hy + 95, 70, 70, "#212121", "#000", 4)
s.text(hx + 105, hy + 135, "HX711", 14, "middle", "bold", "#eee")
h = {}
for i, n in enumerate(["GND", "DT", "SCK", "VCC"]):
    py = hy + 50 + i * 50; s.pad(hx, py, n, "L"); h[n] = (hx, py)
for i, n in enumerate(["E+", "E-", "A-", "A+", "B-", "B+"]):
    py = hy + 40 + i * 36; s.pad(hx + 210, py, n, "R"); h[n] = (hx + 210, py)
s.text(hx + 105, hy + 290, "HX711 モジュール", 15, "middle", "bold")
s.text(hx + 105, hy + 310, "（B-・B+ は使わない）", 13, "middle", fill="#666")
lx, ly = 1010, 215
s.rect(lx, ly, 220, 150, "#b0bec5", "#78909c", 6, 3)
s.text(lx + 120, ly + 70, "ロードセル", 16, "middle", "bold", "#263238")
s.text(lx + 120, ly + 92, "SC601（120kg）", 13, "middle", fill="#263238")
s.text(lx + 110, ly + 178, "線の色は製品の説明書で確認", 13, "middle", fill="#b23", weight="bold")
for n, c in [("E+", "red"), ("E-", "black"), ("A-", "white"), ("A+", "green")]:
    ex, ey = h[n]; s.wire([(ex, ey), (lx, ey)], c)
s.wire([p["D0"], (150, p["D0"][1]), (150, 150), (590, 150), (590, h["DT"][1]), h["DT"]], "yellow", "D0→DT", (290, 140))
s.wire([p["D1"], (130, p["D1"][1]), (130, 120), (610, 120), (610, h["SCK"][1]), h["SCK"]], "blue", "D1→SCK", (420, 110))
YM, YP = 680, 720
rails(s, 160, 900, YM, YP)
s.wire([p["GND"], (450, p["GND"][1]), (450, YM)], "black")
s.wire([p["3V3"], (420, p["3V3"][1]), (420, YP)], "red")
s.wire([h["GND"], (560, h["GND"][1]), (560, YM)], "black")
s.wire([h["VCC"], (620, h["VCC"][1]), (620, 560), (660, 560), (660, YP)], "red")
cap(s, 300, 610, YM, YP, "100µF", "電源の安定", big=True, right=True)
cap(s, 740, 610, YM, YP, "0.1µF", "HX711 の近く", right=True)
bp, bm = cr123a(s, 300, 800)
s.wire([bm, (230, bm[1]), (230, YM)], "black")
s.wire([bp, (600, bp[1]), (600, YP)], "red")
y = table(s, 60, 990, [
    ["線", "どこから", "どこへ", "役目"],
    ["red", "XIAO 3V3", "＋ レール", "3V の電源"],
    ["black", "XIAO GND", "− レール", "マイナス（共通）"],
    ["red", "HX711 VCC", "＋ レール", "HX711 の電源"],
    ["black", "HX711 GND", "− レール", ""],
    ["yellow", "XIAO D0", "HX711 DT", "重さのデータ（HX711→XIAO）"],
    ["blue", "XIAO D1", "HX711 SCK", "読み取りの合図（XIAO→HX711）"],
    ["red", "電池ケース ＋", "＋ レール", "電池を 3V3 に直接つなぐ"],
    ["black", "電池ケース −", "− レール", ""],
], [110, 170, 170, 330], "つなぐ線")
table(s, 920, 990, [["線（一般的な色）", "HX711"], ["red", "E+"], ["black", "E-"], ["white", "A-"], ["green", "A+"]],
      [170, 130], "ロードセル ⇔ HX711")
y = table(s, 60, y + 50, [
    ["部品", "数", "使うところ・メモ"],
    ["Seeed XIAO BLE nRF52840", "1", "本体（マイコン）"],
    ["ロードセル シングルポイント（ビーム型）SC601 120kg", "1", "重さを測る"],
    ["HX711 モジュール ★リストに無い・必要", "1", "ロードセルの小さな信号を数値にする"],
    ["積層セラミックコンデンサー 0.1µF 50V C0G 5mm", "1", "HX711 の VCC と GND の間（HX711 の近く）"],
    ["絶縁ラジアルリード型積層セラミックコンデンサー 100µF 6.3V 5mm", "1", "＋ と − の間（XIAO の近く）。向きなし"],
    ["CR123A 用電池ケース", "1", "＋ を 3V3、− を GND へ"],
    ["CR123A 電池（3V リチウム）", "1", ""],
    ["その他: 配線用の線・ブレッドボードかユニバーサル基板・ピンヘッダー", "", "手持ちに合わせて"],
], [560, 50, 550], "部品表")
notes(s, 60, y + 20, [
    "・HX711 がリストにありません。ロードセルは XIAO に直接つなげないので、HX711 モジュールが要ります。",
    "・USB で書き込むときは、必ず電池を外す（3V3 に電池を直結しているため）。",
    "・ロードセルの線の色は SC601 で未確認。重さを載せて値が減るときは A+ と A- を入れ替える。",
])
s.save("HPX-W_wiring.svg")

# ------------------------------------------------------------------ HPX-TH
s = S(1280, 2060)
title(s, "HPX-TH（温湿度センサ）部品表と配線図", "SHT40: SDA=D4, SCL=D5 ／ サーミスター: D3→10kΩ→A2 ／ 電源 CR123A")
p = xiao(s, 190, 200)
bx, by = 560, 170
s.rect(bx, by, 340, 380, "#f1efe6", "#cfc8b0", 10, 2)
for gx in range(15):
    for gy in range(15):
        s.add(f'<rect x="{bx+15+gx*22}" y="{by+18+gy*24}" width="6" height="6" fill="#bdb6a0"/>')
s.text(bx + 340, by - 10, "ブレッドボード（はんだ付けでも可）", 14, "end", fill="#666")
rx_, r1, r2 = 660, 230, 350
s.add(f'<line x1="{rx_}" y1="{r1}" x2="{rx_}" y2="{r2}" stroke="#888" stroke-width="3"/>')
s.rect(rx_ - 13, r1 + 20, 26, 80, "#7fb3d5", "#3c6e91", 10, 2)
for i, c in enumerate(["#8d5524", "#212121", "#212121", "#e53935", "#8d5524"]):   # 茶黒黒赤茶 = 10kΩ 1%
    s.rect(rx_ - 13, r1 + 28 + i * 14, 26, 6, c, rx=0)
s.rect(rx_ + 18, r1 + 30, 170, 64, "#fffdf6dd", rx=6)
s.text(rx_ + 26, r1 + 52, "抵抗 10kΩ（1%）", 15, weight="bold")
s.text(rx_ + 26, r1 + 72, "★リストに無い・必要", 13, fill="#b23", weight="bold")
s.text(rx_ + 26, r1 + 88, "向きはどちらでもよい", 12, fill="#666")
NODE = 420
s.wire([p["D3"], (130, p["D3"][1]), (130, 120), (rx_, 120), (rx_, r1)], "purple", "D3→抵抗の片方", (300, 110))
s.wire([p["D2"], (150, p["D2"][1]), (150, 150), (600, 150), (600, NODE), (rx_, NODE)], "yellow", "D2（A2）", (290, 140))
s.wire([(rx_, r2), (rx_, NODE), (880, NODE)], "yellow")
s.dot(rx_, NODE, "yellow")
s.top.append(f'<rect x="{rx_+14}" y="{NODE-30}" width="120" height="20" rx="4" fill="#ffffffdd"/>')
s.text(rx_ + 20, NODE - 14, "ここが測る点", 13, weight="bold", fill="#7a5a00", layer=s.top)
YM, YP = 680, 720
rails(s, 160, 900, YM, YP)
cap(s, 780, 500, NODE, YM, "0.1µF", "ノイズよけ", right=True)
# サーミスター: 平行線 2本
tx, ty = 1110, 470
s.add(f'<path d="M{tx},{ty-6} L960,{ty-6} L960,{NODE} L880,{NODE}" fill="none" stroke="#9e9e9e" stroke-width="4"/>')
s.add(f'<path d="M{tx},{ty+6} L980,{ty+6} L980,{YM} L880,{YM}" fill="none" stroke="#9e9e9e" stroke-width="4"/>')
s.add(f'<ellipse cx="{tx+14}" cy="{ty}" rx="18" ry="13" fill="#5d4037" stroke="#3e2723" stroke-width="2"/>')
s.top.append(f'<circle cx="880" cy="{NODE}" r="5" fill="#666"/>')
s.top.append(f'<circle cx="880" cy="{YM}" r="5" fill="#666"/>')
s.text(1000, ty - 46, "サーミスター 10kΩ", 15, weight="bold")
s.text(1000, ty - 26, "（平行線タイプ・向きなし）", 13, fill="#666")
s.text(1000, ty + 38, "線を伸ばして巣箱の中へ", 13, fill="#666")
s.wire([p["GND"], (450, p["GND"][1]), (450, YM)], "black")
s.wire([p["3V3"], (420, p["3V3"][1]), (420, YP)], "red")
cap(s, 300, 610, YM, YP, "100µF", "電源の安定", big=True, right=True)
bp, bm = cr123a(s, 300, 800)
s.wire([bm, (230, bm[1]), (230, YM)], "black")
s.wire([bp, (600, bp[1]), (600, YP)], "red")
# SHT40 モジュール (温湿度、I2C)。ピンは左側
mx, my = 700, 790
s.rect(mx, my, 200, 150, "#5e35b1", "#311b92", 8, 3)
s.rect(mx + 120, my + 40, 44, 44, "#cfd8dc", "#78909c", 3)
s.text(mx + 142, my + 108, "SHT40", 14, "middle", "bold", "#fff")
m = {}
for i, n in enumerate(["VIN", "GND", "SCL", "SDA"]):
    py = my + 30 + i * 30; s.pad(mx, py, n, "L"); m[n] = (mx, py)
s.text(mx + 100, my + 176, "SHT40 モジュール（温湿度）", 15, "middle", "bold")
s.text(mx + 100, my + 196, "ピンの並びは基板の印字で確認", 12, "middle", fill="#b23", weight="bold")
s.wire([m["VIN"], (680, m["VIN"][1]), (680, YP)], "red")
s.wire([m["GND"], (660, m["GND"][1]), (660, YM)], "black")
s.wire([m["SCL"], (620, m["SCL"][1]), (620, 1060), (100, 1060), (100, p["D5"][1]), p["D5"]], "blue", "SCL → D5", (300, 1066))
s.wire([m["SDA"], (640, m["SDA"][1]), (640, 1080), (80, 1080), (80, p["D4"][1]), p["D4"]], "green", "SDA → D4", (440, 1086))
y = table(s, 60, 1150, [
    ["線", "どこから", "どこへ", "役目"],
    ["purple", "XIAO D3", "抵抗 10kΩ の片方", "測る瞬間だけ電気を流す"],
    ["yellow", "抵抗のもう片方", "XIAO D2（A2）", "温度を電圧で読む（測る点）"],
    ["yellow", "測る点", "サーミスターの片方・0.1µF の片方", ""],
    ["black", "サーミスターのもう片方・0.1µF", "− レール", "マイナス"],
    ["red", "XIAO 3V3 ／ 電池 ＋", "＋ レール", "電池を 3V3 に直接つなぐ"],
    ["black", "XIAO GND ／ 電池 −", "− レール", ""],
    ["red", "SHT40 VIN", "＋ レール", "SHT40 の電源（3V）"],
    ["black", "SHT40 GND", "− レール", ""],
    ["green", "SHT40 SDA", "XIAO D4", "データ（I2C）"],
    ["blue", "SHT40 SCL", "XIAO D5", "合図（I2C）"],
], [110, 290, 330, 330], "つなぐ線")
y = table(s, 60, y + 50, [
    ["部品", "数", "使うところ・メモ"],
    ["Seeed XIAO BLE nRF52840", "1", "本体（マイコン）"],
    ["SHT40 モジュール（温湿度、I2C）", "1", "湿度と温度。VIN・GND・SDA(D4)・SCL(D5)"],
    ["サーミスター 10kΩ（平行線タイプ）", "1", "測る点と − の間。巣箱の中へ"],
    ["抵抗 10kΩ 1%（金属皮膜） ★リストに無い・必要", "1", "D3 と測る点の間"],
    ["積層セラミックコンデンサー 0.1µF 50V C0G 5mm", "1", "測る点と − の間（ノイズよけ）"],
    ["絶縁ラジアルリード型積層セラミックコンデンサー 100µF 6.3V 5mm", "1", "＋ と − の間（XIAO の近く）。向きなし"],
    ["CR123A 用電池ケース ／ CR123A 電池", "各1", "＋ を 3V3、− を GND へ"],
    ["その他: 配線用の線・ブレッドボードかユニバーサル基板・ピンヘッダー", "", "手持ちに合わせて"],
], [560, 50, 550], "部品表")
notes(s, 60, y + 20, [
    "・サーミスターの電圧を読むには 10kΩ の固定抵抗が要ります（リストに無いので追加してください）。",
    "・HPX-TH のファームはまだありません（サーミスターの試験スケッチだけ）。この配線に合わせたファームはこれから作ります。",
    "・SHT40 は巣箱の中の空気に触れる場所へ。基板がむき出しなので、結露や蜂のプロポリスで汚れないよう通気性のあるカバーを。",
    "・SHT40 モジュールに電源ランプ（LED）が付いていると、電池が早く減ります。付いていたら教えてください。",
    "・SHT40 チップは 3.6V まで。電池（約3V）につなぐのは OK、5V にはつながない。",
    "・USB で書き込むときは、必ず電池を外す（3V3 に電池を直結しているため）。",
])
s.save("HPX-TH_wiring.svg")

# ------------------------------------------------------------------ HPX-A
s = S(1280, 1720)
title(s, "HPX-A（電柵アラート）部品表と配線図", "ファーム HPX_Alert v1.02 ／ D1→10kΩ→D0(A0)、CdS セル D0–GND ／ 電源 CR123A")
p = xiao(s, 190, 200)
bx, by = 560, 170
s.rect(bx, by, 340, 380, "#f1efe6", "#cfc8b0", 10, 2)
for gx in range(15):
    for gy in range(15):
        s.add(f'<rect x="{bx+15+gx*22}" y="{by+18+gy*24}" width="6" height="6" fill="#bdb6a0"/>')
s.text(bx + 340, by - 10, "ブレッドボード（はんだ付けでも可）", 14, "end", fill="#666")
rx_, r1, r2 = 660, 230, 350
s.add(f'<line x1="{rx_}" y1="{r1}" x2="{rx_}" y2="{r2}" stroke="#888" stroke-width="3"/>')
s.rect(rx_ - 13, r1 + 20, 26, 80, "#e8c88f", "#a07c3c", 10, 2)
for i, c in enumerate(["#8d5524", "#212121", "#f57c00", "#d4af37"]):   # 茶黒橙金 = 10kΩ
    s.rect(rx_ - 13, r1 + 30 + i * 16, 26, 7, c, rx=0)
s.rect(rx_ + 18, r1 + 30, 170, 64, "#fffdf6dd", rx=6)
s.text(rx_ + 26, r1 + 52, "抵抗 10kΩ", 15, weight="bold")
s.text(rx_ + 26, r1 + 72, "★リストに無い・必要", 13, fill="#b23", weight="bold")
s.text(rx_ + 26, r1 + 88, "向きはどちらでもよい", 12, fill="#666")
NODE = 420
s.wire([p["D1"], (130, p["D1"][1]), (130, 120), (rx_, 120), (rx_, r1)], "purple", "D1→抵抗の片方", (300, 110))
s.wire([p["D0"], (150, p["D0"][1]), (150, 150), (600, 150), (600, NODE), (rx_, NODE)], "yellow", "D0（A0）", (290, 140))
s.wire([(rx_, r2), (rx_, NODE), (880, NODE)], "yellow")
s.dot(rx_, NODE, "yellow")
s.top.append(f'<rect x="{rx_+14}" y="{NODE-30}" width="120" height="20" rx="4" fill="#ffffffdd"/>')
s.text(rx_ + 20, NODE - 14, "ここが測る点", 13, weight="bold", fill="#7a5a00", layer=s.top)
YM, YP = 680, 720
rails(s, 160, 900, YM, YP)
# CdS セル (足 2本、向きなし)
tx, ty = 1110, 470
s.add(f'<path d="M{tx},{ty-6} L960,{ty-6} L960,{NODE} L880,{NODE}" fill="none" stroke="#9e9e9e" stroke-width="4"/>')
s.add(f'<path d="M{tx},{ty+6} L980,{ty+6} L980,{YM} L880,{YM}" fill="none" stroke="#9e9e9e" stroke-width="4"/>')
s.add(f'<circle cx="{tx+20}" cy="{ty}" r="20" fill="#f3e5ab" stroke="#8d6e63" stroke-width="3"/>')
s.add(f'<path d="M{tx+6},{ty-8} Q{tx+20},{ty-2} {tx+34},{ty-8} M{tx+6},{ty} Q{tx+20},{ty+6} {tx+34},{ty} M{tx+6},{ty+8} Q{tx+20},{ty+14} {tx+34},{ty+8}" stroke="#c62828" stroke-width="2" fill="none"/>')
s.top.append(f'<circle cx="880" cy="{NODE}" r="5" fill="#666"/>')
s.top.append(f'<circle cx="880" cy="{YM}" r="5" fill="#666"/>')
s.text(1000, ty - 56, "CdS セル 5506（φ5mm）", 15, weight="bold")
s.text(1000, ty - 36, "向きなし。線で延長してよい", 13, fill="#666")
s.text(1000, ty + 44, "警報器の赤いカバーに向け、", 13, fill="#666")
s.text(1000, ty + 62, "カバーごと黒いもので遮光", 13, fill="#666")
s.wire([p["GND"], (450, p["GND"][1]), (450, YM)], "black")
s.wire([p["3V3"], (420, p["3V3"][1]), (420, YP)], "red")
cap(s, 300, 610, YM, YP, "100µF", "電源の安定", big=True, right=True)
cap(s, 520, 610, YM, YP, "0.1µF", "XIAO の近く", right=True)
bp, bm = cr123a(s, 300, 800)
s.wire([bm, (230, bm[1]), (230, YM)], "black")
s.wire([bp, (600, bp[1]), (600, YP)], "red")
y = table(s, 60, 990, [
    ["線", "どこから", "どこへ", "役目"],
    ["purple", "XIAO D1", "抵抗 10kΩ の片方", "読む瞬間だけ光センサに電気を流す"],
    ["yellow", "抵抗のもう片方", "XIAO D0（A0）", "光の強さを電圧で読む（測る点）"],
    ["yellow", "測る点", "CdS セルの片方", ""],
    ["black", "CdS セルのもう片方", "− レール", "マイナス"],
    ["red", "XIAO 3V3 ／ 電池 ＋", "＋ レール", "電池を 3V3 に直接つなぐ"],
    ["black", "XIAO GND ／ 電池 −", "− レール", ""],
], [110, 290, 290, 370], "つなぐ線")
y = table(s, 60, y + 50, [
    ["部品", "数", "使うところ・メモ"],
    ["Seeed XIAO BLE nRF52840", "1", "本体（マイコン）"],
    ["CdS セル（フォトレジスタ）5506 φ5mm", "1", "測る点と − の間。向きなし"],
    ["抵抗 10kΩ（1/4W） ★リストに無い・必要", "1", "D1 と測る点の間。調整で 4.7k〜47kΩ に替えることも"],
    ["積層セラミックコンデンサー 0.1µF 50V C0G 5mm", "1", "＋ と − の間（XIAO の近く）。測る点には付けない"],
    ["絶縁ラジアルリード型積層セラミックコンデンサー 100µF 6.3V 5mm", "1", "＋ と − の間。向きなし"],
    ["CR123A 用電池ケース ／ CR123A 電池", "各1", "＋ を 3V3、− を GND へ"],
    ["その他: 配線用の線・基板・黒い遮光キャップ（熱収縮チューブなど）", "", "手持ちに合わせて"],
], [560, 50, 550], "部品表")
notes(s, 60, y + 20, [
    "・フェンスアラート（StaFIX）の回路と XIAO を電線でつながない。光だけで読む（柵線の高電圧が回り込むため）。",
    "・0.1µF を測る点（D0）に付けると、ファームの読み取り（電源を入れて 50µs 後に読む）に間に合わなくなるので付けない。",
    "・CdS セルは反応が遅め。警報器の点滅が短いと数えられないかもしれません。実機でシリアルの「blink」が出るか確かめる。",
    "・抵抗の目安: シリアルの light が 3900 近くに張り付くなら 4.7kΩ、点滅の peak が 200 に届かないなら 22k〜47kΩ。",
    "・USB で書き込むときは、必ず電池を外す（3V3 に電池を直結しているため）。",
])
s.save("HPX-A_wiring.svg")

# ------------------------------------------------------------------ HPX-Core
s = S(1280, 1260)
title(s, "HPX-Core（親機）部品表と配線図", "ファーム HPX_Core v1.15 ／ Freenove FNK0090B（ESP32-WROOM-32E）／ 電源 USB")
cx, cy = 520, 160
s.rect(cx, cy, 240, 400, "#263238", "#111", 10, 3)
s.rect(cx + 40, cy + 30, 160, 170, "#b0bec5", "#78909c", 6, 2)
s.text(cx + 120, cy + 110, "ESP32-", 16, "middle", "bold", "#263238")
s.text(cx + 120, cy + 132, "WROOM-32E", 16, "middle", "bold", "#263238")
s.rect(cx + 85, cy + 385, 70, 30, "#b0b0b0", "#777", 6)
s.rect(cx + 30, cy + 320, 40, 30, "#eceff1", "#555", 4); s.text(cx + 50, cy + 370, "BOOT", 13, "middle", "bold", "#eee")
s.rect(cx + 170, cy + 320, 40, 30, "#eceff1", "#555", 4); s.text(cx + 190, cy + 370, "RESET", 13, "middle", "bold", "#eee")
s.add(f'<circle cx="{cx+120}" cy="{cy+260}" r="10" fill="#42a5f5" stroke="#0d47a1" stroke-width="2"/>')
s.text(cx + 140, cy + 266, "LED（GPIO2）", 13, fill="#eee")
for i in range(15):
    s.add(f'<circle cx="{cx+14}" cy="{cy+40+i*22}" r="5" fill="#d4af37"/>')
    s.add(f'<circle cx="{cx+226}" cy="{cy+40+i*22}" r="5" fill="#d4af37"/>')
s.text(cx + 120, cy - 30, "Freenove FNK0090B", 15, "middle", "bold")
s.text(cx + 120, cy - 10, "（図はイメージ。ボタンの位置は実物で確認）", 12, "middle", fill="#666")
s.rect(cx + 60, 680 - 40, 120, 70, "#eeeeee", "#999", 10, 2)
s.text(cx + 120, 680, "USB 電源", 15, "middle", "bold")
s.text(cx + 120, 700, "（5V・コンセント）", 12, "middle", fill="#666")
s.add(f'<path d="M{cx+120},{cy+415} L{cx+120},640" stroke="#424242" stroke-width="9" stroke-linecap="round"/>')
s.text(cx + 135, 610, "USB ケーブル", 14, fill="#333")
for i, (n, d) in enumerate([("HPX-W", "重量（BLE）"), ("HPX-TH", "温湿度（BLE）"), ("HPX-A", "電柵アラート（BLE）")]):
    yy = 210 + i * 110
    s.rect(70, yy, 300, 70, "#ffffff", "#c9c2a8", 10, 2)
    s.text(90, yy + 30, n, 16, weight="bold"); s.text(90, yy + 54, d, 14, fill="#666")
    s.add(f'<path d="M380,{yy+35} L{cx-20},{cy+150}" stroke="#1e88e5" stroke-width="2.5" stroke-dasharray="8 7" fill="none"/>')
s.text(220, 190, "電線なし（電波で受信）", 15, "middle", "bold", "#1565c0")
s.rect(830, 270, 360, 84, "#ffffff", "#c9c2a8", 10, 2)
s.text(850, 305, "Wi-Fi ルーター → サーバー", 16, weight="bold")
s.text(850, 332, "Wi-Fi で送る（電線なし）", 14, fill="#666")
s.add(f'<path d="M{cx+250},{cy+150} L825,320" stroke="#43a047" stroke-width="2.5" stroke-dasharray="8 7" fill="none"/>')
y = table(s, 60, 800, [
    ["部品", "数", "使うところ・メモ"],
    ["ESP32 開発ボード Freenove FNK0090B 4MB", "1", "本体（親機）"],
    ["USB ケーブル（ボードの差し込み口に合うもの）", "1", "電源と書き込み"],
    ["USB 電源アダプター 5V（1A 以上）", "1", "コンセントから電源を取る"],
], [560, 50, 550], "部品表")
notes(s, 60, y + 20, [
    "・HPX-Core には外付けの部品も配線もありません。USB 電源をつなぐだけで動きます。",
    "・コンデンサー（0.1µF・100µF）と電池ケースは、USB 電源なら要りません。",
    "・管理画面の動作モードは「常時稼働（商用電源）」のまま使う。",
    "・BOOT：工場出荷リセットに使う（RESET を押して離し、3秒以内に BOOT を5秒長押し）。",
])
s.save("HPX-Core_wiring.svg")
print("ok")
