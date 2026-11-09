<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Null preserves reports from scripts already running during an upgrade.
        Schema::table('cms', fn (Blueprint $table) => $table->string('report_token', 40)->nullable());
    }

    public function down(): void
    {
        Schema::table('cms', fn (Blueprint $table) => $table->dropColumn('report_token'));
    }
};
