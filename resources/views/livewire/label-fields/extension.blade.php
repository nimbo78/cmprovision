              <div class="mb-4">
                  <label for="file_extension" class="block text-fg text-sm font-bold mb-2">File extension for print job:</label>
                  <input type="text" id="file_extension" name="file_extension" wire:model="file_extension" class="cmp-field w-full">
                  @error('file_extension') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
