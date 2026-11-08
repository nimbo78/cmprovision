              <div class="mb-4">
                  <label for="printer_type" class="block text-fg text-sm font-bold mb-2">Method to send print job to printer:</label>
                  <select id="printer_type" name="printer_type" wire:model.live="printer_type" class="cmp-field w-full">
                    <option value="ftp">FTP</option>
                    <option value="command">Custom command</option>
                  </select>
                  @error('printer_type') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
