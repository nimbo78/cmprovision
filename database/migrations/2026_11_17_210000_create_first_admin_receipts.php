<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('first_admin_receipts', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('claim', 32)->nullable();
            // The receipt must survive deletion of the web account.
            $table->unsignedBigInteger('user_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('first_admin_receipts');
    }
};
