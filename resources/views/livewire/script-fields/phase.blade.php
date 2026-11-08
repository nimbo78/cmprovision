              <div class="mb-4">
                  <label for="script_type" class="block text-fg text-sm font-bold mb-2">Script type:</label>
                  <select id="script_type" name="script_type" wire:model.live="script_type" class="cmp-field w-full">
                    <option value="preinstall">preinstall</option>
                    <option value="postinstall">postinstall</option>
                    <option value="firstboot">first boot (in the written system, for the OS check)</option>
                  </select>
                  @error('script_type') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
