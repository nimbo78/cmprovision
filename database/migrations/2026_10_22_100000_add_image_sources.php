<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* 2.8.0, images from the internet (GitHub releases, Raspberry Pi OS, a link): where an image came from and how far its
   fetching is. An uploaded image keeps all of this empty and is ready as before. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->string('source_type')->nullable();              // github, raspberrypi, url
            $table->text('source')->nullable();                     // JSON for people: repository/tag/asset, catalog entry or the address without its query
            $table->text('download_url')->nullable();               // the address of the file as the operator gave it: stored encrypted (its query may be a token)
            $table->string('download_state')->nullable();           // queued, downloading, converting, checking, failed; empty once the image is accepted
            $table->unsignedBigInteger('download_bytes')->nullable();
            $table->unsignedBigInteger('download_total')->nullable();
            $table->timestamp('download_started_at')->nullable();
            $table->timestamp('download_updated_at')->nullable();
            $table->text('download_error')->nullable();
            $table->string('expected_sha256')->nullable();          // of the file as published
            $table->string('expected_uncompressed_sha256')->nullable();
            $table->string('checksum_source')->nullable();          // github-digest, sums-file, sha256-file, catalog, user, none
        });
    }

    public function down(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'source', 'download_url', 'download_state', 'download_bytes', 'download_total',
                'download_started_at', 'download_updated_at', 'download_error', 'expected_sha256', 'expected_uncompressed_sha256', 'checksum_source']);
        });
    }
};
