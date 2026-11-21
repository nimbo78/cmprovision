"""Pixel scrolling for overflowing device names on the station displays."""
from __future__ import annotations

from collections import OrderedDict
from dataclasses import dataclass
from functools import lru_cache
from math import ceil
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from PIL import Image

FRAME_SECONDS = 1 / 20
HOLD_SECONDS = 1.5
EM_PER_SECOND = 1.5
MAX_PAUSED_LABELS = 1024


@lru_cache(maxsize=512)
def _metrics(value, face, baseline):
    left, top, right, bottom = face.getbbox(value, anchor=baseline)
    left = min(0, left)
    return left, top, max(ceil(face.getlength(value)), right) - left, bottom


@dataclass
class Lane:
    mask: Image.Image
    started: float
    speed: float
    xy: tuple[int, int]
    background: Image.Image
    ink: str | tuple[int, int, int]
    offset: int = -1

    def position(self, now):
        distance = self.mask.width - self.background.width
        travel = distance / self.speed
        phase = max(0, now - self.started) % (2 * HOLD_SECONDS + travel)
        if phase < HOLD_SECONDS:
            return 0, HOLD_SECONDS - phase
        if phase < HOLD_SECONDS + travel:
            return min(distance, int((phase - HOLD_SECONDS) * self.speed)), FRAME_SECONDS
        return distance, 2 * HOLD_SECONDS + travel - phase

    def paint(self, image, now):
        offset, _ = self.position(now)
        if offset == self.offset:
            return False
        self.offset = offset
        width, height = self.background.size
        image.paste(self.background, self.xy)
        window = self.mask.crop((offset, 0, offset + width, height))
        image.paste(self.ink, self.xy, window)
        return True


class Marquee:
    """Keep each visible name's clock across data refreshes, reset on a change of view."""

    def __init__(self):
        self.scene = None
        self.slide = 0
        self.image = None
        self.lanes = {}
        self.previous = {}
        self.paused_labels = OrderedDict()

    def begin(self, image, now, scene, *, start=None, slide=0):
        continuing = scene == self.scene and slide >= self.slide
        self.previous = self.lanes if continuing else {}
        self.resuming = continuing and slide != self.slide
        if self.resuming:
            # Count only the time the text was visible across automatic slides.
            for key, lane in self.previous.items():
                self.paused_labels[key] = now - lane.started
            while len(self.paused_labels) > MAX_PAUSED_LABELS:
                self.paused_labels.popitem(last=False)
        elif not continuing:
            self.paused_labels.clear()
        self.slide = slide
        self.lanes = {}
        self.image, self.now, self.scene = image, now, scene
        self.started = now if start is None else start

    def label(self, xy, value, face, ink, width, anchor="lt"):
        """Paint only an overflow. Let the caller render fitting text normally."""
        if width <= 0:
            return True
        # Bound the viewport to both advance and ink (including glyph overhang).
        baseline = "l" + anchor[1]
        left, top, extent, bottom = _metrics(value, face, baseline)
        if extent <= width or bottom <= top:
            return False
        x, y = xy
        if anchor[0] == "r":
            x -= width
        elif anchor[0] == "m":
            x -= width // 2
        key = (x, y, width, value, face, baseline)
        prior = self.previous.get(key)
        elapsed = self.paused_labels.pop(key, 0)
        if prior is None:
            from PIL import Image, ImageDraw
            mask = Image.new("L", (extent, bottom - top))
            ImageDraw.Draw(mask).text((-left, -top), value, font=face, fill=255, anchor=baseline)
            started = self.started - (elapsed if self.resuming else 0)
        else:
            mask, started = prior.mask, prior.started
        origin = (x, y + top)
        background = self.image.crop((x, y + top, x + width, y + bottom))
        lane = Lane(mask, started, EM_PER_SECOND * face.size, origin, background, ink)
        self.lanes[key] = lane
        lane.paint(self.image, self.now)
        return True

    def advance(self, now):
        self.now = now
        changed = False
        for lane in self.lanes.values():
            if lane.paint(self.image, now):
                changed = True
        return changed

    def delay(self, now, idle):
        if not self.lanes:
            return idle
        until_step = min(lane.position(self.now)[1] for lane in self.lanes.values())
        deadline = self.now + max(FRAME_SECONDS, until_step)
        return min(idle, max(0.0, deadline - now))
