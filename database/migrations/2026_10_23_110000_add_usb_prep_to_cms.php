<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* 2.12: when the USB preparation of a board began; empty for a board whose last run was an ordinary one */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('cms', 'usb_prep_at')) {
            Schema::table('cms', fn (Blueprint $table) => $table->timestamp('usb_prep_at')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cms', 'usb_prep_at')) {
            Schema::table('cms', fn (Blueprint $table) => $table->dropColumn('usb_prep_at'));
        }
    }
};
