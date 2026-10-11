<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Fortify's 'confirm' option: two-factor login is on only after the user entered a valid code once */
class AddTwoFactorConfirmedAtToUsersTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('users', 'two_factor_confirmed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('two_factor_confirmed_at')->nullable();
            });
            // With 'confirm' Fortify asks for the code only when the setup is confirmed: users who turned
            // two-factor login on under 1.6, which had no confirmation step, would silently lose it
            DB::table('users')->whereNotNull('two_factor_secret')->update(['two_factor_confirmed_at' => now()]);
        }
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_confirmed_at');
        });
    }
}
