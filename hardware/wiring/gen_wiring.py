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

# ------------------------------------------------------------------ HPX-W
s = S(1280, 1240)
title(s, "HPX-W（重量センサ）配線図", "ファーム HPX_WeightNode v1.01 ／ ピン: DT=D0, SCK=D1")
p = xiao(s, 190, 200)
# HX711 (緑のモジュール。MCU側 GND/DT/SCK/VCC、反対側 E+/E-/A-/A+/B-/B+)
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
# ロードセル
lx, ly = 1010, 215
s.rect(lx, ly, 220, 150, "#b0bec5", "#78909c", 6, 3)
s.text(lx + 120, ly + 70, "ロードセル", 16, "middle", "bold", "#263238")
s.text(lx + 120, ly + 92, "SC601（定格120kg）", 13, "middle", fill="#263238")
s.text(lx + 110, ly + 178, "線の色は製品の説明書で確認", 13, "middle", fill="#b23", weight="bold")
for i, (n, c) in enumerate([("E+", "red"), ("E-", "black"), ("A-", "white"), ("A+", "green")]):
    ex, ey = h[n]
    s.wire([(ex, ey), (lx, ey)], c)
# XIAO ⇔ HX711
s.wire([p["GND"], (470, p["GND"][1]), (470, h["GND"][1]), h["GND"]], "black", "GND", (480, h["GND"][1] - 10))
s.wire([p["3V3"], (500, p["3V3"][1]), (500, h["VCC"][1]), h["VCC"]], "red", "3V3→VCC", (510, h["VCC"][1] - 10))
s.wire([p["D0"], (150, p["D0"][1]), (150, 150), (590, 150), (590, h["DT"][1]), h["DT"]], "yellow", "D0→DT", (290, 140))
s.wire([p["D1"], (130, p["D1"][1]), (130, 120), (610, 120), (610, h["SCK"][1]), h["SCK"]], "blue", "D1→SCK", (420, 110))
# 電池
bp, bm = battery(s, 220, 640)
s.wire([bp, (560, bp[1]), (560, 520), (500, 520), (500, h["VCC"][1])], "red", "電池＋→3V3", (568, 600))
s.dot(500, h["VCC"][1], "red")
s.wire([bm, (160, bm[1]), (160, 600), (450, 600), (450, p["GND"][1] + 0), (470, p["GND"][1])], "black", "電池−→GND", (240, 590))
s.dot(470, p["GND"][1], "black")
y = table(s, 60, 820, [
    ["線", "どこから", "どこへ", "役目"],
    ["red", "XIAO 3V3", "HX711 VCC", "HX711 の電源（3V）"],
    ["black", "XIAO GND", "HX711 GND", "マイナス（共通）"],
    ["yellow", "XIAO D0", "HX711 DT", "重さのデータ（HX711→XIAO）"],
    ["blue", "XIAO D1", "HX711 SCK", "読み取りの合図（XIAO→HX711）"],
    ["red", "電池 ＋", "XIAO 3V3", "電池を直接つなぐ"],
    ["black", "電池 −", "XIAO GND", ""],
], [110, 170, 170, 330], "XIAO ⇔ HX711 ⇔ 電池")
table(s, 920, 820, [["線（一般的な色）", "HX711"], ["red", "E+"], ["black", "E-"], ["white", "A-"], ["green", "A+"]],
      [170, 130], "ロードセル ⇔ HX711")
notes(s, 60, 1060, [
    "・USB で書き込む・シリアルを見るときは、必ず電池を外す（3V3 に電池を直結しているため）。",
    "・ロードセルの線の色はメーカーで違うことがある。SC601 の色の割り当ては未確認なので説明書で確かめる。",
    "　重さを載せて値が減る場合は A+ と A- を入れ替えれば直る（故障ではない）。",
    "・HX711 に VCC と VDD が別にある基板は、両方を 3V3 につなぐ。",
])
s.save("HPX-W_wiring.svg")

