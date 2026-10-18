<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', fn (Blueprint $t) => $t->boolean('reprovision')->default(false));
        Schema::table('cms', fn (Blueprint $t) => $t->string('provisioning_storage')->nullable());
        // Old resume marks have no snapshot of the destination path. The next interrupted write starts over once.
        DB::table('cms')->whereNotNull('durable_bytes')->update(['durable_bytes' => null, 'durable_at' => null, 'durable_image_id' => null]);
        Schema::create('reprovision_operations', function (Blueprint $t) {
            $t->string('id', 32)->primary();
            $t->string('serial')->index();
            $t->unsignedBigInteger('user_id');
            $t->string('session_hash', 64);
            $t->string('state')->default('checking');
            $t->string('supersedes_id', 32)->nullable();
            $t->unsignedBigInteger('project_id');
            $t->string('project_hash', 64);
            $t->string('address');
            $t->string('mac');
            $t->string('run_started_at')->nullable();
            $t->string('method')->nullable();
            $t->string('username')->nullable();
            $t->json('identity')->nullable();
            $t->boolean('password_changed')->default(false);
            $t->text('message')->nullable();
            $t->timestamp('expires_at');
            $t->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('reprovision_operations');
        Schema::table('cms', fn (Blueprint $t) => $t->dropColumn('provisioning_storage'));
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn('reprovision'));
    }
};
