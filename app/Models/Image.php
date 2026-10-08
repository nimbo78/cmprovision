<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    use HasFactory;

    protected $casts = ['uncompressed_size' => 'integer'];

    function imagepath()
    {
        return public_path('uploads/'.$this->filename_on_server);
    }

    /* Size of the compressed file on disk, or null when the file is gone */
    function filesize()
    {
        return is_file($this->imagepath()) ? filesize($this->imagepath()) : null;
    }

    function delete()
    {
        // Delete image from filesystem, if it is still there
        if (is_file($this->imagepath()))
            unlink($this->imagepath());

        // Delete from database
        parent::delete();
    }
}
