#!/usr/bin/env python3
"""PNG 分段裁切（纯标准库，不依赖 Pillow / ImageMagick）

用途：整页截图动辄 9000px 高，人在预览里看不清细节。
     这个脚本把长图按给定高度切成若干段，供逐段人工验收。

用法：
  python tools/png-crop.py <输入.png> <输出前缀> [--slice 1400] [--overlap 80]
  python tools/png-crop.py <输入.png> <输出.png>   --box 0,380,1440,1480

约束：只支持 8 位深度、非隔行的 RGB / RGBA PNG（Chrome、Edge 截图均属此类）。
"""

import sys
import zlib
import struct


def read_png(path):
    data = open(path, 'rb').read()
    if data[:8] != b'\x89PNG\r\n\x1a\n':
        raise SystemExit('不是 PNG 文件：' + path)

    pos = 8
    idat = b''
    ihdr = None
    while pos < len(data):
        length = struct.unpack('>I', data[pos:pos + 4])[0]
        ctype = data[pos + 4:pos + 8]
        body = data[pos + 8:pos + 8 + length]
        if ctype == b'IHDR':
            ihdr = struct.unpack('>IIBBBBB', body)
        elif ctype == b'IDAT':
            idat += body
        elif ctype == b'IEND':
            break
        pos += 12 + length

    if ihdr is None:
        raise SystemExit('缺少 IHDR')
    w, h, depth, color, comp, filt, interlace = ihdr
    if depth != 8 or interlace != 0:
        raise SystemExit('只支持 8 位非隔行 PNG，实际 depth=%d interlace=%d' % (depth, interlace))
    channels = {0: 1, 2: 3, 4: 2, 6: 4}.get(color)
    if channels is None:
        raise SystemExit('不支持的色彩类型：%d' % color)

    raw = zlib.decompress(idat)
    stride = w * channels
    rows = []
    prev = bytearray(stride)
    p = 0
    for _ in range(h):
        f = raw[p]
        p += 1
        line = bytearray(raw[p:p + stride])
        p += stride
        if f == 0:
            pass
        elif f == 1:  # Sub
            for i in range(channels, stride):
                line[i] = (line[i] + line[i - channels]) & 0xFF
        elif f == 2:  # Up
            for i in range(stride):
                line[i] = (line[i] + prev[i]) & 0xFF
        elif f == 3:  # Average
            for i in range(stride):
                left = line[i - channels] if i >= channels else 0
                line[i] = (line[i] + ((left + prev[i]) >> 1)) & 0xFF
        elif f == 4:  # Paeth
            for i in range(stride):
                a = line[i - channels] if i >= channels else 0
                b = prev[i]
                c = prev[i - channels] if i >= channels else 0
                pa, pb, pc = abs(b - c), abs(a - c), abs(a + b - 2 * c)
                pr = a if (pa <= pb and pa <= pc) else (b if pb <= pc else c)
                line[i] = (line[i] + pr) & 0xFF
        else:
            raise SystemExit('未知行过滤类型：%d' % f)
        rows.append(bytes(line))
        prev = line

    return w, h, channels, rows


def write_png(path, w, h, channels, rows):
    color = {1: 0, 3: 2, 2: 4, 4: 6}[channels]
    raw = b''.join(b'\x00' + r for r in rows)

    def chunk(ctype, body):
        return (struct.pack('>I', len(body)) + ctype + body
                + struct.pack('>I', zlib.crc32(ctype + body) & 0xFFFFFFFF))

    out = [b'\x89PNG\r\n\x1a\n',
           chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, color, 0, 0, 0)),
           chunk(b'IDAT', zlib.compress(raw, 6)),
           chunk(b'IEND', b'')]
    open(path, 'wb').write(b''.join(out))


def main():
    args = sys.argv[1:]
    if len(args) < 2:
        raise SystemExit(__doc__)
    src, dst = args[0], args[1]

    def opt(name, default=None):
        if name in args:
            return args[args.index(name) + 1]
        return default

    w, h, ch, rows = read_png(src)

    box = opt('--box')
    if box:
        x0, y0, x1, y1 = [int(v) for v in box.split(',')]
        x0, x1 = max(0, x0), min(w, x1)
        y0, y1 = max(0, y0), min(h, y1)
        seg = [rows[y][x0 * ch:x1 * ch] for y in range(y0, y1)]
        write_png(dst, x1 - x0, y1 - y0, ch, seg)
        print('%s  %dx%d' % (dst, x1 - x0, y1 - y0))
        return

    slice_h = int(opt('--slice', '1400'))
    overlap = int(opt('--overlap', '80'))
    idx = 1
    y = 0
    while y < h:
        y2 = min(h, y + slice_h)
        seg = [rows[k] for k in range(y, y2)]
        name = '%s-%02d.png' % (dst, idx)
        write_png(name, w, y2 - y, ch, seg)
        print('%s  y=%d..%d  %dx%d' % (name, y, y2, w, y2 - y))
        idx += 1
        if y2 >= h:
            break
        y = y2 - overlap
    print('共 %d 段，源图 %dx%d' % (idx - 1, w, h))


if __name__ == '__main__':
    main()
