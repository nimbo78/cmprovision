<details class="mt-4 text-sm text-gray-700">
    <summary style="cursor: pointer" class="font-semibold">How to enable SNMP on the switch</summary>
    @php($code = 'font-mono text-xs bg-gray-100 rounded px-2 py-1 mt-1 block')
    <div class="mt-2">
        <p>Read-only access is enough; allow the provisioning server's address. The table is read when a module starts,
           so its MAC is already known to the switch (DHCP went through it). Set a port description to show it instead of the port name, e.g. the slot on the line.</p>

        <p class="mt-3 font-semibold">Cisco IOS / IOS-XE (Catalyst 1000, 2960, 3560-CX)</p>
        <span class="{{ $code }}">snmp-server community NAME RO</span>
        <p class="mt-1">Each VLAN has its own MAC table, read as NAME@vlan automatically. With SNMPv3 allow the VLAN contexts:</p>
        <span class="{{ $code }}">snmp-server group G v3 priv context vlan- match prefix<br>snmp-server user U G v3 auth sha PASSWORD priv aes 128 PASSWORD</span>
        <p class="mt-1">Older IOS without <span class="font-mono">match prefix</span> needs a <span class="font-mono">context vlan-N</span> line per VLAN (<span class="font-mono">show snmp context</span>).</p>

        <p class="mt-3 font-semibold">Cisco Business (CBS250/350, Catalyst 1200/1300)</p>
        <span class="{{ $code }}">snmp-server server<br>snmp-server community NAME ro SERVER-ADDRESS view Default</span>
        <p class="mt-1">or the same in the web interface. SNMPv3 there is SHA with AES-128 (no MD5 or DES).</p>

        <p class="mt-3 font-semibold">Huawei VRP (S5731) and YunShan (S5735-S-V2)</p>
        <span class="{{ $code }}">snmp-agent<br>snmp-agent sys-info version v2c<br>snmp-agent mib-view included all iso<br>snmp-agent community read cipher NAME mib-view all</span>
        <p class="mt-1">SNMPv3:</p>
        <span class="{{ $code }}">snmp-agent sys-info version v3<br>snmp-agent usm-user v3 U authentication-mode sha cipher PASSWORD<br>snmp-agent usm-user v3 U privacy-mode aes128 cipher PASSWORD</span>

        <p class="mt-3 font-semibold">MikroTik RouterOS</p>
        <span class="{{ $code }}">/snmp set enabled=yes<br>/snmp community set [find default=yes] addresses=SERVER-ADDRESS/32</span>
        <p class="mt-1">Use RouterOS 7.17 or newer: older releases can return an incomplete bridge host table over SNMP. Interface comments show up as port descriptions.</p>

        <p class="mt-3 text-xs text-gray-500">SNMPv3 on this server (PHP {{ PHP_MAJOR_VERSION }}.{{ PHP_MINOR_VERSION }}):
            authentication {{ implode(', ', \App\Services\Snmp\PhpSnmpClient::authProtocols()) }}, privacy DES or AES-128.</p>
    </div>
</details>
