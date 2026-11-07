              <div class="mb-4">
                  <label for="image_id" class="block text-fg text-sm font-bold mb-2">Image to write:</label>
                  <select id="image_id" name="image_id" wire:model="image_id" class="cmp-field w-full">
                    <option value="">-none-</option>
                    @foreach ($images as $image)
                    <option value="{{ $image->id }}">{{ $image->filename }} (added {{ date_format($image->created_at, "d-M-Y") }})</option>
                    @endforeach
                  </select>
                  @error('image_id') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
