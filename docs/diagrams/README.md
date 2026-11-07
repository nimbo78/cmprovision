# Architecture and Workflow Diagrams

Interactive and static visual diagrams for cmprovision, generated with [`archify`](https://github.com/tt-a1i/archify).

## Stand Architecture & Host Services

- **Interactive HTML**:
  - English: [`stand-architecture.html`](stand-architecture.html) — Standalone interactive viewer with pan, zoom, dark/light theme switching, and subsystem boundaries.
  - Русский: [`stand-architecture.ru.html`](stand-architecture.ru.html) — Интерактивный просмотрщик с зумом, темами оформления и границами подсистем.
- **Rendered PNG Previews**:
  - English: [`stand-architecture.png`](stand-architecture.png) (2372 × 1088 Retina PNG).
  - Русский: [`stand-architecture.ru.png`](stand-architecture.ru.png) (2372 × 1080 Retina PNG).

### Subsystems Covered
1. **Management & Uplink (Wi-Fi 6 wlan0)**: Provisioning Operator browser UI, System Administrator SSH/CLI maintenance, and NetBox DCIM bulk inventory integration.
2. **Provisioner Host (Raspberry Pi OS Trixie / Debian 13)**: Nginx reverse-proxy, Laravel 13 core running in dedicated PHP-FPM 8.4 pool (`[cmprovision]`), SQLite 3 WAL database with `IMMEDIATE` transactions, dual systemd queue workers (`cmprovision-queue` and `cmprovision-queue-images`), Dnsmasq (DHCP/TFTP), RpiBoot daemon, HDMI wallboard kiosk, and DataBackup online snapshots.
3. **Stand Network (eth0 172.20.0.0/16, isolated)**: Managed 48-port PoE switch controlled via SNMP v2c (UDP 161), CM4 target mezzanine boards on carrier boards, and network thermal label printer (raw TCP/9100).

## Provisioning Workflow

- **Interactive HTML**:
  - English: [`provisioning-flow.html`](provisioning-flow.html) — Standalone interactive viewer with pan, zoom, dark/light theme switching, and step traces.
  - Русский: [`provisioning-flow.ru.html`](provisioning-flow.ru.html) — Интерактивный просмотрщик с зумом, темами оформления и анимацией переходов.
- **Rendered PNG Previews**:
  - English: [`provisioning-flow.png`](provisioning-flow.png) (2372 × 1838 Retina PNG).
  - Русский: [`provisioning-flow.ru.png`](provisioning-flow.ru.png) (2372 × 1962 Retina PNG).

### Architecture Highlights Covered
1. **Intake & Discovery**: Operator project selection in Livewire 3 UI ➔ SNMP v2c PoE power-cycle to target switch ports.
2. **PXE & Memory Boot**: DHCP Offer & TFTP kernel bootcode from `dnsmasq` over `eth0` (`172.20.0.1/16`) ➔ Utility OS micro-Linux in RAM (with USB OTG `rpiboot` fallback).
3. **Flashing & Recovery**: Device registration at `/scriptexecute` ➔ 512MB chunked image streaming from Nginx ➔ eMMC flashing (with chunk-level network resumption).
4. **Verification & First Boot**: SHA256 disk checksum verification ➔ EEPROM bootloader upgrade ➔ Target OS first boot self-test.
5. **Handoff & Integrations**: Batch dashboard marked done ➔ Raw ZPL thermal label printing (TCP/9100) ➔ NetBox DCIM bulk CSV generation ➔ Telegram Bot progress notification.
