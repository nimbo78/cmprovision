> **Language / Язык:** [English](switch-front-panels.md) | [Русский](ru/switch-front-panels.md)

# Switch front panels

The **Ports** page can draw a switch's Ethernet connectors in their physical positions, seen from the front.
It selects a verified panel automatically from the model reported by the switch. No setting is needed.

The schematic preserves port order and spacing between banks. Panels wider than the screen scroll sideways.
Port colours, filters, selection, Identify and PoE controls work as before.
Console, USB and power connectors are not drawn as network ports.

When a switch stops answering, **Ports** shows one line with its name, model and chassis serial when known, and
**Offline**. A configured switch that has never answered appears under its address. Port counts and filters cover
only the switches that are reporting. The page shows a batch verdict once all switches answer. A successful poll
restores the panel and its contribution to the counts automatically.

## Verified panels

| Model | Downlinks | Uplinks |
| --- | --- | --- |
| Cisco WS-C2960CX-8PC-L / WS-C2960CX-8TC-L | Two banks of four RJ45 ports; odd numbers above even numbers | RJ45 9 and 10, then SFP slots 1 and 2, in one row aligned with the lower RJ45 row |
| Cisco C9200L-24P/24T-4G/4X | Two banks of twelve RJ45 ports; odd numbers above even numbers | Four SFP (4G) or SFP+ (4X) slots in one lower row |
| Cisco C9200L-48P/48T-4G/4X | Four banks of twelve RJ45 ports; odd numbers above even numbers | Four SFP (4G) or SFP+ (4X) slots in one lower row |
| Huawei CloudEngine S5735I-S8U4XN-V2 | Bottom row, eight 1G RJ45 ports numbered left to right with a gap between GE4 and GE5 | Top row, four 10G SFP+ cages numbered left to right. Cage 1 aligns with GE3 and cage 4 with GE8 |
| MikroTik CRS309-1G-8S+IN | Eight SFP+ slots in one row, followed by `ether1` on the right | All nine interfaces are switched; `ether1` accepts PoE power input and does not supply PoE |

On the 2960CX, the case calls the SFP slots **1** and **2**, while IOS calls those interfaces **Gi0/11** and **Gi0/12**.
The panel uses the case's numbers. The tooltip and port details retain the full interface name; selecting a port or
operating its PoE always uses its original switch ID and SNMP interface index.

The web, HDMI, HyperPixel and 1.44-inch Waveshare maps show the Huawei on its side, rotated 90° counterclockwise from its upright mounting position. On the Waveshare, moving the joystick selects a socket and shows its full interface name below the map.
The chassis reports the S5735I family. Its main board supplies the exact model, and cmprovision matches their
serials. The panel requires all eight `GE1/0/1–8` and all four `10GE1/0/1–4` interfaces, including empty cages.
On V600R022C10SPC500 with patch V600R022SPH180, our switch supplied power but returned no PoE readings through SNMP.
Use `display poe power-state interface GE1/0/8` in the console to check that port.

References checked for these panels:

- [Cisco Catalyst 3560-CX and 2960-CX product overview](https://www.cisco.com/c/en/us/td/docs/switches/lan/catalyst2960cx_3650cx/hardware/installation/guide/b_2960cx-3560cx_hig/product_overview.pdf), Front Panel, Figure 1.
- [Cisco compact switch data sheet](https://www.cisco.com/c/en/us/products/collateral/switches/catalyst-3560-cx-series-switches/datasheet-c78-733229.html), Figure 1 and the 2960-CX model table.
- [Cisco Catalyst 9200 hardware installation guide](https://www.cisco.com/c/en/us/td/docs/switches/lan/catalyst9200/hardware/install/b-c9200-hig.pdf), Front Panel Components and Uplink Ports.
- [MikroTik CRS309 product sheet](https://cdn.mikrotik.com/web-assets/product_files/CRS309-1G-8SplusIN_200133.pdf), front view on page 1, and [user manual](https://help.mikrotik.com/docs/spaces/UM/pages/17956906/CRS309-1G-8S%20IN).
- [Huawei Hardware Center, S5735I-S8U4XN-V2, part 98012066](https://info.support.huawei.com/info-finder/vue/mbTool/en/enterprise/hardwarecenter?partNum=98012066&productModel=S5735I-S8U4XN-V2).

## Automatic port order

**Automatic port order** means the model has no verified panel, or the reported interfaces do not match one completely.
This includes incomplete SNMP polls, additional interfaces, stacks with multiple members, and unsupported model variants.
Every reported port is still shown using the existing grouping by PoE and connector type. The page does not invent missing
ports or silently hide extra ones.

A similar model name alone is insufficient. In particular, reduced SNMP test fixtures are not hardware specifications.
Other Huawei and Cisco Business models retain automatic ordering until their particular panel and numbering are verified.
CBS350-8P-2G has copper/SFP combo connectors: drawing both physical connectors as separate ports would incorrectly
duplicate one logical interface. Automatic mode retains the existing connector detection.

New verified models belong in `app/Support/SwitchFrontPanel.php`, with a manufacturer reference and tests for the complete
port set, printed numbering and unchanged interface keys.
