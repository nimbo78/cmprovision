{{-- The window "Add from the internet" of the Images page: the address of an image, what it is, and Download (App\Http\Livewire\ImageSources) --}}
<div class="fixed z-50 inset-0 overflow-y-auto ease-out duration-300">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">

    <div class="fixed inset-0 transition-opacity">
      <div class="absolute inset-0" style="background: var(--scrim)"></div>
    </div>

    <span class="hidden sm:inline-block sm:align-middle sm:h-screen"></span>​

    <div class="inline-block align-bottom bg-panel rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full" role="dialog" aria-modal="true" aria-labelledby="sources-headline">
      <div class="bg-panel px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
        <h2 id="sources-headline" class="font-semibold text-xl text-fg leading-tight mb-4">Add an image from the internet</h2>

        <div class="mb-4">
          <label for="source_url" class="block text-fg text-sm font-bold mb-2">Address of the image (https):</label>
          <div class="flex gap-2">
            <input type="text" id="source_url" wire:model="url" wire:keydown.enter="check" placeholder="https://..." class="cmp-field w-full" autocomplete="off">
            <button type="button" wire:click="check" wire:loading.attr="disabled" wire:target="check" class="cmp-btn cmp-btn-secondary">Check</button>
          </div>
          <p class="text-xs text-fg-muted mt-1">The file must be .gz, .xz, .bz2 or .zip (a .zip is repacked later). The provisioner fetches it itself and goes on after an interruption.</p>
        </div>

        @if ($error)
        <div class="bg-neg-bg border-t-4 border-neg-bar rounded-b text-neg px-4 py-3 shadow-md my-3" role="alert">
          <p class="text-sm">{{ $error }}</p>
        </div>
        @endif

        @if ($probe)
        <div class="mb-4 text-sm" aria-live="polite">
          <p><span class="font-bold">{{ $probe['name'] ?: 'The file has no name of its own' }}</span> · {{ \App\Models\Image::size($probe['size']) }}</p>
          <p class="text-fg-muted">{{ $probe['room'] }}</p>
          @unless ($probe['ranges'])
          <p class="text-warn">The server does not resume interrupted downloads: an interruption restarts the file.</p>
          @endunless
        </div>

        <div class="mb-4">
          <label for="source_filename" class="block text-fg text-sm font-bold mb-2">Name of the image:</label>
          <input type="text" id="source_filename" wire:model="filename" class="cmp-field w-full" autocomplete="off">
        </div>

        <div class="mb-4">
          <label for="source_sum" class="block text-fg text-sm font-bold mb-2">Checksum (optional):</label>
          <input type="text" id="source_sum" wire:model="sum" placeholder="sha256, or the address of a .sha256 / SHA256SUMS file" class="cmp-field w-full font-mono" autocomplete="off">
          <p class="text-xs text-fg-muted mt-1">The downloaded file must match it, or it is deleted.</p>
        </div>

        @if (trim($sum) === '')
        <div class="mb-4">
          <label class="flex items-center text-sm text-fg">
            <input type="checkbox" wire:model="unverified" class="form-checkbox mr-2">
            Add without a checksum
          </label>
        </div>
        @endif
        @endif
      </div>

      <div class="bg-head px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
        @if ($probe)
        <span class="flex w-full rounded-md shadow-sm sm:ml-3 sm:w-auto">
          <button type="button" wire:click="download" wire:loading.attr="disabled" class="cmp-btn w-full">Download</button>
        </span>
        @endif
        <span class="mt-3 flex w-full rounded-md shadow-sm sm:mt-0 sm:w-auto">
          <button type="button" wire:click="close" class="inline-flex justify-center w-full rounded-md border border-pline px-4 py-2 bg-panel text-base leading-6 font-medium text-fg shadow-sm hover:text-fg focus:outline-none focus:border-accent focus:ring focus:ring-accent transition ease-in-out duration-150 sm:text-sm sm:leading-5">
            Cancel
          </button>
        </span>
      </div>
    </div>
  </div>
</div>
