<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* "Identify": a module that finished keeps asking the server whether to blink (polled_at, noted at
   most once a minute, says it is still on the bench); the operator's request lasts until identify_until. */
class AddIdentifyToCmsTable extends Migration
{
    public function up()
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->timestamp('polled_at')->nullable();
            $table->timestamp('identify_until')->nullable();
        });
    }

    public function down()
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->dropColumn(['polled_at', 'identify_until']);
        });
    }
}
