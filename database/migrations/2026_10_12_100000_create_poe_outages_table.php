<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* PoE restarts (docs/superpowers/specs/2026-10-12-poe-restart-design.md): a row per port before cmprovision switches
   its PoE off, closed (on_at) once it is on again or the operator forgot it; an open row with an error, or older
   than a restart takes, is a port left off */
class CreatePoeOutagesTable extends Migration
{
    public function up()
    {
        Schema::create('poe_outages', function (Blueprint $table) {
            $table->id();
            $table->string('switch_host');
            $table->unsignedInteger('if_index');
            $table->string('port');
            $table->string('oid');                       // the object that switches the port; on_value turns it on
            $table->unsignedTinyInteger('on_value');
            $table->string('cm_serial')->nullable();
            $table->string('user')->nullable();
            $table->timestamp('off_at');
            $table->timestamp('on_at')->nullable();
            $table->text('error')->nullable();           // why it could not be switched on again
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('poe_outages');
    }
}
