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
                  <label for="mark_boots" class="block text-sm mt-3">Mark the device over LLDP for the first boots</label>
                  <input id="mark_boots" type="number" min="0" max="{{ \App\Services\Sensor\ModuleMark::MAX_BOOTS }}" wire:model="mark_boots" class="cmp-field w-24">
                  <p class="text-xs text-fg-muted mt-1">With re-provisioning on, the agent tells the switch for this many boots that this provisioner flashed the device, with a code that changes on every boot; then the mark removes itself. A provisioner with the same mark key (php artisan sensor:mark-key) recognises it on the Ports page. 0: no mark. The written system needs lldpd and openssl.</p>
                  @error('mark_boots') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
