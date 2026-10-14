<?php
// Bench only (tests/load/README.md): the rehearsal's stand in the installed app, run by tinker as www-data
use App\Models\EthernetSwitch;
use App\Models\Image;
use App\Models\NotificationBatch;
use App\Models\NotificationChannel;
use App\Models\Project;
use App\Models\Setting;

EthernetSwitch::firstOrCreate(['host' => '127.0.0.1:1161'], ['community' => 'stand48a', 'snmp_version' => '2c']);
EthernetSwitch::firstOrCreate(['host' => '127.0.0.1:1162'], ['community' => 'stand48b', 'snmp_version' => '2c']);
$image = Image::firstOrNew(['filename' => 'load-48.img.gz']);
$image->forceFill(['filename_extension' => 'gz', 'filename_on_server' => 'load-48.img.gz', 'sha256' => str_repeat('a', 64),
    'uncompressed_size' => 8 * 1024 * 1024])->save();
$project = Project::firstOrCreate(['name' => 'load-48'], ['device' => 'cm4', 'storage' => '/dev/mmcblk0',
    'image_id' => $image->id, 'label_moment' => 'never', 'verify' => false]);
Setting::updateOrCreate(['key' => 'active_project'], ['value' => $project->id]);
NotificationBatch::startNew();
NotificationChannel::firstOrCreate(['name' => 'load-48 sink'], ['type' => 'telegram', 'enabled' => true,
    'settings' => ['bot_token' => '1:x', 'chat_id' => '-1'], 'events' => ['started', 'completed', 'failed']]);
echo 'batches: ', NotificationBatch::count(), ', switches: ', EthernetSwitch::count(), "\n";
