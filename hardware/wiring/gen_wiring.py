#!/usr/bin/env python3
# 配線図 (ブレッドボード風) の SVG を作る
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

# ------------------------------------------------------------------ HPX-F
s = S(1280, 1720)
title(s, "HPX-F（電柵アラート）部品表と配線図", "ファーム HPX_F v1.04 ／ D1→10kΩ→D0(A0)、CdS セル D0–GND ／ 電源 CR123A")
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
# ＋防水 (TARO 2026-11-08: 基板を Olimex ESP32-DEVKIT-LIPO に、電源は防水USBカプラーから)
# Olimex の部品の位置は公式資料 (GitHub OLIMEX/ESP32-DevKit-LiPo Rev.D) から: アンテナが上、micro USB が下、
# RST ボタンが左下、BAT コネクタが右下。BOOT ボタンと GPIO2 の LED はない。
s = S(1280, 1300)
title(s, "【以前の図】HPX-Core（親機）＋防水の部品表と配線図", "Olimex ESP32-DEVKIT-LIPO ／ 電源 USB")
cx, cy = 524, 170
s.rect(cx, cy, 232, 400, "#c62828", "#7f0000", 10, 3)
s.rect(cx + 56, cy - 14, 120, 44, "#212121", "#111", 4, 2)
s.text(cx + 116, cy + 12, "アンテナ", 12, "middle", "bold", "#eee")
s.rect(cx + 46, cy + 34, 140, 150, "#b0bec5", "#78909c", 6, 2)
s.text(cx + 116, cy + 104, "ESP32-", 16, "middle", "bold", "#263238")
s.text(cx + 116, cy + 126, "WROOM-32E", 16, "middle", "bold", "#263238")
s.text(cx + 116, cy + 236, "Olimex", 15, "middle", "bold", "#fff")
s.text(cx + 116, cy + 256, "ESP32-DevKit-LiPo", 13, "middle", "bold", "#fff")
s.rect(cx + 90, cy + 372, 52, 34, "#b0b0b0", "#777", 5)          # micro USB
s.rect(cx + 22, cy + 330, 40, 40, "#eceff1", "#555", 4); s.text(cx + 42, cy + 322, "RST", 13, "middle", "bold", "#fff")
s.rect(cx + 164, cy + 340, 50, 40, "#fafafa", "#9e9e9e", 4); s.text(cx + 189, cy + 332, "BAT", 13, "middle", "bold", "#fff")
for i in range(19):
    s.add(f'<circle cx="{cx+12}" cy="{cy+30+i*19.5}" r="5" fill="#d4af37"/>')
    s.add(f'<circle cx="{cx+220}" cy="{cy+30+i*19.5}" r="5" fill="#d4af37"/>')
s.text(cx + 116, cy - 40, "Olimex ESP32-DEVKIT-LIPO", 15, "middle", "bold")
s.text(cx + 116, cy - 22, "（図はイメージ。部品の位置は公式資料から）", 12, "middle", fill="#666")
s.text(cx + 250, cy + 365, "← BAT は使わない", 13, fill="#c62828", weight="bold")
s.text(cx + 100, cy + 440, "micro USB", 13, "end", "bold", "#333")
# ケーブル → 防水USBカプラー（ケースの壁）→ 外の USB 電源
s.add(f'<path d="M{cx+116},{cy+412} L{cx+116},700" stroke="#424242" stroke-width="9" stroke-linecap="round"/>')
s.text(cx + 100, 650, "USB-C to MicroUSB ケーブル 15cm", 14, "end", fill="#333")
s.rect(330, 720, 620, 16, "#90a4ae", "#607d8b", 3, 1)
s.text(316, 733, "ケースの壁", 13, "end", "bold", "#607d8b")
s.rect(cx + 56, 700, 120, 56, "#455a64", "#263238", 8, 2)
s.text(cx + 116, 726, "防水USBカプラー", 13, "middle", "bold", "#fff")
s.text(cx + 116, 745, "USB-C メス-メス", 11, "middle", fill="#eceff1")
s.add(f'<path d="M{cx+116},756 L{cx+116},800" stroke="#424242" stroke-width="9" stroke-linecap="round" stroke-dasharray="4 8"/>')
s.text(cx + 132, 790, "ケースの外：USB-C ケーブルで USB 電源（5V・1A 以上）へ", 13, fill="#666")
s.text(960, 690, "ケースの中", 13, "end", "bold", "#999"); s.text(960, 770, "ケースの外", 13, "end", "bold", "#999")
for i, (n, d) in enumerate([("HPX-W", "重量（BLE）"), ("HPX-TH", "温湿度（BLE）"), ("HPX-F", "電柵アラート（BLE）")]):
    yy = 210 + i * 110
    s.rect(70, yy, 300, 70, "#ffffff", "#c9c2a8", 10, 2)
    s.text(90, yy + 30, n, 16, weight="bold"); s.text(90, yy + 54, d, 14, fill="#666")
    s.add(f'<path d="M380,{yy+35} L{cx-20},{cy+150}" stroke="#1e88e5" stroke-width="2.5" stroke-dasharray="8 7" fill="none"/>')
