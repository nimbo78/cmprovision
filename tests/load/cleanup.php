<?php
// Bench only: takes the rehearsal's stand away again (run like stand.php)
use App\Models\Cm;
use App\Models\EthernetSwitch;
use App\Models\Image;
use App\Models\NotificationChannel;
use App\Models\Project;
use App\Models\Setting;

Cm::where('serial', 'like', '10000000aa%')->delete();
NotificationChannel::where('name', 'load-48 sink')->delete();
$project = Project::where('name', 'load-48')->first();
if ($project && Setting::where('key', 'active_project')->value('value') == $project->id)
    Setting::where('key', 'active_project')->delete();
Project::where('name', 'load-48')->delete();
Image::where('filename', 'load-48.img.gz')->delete();
EthernetSwitch::whereIn('host', ['127.0.0.1:1161', '127.0.0.1:1162'])->delete();
echo "cleaned\n";
