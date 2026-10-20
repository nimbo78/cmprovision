#!/usr/bin/env python3
"""Pack the CM4's USB boot files separately from its ordinary TFTP directory."""
import argparse
import os
from pathlib import Path
import shutil
import subprocess
import tempfile


def payload_files(source: Path) -> list[str]:
    kernels = [name for name in ("kernel8.img", "kernel.img") if (source / name).is_file()]
    if len(kernels) != 1:
        raise ValueError("expected exactly one utility kernel")
    return [
        "config.txt", "cmdline.txt", kernels[0], "scriptexecute.img", "start4.elf", "fixup4.dat",
        "bcm2711-rpi-cm4.dtb", "bcm2711-rpi-4-b.dtb", "bcm2711-rpi-400.dtb",
        "overlays/dwc2.dtbo", "overlays/spi-gpio40-45.dtbo",
    ]


def verify(source: Path) -> None:
    usb = source / "usb"
    if (usb / "config.txt").read_bytes() != b"boot_ramdisk=1\n":
        raise ValueError("USB bootstrap must load the boot ramdisk")
    if (usb / "bootfiles.bin").read_bytes() != (source / "bootfiles.bin").read_bytes():
        raise ValueError("USB second stage differs from the selected utility OS")
    for name in payload_files(source):
        data = subprocess.check_output(["mcopy", "-i", str(usb / "boot.img"), "::/" + name, "-"])
        if data != (source / name).read_bytes():
            raise ValueError("USB payload differs from utility OS: " + name)
    print(f"USB payload matches the utility OS: {source}")


def build(source: Path) -> None:
    source = source.absolute()
    if source.is_symlink() or not source.is_dir():
        raise ValueError("utility OS must be an ordinary directory")
    output = source / "usb"
    names = {"bootfiles.bin", "config.txt", "boot.img"}
    if output.is_symlink() or (output.exists() and not output.is_dir()):
        raise ValueError("USB output must be an ordinary directory")
    if output.exists() and any(p.name not in names or p.is_symlink() or not p.is_file() for p in output.iterdir()):
        raise ValueError("unexpected files in USB output directory")

    files = payload_files(source)
    for name in [*files, "bootfiles.bin"]:
        path = source / name
        if path.is_symlink() or path.parent.is_symlink() or not path.is_file() or path.stat().st_size == 0:
            raise ValueError("missing or invalid boot file: " + name)

    # At least 65,525 clusters for FAT32, with room for the files and FAT.
    # A regular file in our own staging directory is the only mkfs destination.
    mib = 1024 * 1024
    payload_bytes = sum((source / name).stat().st_size for name in files)
    image_bytes = max(40 * mib, ((payload_bytes * 105 // 100 + mib - 1) // mib + 1) * mib)
    if image_bytes > 180 * mib:
        raise ValueError("USB boot ramdisk exceeds the CM4's 180 MiB limit")

    manifest = source / "MANIFEST"
    metadata = [line for line in manifest.read_text().splitlines()
                if line and not line.startswith(("#", "file="))] if manifest.is_file() else None
    with tempfile.TemporaryDirectory(prefix=".usb-image-", dir=source.parent) as temporary:
        stage = Path(temporary)
        payload = stage / "payload"
        payload.mkdir()
        for name in files:
            target = payload / name
            target.parent.mkdir(exist_ok=True)
            shutil.copyfile(source / name, target)
            # Keep FAT file timestamps independent of the build host's checkout.
            os.utime(target, (315532800, 315532800))
        os.utime(payload / "overlays", (315532800, 315532800))
        image = stage / "boot.img"
        with image.open("xb") as stream:
            stream.truncate(image_bytes)
        subprocess.run(["mkfs.fat", "--invariant", "-F", "32", "-S", "512", "-s", "1",
                        "-f", "1", "-n", "CMPUSB", str(image)], check=True)
        env = dict(os.environ, TZ="UTC")
        subprocess.run(["mcopy", "-s", "-m", "-i", str(image),
                        *map(str, sorted(payload.iterdir())), "::/"], check=True, env=env)
        (stage / "config.txt").write_text("boot_ramdisk=1\n", encoding="ascii")
        shutil.copyfile(source / "bootfiles.bin", stage / "bootfiles.bin")
        output.mkdir(exist_ok=True)
        for name in sorted(names):
            shutil.move(str(stage / name), str(output / ("." + name + ".new")))
            (output / ("." + name + ".new")).chmod(0o644)
            (output / ("." + name + ".new")).replace(output / name)
    verify(source)
    if metadata is not None:
        subprocess.run(["sh", str(Path(__file__).with_name("manifest.sh")), str(source), *metadata], check=True)
    print(f"USB boot: {output} ({image_bytes // mib} MiB FAT32)")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source", type=Path)
    parser.add_argument("--check", action="store_true", help="compare the packed files with the selected utility OS")
    args = parser.parse_args()
    (verify if args.check else build)(args.source)
