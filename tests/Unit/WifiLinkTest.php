<?php

namespace Tests\Unit;

use App\Support\WifiLink;
use PHPUnit\Framework\TestCase;

/**
 * What `iw dev <if> link` and `iw dev <if> info` say about a Wi-Fi connection: network, band, channel,
 * width, signal, bitrates and the Wi-Fi generation from the bitrate flags (HT, VHT, HE, EHT).
 */
class WifiLinkTest extends TestCase
{
    /** MT7922 in the M.2 slot of the provisioner (mt7921e), captured on 2026-10-10 */
    const HE_LINK = "Connected to 78:45:58:82:dd:8b (on wlan1)\n\tSSID: 78\n\tfreq: 5765.0\n"
        ."\tRX: 28961313 bytes (25546 packets)\n\tTX: 1545931 bytes (9165 packets)\n\tsignal: -45 dBm\n"
        ."\trx bitrate: 1200.9 MBit/s 80MHz HE-MCS 11 HE-NSS 2 HE-GI 0 HE-DCM 0\n"
        ."\ttx bitrate: 1200.9 MBit/s 80MHz HE-MCS 11 HE-NSS 2 HE-GI 0 HE-DCM 0\n"
        ."\tbss flags: short-slot-time\n\tdtim period: 3\n\tbeacon int: 100\n";
    const HE_INFO = "Interface wlan1\n\tifindex 4\n\twdev 0x100000001\n\taddr 4c:d5:77:f7:ad:fb\n\tssid 78\n"
        ."\ttype managed\n\twiphy 1\n\tchannel 153 (5765 MHz), width: 80 MHz, center1: 5775 MHz\n\ttxpower 3.00 dBm\n";

    /** the CM4's own radio (brcmfmac, a FullMAC driver): bitrates without MCS flags */
    const ONBOARD_LINK = "Connected to 78:45:58:82:dd:8b (on wlan0)\n\tSSID: 78\n\tfreq: 5765.0\n"
        ."\tRX: 17044645 bytes (14101 packets)\n\tTX: 10611 bytes (69 packets)\n\tsignal: -54 dBm\n"
        ."\trx bitrate: 97.5 MBit/s\n\ttx bitrate: 24.0 MBit/s\n\tbss flags: \n\tdtim period: 3\n\tbeacon int: 100\n";
    const ONBOARD_INFO = "Interface wlan0\n\tifindex 3\n\twdev 0x1\n\taddr 2c:cf:67:30:7d:a3\n\tssid 78\n\ttype managed\n"
        ."\twiphy 0\n\tchannel 153 (5765 MHz), width: 80 MHz, center1: 5775 MHz\n\ttxpower 31.00 dBm\n";

    public function test_wifi_6_card_on_5_ghz()
    {
        $link = WifiLink::parse(self::HE_LINK, self::HE_INFO);

        $this->assertSame('78', $link['ssid']);
        $this->assertSame(5765, $link['freq']);
        $this->assertSame('5 GHz', $link['band']);
        $this->assertSame(153, $link['channel']);
        $this->assertSame(80, $link['width']);
        $this->assertSame(-45, $link['signal']);
        $this->assertSame(1200.9, $link['rx']);
        $this->assertSame(1200.9, $link['tx']);
        $this->assertSame('6', $link['generation']);
        $this->assertSame(2, $link['streams']);
    }

    public function test_fullmac_radio_reports_no_generation()
    {
        $link = WifiLink::parse(self::ONBOARD_LINK, self::ONBOARD_INFO);

        $this->assertSame(-54, $link['signal']);
        $this->assertSame(97.5, $link['rx']);
        $this->assertSame(24.0, $link['tx']);
        $this->assertNull($link['generation']);
        $this->assertNull($link['streams']);
        $this->assertSame(153, $link['channel']);
    }

    public function test_wifi_5_from_vht_flags()
    {
        $link = WifiLink::parse("Connected to 00:11:22:33:44:55 (on wlan0)\n\tSSID: lab\n\tfreq: 5180\n\tsignal: -71 dBm\n"
            ."\trx bitrate: 866.7 MBit/s VHT-MCS 9 80MHz short GI VHT-NSS 2\n\ttx bitrate: 650.0 MBit/s VHT-MCS 7 80MHz VHT-NSS 2\n");

        $this->assertSame('5', $link['generation']);
        $this->assertSame(2, $link['streams']);
        $this->assertSame(36, $link['channel']);
        $this->assertSame(80, $link['width']);
    }

    public function test_wifi_4_on_2_4_ghz_counts_streams_from_the_mcs_index()
    {
        $link = WifiLink::parse("Connected to 00:11:22:33:44:55 (on wlan0)\n\tSSID: office\n\tfreq: 2437\n\tsignal: -80 dBm\n"
            ."\trx bitrate: 144.4 MBit/s MCS 15 short GI\n\ttx bitrate: 65.0 MBit/s MCS 7\n",
            "\tchannel 6 (2437 MHz), width: 20 MHz, center1: 2437 MHz\n");

        $this->assertSame('2.4 GHz', $link['band']);
        $this->assertSame(6, $link['channel']);
        $this->assertSame(20, $link['width']);
        $this->assertSame('4', $link['generation']);
        $this->assertSame(2, $link['streams']);
    }

    public function test_wifi_6e_and_7_on_6_ghz()
    {
        $he = WifiLink::parse("Connected to 00:11:22:33:44:55 (on wlan1)\n\tSSID: six\n\tfreq: 5975\n\tsignal: -50 dBm\n"
            ."\ttx bitrate: 1200.9 MBit/s 80MHz HE-MCS 11 HE-NSS 2 HE-GI 0 HE-DCM 0\n");
        $eht = WifiLink::parse("Connected to 00:11:22:33:44:55 (on wlan1)\n\tSSID: seven\n\tfreq: 6115\n\tsignal: -48 dBm\n"
            ."\ttx bitrate: 2882.3 MBit/s 160MHz EHT-MCS 13 EHT-NSS 2 EHT-GI 0\n");

        $this->assertSame('6 GHz', $he['band']);
        $this->assertSame(5, $he['channel']);
        $this->assertSame('6E', $he['generation']);
        $this->assertSame('7', $eht['generation']);
        $this->assertSame(33, $eht['channel']);
        $this->assertSame(160, $eht['width']);
        $this->assertNull($eht['rx']);
    }

    public function test_not_connected()
    {
        $this->assertNull(WifiLink::parse("Not connected.\n"));
        $this->assertNull(WifiLink::parse(''));
    }

    public function test_signal_words_arcs_and_bars()
    {
        $this->assertSame(['Excellent', 3, 4], [WifiLink::quality(-45), WifiLink::arcs(-45), WifiLink::bars(-45)]);
        $this->assertSame(['Good', 3, 3], [WifiLink::quality(-60), WifiLink::arcs(-60), WifiLink::bars(-60)]);
        $this->assertSame(['Fair', 2, 2], [WifiLink::quality(-72), WifiLink::arcs(-72), WifiLink::bars(-72)]);
        $this->assertSame(['Weak', 1, 1], [WifiLink::quality(-80), WifiLink::arcs(-80), WifiLink::bars(-80)]);
        $this->assertSame(['Weak', 0, 0], [WifiLink::quality(-88), WifiLink::arcs(-88), WifiLink::bars(-88)]);
    }
}
