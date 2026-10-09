<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* The steps of the module's last run as JSON: [{phase, detail, at (unix time)}, ...], one entry each
   time the phase or the script changes (Cm::setPhase). The module card shows them as a timeline. */
class AddTimelineToCmsTable extends Migration
{
    public function up()
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->text('timeline')->nullable();
        });
    }

    public function down()
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->dropColumn('timeline');
        });
    }
}
