<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('reprovision_operations', function (Blueprint $t) {
            $t->string('serial')->nullable()->change();
            $t->json('context')->nullable();
            $t->index(['mac','state']);
        });
    }
    public function down(): void {
        // Unchecked discoveries have no hardware serial and cannot exist in the old schema.
        \Illuminate\Support\Facades\DB::table('reprovision_operations')->whereNull('serial')->delete();
        Schema::table('reprovision_operations', function (Blueprint $t) {
            $t->dropIndex(['mac','state']); $t->dropColumn('context'); $t->string('serial')->nullable(false)->change();
        });
    }
};
