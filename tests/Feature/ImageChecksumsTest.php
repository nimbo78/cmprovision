<?php

namespace Tests\Feature;

use App\Services\ImageChecksums;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The checksum files sources publish: "<hex>  <name>" lines, a bare hex, BSD style */
class ImageChecksumsTest extends TestCase
{
    const A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    const B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public static function files(): array
    {
        return [
            'one line' => [self::A.'  wlanpi-os-26.10-DeadEye-full.img.gz'."\n", null, self::A],
            'binary marker' => [self::A.' *os.img.xz'."\n", 'os.img.xz', self::A],
            'bare hex' => [self::A."\n", null, self::A],
            'bare hex for a name' => [self::A, 'anything.img.gz', self::A],
            'upper case' => [strtoupper(self::A).'  os.img.gz', 'os.img.gz', self::A],
            'bsd style' => ['SHA256 (os.img.gz) = '.self::B."\n", 'os.img.gz', self::B],
            'windows line ends' => [self::A."  os.img.gz\r\n".self::B."  other.img.gz\r\n", 'other.img.gz', self::B],
            'a path in front of the name' => [self::A.'  ./out/os.img.gz', 'os.img.gz', self::A],
            'the sums of a release' => [self::A."  wlanpi-os-full.img.gz\n".self::B."  wlanpi-os-full.img.gz.sha256\n", 'wlanpi-os-full.img.gz', self::A],
            'the other file of the release' => [self::A."  wlanpi-os-full.img.gz\n".self::B."  wlanpi-os-full.img.gz.sha256\n", 'wlanpi-os-full.img.gz.sha256', self::B],
        ];
    }

    #[DataProvider('files')]
    public function test_the_sum_of_a_file_is_found_in_what_was_published(string $text, ?string $name, string $sum)
    {
        $this->assertSame($sum, ImageChecksums::parse($text, $name));
    }

    public function test_a_name_that_is_not_in_the_list_has_no_sum_and_neither_has_nonsense()
    {
        $this->assertNull(ImageChecksums::parse(self::A.'  os.img.gz', 'other.img.gz'));
        $this->assertNull(ImageChecksums::parse('<html>Not found</html>'));
        $this->assertNull(ImageChecksums::parse(''));
        $this->assertNull(ImageChecksums::parse(substr(self::A, 0, 63).'  os.img.gz'), 'a sha256 has 64 digits');
        $this->assertNull(ImageChecksums::parse(self::A.'0  os.img.gz'));
        $this->assertNull(ImageChecksums::parse('gggggggggggggggggggggggggggggggggggggggggggggggggggggggggggggggg  os.img.gz'));
    }

    public function test_a_line_that_only_ends_in_the_name_is_not_the_name()
    {
        $this->assertNull(ImageChecksums::parse(self::A.'  old-os.img.gz', 'os.img.gz'));
    }

    public function test_a_published_sum_is_told_from_a_typed_one()
    {
        $this->assertTrue(ImageChecksums::isSum(self::A));
        $this->assertTrue(ImageChecksums::isSum('  '.strtoupper(self::B)."\n"));
        $this->assertFalse(ImageChecksums::isSum('https://example.org/os.img.gz.sha256'));
        $this->assertFalse(ImageChecksums::isSum(substr(self::A, 1)));
        $this->assertSame(self::B, ImageChecksums::normalize('  '.strtoupper(self::B)."\n"));
    }
}
