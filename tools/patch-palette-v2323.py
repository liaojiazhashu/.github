#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
荒启科幻官网 · v2.3.23 配色修正补丁

背景
    组件里散落着一批「深色主题专用」的硬编码颜色：
      · rgba(255,255,255,.0x) 当作洗色 —— 白天模式等于不可见（悬停、轨道、面板底全丢）
      · rgba(79,193,224,.16) 当作幽灵序号 —— 浅底上同样不可见
      · #fff 压在冰青实色上 —— 夜间对比度只有 2.1:1
      · #2A3559 / #232E55 / #2A3470 深藏青 —— 白天模式变成脏色块
    本脚本把它们替换为按主题给出的令牌。

幂等
    替换前先判断目标文本是否存在；已改过的文件不会被二次修改。
"""

import io
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

TOKENS_DARK = """
  /* ---- 透明洗色（v2.3.23）----
     旧版把 rgba(255,255,255,.0x) 直接写进组件，白天模式下等于没有：
     悬停态、轨道、条形底、面板底色会整体消失。改由令牌按主题给出。 */
  --wash-1: rgba(255, 255, 255, 0.03);
  --wash-2: rgba(255, 255, 255, 0.06);
  --wash-3: rgba(255, 255, 255, 0.14);
  --track: rgba(255, 255, 255, 0.07);

  /* ---- 实色块上的字色 ----
     白字压冰青（#4FC1E0）只有 2.1:1，夜间主按钮会糊成一片。
     品牌规则本来就是「冰青实色块 + 墨黑字」，这里把它令牌化。 */
  --on-accent: #0B0E14;

  /* ---- 幽灵序号（卡片 01/02/03 与期次编号）---- */
  --ghost-1: rgba(79, 193, 224, 0.16);
  --ghost-2: rgba(79, 193, 224, 0.28);
"""

TOKENS_DAY = """  --wash-1: rgba(22, 35, 47, 0.04);
  --wash-2: rgba(22, 35, 47, 0.07);
  --wash-3: rgba(22, 35, 47, 0.16);
  --track: rgba(22, 35, 47, 0.10);
  --on-accent: #FFFFFF;
  --ghost-1: rgba(23, 137, 173, 0.22);
  --ghost-2: rgba(23, 137, 173, 0.34);

