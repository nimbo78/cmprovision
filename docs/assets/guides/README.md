# Illustrated guide assets

These captures show the actual cmprovision UI from the configuration-transfer implementation (b271451), using a disposable local database. The EEPROM and image fixtures are demo data; no hardware was flashed. UI labels remain English in both guide languages; captions and diagrams are localized.

- `usb-prep-start.png` and `usb-prep-ready.png`: Dashboard tile at a narrow viewport.
- `transfer-export.png`: project selection, format and protection.
- `transfer-preview.png`: the project conflict row and review/apply controls; dependencies are above the crop.
- `transfer-complete.png`: a completed copy with four reused dependencies.

Each capture has an unannotated `.source.png` and an editable `.annotations.svg` overlay. Numbered markers are positioned from actual DOM bounds and drawn over the live page before capture. Controls, labels and results are not recreated. No entered passwords are shown.

To refresh: run a disposable UI build, recreate the documented state, capture with the same viewport and use current DOM bounds for the overlay. Check the final image at article and mobile width. Use paired English/Russian captions with matching marker numbers.

The source-backed process diagrams live in [diagrams](../../diagrams/README.md). Their HTML files include interactive exploration; PNG previews work directly in GitHub, and SVG exports retain vector detail.
