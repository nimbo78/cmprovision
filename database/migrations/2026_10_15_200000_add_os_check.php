<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* 2.6.0, the OS check after the first boot (docs/superpowers/specs/2026-10-15-os-check-design.md): the project's
   switch, a script type and a label moment for it, and what the module's new system reports.
   scripts.script_type and projects.label_moment were enums, a CHECK constraint in SQLite: they become strings (the app
   validates them), so a new value never again needs a rebuilt table. ->change() rebuilds both tables: Laravel switches
   the foreign keys off around the rebuild, which SQLite ignores inside a transaction; dropping the old table would then
   delete the project_script rows and clear the project of every module and batch (cascades; seen on the bench). Laravel
   13 runs SQLite's migrations outside a transaction anyway (its SQLite grammar has no schema transactions): this one
   says so itself, should that change. */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->string('script_type', 20)->change();
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->string('label_moment', 20)->change();
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('os_check')->default(false);
        });
        Schema::table('cms', function (Blueprint $table) {
            // pending (checked once written), waiting (rebooted into the new system), passed, failed, skipped (USB boot)
            $table->string('os_check', 10)->nullable();
            $table->string('hostname', 64)->nullable();
            $table->string('os_state', 20)->nullable();
            $table->text('os_failed_units')->nullable();
            $table->timestamp('os_checked_at')->nullable();
            $table->text('os_check_output')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->dropColumn(['os_check', 'hostname', 'os_state', 'os_failed_units', 'os_checked_at', 'os_check_output']);
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('os_check');
        });
        // what 2.5 cannot hold: its label moment falls back to "after provisioning", its scripts go
        DB::table('projects')->where('label_moment', 'firstboot')->update(['label_moment' => 'postinstall']);
        DB::table('scripts')->where('script_type', 'firstboot')->delete();
        Schema::table('scripts', function (Blueprint $table) {
            $table->enum('script_type', ['postinstall', 'preinstall'])->change();
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->enum('label_moment', ['never', 'preinstall', 'postinstall'])->change();
        });
    }
};
