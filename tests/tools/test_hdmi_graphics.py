"""Exercise the HDMI artwork with real Pillow fonts, pixels and wallboard helpers."""
import importlib.util
from pathlib import Path
import sys
import unittest
from unittest.mock import patch

from PIL import Image, ImageFont

TOOLS = Path(__file__).resolve().parents[2] / "tools"
sys.path.insert(0, str(TOOLS))
spec = importlib.util.spec_from_file_location("wallboard", TOOLS / "hdmi-wallboard.py")
board = importlib.util.module_from_spec(spec)
spec.loader.exec_module(board)


def port(n, state="free", serials=(), **extra):
    return dict(name="Gi0/%d" % n, number=n, state=state, serials=list(serials), **extra)


def bank(label, rows, ports):
    return dict(label=label, rows=rows, ports=ports)


def switch(name, *blocks, online=True):
    return dict(name=name, model="WS-C2960CX-8PC-L", answering=online, blocks=list(blocks))


def catalyst(count, suffix="P-4X", name="rack"):
    # WallboardSnapshot keeps the panel_number from each verified SwitchFrontPanel bank.
    blocks = []
    for start in range(1, count+1, 12):
        ports = [dict(number=n, name=f"Gi2/0/{n}", endpoint=f"node-{n}", state="free",
                      link_up=True, speed=1000, poe_state="delivering", watts=3.1) for n in range(start, start+12)]
        blocks.append(bank("RJ45", 2, ports))
    fiber = "SFP+" if "4X" in suffix else "SFP"
    prefix = "Te" if fiber == "SFP+" else "Gi"
    blocks.append(bank(fiber, 1, [dict(number=n, name=f"{prefix}2/1/{n}", endpoint=f"fiber-{n}",
                                       state="uplink", link_up=n == 1, speed=10000 if n == 1 else None)
                                  for n in range(1, 5)]))
    return dict(name=name, model=f"C9200L-{count}{suffix}", serial=f"SN-{count}", verified_layout=True,
                answering=True, blocks=blocks)


def example():
    a = switch("assembly-east", bank("RJ45", 2, [port(1), port(2)]))
    b = switch("assembly-west", bank("RJ45", 2, [port(1, "module", ["s1"]), port(2, "recognized"),
                                                port(3, "uplink", server=True), port(4, "uplink", station={"name":"peer"})]),
               bank("SFP", 1, [port(11), port(12)]))
    writer = dict(name="wlanpi-fd3", hostname="wlanpi-fd3", mac="de:ad:be:ef:00:01", serial="s1",
                  port="assembly-west Gi0/1", phase="write", state="run", percent=63,
                  eta=60, speed=25*1048576, cycle=236, detail="Writing image to eMMC",
                  hardware={"model": "Compute Module 4", "revision": "1.1", "sku":"CM4104032 (inferred)"},
                  steps=["preinstall", "write"], diagnostics=None)
    sensor = dict(name="marked-sensor", mac="de:ad:be:ef:00:02", port="assembly-west Gi0/2", state="recognized",
                  detail="Flashed by wlanpi-da2 / boot 1 of 20",
                  flashed_by=dict(station="wlanpi-da2", boot=1, boots=20))
    return dict(host="wlanpi-d62", clock="2026-11-20 15:00:00 MSK", generated_at=100, project="Line A",
                counts=dict(done=0, running=1, failed=0, stale=0), modules=[writer], recognized=[sensor],
                switches=[a, b, switch("assembly-spare", online=False)], unknown=[],
                station=dict(uplink=dict(state="wifi", device="wlan1", ssid="Lab 78", signal=-51, address="10.2.3.4"),
                             links=[dict(name="eth0", kind="ethernet", role="stand", up=True, speed=1000, address="172.20.0.2")]))


