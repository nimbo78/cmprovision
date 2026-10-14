<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* 2.5: a module's board is looked up in the background (App\Jobs\ResolveBoards); when its start asked for it */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->timestamp('board_pending_since')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->dropIndex(['board_pending_since']);
            $table->dropColumn('board_pending_since');
        });
    }
};
