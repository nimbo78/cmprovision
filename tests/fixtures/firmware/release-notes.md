# Raspberry Pi4 bootloader EEPROM release notes

## 2026-10-22: Disable HTTP redirects (latest)

* Improve HTTP status handling
  The code currently only handles specific responses and doesn't treat
  an unexpected response as an error.
* Disable HTTP redirects
  For safety disable HTTP redirects.
  If you change HTTP_HOST you can re-enable redirects with
  HTTP_ALLOW_REDIRECTS=1 in the eeprom config.

## 2026-10-15: 2711: recovery.bin: SDRAM init robustness improvements (latest)

Add some robustness improvements (so far theoretical) to the low-speed memory
init code used in recovery.bin.

## 2026-09-27: Promote 2026-09-23 to the default release (default)

## 2026-09-23: Check MFG version when updating (latest)

* Check target-soc in pieeprom.sig during updates
  Recent versions of rpi-eeprom-digest add a "target-soc" tag.
* recovery: Check MFG version when updating
  If the MFG version of the image to update is less than MFG version in OTP
  then recovery.bin and self update will both fail to write the eeprom update.

## 2026-09-12: Cache the EEPROM config for start.elf (latest)

* 2711: Cache the EEPROM config for start.elf
  Update bootsys to cache a copy of the EEPROM config text.

## 2024-08-14 (recovery.bin) -  Add support for OTP metadata (latest)

* Add support for OTP metadata

## 2024-04-15 - Fix tryboot mode in secure-boot - DEFAULT

* Fix tryboot mode in secure-boot

## 2024-04-15 - Fix tryboot mode in secure-boot - STABLE

* Fix tryboot mode in secure-boot

## Promote 2020-04-16 EEPROM release critical

This undated heading ends the entry before it and is no entry itself.
