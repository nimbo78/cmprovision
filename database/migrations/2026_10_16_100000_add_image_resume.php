<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* 2.6.1, the image resume: the project's checkbox for the pieces copy of its image, the copy itself (App\Jobs\SplitImage),
   and how far each module's run on the copy is written for sure */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->boolean('split_image')->default(false);
        });
        Schema::table('images', function (Blueprint $table) {
            $table->string('split_filename')->nullable();
            $table->text('split_offsets')->nullable();
            $table->unsignedInteger('split_piece_mb')->nullable();
            $table->text('split_error')->nullable();
            $table->timestamp('split_queued_at')->nullable();
        });
        Schema::table('cms', function (Blueprint $table) {
            $table->unsignedBigInteger('durable_bytes')->nullable();
            $table->unsignedBigInteger('durable_image_id')->nullable();
            $table->unsignedBigInteger('progress_base')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->dropColumn(['durable_bytes', 'durable_image_id', 'progress_base']);
        });
        Schema::table('images', function (Blueprint $table) {
            $table->dropColumn(['split_filename', 'split_offsets', 'split_piece_mb', 'split_error', 'split_queued_at']);
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('split_image');
        });
    }
};
