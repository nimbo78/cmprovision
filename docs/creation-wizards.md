> **Language / Язык:** [English](creation-wizards.md) | [Русский](ru/creation-wizards.md)

# Create projects, labels and scripts with a wizard

[Operations](operations.md) · [Configuration transfer](configuration-transfer.md)

Projects, Labels and Scripts each offer **Create with wizard**. **Copy with wizard** starts from an existing row and creates a distinct object. The ordinary **Add** and **Edit** forms remain available.

Each wizard shows its steps, checks the current step before continuing and provides Back, Cancel and a final summary. Back retains entered values. Save is available only at the summary and validates the complete definition again. Escape cancels the wizard.

## Projects

1. Choose the name and whether this project writes sensors or configures a new cmprovision station.
2. Select an image and destination storage. CM4 eMMC normally uses `/dev/mmcblk0`. A sensor project may omit its image to run scripts or update EEPROM; station setup requires an image.
3. Choose verification, OS check, resumable image pieces, re-provision access, mark duration and an optional EEPROM update.
4. Select scripts and first-boot packages. First-boot scripts and packages require OS check. Lower script priorities run first.
5. Choose a label and when to print it. Printing with the installed host name requires the OS check.
6. For a station project, set its Linux user, password or SSH keys, and optional management Wi-Fi. These settings need a compatible [station image](station-project-setup.md).
7. Review the summary, then save. Select **Set as active project** only when you want to activate it immediately.

New guided projects start with Verify, OS check and Split enabled, and activation disabled. Re-provision stays off. The ordinary complete form retains its existing defaults.

Verify checks the written bytes against the image hash. OS check boots the written system and runs its first-boot work. Split lets an interrupted write continue from the last completed piece. Re-provision installs the station's restricted access agent; the mark duration controls how many boots advertise a verifiable provisioning mark. The field descriptions explain their requirements.

Copying a project keeps its dependencies and script order. The copy receives a new name and ID and remains inactive until explicitly selected. Station passwords stay private while navigating Back and Next; a saved Wi-Fi password is retained only for the same SSID.

## Labels

Choose a name, output extension and printer method, then configure its command or FTP connection. Next, edit the template and inspect an escaped sample expansion using the same sample values as test printing:

| Variable | Sample |
| --- | --- |
| `$serial` | `1000000012345678` |
| `$mac` | `11:22:33:44:55:66` |
| `$hostname` | `wlanpi-a1b` |
| `$provisionboard` | `sw01 Gi0/3` |
| `$switch` | `sw01` |
| `$port` | `Gi0/3` |

Copy a working label to preserve its printer language and layout. The sample demonstrates substitution; it does not render printer commands as a graphical label.

At the summary, **Print test label** explicitly contacts the configured printer or runs the command. **Save** stores the definition without printing. A copied FTP password is retained privately only for the same host and user. Changing either requires a new password.

## Scripts

Choose a name, execution phase, priority/background behaviour and script body. A new script starts with `#!/bin/sh` and `set -e`.

- **Pre-install:** runs in the utility OS before the image write.
- **Post-install:** runs after writing, with the image partitions available as `$PART1` and `$PART2`.
- **First boot:** runs as root in the installed OS during its check; a non-zero exit fails that check. These scripts always run sequentially.

Back preserves the script body. Save normalises line endings and creates the definition; it does not execute it. Copy preserves the source body, phase and priority while creating a new record.

## Private state and transfer

Passwords entered in guided forms are action arguments and are retained encrypted in the signed-in user's browser session, outside the Livewire snapshot. Private state expires after 24 hours and is removed on Save or Cancel. Keep using the same signed-in session while completing a wizard; a page reload does not reopen its unfinished form.

After saving, open **Settings → Status → Backup → Selective import / export** and select the object to [transfer its configuration](configuration-transfer.md). The same definition checks serve wizards, full forms and import. Transfer includes required dependencies, offers protected secrets separately and leaves imported projects inactive.
