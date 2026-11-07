              <div class="mb-4">
                  <label for="script" class="block text-fg text-sm font-bold mb-2">Script:</label>
                  <textarea wrap="off" type="text" id="script" name="script" wire:model="script" class="h-48 cmp-field w-full font-mono"></textarea>
                  @if ($script_type === 'firstboot')
                  <small>Runs in the written system on its first boot, as root, when the project checks the OS: a non-zero exit fails the check. Environment variable: $SERVER</small><br>
                  @else
                  <small>Available environment variables:<br>$SERVER $STORAGE $PART1 $PART2</small><br>
                  @endif
                  @error('script') <span class="text-neg">{{ $message }}</span>@enderror
              </div>

