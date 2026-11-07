              <div class="mb-4">
                  <label for="storage" class="block text-fg text-sm font-bold mb-2">Destination storage device:</label>
                  <input type="text" id="storage" name="storage" wire:model="storage" class="cmp-field w-full">
                  @error('storage') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
