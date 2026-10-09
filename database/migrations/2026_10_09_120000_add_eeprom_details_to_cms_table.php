<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* The bootloader before and after provisioning: what the module reported when it started (version
   and settings), the settings the project flashed, and whether flashrom wrote the EEPROM
   ('written'), found it holding the image already ('identical') or failed ('failed').
   cms.firmware stays what the EEPROM holds after provisioning. */
class AddEepromDetailsToCmsTable extends Migration
{
    public function up()
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->text('eeprom_before')->nullable();
            $table->text('eeprom_config_before')->nullable();
            $table->text('eeprom_config_after')->nullable();
            $table->string('eeprom_result')->nullable();
        });
    }

    public function down()
    {
        Schema::table('cms', function (Blueprint $table) {
            $table->dropColumn(['eeprom_before', 'eeprom_config_before', 'eeprom_config_after', 'eeprom_result']);
        });
    }
}
