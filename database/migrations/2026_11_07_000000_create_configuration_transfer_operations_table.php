<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_transfer_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('session_hash', 64);
            $table->string('direction', 6);
            $table->string('status', 24)->default('created');
            $table->string('format', 16)->nullable();
            $table->text('options')->nullable();
            $table->text('secrets')->nullable();
            $table->text('result')->nullable();
            $table->text('error')->nullable();
            $table->unsignedBigInteger('progress_bytes')->default(0);
            $table->unsignedBigInteger('total_bytes')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_transfer_operations');
    }
};
