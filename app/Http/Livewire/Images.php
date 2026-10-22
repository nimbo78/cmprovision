<?php

namespace App\Http\Livewire;

use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;
use App\Models\Image;
use App\Services\ImageDownloads;
use App\Services\ImageSpace;
use App\Support\FetchSpeed;
use \Illuminate\Http\UploadedFile;

#[Title('Images')]
class Images extends Component
{

    public $images, $maxfilesize, $freediskspace, $hashPending = false, $splitPending = false;
    public $isOpen = false;
    // images from the internet (2.8.0): the window, the images on their way, what each fetch runs at, the free space they have
    public $sourcesOpen = false, $fetching = false, $speeds = [], $imageSpace = 0;
    public $os32bit = false;

    public function render()
    {
        $this->images = Image::orderBy('filename')->orderBy('id')->get();
        $this->maxfilesize = UploadedFile::getMaxFilesize();
        // uploads pass through PHP's temporary directory before landing in public/uploads
        $tmpdir = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
        $uploads = public_path('uploads');
        $this->freediskspace = min(
            is_dir($tmpdir) ? disk_free_space($tmpdir) : 0,
            is_dir($uploads) ? disk_free_space($uploads) : 0
        );
        $this->os32bit = (PHP_INT_MAX == 2147483647);
        // a hash is pending for an image that is ready to be hashed; a fetch has its own state
        $this->hashPending = Image::where('sha256', '')->whereNull('download_state')->exists();
        $this->fetching = $this->images->contains(fn ($image) => in_array($image->download_state, ImageSpace::IN_FLIGHT, true));
        $this->speeds = [];
        foreach ($this->images as $image)
            if ($image->download_state === 'downloading')
                $this->speeds[$image->id] = FetchSpeed::note($image);
        $this->imageSpace = app(ImageSpace::class)->free();
        $this->splitPending = $this->images->contains(function ($image) { return $image->splitPreparing(); });

        return view('livewire.images');
    }

    public function openModal()
    {
        $this->isOpen = true;
    }

    public function openSources()
    {
        $this->sourcesOpen = true;
    }

    #[On('image-sources-closed')]
    public function closeSources()
    {
        $this->sourcesOpen = false;
    }

    /* a failed fetch goes on from what is there */
    public function retry($id)
    {
        app(ImageDownloads::class)->retry(Image::findOrFail($id));
    }

    public function closeModal()
    {
        $this->isOpen = false;
    }
    
    public function delete($id)
    {
        Image::destroy($id);
        session()->flash('message', 'Image deleted.');
    }

    /* The pieces copy (2.6.1) that no project with "Split the image into pieces" needs */
    public function removeSplit($id)
    {
        $image = Image::findOrFail($id);
        if ($image->splitNeeded())
        {
            session()->flash('message', 'A project with "Split the image into pieces" writes this image: its pieces stay.');
            return;
        }
        $image->removeSplit();
        session()->flash('message', 'Pieces removed.');
    }

    public function retrySplit($id)
    {
        $image = Image::findOrFail($id);
        if ($image->splitNeeded())
            $image->queueSplit();
    }

    public function create()
    {
        $this->openModal();
    }

    public function cancel()
    {
        $this->closeModal();
    }
}
