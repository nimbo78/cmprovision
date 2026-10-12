"""Build cmprovision's logo files: the mark on its tile, one-colour versions, the favicon cut and the name lockups.

    python build_logo.py docs/brand public/fonts/nunito-v16-latin-700.woff2

Needs Python 3 with skia-python, uharfbuzz and fontTools (pip install skia-python uharfbuzz fonttools). The line is
drawn as a stroke and outlined here, so every file is plain filled paths. favicon.ico, apple-touch-icon.png and the
PNGs in png/ are renders of these files (any SVG renderer; 16, 32 and 48 px for the .ico).
"""
import re
import sys
from pathlib import Path

import skia
import uharfbuzz as hb
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont

OUT = Path(sys.argv[1])
FONT = Path(sys.argv[2])
OUT.mkdir(parents=True, exist_ok=True)

INK = '#2a1a0c'
MARK_A, MARK_B = '#ffd37a', '#e2681c'          # T-Premium warm, light mode
FLAT = '#e8873a'                                 # the gradient's middle, for places without gradients

# B3: one line writes c and runs on into m (48 grid, centre line, stroke 3.6, round caps and joins)
GLYPH = 'M20.79 18.77A6.1 6.1 0 1 0 18.7 30.6Q24.9 30.6 24.9 25.2V21.2A3 3 0 0 1 30.9 21.2V30.4M30.9 21.2A3 3 0 0 1 36.9 21.2V30.4'
GLYPH_STROKE = 3.6
# favicon cut: the same line, larger and heavier on a full-bleed tile
FAV_SCALE, FAV_CENTRE, FAV_STROKE = 1.22, (26.9, 24.5), 4.6


def parse(d):
    """A small SVG path parser (absolute M L H V Q C A Z) into a skia.Path."""
    p = skia.Path()
    tokens = re.findall(r'[MLHVQCAZ]|-?\d*\.?\d+(?:e-?\d+)?', d)
    i, cmd, x, y = 0, None, 0.0, 0.0

    def num():
        nonlocal i
        i += 1
        return float(tokens[i - 1])
    while i < len(tokens):
        if re.match(r'[A-Z]', tokens[i]):
            cmd = tokens[i]
            i += 1
        if cmd == 'M':
            x, y = num(), num(); p.moveTo(x, y); cmd = 'L'
        elif cmd == 'L':
            x, y = num(), num(); p.lineTo(x, y)
        elif cmd == 'H':
            x = num(); p.lineTo(x, y)
        elif cmd == 'V':
            y = num(); p.lineTo(x, y)
        elif cmd == 'Q':
            x1, y1, x, y = num(), num(), num(), num(); p.quadTo(x1, y1, x, y)
        elif cmd == 'C':
            x1, y1, x2, y2, x, y = num(), num(), num(), num(), num(), num(); p.cubicTo(x1, y1, x2, y2, x, y)
        elif cmd == 'A':
            rx, ry, rot, large, sweep, x, y = num(), num(), num(), num(), num(), num(), num()
            p.arcTo(rx, ry, rot, skia.Path.ArcSize.kLarge_ArcSize if large else skia.Path.ArcSize.kSmall_ArcSize,
                    skia.PathDirection.kCW if sweep else skia.PathDirection.kCCW, x, y)
        elif cmd == 'Z':
            p.close()
            cmd = None
        else:
            raise ValueError(f'unsupported path data near {tokens[i:i + 4]}')
    return p


def stroked(path, width):
    paint = skia.Paint(Style=skia.Paint.kStroke_Style, StrokeWidth=width,
                       StrokeCap=skia.Paint.kRound_Cap, StrokeJoin=skia.Paint.kRound_Join, AntiAlias=True)
    out = skia.Path()
    paint.getFillPath(path, out, None, 8.0)
    return skia.Simplify(out)


