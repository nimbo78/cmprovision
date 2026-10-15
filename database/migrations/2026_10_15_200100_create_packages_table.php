<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* 2.6.0: the .deb packages the OS check installs on a module's first boot, and the projects that install them */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('filename');                        // as uploaded
            $table->string('filename_on_server')->unique();    // public/uploads/packages/<random>.deb
            $table->unsignedBigInteger('size');
            $table->string('sha256', 64);
            $table->string('deb_package');
            $table->string('deb_version');
            $table->string('deb_architecture', 32);
            $table->timestamps();
        });
        Schema::create('package_project', function (Blueprint $table) {
            $table->foreignId('package_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->primary(['package_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_project');
        Schema::dropIfExists('packages');
    }
};