# ------------------------------------------------------------------ HPX-A
s = S(1280, 1220)
title(s, "HPX-A（電柵アラート）配線図", "ファーム HPX_Alert v1.02 ／ 光センサ: D1→抵抗→D0（A0）")
p = xiao(s, 190, 200)
# ブレッドボード上の 10kΩ とフォトトランジスタ
bx, by = 560, 170
s.rect(bx, by, 330, 360, "#f1efe6", "#cfc8b0", 10, 2)
for gx in range(14):
    for gy in range(14):
        s.add(f'<rect x="{bx+20+gx*22}" y="{by+20+gy*24}" width="6" height="6" fill="#bdb6a0"/>')
s.text(bx + 330, by - 10, "ブレッドボード（はんだ付けでも可）", 14, "end", fill="#666")
# 抵抗 (縦向き)
rx_, r1, r2 = bx + 110, by + 60, by + 200
s.add(f'<line x1="{rx_}" y1="{r1}" x2="{rx_}" y2="{r2}" stroke="#888" stroke-width="3"/>')
s.rect(rx_ - 13, r1 + 30, 26, 80, "#e8c88f", "#a07c3c", 10, 2)
for i, c in enumerate(["#8d5524", "#212121", "#f57c00", "#d4af37"]):   # 茶黒橙金 = 10kΩ
    s.rect(rx_ - 13, r1 + 40 + i * 16, 26, 7, c, rx=0)
s.rect(rx_ + 18, r1 + 52, 150, 70, "#fffdf6dd", rx=6)
s.text(rx_ + 26, r1 + 75, "抵抗 10kΩ", 15, weight="bold")
s.text(rx_ + 26, r1 + 95, "（茶 黒 橙 金）", 13, fill="#666")
s.text(rx_ + 26, r1 + 113, "向きはどちらでもよい", 13, fill="#666")
# フォトトランジスタ
px_, py_ = bx + 230, by + 250
s.add(f'<circle cx="{px_}" cy="{py_}" r="26" fill="#cfe8ff" stroke="#5a8ab0" stroke-width="3" opacity=".9"/>')
s.add(f'<line x1="{px_-10}" y1="{py_+26}" x2="{px_-10}" y2="{py_+80}" stroke="#888" stroke-width="3"/>')
s.add(f'<line x1="{px_+10}" y1="{py_+26}" x2="{px_+10}" y2="{py_+70}" stroke="#888" stroke-width="3"/>')
s.rect(px_ + 30, py_ - 22, 200, 46, "#fffdf6dd", rx=6)
s.text(px_ + 36, py_ - 4, "フォトトランジスタ", 15, weight="bold")
s.text(px_ + 36, py_ + 16, "（TPS601A・PT204-6B など）", 13, fill="#666")
s.top.append('<rect x="%d" y="%d" width="22" height="22" rx="4" fill="#fffdf6"/>' % (px_ - 40, py_ + 38))
s.text(px_ - 29, py_ + 55, "C", 15, "middle", "bold", "#1565c0", layer=s.top)
s.top.append('<rect x="%d" y="%d" width="22" height="22" rx="4" fill="#fffdf6"/>' % (px_ + 18, py_ + 38))
s.text(px_ + 29, py_ + 55, "E", 15, "middle", "bold", "#1565c0", layer=s.top)
C = (px_ - 10, py_ + 80); E = (px_ + 10, py_ + 70)
# 配線
s.wire([p["D1"], (140, p["D1"][1]), (140, 130), (rx_, 130), (rx_, r1)], "purple", "D1→抵抗の片方", (300, 120))
s.wire([p["D0"], (160, p["D0"][1]), (160, 150), (520, 150), (520, r2 + 30), (rx_, r2 + 30), (rx_, r2)], "yellow", "D0", (530, 290))
s.dot(rx_, r2 + 30, "yellow")
s.wire([(rx_, r2 + 30), (rx_, C[1] + 40), (C[0], C[1] + 40), C], "yellow", "D0→コレクタ C", (rx_ + 10, C[1] + 66))
s.wire([p["GND"], (470, p["GND"][1]), (470, 590), (E[0] + 40, 590), (E[0] + 40, E[1]), E], "black", "GND→エミッタ E", (480, 584))
bp, bm = battery(s, 220, 640)
s.wire([bp, (540, bp[1]), (540, 470), (440, 470), (440, p["3V3"][1]), p["3V3"]], "red", "電池＋→3V3", (548, 640))
s.wire([bm, (160, bm[1]), (160, 610), (470, 610), (470, 590)], "black", "電池−→GND", (240, 600))
s.dot(470, 590, "black")
table(s, 60, 820, [
    ["線", "どこから", "どこへ", "役目"],
    ["purple", "XIAO D1", "抵抗 10kΩ の片方", "読む瞬間だけ光センサに電気を流す"],
    ["yellow", "抵抗のもう片方", "XIAO D0（A0）", "光の強さを電圧で読む"],
    ["yellow", "同じ点", "フォトトランジスタ C", "（D0 と同じ線）"],
    ["black", "XIAO GND", "フォトトランジスタ E", "マイナス"],
    ["red", "電池 ＋（単3リチウム×2 か CR123A）", "XIAO 3V3", "電池を直接つなぐ"],
    ["black", "電池 −", "XIAO GND", ""],
], [110, 330, 230, 330], "つなぐ線")
notes(s, 60, 1062, [
    "・フェンスアラート（StaFIX）の回路と XIAO を電線でつながない。光だけで読む（柵線の高電圧が回り込むため）。",
    "・C（コレクタ）と E（エミッタ）の見分け方は部品ごとに違う。データシートで確認する（逆だと反応しないだけで壊れにくい）。",
    "・センサは赤いカバーに向けて貼り、カバーごと黒いもので覆って日光をさえぎる。USB 接続時は電池を外す。",
])
s.save("HPX-A_wiring.svg")

