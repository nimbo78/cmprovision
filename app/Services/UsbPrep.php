<?php

namespace App\Services;

use App\Models\Cm;
use App\Models\Firmware;
use App\Models\Setting;
use RuntimeException;

/**
 * The USB preparation of a board (2.12): while it is on, a board that boots over USB (rpiboot, boot mode 3) is given the
 * bootloader below and nothing else: no image, no OS check, no label, nothing counted in the batch. The board is then
 * unplugged and put on the PoE switch, where it boots from the network, and the stand writes the image there.
 *
 * The mode is the stand's, off by itself after IDLE_MINUTES without a board. The bootloader is its own: a firmware of the
 * store and the settings below (network first by default), not those of the active project.
 */
class UsbPrep
{
    const IDLE_MINUTES = 60;
    const IMAGE = 'uploads/pieeprom-usbprep.bin';

    /* the fleet's headless settings, with the network first: a board with somebody's system on its eMMC goes to the stand
       too (0xf12: the network, then the eMMC, then again) */
    const DEFAULT_SETTINGS = "[all]\nBOOT_UART=0\nWAKE_ON_GPIO=1\nPOWER_OFF_ON_HALT=1\nBOOT_ORDER=0xf12\nENABLE_SELF_UPDATE=1\nNET_INSTALL_AT_POWER_ON=0\n";

    protected function get(string $key): ?string
    {
        return Setting::find($key)?->value;
    }

    protected function put(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public function until(): ?int
    {
        $until = $this->get('usb_prep_until');

        return $until !== null && ctype_digit($until) ? (int) $until : null;
    }

    public function active(): bool
    {
        return ($this->until() ?? 0) > now()->getTimestamp();
    }

    /** Turns it on (the image is made first: a mode that cannot serve is not on). */
    public function start(): void
    {
        if ($problem = $this->problem()) {
            throw new RuntimeException($problem);
        }
        $this->build();
        $this->put('usb_prep_until', (string) now()->addMinutes(self::IDLE_MINUTES)->getTimestamp());
    }

    public function stop(): void
    {
        $this->put('usb_prep_until', '0');
    }

    /** A board came: the hour starts again (a stopped mode stays stopped). */
    public function touch(): void
    {
        if ($this->active()) {
            $this->put('usb_prep_until', (string) now()->addMinutes(self::IDLE_MINUTES)->getTimestamp());
        }
    }

    /** The firmware it writes: the one saved, or the newest of the default channel. */
    public function firmware(): ?string
    {
        return $this->get('usb_prep_firmware') ?: (Firmware::allOfChannel('default')[0]->path ?? null);
    }

    public function settings(): string
    {
        $settings = $this->get('usb_prep_settings');

        return $settings === null || trim($settings) === '' ? self::DEFAULT_SETTINGS : $settings;
    }

    public function imageSha(): ?string
    {
        return $this->get('usb_prep_sha256');
    }

    /** What stops it from serving, or null. */
    public function problem(): ?string
    {
        $firmware = $this->firmware();
        if ($firmware === null) {
            return 'There is no EEPROM firmware in the store: get one on the Firmware page.';
        }
        if (!in_array($firmware, array_map(fn ($f) => $f->path, Firmware::all()), true)) {
            return "The EEPROM firmware '$firmware' is no longer in the store: choose another one in Settings → Provisioning.";
        }

        return null;
    }

    /** Saves the firmware and the settings, and makes the image the boards download. The image comes first: what the store
     *  does not have, or what does not fit the bootloader, is refused with the saved ones left as they were. */
    public function save(string $firmware, string $settings): void
    {
        $settings = EepromImage::normalizeConfig($settings);
        $this->write($firmware, $settings);
        $this->put('usb_prep_firmware', $firmware);
        $this->put('usb_prep_settings', $settings);
    }

    /** The image of the firmware and the settings as they are now (a firmware nobody chose is the newest of the store);
     *  its checksum goes with the script. */
    public function build(): void
    {
        $this->write((string) $this->firmware(), $this->settings());
    }

    protected function write(string $firmware, string $settings): void
    {
        $data = EepromImage::forFirmware($firmware, $settings);
        $target = public_path(self::IMAGE);
        $tmp = $target.'.'.bin2hex(random_bytes(4)).'.part';   // one file per writer: two boards cannot share a half-written one
        if (@file_put_contents($tmp, $data) === false || !rename($tmp, $target)) {
            @unlink($tmp);
            throw new RuntimeException("Error writing '$target'");
        }
        $this->put('usb_prep_sha256', hash('sha256', $data));
        $this->put('usb_prep_built', $this->key($firmware, $settings));
    }

    protected function key(string $firmware, string $settings): string
    {
        return $firmware.'|'.hash('sha256', EepromImage::normalizeConfig($settings));
    }

    /** The image is there for the board that asks, and is the one of the firmware and the settings now: a restore without
     *  the uploads loses the file, and a newer firmware may have come into the store since. */
    public function ensureImage(): void
    {
        if (!is_file(public_path(self::IMAGE)) || $this->imageSha() === null
            || $this->get('usb_prep_built') !== $this->key((string) $this->firmware(), $this->settings())) {
            $this->build();
        }
    }

    /** The boards prepared lately, the last first. */
    public function recent(int $limit = 8)
    {
        return Cm::whereNotNull('usb_prep_at')->orderByDesc('usb_prep_at')->limit($limit)->get();
    }
}
