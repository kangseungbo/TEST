"""기능 화면 캡처 자리에 들어갈 와이어프레임 이미지 생성 (실제 캡처로 교체용)."""
import os
from PIL import Image, ImageDraw

W, H = 1600, 900
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), "placeholders")
BG, BAR, BLOCK, LINE, ACC = "#F4F6FA", "#E3E8F0", "#D5DCE7", "#C4CDDB", "#9FD8D2"


def base():
    im = Image.new("RGB", (W, H), BG)
    d = ImageDraw.Draw(im)
    d.rectangle([0, 0, W, 70], fill="#FFFFFF")          # 상단 GNB
    d.rounded_rectangle([40, 22, 200, 48], 8, fill=ACC)  # 로고
    for i in range(5):
        d.rounded_rectangle([300 + i * 150, 28, 400 + i * 150, 42], 6, fill=BAR)
    d.ellipse([W - 80, 18, W - 46, 52], fill=BLOCK)
    return im, d


def lines(d, x, y, w, n, gap=34, h=14):
    for i in range(n):
        d.rounded_rectangle([x, y + i * gap, x + w * (0.95 - 0.12 * (i % 3)), y + i * gap + h], 6, fill=LINE)


def card(d, box, r=16):
    d.rounded_rectangle(box, r, fill="#FFFFFF")


def dashboard():
    im, d = base()
    d.rectangle([0, 70, 240, H], fill="#FFFFFF")
    lines(d, 40, 120, 160, 8, gap=60)
    for i in range(4):
        x = 280 + i * 325
        card(d, [x, 110, x + 300, 250])
        d.rounded_rectangle([x + 24, 140, x + 120, 158], 6, fill=LINE)
        d.rounded_rectangle([x + 24, 180, x + 200, 225], 8, fill=ACC if i == 0 else BLOCK)
    card(d, [280, 280, 1060, 860])
    pts = [(320 + i * 70, 760 - (i * 37 % 300) - i * 18) for i in range(11)]
    d.line(pts, fill="#2EC4B6", width=8, joint="curve")
    card(d, [1090, 280, 1560, 860])
    lines(d, 1120, 320, 400, 14)
    return im


def search():
    im, d = base()
    d.rounded_rectangle([300, 140, 1300, 220], 40, fill="#FFFFFF", outline=ACC, width=4)
    d.ellipse([1220, 155, 1270, 205], fill=ACC)
    for i in range(5):
        y = 270 + i * 120
        card(d, [300, y, 1300, y + 100])
        d.rounded_rectangle([330, y + 22, 700, y + 40], 6, fill="#7FB8E6")
        lines(d, 330, y + 56, 900, 1)
    return im


def board():
    im, d = base()
    d.rounded_rectangle([80, 110, 400, 140], 8, fill=BLOCK)
    card(d, [80, 170, 1520, 860])
    d.rectangle([80, 170, 1520, 230], fill=BAR)
    for i in range(10):
        y = 260 + i * 58
        d.rounded_rectangle([120, y, 180, y + 14], 6, fill=ACC if i < 2 else LINE)
        d.rounded_rectangle([220, y, 220 + 700 - (i * 53 % 300), y + 14], 6, fill=LINE)
        d.rounded_rectangle([1200, y, 1300, y + 14], 6, fill=LINE)
        d.rounded_rectangle([1360, y, 1470, y + 14], 6, fill=LINE)
    return im


def calendar():
    im, d = base()
    card(d, [80, 110, 1520, 860])
    d.rounded_rectangle([120, 140, 420, 170], 8, fill=BLOCK)
    cw, ch = (1400 - 40) / 7, 105
    for r in range(6):
        for c in range(7):
            x, y = 120 + c * cw, 200 + r * ch
            d.rectangle([x, y, x + cw, y + ch], outline=BAR, width=2)
            if (r * 7 + c) % 5 == 2:
                d.rounded_rectangle([x + 10, y + 40, x + cw - 20, y + 62], 6, fill=ACC)
            if (r * 7 + c) % 7 == 4:
                d.rounded_rectangle([x + 10, y + 70, x + cw - 40, y + 92], 6, fill="#F6C177")
    return im


