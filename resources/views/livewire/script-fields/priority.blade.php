              <div class="mb-4">
                  <label for="priority" class="block text-fg text-sm font-bold mb-2">Priority:</label>
                  <input type="text" id="priority" name="priority" wire:model="priority" class="cmp-field w-full">
                  <small>Script with lowest number is started first</small><br>
                  @error('priority') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
