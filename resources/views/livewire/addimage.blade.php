{{-- Upload dialog. Without JavaScript the form posts normally; with it, imageUploader() (defined in
     images.blade.php) sends the file with XMLHttpRequest and shows progress, speed and remaining time. --}}
<div class="fixed z-10 inset-0 overflow-y-auto ease-out duration-300">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">

    <div class="fixed inset-0 transition-opacity">
      <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
    </div>

    <span class="hidden sm:inline-block sm:align-middle sm:h-screen"></span>​

    <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full" role="dialog" aria-modal="true" aria-labelledby="modal-headline"
         x-data="imageUploader({ maxSize: {{ (int) $maxfilesize }}, freeSpace: {{ (int) $freediskspace }}, csrf: '{{ csrf_token() }}', done: '{{ route('images') }}' })" x-init="init()">
      <form method="post" action="/addImage" enctype="multipart/form-data" @submit.prevent="start($refs.file)">
      @csrf
      <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
        <div class="">
              <div class="mb-4">
                  <label for="image" class="block text-gray-700 text-sm font-bold mb-2">Image file (.gz/.bz2/.xz):</label>
                  <input type="file" id="image" name="image" x-ref="file" x-bind:disabled="state != 'idle'" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring">
              </div>

              <div class="mb-4" x-show="state == 'uploading' || state == 'processing'" x-cloak>
                  <div class="w-full bg-gray-200 rounded-full h-4 overflow-hidden">
                      <div class="bg-blue-500 h-4" :style="'width: ' + percent + '%'"></div>
                  </div>
                  <p class="text-sm text-gray-700 mt-2 font-mono" x-text="statusLine"></p>
              </div>

              <div class="bg-red-100 border-t-4 border-red-500 rounded-b text-red-900 px-4 py-3 shadow-md my-3" role="alert" x-show="error" x-cloak>
                  <p class="text-sm" x-text="error"></p>
              </div>

              <div class="mb-4">
                <b>Notes:</b><br><br>
                  <ul class="px-6 list-disc">
                    <li> Images must be uploaded compressed (.gz/.bz2/.xz).
                    <li> Do NOT use tar.
                    <li> Upload file size limit configured in php.ini: {{ number_format($maxfilesize/1024/1024/1024, 1) }} GiB.
                    <li> Disk space available: {{ number_format( $freediskspace/1024/1024/1024, 1) }} GiB (should be at least twice the size of image).
                    <li> Be aware that after upload is finished it will compute the sha256sum on the server, which may take some time.
                  </ul>
              </div>

              @if ($os32bit)
                <div class="bg-teal-100 border-t-4 border-teal-500 rounded-b text-teal-900 px-4 py-3 shadow-md my-3" role="alert">
                  <div class="flex">
                    <div>
                      <p class="text-sm">The provisioning system seems to be running on a 32-bit OS. As a result image upload size is limited to 2 GB.</p>
                    </div>
                  </div>
                </div>
              @endif
        </div>
      </div>

      <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
        <span class="flex w-full rounded-md shadow-sm sm:ml-3 sm:w-auto" x-show="state == 'idle'">
          <button id="uploadbutton" type="submit" class="inline-flex justify-center w-full rounded-md border border-transparent px-4 py-2 bg-green-600 text-base leading-6 font-medium text-white shadow-sm hover:bg-green-500 focus:outline-none focus:border-green-700 focus:ring focus:ring-green-200 transition ease-in-out duration-150 sm:text-sm sm:leading-5">
            Upload
          </button>
        </span>
        <span class="flex w-full rounded-md shadow-sm sm:ml-3 sm:w-auto" x-show="state == 'uploading'" x-cloak>
          <button type="button" @click="cancel()" class="inline-flex justify-center w-full rounded-md border border-transparent px-4 py-2 bg-red-500 text-base leading-6 font-medium text-white shadow-sm hover:bg-red-700 focus:outline-none transition ease-in-out duration-150 sm:text-sm sm:leading-5">
            Cancel upload
          </button>
        </span>
        <span class="mt-3 flex w-full rounded-md shadow-sm sm:mt-0 sm:w-auto" x-show="state == 'idle'">
          <button wire:click="closeModal()" type="button" class="inline-flex justify-center w-full rounded-md border border-gray-300 px-4 py-2 bg-white text-base leading-6 font-medium text-gray-700 shadow-sm hover:text-gray-500 focus:outline-none focus:border-blue-300 focus:ring focus:ring-blue-200 transition ease-in-out duration-150 sm:text-sm sm:leading-5">
            Cancel
          </button>
        </span>
        </form>
      </div>

    </div>
  </div>
</div>
