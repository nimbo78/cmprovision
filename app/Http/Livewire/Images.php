<?php

namespace App\Http\Livewire;

use Livewire\Attributes\Title;
use Livewire\Component;
use App\Models\Image;
use \Illuminate\Http\UploadedFile;

#[Title('Images')]
class Images extends Component
{

    public $images, $maxfilesize, $freediskspace, $hashPending = false, $splitPending = false;
    public $isOpen = false;
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
        $this->hashPending = Image::where('sha256', '')->exists();
        $this->splitPending = $this->images->contains(function ($image) { return $image->splitPreparing(); });

        return view('livewire.images');
    }

    public function openModal()
    {
        $this->isOpen = true;
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