def approval():
    im, d = base()
    card(d, [80, 110, 1000, 860])
    lines(d, 120, 160, 800, 3, gap=50, h=20)
    d.rectangle([120, 330, 960, 820], outline=BAR, width=3)
    lines(d, 150, 360, 780, 12)
    card(d, [1040, 110, 1520, 860])
    for i in range(4):
        y = 160 + i * 170
        d.ellipse([1080, y, 1160, y + 80], fill=ACC if i < 2 else BLOCK)
        d.rounded_rectangle([1190, y + 20, 1460, y + 38], 6, fill=LINE)
        d.rounded_rectangle([1190, y + 52, 1360, y + 66], 6, fill=BAR)
    return im


def report():
    im, d = base()
    card(d, [80, 110, 1000, 860])
    for i in range(9):
        x = 140 + i * 95
        hgt = 120 + (i * 97 % 420)
        d.rounded_rectangle([x, 800 - hgt, x + 60, 800], 8, fill="#2EC4B6" if i % 3 == 0 else "#9FB7D6")
    card(d, [1040, 110, 1520, 480])
    d.pieslice([1160, 150, 1400, 390], 0, 220, fill="#2EC4B6")
    d.pieslice([1160, 150, 1400, 390], 220, 300, fill="#F6C177")
    d.pieslice([1160, 150, 1400, 390], 300, 360, fill="#9FB7D6")
    card(d, [1040, 510, 1520, 860])
    lines(d, 1080, 550, 400, 8)
    return im


def mypage():
    im, d = base()
    card(d, [80, 110, 500, 860])
    d.ellipse([190, 160, 390, 360], fill=BLOCK)
    lines(d, 140, 400, 300, 8, gap=50)
    for i in range(3):
        y = 110 + i * 255
        card(d, [540, y, 1520, y + 230])
        d.rounded_rectangle([580, y + 30, 820, y + 50], 6, fill=ACC)
        lines(d, 580, y + 80, 880, 4)
    return im


def mobile():
    im = Image.new("RGB", (W, H), BG)
    d = ImageDraw.Draw(im)
    for i, x in enumerate([420, 940]):
        d.rounded_rectangle([x, 60, x + 380, 860], 50, fill="#1F2A3C")
        d.rounded_rectangle([x + 20, 90, x + 360, 830], 36, fill="#FFFFFF")
        d.rounded_rectangle([x + 50, 130, x + 200, 160], 8, fill=ACC)
        for j in range(5):
            y = 200 + j * 120
            d.rounded_rectangle([x + 50, y, x + 330, y + 95], 16, fill=BG)
            d.rounded_rectangle([x + 70, y + 25, x + 260 - j * 20, y + 40], 6, fill=LINE)
    return im


def notice():
    im, d = base()
    card(d, [80, 110, 560, 860])
    for i in range(7):
        y = 140 + i * 100
        d.ellipse([110, y, 170, y + 60], fill=ACC if i == 0 else BLOCK)
        d.rounded_rectangle([190, y + 12, 500 - i * 15, y + 26], 6, fill=LINE)
        d.rounded_rectangle([190, y + 38, 420, y + 50], 6, fill=BAR)
    card(d, [600, 110, 1520, 860])
    for i in range(6):
        y = 150 + i * 110
        if i % 2:
            d.rounded_rectangle([1100, y, 1480, y + 70], 22, fill=ACC)
        else:
            d.rounded_rectangle([640, y, 1060, y + 70], 22, fill=BAR)
    return im


GEN = dict(notice=notice, main=dashboard, search=search, board=board, calendar=calendar,
           approval=approval, report=report, mypage=mypage, mobile=mobile)

if __name__ == "__main__":
    os.makedirs(OUT, exist_ok=True)
    for name, fn in GEN.items():
        fn().save(os.path.join(OUT, f"{name}.png"))
    print("ok", sorted(os.listdir(OUT)))
