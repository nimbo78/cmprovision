<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->string('diagnostics_token', 40)->nullable();
            $table->json('storage_diagnostics')->nullable();
            $table->timestamp('storage_diagnostics_at')->nullable();
            $table->timestamp('diagnostics_requested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->dropColumn(['diagnostics_token', 'storage_diagnostics', 'storage_diagnostics_at', 'diagnostics_requested_at']);
        });
    }
};
