<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('serial_console_access')->default(false));
        $first = DB::table('first_admin_receipts')->where('id', 1)->value('user_id');
        if ($first === null && DB::table('users')->count() === 1) {
            $first = DB::table('users')->value('id');
        }
        if ($first !== null) {
            DB::table('users')->where('id', $first)->update(['serial_console_access' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('serial_console_access'));
    }
};
