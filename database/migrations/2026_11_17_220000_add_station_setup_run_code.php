<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('station_setup_runs', function (Blueprint $table) {
            $table->text('web_setup_code')->nullable();
            $table->string('target_serial', 16)->nullable();
            $table->string('target_cid', 32)->nullable();
        });
    }
    public function down(): void
    {
        Schema::table('station_setup_runs', fn (Blueprint $table) => $table->dropColumn(['web_setup_code', 'target_serial', 'target_cid']));
    }
};
