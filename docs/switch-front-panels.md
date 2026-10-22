# Switch front panels

The **Ports** page can draw a switch's Ethernet connectors in their physical positions, seen from the front.
It selects a verified panel automatically from the model reported by the switch. No setting is needed.

The panel keeps the order, rows and grouping of its port banks. It is a schematic, not a scale drawing of the chassis.
On a narrow screen, scroll the panel sideways;
its banks do not move into different rows. Port colours, filters, selection, Identify and PoE controls work as before.
Console, USB and power connectors are not drawn as network ports.

## Verified panels

| Model | Downlinks | Uplinks |
| --- | --- | --- |
| Cisco WS-C2960CX-8PC-L / WS-C2960CX-8TC-L | Two banks of four RJ45 ports; odd numbers above even numbers | RJ45 9 and 10, then SFP slots 1 and 2, in one row aligned with the lower RJ45 row |
| Cisco C9200L-24P/24T-4G/4X | Two banks of twelve RJ45 ports; odd numbers above even numbers | Four SFP (4G) or SFP+ (4X) slots in one lower row |
| Cisco C9200L-48P/48T-4G/4X | Four banks of twelve RJ45 ports; odd numbers above even numbers | Four SFP (4G) or SFP+ (4X) slots in one lower row |
| MikroTik CRS309-1G-8S+IN | Eight SFP+ slots in one row, followed by `ether1` on the right | All nine interfaces are switched; `ether1` accepts PoE power input and does not supply PoE |

On the 2960CX, the case calls the SFP slots **1** and **2**, while IOS calls those interfaces **Gi0/11** and **Gi0/12**.
The panel uses the case's numbers. The tooltip and port details retain the full interface name; selecting a port or
operating its PoE always uses its original switch ID and SNMP interface index.

References checked for these panels:

- [Cisco Catalyst 3560-CX and 2960-CX product overview](https://www.cisco.com/c/en/us/td/docs/switches/lan/catalyst2960cx_3650cx/hardware/installation/guide/b_2960cx-3560cx_hig/product_overview.pdf), Front Panel, Figure 1.
- [Cisco compact switch data sheet](https://www.cisco.com/c/en/us/products/collateral/switches/catalyst-3560-cx-series-switches/datasheet-c78-733229.html), Figure 1 and the 2960-CX model table.
- [Cisco Catalyst 9200 hardware installation guide](https://www.cisco.com/c/en/us/td/docs/switches/lan/catalyst9200/hardware/install/b-c9200-hig.pdf), Front Panel Components and Uplink Ports.
- [MikroTik CRS309 product sheet](https://cdn.mikrotik.com/web-assets/product_files/CRS309-1G-8SplusIN_200133.pdf), front view on page 1, and [user manual](https://help.mikrotik.com/docs/spaces/UM/pages/17956906/CRS309-1G-8S%20IN).

## Automatic port order

**Automatic port order** means the model has no verified panel, or the reported interfaces do not match one completely.
This includes incomplete SNMP polls, additional interfaces, stacks with multiple members, and unsupported model variants.
Every reported port is still shown using the existing grouping by PoE and connector type. The page does not invent missing
ports or silently hide extra ones.

A similar model name alone is insufficient. In particular, reduced SNMP test fixtures are not hardware specifications.
Huawei and Cisco Business models retain automatic ordering until their particular panel and numbering are verified.
CBS350-8P-2G has copper/SFP combo connectors: drawing both physical connectors as separate ports would incorrectly
duplicate one logical interface. Automatic mode retains the existing connector detection.

New verified models belong in `app/Support/SwitchFrontPanel.php`, with a manufacturer reference and tests for the complete
port set, printed numbering and unchanged interface keys.
