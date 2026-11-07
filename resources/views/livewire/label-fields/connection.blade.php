              @if ($printer_type == "command")
              <div class="mb-4">
                  <label for="print_command" class="block text-fg text-sm font-bold mb-2">Command to queue print job:</label>
                  <input type="text" id="print_command" name="print_command" wire:model="print_command" class="cmp-field w-full">
                  @error('print_command') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              @elseif ($printer_type == "ftp")
              <div class="mb-4">
                  <label for="ftp_hostname" class="block text-fg text-sm font-bold mb-2">FTP hostname:</label>
                  <input type="text" id="ftp_hostname" name="ftp_hostname" wire:model="ftp_hostname" class="cmp-field w-full">
                  @error('ftp_hostname') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              <div class="mb-4">
                  <label for="ftp_username" class="block text-fg text-sm font-bold mb-2">FTP user:</label>
                  <input type="text" id="ftp_username" name="ftp_username" wire:model="ftp_username" class="cmp-field w-full">
                  @error('ftp_username') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              <div class="mb-4">
                  <label for="ftp_password" class="block text-fg text-sm font-bold mb-2">FTP password:</label>
                  @if ($wizardMode)
                  <input type="password" id="ftp_password" x-model="wizardCredentials.ftp_password" class="cmp-field w-full" autocomplete="new-password" placeholder="Leave empty to retain this destination's saved password">
                  @else
                  <input type="password" id="ftp_password" name="ftp_password" wire:model="ftp_password" class="cmp-field w-full">
                  @endif
                  @error('ftp_password') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              @endif
