              <div class="mb-4">
                  <label for="label_moment" class="block text-fg text-sm font-bold mb-2">When to print label:</label>
                  <select id="label_moment" name="label_moment" wire:model.live="label_moment" class="cmp-field w-full">
                    <option value="never">Never</option>
                    <option value="preinstall">Before provisioning</option>
                    <option value="postinstall">After writing and post-install</option>
                    @if ($os_check)
                    <option value="firstboot">After the OS check (with $hostname)</option>
                    @endif
                  </select>
                  @error('label_moment') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              @if ($label_moment != "never")
              <div class="mb-4">
                  <label for="label_id" class="block text-fg text-sm font-bold mb-2">Label to print:</label>
                  <select id="label_id" name="label_id" wire:model="label_id" class="cmp-field w-full">
                    @foreach ($labels as $label)
                    <option value="{{ $label->id }}">{{ $label->name }}</option>
                    @endforeach
                  </select>
                  @error('label_id') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              @endif

