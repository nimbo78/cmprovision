              @if (count($scripts))
              <div class="mb-4">
                  <div class="block text-fg text-sm font-bold mb-2">Extra scripts to apply:</div>
                  @foreach ($scripts as $script)
                  <label class="inline-flex items-center">
                    <input type="checkbox" id="chkscript{{ $script->id }}" value="{{ $script->id }}" wire:model="selectedScripts" class="form-checkbox h-6 w-6 text-accent">
                    <span class="ml-3 text-sm">{{ $script->name }} <span class="text-fg-muted">({{ $script->script_type === 'firstboot' ? 'first boot' : $script->script_type }})</span></span>
                  </label><br>
                  @endforeach
                  @error('selectedScripts') <span class="text-neg">{{ $message }}</span>@enderror
              </div>              
              @endif