class GraphicsTest(unittest.TestCase):
    def setUp(self):
        self.assertIsNotNone(importlib.util.find_spec("hdmi_graphics"), "The Pillow wallboard renderer is missing")
        import hdmi_graphics
        self.graphics = hdmi_graphics

    def draw(self, data=None, size=(1600, 900), now=102, **options):
        # Record the final text and coordinates after clipping. Both cached masks
        # and native text still paint the actual image checked by the pixel tests.
        items = []
        original = self.graphics.Painter.paint_label
        def record(painter, xy, value, *args, **kwargs):
            items.append((value, xy))
            return original(painter, xy, value, *args, **kwargs)
        with patch.object(self.graphics.Painter, "paint_label", record):
            frame = self.graphics.render_frame(board, example() if data is None else data, size, now=now, **options)
        self.assertIsInstance(frame, Image.Image)
        self.assertEqual((frame.mode, frame.size), ("RGB", size))
        return frame, items

    def test_stand_displays_switch_roster_current_owner_port_and_offline_status(self):
        _, items = self.draw(view="desk")
        labels = "\n".join(text for text, _ in items)
        for value in ("assembly-east", "assembly-west", "assembly-spare", "Offline", "Device card",
                      "assembly-west Gi0/1", "Gi0/1", "63%", "25.0 MiB/s", "ETA 1:00", "Lab 78", "172.20.0.2",
                      "This station", "Other station"):
            self.assertIn(value, labels)
        self.assertGreaterEqual(labels.count("assembly-west"), 3)  # roster, current front panel and card

    def test_verified_catalyst_chassis_displays_every_socket_in_physical_order(self):
        for count, suffix, size, palette in ((24, "P-4X", (1600, 900), "warm"), (48, "T-4G-E", (1600, 670), "warm-light"),
                                            (24, "T-4G-A", (1280, 720), "neutral-light"), (48, "P-4X", (1920, 1080), "neutral")):
            with self.subTest(count=count, suffix=suffix, size=size, palette=palette):
                sw = catalyst(count, suffix)
                # Keep an independent second observation on a fiber socket too.
                sw["blocks"][-1]["ports"][1].update(poe_state="fault")
                frame, items = self.draw({"switches": [sw]}, size, view="map", palette=palette)
                xy = dict(items)
                self.assertTrue(any("Full switch map" in word for word, _ in items))
                self.assertIn("S/N SN-"+str(count), xy)
                self.assertIn("SFP+ uplinks" if "4X" in suffix else "SFP uplinks", xy)
                for n in range(1, count+1):
                    self.assertIn(f"node-{n}", xy)
                self.assertEqual(xy["node-1"][0], xy["node-2"][0])
                self.assertLess(xy["node-1"][1], xy["node-2"][1])
                self.assertEqual(xy["node-1"][1], xy["node-13"][1])
                self.assertLess(xy["node-11"][0], xy["node-13"][0])
                if count == 48:
                    self.assertGreater(xy["node-25"][1], xy["node-24"][1])
                    self.assertEqual(xy["node-1"][0], xy["node-25"][0])
                for n in range(1, 5):
                    self.assertGreater(xy[f"fiber-{n}"][1], xy[f"node-{count}"][1])
                self.assertIn("10G", xy)
                self.assertIn("Fault", xy)
                self.assertIn("3.1 W", xy)
                self.assertTrue(all(0 <= x < frame.width and 0 <= y < frame.height for _, (x,y) in items))
                self.assertEqual("Devices on the stand" in xy, count == 24)

    def test_explicit_map_visits_distinct_switches_and_uses_the_actual_selected_socket(self):
        first, last = catalyst(24, name="east"), catalyst(48, name="west")
        last["blocks"][2]["ports"][4].update(serials=["s1"], state="module", endpoint="writer")
        data = example()
        data.update(switches=[first, switch("spare", online=False), last], recognized=[])
        data["modules"][0].update(name="writer", port="old label")
        for offset, wanted in ((0, "Gi2/0/29"), (1, "No selected card on this switch"), (2, "No port data available"),
                               (3, "Gi2/0/29"), (-1, "No port data available")):
            with self.subTest(offset=offset):
                _, items = self.draw(data, view="map", switch_offset=offset)
                labels = [word for word, _ in items]
                self.assertTrue(any(wanted in word for word in labels))
                if offset in (2, -1):
                    self.assertTrue(any("Stand console" in word for word in labels))
                    self.assertTrue(any("Full map unavailable" in word for word in labels))
                else:
                    self.assertTrue(any("Full switch map" in word for word in labels))
                    self.assertEqual("Devices on the stand" in labels, offset == 1)

    def test_short_automatic_slides_reveal_the_end_of_a_64_character_port_name(self):
        east, west = catalyst(48, name="east"), catalyst(48, name="west")
        name = "cm-" + "a" * 57 + "-b29"
        east["blocks"][0]["ports"][0]["endpoint"] = name
        data = example()
        data.update(switches=[east, west], modules=[], recognized=[], unknown=[])
        motion = self.graphics.Marquee()

        def draw(tick, at, offset=0):
            self.graphics.render_frame(board, data, (1600, 900), view="map", tick=tick, switch_offset=offset,
                                       now=100, motion=motion, monotonic=at)
            return next((lane for key, lane in motion.lanes.items() if name in key), None)

        lane = draw(0, 0)
        distance = lane.mask.width - lane.background.width
        end = 1.5 + distance / lane.speed + .25
        self.assertGreater(end, 10)
        visit = 0
        while (visit + 1) * 5 < end:
            self.assertIsNone(draw(2 * visit + 1, 10 * visit + 5))
            visit += 1
            lane = draw(2 * visit, 10 * visit)
            self.assertGreater(lane.offset, 0, "Repeated slides must continue past the prefix")
        motion.advance(10 * visit + end - 5 * visit)
        self.assertEqual(lane.offset, distance)
        self.assertEqual(draw(2 * visit, 10 * visit + 5, offset=2).offset, 0)

    def test_auto_uses_current_switch_and_keeps_failure_diagnostics_and_saved_data(self):
        data = example()
        sw = catalyst(48)
        sw.update(answering=False, serial=None)
        sw["blocks"][0]["ports"][0].update(serials=["s1"], state="module")
        data["switches"] = [sw, switch("small", bank("Copper", 2, [port(1)]))]
        for offset, expected in ((0, "Full switch map"), (1, "Stand console")):
            _, items = self.draw(data, view="auto", switch_offset=offset)
            labels = [word for word, _ in items]
            self.assertTrue(any(expected in word for word in labels))
            if offset == 0:
                self.assertIn("S/N unavailable", labels)
                self.assertIn("Offline · last known ports", labels)
        for state in ("fail", "stale"):
            data["modules"][0]["state"] = state
            _, items = self.draw(data, view="auto")
            self.assertIn("Device diagnostics", [word for word, _ in items])
        _, items = self.draw(data, view="desk")
        self.assertIn("Device card", dict(items))

    def test_a_partial_or_unverified_chassis_falls_back_without_claiming_a_full_map(self):
        for problem in ("unverified", "model", "short", "extra", "missing fiber", "duplicate", "reordered", "wrong media",
                        "null bank", "null socket", "no switches"):
            with self.subTest(problem=problem):
                sw = catalyst(24)
                groups = sw["blocks"]
                if problem == "unverified":
                    sw.pop("verified_layout")
                elif problem == "model":
                    sw["model"] = "Unknown"
                elif problem == "short":
                    groups[0]["ports"].pop()
                elif problem == "extra":
                    groups.append(bank("Other", 1, [port(33)]))
                elif problem == "missing fiber":
                    groups.pop()
                elif problem == "duplicate":
                    groups[0]["ports"][1]["name"] = groups[0]["ports"][0]["name"]
                elif problem == "reordered":
                    groups[0]["ports"].reverse()
                elif problem == "wrong media":
                    groups[-1]["label"] = "SFP"
                elif problem == "null bank":
                    groups[0] = None
                    sw["answering"] = False
                elif problem == "null socket":
                    groups[0]["ports"][0] = None
                _, items = self.draw({"switches": [] if problem == "no switches" else [sw]}, view="map")
                self.assertIn("Device card", dict(items))
                self.assertTrue(any("Full map unavailable" in word for word, _ in items))

    def test_front_panel_shows_each_endpoint_with_independent_link_and_poe_rows(self):
        ports = [
            port(21, "module", ["s1"], endpoint="writer", link_up=False, speed=1000, poe_state="delivering", watts=6.5),
            port(22, endpoint="AP +2", link_up=True, speed=1000, poe_state="disabled", watts=0),
            port(23, endpoint="bridge", link_up=True, poe_state="delivering"),
            port(24, endpoint=None, link_up=None, watts=0),
            port(25, endpoint="relay", link_up=False, poe_state="fault"),
            port(26, endpoint="tester", link_up=False, poe_state="test"),
            port(27, endpoint=None, link_up=False, poe_state="searching"),
            port(28, endpoint=None, link_up=True),
        ]
        data = example()
        data["switches"] = [switch("bench", bank("Copper", 2, ports))]
        for size in ((1920, 1080), (3440, 1440)):
            with self.subTest(size=size):
                frame, items = self.draw(data, size, view="desk")
                positions = dict(items)
                dx = positions["23"][0] - positions["21"][0]
                dy = positions["22"][1] - positions["21"][1]
                for n, endpoint, link, poe in ((21, "writer", "Down", "6.5 W"), (22, "AP +2", "1G", "Off"),
                                                (23, "bridge", "Up", "On"), (24, "—", "?", "?"),
                                                (25, "relay", "Down", "Fault"), (26, "tester", "Down", "Test"),
                                                (27, "—", "Down", "Idle"), (28, "No ID", "Up", "?")):
                    x, y = positions[str(n)]
                    labels = [(word, xy) for word, xy in items if x <= xy[0] < x+dx-3 and y < xy[1] < y+dy-3]
                    self.assertEqual([word for word, _ in labels], [endpoint, link, poe], f"port {n}")
                    (_, ey), (lx, ly), (px, py) = [xy for _, xy in labels]
                    self.assertTrue(y < ey < ly < py)
                    self.assertEqual(lx, px)
                    if n == 22:
                        # A link glyph is painted to the left of the speed. Its pixels
                        # stay within this socket even when adjacent sockets disagree.
                        patch = frame.crop((x, ly-10, lx-2, ly+10))
                        self.assertIn((138, 212, 173), set(patch.getdata()))
                    if n == 21:
                        patch = frame.crop((x, py-10, px-2, py+10))
                        self.assertIn((235, 191, 114), set(patch.getdata()))
                for legend in ("Ethernet link", "PoE", "? No reading", "— No device seen"):
                    self.assertIn(legend, positions)

    def test_older_snapshots_match_devices_to_their_own_physical_ports(self):
        data = example()
        data["modules"][0].update(name="writer", hostname="writer")
        data["host"] = "local-box"
        data["recognized"][0].update(name="verified", port="assembly-west Gi0/32")
        data["unknown"] = [dict(name="camera", port="assembly-west Gi0/33", state="unrecognized"),
                           dict(name="wrong-box", port="Gi0/38", state="unrecognized")]
        data["switches"][1]["blocks"] = [bank("Copper", 2, [
            port(31, "module", ["s1"]), port(32, "recognized"), port(33, "unrecognized"), port(34, "uplink", server=True),
            port(35, "uplink", station={"host": "peer-box"}), port(36, "free"),
            port(37, "unrecognized", endpoint=None), port(38, "unrecognized"),
        ])]
        data["unknown"].append(dict(name="obsolete", port="assembly-west Gi0/37", state="unrecognized"))
        _, items = self.draw(data, view="desk")
        positions = dict(items)
        dy = positions["32"][1] - positions["31"][1]
        for n, name in ((31, "writer"), (32, "verified"), (33, "camera"), (34, "local-box"),
                        (35, "peer-box"), (36, "—"), (37, "No ID"), (38, "No ID")):
            x, y = positions[str(n)]
            self.assertIn(name, [word for word, (px, py) in items if px == x and y < py < y+dy])

        # Identical hostnames and interface names on two switches make even a
        # fully qualified legacy observation ambiguous. Neither tile claims it.
        data["switches"][0] = switch("assembly-west", bank("Copper", 1, [port(33, "unrecognized")]))
        _, items = self.draw(data, view="desk")
        x, y = dict(items)["33"]
        self.assertNotIn("camera", [word for word, (px, py) in items if px == x and y < py < y+dy])

    def test_switch_serials_identify_the_roster_and_the_selected_saved_panel(self):
        data = example()
        data["switches"][0].update(name="assembly", serial="EAST0042")
        data["switches"][1].update(name="assembly", serial="WEST0088", answering=False)
        _, items = self.draw(data, view="desk")
        captions = [word for word, _ in items]
        self.assertEqual(captions.count("S/N EAST0042"), 1)
        self.assertEqual(captions.count("S/N WEST0088"), 2)
        self.assertIn("S/N unavailable", captions)
        self.assertTrue(any("last known ports" in word for word in captions))
        roster = [xy for word, xy in items if word in ("S/N EAST0042", "S/N WEST0088", "S/N unavailable")]
        self.assertEqual(roster[0][1], roster[1][1])
        self.assertEqual(roster[1][1], roster[2][1])
        self.assertGreater(roster[3][1], roster[0][1])

    def test_long_endpoint_keeps_its_additional_device_count_inside_the_tile(self):
        data = example()
        ports = [port(i, endpoint=("west-assembly-cell-" * 5 + " +12") if i == 41 else None) for i in range(41, 57)]
        data["switches"] = [switch("assembly", bank("Copper", 2, ports))]
        first, items = self.draw(data, now=0, view="desk")
        moving, _ = self.draw(data, now=4, view="desk")
        positions = dict(items)
        x, y = positions["41"]
        other_x = positions["43"][0]
        other_y = positions["42"][1]
        tail = [(word, xy) for word, xy in items if x <= xy[0] < other_x and y < xy[1] < other_y]
        self.assertTrue(any(word == "+12" for word, _ in tail))
        count_x, count_y = positions["+12"]
        badge = (count_x-20, count_y-7, count_x+1, count_y+7)
        name = (x, count_y-7, count_x-24, count_y+7)
        self.assertTrue(first.crop(name).tobytes() != moving.crop(name).tobytes())
        self.assertEqual(first.crop(badge).tobytes(), moving.crop(badge).tobytes())

    def test_overflowing_hostname_moves_by_pixels_and_keeps_the_other_device_badge_fixed(self):
        def tile(at):
            p = self.graphics.Painter((1600, 900), "warm", at)
            self.graphics.endpoint_label(p, 20, 30, "wlanpi-warehouse-rack12-sensor-a17 +3", 106, "fg")
            return p.image.crop((20, 16, 126, 44))

        a, b = tile(3.00), tile(3.08)
        self.assertTrue(a.tobytes() != b.tobytes())
        self.assertEqual(a.crop((88,0,106,28)).tobytes(), b.crop((88,0,106,28)).tobytes())
        self.assertTrue(any(a.crop((8+dx,0,75+dx,28)).tobytes() == b.crop((8,0,75,28)).tobytes()
                            for dx in (1,2,3)), "The same glyphs should advance by pixels within their lane")

    def test_dense_bank_keeps_measured_watts_and_unknown_identity_readable(self):
        ports = [port(i, link_up=True, speed=1000, poe_state="delivering", watts=23.7) for i in range(21, 41)]
        ports[0]["endpoint"] = "west-repair-station"
        data = {"switches": [switch("assembly", bank("Copper", 2, ports))]}
        first, items = self.draw(data, now=0, view="desk")
        moving, _ = self.draw(data, now=3, view="desk")
        labels = [word for word, _ in items]
        self.assertEqual(labels.count("23.7 W"), 20)
        self.assertEqual(labels.count("No ID"), 19)
        x, y = dict(items)["21"]
        lower = dict(items)["22"][1]
        name_row = (x, y+9, dict(items)["23"][0]-12, lower-10)
        self.assertTrue(first.crop(name_row).tobytes() != moving.crop(name_row).tobytes())

    def test_station_hostnames_fit_an_eight_column_faceplate_without_scrolling(self):
        ports = [port(i) for i in range(21, 37)]
        ports[0].update(endpoint="lab-station-1")
        ports[1].update(endpoint="lab-station-2")
        data = {"switches": [switch("assembly", bank("Copper", 2, ports))]}
        _, items = self.draw(data, size=(1920, 1080), view="desk")
        self.assertIn("lab-station-1", dict(items))
        self.assertIn("lab-station-2", dict(items))

    def test_bank_pages_follow_serial_even_if_other_switch_uses_same_interface(self):
        data = example()
        cards = data["modules"] + data["recognized"]
        cards[0] = dict(cards[0], port="Gi0/1")  # older short label
        overview = self.graphics.PortMap(data["switches"], cards, columns=2)
        self.assertEqual(overview.owner(cards[0]), (1, "Gi0/1"))
        self.assertEqual(overview.owner(cards[1]), (1, "Gi0/2"))  # marked sensors have no serial
        self.assertEqual(overview.page(cards[0], 2, 0, 0), 1)
        self.assertEqual(overview.page(cards[0], 2, 0, 1), 2)  # next bank of the same switch
        self.assertEqual(overview.page(cards[0], 2, 0, -1), 0)
        self.assertEqual([overview.page(cards[0], 2, tick, 0) for tick in range(2, 6)], [0, 1, 2, 3])
        self.assertEqual(overview.pages[-1], (2, []))  # offline without a cached panel is still navigable
        self.assertIsNone(overview.owner({"port": "Gi0/1"}))

    def test_large_physical_banks_keep_every_column_in_wire_order(self):
        ports = [port(i) for i in range(1, 49)]
        uplinks = [port(i) for i in range(49, 53)]
        plan = self.graphics.PortMap([switch("c9200", bank("RJ45", 2, ports), bank("SFP+", 1, uplinks))], [], columns=5)
        numbers = [p["number"] for _, groups in plan.pages for group in groups for p in group["ports"]]
        self.assertEqual(numbers, list(range(1, 53)))
        for _, groups in plan.pages:
            for group in groups:
                if group["label"] == "RJ45":
                    self.assertEqual(group["rows"], 2)
                    self.assertEqual(group["ports"][0]["number"] % 2, 1)
        self.assertEqual({plan.page(None, 0, t, 0) for t in range(len(plan.pages))}, set(range(len(plan.pages))))

    def test_lower_uplinks_and_odd_even_rows_have_physical_positions(self):
        data = example()
        data["switches"] = [switch("rack", bank("PANEL", 2, [port(n) for n in (11, 12, 13, 14)]),
                                  bank("SFP", 1, [port(81), port(82)]))]
        _, items = self.draw(data, view="desk")
        xy = dict(items)
        self.assertEqual(xy["11"][0], xy["12"][0])
        self.assertEqual(xy["11"][1], xy["13"][1])
        self.assertEqual(xy["12"][1], xy["81"][1])
        self.assertLess(xy["11"][0], xy["13"][0])
        self.assertLess(xy["13"][0], xy["81"][0])

    def test_auto_diagnostics_show_saved_evidence_and_action(self):
        data = example()
        m = data["modules"][0]
        m.update(phase="failed", state="fail", percent=None, detail="Writing stopped",
                 diagnostics=dict(source="USB", received_at=70, title="Write I/O error", facts=dict(temperature_mc=48100, throttled="0x0"),
                                  issues=[{"evidence":"mmcblk0 reported an I/O error", "advice":"Inspect storage"}]))
        _, items = self.draw(data)
        labels = "\n".join(text for text, _ in items)
        for word in ("Device diagnostics", "Hardware", "Write I/O error", "Saved snapshot", "USB", "32s ago",
                     "48.1", "0x0", "Reported steps", "mmcblk0 reported an I/O error", "Inspect storage", "Failed"):
            self.assertIn(word, labels)
        m.update(state="stale", phase="write")
        labels = "\n".join(text for text, _ in self.draw(data)[1])
        self.assertIn("No report", labels)

    def test_flow_follows_selected_card_and_makes_room_for_six_on_ultrawide(self):
        data = example()
        m = data["modules"][0]
        data["modules"] = [dict(m, name="Device-%d" % n, serial=str(n)) for n in range(7)]
        data["recognized"] = []
        for size, count, selected, visible in [((1600, 900), 4, 0, range(4)), ((1600, 670), 6, 0, range(6)),
                                              ((1600, 670), 1, 6, [6])]:
            _, items = self.draw(data, size, view="flow", selected=selected)
            self.assertEqual([label for label, _ in items if label.startswith("Device-")],
                             ["Device-%d" % n for n in visible])

    def test_palette_auto_missing_snapshot_and_footer_on_smaller_screen(self):
        for style, want in (("warm", "#161412"), ("neutral", "#121214"), ("warm-light", "#f8f5f0"), ("neutral-light", "#f5f5f7")):
            data = {"hdmi_auto_mode": True, "display_auto_mode": "light" if "light" in style else "dark"}
            frame, items = self.draw(data, (1024, 576), palette=style.split("-")[0])
            self.assertEqual(frame.getpixel((1, 120)), tuple(bytes.fromhex(want[1:])))
            labels = "\n".join(label for label, _ in items)
            self.assertIn("Data stale", labels)
            self.assertIn("No managed switch", labels)
            self.assertIn("Alt+F8", labels)
            self.assertTrue(all(0 <= y < 576 for _, (_, y) in items))

    def test_unrecognized_quiet_and_verified_sensor_remain_distinct(self):
        data = example()
        data["modules"] = []
        data["unknown"] = [dict(state=s, name=s + " host", port="assembly-west Gi0/5", detail="LLDP data") for s in ("unrecognized", "quiet")]
        _, items = self.draw(data, view="desk")
        labels = "\n".join(label for label, _ in items)
        for word in ("Our sensor", "Unrecognized", "Link only", "LLDP data", "wlanpi-da2", "boot 1 of 20"):
            self.assertIn(word, labels)

    def test_shared_long_switch_name_keeps_switch_number_and_interface_together_on_the_card(self):
        data = example()
        prefix = "north-industrial-assembly-"*6
        data["switches"][0]["name"] = prefix+"east"
        data["switches"][1]["name"] = prefix+"west"
        data["modules"][0]["port"] = prefix+"west Gi0/1"
        for view in ("desk", "flow", "engineer"):
            with self.subTest(view=view):
                _, items = self.draw(data, size=(1280, 720), view=view)
                self.assertTrue(any("#2 · Gi0/1" in label for label, _ in items),
                                "The interface and its unique switch number must stay visible while the long name scrolls")

    def test_no_saved_panel_is_identified_in_offline_warning(self):
        _, items = self.draw()
        self.assertTrue(any("assembly-spare offline" in label and "no saved ports" in label for label, _ in items))

    def test_partial_module_and_unnamed_switch_do_not_stop_rendering(self):
        data = {"modules":[{"serial":"s1"}], "switches":[switch(None, bank("Copper", 2, [port(1, "module", ["s1"])]))]}
        _, items = self.draw(data)
        self.assertIn("Device card", [text for text, _ in items])

    def test_route_displays_verbatim_ssid_and_distinguishes_signal_and_address(self):
        data = example()
        data["station"]["uplink"]["ssid"] = "Assembly / Floor 2"
        _, items = self.draw(data)
        labels = [text for text, _ in items]
        self.assertIn('Wi-Fi "Assembly / Floor 2"', labels)
        self.assertIn("-51 dBm", labels)
        self.assertIn("10.2.3.4", labels)

    def test_protected_first_admin_card_only_displays_setup_fields(self):
        frame, items = self.draw(setup={"code": "ABCD2345EFGH6723", "host": "192.0.2.5", "url": "http://192.0.2.5/setup"})
        labels = "\n".join(label for label, _ in items)
        for word in ("http://192.0.2.5/setup", "ABCD", "2345", "EFGH", "6723", "Set up this station"):
            self.assertIn(word, labels)
        for secret in ("assembly-west", "wlanpi-fd3", "Lab 78", "Line A"):
            self.assertNotIn(secret, labels)

    def test_rescue_uses_integer_black_white_modules_with_unbroken_quiet_zone(self):
        matrix = [[False] * 29 for _ in range(29)]
        for y in range(4, 25):
            for x in range(4, 25):
                matrix[y][x] = (x + y) % 2 == 0
        frame, items = self.draw(size=(1280,720), access=dict(matrix=matrix, ssid="Field rescue", password="ALongExamplePass"), remaining=37)
        labels = "\n".join(label for label, _ in items)
        for word in ("Field rescue", "ALongExamplePass", "37", "Scan"):
            self.assertIn(word, labels)
        self.assertNotIn("wlanpi-fd3", labels)
        # Find the pure black QR ink (the dark theme otherwise uses tinted colors).
        mask = frame.convert("L").point(lambda value: 255 if value == 0 else 0)
        x, y, right, bottom = mask.getbbox()
        step = (right-x)//21
        self.assertGreaterEqual(step, 5)
        self.assertEqual((right-x, bottom-y), (step*21, step*21))
        for row in range(29):
            for col in range(29):
                region = frame.crop((x+(col-4)*step, y+(row-4)*step, x+(col-3)*step, y+(row-3)*step))
                channel = 0 if matrix[row][col] else 255
                self.assertEqual(region.getextrema(), ((channel, channel),)*3)

    def test_second_ordinary_frame_reuses_glyph_shapes_and_text_measurements(self):
        draw = self.graphics.render_frame
        data, size = example(), (1600,900)
        first = draw(board,data,size,now=99)
        calls = []
        original_mask = ImageFont.FreeTypeFont.getmask2
        original_width = ImageFont.FreeTypeFont.getlength
        def mask(font, value, *args, **kwargs):
            calls.append("mask")
            return original_mask(font,value,*args,**kwargs)
        def width(font, value, *args, **kwargs):
            calls.append("width")
            return original_width(font,value,*args,**kwargs)
        with patch.object(ImageFont.FreeTypeFont,"getmask2",mask), patch.object(ImageFont.FreeTypeFont,"getlength",width):
            second = draw(board,data,size,now=99)
        self.assertEqual(first.tobytes(),second.tobytes())
        self.assertEqual(calls.count("mask"),0,"An unchanged label must not rasterize its font again")
        self.assertEqual(calls.count("width"),0,"An unchanged column must not measure its text again")

    def test_protected_text_is_rasterized_and_measured_afresh_on_each_reveal(self):
        matrix = [[False]*29 for _ in range(29)]
        cases = [
            (dict(access={"ssid":"Rescue private", "password":"a-password-12345", "matrix":matrix}, remaining=30),
             {"Rescue private","a-password-12345"}, {"Rescue private"}),
            (dict(setup={"url":"http://setup.local/setup","code":"ABCD2345EFGH6723"}),
             {"ABCD","2345","EFGH","6723"}, {"http://setup.local/setup"}),
        ]
        for options, secrets, measured in cases:
            with self.subTest(fields=secrets):
                draw = self.graphics.render_frame
                draw(board,{},(1600,900),now=100,**options)
                masks, lengths = set(), set()
                original_mask = ImageFont.FreeTypeFont.getmask2
                original_width = ImageFont.FreeTypeFont.getlength
                def mask(font, value, *args, **kwargs):
                    masks.add(value)
                    return original_mask(font,value,*args,**kwargs)
                def width(font, value, *args, **kwargs):
                    lengths.add(value)
                    return original_width(font,value,*args,**kwargs)
                with patch.object(ImageFont.FreeTypeFont,"getmask2",mask), patch.object(ImageFont.FreeTypeFont,"getlength",width):
                    draw(board,{},(1600,900),now=100,**options)
                self.assertTrue(secrets <= masks)
                self.assertTrue(measured <= lengths)

    def test_completed_cycle_caption_does_not_repeat_the_systemd_state_as_run_status(self):
        data = example()
        data["modules"][0].update(phase="done", state="ok", os_state="running", percent=None)
        _, items = self.draw(data,view="flow")
        labels = [text for text,_ in items]
        self.assertIn("Done",labels)
        self.assertIn("Cycle time",labels)
        self.assertNotIn("Cycle time · running",labels)

    def test_only_measured_management_wifi_uses_signal_quality_colors(self):
        data = example()
        data["station"]["links"].append(dict(name="wlan0",kind="wifi",role="other",up=True,ssid="Standby"))
        for dbm, expected in ((-67,"#8ad4ad"), (-68,"#ebbf72"), (-75,"#ebbf72"), (-76,"#faa69a"), (None,"#b6a99b")):
            with self.subTest(dbm=dbm):
                data["station"]["uplink"]["signal"] = dbm
                image, items = self.draw(data)
                samples = [(xy, text) for text,xy in items if text == "signal ?" or text.endswith(" dBm")]
                self.assertEqual(len(samples),2)
                for ((x,y), _), ink in zip(samples,(expected,"#b6a99b")):
                    swatch = image.crop((x,y-11,x+52,y+10))
                    self.assertIn(tuple(bytes.fromhex(ink[1:])),set(swatch.getdata()))
        data["station"]["links"][-1]["signal"] = -60
        image, items = self.draw(data)
        x,y = dict(items)["-60 dBm"]
        self.assertIn((182,169,155),set(image.crop((x,y-11,x+52,y+10)).getdata()))


if __name__ == "__main__":
    unittest.main()
