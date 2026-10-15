<?php
/*
 * nmcli for WifiNetworksTest, which runs tools/wifi-networks under sh. NetworkManager's profiles are keyfiles in
 * $FAKE/keyfiles (where the helper manages) and $FAKE/elsewhere (netplan's /run, say); active connections are lines
 * "uuid:device" in $FAKE/active. Every call is appended to $FAKE/calls. $FAKE/up says how "connection up" ends
 * ("connected 192.168.68.236/24", "connected" without an address, "wrong-password", "not-found", "no-address",
 * "timeout", "failed"); $FAKE/scan is what "device wifi list" prints; $FAKE/nm-gone makes every call fail;
 * $FAKE/mangle-psk makes the password read back differ. Values are printed as nmcli's terse detail view prints them.
 */

const PROPERTIES = [
    'connection.id' => ['connection', 'id'], 'con-name' => ['connection', 'id'],
    'connection.uuid' => ['connection', 'uuid'],
    'connection.interface-name' => ['connection', 'interface-name'],
    'connection.autoconnect-priority' => ['connection', 'autoconnect-priority'],
    'connection.multi-connect' => ['connection', 'multi-connect'],
    '802-11-wireless.ssid' => ['wifi', 'ssid'], 'ssid' => ['wifi', 'ssid'],
    '802-11-wireless.mode' => ['wifi', 'mode'],
    '802-11-wireless.hidden' => ['wifi', 'hidden'],
    '802-11-wireless.mac-address' => ['wifi', 'mac-address'],
    '802-11-wireless-security.key-mgmt' => ['wifi-security', 'key-mgmt'], 'wifi-sec.key-mgmt' => ['wifi-security', 'key-mgmt'],
    '802-11-wireless-security.pmf' => ['wifi-security', 'pmf'], 'wifi-sec.pmf' => ['wifi-security', 'pmf'],
    '802-11-wireless-security.psk-flags' => ['wifi-security', 'psk-flags'], 'wifi-sec.psk-flags' => ['wifi-security', 'psk-flags'],
    '802-11-wireless-security.psk' => ['wifi-security', 'psk'],
    'ipv4.route-metric' => ['ipv4', 'route-metric'], 'ipv6.route-metric' => ['ipv6', 'route-metric'],
];

function read_keyfile(string $file): array
{
    $data = [];
    $section = '';
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line)
    {
        if (preg_match('/^\[(.+)\]$/', $line, $m))
            $data[$section = $m[1]] = $data[$m[1]] ?? [];
        elseif (($at = strpos($line, '=')) !== false)
            $data[$section][substr($line, 0, $at)] = substr($line, $at + 1);
    }
    return $data;
}

function write_keyfile(string $file, array $data): void
{
    $text = '';
    foreach ($data as $section => $values)
    {
        $text .= "[$section]\n";
        foreach ($values as $key => $value)
            $text .= "$key=$value\n";
        $text .= "\n";
    }
    file_put_contents($file, $text);
}

/* uuid => [file, data] of every keyfile */
function profiles(string $fake): array
{
    $profiles = [];
    foreach (['keyfiles', 'elsewhere'] as $dir)
        foreach (glob("$fake/$dir/*.nmconnection") ?: [] as $file)
        {
            $data = read_keyfile($file);
            $profiles[$data['connection']['uuid']] = ['file' => $file, 'data' => $data];
        }
    return $profiles;
}

/* GKeyFile's escapes, as NetworkManager reads a value */
function unescape_value(string $value): string
{
    return preg_replace_callback('/\x5c(.)/', function ($m) {
        return ['s' => ' ', 'n' => "\n", 't' => "\t", 'r' => "\r"][$m[1]] ?? $m[1];
    }, $value);
}

/* nmcli's terse lists escape ':' and the backslash */
function escape_field(string $value): string
{
    return str_replace([chr(92), ':'], [chr(92).chr(92), chr(92).':'], $value);
}

