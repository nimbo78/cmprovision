from math import ceil
from pathlib import Path
import sys
import unittest
from PIL import Image, ImageDraw, ImageFont

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "tools"))
from display_marquee import Marquee


class HostnameMarqueeTest(unittest.TestCase):
    def test_full_64_character_name_reaches_both_edges_with_dwell_and_no_bleed(self):
        face = ImageFont.load_default(size=16)
        name = ("wlanpi-room-" + "longname" * 8)[:60] + "-b29"
        self.assertEqual(len(name), 64)
        width, x, y = 93, 7, 4
        left, top, right, bottom = face.getbbox(name, anchor="lt")
        extent = max(ceil(face.getlength(name)), right) - min(left, 0)
        full = Image.new("RGB", (extent, bottom - top), "navy")
        ImageDraw.Draw(full).text((-min(left, 0), -top), name, font=face, fill="white", anchor="lt")
        box = (x, y + top, x + width, y + bottom)
        image = Image.new("RGB", (120, 35), "navy")
        motion = Marquee()
        motion.begin(image, 10, "map")
        self.assertTrue(motion.label((x, y), name, face, "white", width))
        prefix = full.crop((0, 0, width, full.height)).tobytes()
        suffix = full.crop((extent - width, 0, extent, full.height)).tobytes()
        self.assertEqual(image.crop(box).tobytes(), prefix)
        self.assertFalse(motion.advance(11))
        self.assertEqual(motion.delay(11, .25), .25)
        # Measure the actual visible strips, including the unique suffix in the last one.
        end = 11.5 + (extent - width) / (1.5 * 16)
        self.assertTrue(motion.advance(end + 0.1))
        self.assertEqual(image.crop(box).tobytes(), suffix)
        self.assertFalse(motion.advance(end + 1))
        self.assertEqual(image.getpixel((x - 1, y + 5)), (0, 0, 128))
        self.assertEqual(image.getpixel((x + width, y + 5)), (0, 0, 128))
        self.assertTrue(motion.advance(end + 1.6))
        self.assertEqual(image.crop(box).tobytes(), prefix)

    def test_refresh_preserves_progress_and_a_new_name_or_page_starts_at_its_prefix(self):
        face = ImageFont.load_default(size=16)
        motion = Marquee()
        a = "warehouse-east-floor03-radio-sensor-a17"
        b = "warehouse-east-floor03-radio-sensor-b29"

        def draw(at, page, name):
            image = Image.new("RGB", (100, 25), "black")
            motion.begin(image, at, page)
            motion.label((2, 3), name, face, "white", 90)
            return image

        first = draw(0, "map", a).copy()
        self.assertTrue(motion.advance(3))
        moving = motion.image.copy()
        self.assertTrue(first.tobytes() != moving.tobytes())
        self.assertEqual(draw(3, "map", a).tobytes(), moving.tobytes())
        self.assertEqual(draw(3, "map", b).tobytes(), first.tobytes())
        self.assertTrue(motion.advance(6))
        self.assertEqual(draw(6, "details", b).tobytes(), first.tobytes())

    def test_short_name_does_not_schedule_animated_frames(self):
        image = Image.new("RGB", (100, 25))
        motion = Marquee()
        motion.begin(image, 13, "map")
        self.assertFalse(motion.label((2, 2), "wlanpi-d62", ImageFont.load_default(size=14), "white", 90))
        self.assertFalse(motion.advance(16))
        self.assertEqual(motion.delay(16, .25), .25)

    def test_next_frame_accounts_for_time_spent_presenting_the_previous_frame(self):
        motion = Marquee()
        image = Image.new("RGB", (100, 25))
        motion.begin(image, 0, "map")
        motion.label((2, 2), "wlanpi-warehouse-long-hostname", ImageFont.load_default(size=16), "white", 90)
        self.assertTrue(motion.advance(2))
        self.assertAlmostEqual(motion.delay(2.03, .25), .02)
