<div class="fixed z-50 inset-0 overflow-y-auto ease-out duration-300">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">

    <div class="fixed inset-0 transition-opacity">
      <div class="absolute inset-0" style="background: var(--scrim)"></div>
    </div>
  
    <span class="hidden sm:inline-block sm:align-middle sm:h-screen"></span>​
  
    <div class="inline-block align-bottom bg-panel rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full" role="dialog" aria-modal="true" aria-labelledby="modal-headline">
      <div class="bg-panel px-4 pt-5 pb-4 sm:p-6 sm:pb-4">

        <div >
              <div class="mb-4">
                  <label for="ip" class="block text-fg text-sm font-bold mb-2">IP:</label>
                  <input type="text" id="ip" name="ip" wire:model="ip" class="cmp-field w-full">
                  @error('ip') <span class="text-neg">{{ $message }}</span>@enderror
              </div>

              <div class="mb-4">
                  <label for="mac" class="block text-fg text-sm font-bold mb-2">MAC:</label>
                  <input type="text" id="mac" name="mac" wire:model="mac" class="cmp-field w-full">
                  @error('mac') <span class="text-neg">{{ $message }}</span>@enderror
              </div>

              <div class="mb-4">
                  <div class="block text-fg text-sm font-bold mb-2">List of unknown hosts that attempted to obtain DHCP recently:</div>
                  @forelse($detectedMacs as $m)
                  {{ $m }}<br>
                  @empty
                  No entries
                  @endforelse
              </div>
        </div>
      </div>
  
      <div class="bg-head px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
        <span class="flex w-full rounded-md shadow-sm sm:ml-3 sm:w-auto">
          <button wire:click="storeStaticIP()" type="button" class="cmp-btn w-full">
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