# ------------------------------------------------------------------ HPX-Core
s = S(1280, 760)
title(s, "HPX-Core（親機）配線図", "ファーム HPX_Core v1.15 ／ Freenove ESP32（ESP32-WROOM-32E）")
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
s.text(cx + 120, cy - 30, "Freenove ESP32 ボード", 15, "middle", "bold")
s.text(cx + 120, cy - 10, "（図はイメージ。ボタンの位置は実物で確認）", 12, "middle", fill="#666")
# USB アダプタ
s.rect(cx + 60, 680 - 40, 120, 70, "#eeeeee", "#999", 10, 2)
s.text(cx + 120, 680 - 0, "USB 電源", 15, "middle", "bold")
s.text(cx + 120, 680 + 20, "（5V・コンセント）", 12, "middle", fill="#666")
s.add(f'<path d="M{cx+120},{cy+415} L{cx+120},{640}" stroke="#424242" stroke-width="9" stroke-linecap="round"/>')
s.text(cx + 135, 610, "USB ケーブル", 14, fill="#333")
# 無線の説明
for i, (n, d) in enumerate([("HPX-W", "重量（BLE）"), ("HPX-TH / Inkbird IBS-TH2", "温湿度（BLE）"), ("HPX-A", "電柵アラート（BLE）")]):
    yy = 210 + i * 110
    s.rect(70, yy, 300, 70, "#ffffff", "#c9c2a8", 10, 2)
    s.text(90, yy + 30, n, 16, weight="bold"); s.text(90, yy + 54, d, 14, fill="#666")
    s.add(f'<path d="M380,{yy+35} L{cx-20},{cy+150}" stroke="#1e88e5" stroke-width="2.5" stroke-dasharray="8 7" fill="none"/>')
s.text(220, 190, "電線なし（電波で受信）", 15, "middle", "bold", "#1565c0")
s.rect(830, 270, 360, 84, "#ffffff", "#c9c2a8", 10, 2)
s.text(850, 295, "Wi-Fi ルーター → サーバー", 16, weight="bold")
s.text(850, 322, "Wi-Fi で送る（電線なし）", 14, fill="#666")
s.add(f'<path d="M{cx+250},{cy+150} L825,320" stroke="#43a047" stroke-width="2.5" stroke-dasharray="8 7" fill="none"/>')
s.rect(830, 420, 360, 210, "#fff3cd", "#e0b84a", 10, 2)
for i, l in enumerate(["HPX-Core には外付けの部品も", "配線もありません。", "USB 電源をつなぐだけで動きます。", "", "BOOT：工場出荷リセットに使う", "（RESET を押して離し、3秒以内に", "　BOOT を5秒長押し）"]):
    s.text(850, 455 + i * 24, l, 15, weight="bold" if i < 3 else "normal", fill="#4a3a00")
s.save("HPX-Core_wiring.svg")
print("ok")
