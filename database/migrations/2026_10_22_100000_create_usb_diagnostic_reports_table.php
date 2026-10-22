<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usb_diagnostic_reports', function (Blueprint $table) {
            $table->id();
            $table->string('serial', 16);
            $table->string('boot_id', 36);
            $table->string('event_id', 36);
            $table->string('mac', 17)->nullable()->index();
            $table->string('usb_path', 255);
            $table->json('report');
            $table->timestamp('received_at');
            $table->unique(['serial', 'event_id']);
            $table->index(['serial', 'received_at']);
        });
    }
    public function down(): void { Schema::dropIfExists('usb_diagnostic_reports'); }
};
