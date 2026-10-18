<div class="fixed z-50 inset-0 overflow-y-auto ease-out duration-300">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">

    <div class="fixed inset-0 transition-opacity">
      <div class="absolute inset-0" style="background: var(--scrim)"></div>
    </div>
  
    <span class="hidden sm:inline-block sm:align-middle sm:h-screen"></span>​
  
    <div class="inline-block align-bottom bg-panel rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full" role="dialog" aria-modal="true" aria-labelledby="modal-headline">
      <div class="bg-panel px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
        <div >
              <div class="mb-4">
                  <label for="name" class="block text-fg text-sm font-bold mb-2">Project name:</label>
                  <input type="text" id="name" name="name" wire:model="name" class="cmp-field w-full">
                  @error('name') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
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
              <div class="mb-4">
                  <label class="inline-flex items-center">
                    <input type="checkbox" id="verify" name="verify" wire:model="verify" class="form-checkbox h-6 w-6 text-accent">
                    <span class="ml-3 text-sm">Verify that image was written correctly</span>
                  </label>
                  @error('verify') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              <div class="mb-4">
                  <label class="inline-flex items-center">
                    <input type="checkbox" id="os_check" name="os_check" wire:model.live="os_check" class="form-checkbox h-6 w-6 text-accent">
                    <span class="ml-3 text-sm">Check the OS after the first boot</span>
                  </label>
                  <p class="text-xs text-fg-muted mt-1">The module boots the written system on the stand: it installs the packages, runs the first-boot scripts and reports its host name. The bootloader must try eMMC before the network (BOOT_ORDER).</p>
                  @error('os_check') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              <div class="mb-4">
                  <label class="inline-flex items-center">
                    <input type="checkbox" id="split_image" name="split_image" wire:model.live="split_image" class="form-checkbox h-6 w-6 text-accent">
                    <span class="ml-3 text-sm">Split the image into {{ \App\Support\ImagePieces::pieceMb() }} MB pieces</span>
                  </label>
                  <p class="text-xs text-fg-muted mt-1">A module restarted during the write continues from the last piece it wrote. Turn on the verification too: it checks the whole written image, including the part written before the restart.</p>
                  @if ($split_image && ($chosen = $images->firstWhere('id', $image_id)))
                  <p class="text-xs text-fg-muted mt-1">Pieces of {{ $chosen->filename }}: {{ $chosen->splitState() }}</p>
                  @if (in_array($chosen->filename_extension, ['xz', 'bz2'], true))
                  <p class="text-xs text-fg-muted mt-1">The pieces are a gzip copy of this {{ $chosen->filename_extension }} image: the download grows.</p>
                  @endif
                  @endif
                  @error('split_image') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              <div class="mb-4">
                  <label class="inline-flex items-center">
                    <input type="checkbox" wire:model="reprovision" class="form-checkbox h-6 w-6 text-accent">
                    <span class="ml-3 text-sm">Re-provision from the web UI</span>
                  </label>
                  <p class="text-xs text-fg-muted mt-1">Puts the provisioner's public SSH key on this image. Its restricted agent can identify the device and erase its boot structure for re-provisioning. The key stays on each device; keep this off for images that should not permit remote re-provisioning. Requires eMMC/SD, MBR partitioning, an ext4 root on partition 2 and SSH root key access.</p>
                  @error('reprovision') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
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
              <div class="mb-4">
                  <label for="storage" class="block text-fg text-sm font-bold mb-2">Destination storage device:</label>
                  <input type="text" id="storage" name="storage" wire:model="storage" class="cmp-field w-full">
                  @error('storage') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              <div class="mb-4">
                  <label for="firmware" class="block text-fg text-sm font-bold mb-2">EEPROM firmware update to apply:</label>
                  <select id="firmware" name="firmware" wire:model.live="firmware" class="cmp-field w-full">
                    <option value="">-none-</option>
                      @foreach ($firmwareChannels as $channel => $images)
                      <optgroup label="{{ $channel }}{{ $channel == 'default' ? ' (recommended by Raspberry Pi)' : (in_array($channel, ['stable', 'beta', 'critical']) ? ' (old channel name, images downloaded years ago)' : '') }}">
                        @foreach ($images as $fw)
                        <option value="{{ $fw->path }}">{{ $fw->name }}</option>
                        @endforeach
                      </optgroup>
                      @endforeach
                  </select>
                  @error('firmware') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              @if ($firmware != "")
              @if ($offerSettingsReset)
                <div class="cmp-flash my-3" role="alert">
                  <div class="flex">
                    <div>
                      <p class="text-sm">You are currently using custom EEPROM settings which
                                         differ from the default settings of the newly selected EEPROM image.</p>
                      <div class="mt-3 flex w-full rounded-md shadow-sm sm:mt-0 sm:w-auto">
                        <button wire:click="resetEEPROMsettings" class="border border-pline px-4 py-2 bg-panel text-base leading-6 font-medium text-fg shadow-sm hover:text-fg focus:outline-none focus:border-accent focus:ring focus:ring-accent transition ease-in-out duration-150 sm:text-sm sm:leading-5">
                          Reset settings to default
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              @endif
              <div class="mb-4">
                  <label for="eeprom_settings" class="block text-fg text-sm font-bold mb-2">EEPROM settings:</label>
                  <textarea type="text" id="eeprom_settings" name="eeprom_settings" wire:model="eeprom_settings" class="h-28 cmp-field w-full font-mono"></textarea>
                  @error('eeprom_settings') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              @endif
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

              <div class="mb-4">
                  <div class="block text-fg text-sm font-bold mb-2">Other options:</div>
                  <label class="inline-flex items-center">
                    <input type="checkbox" id="active" name="active" wire:model="active" class="form-checkbox h-6 w-6 text-accent">
                    <span class="ml-3 text-sm">Set as active project</span>
                  </label>
                  @error('active') <span class="text-neg">{{ $message }}</span>@enderror
              </div>     
        </div>
      </div>
  
      <div class="bg-head px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
        <span class="flex w-full rounded-md shadow-sm sm:ml-3 sm:w-auto">
          <button wire:click="store()" type="button" class="cmp-btn w-full">
            Save
          </button>
        </span>
        <span class="mt-3 flex w-full rounded-md shadow-sm sm:mt-0 sm:w-auto">
            
          <button wire:click="closeModal()" type="button" class="inline-flex justify-center w-full rounded-md border border-pline px-4 py-2 bg-panel text-base leading-6 font-medium text-fg shadow-sm hover:text-fg focus:outline-none focus:border-accent focus:ring focus:ring-accent transition ease-in-out duration-150 sm:text-sm sm:leading-5">
            Cancel
          </button>
        </span>
      </div>
        
    </div>
  </div>
</div>
