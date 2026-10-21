<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* The switches of the stand (docs/operations.md#switches-and-poe-restart). The 2.3 settings become the
   first one and stay where they were: a 2.3.x installed back over this database still finds its switch. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ethernet_switches', function (Blueprint $table) {
            $table->id();
            $table->string('host')->unique();
            $table->string('snmp_version')->default('2c');
            foreach (['community', 'user', 'auth_protocol', 'auth_password', 'priv_protocol', 'priv_password',
                      'write_community', 'write_user', 'write_auth_protocol', 'write_auth_password', 'write_priv_protocol',
                      'write_priv_password'] as $column)
                $table->string($column)->nullable();
            $table->string('method')->default('auto');
            $table->string('method_detected')->nullable();
            $table->string('sys_name')->nullable();
            $table->string('base_mac')->nullable()->unique();
            $table->text('own_macs')->nullable();   // JSON
            $table->text('links')->nullable();      // JSON: ifIndex => id of the neighbour
            $table->timestamps();
        });
        Schema::table('cms', function (Blueprint $table) {
            $table->unsignedBigInteger('switch_id')->nullable()->index();
            $table->string('switch_name')->nullable();
            $table->string('switch_port')->nullable();
        });
        Schema::table('poe_outages', function (Blueprint $table) {
            $table->unsignedBigInteger('switch_id')->nullable()->index();
        });
        $this->copyLegacySettings();
    }

    public function down(): void
    {
        Schema::table('poe_outages', function (Blueprint $table) {
            $table->dropIndex(['switch_id']);
            $table->dropColumn('switch_id');
        });
        Schema::table('cms', function (Blueprint $table) {
            $table->dropIndex(['switch_id']);
            $table->dropColumn(['switch_id', 'switch_name', 'switch_port']);
        });
        Schema::dropIfExists('ethernet_switches');
    }

    /* The one switch of 2.3 (settings ethernetswitch_*) as the first row; the keys are not removed */
    public function copyLegacySettings(): void
    {
        $get = function ($key) {
            $value = DB::table('settings')->where('key', 'ethernetswitch_'.$key)->value('value');
            return $value === null || $value === '' ? null : $value;
        };
        if ($get('ip') === null || DB::table('ethernet_switches')->where('host', $get('ip'))->exists())
            return;
        DB::table('ethernet_switches')->insert([
            'host' => $get('ip'), 'snmp_version' => $get('snmp_version') ?? '2c', 'community' => $get('snmp_community'),
            'user' => $get('snmp_user'), 'auth_protocol' => $get('snmp_auth_protocol'), 'auth_password' => $get('snmp_auth_password'),
            'priv_protocol' => $get('snmp_priv_protocol'), 'priv_password' => $get('snmp_priv_password'),
            'write_community' => $get('snmp_write_community'), 'write_user' => $get('snmp_write_user'),
            'write_auth_protocol' => $get('snmp_write_auth_protocol'), 'write_auth_password' => $get('snmp_write_auth_password'),
            'write_priv_protocol' => $get('snmp_write_priv_protocol'), 'write_priv_password' => $get('snmp_write_priv_password'),
            'method' => $get('method') ?? 'auto', 'method_detected' => $get('method_detected'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};
