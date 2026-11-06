<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', fn (Blueprint $table) => $table->text('station_setup')->nullable());
        Schema::create('station_setup_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cm_id')->unique()->constrained('cms')->cascadeOnDelete();
            $table->string('run', 40);
            $table->text('settings')->nullable();
            $table->text('host_key')->nullable();
            $table->string('state')->default('pending');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('station_setup_runs');
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('station_setup'));
    }
};
