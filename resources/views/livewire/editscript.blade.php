<div class="fixed z-10 inset-0 overflow-y-auto ease-out duration-300">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
      
    <div class="fixed inset-0 transition-opacity">
      <div class="absolute inset-0" style="background: var(--scrim)"></div>
    </div>
  
    <span class="hidden sm:inline-block sm:align-middle sm:h-screen"></span>​
  
    <div class="inline-block align-bottom bg-panel rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full" role="dialog" aria-modal="true" aria-labelledby="modal-headline">
      <div class="bg-panel px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
        <div >
              <div class="mb-4">
                  <label for="name" class="block text-fg text-sm font-bold mb-2">Script name:</label>
                  <input type="text" id="name" name="name" wire:model="name" class="shadow appearance-none border rounded w-full py-2 px-3 text-fg leading-tight focus:outline-none focus:ring">
                  @error('name') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              <div class="mb-4">
                  <label for="script_type" class="block text-fg text-sm font-bold mb-2">Script type:</label>
                  <select id="script_type" name="script_type" wire:model="script_type" class="shadow appearance-none border rounded w-full py-2 px-3 text-fg leading-tight focus:outline-none focus:ring">
                    <option value="preinstall">preinstall</option>
                    <option value="postinstall">postinstall</option>
                  </select>
                  @error('script_type') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              <div class="mb-4">
                  <label for="priority" class="block text-fg text-sm font-bold mb-2">Priority:</label>
                  <input type="text" id="priority" name="priority" wire:model="priority" class="shadow appearance-none border rounded w-full py-2 px-3 text-fg leading-tight focus:outline-none focus:ring">
                  <small>Script with lowest number is started first</small><br>
                  @error('priority') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              <div class="mb-4">
                  <label class="inline-flex items-center">
                    <input type="checkbox" id="bg" name="bg" wire:model="bg" class="form-checkbox h-6 w-6 text-accent">
                    <span class="ml-3 text-sm">Run in background</span>
                  </label>
                  @error('bg') <span class="text-neg">{{ $message }}</span>@enderror
              </div>
              <div class="mb-4">
                  <label for="script" class="block text-fg text-sm font-bold mb-2">Script:</label>
                  <textarea wrap="off" type="text" id="script" name="script" wire:model="script" class="h-48 shadow appearance-none border rounded w-full py-2 px-3 text-fg leading-tight focus:outline-none focus:ring"></textarea>
                  <small>Available environment variables:<br>$SERVER $STORAGE $PART1 $PART2</small><br>
                  @error('script') <span class="text-neg">{{ $message }}</span>@enderror
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