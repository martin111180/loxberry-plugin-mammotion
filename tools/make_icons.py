#!/usr/bin/env python3
"""Erzeugt die Plugin-Icons (64/128/256/512 px) nach plugin/icons. Benötigt Pillow.

Verwendung:  python tools/make_icons.py plugin/icons
"""
import sys, math
from PIL import Image, ImageDraw, ImageFilter
out = sys.argv[1]
S = 2048  # Supersampling, danach herunterskaliert
u = S / 64
img = Image.new("RGBA", (S, S), (0, 0, 0, 0))
d = ImageDraw.Draw(img)
def P(*pts): return [(x*u, y*u) for x, y in pts]
def box(x0, y0, x1, y1): return [x0*u, y0*u, x1*u, y1*u]

# Hintergrund: Himmel-Verlauf oben, Rasen unten, abgerundet
bg = Image.new("RGBA", (S, S))
bd = ImageDraw.Draw(bg)
for y in range(S):
    t = y / S
    if t < 0.70:
        k = t / 0.70
        c = (int(120 + 70*k), int(190 + 35*k), int(235 - 10*k))
    else:
        k = (t - 0.70) / 0.30
        c = (int(76 - 30*k), int(160 - 45*k), int(60 - 20*k))
    bd.line([(0, y), (S, y)], fill=c + (255,))
mask = Image.new("L", (S, S), 0)
ImageDraw.Draw(mask).rounded_rectangle([0, 0, S-1, S-1], radius=int(13*u), fill=255)
img.paste(bg, (0, 0), mask)
d = ImageDraw.Draw(img)

# Schatten
sh = Image.new("RGBA", (S, S), (0, 0, 0, 0))
ImageDraw.Draw(sh).ellipse(box(4, 49.5, 60, 55.5), fill=(0, 0, 0, 110))
img.alpha_composite(sh.filter(ImageFilter.GaussianBlur(1.2*u)))
d = ImageDraw.Draw(img)

# Fahrgestell (dunkelgrau)
d.rounded_rectangle(box(7, 35, 57, 43), radius=3*u, fill=(52, 56, 62))

# Oberschale: hell, vorne (rechts) abgeschrägt, hinten gerundet
shell = P((7, 38), (7, 33), (10, 28.5), (17, 26.5), (40, 26.2), (49, 27.5), (55.5, 32), (57.5, 36), (57.5, 38))
d.polygon(shell, fill=(236, 239, 242))
# Lichtkante oben
d.line(P((10.5, 28.8), (17, 27.2), (40, 26.9), (48.5, 28.2)), fill=(255, 255, 255), width=int(0.9*u))
# Trennfuge Schale / Seitenteil
d.line(P((8, 33.2), (56, 33.2)), fill=(176, 182, 190), width=int(0.6*u))
# Sensorfenster vorne
d.polygon(P((48.5, 28.3), (54, 31.2), (55.2, 33.2), (48.5, 33.2), (47.2, 30)), fill=(30, 34, 40))
d.ellipse(box(49.6, 30.1, 51.6, 32.1), fill=(70, 150, 230))
# Akzentstreifen (Mammotion-typisches Orange)
d.line(P((9, 35.3), (56, 35.3)), fill=(245, 130, 32), width=int(1.0*u))
# Bedienfeld oben
d.rounded_rectangle(box(25, 25.0, 35, 26.6), radius=0.8*u, fill=(200, 205, 212))

# Räder: groß, Stollenprofil
def wheel(cx, cy, r):
    d.ellipse(box(cx-r, cy-r, cx+r, cy+r), fill=(28, 30, 34))
    n = 14
    for i in range(n):
        a0 = 2*math.pi*i/n; a1 = a0 + math.pi/n*0.9
        pts = [(cx + r*math.cos(a0), cy + r*math.sin(a0)),
               (cx + (r+1.1)*math.cos((a0+a1)/2 - 0.08), cy + (r+1.1)*math.sin((a0+a1)/2 - 0.08)),
               (cx + (r+1.1)*math.cos((a0+a1)/2 + 0.08), cy + (r+1.1)*math.sin((a0+a1)/2 + 0.08)),
               (cx + r*math.cos(a1), cy + r*math.sin(a1))]
        d.polygon(P(*pts), fill=(28, 30, 34))
    d.ellipse(box(cx-r*0.58, cy-r*0.58, cx+r*0.58, cy+r*0.58), fill=(88, 94, 102))
    d.ellipse(box(cx-r*0.42, cy-r*0.42, cx+r*0.42, cy+r*0.42), fill=(130, 137, 146))
    for i in range(5):
        a = 2*math.pi*i/5 - math.pi/2
        d.line(P((cx, cy), (cx + r*0.5*math.cos(a), cy + r*0.5*math.sin(a))), fill=(88, 94, 102), width=int(0.8*u))
    d.ellipse(box(cx-r*0.14, cy-r*0.14, cx+r*0.14, cy+r*0.14), fill=(60, 64, 70))
wheel(16.5, 44, 8.2)
wheel(47.5, 44, 8.2)

# Grashalme im Vordergrund
import random
random.seed(4)
for i in range(46):
    x = 1 + i * 1.4 + random.uniform(-0.3, 0.3)
    h = random.uniform(3.5, 6.5)
    col = random.choice([(96, 178, 70), (70, 150, 55), (120, 195, 85)])
    d.polygon(P((x-0.6, 64), (x + random.uniform(-0.8, 0.8), 64-h), (x+0.6, 64)), fill=col + (255,))
# Gras außerhalb der Rundung abschneiden
alpha = img.getchannel("A")
from PIL import ImageChops
img.putalpha(ImageChops.multiply(alpha, mask))

for s in (64, 128, 256, 512):
    img.resize((s, s), Image.LANCZOS).save(f"{out}/icon_{s}.png", optimize=True)
print("ok")
