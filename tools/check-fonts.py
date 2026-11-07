#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
荒启科幻官网 · 字体字形覆盖检查（只读，不改任何文件）

为什么需要
    站点的中文字体是子集化后的 woff2，只含 subset-fonts.py 扫描当时出现过的字。
    之后任何一次改稿只要引入一个新汉字，浏览器就会静默回退到系统字体
    （微软雅黑 / 苹方）—— 同一行里几个字粗一点、方一点，字重和字形都断了，
    而且不会报任何错误。这类问题只能靠逐字比对 cmap 发现。

检查对象
    · 站点根目录的 6 个构建产物 HTML
    · js/ 下的脚本（作品卡、期次表等由 JS 写入的文案）
    · css/ 下的样式（伪元素 content 里的中文）

用法
    python tools/check-fonts.py
    退出码 0 = 全部命中；1 = 有缺字（会把缺的字与所在位置打出来）
"""

import io
import os
import re
import sys

from fontTools.ttLib import TTFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FONT = os.path.join(ROOT, "assets", "fonts", "noto-sans-sc-400.woff2")

SCAN_DIRS = [("js", (".js",)), ("css", (".css",))]


def site_chars():
    """汇总待检查字符：{字符: 首次出现位置}"""
    where = {}

    def feed(path, label):
        try:
            with io.open(path, "r", encoding="utf-8") as fh:
                text = fh.read()
        except (UnicodeDecodeError, OSError):
            return
        # 注释永远不会渲染，却会把字带进字符集（改稿时最容易被它误报）。
        # 只剔除注释，CSS 的 content: '…' 是真实文案，必须保留。
        if path.endswith(".css"):
            text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
        if path.endswith(".html"):
            text = re.sub(r"<!--.*?-->", "", text, flags=re.S)
        for ch in text:
            if ch.isprintable() and ch not in "\u200b\u200c\u200d\ufeff":
                where.setdefault(ch, label)

    for fn in sorted(os.listdir(ROOT)):
        if fn.endswith(".html"):
            feed(os.path.join(ROOT, fn), fn)

    for rel, exts in SCAN_DIRS:
        base = os.path.join(ROOT, rel)
        if not os.path.isdir(base):
            continue
        for fn in sorted(os.listdir(base)):
            if fn.endswith(exts):
                feed(os.path.join(base, fn), "{}/{}".format(rel, fn))

    return where


def main():
    if not os.path.exists(FONT):
        print("找不到字体文件：{}".format(FONT))
        return 1

    cmap = set(TTFont(FONT).getBestCmap().keys())
    where = site_chars()

    missing = [(ch, src) for ch, src in sorted(where.items()) if ord(ch) not in cmap]
    print("字体码位：{}　站点字符：{}".format(len(cmap), len(where)))

    if not missing:
        print("字形覆盖完整，没有回退风险。")
        return 0

    print("缺字 {} 个（这些字会以系统字体渲染）：".format(len(missing)))
    for ch, src in missing:
        print("  {}  U+{:04X}  {}".format(ch, ord(ch), src))
    print("\n修复：python tools/subset-fonts.py 重新子集化后重新构建。")
    return 1


if __name__ == "__main__":
    sys.exit(main())
