<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* 2.5: provisioning events wait here for FlushNotifications, a row per channel */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained('notification_channels')->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('notification_batches')->nullOnDelete();
            $table->foreignId('cm_id')->nullable()->constrained('cms')->nullOnDelete();
            $table->string('event', 16);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['sent_at', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_outbox');
    }
};
