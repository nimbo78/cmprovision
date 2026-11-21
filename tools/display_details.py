"""Read-only field lists shared by HDMI and HyperPixel. All fields in older snapshots are optional."""
import math


def record(value):
    return value if isinstance(value, dict) else {}


def entries(value):
    return [row for row in value if isinstance(row, dict)] if isinstance(value, list) else []


def rows(*pairs):
    return [(label, str(value)) for label, value in pairs if value is not None and value != ""]


def numeric(value):
    return isinstance(value, (int, float)) and not isinstance(value, bool) and math.isfinite(value) and value >= 0


def elapsed(value):
    if numeric(value):
        minutes, seconds = divmod(int(value), 60)
        return f"{minutes}:{seconds:02d}"
    return None


def hardware_rows(device):
    hw = record(device.get("hardware"))
    return rows(*[(label, hw.get(key)) for key, label in (
        ("model", "Model"), ("revision", "Revision"), ("ram", "RAM"), ("emmc", "eMMC"),
        ("wifi", "Wi-Fi"), ("sku", "SKU"))])


def has_run(device):
    return device.get("registered") is not False and device.get("state") not in (
        "recognized", "unrecognized", "quiet") and bool(device.get("registered") or device.get("phase") or device.get("serial"))


def run_rows(device):
    if not has_run(device):
        return []
    speed = device.get("speed")
    progress = device.get("percent")
    active = device.get("state") == "run" or (not device.get("state") and device.get("phase") in ("write", "verify"))
    return rows(("Project", device.get("project")), ("Image", device.get("image")),
                ("Phase", device.get("step") or device.get("phase")),
                ("Progress", f"{progress}%" if numeric(progress) else None),
                ("Rate", f"{speed/1048576:.1f} MiB/s" if active and numeric(speed) and speed > 0 else None),
                ("ETA", elapsed(device.get("eta")) if active else None), ("Cycle", elapsed(device.get("cycle"))),
                ("OS check", device.get("os_state")), ("Started", device.get("started")), ("Finished", device.get("finished")))


def diagnostic_rows(device, now):
    report = record(device.get("diagnostics"))
    received = report.get("received_at")
    source = report.get("source")
    stamp = elapsed(max(0, now-received)) if numeric(received) and numeric(now) else None
    result = rows(("Report", " · ".join(str(v) for v in (source, stamp and stamp+" ago") if v)),
                  ("Finding", device.get("cause") or report.get("title")),
                  ("Reason", device.get("reason")), ("Code", device.get("code")),
                  ("Next step", device.get("advice")))
    for issue in entries(report.get("issues"))[:4]:
        for pair in rows(("Finding", issue.get("title")), ("Evidence", issue.get("evidence")), ("Next step", issue.get("advice"))):
            if pair not in result:
                result.append(pair)
    facts = record(report.get("facts"))
    temp = facts.get("temperature_mc")
    result.extend(rows(("Temperature", f"{temp/1000:.1f} °C" if numeric(temp) else None),
                       ("Power flags", facts.get("throttled")), ("Kernel", facts.get("kernel"))))
    return result


def physical_rows(port, switch):
    info = record(port.get("info"))
    macs = info.get("macs") or []
    media = {"copper": "RJ45", "sfp": "SFP", "combo": "RJ45 / SFP"}.get(info.get("media"))
    fitted = info.get("transceiver")
    changed = elapsed(info.get("changed"))
    return rows(("Switch", switch.get("name")), ("Switch S/N", switch.get("serial")),
                ("Interface", port.get("portname") or port.get("name")), ("Alias", info.get("alias")),
                ("Connector", media), ("Transceiver", ("Present" if fitted else "Empty") if isinstance(fitted, bool) else None),
                ("Last link change", changed and changed+" ago"),
                ("PoE class", info.get("poe_class")), ("Learned MACs", info.get("mac_count")),
                *(("MAC", mac) for mac in macs[:8] if isinstance(mac, str)))


def neighbor_rows(port):
    return discovery_rows(entries(record(port.get("info")).get("neighbors")))


def discovery_rows(neighbors):
    result = []
    for neighbor in neighbors:
        result.extend(rows(("Discovery", str(neighbor.get("protocol") or "").upper()), ("Neighbor", neighbor.get("name")),
                           ("Platform", neighbor.get("platform")), ("Remote port", neighbor.get("port")),
                           ("Management IP", neighbor.get("address")), ("Chassis", neighbor.get("chassis")),
                           ("Description", neighbor.get("description"))))
    return result


def observed_rows(device, port):
    mark = record(device.get("flashed_by"))
    boot, boots = mark.get("boot"), mark.get("boots")
    neighbors = entries(record(port.get("info")).get("neighbors"))
    mac = device.get("mac")
    matches = [n for n in neighbors if mac and mac in (n.get("mac"), n.get("port_mac"))]
    if not matches:
        matches = [n for n in neighbors if n.get("name") and n["name"] == device.get("name") and
                   not (mac and (n.get("mac") or n.get("port_mac")))]
    identity = rows(("MAC", device.get("mac")), ("Flashed by", mark.get("station")),
                    ("Signed boot", f"{boot} / {boots}" if boot is not None and boots is not None else None))
    return identity + (discovery_rows(matches) if len(matches) == 1 else [])