def svg_d(path):
    """Path data as skia's SVG backend writes it (conics become quads), rounded to 0.01."""
    stream = skia.DynamicMemoryWStream()
    canvas = skia.SVGCanvas.Make(skia.Rect(0, 0, 1000, 1000), stream)
    canvas.drawPath(path, skia.Paint(AntiAlias=True))
    del canvas
    svg = bytes(stream.detachAsData()).decode()
    d = re.search(r' d="([^"]+)"', svg).group(1)
    return re.sub(r'-?\d+\.\d+', lambda m: f'{float(m.group()):.2f}'.rstrip('0').rstrip('.'), d)


def P(path, fill):
    """A <path> element with the path's own fill rule (Simplify may hand back even-odd)."""
    rule = 'evenodd' if path.getFillType() in (skia.PathFillType.kEvenOdd, skia.PathFillType.kInverseEvenOdd) else 'nonzero'
    return f'<path fill-rule="{rule}" d="{svg_d(path)}" fill="{fill}"/>'


def rrect(x, y, w, h, r):
    p = skia.Path()
    p.addRRect(skia.RRect.MakeRectXY(skia.Rect.MakeXYWH(x, y, w, h), r, r))
    return p


def scaled(path, s, cx, cy, tx=24.0, ty=24.0):
    m = skia.Matrix()
    m.setScaleTranslate(s, s, tx - cx * s, ty - cy * s)
    out = skia.Path()
    path.transform(m, out)
    return out


def write(name, body, w=48, h=48, title='cmprovision'):
    (OUT / name).write_text(
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w:g} {h:g}" role="img" aria-label="{title}">'
        f'<title>{title}</title>{body}</svg>\n', encoding='utf-8', newline='\n')
    print('wrote', name)


GRAD = (f'<defs><linearGradient id="cmp-tile" x1="0" y1="0" x2="1" y2="1">'
        f'<stop offset="0" stop-color="{MARK_A}"/><stop offset="1" stop-color="{MARK_B}"/></linearGradient></defs>')

glyph = stroked(parse(GLYPH), GLYPH_STROKE)
tile = rrect(2, 2, 44, 44, 13)
knockout = skia.Op(tile, glyph, skia.PathOp.kDifference_PathOp)

# symbol: the design system's tile with the glyph in ink
write('cmprovision-symbol.svg', GRAD + P(tile, 'url(#cmp-tile)') + P(glyph, INK))
write('cmprovision-symbol-flat.svg', P(tile, FLAT) + P(glyph, INK))
# one colour: the glyph alone, and a tile with the glyph cut out (labels, stamps)
write('cmprovision-glyph-ink.svg', P(glyph, INK))
write('cmprovision-glyph-black.svg', P(glyph, '#000'))
write('cmprovision-glyph-white.svg', P(glyph, '#fff'))
write('cmprovision-knockout-black.svg', P(knockout, '#000'))

# favicon cut: full-bleed tile, the line larger and heavier so it survives 16 px
fav_line = scaled(parse(GLYPH), FAV_SCALE, *FAV_CENTRE, tx=24.0, ty=24.3)
fav_glyph = stroked(fav_line, FAV_STROKE)
fav_tile = rrect(0, 0, 48, 48, 12)
write('cmprovision-favicon.svg', GRAD + P(fav_tile, 'url(#cmp-tile)') + P(fav_glyph, INK))

# the browser tab's state (public/js/tab-status.js): the favicon cut with a dot, white-ringed, at the top right
def dot(cx, cy, r):
    p = skia.Path()
    p.addCircle(cx, cy, r)
    return p


CHECK = 'M34.6 9.4L37.6 12.4L43.1 6.4'
for state, colour in (('run', '#2563eb'), ('fail', '#dc2626'), ('done', '#16a34a')):
    badge = P(dot(39, 9, 10.5), '#fff') + P(dot(39, 9, 8.5), colour)
    if state == 'done':
        badge += P(stroked(parse(CHECK), 2.6), '#fff')
    write(f'cmprovision-favicon-{state}.svg', GRAD + P(fav_tile, 'url(#cmp-tile)') + P(fav_glyph, INK) + badge)

