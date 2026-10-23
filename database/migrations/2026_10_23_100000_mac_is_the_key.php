<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 2.11: the MAC address is the key of a module. The MACs are written in one way, a MAC two modules share stays with the
 * one updated last (the other gets a locally administered one made of its serial) and is unique from now on; the lines
 * of a module's log and the PoE outage of its port name its MAC instead of its serial. Safe to run again.
 */
return new class extends Migration {
    public function up(): void
    {
        $taken = [];
        foreach (DB::table('cms')->orderByDesc('updated_at')->orderByDesc('id')->get(['id', 'serial', 'mac']) as $row) {
            $mac = strtolower(str_replace('-', ':', trim((string) $row->mac)));
            if ($mac === '' || isset($taken[$mac])) {
                $mac = $this->pseudo((string) $row->serial, $taken);
            }
            $taken[$mac] = true;
            if ($mac !== $row->mac) {
                DB::table('cms')->where('id', $row->id)->update(['mac' => $mac]);
            }
        }
        if (!Schema::hasIndex('cms', 'cms_mac_unique')) {
            Schema::table('cms', fn (Blueprint $table) => $table->unique('mac'));
        }

        DB::statement('UPDATE cmlogs SET cm = (SELECT mac FROM cms WHERE cms.serial = cmlogs.cm) WHERE cm IN (SELECT serial FROM cms)');

        if (Schema::hasColumn('poe_outages', 'cm_serial')) {
            Schema::table('poe_outages', fn (Blueprint $table) => $table->renameColumn('cm_serial', 'cm_mac'));
            DB::statement('UPDATE poe_outages SET cm_mac = (SELECT mac FROM cms WHERE cms.serial = poe_outages.cm_mac) WHERE cm_mac IN (SELECT serial FROM cms)');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('poe_outages', 'cm_mac')) {
            DB::statement('UPDATE poe_outages SET cm_mac = (SELECT serial FROM cms WHERE cms.mac = poe_outages.cm_mac) WHERE cm_mac IN (SELECT mac FROM cms)');
            Schema::table('poe_outages', fn (Blueprint $table) => $table->renameColumn('cm_mac', 'cm_serial'));
        }

        DB::statement('UPDATE cmlogs SET cm = (SELECT serial FROM cms WHERE cms.mac = cmlogs.cm) WHERE cm IN (SELECT mac FROM cms)');

        if (Schema::hasIndex('cms', 'cms_mac_unique')) {
            Schema::table('cms', fn (Blueprint $table) => $table->dropUnique('cms_mac_unique'));
        }
    }

    /* 02:, then the last five bytes of the serial; a collision with a MAC already given moves it one up */
    private function pseudo(string $serial, array $taken): string
    {
        $hex = substr(str_pad(preg_replace('/[^0-9a-f]/', '0', strtolower($serial)), 10, '0', STR_PAD_LEFT), -10);
        $number = hexdec($hex);
        do {
            $mac = '02:'.implode(':', str_split(str_pad(dechex($number), 10, '0', STR_PAD_LEFT), 2));
            $number++;
        } while (isset($taken[$mac]));

        return $mac;
    }
};
