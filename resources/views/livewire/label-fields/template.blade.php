              <div class="mb-4">
                  <label for="template" class="block text-fg text-sm font-bold mb-2">Label template:</label>
                  <textarea wrap="off" type="text" id="template" name="template" wire:model="template" class="h-48 cmp-field w-full font-mono"></textarea>
                  <small>Available variables:<br>$serial $mac $hostname (the host name, after the OS check) $provisionboard ("sw01 Gi0/3", or the jumpers) $switch $port</small><br>
                  @error('template') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
