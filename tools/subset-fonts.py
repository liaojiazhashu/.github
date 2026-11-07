#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
荒启科幻官网 · 字体子集化

作用
    扫描站点中所有会渲染到屏幕的文本来源（HTML / CSS / JS / 规划文档），
    汇总去重后得到一个字符集，再用 pyftsubset 把 Noto Sans SC 裁成只含这些字符的
    woff2 文件，输出到 assets/fonts/。

为什么需要重新运行
    只要页面文案新增了字符集之外的汉字，就需要重新执行本脚本，否则新字会以
    系统回退字体渲染，破坏字重一致性。

用法
    python tools/subset-fonts.py

依赖
    fontTools（含 pyftsubset）、brotli
"""

import io
import os
import re
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SRC_FONTS = os.path.join(ROOT, ".build", "fonts")
OUT_FONTS = os.path.join(ROOT, "assets", "fonts")

# 输入 OTF -> 输出 woff2 文件名
JOBS = [
    ("NotoSansSC-Regular.otf", "noto-sans-sc-400.woff2"),
    ("NotoSansSC-Medium.otf", "noto-sans-sc-500.woff2"),
    ("NotoSansSC-Bold.otf", "noto-sans-sc-700.woff2"),
]

# 扫描的文本来源（相对 ROOT）
SOURCES = [".build/pages", ".", "css", "js", "docs"]
TEXT_EXT = (".html", ".css", ".js", ".md")

# 兜底字符：ASCII 可打印区、常用中英文标点、数学与箭头符号、货币符号
SAFETY = (
    "".join(chr(c) for c in range(0x20, 0x7F))
    + "　、。〈〉《》「」『』【】〔〕・ー—–…‥‘’“”＂＇（）［］｛｝＜＞"
    + "，．：；！？％＆＊＋－／＝＠＃｜＼＾＿～￥＄€£¥·°±×÷≈≠≤≥∞√∑∏∫"
    + "←↑→↓↔↕⇄⇒⇔△▽◇◆○●□■☆★▼▲►◄§¶†‡•‰‱№"
    + "①②③④⑤⑥⑦⑧⑨⑩"
)


def collect_chars():
    chars = set(SAFETY)
    seen_files = 0

    for rel in SOURCES:
        base = os.path.join(ROOT, rel)
        if not os.path.exists(base):
            continue

        if os.path.isfile(base):
            walk = [base]
        else:
            walk = []
            for dirpath, dirnames, filenames in os.walk(base):
                # 跳过构建中间件中体量巨大且不直接渲染的文件
                dirnames[:] = [d for d in dirnames if d not in ("node_modules", ".git")]
                for fn in filenames:
                    walk.append(os.path.join(dirpath, fn))

        for path in walk:
            if not path.endswith(TEXT_EXT):
                continue
            # 站点根目录只取顶层 HTML，避免重复读取 .build 下的主体文件
            if rel == "." and os.sep in os.path.relpath(path, ROOT):
                continue
            try:
                with io.open(path, "r", encoding="utf-8") as fh:
                    chars.update(fh.read())
            except (UnicodeDecodeError, OSError):
                continue
            seen_files += 1

    # 去掉控制字符与零宽字符
    chars = {c for c in chars if c.isprintable() and c not in "\u200b\u200c\u200d\ufeff"}
    return chars, seen_files


def subset(src, dst, charset_file):
    # 直接用 python -m 方式调用，避免依赖 Scripts 目录出现在 PATH 中
    cmd = [
        sys.executable,
        "-m",
        "fontTools.subset",
        src,
        "--text-file=" + charset_file,
        "--output-file=" + dst,
        "--flavor=woff2",
        "--layout-features=kern,liga,clig,calt,locl,ccmp,mark,mkmk,rlig,vert",
        "--no-hinting",
        "--desubroutinize",
        "--name-IDs=*",
        "--drop-tables+=DSIG",
        "--recalc-bounds",
        "--passthrough-tables",
    ]
    proc = subprocess.run(cmd, capture_output=True, text=True)
    if proc.returncode != 0:
        raise RuntimeError(
            "子集化失败：{}\n{}".format(src, (proc.stderr or proc.stdout).strip()[-1200:])
        )


def main():
    os.makedirs(OUT_FONTS, exist_ok=True)

    chars, seen = collect_chars()
    cjk = [c for c in chars if "\u4e00" <= c <= "\u9fff"]
    print("扫描文件数：{}".format(seen))
    print("字符总数：{}（其中汉字 {}）".format(len(chars), len(cjk)))

    charset_file = os.path.join(ROOT, ".build", "charset.txt")
    with io.open(charset_file, "w", encoding="utf-8") as fh:
        fh.write("".join(sorted(chars)))

    for src_name, dst_name in JOBS:
        src = os.path.join(SRC_FONTS, src_name)
        dst = os.path.join(OUT_FONTS, dst_name)
        if not os.path.exists(src):
            print("  跳过（缺少源文件）：{}".format(src_name))
            continue
        subset(src, dst, charset_file)
        size = os.path.getsize(dst)
        print("  {:<30} {:>8.1f} KB".format(dst_name, size / 1024.0))

    print("完成。")
    print("提示：页面文案新增汉字后，请重新运行本脚本。")


if __name__ == "__main__":
    main()