/* a property as nmcli's terse detail view prints it */
function show(array $data, string $property, bool $secrets): string
{
    [$section, $key] = PROPERTIES[$property];
    $value = $data[$section][$key] ?? null;
    switch ($property)
    {
        case '802-11-wireless.hidden':
            return $value === 'true' ? 'yes' : 'no';
        case 'connection.multi-connect':
        case '802-11-wireless-security.pmf':
        case 'connection.autoconnect-priority':
            return $value ?? '0';
        case 'ipv4.route-metric':
        case 'ipv6.route-metric':
            return $value ?? '-1';
        case '802-11-wireless-security.psk':
            if ($value === null)
                return '';
            return $secrets ? unescape_value($value) : '<hidden>';
    }
    return $value ?? '';
}

/* a value given to nmcli, into the keyfile as NetworkManager writes it */
function store(array &$data, string $property, string $value): void
{
    if (!isset(PROPERTIES[$property]))
    {
        fwrite(STDERR, "Error: invalid property '$property'.\n");
        exit(2);
    }
    [$section, $key] = PROPERTIES[$property];
    if ($property === '802-11-wireless.hidden')
        $value = $value === 'yes' ? 'true' : 'false';
    if ($property === 'connection.multi-connect' && $value === 'multiple')
        $value = '3';
    if ($value === '' || (substr($property, -13) === '.route-metric' && $value === '-1'))
        unset($data[$section][$key]);
    else
        $data[$section][$key] = $value;
}

function save_active(string $fake, array $lines): void
{
    file_put_contents("$fake/active", implode('', array_map(function ($line) { return "$line\n"; }, $lines)));
}

$fake = getenv('FAKE');
$args = array_slice($argv, 1);
file_put_contents("$fake/calls", 'nmcli '.implode(' ', $args)."\n", FILE_APPEND);
if (file_exists("$fake/nm-gone"))
    exit(8);

