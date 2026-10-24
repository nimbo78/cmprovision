<x-settings-card title="Services" description="The processes of this server. View log opens the journal of one and follows it." id="services" flush>
    <ul class="cmp-rows">
        @foreach ($services as $service)
            <li class="cmp-row">
                <span class="cmp-row-name">{{ $service['label'] }}</span>
                <span class="cmp-chip {{ $service['running'] ? 'cmp-chip-ok' : 'cmp-chip-fail' }}"><span class="dot"></span>{{ $service['running'] ? 'running' : 'stopped' }}</span>
                <button wire:click="{{ $service['method'] }}()" class="cmp-btn cmp-btn-secondary cmp-btn-small">View log</button>
            </li>
        @endforeach
        <li class="cmp-row">
            <span class="cmp-row-name">utility OS: {{ $utilityOs }}</span>
            <span class="cmp-chip {{ $utilityOs !== 'none' ? 'cmp-chip-ok' : 'cmp-chip-fail' }}"><span class="dot"></span>{{ $utilityOs !== 'none' ? 'present' : 'missing' }}</span>
            <a href="{{ route('settings', ['tab' => 'provisioning']) }}#utility-os" class="text-sm text-link hover:underline">Change</a>
        </li>
    </ul>
</x-settings-card>

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
        <div>
            <a href="{{ route('settings.backup') }}" class="cmp-btn cmp-btn-secondary">Download backup</a>
        </div>
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
