# README assets

## Direction

- Audience: operators preparing batches of CM4 devices and developers maintaining the stand.
- Value: write, verify and optionally boot-check a batch from one Raspberry Pi and a browser.
- Proof: all nine application tabs and supporting views, captured from a disposable local copy with clearly identified demo data.
- First successful action: install the package, upload an image, activate a project and provision a module.
- Theme: the existing warm cmprovision identity and the physical separation of management Wi-Fi from provisioning Ethernet.

The existing outlined Nunito logo in `docs/brand/` remains the title mark, incorporated unchanged into the hero. Body copy and commands stay in Markdown. The hero combines a generated hardware illustration with deterministic SVG typography and layout; the network diagram remains pure SVG.

Palette: warm white `#FBF7F2`, ink `#2A1A0C`, orange `#E2681C`, muted brown `#776759`, white `#FFFFFF`. Diagram typography uses Segoe UI / system sans-serif, with 42-unit titles, 28-unit node labels and 22-unit supporting labels. Geometry uses an 8-unit spacing grid, 16-unit corners and 3-unit connection strokes. The motif is one provisioner feeding several modules through an isolated switch.

## Files

- `hero.png`: published 2400 × 800 hero, composed from the sources below; the README scales it to its content width.
- `source/hero-layout.svg`: editable 1200 × 400 composition, including the original outlined logo, exact text and a named hardware illustration layer.
- `source/hero-subject.png`: transparent hardware illustration generated and refined with the built-in ImageGen tool on 2026-10-17.
- `source/hero-prompt.txt`: generation and refinement prompts for the final illustration.
- `stand.svg`: network topology; labels and network requirements are also explained in the README.
- `screenshots/`: 24 PNG browser captures made on 2026-10-17 with Playwright and headless Edge. The application was a disposable copy of the current working tree (version `2.6.2` in `debian/changelog`), served locally inside the `cmprov-trixie` WSL environment with its own SQLite database and demo account.

## Hero production

The illustration represents the project's batch workflow: a WLAN Pi M4+ provisioner in its orange enclosure, an Ethernet switch and four CM4 modules on carrier boards. The M4+ appearance follows the [official WLAN Pi reference](https://userguide.wlanpi.com/hardware/wlan-pi-m4%2B) and the [BadgerWiFi product views](https://www.badgerwifi.co.uk/store/p/wlanpi-m4-plus): orange enclosure, black display panel, joystick and three buttons. Reference photographs are used only as visual inputs and are not distributed with these assets. It is a conceptual illustration, not a photograph of a tested stand or an exact assembly drawing. The existing `stand.svg` and README explain the required network setup.

The cabling was rebuilt in stages and checked from plug to plug: one cord connects the WLAN Pi to the switch, and four independent cords connect the switch to the carrier boards. Each plug enters the visible RJ45 opening; no cord attaches to a jack's metal side or a power inlet.

The generated layer contains hardware only. The original logo, headline, description and workflow labels are composed in SVG, using a warm-white background `#FBF7F2`, ink `#2A1A0C`, orange `#E2681C` and muted brown `#776759`. The headline uses 62-unit Segoe UI/system sans-serif; the description uses 24 units and process labels 20 units. The logo is copied as paths with its brand colours and aspect ratio preserved. The published PNG was rendered from the layout at 2× resolution with Edge through Playwright.

Keep `hero-layout.svg` and `hero-subject.png` together when editing: the SVG uses a relative raster reference. Publish the exported PNG, not that linked SVG. To refresh the composition, open the SVG in a renderer that resolves its local image, export at 2400 × 800, and inspect the result at 900 and 360 CSS pixels. The transparent subject was also checked against both light and dark backgrounds. No intermediate generations are kept in this asset directory.

## Screenshot data and capture

The eight module records, two projects, images, scripts, package metadata, label template, firmware entries and notification channel are synthetic fixtures. Network readings use a demo `NetworkStatus` service; Wi-Fi and switch responses use the repository's `FakeWifiNetworks` and `FakeSnmp` helpers. The switch starts from the S5731 test fixture with demo MAC addresses and port states. No real modules were provisioned, switch power changed or notifications sent for this gallery. Firmware and OS files are placeholders, not downloadable releases. Rates, power, sizes and timings illustrate UI states and are not benchmarks.

Screenshots come directly from the rendered application, without visual editing or added UI. Most use a 1440 × 1000 CSS-pixel viewport, Warm / Light / Compact, at device scale 1. The density comparison uses 1600 × 1000 so the expanded navigation fits in Roomy mode. Settings, module details and Profile use full-page captures. Editor dialogs are captured as elements in a taller viewport. The connection panel uses a 660 × 520 crop; Appearance uses a 540 × 490 crop. Mobile navigation uses a 390 × 844 viewport. Only unrelated browser chrome and unused page space are excluded.

The gallery covers Dashboard, CMs, Ports, Images, Projects, Scripts, Labels, Firmware and Settings; three editor dialogs; module details; four style/theme combinations; two densities; connection and appearance panels; Profile; API Tokens; and mobile navigation.

All 24 views use Israel's `Asia/Jerusalem` time zone for both the demo application's display times and the capture browser. The Appearance panel derives its city and sunrise/sunset times from that zone. Screens were recaptured after changing the demo configuration; the PNG text was not retouched.

To refresh, create an isolated copy with its own database and sample fixtures, sign in, and exercise the real navigation and appearance controls. Capture all nine tabs in the same browser session, then the dialogs and UI variants. Keep credentials and production identifiers out of the images. Label simulated readings wherever the screenshots are presented. Verify there are no browser errors, broken views or clipped dialogs, then remove the temporary account and stop the demo server.

Preview the root README at 900- and 360-pixel content widths on light and dark backgrounds. Essential setup instructions must remain readable as Markdown when images are unavailable.