$secrets = false;
$fields = [];
while ($args && $args[0] !== '' && $args[0][0] === '-')
{
    $option = array_shift($args);
    if ($option === '-s')
        $secrets = true;
    elseif ($option === '-f' || $option === '-g')
        $fields = explode(',', array_shift($args));
    elseif ($option === '-w')
        array_shift($args);
}
$active = is_file("$fake/active") ? file("$fake/active", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$profiles = profiles($fake);
$what = ($args[0] ?? '').' '.($args[1] ?? '');

if (($args[0] ?? '') === 'general')
{
    echo "running\n";
    exit(0);
}

if ($what === 'connection show' && (count($args) === 2 || $args[2] === '--active'))
{
    $rows = [];
    if (count($args) === 3)
        foreach ($active as $line)
        {
            [$uuid, $device] = explode(':', $line, 2);
            $rows[] = ['UUID' => $uuid, 'DEVICE' => $device];
        }
    else
        foreach ($profiles as $uuid => $p)
        {
            $devices = [];
            foreach ($active as $line)
            {
                [$u, $device] = explode(':', $line, 2);
                if ($u === $uuid)
                    $devices[] = $device;
            }
            $type = ($p['data']['connection']['type'] ?? '') === 'wifi' ? '802-11-wireless' : '802-3-ethernet';
            // a profile active on two devices is listed twice, as nmcli does
            foreach ($devices ?: [''] as $device)
                $rows[] = ['UUID' => $uuid, 'TYPE' => $type, 'ACTIVE' => $device === '' ? 'no' : 'yes', 'DEVICE' => $device, 'FILENAME' => $p['file']];
        }
    foreach ($rows as $row)
        echo implode(':', array_map(function ($field) use ($row) { return escape_field($row[$field] ?? ''); }, $fields)), "\n";
    exit(0);
}

if ($what === 'connection show')
{
    $p = $profiles[$args[2]] ?? null;
    if (!$p)
    {
        fwrite(STDERR, "Error: {$args[2]} - no such connection profile.\n");
        exit(10);
    }
    foreach ($fields as $property)
    {
        $value = show($p['data'], $property, $secrets);
        if ($property === '802-11-wireless-security.psk' && $secrets && file_exists("$fake/mangle-psk"))
            $value .= 'x';
        echo $property, ':', $value, "\n";
    }
    exit(0);
}

if ($what === 'connection add')
{
    $data = ['connection' => ['id' => '', 'uuid' => '', 'type' => 'wifi'], 'wifi' => [], 'wifi-security' => [],
             'ipv4' => ['method' => 'auto'], 'ipv6' => ['method' => 'auto']];
    $pairs = array_slice($args, 2);
    for ($i = 0; $i + 1 < count($pairs); $i += 2)
        if ($pairs[$i] !== 'type')
            store($data, $pairs[$i], $pairs[$i + 1]);
    if ($data['connection']['uuid'] === '')
        $data['connection']['uuid'] = '00000000-0000-4000-8000-'.substr(md5(microtime()), 0, 12);
    write_keyfile("$fake/keyfiles/".str_replace('/', '_', $data['connection']['id']).'.nmconnection', $data);
    echo "Connection '{$data['connection']['id']}' ({$data['connection']['uuid']}) successfully added.\n";
    exit(0);
}

if ($what === 'connection modify')
{
    if (file_exists("$fake/fail-modify"))
    {
        fwrite(STDERR, "Error: Failed to modify connection: refused.\n");
        exit(4);
    }
    $p = $profiles[$args[2]] ?? null;
    if (!$p)
        exit(10);
    $data = $p['data'];
    $pairs = array_slice($args, 3);
    for ($i = 0; $i + 1 < count($pairs); $i += 2)
        store($data, $pairs[$i], $pairs[$i + 1]);
    write_keyfile($p['file'], $data);
    exit(0);
}

if ($what === 'connection delete')
{
    $p = $profiles[$args[2]] ?? null;
    if (!$p)
        exit(10);
    unlink($p['file']);
    save_active($fake, array_filter($active, function ($line) use ($args) { return strpos($line, $args[2].':') !== 0; }));
    exit(0);
}

if ($what === 'connection load')
    exit(0);

if ($what === 'connection up')
{
    $uuid = $args[2];
    $device = $args[4] ?? '';
    $outcome = is_file("$fake/up") ? trim(file_get_contents("$fake/up")) : 'connected 192.168.68.236/24';
    [$result, $address] = array_pad(explode(' ', $outcome, 2), 2, '');
    $errors = [
        'wrong-password' => 'Error: Connection activation failed: Secrets were required, but not provided.',
        'not-found' => 'Error: Connection activation failed: The Wi-Fi network could not be found.',
        'no-address' => 'Error: Connection activation failed: IP configuration could not be reserved (no available address, timeout, etc.).',
        'timeout' => 'Error: Timeout expired (30 seconds)',
        'failed' => 'Error: Connection activation failed: The device could not be readied for configuration.',
    ];
    if (isset($errors[$result]))
    {
        fwrite(STDERR, $errors[$result]."\n");
        exit(4);
    }
    save_active($fake, array_merge($active, ["$uuid:$device"]));
    file_put_contents("$fake/address-$device", $address);
    echo "Connection successfully activated (D-Bus active path: /org/freedesktop/NetworkManager/ActiveConnection/9)\n";
    exit(0);
}

if ($what === 'device show')
{
    echo is_file("$fake/address-{$args[2]}") ? file_get_contents("$fake/address-{$args[2]}") : '', "\n";
    exit(0);
}

if ($what === 'device disconnect')
{
    $device = $args[2];
    save_active($fake, array_filter($active, function ($line) use ($device) { return substr($line, -strlen(":$device")) !== ":$device"; }));
    @unlink("$fake/address-$device");
    exit(0);
}

if ($what === 'device wifi')
{
    echo is_file("$fake/scan") ? file_get_contents("$fake/scan") : '';
    exit(0);
}

fwrite(STDERR, 'fake nmcli does not know: '.implode(' ', $args)."\n");
exit(2);
