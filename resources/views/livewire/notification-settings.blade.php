@php($input = 'cmp-field w-full')
@php($label = 'block text-fg text-sm font-bold mb-2')
@php($small = 'cmp-btn-small')
<x-settings-card title="Notifications" id="notifications" description="Tell Mattermost, Telegram or any webhook when a module is done or failed.">
    @if (!$formOpen)
        <x-slot name="actions">
            <button wire:click="add()" class="cmp-btn">Add channel</button>
        </x-slot>
    @endif
    <x-slot name="help">
        Bots keep a thread per batch of modules with an up-to-date summary; the selected events go as replies under it.
        A batch is the modules of one project started together: it closes after a long idle time, when the project
        changes, or with Start a new batch.
    </x-slot>

    @if ($notice)
        <div class="rounded px-4 py-2 mb-4 text-sm font-semibold {{ $noticeIsError ? 'bg-neg-bg text-neg' : 'bg-pos-bg text-pos' }}">{{ $notice }}</div>
    @endif

    <div class="flex items-center justify-between mb-4 text-sm text-fg">
        <div>
            @if ($batch)
                <span class="font-semibold">Batch #{{ $batch->id }}</span>
                &middot; project {{ $batch->project ? $batch->project->name : '(deleted)' }}
                &middot; since {{ $batch->started_at->local()->format('d.m H:i') }}
                &middot; {{ $batchCounts['done'] }} done, {{ $batchCounts['failed'] }} failed, {{ $batchCounts['active'] }} in progress
            @else
                No batch yet: the next module starts one.
            @endif
        </div>
        <button wire:click="newBatch()" class="cmp-btn cmp-btn-secondary {{ $small }}" title="Close the current batch; bots open a new thread for the next module">Start a new batch</button>
    </div>

    <div class="cmp-card-scroll">
        <table class="cmp-table text-sm">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Events</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($channels as $c)
                <tr wire:key="channel-{{ $c->id }}">
                    <td >{{ $c->name }}@if (!$c->enabled) <span class="text-fg-muted">(off)</span>@endif</td>
                    <td >{{ $c->typeLabel() }}@if (in_array($c->type, \App\Models\NotificationChannel::TLS_TYPES, true) && $c->setting('tls_insecure')) <span class="text-neg">· any certificate</span>@elseif (in_array($c->type, \App\Models\NotificationChannel::TLS_TYPES, true) && $c->setting('tls_ca')) <span class="text-fg-muted">· own certificate</span>@endif</td>
                    <td >{{ collect($c->events)->map(function ($e) { return \App\Models\NotificationChannel::EVENTS[$e] ?? $e; })->implode(', ') }}</td>
                    <td class="text-xs">
                        @if ($c->last_error)
                            <span class="text-neg [overflow-wrap:anywhere]">{{ \Illuminate\Support\Str::limit($c->last_error, 160) }}</span>
                        @elseif ($c->last_sent_at)
                            <span class="text-pos">sent {{ $c->last_sent_at->local()->format('d.m H:i') }}</span>
                        @else
                            <span class="text-fg-muted">nothing sent yet</span>
                        @endif
                    </td>
                    <td >
                        <button wire:click="test({{ $c->id }})" class="cmp-btn cmp-btn-secondary {{ $small }}">Test</button>
                        <button wire:click="edit({{ $c->id }})" class="cmp-btn cmp-btn-secondary {{ $small }}">Edit</button>
                        <button wire:click="toggle({{ $c->id }})" class="cmp-btn cmp-btn-secondary {{ $small }}">{{ $c->enabled ? 'Turn off' : 'Turn on' }}</button>
                        <button wire:click="delete({{ $c->id }})" onclick="confirm('Delete {{ addslashes($c->name) }}?') || event.stopImmediatePropagation()" class="cmp-btn cmp-btn-danger {{ $small }}">Delete</button>
                    </td>
                </tr>
            @empty
                <tr><td class="text-fg-muted" colspan="5">No channels. Add Mattermost, Telegram or a webhook to hear about every module.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @if ($formOpen)
        <div class="border border-pline rounded p-4 mt-4">
            <div class="font-bold mb-3">{{ $editingId ? 'Edit channel' : 'New channel' }}</div>

            <div class="mb-4">
                <label class="{{ $label }}">Name</label>
                <input type="text" wire:model="name" class="{{ $input }}" placeholder="Line chat">
                @error('name') <span class="text-neg text-sm">{{ $message }}</span> @enderror
            </div>

            <div class="mb-4">
                <label class="{{ $label }}">Type</label>
                <select wire:model.live="type" class="{{ $input }}">
                    @foreach (\App\Models\NotificationChannel::TYPES as $key => $title)
                        <option value="{{ $key }}">{{ $title }}</option>
                    @endforeach
                </select>
            </div>

            @if ($type === 'mattermost_webhook' || $type === 'webhook')
                <div class="mb-4">
                    <label class="{{ $label }}">Webhook URL</label>
                    <input type="text" wire:model="url" class="{{ $input }}" placeholder="https://mattermost.example.com/hooks/...">
                    @error('url') <span class="text-neg text-sm">{{ $message }}</span> @enderror
                    <p class="text-xs text-fg-muted mt-1">
                        @if ($type === 'webhook')
                            Receives a JSON POST per event: event, serial, mac, board, project, image, phase, detail, times, batch counts and a ready "text".
                        @else
                            One message per event. For a thread per batch use the Mattermost bot type.
                        @endif
                    </p>
                </div>
            @elseif ($type === 'mattermost_bot')
                <div class="mb-4">
                    <label class="{{ $label }}">Mattermost server</label>
                    <input type="text" wire:model="serverUrl" class="{{ $input }}" placeholder="https://mattermost.example.com">
                    @error('serverUrl') <span class="text-neg text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="mb-4">
                    <label class="{{ $label }}">Bot access token</label>
                    <input type="password" wire:model="token" class="{{ $input }}" placeholder="{{ $editingId ? 'leave empty to keep the stored token' : '' }}" autocomplete="new-password">
                    @error('token') <span class="text-neg text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="mb-4">
                    <label class="{{ $label }}">Channel</label>
                    <input type="text" wire:model="channel" class="{{ $input }}" placeholder="https://mattermost.example.com/team/channels/provisioning">
                    @error('channel') <span class="text-neg text-sm">{{ $message }}</span> @enderror
                    <p class="text-xs text-fg-muted mt-1">Channel link, team/channel or channel id. Add the bot to the team and, for a private channel, to the channel.</p>
                </div>
            @elseif ($type === 'telegram')
                <div class="mb-4">
                    <label class="{{ $label }}">Bot token</label>
                    <input type="password" wire:model="botToken" class="{{ $input }}" placeholder="{{ $editingId ? 'leave empty to keep the stored token' : '123456:ABC...' }}" autocomplete="new-password">
                    @error('botToken') <span class="text-neg text-sm">{{ $message }}</span> @enderror
                </div>
                <div class="mb-4">
                    <label class="{{ $label }}">Chat id</label>
                    <div class="flex items-center">
                        <input type="text" wire:model="chatId" class="{{ $input }}" placeholder="-1001234567890">
                        <button wire:click="findTelegramChats()" class="cmp-btn cmp-btn-secondary {{ $small }} ml-2" style="white-space: nowrap">Find chats</button>
                    </div>
                    @error('chatId') <span class="text-neg text-sm">{{ $message }}</span> @enderror
                    @if ($foundChats)
                        <div class="text-sm mt-2">
                            Chats the bot has seen:
                            @foreach ($foundChats as $id => $title)
                                <div><button wire:click="useChat('{{ $id }}')" class="underline">{{ $id }}</button> {{ $title }}</div>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="mb-4">
                    <label class="{{ $label }}">Topic id (forum groups, optional)</label>
                    <input type="text" wire:model="topicId" class="{{ $input }}">
                    @error('topicId') <span class="text-neg text-sm">{{ $message }}</span> @enderror
                </div>
            @endif

            @if (in_array($type, \App\Models\NotificationChannel::TLS_TYPES, true))
                <div class="mb-4">
                    <label class="{{ $label }}">Server certificate</label>
                    <p class="text-xs text-fg-muted mb-2">Only for an https server whose certificate this system does not trust: a self-signed one or one from your own CA. The host name is still checked.</p>
                    <button wire:click="fetchCertificate()" class="cmp-btn cmp-btn-secondary {{ $small }}">Fetch from the server</button>
                    @if ($tlsFetched)
                        <p class="text-sm mt-2">{{ $tlsFetched }}</p>
                    @endif
                    <textarea wire:model.blur="tlsCa" rows="3" spellcheck="false" class="{{ $input }} font-mono text-xs mt-2" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
                    @error('tlsCa') <span class="text-neg text-sm">{{ $message }}</span> @enderror
                    @foreach ($tlsCerts as $cert)
                        <div class="text-xs mt-1">
                            <span class="font-semibold">{{ $cert['subject'] }}</span>
                            · {{ $cert['self_signed'] ? 'self-signed' : 'issued by '.$cert['issuer'] }}
                            · <span class="{{ $cert['valid_to'] < time() ? 'text-neg' : '' }}">valid until {{ \Illuminate\Support\Carbon::createFromTimestamp($cert['valid_to'])->local()->format('d.m.Y') }}</span>
                            <div class="font-mono text-fg-muted break-all">SHA-256 {{ $cert['sha256'] }}</div>
                        </div>
                    @endforeach
                    <label class="text-sm block mt-2"><input type="checkbox" wire:model.live="tlsInsecure"> Accept any certificate (insecure)</label>
                    @if ($tlsInsecure)
                        <p class="text-xs text-neg mt-1">The certificate is not checked: anyone on the way to the server can read the {{ $type === 'mattermost_bot' ? 'bot token and the ' : '' }}messages.</p>
                    @endif
                </div>
            @endif

            @if (in_array($type, \App\Models\NotificationChannel::THREADED, true))
                <p class="text-xs text-fg-muted mb-4">The bot posts a summary for every batch (project, image, EEPROM, counters) and keeps it up to date; the selected events go as replies under it.</p>
            @endif

            <div class="mb-4">
                <label class="{{ $label }}">Events</label>
                @foreach (\App\Models\NotificationChannel::EVENTS as $key => $title)
                    <label class="mr-4 text-sm" style="margin-right: 1rem"><input type="checkbox" wire:model="events" value="{{ $key }}"> {{ $title }}</label>
                @endforeach
                @error('events') <div class="text-neg text-sm">{{ $message }}</div> @enderror
            </div>

            <div class="mb-4">
                <label class="text-sm"><input type="checkbox" wire:model="enabled"> Enabled</label>
            </div>

            <button wire:click="save()" class="cmp-btn">Save</button>
            <button wire:click="cancel()" class="cmp-btn cmp-btn-secondary ml-2">Cancel</button>
        </div>
    @endif
</x-settings-card>
