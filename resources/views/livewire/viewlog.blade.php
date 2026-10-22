<div class="fixed z-50 inset-0 overflow-y-auto ease-out duration-300">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
      
    <div class="fixed inset-0 transition-opacity">
      <div class="absolute inset-0" style="background: var(--scrim)"></div>
    </div>
  
    <!-- This element is to trick the browser into centering the modal contents. -->
    <span class="hidden sm:inline-block sm:align-middle sm:h-screen"></span>​
  
    <div class="inline-block align-bottom bg-panel rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-5xl sm:w-full" role="dialog" aria-modal="true" aria-labelledby="modal-headline">
      <div class="bg-panel px-4 pt-5 pb-4 sm:p-6 sm:pb-4 h-full">
        <h2 class="font-semibold text-xl text-fg leading-tight">{{ $logTitle }}</h2>
        <br>

        <livewire:service-log :source="$logSource" :key="'log-'.$logSource" />

      </div>
 
      <div class="bg-head px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
        <span class="mt-3 flex w-full rounded-md shadow-sm sm:mt-0 sm:w-auto">
          <button type="button" wire:click="closeModal()" type="button" class="inline-flex justify-center w-full rounded-md border border-pline px-4 py-2 bg-panel text-base leading-6 font-medium text-fg shadow-sm hover:text-fg focus:outline-none focus:border-accent focus:ring focus:ring-accent transition ease-in-out duration-150 sm:text-sm sm:leading-5">
            Close
          </button>
        </span>
      </div>
    </div>
  </div>
</div>
