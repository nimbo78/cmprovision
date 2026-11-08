              <div class="mb-4">
                  <label for="firmware" class="block text-fg text-sm font-bold mb-2">EEPROM firmware update to apply:</label>
                  <select id="firmware" name="firmware" wire:model.live="firmware" class="cmp-field w-full">
                    <option value="">-none-</option>
                      @foreach ($firmwareChannels as $channel => $images)
                      <optgroup label="{{ $channel }}{{ $channel == 'default' ? ' (recommended by Raspberry Pi)' : (in_array($channel, ['stable', 'beta', 'critical']) ? ' (old channel name, images downloaded years ago)' : '') }}">
                        @foreach ($images as $fw)
                        <option value="{{ $fw->path }}">{{ $fw->name }}</option>
                        @endforeach
                      </optgroup>
                      @endforeach
                  </select>
                  @error('firmware') <span class="text-neg">{{ $message }}</span>@enderror
                  @if ($firmwareChanges)
                  <div class="mt-2 text-sm" aria-live="polite">
                      <div class="font-bold mb-1">{{ $firmwareChanges['heading'] }}</div>
                      @forelse ($firmwareChanges['entries'] as $entry)
                          @include('livewire.firmware-entry', ['entry' => $entry, 'open' => true])
                      @empty
                          <p class="text-fg-muted">The release notes have nothing for this image.</p>
                      @endforelse
                  </div>
                  @endif
              </div>
              @if ($firmware != "")
              @if ($offerSettingsReset)
                <div class="cmp-flash my-3" role="alert">
                  <div class="flex">
                    <div>
                      <p class="text-sm">You are currently using custom EEPROM settings which
                                         differ from the default settings of the newly selected EEPROM image.</p>
                      <div class="mt-3 flex w-full rounded-md shadow-sm sm:mt-0 sm:w-auto">
                        <button wire:click="resetEEPROMsettings" class="border border-pline px-4 py-2 bg-panel text-base leading-6 font-medium text-fg shadow-sm hover:text-fg focus:outline-none focus:border-accent focus:ring focus:ring-accent transition ease-in-out duration-150 sm:text-sm sm:leading-5">
                          Reset settings to default
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              @endif
              <div class="mb-4">
                  <label for="eeprom_settings" class="block text-fg text-sm font-bold mb-2">EEPROM settings:</label>
                  <textarea type="text" id="eeprom_settings" name="eeprom_settings" wire:model="eeprom_settings" class="h-28 cmp-field w-full font-mono"></textarea>
                  @error('eeprom_settings') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              @endif
