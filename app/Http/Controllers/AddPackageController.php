<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Services\DebPackage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/* A .deb for the OS check (Scripts page, Packages) by a plain form, as the images come (Livewire handles large
   files badly); dpkg-deb tells what it is, the server keeps its SHA256 for the module to check */
class AddPackageController extends Controller
{
    public function store(Request $req, DebPackage $deb)
    {
        @set_time_limit(3600);
        $file = $req->file('package');
        // PHP drops a file over upload_max_filesize or post_max_size: the form arrives without it
        if (!$file || !$file->isValid())
            return $this->back('No package arrived: choose a .deb file; a file over the upload limit does not arrive either.');
        $name = $file->getClientOriginalName();
        if (strtolower($file->getClientOriginalExtension()) !== 'deb')
            return $this->back('Only .deb packages can be uploaded.');
        $fields = $deb->fields($file->getPathname());
        if (!$fields)
            return $this->back("'$name' is not a Debian package.");

        $package = new Package([
            'filename' => $name,
            'size' => $file->getSize(),
            'sha256' => hash_file('sha256', $file->getPathname()),
            'deb_package' => $fields['package'],
            'deb_version' => $fields['version'],
            'deb_architecture' => $fields['architecture'],
        ]);
        do {
            $package->filename_on_server = Str::random(40).'.deb';
        } while (file_exists($package->path()));
        $file->move(public_path('uploads/packages'), $package->filename_on_server);
        $package->save();

        return $this->back('Package '.$package->deb_package.' '.$package->deb_version.' ('.$package->deb_architecture.') added.');
    }

    protected function back(string $message)
    {
        session()->flash('message', $message);
        return redirect()->route('scripts');
    }
}
