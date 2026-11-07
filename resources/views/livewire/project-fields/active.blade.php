              <div class="mb-4">
                  <div class="block text-fg text-sm font-bold mb-2">Other options:</div>
                  <label class="inline-flex items-center">
                    <input type="checkbox" id="active" name="active" wire:model="active" class="form-checkbox h-6 w-6 text-accent">
                    <span class="ml-3 text-sm">Set as active project</span>
                  </label>
                  @error('active') <span class="text-neg">{{ $message }}</span>@enderror
              </div>     
