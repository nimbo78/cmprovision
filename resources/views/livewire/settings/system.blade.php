<livewire:station-time-settings />

<livewire:station-power />

<livewire:wallboard-settings />

<livewire:fpms-settings />

<x-settings-card title="Backup" description="A copy of the data of this server to keep somewhere else." id="backup" help-title="How to restore">
    <div class="flex flex-col gap-3">
        @if (session('backupError'))
            <div class="cmp-flash" role="alert"><p class="text-sm">{{ session('backupError') }}</p></div>
        @endif
        <p>
            One file with the database, the settings, the firmware store, the SSH key of Re-provision and the DHCP config. It also holds
            the application key and the passwords the server keeps (switches, printers, chat bots, sensor accounts): keep it as you keep a
            password. The images and the packages of the OS check are not in it, they are too big; the password is asked before the download.
        </p>
        <p class="text-sm text-fg-muted">This data-only copy does not change the destination station's system time zone or NTP servers.</p>
        <div>
            <a href="{{ route('settings.backup') }}" class="cmp-btn cmp-btn-secondary">Download backup</a>
            <a href="{{ route('settings.transfer') }}" class="cmp-btn cmp-btn-secondary">Selective import / export</a>
        </div>
        <livewire:station-backups-panel />
    </div>
    <x-slot name="help">
        <p>On a server where cmprovision4 is installed (a new one, or this one after a reinstall), as root:</p>
        <pre class="mono my-2 overflow-x-auto">tar xf cmprovision-backup-&lt;host&gt;-&lt;date&gt;-&lt;version&gt;.tar
cmprovision-restore --data-only &lt;date&gt;-&lt;version&gt;</pre>
        <p>
            It puts back the database, <span class="mono">.env</span>, the firmware store, the SSH key and the DHCP config, runs the
            migrations of the installed version and restarts the services; the network, nginx and PHP settings of the server stay as
            they are. The images are uploaded again, or come from <span class="mono">cmprovision-backup --with-uploads</span> made on the server.
        </p>
    </x-slot>
</x-settings-card>