s.text(220, 190, "電線なし（電波で受信）", 15, "middle", "bold", "#1565c0")
s.rect(830, 270, 360, 84, "#ffffff", "#c9c2a8", 10, 2)
s.text(850, 305, "Wi-Fi ルーター → サーバー", 16, weight="bold")
s.text(850, 332, "Wi-Fi で送る（電線なし）", 14, fill="#666")
s.add(f'<path d="M{cx+250},{cy+150} L825,320" stroke="#43a047" stroke-width="2.5" stroke-dasharray="8 7" fill="none"/>')
# HPX-Solar で電圧も測るとき (TARO 2026-11-10、案2): micro USB の代わりに、ケースの中で USB-C メス基板 (データ対応) に受けて 4本に分ける
s.rect(900, 380, 340, 290, "#fff3e0", "#e65100", 10, 2)
s.text(1070, 408, "HPX-Solar で電圧も測るとき", 15, "middle", "bold", "#e65100")
s.text(1070, 428, "（micro USB の代わりに、中をこうする）", 11, "middle", fill="#666")
s.rect(920, 450, 90, 120, "#37474f", "#222", 6, 2)
s.text(965, 590, "USB-C メス基板", 11, "middle", "bold"); s.text(965, 604, "（データ対応）", 10, "middle", fill="#666")
s.text(1070, 630, "防水USBカプラーから USB-C ケーブル", 11, "middle", fill="#555"); s.text(1070, 644, "（データ対応）で受ける", 11, "middle", fill="#555")
for i, (n, to, c) in enumerate([("VBUS", "5V", "red"), ("GND", "GND", "black"), ("D+", "IO34", "white"), ("D−", "IO36", "blue")]):
    yy = 470 + i * 26
    s.add(f'<circle cx="1010" cy="{yy}" r="6" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
    s.text(1000, yy + 4, n, 11, "end", "bold", "#fff")
    s.wire([(1010, yy), (1110, yy)], c)
    s.text(1118, yy + 5, "Olimex " + to, 12, weight="bold")
s.text(1120, 590, "ジャンパー線 4本", 12, "middle", "bold", "#333")
s.text(1070, 664, "設定「電圧の測り方」＝HPX-Solar、ディープスリープ", 11, "middle", "bold", "#c62828")
CORE_ROWS = [
    ["部品", "数", "使うところ・メモ"],
    ["ESP32 開発ボード Olimex ESP32-DEVKIT-LIPO", "1", "本体（親機）。差し込み口は micro USB"],
    ["USB-C to MicroUSB ケーブル 15cm", "1", "ケースの中で、防水USBカプラーと Olimex をつなぐ"],
    ["防水USBカプラー（USB Type-C メス-メス）", "1", "ケースの壁に取り付ける。外から USB 電源をつなぐ"],
    ["（HPX-Solar のとき）USB-C メス基板（データ対応）・USB-C ケーブル 15cm", "各1", "USB-C to MicroUSB ケーブルの代わり。カプラーから受けて 4本に分ける"],
    ["（HPX-Solar のとき）ジャンパー線・ピンヘッダー", "4本", "VBUS → 5V、GND → GND、D+ → IO34、D− → IO36"],
]
y = table(s, 60, 860, CORE_ROWS, [560, 50, 550], "部品表")
CORE_NOTES = [
    "・HPX-Core には外付けの部品も配線もありません。防水USBカプラーから USB 電源をつなぐだけで動きます。",
    "・HPX-Solar（小型ソーラー電源）から電気をもらい、電池とパネルの電圧も測るときは、右の枠のように USB-C メス基板で 4本に分ける（試作中・実機未確認）。",
    "  そのときは Olimex を 5V ピンから動かし、動作設定の「電圧の測り方」で「HPX-Solar」、動作モードは「ディープスリープ」にする。USB 電源に戻すときは「測らない」に。",
    "・基板の「BAT」コネクタは充電式リチウムイオン電池用。この版では何もつながない。",
    "・USB 電源のときは、管理画面の動作モードは「常時稼働（商用電源）」のまま使う。",
    "・Olimex には BOOT ボタンと GPIO2 の LED がありません。工場出荷リセット（BOOT 長押し）は、GPIO0 のピンを GND に5秒つないで代わりにする（未確認）。",
]
notes(s, 60, y + 20, CORE_NOTES)
s.save("HPX-Core_olimex_wiring.svg")
# ウェブサイト用 (TARO 2026-11-08): 図だけの SVG (部品表の上で切る) と、部品表・注意の文字 (JSON)。表・注意はページに HTML で出す
_full = open(os.path.join(OUT, "HPX-Core_olimex_wiring.svg"), encoding="utf-8").read()
_cut = 820
open(os.path.join(OUT, "HPX-Core_olimex_wiring_fig.svg"), "w", encoding="utf-8").write(
    _full.replace(f'width="{s.w}" height="{s.h}" viewBox="0 0 {s.w} {s.h}"', f'width="{s.w}" height="{_cut}" viewBox="0 0 {s.w} {_cut}"', 1))
import json
json.dump({"tables": [{"head": "部品の使いどころ", "rows": CORE_ROWS}], "notes": [n.lstrip("・") for n in CORE_NOTES]},
          open(os.path.join(OUT, "HPX-Core_olimex_wiring.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)
print("ok")
# ------------------------------------------------------------------ HPX-Core
# ＋防水 (TARO 2026-11-13: FireBeetle 2 ESP32-E に。電源は防水USBカプラーから USB-C ケーブルで)
s = S(1280, 1300)
title(s, "HPX-Core（親機）＋防水の部品表と配線図", "FireBeetle 2 ESP32-E ／ 電源 USB ／ ファーム HPX_Core v1.25")
cx, cy = 524, 170
s.rect(cx, cy, 232, 400, "#37474f", "#102027", 10, 3)
s.rect(cx + 46, cy + 8, 140, 196, "#b0bec5", "#78909c", 6, 2)
s.rect(cx + 46, cy + 8, 140, 46, "#212121", "#111", 4, 2)
s.text(cx + 116, cy + 36, "アンテナ", 12, "middle", "bold", "#eee")
s.text(cx + 116, cy + 120, "ESP32-", 16, "middle", "bold", "#263238")
s.text(cx + 116, cy + 142, "WROOM-32E", 16, "middle", "bold", "#263238")
s.text(cx + 116, cy + 250, "FireBeetle 2", 15, "middle", "bold", "#fff")
s.text(cx + 116, cy + 270, "ESP32-E", 13, "middle", "bold", "#fff")
s.rect(cx + 82, cy + 380, 68, 34, "#b0b0b0", "#777", 8)          # USB-C
s.rect(cx + 30, cy + 330, 34, 30, "#eceff1", "#555", 4); s.text(cx + 47, cy + 322, "RST", 12, "middle", "bold", "#fff")
s.rect(cx + 168, cy + 330, 34, 30, "#eceff1", "#555", 4); s.text(cx + 185, cy + 322, "IO27", 12, "middle", "bold", "#fff")
s.rect(cx + 150, cy + 278, 60, 26, "#fafafa", "#9e9e9e", 4); s.text(cx + 180, cy + 296, "電池", 11, "middle", "bold", "#555")
for i in range(18):
    s.add(f'<circle cx="{cx+12}" cy="{cy+24+i*20.5}" r="5" fill="#d4af37"/>')
for i in range(14):
    s.add(f'<circle cx="{cx+220}" cy="{cy+24+(i+4)*20.5}" r="5" fill="#d4af37"/>')
s.text(cx + 116, cy - 40, "DFRobot FireBeetle 2 ESP32-E（DFR0654）", 15, "middle", "bold")
s.text(cx + 116, cy - 22, "（図はイメージ。部品の位置は DFRobot の資料から）", 12, "middle", fill="#666")
s.text(cx + 250, cy + 296, "← 使わない", 13, fill="#c62828", weight="bold")
s.text(cx + 100, cy + 440, "USB-C", 13, "end", "bold", "#333")
# ケーブル → 防水USBカプラー（ケースの壁）→ 外の USB 電源
s.add(f'<path d="M{cx+116},{cy+412} L{cx+116},700" stroke="#424242" stroke-width="9" stroke-linecap="round"/>')
s.text(cx + 100, 650, "USB-C ケーブル（両側 USB-C、短いもの）", 14, "end", fill="#333")
s.rect(330, 720, 620, 16, "#90a4ae", "#607d8b", 3, 1)
s.text(316, 733, "ケースの壁", 13, "end", "bold", "#607d8b")
s.rect(cx + 56, 700, 120, 56, "#455a64", "#263238", 8, 2)
s.text(cx + 116, 726, "防水USBカプラー", 13, "middle", "bold", "#fff")
s.text(cx + 116, 745, "USB-C メス-メス", 11, "middle", fill="#eceff1")
s.add(f'<path d="M{cx+116},756 L{cx+116},800" stroke="#424242" stroke-width="9" stroke-linecap="round" stroke-dasharray="4 8"/>')
s.text(cx + 132, 790, "ケースの外：USB-C ケーブルで USB 電源（5V・1A 以上）へ", 13, fill="#666")
s.text(960, 690, "ケースの中", 13, "end", "bold", "#999"); s.text(960, 770, "ケースの外", 13, "end", "bold", "#999")
for i, (n, d) in enumerate([("HPX-W", "重量（BLE）"), ("HPX-TH", "温湿度（BLE）"), ("HPX-F", "電柵アラート（BLE）")]):
    yy = 210 + i * 110
    s.rect(70, yy, 300, 70, "#ffffff", "#c9c2a8", 10, 2)
    s.text(90, yy + 30, n, 16, weight="bold"); s.text(90, yy + 54, d, 14, fill="#666")
    s.add(f'<path d="M380,{yy+35} L{cx-20},{cy+150}" stroke="#1e88e5" stroke-width="2.5" stroke-dasharray="8 7" fill="none"/>')
s.text(220, 190, "電線なし（電波で受信）", 15, "middle", "bold", "#1565c0")
s.rect(830, 270, 360, 84, "#ffffff", "#c9c2a8", 10, 2)
s.text(850, 305, "Wi-Fi ルーター → サーバー", 16, weight="bold")
s.text(850, 332, "Wi-Fi で送る（電線なし）", 14, fill="#666")
s.add(f'<path d="M{cx+250},{cy+150} L825,320" stroke="#43a047" stroke-width="2.5" stroke-dasharray="8 7" fill="none"/>')
# HPX-Solar で電圧も測るとき (TARO 2026-11-10、案2): micro USB の代わりに、ケースの中で USB-C メス基板 (データ対応) に受けて 4本に分ける
s.rect(900, 380, 340, 290, "#fff3e0", "#e65100", 10, 2)
s.text(1070, 408, "HPX-Solar で電圧も測るとき", 15, "middle", "bold", "#e65100")
s.text(1070, 428, "（USB-C ケーブルの代わりに、中をこうする）", 11, "middle", fill="#666")
s.rect(920, 450, 90, 120, "#37474f", "#222", 6, 2)
s.text(965, 590, "USB-C メス基板", 11, "middle", "bold"); s.text(965, 604, "（データ対応）", 10, "middle", fill="#666")
s.text(1070, 630, "防水USBカプラーから USB-C ケーブル", 11, "middle", fill="#555"); s.text(1070, 644, "（データ対応）で受ける", 11, "middle", fill="#555")
for i, (n, to, c) in enumerate([("VBUS", "VCC", "red"), ("GND", "GND", "black"), ("D+", "IO39", "white"), ("D−", "IO36", "blue")]):
    yy = 470 + i * 26
    s.add(f'<circle cx="1010" cy="{yy}" r="6" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
    s.text(1000, yy + 4, n, 11, "end", "bold", "#fff")
    s.wire([(1010, yy), (1110, yy)], c)
    s.text(1118, yy + 5, "FireBeetle " + to, 12, weight="bold")
s.text(1120, 590, "ジャンパー線 4本", 12, "middle", "bold", "#333")
s.text(1070, 664, "設定「電圧の測り方」＝HPX-Solar、ディープスリープ", 11, "middle", "bold", "#c62828")
CORE_ROWS = [
    ["部品", "数", "使うところ・メモ"],
    ["DFRobot FireBeetle 2 ESP32-E（DFR0654）", "1", "本体（親機）。差し込み口は USB-C"],
    ["USB-C ケーブル（両側 USB-C、短いもの）", "1", "ケースの中で、防水USBカプラーと FireBeetle をつなぐ"],
    ["防水USBカプラー（USB Type-C メス-メス）", "1", "ケースの壁に取り付ける。外から USB 電源をつなぐ"],
    ["（HPX-Solar のとき）USB-C メス基板（データ対応）・USB-C ケーブル（データ対応）", "各1", "中の USB-C ケーブルの代わり。カプラーから受けて 4本に分ける"],
    ["（HPX-Solar のとき）ジャンパー線・ピンヘッダー", "4本", "VBUS → VCC、GND → GND、D+ → IO39、D− → IO36"],
]
y = table(s, 60, 860, CORE_ROWS, [560, 50, 550], "部品表")
CORE_NOTES = [
    "・HPX-Core には外付けの部品も配線もありません。防水USBカプラーから USB 電源をつなぐだけで動きます。",
    "・HPX-Solar（小型ソーラー電源）から電気をもらい、電池とパネルの電圧も測るときは、右の枠のように USB-C メス基板で 4本に分ける（試作中・実機未確認）。",
    "  そのときは FireBeetle を VCC ピンから動かし（D+ は IO39 で読む。ファーム v1.25 から）、動作設定の「電圧の測り方」で「HPX-Solar」、動作モードは「ディープスリープ」にする。USB 電源に戻すときは「測らない」に。",
    "・FireBeetle の電池コネクタ（PH2.0）は充電式リチウムイオン電池用。この版では何もつながない。",
    "・USB 電源のときは、管理画面の動作モードは「常時稼働（商用電源）」のまま使う。",
    "・FireBeetle には BOOT ボタンがありません。工場出荷リセットは、ファーム v1.25 なら基板のボタン（IO27）を起動してすぐ5秒長押し（v1.24 までは IO0 のピンを GND に5秒つなぐ）。",
    "・ケースの中のケーブルは、両側 USB-C の短いもの（L型だと収まりやすい）。HPX-Solar のときはデータ対応のものにする。",
]
notes(s, 60, y + 20, CORE_NOTES)
s.save("HPX-Core_wiring.svg")
# ウェブサイト用 (TARO 2026-11-08): 図だけの SVG (部品表の上で切る) と、部品表・注意の文字 (JSON)。表・注意はページに HTML で出す
_full = open(os.path.join(OUT, "HPX-Core_wiring.svg"), encoding="utf-8").read()
_cut = 820
open(os.path.join(OUT, "HPX-Core_wiring_fig.svg"), "w", encoding="utf-8").write(
    _full.replace(f'width="{s.w}" height="{s.h}" viewBox="0 0 {s.w} {s.h}"', f'width="{s.w}" height="{_cut}" viewBox="0 0 {s.w} {_cut}"', 1))
import json
json.dump({"tables": [{"head": "部品の使いどころ", "rows": CORE_ROWS}], "notes": [n.lstrip("・") for n in CORE_NOTES]},
          open(os.path.join(OUT, "HPX-Core_wiring.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)
print("ok")

# ------------------------------------------------------------------ HPX-Core 有線LAN (W5500)
s = S(1280, 1770)
title(s, "HPX-Core（親機）有線LAN（W5500）の部品表と配線図", "ファーム HPX_Core v1.20 以降 ／ 電源 USB")
# ESP32 ボード
cx, cy = 150, 170
s.rect(cx, cy, 300, 520, "#263238", "#111", 10, 3)
s.rect(cx + 50, cy + 30, 150, 150, "#b0bec5", "#78909c", 6, 2)
s.text(cx + 125, cy + 100, "ESP32-", 16, "middle", "bold", "#263238")
s.text(cx + 125, cy + 122, "WROOM-32E", 16, "middle", "bold", "#263238")
s.rect(cx + 115, cy + 505, 70, 30, "#b0b0b0", "#777", 6)
s.text(cx + 150, cy - 30, "ELEGOO ESP-32（USB-C）", 15, "middle", "bold")
s.text(cx + 150, cy - 10, "（図は線の対応だけ。並びは基板の図を見る）", 12, "middle", fill="#666")
esp = {}
epins = ["3V3", "GND", "D18", "D4", "D23", "D5", "TX2", "RX2"]
for i, n in enumerate(epins):
    py = cy + 210 + i * 38
    s.pad(cx + 300, py, n, "R"); esp[n] = (cx + 300, py)
# W5500 モジュール
wx, wy = 790, 230
s.rect(wx, wy, 260, 400, "#1565c0", "#0d3c7a", 10, 3)
s.rect(wx + 120, wy + 130, 110, 110, "#212121", "#000", 4, 2)
s.text(wx + 175, wy + 190, "W5500", 15, "middle", "bold", "#eee")
s.rect(wx + 250, wy + 270, 90, 100, "#cfd8dc", "#607d8b", 6, 3)      # LAN の差し込み口
s.rect(wx + 268, wy + 300, 54, 40, "#37474f", "#222", 3, 1)
s.text(wx + 295, wy + 262, "LAN 差し込み口", 13, "middle", "bold")
s.text(wx + 130, wy - 30, "W5500 イーサネットモジュール", 15, "middle", "bold")
s.text(wx + 130, wy - 10, "（ピン 6本×2列。図は線の対応だけ。並びは基板の図を見る）", 12, "middle", fill="#666")
w5 = {}
wpins = ["V", "G", "SCK", "MI", "MO", "CS", "INT", "RST"]
for i, n in enumerate(wpins):
    py = wy + 40 + i * 38
    s.pad(wx, py, n, "L"); w5[n] = (wx, py)
wires = [("3V3", "V", "red"), ("GND", "G", "black"), ("D18", "SCK", "yellow"), ("D4", "MI", "green"),
         ("D23", "MO", "blue"), ("D5", "CS", "orange"), ("TX2", "INT", "purple"), ("RX2", "RST", "white")]
for i, (a, b, c) in enumerate(wires):
    (x1, y1), (x2, y2) = esp[a], w5[b]
    mx = 560 + i * 22
    s.wire([(x1, y1), (mx, y1), (mx, y2), (x2, y2)], c)
# LAN ケーブルと LTE ルーター
s.add(f'<path d="M{wx+340},{wy+320} L1190,{wy+320} L1190,800" stroke="#1e88e5" stroke-width="9" fill="none" stroke-linejoin="round" stroke-linecap="round"/>')
s.text(1180, wy + 460, "LAN ケーブル", 14, "end", "bold", "#1565c0")
s.rect(990, 800, 260, 84, "#ffffff", "#c9c2a8", 10, 2)
s.text(1010, 835, "LTE ルーター → サーバー", 16, weight="bold")
s.text(1010, 862, "LAN 差し込み口へ（Wi-Fi 無しでよい）", 13, fill="#666")
# USB 電源
s.add(f'<path d="M{cx+150},{cy+535} L{cx+150},790" stroke="#424242" stroke-width="9" stroke-linecap="round"/>')
s.text(cx + 165, 760, "USB ケーブル", 14, fill="#333")
s.rect(cx + 90, 790, 120, 70, "#eeeeee", "#999", 10, 2)
s.text(cx + 150, 830, "USB 電源", 15, "middle", "bold")
s.text(cx + 150, 850, "（5V・1A 以上）", 12, "middle", fill="#666")
s.text(640, 905, "センサ（HPX-W・HPX-TH・HPX-F）は、これまでどおり電波（BLE）で受け取ります。", 15, "middle", "bold", "#1565c0")
y = table(s, 60, 960, [
    ["線の色（例）", "W5500 のピン", "ESP32 のピン", "メモ"],
    ["red", "V（3.3V）", "3V3", "電源"],
    ["black", "G（GND）", "GND", ""],
    ["yellow", "SCK", "D18（GPIO18）", "SPI の時計"],
    ["green", "MI（MISO）", "D4（GPIO4）", "W5500 → ESP32"],
    ["blue", "MO（MOSI）", "D23（GPIO23）", "ESP32 → W5500"],
    ["orange", "CS", "D5（GPIO5）", "選択"],
    ["purple", "INT", "TX2（GPIO17）", "知らせ（ファームで -1 にすれば無くてもよい）"],
    ["white", "RST", "RX2（GPIO16）", "リセット（ファームで -1 にすれば無くてもよい）"],
], [180, 260, 200, 520], "つなぐ線（基板に組むときは HPX-Core_LAN_pcb.png、試すだけならジャンパー線でも可）")
y = table(s, 60, y + 60, [
    ["部品", "数", "使うところ・メモ"],
    ["ESP32 開発ボード ELEGOO ESP-32（USB-C・30ピン）", "1", "本体（親機）。いまの HPX-Core のまま"],
    ["W5500 イーサネットモジュール（ACEIRMC、6ピン×2）", "1", "有線LAN"],
    ["ユニバーサル基板 6×8cm・ピンソケット 15ピン×2・6ピン×2", "1式", "基板に組む（HPX-Core_LAN_pcb.png）"],
    ["LAN ケーブル（カテゴリー5e 以上）", "1", "W5500 → LTE ルーター"],
    ["防水の LAN コネクター（パネル取付）またはケーブルグランド", "1", "ケースの壁に通す。型番は未定"],
    ["USB ケーブル・USB 電源アダプター 5V（1A 以上）", "各1", "いまと同じ"],
], [560, 50, 550], "部品表（いまの HPX-Core に足すもの）")
notes(s, 60, y + 20, [
    "・管理画面の「動作設定」（初めてなら設定用 Wi-Fi の画面）で、接続方法を「有線LAN」にして保存します。",
    "・有線LANのときも、管理画面用の Wi-Fi「HPX-Setup-xxxx」は出たままです。スマートフォンでつなぎ http://192.168.4.1/ を開く。",
    "・動作モードは「常時稼働（商用電源）」で使う。W5500 は眠っている間も電気を使うので、電池には向きません。",
    "・電源を入れたまま抜き差ししない。",
    "・実機ではまだ確かめていません（ファーム v1.20 はコンパイルだけ確認済み）。",
    "・ピンは基板の裏の線が交わらないように選んだ（MISO＝D4、INT＝TX2、RST＝RX2）。",
])
s.save("HPX-Core_LAN_elegoo_wiring.svg")
print("ok LAN")

# ------------------------------------------------------------------ HPX-Core ＋LTE-M (TARO 2026-11-09、2026-11-10 電源を HPX-Solar に分け、USB-C ケーブル1本でつなぐ)
# Olimex ESP32-DEVKIT-LIPO ＋ M5Stamp CatM (SIM7080G)。電源は USB-C 口から (HPX-Solar か USB 充電器)。
# Olimex は 5V ピンから。CatM の 5V は基板の P-MOSFET で入り切り (IO4 → 10kΩ → NPN → ゲート)。D+・D− は HPX-Solar の電池・パネルの電圧 (IO34・IO36)。
s = S(1280, 1990)
title(s, "【以前の図】HPX-Core（親機）＋LTE-M の線の対応図", "試作中 ／ 実機未確認 ／ ファーム v1.24")
# HPX-Solar (別の機器)
s.rect(50, 110, 230, 170, "#fff3e0", "#e65100", 10, 3)
s.text(165, 150, "HPX-Solar", 18, "middle", "bold", "#e65100")
s.text(165, 174, "（別の機器・別のケース）", 12, "middle", fill="#e65100")
s.text(165, 200, "パネル・DFR0559・18650", 12, "middle", fill="#555")
s.rect(200, 220, 70, 46, "#37474f", "#222", 6, 2)
s.text(235, 248, "USB-C", 12, "middle", "bold", "#fff")
s.text(165, 305, "または USB 充電器（家庭用）", 13, "middle", "bold", "#555")
# USB-C ケーブル
s.add('<path d="M270,243 C330,243 330,243 380,243" stroke="#424242" stroke-width="12" stroke-linecap="round"/>')
s.text(325, 225, "USB-C ケーブル", 12, "middle", "bold", "#333")
s.text(325, 275, "（データ対応）", 11, "middle", fill="#666")
# HPX-Core 側の USB-C メス基板
s.rect(380, 150, 90, 230, "#37474f", "#222", 8, 2)
s.text(425, 400, "USB-C メス基板", 12, "middle", "bold"); s.text(425, 416, "（ケースの壁）", 11, "middle", fill="#666")
us = {}
for i, n in enumerate(["D+", "D−", "VBUS", "GND"]):
    yy = 180 + i * 50
    s.add(f'<circle cx="470" cy="{yy}" r="8" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
    s.text(456, yy + 5, n, 13, "end", "bold", "#fff"); us[n] = (470, yy)
def gnd(x, y):   # GND の印
    s.add(f'<path d="M{x},{y} L{x},{y+14} M{x-12},{y+14} L{x+12},{y+14} M{x-7},{y+20} L{x+7},{y+20} M{x-3},{y+26} L{x+3},{y+26}" stroke="#212121" stroke-width="3" fill="none"/>')
    s.text(x + 16, y + 22, "GND", 11, fill="#333")
# M5Stamp CatM
s.rect(560, 300, 260, 130, "#eceff1", "#90a4ae", 10, 3)
s.text(690, 350, "M5Stamp CatM", 16, "middle", "bold", "#263238")
s.text(690, 372, "SIM7080G（LTE-M）・microSIM", 12, "middle", fill="#455a64")
st = {}
for n, x in (("5V", 760), ("GND", 700)):
    s.add(f'<circle cx="{x}" cy="430" r="8" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
    s.text(x, 416, n, 13, "middle", "bold", "#333"); st[n] = (x, 430)
for n, y in (("TX", 330), ("RX", 370)):
    s.add(f'<circle cx="820" cy="{y}" r="8" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
    s.text(806, y + 5, n, 13, "end", "bold", "#333"); st[n] = (820, y)
s.add('<path d="M600,300 L600,272" stroke="#212121" stroke-width="6" stroke-linecap="round"/>')
s.text(612, 284, "付属アンテナ（ケースの外へ、SMA）", 12, fill="#666")
# CatM の 5V スイッチ
s.rect(560, 480, 260, 140, "#eceff1", "#90a4ae", 10, 2)
s.text(690, 512, "CatM の 5V スイッチ（基板の上）", 13, "middle", "bold", "#263238")
s.text(690, 532, "P-MOSFET: S＝5V、D＝CatM 5V", 11, "middle", fill="#37474f")
s.text(690, 549, "ゲート → 100kΩ → 5V", 11, "middle", fill="#37474f")
s.text(690, 566, "NPN: B ← 10kΩ ← D4（100kΩ → GND）", 11, "middle", fill="#37474f")
s.text(690, 583, "C → ゲート、E → GND", 11, "middle", fill="#37474f")
s.text(690, 605, "D4 が High で ON", 12, "middle", "bold", "#c62828")
sw = {"IN": (620, 480), "G": (700, 480), "OUT": (760, 480), "CTL": (820, 560), "GND": (690, 620)}
for k, (x, y) in sw.items():
    s.add(f'<circle cx="{x}" cy="{y}" r="8" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
gnd(690, 628)
# 開発ボード
s.rect(940, 120, 240, 640, "#c62828", "#7f0000", 10, 3)
s.rect(996, 106, 120, 40, "#212121", "#111", 4, 2)
s.text(1056, 132, "アンテナ", 12, "middle", "bold", "#eee")
s.text(1060, 450, "Olimex", 16, "middle", "bold", "#fff")
s.text(1060, 472, "ESP32-DevKit-LiPo", 13, "middle", "bold", "#fff")
ol = {}
for n, y in (("D34", 180), ("D36", 230), ("D16", 330), ("D17", 370), ("D4", 560), ("5V", 680), ("GND", 720)):
    s.pad(940, y, n, "L"); ol[n] = (940, y)
s.text(1060, 782, "micro USB は書き込みのときだけ。BAT は使わない", 12, "middle", fill="#666")
# 線
s.wire([us["D+"], ol["D34"]], "white")
s.wire([us["D−"], ol["D36"]], "blue")
s.wire([us["VBUS"], (520, 280), (520, 680), ol["5V"]], "red")
s.wire([(520, 460), (620, 460), sw["IN"]], "red")
s.dot(520, 460, "red")
s.wire([sw["OUT"], st["5V"]], "orange")
s.wire([st["GND"], sw["G"]], "black")
s.wire([us["GND"], (500, 330), (500, 720), ol["GND"]], "black")
s.wire([sw["CTL"], ol["D4"]], "purple")
s.wire([st["TX"], ol["D16"]], "yellow")
s.wire([st["RX"], ol["D17"]], "green")
s.text(640, 820, "センサ（HPX-W・HPX-TH・HPX-F）は、これまでどおり電波（BLE）で受け取ります。ルーターは使いません。", 15, "middle", "bold", "#1565c0")
LTE_WIRES = [
    ["線の色（例）", "どこから", "どこへ", "メモ"],
    ["red", "USB-C の VBUS（5V）", "Olimex 5V と CatM の 5V スイッチ", "HPX-Solar か USB 充電器から"],
    ["black", "USB-C の GND", "Olimex GND・CatM GND・スイッチの GND", "GND は全部共通（図の GND の印）"],
    ["orange", "5V スイッチの出口（P-MOSFET の D）", "M5Stamp CatM 5V", "送るときだけ 5V を入れる"],
    ["purple", "Olimex D4（GPIO4）", "5V スイッチ（10kΩ → NPN のベース）", "High で ON"],
    ["white", "USB-C の D+", "Olimex D34（GPIO34）", "HPX-Solar の電池の電圧（1/2）"],
    ["blue", "USB-C の D−", "Olimex D36（GPIO36）", "HPX-Solar のパネルの電圧（1/6）"],
    ["yellow", "M5Stamp CatM TX", "Olimex D16（GPIO16、受信）", "初めは 115200bps、ファームで 921600bps に"],
    ["green", "M5Stamp CatM RX", "Olimex D17（GPIO17、送信）", ""],
]
y = table(s, 60, 890, LTE_WIRES, [140, 330, 340, 350], "つなぐ線")
LTE_ROWS = [
    ["部品", "数", "使うところ・メモ"],
    ["ESP32 開発ボード Olimex ESP32-DEVKIT-LIPO", "1", "本体（親機）。5V ピンから動かす"],
    ["M5Stamp CatM（SIM7080G）＋付属アンテナ・IPEX-SMA ケーブル", "1", "LTE-M でサーバーへ送る。付属アンテナを使う（技適）"],
    ["LTE-M 対応 SIM（microSIM）", "1", "例: SORACOM plan-D（LTE-M 対応）"],
    ["USB-C メス基板（データ対応）", "1", "ケースの壁に付ける。HPX-Solar から USB-C ケーブルで"],
    ["P-MOSFET・NPN トランジスタ・抵抗 100kΩ ×2・10kΩ ×1", "1式", "CatM の 5V スイッチ"],
    ["電源: HPX-Solar（別の機器）または USB 充電器（5V・1A 以上）", "1", "HPX-Solar のときは電池とパネルの電圧も測れる"],
]
y = table(s, 60, y + 60, LTE_ROWS, [560, 50, 550], "部品の使いどころ")
LTE_NOTES = [
    "・試作中の図です。実機では確かめていません。ファームウェアは HPX-Core v1.24（Wi-Fi・有線LAN の版と同じファイル）。",
    "・動作設定の「電圧の測り方」: HPX-Solar につなぐときは「HPX-Solar」、USB 充電器のときは「測らない」を選ぶ。",
    "・USB 充電器は D+・D− に独自の電圧を出すことがあるので、「測らない」にしないと、でたらめな電圧が出る（壊れはしない見込み）。",
    "・USB-C ケーブルは「データ対応」のものを使う（充電専用のケーブルには D+・D− の線がない）。",
    "・ESP32 は眠る前に D4 を Low にして固定し、CatM の 5V を切る（ファームで行う）。起動のときも 100kΩ で切れたまま。",
    "・M5Stamp CatM の信号の電圧が ESP32 の 3.3V に合うかは未確認。",
    "・線（UART）の速さ: 電源を入れたら AT+IPR=921600 で M5Stamp CatM を速くし、ESP32 も 921600bps にする（ファームで行う、配線は同じ）。",
    "  SIM7080G が 921600bps で使えるかは未確認。取りこぼすときは 460800bps に下げる。",
    "・HPX-CAM の画像: Olimex が 30分ごとに 1〜2分だけ Wi-Fi の親機になり、カメラから受けて LTE-M で送る（その間 BLE は止める。ファームは準備中）。",
    "・アンテナは付属品を使う（技適）。電波の届き具合は各社の LTE-M エリアで確かめる。",
    "・動作モードはディープスリープ（30分ごと）。家庭用電源のときは常時稼働でもよい。基板に組む図は HPX-Core_LTE_pcb.png。",
]
notes(s, 60, y + 20, LTE_NOTES)
s.save("HPX-Core_LTE_olimex_wiring.svg")
_full = open(os.path.join(OUT, "HPX-Core_LTE_olimex_wiring.svg"), encoding="utf-8").read()
_cut = 845
open(os.path.join(OUT, "HPX-Core_LTE_olimex_wiring_fig.svg"), "w", encoding="utf-8").write(
    _full.replace(f'width="{s.w}" height="{s.h}" viewBox="0 0 {s.w} {s.h}"', f'width="{s.w}" height="{_cut}" viewBox="0 0 {s.w} {_cut}"', 1))
json.dump({"tables": [{"head": "つなぐ線", "rows": LTE_WIRES}, {"head": "部品の使いどころ", "rows": LTE_ROWS}],
           "notes": [n.lstrip("・") for n in LTE_NOTES]},
          open(os.path.join(OUT, "HPX-Core_LTE_olimex_wiring.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)
print("ok LTE")
# ------------------------------------------------------------------ HPX-Core ＋LTE-M (TARO 2026-11-09、2026-11-10 電源を HPX-Solar に分け、USB-C ケーブル1本でつなぐ)
# FireBeetle 2 ESP32-E ＋ M5Stamp CatM (SIM7080G)。TARO 2026-11-13 に変更。電源は USB-C 口から (HPX-Solar か USB 充電器)。
# FireBeetle は VCC ピンから。CatM の 5V は基板の P-MOSFET で入り切り (IO4 → 10kΩ → NPN → ゲート)。D+・D− は HPX-Solar の電池・パネルの電圧 (IO39・IO36、v1.25)。
s = S(1280, 1990)
title(s, "HPX-Core（親機）＋LTE-M の線の対応図", "試作中 ／ 実機未確認 ／ FireBeetle 2 ESP32-E ／ ファーム v1.25")
# HPX-Solar (別の機器)
s.rect(50, 110, 230, 170, "#fff3e0", "#e65100", 10, 3)
s.text(165, 150, "HPX-Solar", 18, "middle", "bold", "#e65100")
s.text(165, 174, "（別の機器・別のケース）", 12, "middle", fill="#e65100")
s.text(165, 200, "パネル・DFR0559・18650", 12, "middle", fill="#555")
s.rect(200, 220, 70, 46, "#37474f", "#222", 6, 2)
s.text(235, 248, "USB-C", 12, "middle", "bold", "#fff")
s.text(165, 305, "または USB 充電器（家庭用）", 13, "middle", "bold", "#555")
# USB-C ケーブル
s.add('<path d="M270,243 C330,243 330,243 380,243" stroke="#424242" stroke-width="12" stroke-linecap="round"/>')
s.text(325, 225, "USB-C ケーブル", 12, "middle", "bold", "#333")
s.text(325, 275, "（データ対応）", 11, "middle", fill="#666")
# HPX-Core 側の USB-C メス基板
s.rect(380, 150, 90, 230, "#37474f", "#222", 8, 2)
s.text(425, 400, "USB-C メス基板", 12, "middle", "bold"); s.text(425, 416, "（ケースの壁）", 11, "middle", fill="#666")
us = {}
for i, n in enumerate(["D+", "D−", "VBUS", "GND"]):
    yy = 180 + i * 50
    s.add(f'<circle cx="470" cy="{yy}" r="8" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
    s.text(456, yy + 5, n, 13, "end", "bold", "#fff"); us[n] = (470, yy)
def gnd(x, y):   # GND の印
    s.add(f'<path d="M{x},{y} L{x},{y+14} M{x-12},{y+14} L{x+12},{y+14} M{x-7},{y+20} L{x+7},{y+20} M{x-3},{y+26} L{x+3},{y+26}" stroke="#212121" stroke-width="3" fill="none"/>')
    s.text(x + 16, y + 22, "GND", 11, fill="#333")
# M5Stamp CatM
s.rect(560, 300, 260, 130, "#eceff1", "#90a4ae", 10, 3)
s.text(690, 350, "M5Stamp CatM", 16, "middle", "bold", "#263238")
s.text(690, 372, "SIM7080G（LTE-M）・microSIM", 12, "middle", fill="#455a64")
st = {}
for n, x in (("5V", 760), ("GND", 700)):
    s.add(f'<circle cx="{x}" cy="430" r="8" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
    s.text(x, 416, n, 13, "middle", "bold", "#333"); st[n] = (x, 430)
for n, y in (("TX", 330), ("RX", 370)):
    s.add(f'<circle cx="820" cy="{y}" r="8" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
    s.text(806, y + 5, n, 13, "end", "bold", "#333"); st[n] = (820, y)
s.add('<path d="M600,300 L600,272" stroke="#212121" stroke-width="6" stroke-linecap="round"/>')
s.text(612, 284, "付属アンテナ（ケースの外へ、SMA）", 12, fill="#666")
# CatM の 5V スイッチ
s.rect(560, 480, 260, 140, "#eceff1", "#90a4ae", 10, 2)
s.text(690, 512, "CatM の 5V スイッチ（基板の上）", 13, "middle", "bold", "#263238")
s.text(690, 532, "P-MOSFET: S＝5V、D＝CatM 5V", 11, "middle", fill="#37474f")
s.text(690, 549, "ゲート → 100kΩ → 5V", 11, "middle", fill="#37474f")
s.text(690, 566, "NPN: B ← 10kΩ ← IO4（100kΩ → GND）", 11, "middle", fill="#37474f")
s.text(690, 583, "C → ゲート、E → GND", 11, "middle", fill="#37474f")
s.text(690, 605, "IO4 が High で ON", 12, "middle", "bold", "#c62828")
sw = {"IN": (620, 480), "G": (700, 480), "OUT": (760, 480), "CTL": (820, 560), "GND": (690, 620)}
for k, (x, y) in sw.items():
    s.add(f'<circle cx="{x}" cy="{y}" r="8" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
gnd(690, 628)
# 開発ボード
s.rect(940, 120, 240, 640, "#37474f", "#102027", 10, 3)
s.rect(1016, 106, 80, 40, "#b0b0b0", "#777", 8, 2)
s.text(1056, 132, "USB-C", 12, "middle", "bold", "#eee")
s.text(1060, 450, "FireBeetle 2", 16, "middle", "bold", "#fff")
s.text(1060, 472, "ESP32-E", 13, "middle", "bold", "#fff")
ol = {}
for n, y in (("IO39", 180), ("IO36", 230), ("IO16", 330), ("IO17", 370), ("IO4", 560), ("VCC", 680), ("GND", 720)):
    s.pad(940, y, n, "L"); ol[n] = (940, y)
s.text(1060, 782, "USB-C は書き込みのときだけ。電池コネクタは使わない", 12, "middle", fill="#666")
# 線
s.wire([us["D+"], ol["IO39"]], "white")
s.wire([us["D−"], ol["IO36"]], "blue")
s.wire([us["VBUS"], (520, 280), (520, 680), ol["VCC"]], "red")
s.wire([(520, 460), (620, 460), sw["IN"]], "red")
s.dot(520, 460, "red")
s.wire([sw["OUT"], st["5V"]], "orange")
s.wire([st["GND"], sw["G"]], "black")
s.wire([us["GND"], (500, 330), (500, 720), ol["GND"]], "black")
s.wire([sw["CTL"], ol["IO4"]], "purple")
s.wire([st["TX"], ol["IO16"]], "yellow")
s.wire([st["RX"], ol["IO17"]], "green")
s.text(640, 820, "センサ（HPX-W・HPX-TH・HPX-F）は、これまでどおり電波（BLE）で受け取ります。ルーターは使いません。", 15, "middle", "bold", "#1565c0")
LTE_WIRES = [
    ["線の色（例）", "どこから", "どこへ", "メモ"],
    ["red", "USB-C の VBUS（5V）", "FireBeetle VCC と CatM の 5V スイッチ", "HPX-Solar か USB 充電器から"],
    ["black", "USB-C の GND", "FireBeetle GND・CatM GND・スイッチの GND", "GND は全部共通（図の GND の印）"],
    ["orange", "5V スイッチの出口（P-MOSFET の D）", "M5Stamp CatM 5V", "送るときだけ 5V を入れる"],
    ["purple", "FireBeetle IO4（GPIO4）", "5V スイッチ（10kΩ → NPN のベース）", "High で ON"],
    ["white", "USB-C の D+", "FireBeetle IO39（GPIO39、v1.25）", "HPX-Solar の電池の電圧（1/2）"],
    ["blue", "USB-C の D−", "FireBeetle IO36（GPIO36）", "HPX-Solar のパネルの電圧（1/6）"],
    ["yellow", "M5Stamp CatM TX", "FireBeetle IO16（GPIO16、受信）", "初めは 115200bps、ファームで 921600bps に"],
    ["green", "M5Stamp CatM RX", "FireBeetle IO17（GPIO17、送信）", ""],
]
y = table(s, 60, 890, LTE_WIRES, [140, 330, 340, 350], "つなぐ線")
LTE_ROWS = [
    ["部品", "数", "使うところ・メモ"],
    ["DFRobot FireBeetle 2 ESP32-E（DFR0654）", "1", "本体（親機）。VCC ピンから動かす"],
    ["M5Stamp CatM（SIM7080G）＋付属アンテナ・IPEX-SMA ケーブル", "1", "LTE-M でサーバーへ送る。付属アンテナを使う（技適）"],
    ["LTE-M 対応 SIM（microSIM）", "1", "例: SORACOM plan-D（LTE-M 対応）"],
    ["USB-C メス基板（データ対応）", "1", "ケースの壁に付ける。HPX-Solar から USB-C ケーブルで"],
    ["P-MOSFET・NPN トランジスタ・抵抗 100kΩ ×2・10kΩ ×1", "1式", "CatM の 5V スイッチ"],
    ["電源: HPX-Solar（別の機器）または USB 充電器（5V・1A 以上）", "1", "HPX-Solar のときは電池とパネルの電圧も測れる"],
]
y = table(s, 60, y + 60, LTE_ROWS, [560, 50, 550], "部品の使いどころ")
LTE_NOTES = [
    "・試作中の図です。実機では確かめていません。ファームウェアは HPX-Core v1.25（Wi-Fi・有線LAN の版と同じファイル。HPX-Solar の電池の電圧を IO39 で読むのは v1.25 から）。",
    "・動作設定の「電圧の測り方」: HPX-Solar につなぐときは「HPX-Solar」、USB 充電器のときは「測らない」を選ぶ。",
    "・USB 充電器は D+・D− に独自の電圧を出すことがあるので、「測らない」にしないと、でたらめな電圧が出る（壊れはしない見込み）。",
    "・USB-C ケーブルは「データ対応」のものを使う（充電専用のケーブルには D+・D− の線がない）。",
    "・ESP32 は眠る前に IO4 を Low にして固定し、CatM の 5V を切る（ファームで行う）。起動のときも 100kΩ で切れたまま。",
    "・M5Stamp CatM の信号の電圧が ESP32 の 3.3V に合うかは未確認。",
    "・線（UART）の速さ: 電源を入れたら AT+IPR=921600 で M5Stamp CatM を速くし、ESP32 も 921600bps にする（ファームで行う、配線は同じ）。",
    "  SIM7080G が 921600bps で使えるかは未確認。取りこぼすときは 460800bps に下げる。",
    "・HPX-CAM の画像: FireBeetle が 30分ごとに 1〜2分だけ Wi-Fi の親機になり、カメラから受けて LTE-M で送る（その間 BLE は止める。ファームは準備中）。",
    "・FireBeetle の VCC に 5V を入れると、基板の中のスイッチを通って電池コネクタにも 5V が出る（回路図から）。電池コネクタには何もつながない。",
    "・アンテナは付属品を使う（技適）。電波の届き具合は各社の LTE-M エリアで確かめる。",
    "・動作モードはディープスリープ（30分ごと）。家庭用電源のときは常時稼働でもよい。基板に組む図は HPX-Core_LTE_pcb.png。",
]
notes(s, 60, y + 20, LTE_NOTES)
s.save("HPX-Core_LTE_wiring.svg")
_full = open(os.path.join(OUT, "HPX-Core_LTE_wiring.svg"), encoding="utf-8").read()
_cut = 845
open(os.path.join(OUT, "HPX-Core_LTE_wiring_fig.svg"), "w", encoding="utf-8").write(
    _full.replace(f'width="{s.w}" height="{s.h}" viewBox="0 0 {s.w} {s.h}"', f'width="{s.w}" height="{_cut}" viewBox="0 0 {s.w} {_cut}"', 1))
json.dump({"tables": [{"head": "つなぐ線", "rows": LTE_WIRES}, {"head": "部品の使いどころ", "rows": LTE_ROWS}],
           "notes": [n.lstrip("・") for n in LTE_NOTES]},
          open(os.path.join(OUT, "HPX-Core_LTE_wiring.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)
print("ok LTE")

# ------------------------------------------------------------------ HPX-Solar (小型ソーラー電源、TARO 2026-11-10)
# ＋LTE-M＆ソーラー版の電源の部分 (ソーラーパネル・USB-C メス基板・DFR0559・18650 ×2) を別の機器にした。
# HPX-Core とは USB-C ケーブル1本でつなぐ (案2、TARO 2026-11-10): VBUS=5V、GND、D+=電池の電圧 (1/2)、D−=パネルの電圧 (1/6)。
# 板はタカチ PCB72-95。ケースは前のまま OB10-17-5W (TARO: ケースはあとで考え直す)。
s = S(1280, 2240)
title(s, "HPX-Solar（小型ソーラー電源）の配線図", "試作中 ／ 実機未確認 ／ HPX-Core とは USB-C ケーブル1本")
K = 5.6                      # 1mm = 5.6px
BX, BY = 110, 150            # 板の左上
def mm(x, y): return BX + x * K, BY + y * K
s.text(BX, BY - 30, "PCB72-95 を上から見たところ", 17, weight="bold")
s.rect(BX, BY, 72 * K, 95 * K, "#e8d5a8", "#b08a4a", 4, 3)
for gx in range(27):
    for gy in range(36):
        x, y = mm(2.0 + gx * 2.54 + 1.0, 2.0 + gy * 2.54 + 1.0)
        s.add(f'<circle cx="{x:.1f}" cy="{y:.1f}" r="2.2" fill="#c9a865"/>')
for hx, hy in ((5, 5), (67, 5), (5, 90), (67, 90)):
    x, y = mm(hx, hy)
    s.add(f'<circle cx="{x}" cy="{y}" r="{1.6*K}" fill="#fffdf6" stroke="#8a6d1d" stroke-width="2"/>')
# 入力の USB-C メス基板 (左上。パネルの USB-C を差す)
x, y = mm(9, 2.5)
s.rect(x, y, 16 * K, 16 * K, "#37474f", "#222", 6, 2)
s.rect(x + 4 * K, y - 2 * K, 8 * K, 4 * K, "#b0b0b0", "#666", 4, 1)
s.text(x + 8 * K, y + 8 * K, "USB-C（入力）", 12, "middle", "bold", "#fff")
s.text(x + 8 * K, y + 11 * K, "パネルを差す", 11, "middle", fill="#eceff1")
s.text(x + 8 * K, y - 4 * K, "↓ ソーラーパネルの USB-C", 12, "middle", "bold", "#0d47a1")
# 18650 ホルダー ×2 (縦向き、右)
for i in range(2):
    x, y = mm(30.5 + i * 20.8, 9)
    s.rect(x, y, 20.2 * K, 77 * K, "#37474f", "#212121", 8, 2)
    s.rect(x + 2.2 * K, y + 5 * K, 15.8 * K, 67 * K, "#2e7d32", "#1b5e20", 40, 2)
    s.text(x + 10.1 * K, y + 38 * K, "18650", 14, "middle", "bold", "#fff")
    s.text(x + 10.1 * K, y + 41.5 * K, "ホルダー", 12, "middle", fill="#fff")
    s.text(x + 10.1 * K, y + 9.5 * K, "＋", 18, "middle", "bold", "#ffcdd2")
    s.text(x + 10.1 * K, y + 70 * K, "−", 20, "middle", "bold", "#bbdefb")
# DFR0559 (左、少し板の外へはみ出す。大きさは目安)
x, y = mm(-3, 22)
s.rect(x, y, 32 * K, 50 * K, "#1565c0", "#0d3c7a", 8, 3)
s.text(x + 16 * K, y + 21 * K, "DFR0559", 16, "middle", "bold", "#fff")
s.text(x + 16 * K, y + 25 * K, "ソーラー", 12, "middle", fill="#e3f2fd")
s.text(x + 16 * K, y + 28.5 * K, "パワーマネージャー 5V", 12, "middle", fill="#e3f2fd")
s.text(x + 16 * K, y + 5 * K, "SOLAR・BAT", 11, "middle", "bold", "#fff")
s.text(x + 16 * K, y + 47 * K, "5V・GND", 11, "middle", "bold", "#fff")
# 分圧の抵抗とコンデンサー (DFR0559 の下)
x, y = mm(2, 74)
s.rect(x, y, 26 * K, 13 * K, "#fff8e1", "#c9a227", 6, 2)
s.text(x + 13 * K, y + 5 * K, "抵抗 4本・0.1µF 2個", 12, "middle", "bold")
s.text(x + 13 * K, y + 9.5 * K, "（電池 1/2・パネル 1/6）", 11, "middle", fill="#666")
# 出力の USB-C メス基板はケースの壁に付ける
cx, cy = mm(15, 95)
s.add(f'<path d="M{cx},{cy-30} L{cx},{cy+46}" stroke="#424242" stroke-width="6" stroke-dasharray="8 5"/>')
s.rect(cx - 45, cy + 46, 90, 54, "#37474f", "#222", 6, 2)
s.text(cx, cy + 70, "USB-C（出力）", 12, "middle", "bold", "#fff")
s.text(cx, cy + 88, "ケースの壁", 11, "middle", fill="#eceff1")
s.text(cx + 60, cy + 78, "→ USB-C ケーブル（データ対応）で HPX-Core へ", 13, weight="bold", fill="#e65100")
s.text(BX, BY + 95 * K + 140, "部品は両面テープか M3 ねじで留める。DFR0559 の大きさは目安（実物で確かめる）", 12, fill="#555")
s.text(BX + 72 * K + 16, BY + 47 * K, "95mm", 13, fill="#555")
s.text(BX + 36 * K, BY - 8, "72mm", 13, "middle", fill="#555")

# 右: 線のつながり
RX0 = 640
s.text(RX0, BY - 30, "線のつながり", 17, weight="bold")
s.rect(RX0, BY, 170, 100, "#0d47a1", "#06244f", 6, 3)
s.text(RX0 + 85, BY + 120, "ソーラーパネル 10W/5V", 13, "middle", "bold")
s.rect(RX0 + 230, BY + 20, 70, 60, "#37474f", "#222", 6, 2)
s.text(RX0 + 265, BY + 100, "USB-C", 11, "middle", "bold"); s.text(RX0 + 265, BY + 114, "（入力）", 11, "middle", "bold")
s.add(f'<path d="M{RX0+170},{BY+50} L{RX0+230},{BY+50}" stroke="#424242" stroke-width="8"/>')
s.rect(RX0 + 360, BY, 230, 230, "#1565c0", "#0d3c7a", 10, 3)
s.text(RX0 + 475, BY + 120, "DFR0559", 16, "middle", "bold", "#fff")
for n, yy in (("SOLAR＋", 30), ("SOLAR−", 70), ("BAT＋", 150), ("BAT−", 190)):
    s.pad(RX0 + 360, BY + yy, n, "L")
for n, xx in (("5V", 410), ("GND", 470)):
    s.add(f'<circle cx="{RX0+xx}" cy="{BY+230}" r="8" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
    s.text(RX0 + xx, BY + 214, n, 14, "middle", "bold", "#fff")
s.rect(RX0, BY + 280, 250, 110, "#455a64", "#263238", 10, 3)
for i in range(2):
    s.rect(RX0 + 15, BY + 295 + i * 48, 220, 36, "#2e7d32", "#1b5e20", 18, 2)
    s.text(RX0 + 125, BY + 319 + i * 48, "18650（保護回路付き）", 12, "middle", "bold", "#fff")
s.pad(RX0 + 250, BY + 300, "＋", "R"); s.pad(RX0 + 250, BY + 370, "−", "R")
s.text(RX0, BY + 412, "18650 ×2（並列 約3.7V）", 13, weight="bold")
s.wire([(RX0 + 300, BY + 35), (RX0 + 330, BY + 35), (RX0 + 330, BY + 30), (RX0 + 360, BY + 30)], "red")
s.wire([(RX0 + 300, BY + 65), (RX0 + 340, BY + 65), (RX0 + 340, BY + 70), (RX0 + 360, BY + 70)], "black")
s.wire([(RX0 + 250, BY + 300), (RX0 + 300, BY + 300), (RX0 + 300, BY + 150), (RX0 + 360, BY + 150)], "red")
s.wire([(RX0 + 250, BY + 370), (RX0 + 320, BY + 370), (RX0 + 320, BY + 190), (RX0 + 360, BY + 190)], "black")
# 分圧 (電池 100k/100k、パネル 100k/20k)。下の抵抗と 0.1µF は GND へ
def dv(x, y, top, bot, r):
    s.rect(x, y, 96, 84, "#fff8e1", "#c9a227", 8, 2)
    s.text(x + 48, y + 22, top, 13, "middle", "bold")
    s.text(x + 48, y + 40, "↓（" + r + "）", 12, "middle")
    s.text(x + 48, y + 58, bot, 13, "middle", "bold")
    s.text(x + 48, y + 76, "＋0.1µF→GND", 10, "middle", fill="#666")
dv(RX0 + 290, BY + 440, "100kΩ", "100kΩ", "電池 1/2")
dv(RX0 + 530, BY + 440, "100kΩ", "20kΩ", "パネル 1/6")
# 出力の USB-C（ピンは上の辺に D+・VBUS・GND・D−）
s.rect(RX0 + 300, BY + 600, 300, 90, "#37474f", "#222", 8, 2)
s.text(RX0 + 450, BY + 660, "USB-C（出力）", 13, "middle", "bold", "#fff")
up = {}
for n, xx in (("D+", 338), ("VBUS", 410), ("GND", 470), ("D−", 578)):
    s.add(f'<circle cx="{RX0+xx}" cy="{BY+610}" r="8" fill="#d4af37" stroke="#8a6d1d" stroke-width="2"/>')
    s.text(RX0 + xx, BY + 638, n, 12, "middle", "bold", "#fff"); up[n] = (RX0 + xx, BY + 610)
s.wire([(RX0 + 410, BY + 230), up["VBUS"]], "orange")
s.wire([(RX0 + 470, BY + 230), up["GND"]], "black")
s.wire([(RX0 + 300, BY + 300), (RX0 + 300, BY + 430), (RX0 + 338, BY + 430), (RX0 + 338, BY + 440)], "red")          # 電池＋ → 分圧
s.wire([(RX0 + 338, BY + 524), up["D+"]], "white")
s.wire([(RX0 + 265, BY + 20), (RX0 + 265, BY - 12), (RX0 + 600, BY - 12), (RX0 + 600, BY + 440)], "blue")   # パネル＋（入力 USB-C の VBUS）→ 分圧
s.wire([(RX0 + 578, BY + 524), up["D−"]], "blue")
s.dot(RX0 + 300, BY + 300, "red")
s.text(RX0, BY + 730, "→ USB-C ケーブル（データ対応）で HPX-Core ＋LTE-M・＋防水 の USB-C 口へ", 13, weight="bold", fill="#e65100")

SOLAR_WIRES = [
    ["線の色（例）", "どこから", "どこへ", "メモ"],
    ["red", "入力 USB-C の VBUS（＋）", "DFR0559 SOLAR＋", "パネルの USB-C を切らずに差す"],
    ["black", "入力 USB-C の GND（−）", "DFR0559 SOLAR−", ""],
    ["red", "18650 の＋（2本並列）", "DFR0559 BAT＋", ""],
    ["black", "18650 の−（2本並列）", "DFR0559 BAT−", ""],
    ["orange", "DFR0559 5V（ピン）", "出力 USB-C の VBUS", "HPX-Core の電源"],
    ["black", "DFR0559 GND（ピン）", "出力 USB-C の GND", ""],
    ["white", "18650 の＋ → 100kΩ", "出力 USB-C の D+", "100kΩ と 0.1µF で GND へ（電池の電圧 1/2）"],
    ["blue", "入力 USB-C の VBUS → 100kΩ", "出力 USB-C の D−", "20kΩ と 0.1µF で GND へ（パネルの電圧 1/6）"],
]
y = table(s, 60, 1040, SOLAR_WIRES, [140, 320, 300, 380], "中の線")
OUT_ROWS = [
    ["USB-C の線", "HPX-Solar から", "HPX-Core（＋LTE-M・＋防水）で", "メモ"],
    ["VBUS", "DFR0559 の 5V", "HPX-Core の VCC（＋LTE-M は CatM の 5V スイッチへも）", "5V・1A まで"],
    ["GND", "DFR0559 の GND", "GND", ""],
    ["D+", "電池の電圧（1/2）", "GPIO39（v1.25。v1.24 までは GPIO34）", "動作設定「電圧の測り方」で HPX-Solar を選ぶ"],
    ["D−", "パネルの電圧（1/6）", "GPIO36", ""],
]
y = table(s, 60, y + 60, OUT_ROWS, [120, 260, 420, 340], "HPX-Core への USB-C ケーブル（データ対応のもの）")
OTHER_ROWS = [
    ["つなぐ機器", "使えるか", "メモ"],
    ["HPX-Core ＋LTE-M", "◎ 電圧も測れる", "USB-C 口の D+・D− を読む"],
    ["HPX-Core ＋防水（USB-C 口を足した版）", "◎ 電圧も測れる", "ディープスリープで使う（常時稼働は冬に足りない見込み）"],
    ["HPX-Core 基本モデル・HPX-CAM など", "○ 5V だけ", "電圧は測れない。眠りながら動く機器に向く"],
    ["＋有線LAN・LTE ルーター", "✕", "ずっと電気を使うので、カーバッテリーの方式を使う"],
]
y = table(s, 60, y + 60, OTHER_ROWS, [400, 240, 500], "ほかの機器に使うとき")
SOLAR_ROWS = [
    ["部品", "数", "使うところ・メモ"],
    ["ソーラーパネル 10W/5V（USB-C 出力）", "1", "ケーブルは切らずに入力の USB-C メス基板へ差す"],
    ["USB-C メス基板（電源用、5.1kΩ 付き）", "1", "入力。パネルの USB-C を受けて＋−の線に分ける"],
    ["USB-C メス基板（データ対応、VBUS・GND・D+・D− が出ているもの）", "1", "出力。ケースの壁に付ける"],
    ["DFRobot DFR0559 ソーラーパワーマネージャー 5V", "1", "18650 の充電と 5V の出力"],
    ["18650 リチウムイオン電池（保護回路付き）・電池ホルダー 1本用", "各2", "並列につなぐ"],
    ["抵抗 100kΩ ×3・20kΩ ×1・コンデンサー 0.1µF ×2", "1式", "電池とパネルの電圧を D+・D− へ"],
    ["タカチ PCB72-95・ケース OB10-17-5W・取り付けベース OBP10-17・AST3-6B", "各1", "ケースはあとで考え直す（今は前の版のまま）"],
]
y = table(s, 60, y + 60, SOLAR_ROWS, [600, 60, 480], "部品の使いどころ")
SOLAR_NOTES = [
    "・試作中の図です。実機では確かめていません。",
    "・出力の USB-C は普通の USB 機器（パソコンなど）に差さない（D+・D− に電圧を出すため）。印を付けておく。",
    "・ケーブルは「データ対応」の USB-C ケーブルを使う（充電専用のケーブルには D+・D− の線がない）。",
    "・18650 は保護回路付きを使う。2本は同じ銘柄・同じ残量で並列にする（直列にしない）。",
    "・リチウムイオン電池は 0℃以下で充電すると傷む。電池はケースの中の日陰に置く。",
    "・入力の USB-C メス基板は CC に 5.1kΩ が付いた電源用を使う。付いていないと、パネルが 5V を出さないことがある。",
    "・DFR0559 の EN は使わない（5V はずっと出す）。何もつながないとき 5V が出るかは未確認。出ないときは EN を ON の側へつなぐ。",
    "・DFR0559 の 5V 出力は 1A まで。待機の電流（眠っている間の電気）は未確認なので、届いたらテスターで測る。",
    "・使える電気の目安（実測ではない）: 10W パネルは冬の晴れで 1日 約10〜20Wh、18650 2本で約22Wh。眠りながら動く機器に向く。",
]
notes(s, 60, y + 20, SOLAR_NOTES)
s.save("HPX-Solar_pcb.svg")
_full = open(os.path.join(OUT, "HPX-Solar_pcb.svg"), encoding="utf-8").read()
_cut = 1000
open(os.path.join(OUT, "HPX-Solar_pcb_fig.svg"), "w", encoding="utf-8").write(
    _full.replace(f'width="{s.w}" height="{s.h}" viewBox="0 0 {s.w} {s.h}"', f'width="{s.w}" height="{_cut}" viewBox="0 0 {s.w} {_cut}"', 1))
json.dump({"tables": [{"head": "中の線", "rows": SOLAR_WIRES}, {"head": "HPX-Core への USB-C ケーブル（データ対応のもの）", "rows": OUT_ROWS},
                      {"head": "ほかの機器に使うとき", "rows": OTHER_ROWS}, {"head": "部品の使いどころ", "rows": SOLAR_ROWS}],
           "notes": [n.lstrip("・") for n in SOLAR_NOTES]},
          open(os.path.join(OUT, "HPX-Solar_pcb.json"), "w", encoding="utf-8"), ensure_ascii=False, indent=1)
print("ok Solar")
