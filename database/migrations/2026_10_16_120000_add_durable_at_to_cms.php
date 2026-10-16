<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* 2.6.1, the image resume: when a module's last durable mark came, so that a run started after it (one that does not know
   the marks, as 2.6.0 after a rollback) never goes on from them */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->timestamp('durable_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->dropColumn('durable_at');
        });
    }
};
