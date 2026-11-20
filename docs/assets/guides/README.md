# Illustrated guide assets

`hdmi-two-switches.png` is a 1920 × 1080 frame from the native Pillow HDMI renderer. The two demo switches have synthetic chassis serials `DEM2645E001` and `DEM2645W002`. Each has a sensor on Gi0/1. On assembly-west, port 3 supplies 6.2 W with no Ethernet carrier and port 4 is empty with PoE idle. The English and Russian HDMI guides use this same frame.

`station-setup.png` shows the station wizard Overview at 1440 px, using an isolated demonstration database and the real Blade/Livewire page. Network and SSH controllers are test doubles for this capture; no station or credentials were changed. Captured for 2.30.4.

These captures show the actual cmprovision UI using a disposable local database. The USB and transfer captures come from the configuration-transfer implementation (b271451); the image-access capture comes from its SSH-access follow-up. The EEPROM and image fixtures are demo data; no hardware was flashed. UI labels remain English in both guide languages; captions and diagrams are localized.

- `usb-prep-start.png` and `usb-prep-ready.png`: Dashboard tile at a narrow viewport.
- `transfer-export.png`: project selection, format and protection.
- `transfer-preview.png`: the project conflict row and review/apply controls; dependencies are above the crop.
- `transfer-complete.png`: a completed copy with four reused dependencies.
- `image-access.png`: the actual project SSH-access form from `codex/image-ssh-access`, captured in a disposable demo database with the existing `wlanpi` user and desired `operator` login. Browser acceptance covered profile switching, saved fields, blank saved-password display and a 390 px viewport.

The numbered USB and transfer captures have an unannotated `.source.png` and an editable `.annotations.svg` overlay. Numbered markers are positioned from actual DOM bounds and drawn over the live page before capture. The image-access capture is an unannotated crop. Controls, labels and results are not recreated. No entered passwords are shown.

To refresh: run a disposable UI build, recreate the documented state, capture with the same viewport and use current DOM bounds for the overlay. Check the final image at article and mobile width. Use paired English/Russian captions with matching marker numbers.

The source-backed process diagrams live in [diagrams](../../diagrams/README.md). Their HTML files include interactive exploration; PNG previews work directly in GitHub, and SVG exports retain vector detail.
