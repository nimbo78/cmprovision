<picture>
  <source media="(prefers-color-scheme: dark)" srcset="cmprovision-horizontal-white.svg">
  <img src="cmprovision-horizontal.svg" alt="cmprovision" height="48">
</picture>

# cmprovision brand

One line writes the c and, without lifting, runs on into the m: a batch of modules going through the stand. The line
sits on a warm tile, and the name is set in Nunito Bold, the app's own typeface.

## Files

| File | Use it for |
|---|---|
| `cmprovision-horizontal.svg` | the logo: README, documents, slides on light backgrounds |
| `cmprovision-horizontal-white.svg` | the same on dark backgrounds: the name turns white, the tile stays |
| `cmprovision-stacked.svg` | narrow or square spaces |
| `cmprovision-symbol.svg` | the mark alone, 24 px and up |
| `cmprovision-favicon.svg` | below 24 px: a full-bleed tile with a larger, heavier line (the browser tab) |
| `cmprovision-apple-touch.svg` | phones' home screens: a full square, the phone rounds the corners |
| `cmprovision-symbol-flat.svg` | where a gradient will not print: one flat orange |
| `cmprovision-knockout-black.svg` | one colour: the tile with the line cut out (labels, stamps, engraving) |
| `cmprovision-horizontal-black.svg` | one colour, with the name |
| `cmprovision-glyph-ink.svg`, `-black.svg`, `-white.svg` | the line alone, for one-colour use on a background of your own |
| `png/` | the logo, the mark and the one-colour versions at 1024–1200 px, for label editors that take no SVG |
| `build_logo.py` | rebuilds every SVG here |

## Colours

| Name | HEX | RGB | Use |
|---|---|---|---|
| Tile, top left | `#FFD37A` | 255 211 122 | gradient start |
| Tile, bottom right | `#E2681C` | 226 104 28 | gradient end |
| Tile, flat | `#E8873A` | 232 135 58 | instead of the gradient |
| Ink | `#2A1A0C` | 42 26 12 | the line and the name |

The ink keeps 5:1 contrast at the tile's dark corner and 12:1 at the light one. No CMYK or Pantone values are
set: for print, convert the flat colour with the printer's profile.

## Space and size

- Clear space: the tile's corner radius (about 0.3 of its side) on every side.
- Screen: the mark from 24 px, the favicon cut from 16 to 23 px; the horizontal logo from a 24 px tile (116 px wide).
- Print: the mark from 6 mm; on 203 dpi thermal labels from 8 mm, where the line is about 5 dots wide.

## Backgrounds

- Light: the colour mark and logo.
- Dark: the tile as it is, the name white (`-horizontal-white`).
- One colour: `knockout-black` or `horizontal-black`; the line alone (`glyph-black`, `glyph-white`) when the
  background is the tile.

## Please don't

- recolour the tile, turn the gradient, or draw the line in any colour but ink, black or white;
- stretch, rotate or outline the mark, or add shadows and glows;
- retype the name in another typeface: use the files, where Nunito Bold is outlined;
- show the line alone in colour: in colour it always sits on its tile;
- put the raspberry back: the Raspberry Pi logo belongs to Raspberry Pi Ltd, and cmprovision is an independent fork.

## In the app

- The top bar shows `resources/views/components/application-mark.blade.php`; the sign-in pages show
  `authentication-card-logo.blade.php`, the mark with the name as live text (`.cmp-lockup` in `resources/css/app.css`).
- Every inline copy has a gradient id of its own (`cmp-mark-tile`, `cmp-logo-tile`, `cmp-auth-tile`): ids are shared
  by the whole page.
- The browser tab and home screens take `public/favicon.svg` (the favicon cut), `public/favicon.ico` (16, 32 and
  48 px) and `public/apple-touch-icon.png` (180 px).
- While a batch runs, `public/js/tab-status.js` swaps the tab icon for `favicon-run`, `favicon-fail`, `favicon-stale`
  or `favicon-done` (the favicon cut with a blue, red, yellow or green dot; yellow: a module stopped reporting;
  `build_logo.py` makes them, PNGs at 32 px rendered with rsvg-convert).
- `tests/Feature/BrandingTest.php` checks the colours in the pages and the icon files.

## Rebuilding

```sh
python docs/brand/build_logo.py docs/brand public/fonts/nunito-v16-latin-700.woff2
```

The script needs skia-python, uharfbuzz and fontTools. If the mark changes, render the PNGs again, `favicon.ico`
from `cmprovision-favicon.svg` (16, 32 and 48 px) and `apple-touch-icon.png` from `cmprovision-apple-touch.svg`
(180 px). Nunito is licensed under the SIL Open Font License.
