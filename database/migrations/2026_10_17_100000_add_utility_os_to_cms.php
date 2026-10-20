<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* 2.7.0: the utility OS of the module's last start, from the uos= parameter of ours (empty: the legacy one) */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->string('utility_os', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->dropColumn('utility_os');
        });
    }
};
