<?php

namespace App\Http\Livewire;

use Livewire\Component;
use App\Models\Image;
use \Illuminate\Http\UploadedFile;

class Images extends Component
{
    use \Livewire\WithFileUploads;

    public $images, $maxfilesize, $freediskspace, $hashPending = false;
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

    public function create()
    {
        $this->openModal();
    }

    public function cancel()
    {
        $this->closeModal();
    }
}
