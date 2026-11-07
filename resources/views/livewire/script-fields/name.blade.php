              <div class="mb-4">
                  <label for="name" class="block text-fg text-sm font-bold mb-2">Script name:</label>
                  <input type="text" id="name" name="name" wire:model="name" class="cmp-field w-full">
                  @error('name') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
