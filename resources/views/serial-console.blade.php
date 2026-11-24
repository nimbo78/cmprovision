<x-app-layout title="Serial console">
    <link rel="stylesheet" href="{{ asset('vendor/xterm/xterm.css') }}">
    <link rel="stylesheet" href="{{ asset('css/serial-console.css') }}?v={{ @filemtime(public_path('css/serial-console.css')) }}">
    <div class="sc-page" id="serial-console" data-base="{{ route('serial-console.index', [], false) }}">
        <h1>Serial console</h1>
        <noscript>Enable JavaScript to use the serial console.</noscript>
        <div id="sc-message" role="status" aria-live="polite"></div>
        <a class="text-link" id="sc-auth" href="{{ route('serial-console.index') }}" hidden>Reopen console and confirm your password</a>
        <div id="sc-empty" class="cmp-card sc-placeholder">
            <h2 id="sc-empty-title">Checking USB adapters…</h2>
            <p id="sc-empty-help"></p>
            <a class="cmp-btn cmp-btn-secondary" href="{{ route('ports') }}">Back to Ports</a>
        </div>
        <div id="sc-workspace" class="sc-workspace" hidden>
            <aside class="cmp-card sc-sidebar">
                <div class="sc-heading"><h2>USB ports</h2><span id="sc-count"></span></div>
                <ul id="sc-list"></ul>
            </aside>
            <label class="sc-mobile">USB port <select id="sc-port" class="cmp-field"></select></label>
            <section class="cmp-card sc-panel" aria-labelledby="sc-target">
                <div class="sc-heading">
                    <div class="sc-target">
                        <svg aria-hidden="true" viewBox="0 0 24 24"><rect x="2.5" y="4" width="19" height="16" rx="2.5"/><path d="m6 9 3 3-3 3m6 0h5"/></svg>
                        <div><h2 id="sc-target"></h2><p id="sc-meta"></p></div>
                    </div>
                    <div class="sc-actions"><span class="sc-status" id="sc-badge"></span><button id="sc-disconnect" type="button" class="cmp-btn cmp-btn-secondary" hidden>Disconnect</button></div>
                </div>
                <div class="sc-tabs" aria-label="Console panels">
                    <button type="button" id="sc-terminal-tab" aria-controls="sc-terminal-pane" aria-pressed="true">Terminal</button>
                    <button type="button" id="sc-details-tab" aria-controls="sc-details" aria-pressed="false">Port details</button>
                </div>
                <div id="sc-terminal-pane" class="sc-pane">
                    <div id="sc-placeholder" class="sc-placeholder">
                        <h3 id="sc-placeholder-title"></h3>
                        <p id="sc-placeholder-help"></p>
                        <div class="sc-actions">
                            <button type="button" id="sc-connect" class="cmp-btn">Connect</button>
                            <button type="button" id="sc-identify" class="cmp-btn cmp-btn-secondary">Identify</button>
                            <button type="button" id="sc-manual" class="cmp-btn cmp-btn-secondary" hidden>Set line settings</button>
                        </div>
                    </div>
                    <div id="sc-screen" aria-label="Serial terminal" hidden></div>
                    <section id="sc-transfer" aria-labelledby="sc-file-heading" hidden>
                        <div class="sc-transfer-head"><h3 id="sc-file-heading">Paste config from file</h3>
                            <button type="button" id="sc-file-close" class="cmp-btn cmp-btn-secondary">Close</button>
                        </div>
                        <div id="sc-picker">
                            <label class="sc-field">Choose a UTF-8 configuration up to 1 MiB
                                <input type="file" id="sc-file">
                            </label>
                        </div>
                        <div id="sc-job" hidden>
                            <strong id="sc-filename"></strong><p id="sc-progress-text"></p>
                            <progress id="sc-progress" aria-label="Commands returned to prompt"></progress>
                            <p id="sc-notice" role="status"></p>
                            <div id="sc-review" hidden>
                                <label class="sc-field">Sending mode
                                    <select class="cmp-field" id="sc-mode">
                                        <option value="bulk">Automatic · answer Yes and accept defaults</option>
                                        <option value="smart">Smart · ask before confirming</option>
                                        <option value="step">Step through</option>
                                    </select>
                                </label>
                                <label id="sc-default-choice" hidden><input type="checkbox" id="sc-default"> <span id="sc-default-label"></span></label>
                                <ol id="sc-rows" aria-label="Original file lines"></ol>
                                <div class="sc-actions" id="sc-pagination" hidden>
                                    <button type="button" class="cmp-btn cmp-btn-secondary" id="sc-prev">Previous</button>
                                    <span id="sc-page-number"></span><button type="button" class="cmp-btn cmp-btn-secondary" id="sc-next-page">Next</button>
                                </div>
                            </div>
                            <label class="sc-field" id="sc-response-field" hidden><span id="sc-response-label"></span>
                                <input type="text" id="sc-response" class="cmp-field font-mono" autocomplete="off" spellcheck="false">
                            </label>
                            <div id="sc-file-actions" class="sc-actions"></div>
                        </div>
                    </section>
                    <div class="sc-foot"><span id="sc-settings-summary"></span><div class="sc-actions">
                        <button type="button" id="sc-paste-file" class="cmp-btn cmp-btn-secondary" hidden>Paste config from file</button>
                        <button type="button" id="sc-redetect" class="cmp-btn cmp-btn-secondary" hidden>Re-detect</button>
                        <button type="button" id="sc-line-settings" class="cmp-btn cmp-btn-secondary">Line settings</button>
                    </div></div>
                </div>
                <div class="sc-details" id="sc-details" hidden>
                    <dl>
                        <dt>USB connection</dt><dd id="sc-connection"></dd>
                        <dt>Stable port</dt><dd><code id="sc-path"></code></dd>
                        <dt>Observed hostname</dt><dd id="sc-hostname"></dd>
                        <dt>Platform</dt><dd><span id="sc-platform"></span> <button type="button" id="sc-version" class="cmp-btn cmp-btn-secondary" hidden>Read version</button></dd>
                    </dl>
                    <form id="sc-form" class="sc-settings">
                        <label class="sc-field">Cable label<input class="cmp-field" id="sc-label" maxlength="64" placeholder="e.g. Rack 2 console" autocomplete="off"></label>
                        <label class="sc-field">Baud rate<select class="cmp-field" id="sc-baud"><option value="auto">Auto</option>
                            @foreach ([9600, 19200, 38400, 57600, 115200] as $baud)
                                <option value="{{ $baud }}">{{ $baud }}</option>
                            @endforeach
                        </select></label>
                        <label class="sc-field">Manual format<select class="cmp-field" id="sc-frame" disabled>
                            @foreach (['8n1', '8n2', '7e1', '8e1', '7o1', '8o1'] as $frame)
                                <option value="{{ $frame }}">{{ strtoupper($frame) }}</option>
                            @endforeach
                        </select></label>
                        <div class="sc-field">
                            <label><input type="checkbox" id="sc-wake" aria-describedby="sc-wake-help"> Send Enter on connect</label>
                            <span class="sc-help" id="sc-wake-help">After one quiet second or a login invitation. On a silent console, Enter can submit a pending command.</span>
                        </div>
                        <button type="submit" id="sc-save" class="cmp-btn cmp-btn-secondary">Save</button>
                    </form>
                    <p class="sc-help">Line settings apply at the next connection.</p>
                </div>
            </section>
        </div>
    </div>
    <script defer src="{{ asset('vendor/xterm/xterm.js') }}"></script>
    <script defer src="{{ asset('js/serial-console.js') }}?v={{ @filemtime(public_path('js/serial-console.js')) }}"></script>
</x-app-layout>
