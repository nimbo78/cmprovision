              @if ($os_check && count($packages))
              <div class="mb-4">
                  <div class="block text-fg text-sm font-bold mb-2">Packages to install on the first boot:</div>
                  @foreach ($packages as $package)
                  <label class="inline-flex items-center">
                    <input type="checkbox" id="chkpackage{{ $package->id }}" value="{{ $package->id }}" wire:model="selectedPackages" class="form-checkbox h-6 w-6 text-accent">
                    <span class="ml-3 text-sm">{{ $package->deb_package }} {{ $package->deb_version }} <span class="text-fg-muted">({{ $package->deb_architecture }})</span></span>
                  </label><br>
                  @endforeach
              </div>
              @endif
              @error('selectedPackages') <div class="mb-4 text-neg">{{ $message }}</div>@enderror
