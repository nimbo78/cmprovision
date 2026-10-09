<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Notifications: where to send them (channels), modules provisioned together (batches) and the
   thread each bot channel keeps per batch (the id of its summary message). */
class CreateNotificationTables extends Migration
{
    public function up()
    {
        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');              // mattermost_webhook, mattermost_bot, telegram, webhook
            $table->boolean('enabled')->default(true);
            $table->text('settings');            // JSON: url / server_url, token, channel_id / bot_token, chat_id, topic_id
            $table->text('events');              // JSON list: started, completed, failed
            $table->timestamp('last_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnUpdate()->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('last_event_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('notification_batches')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained('notification_channels')->cascadeOnDelete();
            $table->string('external_id');       // Mattermost post id or Telegram message id of the summary
            $table->timestamps();
            $table->unique(['batch_id', 'channel_id']);
        });

        Schema::table('cms', function (Blueprint $table) {
            $table->unsignedBigInteger('notification_batch_id')->nullable()->index();
        });
    }

    public function down()
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->dropColumn('notification_batch_id');
        });
        Schema::dropIfExists('notification_threads');
        Schema::dropIfExists('notification_batches');
        Schema::dropIfExists('notification_channels');
    }
}
