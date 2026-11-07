<div class="fixed z-50 inset-0 overflow-y-auto ease-out duration-300">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">

    <div class="fixed inset-0 transition-opacity">
      <div class="absolute inset-0" style="background: var(--scrim)"></div>
    </div>
  
    <span class="hidden sm:inline-block sm:align-middle sm:h-screen"></span>​
  
    <div class="inline-block align-bottom bg-panel rounded-lg text-left overflow-hidden shadow-xl relative transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full" role="dialog" aria-modal="true" aria-label="Project settings">
      <div class="bg-panel px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
        <div >
              @include('livewire.project-fields.name')
              @include('livewire.project-fields.image')
              @include('livewire.project-fields.checks')
              @include('livewire.project-fields.station')
              @include('livewire.project-fields.packages')
              @include('livewire.project-fields.storage')
              @include('livewire.project-fields.firmware')
              @include('livewire.project-fields.label')
              @include('livewire.project-fields.scripts')
              @include('livewire.project-fields.active')
        </div>
      </div>
  
      <div class="bg-head px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
        <span class="flex w-full rounded-md shadow-sm sm:ml-3 sm:w-auto">
          @if ($stationEnabled || $savedStationSetup)
          <x-confirms-password wire:then="store"><button type="button" class="cmp-btn w-full">Save</button></x-confirms-password>
          @else
          <button wire:click="store()" type="button" class="cmp-btn w-full">
            Save
          </button>
          @endif
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