# phones' home screens: a full square (the phone rounds the corners itself) with the standard line
square = skia.Path()
square.addRect(skia.Rect.MakeWH(48, 48))
write('cmprovision-apple-touch.svg', GRAD + P(square, 'url(#cmp-tile)') + P(glyph, INK))

# wordmark: "cmprovision" in Nunito Bold, shaped with its kerning and outlined
font = TTFont(str(FONT))
upm = font['head'].unitsPerEm
# HarfBuzz does not read WOFF2: shape the same font as plain TrueType
import io
ttf = io.BytesIO()
font.flavor = None
font.save(ttf)
font = TTFont(io.BytesIO(ttf.getvalue()))
blob = hb.Blob(ttf.getvalue())
face = hb.Face(blob)
hbfont = hb.Font(face)
buf = hb.Buffer()
buf.add_str('cmprovision')
buf.guess_segment_properties()
hb.shape(hbfont, buf, {'kern': True, 'liga': False})
glyph_set = font.getGlyphSet()
order = font.getGlyphOrder()


def wordmark(size, x0, baseline):
    """Outlined text as one skia path; returns (path, advance width)."""
    scale = size / upm
    pen_x = 0.0
    path = skia.Path()
    for info, pos in zip(buf.glyph_infos, buf.glyph_positions):
        name = order[info.codepoint]
        pen = SVGPathPen(glyph_set)
        tp = TransformPen(pen, (scale, 0, 0, -scale, x0 + (pen_x + pos.x_offset) * scale, baseline - pos.y_offset * scale))
        glyph_set[name].draw(tp)
        d = pen.getCommands()
        if d:
            path.addPath(parse(d.replace('Z', 'Z ')))
        pen_x += pos.x_advance
    return skia.Simplify(path), pen_x * scale


xh = font['OS/2'].sxHeight / upm                  # x-height as a share of the em
# horizontal lockup: the Switch Sync proportions (28 px tile, 17 px name, 11 px gap) at a 48 tile
size = 48 * 17 / 28
gap = 48 * 11 / 28
base = 24 + xh * size / 2                         # the lowercase band centred on the tile
text, width = wordmark(size, 48 + gap, base)
W = 48 + gap + width + 2
write('cmprovision-horizontal.svg', GRAD + P(tile, 'url(#cmp-tile)') + P(glyph, INK) + P(text, INK), w=round(W, 2))
write('cmprovision-horizontal-white.svg', GRAD + P(tile, 'url(#cmp-tile)') + P(glyph, INK) + P(text, '#fff'), w=round(W, 2))
write('cmprovision-horizontal-black.svg', P(knockout, '#000') + P(text, '#000'), w=round(W, 2))

# stacked lockup: tile above the name
size2 = 48 * 0.5
text2, width2 = wordmark(size2, 0, 0)
W2 = max(48.0, width2)
top = 48 + 48 * 0.28
m = skia.Matrix()
m.setTranslate((W2 - width2) / 2, top + xh * size2 + 1)
text2m = skia.Path()
text2.transform(m, text2m)
tile2, glyph2 = skia.Path(), skia.Path()
shift = skia.Matrix()
shift.setTranslate((W2 - 48) / 2, 0)
tile.transform(shift, tile2)
glyph.transform(shift, glyph2)
H2 = top + xh * size2 + 1 + size2 * 0.24
write('cmprovision-stacked.svg', GRAD + P(tile2, 'url(#cmp-tile)') + P(glyph2, INK) + P(text2m, INK), w=round(W2, 2), h=round(H2, 2))
print(f'x-height {xh:.3f} em; horizontal {W:.1f}x48; stacked {W2:.1f}x{H2:.1f}')
