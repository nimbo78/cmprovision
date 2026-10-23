<?php

namespace Tests\Unit;

use App\Services\OuiVendors;
use Tests\TestCase;

/**
 * The maker of a device from its MAC address, out of the registries of the ieee-data package. tests/fixtures/ieee holds
 * the first entries of the real files (oui.txt, mam.txt, oui36.txt, iab.txt of ieee-data 20240722) in their real layout:
 * in the files of the longer blocks the OUI is on the "(hex)" line and the "(base 16)" line gives a range.
 */
class OuiVendorsTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/cmprov-oui-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        foreach (glob(dirname(__DIR__).'/fixtures/ieee/*.txt') as $file)
            copy($file, $this->dir.'/'.basename($file));
        config(['cmprovision.ieee_data_dir' => $this->dir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file)
            unlink($file);
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_a_24_bit_block_is_named_by_oui_txt_in_any_notation()
    {
        $names = OuiVendors::names(['10:e9:92:12:34:56', '10-E9-92-00-11-22', '10e9.9200.0001', '78:f2:76:00:00:01']);
        $this->assertSame('INGRAM MICRO SERVICES', $names['10:e9:92:12:34:56']);
        $this->assertSame('INGRAM MICRO SERVICES', $names['10-E9-92-00-11-22']);
        $this->assertSame('INGRAM MICRO SERVICES', $names['10e9.9200.0001']);
        $this->assertSame('Cyklop Fastjet Technologies (Shanghai) Inc.', $names['78:f2:76:00:00:01']);
    }

    public function test_a_28_bit_block_is_the_oui_and_the_first_digit_of_the_range()
    {
        $names = OuiVendors::names(['c8:5c:e2:7a:bc:de', 'c8:5c:e2:a1:00:01', 'b8:4c:87:41:11:11', 'b8:4c:87:91:11:11', 'c8:5c:e2:11:00:00']);
        $this->assertSame('SYNERGY SYSTEMS AND SOLUTIONS', $names['c8:5c:e2:7a:bc:de'], '700000-7FFFFF');
        $this->assertSame('San Telequip (P) Ltd.,', $names['c8:5c:e2:a1:00:01'], 'the same OUI, another range');
        $this->assertSame('Blum Novotest GmbH', $names['b8:4c:87:41:11:11']);
        $this->assertSame('Airgain Inc.', $names['b8:4c:87:91:11:11']);
        $this->assertNull($names['c8:5c:e2:11:00:00'], 'a range nobody holds in this excerpt');
    }

    public function test_a_36_bit_block_is_the_oui_and_the_first_three_digits_of_the_range()
    {
        $names = OuiVendors::names(['8c:1f:64:c1:6a:bc', '8c:1f:64:af:a1:23', '40:d8:55:0d:71:23']);
        $this->assertSame('ALISONIC SRL', $names['8c:1f:64:c1:6a:bc'], 'C16000-C16FFF');
        $this->assertSame('DATA ELECTRONIC DEVICES, INC', $names['8c:1f:64:af:a1:23'], 'the same OUI, another range');
        $this->assertSame('Avant Technologies', $names['40:d8:55:0d:71:23'], 'iab.txt, the older 36 bit registry');
    }

    public function test_the_longest_block_wins_over_the_registration_authority_that_holds_the_oui()
    {
        file_put_contents($this->dir.'/oui.txt', "8C-1F-64   (hex)\t\tIEEE Registration Authority\n8C1F64     (base 16)\t\tIEEE Registration Authority\n", FILE_APPEND);
        $names = OuiVendors::names(['8c:1f:64:c1:6a:bc', '8c:1f:64:11:22:33']);
        $this->assertSame('ALISONIC SRL', $names['8c:1f:64:c1:6a:bc']);
        $this->assertSame('IEEE Registration Authority', $names['8c:1f:64:11:22:33'], 'no longer block holds it');
    }

    public function test_raspberry_pis_own_36_bit_block_is_not_lost_to_the_registration_authority()
    {
        // the real oui.txt gives 8C1F64 to the authority; the Raspberry Pi blocks inside it are built in
        file_put_contents($this->dir.'/oui.txt', "8C-1F-64   (hex)\t\tIEEE Registration Authority\n8C1F64     (base 16)\t\tIEEE Registration Authority\n", FILE_APPEND);
        $this->assertSame('Raspberry Pi Trading Ltd', OuiVendors::name('8c:1f:64:34:a1:23'));
    }

    public function test_an_unknown_block_and_a_malformed_address_have_no_name()
    {
        $names = OuiVendors::names(['00:11:22:33:44:55', 'not a mac', '']);
        $this->assertSame([null, null, null], array_values($names));
    }

    public function test_a_locally_administered_address_has_no_maker()
    {
        $this->assertSame(OuiVendors::LOCAL, OuiVendors::name('02:00:00:00:00:01'));
        $this->assertSame(OuiVendors::LOCAL, OuiVendors::name('da:a1:19:00:00:01'));
    }

    public function test_without_the_package_the_raspberry_pi_blocks_are_still_known()
    {
        config(['cmprovision.ieee_data_dir' => $this->dir.'/missing']);
        $this->assertSame('Raspberry Pi Trading Ltd', OuiVendors::name('e4:5f:01:62:9f:d3'));
        $this->assertSame('Raspberry Pi Trading Ltd', OuiVendors::name('2c:cf:67:30:7d:a2'));
        $this->assertNull(OuiVendors::name('10:e9:92:12:34:56'));
        $this->assertNull(OuiVendors::name(null));
    }

    public function test_a_name_is_cleaned_like_any_text_from_outside()
    {
        file_put_contents($this->dir.'/oui.txt', "00-0A-95   (hex)\t\tEvil\x1b[31m Co\u{202E}\n000A95     (base 16)\t\tEvil\x1b[31m Co\u{202E}\n");
        $name = OuiVendors::name('00:0a:95:00:00:01');
        $this->assertDoesNotMatchRegularExpression('/[\p{Cc}\p{Cf}]/u', $name);
        $this->assertStringContainsString('Evil', $name);
    }

    public function test_the_answers_are_kept_so_the_long_files_are_not_read_at_every_refresh_of_the_page()
    {
        $this->assertSame('INGRAM MICRO SERVICES', OuiVendors::name('10:e9:92:12:34:56'));
        $this->assertNull(OuiVendors::name('00:11:22:33:44:55'));
        $mtime = filemtime($this->dir.'/oui.txt');
        $other = "10-E9-92   (hex)\t\tSomebody Else\n10E992     (base 16)\t\tSomebody Else\n";
        file_put_contents($this->dir.'/oui.txt', $other);
        touch($this->dir.'/oui.txt', $mtime);
        clearstatcache();
        $this->assertSame('INGRAM MICRO SERVICES', OuiVendors::name('10:e9:92:12:34:99'), 'the same block, from what was learnt');
        $this->assertNull(OuiVendors::name('00:11:22:33:44:56'), 'an unknown block is remembered too');

        touch($this->dir.'/oui.txt', $mtime + 10);   // a new version of the package is a new file
        clearstatcache();
        $this->assertSame('Somebody Else', OuiVendors::name('10:e9:92:12:34:99'));
    }
}
