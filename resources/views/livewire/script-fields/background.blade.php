              @if ($script_type !== 'firstboot')
              <div class="mb-4">
                  <label class="inline-flex items-center">
                    <input type="checkbox" id="bg" name="bg" wire:model="bg" class="form-checkbox h-6 w-6 text-accent">
                    <span class="ml-3 text-sm">Run in background</span>
                  </label>
                  @error('bg') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              @endif
