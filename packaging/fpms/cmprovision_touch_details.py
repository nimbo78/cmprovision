"""Port and device drill-downs for the 800 × 480 touch panel."""
import time
from . import display_details as fields
from .cmprovision import identity, text
from .cmprovision_new import STATE
from .cmprovision_touch_style import font, wrap


def facts(menu, pairs, top, empty, per_page=5):
    """Wrap values at their true font width. Page the wrapped lines, including long advice."""
    items = []
    for label, value in pairs:
        items.extend((label if i == 0 else "", line)
                     for i, line in enumerate(wrap(value, font(17), 545)))
    if not items:
        menu.label(39, top+24, empty, 20, "muted", 710)
        return
    menu.header_pager(len(items), per_page, 781, 66)
    count = min(per_page, len(items)-menu.offset)
    menu.card((18, top, 781, top+count*36+8))
    for i, (label, value) in enumerate(items[menu.offset:menu.offset+per_page]):
        y = top+12+i*36
        menu.label(35, y, label, 16, "muted", 166)
        menu.label(209, y, value, 17, width=550)
        if i+1 < count:
            menu.draw.line((34, y+25, 764, y+25), fill=menu.c["line"])


def port(menu, row, switch, link, power):
    menu.title(row.get("portname") or row.get("name"))
    menu.label(24, 116, switch.get("name"), 16, "muted", 377, hostname=True)
    chassis = switch.get("serial")
    if chassis:
        menu.label(418, 116, "S/N "+text(chassis), 16, "muted", 354, "mono")
    if switch.get("answering") is False:
        menu.label(35, 168, "Switch not responding", 22, "warn")
        return
    if menu.section == "physical":
        pairs = [("Ethernet", link[1]), ("PoE", power[1])]
        pairs += fields.physical_rows(row, switch) + fields.neighbor_rows(row)
        station = fields.record(row.get("station")) if row.get("state") != "free" and row.get("link_up") is not False else {}
        pairs += fields.rows(("Station address", station.get("address")), ("Station version", station.get("version")))
        facts(menu, pairs, 146, "No port readings", 7)
        return
    members = menu.port_devices(row)
    if menu.section == "members":
        menu.header_pager(len(members), 4, 781, 66)
        for i, device in enumerate(members[menu.offset:menu.offset+4]):
            box = (18, 148+i*60, 781, 202+i*60)
            menu.card(box)
            menu.label(35, box[1]+6, device.get("hostname") or device.get("name") or identity(device),
                       20, width=605, weight="bold", hostname=True)
            menu.label(35, box[1]+31, device.get("mac") or device.get("serial"), 15, "muted", 605, "mono")
            menu.mark(745, box[1]+17, "next", 16)
            menu.targets.append((box, ("port_device", identity(device))))
        if not members:
            menu.label(35, 168, "Devices no longer present", 20, "muted")
        return
    menu.card((18, 147, 781, 210))
    for x, title, sign, reading in ((34, "Ethernet", "Ports", link), (295, "PoE", "bolt", power)):
        menu.mark(x, 162, sign, 20, reading[2])
        menu.label(x+28, 156, title, 14, "muted", 218)
        menu.label(x+28, 179, reading[1], 17, reading[2], 244, "bold")
    menu.label(625, 171, "Port info", 17, "accent", 114, "bold")
    menu.mark(745, 169, "next", 16)
    menu.targets.append(((18,147,781,210), ("section", "physical")))
    menu.card((18, 221, 781, 400))
    name, role, ink, device = menu.port_identity(row)
    menu.label(36, 236, "Connected device" if len(members) <= 1 else f"{len(members)} devices on this port",
               15, "muted", 435, "bold")
    if role:
        menu.badge(760, 230, role, ink)
    menu.label(36, 265, name, 25, width=570, weight="mono", hostname=True)
    spec = dict(fields.hardware_rows(device or {}))
    if spec:
        caption = " · ".join(str(val) for val in (spec.get("Model"), spec.get("Revision") and
            "rev "+spec["Revision"], spec.get("RAM") and spec["RAM"]+" RAM", spec.get("eMMC") and spec["eMMC"]+" eMMC") if val)
    elif device:
        caption = device.get("detail") or device.get("mac") or ""
    else:
        station = fields.record(row.get("station")) if row.get("state") != "free" and row.get("link_up") is not False else {}
        caption = " · ".join(text(v) for v in (station.get("address"), station.get("version")) if v) or ("PoE is delivering power without carrier" if
            row.get("link_up") is False and row.get("poe_state") == "delivering" else "")
    menu.label(36, 305, caption, 17, "muted", 706)
    if members:
        action = ("section", "members") if len(members)>1 else ("port_device", identity(members[0]))
        menu.button((30,344,769,391), "Choose device" if len(members)>1 else "Device details", action, True)
        menu.targets.append(((18,221,781,341), action))


def device(menu, row):
    menu.title(row.get("hostname") or row.get("name") or identity(row), hostname=True)
    if row.get("state") in STATE:
        ink, caption = STATE[row["state"]]
        menu.badge(659, 77, caption, "muted" if ink == "dim" else ink)
    observation = row.get("state") in ("recognized", "unrecognized", "quiet")
    tabs = ["Overview"]
    if fields.has_run(row):
        tabs.append("Run")
    if not observation or row.get("diagnostics"):
        tabs.append("Diagnostics")
    tab = menu.section if menu.section in tabs else tabs[0]
    for i, title in enumerate(tabs):
        menu.button((18+i*183,125,192+i*183,169), title, ("tab", title), title == tab)
    if tab == "Run":
        facts(menu, fields.run_rows(row), 179, "No run details yet", 6)
    elif tab == "Diagnostics":
        facts(menu, fields.diagnostic_rows(row, time.time()), 179, "No diagnostic report yet", 6)
    elif observation:
        port = menu.observed_port(row)
        pairs = fields.observed_rows(row, port)
        if not pairs or (not port and row.get("detail")):
            pairs += fields.rows(("Observation", row.get("detail")))
        facts(menu, pairs, 179, "No device identity reported", 6)
    else:
        hardware = fields.hardware_rows(row)
        menu.card((18, 181, 380, 400))
        menu.card((393, 181, 781, 400))
        menu.label(35, 195, "Identity", 17, "muted", 325, "bold")
        pairs = fields.rows(("Port", row.get("port")), ("MAC", row.get("mac")), ("Serial", row.get("serial")),
                            ("Cycle", fields.elapsed(row.get("cycle")) or row.get("cycle")))
        for i, (label, value) in enumerate(pairs):
            y = 233+i*39
            menu.label(35,y,label,15,"muted",62)
            menu.label(105,y,value,16,width=259,weight="mono",hostname=True)
        menu.label(409, 195, "Hardware", 17, "muted", 341, "bold")
        for i, (label, value) in enumerate(hardware):
            y = 229+i*28
            menu.label(410,y,label,15,"muted",70)
            menu.label(489,y,value,16,width=274,weight="bold",hostname=True)
        if not hardware:
            menu.label(410, 239, "No hardware report yet", 17, "muted", 338)
