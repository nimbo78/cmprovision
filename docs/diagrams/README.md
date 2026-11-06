# Architecture and Workflow Diagrams

Interactive and static visual diagrams for cmprovision, generated with [`archify`](https://github.com/tt-a1i/archify).

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
