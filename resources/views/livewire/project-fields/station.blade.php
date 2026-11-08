              <div class="mb-4 border-t border-pline pt-4">
                  @if (!$wizardMode)
                  <label class="inline-flex items-center">
                    <input type="checkbox" wire:model.live="stationEnabled" class="form-checkbox h-6 w-6 text-accent">
                    <span class="ml-3 text-sm font-bold">Set up a cmprovision station</span>
                  </label>
                  @endif
                  <p class="text-xs text-fg-muted mt-1">For a compatible cmprovision Lite image and the 64-bit utility OS. Settings are sent over SSH after image verification. DHCP/TFTP stays disabled until the new station's network is configured.</p>
                  @error('stationEnabled') <p class="text-sm text-neg">{{ $message }}</p> @enderror
                  @if ($stationEnabled)
                    <div class="mt-3 space-y-3">
                      @foreach (['hostname' => 'Host name', 'username' => 'Linux user name'] as $key => $title)
                        <div><label for="station-{{ $key }}" class="block text-sm font-bold mb-1">{{ $title }}</label>
                        <input id="station-{{ $key }}" wire:model="station.{{ $key }}" class="cmp-field w-full" autocomplete="off">
                        @error('station.'.$key) <p class="text-sm text-neg">{{ $message }}</p> @enderror</div>
                      @endforeach
                      <p class="text-xs text-fg-muted">Use {serial} in the host name to give each station a unique name.</p>
                      <label class="block text-sm"><input type="checkbox" wire:model.live="station.password_login" class="form-checkbox"> Enable password login</label>
                      @if (!($station['password_login'] ?? true))
                        <p class="text-xs text-fg-muted">With keys only, password login is locked and this administrator uses sudo without a password.</p>
                      @endif
                      @if ($station['password_login'] ?? true)
                        <div><label for="station-password" class="block text-sm font-bold mb-1">Initial Linux password</label>
                        @if ($wizardMode)
                        <input id="station-password" type="password" x-model="wizardCredentials.password" class="cmp-field w-full" autocomplete="new-password" placeholder="{{ $savedStationSetup ? 'Leave empty to keep saved password' : 'At least 8 characters' }}">
                        @else
                        <input id="station-password" type="password" wire:model="station.password" class="cmp-field w-full" autocomplete="new-password" placeholder="{{ $savedStationSetup ? 'Leave empty to keep saved password' : 'At least 8 characters' }}">
                        @endif
                        @error('station.password') <p class="text-sm text-neg">{{ $message }}</p> @enderror</div>
                      @endif
                      <div><label for="station-keys" class="block text-sm font-bold mb-1">SSH public keys</label>
                      <textarea id="station-keys" wire:model="station.keys" class="cmp-field w-full font-mono text-sm" rows="3" placeholder="One public key per line"></textarea>
                      @error('station.keys') <p class="text-sm text-neg">{{ $message }}</p> @enderror</div>
                      <label class="block text-sm"><input type="checkbox" wire:model="station.ssh" class="form-checkbox"> Enable SSH</label>
                      @error('station.ssh') <p class="text-sm text-neg">{{ $message }}</p> @enderror
                      <label class="block text-sm"><input type="checkbox" wire:model.live="station.wifi" class="form-checkbox"> Configure management Wi-Fi</label>
                      @if ($station['wifi'] ?? false)
                        <div><label for="station-ssid" class="block text-sm font-bold mb-1">Wi-Fi SSID</label>
                        <input id="station-ssid" wire:model="station.ssid" class="cmp-field w-full" autocomplete="off">
                        @error('station.ssid') <p class="text-sm text-neg">{{ $message }}</p> @enderror</div>
                        <div><label for="station-security" class="block text-sm font-bold mb-1">Wi-Fi security</label>
                        <select id="station-security" wire:model="station.security" class="cmp-field w-full"><option value="wpa2">WPA2-Personal</option><option value="mixed">WPA2/WPA3 mixed</option><option value="wpa3">WPA3-Personal</option></select>
                        @error('station.security') <p class="text-sm text-neg">{{ $message }}</p> @enderror</div>
                        <div><label for="station-wifi-password" class="block text-sm font-bold mb-1">Wi-Fi password</label>
                        @if ($wizardMode)
                        <input id="station-wifi-password" type="password" x-model="wizardCredentials.wifi_password" class="cmp-field w-full" autocomplete="new-password" placeholder="Leave empty to keep this network's saved password">
                        @else
                        <input id="station-wifi-password" type="password" wire:model="station.wifi_password" class="cmp-field w-full" autocomplete="new-password" placeholder="Leave empty to keep this network's saved password">
                        @endif
                        @error('station.wifi_password') <p class="text-sm text-neg">{{ $message }}</p> @enderror</div>
                        <div><label for="station-country" class="block text-sm font-bold mb-1">Wi-Fi country</label>
                        <input id="station-country" wire:model="station.country" class="cmp-field w-24" maxlength="2" placeholder="IL">
                        @error('station.country') <p class="text-sm text-neg">{{ $message }}</p> @enderror</div>
                        <label class="block text-sm"><input type="checkbox" wire:model="station.hidden" class="form-checkbox"> Hidden network</label>
                      @endif
                      <p class="text-xs text-fg-muted">Saved passwords are never shown again. These are Linux access settings; create a separate web account after first boot. Use an isolated provisioning network.</p>
                    </div>
                  @endif
              </div>
