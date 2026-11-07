# -*- coding: utf-8 -*-
"""
荒启科幻 Logo 资源处理
- 去白底 -> 透明 PNG（同时输出黑色版 / 白色版）
- 自动裁切留白
- 生成 favicon 全套（深空蓝底 + 白色标识）
"""
import os
from PIL import Image, ImageFilter, ImageOps

SRC = r"C:\Users\Administrator\Desktop\荒启\AI"
OUT = r"D:\AITEST\huangqi-scifi\assets\logo"
BACKGROUND = (11, 16, 38, 255)  # #0B1026 深空蓝


def to_alpha(lum_img, lo=225.0, hi=95.0):
    """白底黑图 -> alpha 通道（白透明、黑不透明），lo/hi 之间线性过渡"""
    px = lum_img.load()
    w, h = lum_img.size
    out = Image.new("L", (w, h))
    po = out.load()
    span = lo - hi
    for y in range(h):
        for x in range(w):
            v = px[x, y]
            if v >= lo:
                a = 0
            elif v <= hi:
                a = 255
            else:
                a = int((lo - v) / span * 255)
            po[x, y] = a
    return out


def extract(path, color, out_name, target_w, blur=0.4, trim_pad=6):
    im = Image.open(path).convert("RGB")
    lum = im.convert("L")
    a = to_alpha(lum)
    # 去噪 + 轻微羽化，消除 JPEG 压缩造成的边缘毛刺
    a = a.filter(ImageFilter.MedianFilter(3))
    a = a.filter(ImageFilter.GaussianBlur(blur))

    solid = Image.new("RGBA", im.size, color + (255,))
    solid.putalpha(a)

    bbox = a.point(lambda v: 255 if v > 20 else 0).getbbox()
    if bbox:
        l, t, r, b = bbox
        l = max(0, l - trim_pad); t = max(0, t - trim_pad)
        r = min(im.width, r + trim_pad); b = min(im.height, b + trim_pad)
        solid = solid.crop((l, t, r, b))

    ratio = target_w / solid.width
    solid = solid.resize((target_w, max(1, round(solid.height * ratio))), Image.LANCZOS)
    dst = os.path.join(OUT, out_name)
    solid.save(dst, "PNG", optimize=True)
    print(f"  {out_name:34s} {solid.size[0]}x{solid.size[1]}  {os.path.getsize(dst)/1024:.1f} KB")
    return solid


def build_favicon(mark_white):
    """深空蓝圆角底 + 白色标识"""
    for size, name in ((512, "favicon-512.png"), (180, "apple-touch-icon.png"), (32, "favicon-32.png")):
        canvas = Image.new("RGBA", (size, size), (0, 0, 0, 0))
        bg = Image.new("RGBA", (size, size), BACKGROUND)
        canvas.alpha_composite(bg)
        m = mark_white.copy()
        m.thumbnail((int(size * 0.72), int(size * 0.72)), Image.LANCZOS)
        canvas.alpha_composite(m, ((size - m.width) // 2, (size - m.height) // 2))
        canvas.save(os.path.join(OUT, name), "PNG", optimize=True)
        print(f"  {name:34s} {size}x{size}  {os.path.getsize(os.path.join(OUT, name))/1024:.1f} KB")

    # 多尺寸 ico
    ico_src = Image.open(os.path.join(OUT, "favicon-512.png"))
    ico_src.save(os.path.join(OUT, "favicon.ico"), sizes=[(16, 16), (24, 24), (32, 32), (48, 48), (64, 64)])
    print(f"  {'favicon.ico':34s} multi-size")


if __name__ == "__main__":
    os.makedirs(OUT, exist_ok=True)
    WHITE = (255, 255, 255)
    BLACK = (10, 10, 14)

    print("[1] 带文字 LOGO")
    extract(os.path.join(SRC, "logo带文字.jpg"), WHITE, "logo-full-white.png", 880)
    extract(os.path.join(SRC, "logo带文字.jpg"), BLACK, "logo-full-dark.png", 880)

    print("[2] 纯图形 LOGO")
    mark_w = extract(os.path.join(SRC, "纯logo.jpg"), WHITE, "logo-mark-white.png", 560)
    extract(os.path.join(SRC, "纯logo.jpg"), BLACK, "logo-mark-dark.png", 560)

    print("[3] favicon")
    build_favicon(mark_w)
    print("完成 ->", OUT)
