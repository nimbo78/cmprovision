<div class="fixed z-50 inset-0 overflow-y-auto ease-out duration-300">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">

    <div class="fixed inset-0 transition-opacity">
      <div class="absolute inset-0" style="background: var(--scrim)"></div>
    </div>
  
    <span class="hidden sm:inline-block sm:align-middle sm:h-screen"></span>​
  
    <div class="inline-block align-bottom bg-panel rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full" role="dialog" aria-modal="true" aria-labelledby="modal-headline">
      <div class="bg-panel px-4 pt-5 pb-4 sm:p-6 sm:pb-4">

        @if (session()->has('message'))
          <div class="cmp-flash my-3" role="alert">
            <div class="flex">
              <div>
                <p class="text-sm">{{ session('message') }}</p>
              </div>
            </div>
          </div>
        @endif

        <div >
              <div class="mb-4">
                  <label for="name" class="block text-fg text-sm font-bold mb-2">Label name:</label>
                  <input type="text" id="name" name="name" wire:model="name" class="cmp-field w-full">
                  @error('name') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              <div class="mb-4">
                  <label for="printer_type" class="block text-fg text-sm font-bold mb-2">Method to send print job to printer:</label>
                  <select id="printer_type" name="printer_type" wire:model.live="printer_type" class="cmp-field w-full">
                    <option value="ftp">FTP</option>
                    <option value="command">Custom command</option>
                  </select>
                  @error('printer_type') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
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
                  <input type="password" id="ftp_password" name="ftp_password" wire:model="ftp_password" class="cmp-field w-full">
                  @error('ftp_password') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              @endif
              <div class="mb-4">
                  <label for="file_extension" class="block text-fg text-sm font-bold mb-2">File extension for print job:</label>
                  <input type="text" id="file_extension" name="file_extension" wire:model="file_extension" class="cmp-field w-full">
                  @error('file_extension') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              <div class="mb-4">
                  <label for="template" class="block text-fg text-sm font-bold mb-2">Label template:</label>
                  <textarea wrap="off" type="text" id="template" name="template" wire:model="template" class="h-48 cmp-field w-full font-mono"></textarea>
                  <small>Available variables:<br>$serial $mac $hostname (the host name, after the OS check) $provisionboard ("sw01 Gi0/3", or the jumpers) $switch $port</small><br>
                  @error('template') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
        </div>
      </div>
  
      <div class="bg-head px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
        <span class="flex w-full rounded-md shadow-sm sm:ml-3 sm:w-auto">
          <button wire:click="store()" type="button" class="cmp-btn w-full">
            Save
          </button>
        </span>
        <span class="flex w-full rounded-md shadow-sm sm:ml-3 sm:w-auto">
          <button wire:click="printTestLabel()" type="button" class="cmp-btn cmp-btn-secondary w-full">
            Print test label
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