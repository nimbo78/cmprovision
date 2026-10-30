<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* The provisioner's mark over LLDP (2.23.0, App\Services\Sensor\ModuleMark): for how many boots of the written system the
   re-provision agent's mark names the provisioner to the switch; 0, no mark */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', fn (Blueprint $t) => $t->unsignedSmallInteger('mark_boots')->default(0));
    }

    public function down(): void
    {
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('mark_boots'));
    }
};
