<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Live provisioning status of a module: phase (preinstall, write, verify, postinstall, done, failed),
   the script or failure it is about, and how far the image write or verification has come. */
class AddProgressToCmsTable extends Migration
{
    public function up()
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->string('phase')->nullable();
            $table->string('phase_detail')->nullable();
            $table->timestamp('phase_started_at')->nullable();
            $table->unsignedBigInteger('progress_bytes')->nullable();
            $table->unsignedBigInteger('progress_total')->nullable();
            $table->timestamp('progress_updated_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->dropColumn(['phase', 'phase_detail', 'phase_started_at', 'progress_bytes', 'progress_total', 'progress_updated_at']);
        });
    }
}