"""

PATCHES = [
    # ---------------- 01 令牌 ----------------
    (
        "01-tokens.css",
        "  /* ---- 字体族 ---- */",
        TOKENS_DARK.lstrip("\n") + "\n  /* ---- 字体族 ---- */",
    ),
    (
        "01-tokens.css",
        "  /* 曲速尾焰（昼）",
        TOKENS_DAY + "  /* 曲速尾焰（昼）",
    ),
    (
        "01-tokens.css",
        "  background: rgba(79, 193, 224, 0.32);\n  color: #fff;",
        "  background: rgba(79, 193, 224, 0.32);\n  color: var(--on-accent);",
    ),
    # ---------------- 02 基础 ----------------
    (
        "02-base.css",
        "  background: linear-gradient(150deg, rgba(79, 193, 224, 0.16), rgba(139, 158, 216, 0.05));",
        "  background: var(--accent-1-soft);",
    ),
    (
        "02-base.css",
        "  background: linear-gradient(150deg, rgba(139, 158, 216, 0.14), rgba(79, 193, 224, 0.06));\n  border-color: rgba(139, 158, 216, 0.24);",
        "  background: var(--accent-2-soft);\n  border-color: var(--accent-2-line);",
    ),
    (
        "02-base.css",
        "  border-radius: var(--r-1);\n  background: rgba(255, 255, 255, 0.02);\n  color: var(--text-2);",
        "  border-radius: var(--r-1);\n  background: var(--wash-1);\n  color: var(--text-2);",
    ),
    (
        "02-base.css",
        "  border-radius: var(--r-2);\n  background: rgba(255, 255, 255, 0.015);",
        "  border-radius: var(--r-2);\n  background: var(--wash-1);",
    ),
    (
        "02-base.css",
        "  background: linear-gradient(90deg, rgba(139, 158, 216, 0), var(--accent-2));",
        "  background: var(--accent-2-line);",
    ),
    (
        "02-base.css",
        "  background: var(--accent-1);\n  color: #fff;",
        "  background: var(--accent-1);\n  color: var(--on-accent);",
    ),
    # ---------------- 04 导航 / 按钮 ----------------
    (
        "04-components-nav.css",
        "  color: #fff;\n  background: var(--accent-1);",
        "  color: var(--on-accent);\n  background: var(--accent-1);",
    ),
    (
        "04-components-nav.css",
        "  border: 1px solid var(--line-2);\n  background: rgba(255, 255, 255, 0.02);",
        "  border: 1px solid var(--line-2);\n  background: var(--wash-1);",
    ),
    (
        "04-components-nav.css",
        "  border-color: var(--line-2);\n  background: rgba(255, 255, 255, 0.03);",
        "  border-color: var(--line-2);\n  background: var(--wash-2);",
    ),
    (
        "04-components-nav.css",
        ".nav__link:hover { color: var(--text-1); background: rgba(255, 255, 255, 0.04); }",
        ".nav__link:hover { color: var(--text-1); background: var(--wash-2); }",
    ),
    (
        "04-components-nav.css",
        ".nav__caret-btn:hover { color: var(--text-1); background: rgba(255, 255, 255, 0.05); }",
        ".nav__caret-btn:hover { color: var(--text-1); background: var(--wash-2); }",
    ),
    (
        "04-components-nav.css",
        ".drop-item:hover { background: rgba(79, 193, 224, 0.12); }",
        ".drop-item:hover { background: var(--accent-1-soft); }",
    ),
    (
        "04-components-nav.css",
        "  color: var(--accent-1-hi);\n  background: rgba(79, 193, 224, 0.10);",
        "  color: var(--accent-1-hi);\n  background: var(--accent-1-soft);",
    ),
    (
        "04-components-nav.css",
        "  color: var(--text-1);\n  background: rgba(255, 255, 255, 0.03);",
        "  color: var(--text-1);\n  background: var(--wash-1);",
    ),
    (
        "04-components-nav.css",
        "  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.35);",
        "  box-shadow: var(--sh-1);",
    ),
    (
        "04-components-nav.css",
        "  box-shadow: 0 30px 80px rgba(0, 0, 0, 0.45);",
        "  box-shadow: var(--sh-2);",
    ),
    (
        "04-components-nav.css",
        "  box-shadow: -20px 0 60px rgba(0, 0, 0, 0.6);",
        "  box-shadow: var(--sh-2);",
    ),
    # ---------------- 05 内容组件 ----------------
    (
        "05-components-content.css",
        "  color: rgba(79, 193, 224, 0.16);",
        "  color: var(--ghost-1);",
    ),
    (
        "05-components-content.css",
        ".card--numbered:hover .card__index { color: rgba(79, 193, 224, 0.28); }",
        ".card--numbered:hover .card__index { color: var(--ghost-2); }",
    ),
    (
        "05-components-content.css",
        ".data-table tbody tr:hover { background: rgba(79, 193, 224, 0.07); }",
        ".data-table tbody tr:hover { background: var(--accent-1-soft); }",
    ),
    # ---------------- 06 页面级 ----------------
    (
        "06-pages.css",
        "  box-shadow: 0 14px 34px rgba(4, 6, 16, 0.5);",
        "  box-shadow: var(--sh-2);",
    ),
    (
        "06-pages.css",
        "  border-radius: 3px;\n  background: rgba(255, 255, 255, 0.06);",
        "  border-radius: 3px;\n  background: var(--track);",
    ),
    (
        "06-pages.css",
        "  border-radius: 2px;\n  background: rgba(255, 255, 255, 0.07);",
        "  border-radius: 2px;\n  background: var(--track);",
    ),
    (
        "06-pages.css",
        "  background: rgba(79, 193, 224, 0.05);",
        "  background: var(--accent-1-soft);",
    ),
]


def read(path):
    with io.open(path, "r", encoding="utf-8", newline="") as fh:
        raw = fh.read()
    crlf = "\r\n" in raw
    return raw.replace("\r\n", "\n"), crlf


def write(path, text, crlf):
    if crlf:
        text = text.replace("\n", "\r\n")
    with io.open(path, "w", encoding="utf-8", newline="") as fh:
        fh.write(text)


def main():
    for name, old, new in PATCHES:
        path = os.path.join(ROOT, ".build", "css-parts", name)
        text, crlf = read(path)
        if new in text and old not in text:
            print("  跳过（已是目标状态）：{} ← {}".format(name, old.strip()[:48]))
            continue
        n = text.count(old)
        if n != 1:
            print("  ! 匹配数异常 {}（{}）：{}".format(n, name, old.strip()[:60]))
            sys.exit(1)
        write(path, text.replace(old, new), crlf)
        print("  改好：{} ← {}".format(name, new.strip().splitlines()[0][:56]))

    print("完成。")


if __name__ == "__main__":
    main()
