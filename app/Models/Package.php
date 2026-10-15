<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/* A .deb the OS check installs on a module's first boot (Scripts page, Packages); nginx serves the file from
   public/uploads/packages, as it serves the images */
class Package extends Model
{
    protected $fillable = ['filename', 'filename_on_server', 'size', 'sha256', 'deb_package', 'deb_version', 'deb_architecture'];
    protected $casts = ['size' => 'integer'];

    public function projects()
    {
        return $this->belongsToMany(Project::class);
    }

    public function path(): string
    {
        return public_path('uploads/packages/'.$this->filename_on_server);
    }
}
