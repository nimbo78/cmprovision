<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Host extends Model
{
    use HasFactory;

    protected $fillable = ['mac', 'ip', 'hostname'];

    public static function firstAvailableIP()
    {
        $net = "172.20";

        for ($i = 0; $i < 256; $i++)
        {
            for ($j = 0; $j < 256; $j++)
            {
                if ($i == 0 && $j <= 10)
                    continue; /* 172.20.0.0 is the network, .1 the provisioner, .2 to .10 are kept for hosts set by hand (a second
                                 station on the stand); the DHCP pool of dnsmasq.conf starts at .11 too */

                $ip = "$net.$i.$j";
                if (!Host::firstWhere('ip', $ip))
                {
                    return $ip;
                }
            }
        }

        return "";
    }
}
