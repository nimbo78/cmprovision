<?php

namespace Tests\Unit;

use App\Http\Controllers\ScriptExecuteController;
use App\Support\FailureAdvice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The server reads what the tools of the new utility OS print (2.7.0): tests/fixtures/utility-os/messages.txt holds their
    messages as tools/utility-os/test.sh recorded them under QEMU */
class UtilityOsMessagesTest extends TestCase
{
    /** section => its text */
    protected static function sections(): array
    {
        $sections = [];
        $name = null;
        foreach (file(dirname(__DIR__).'/fixtures/utility-os/messages.txt', FILE_IGNORE_NEW_LINES) as $line)
        {
            if (preg_match('/^\[([a-z0-9-]+)\]$/', $line, $m))
                $sections[$name = $m[1]] = '';
            elseif ($name !== null)
                $sections[$name] .= $line."\n";
        }
        return $sections;
    }

    public function test_every_message_was_recorded()
    {
        foreach (['gz-cut', 'gz-garbage', 'xz-cut', 'xz-garbage', 'bz2-cut', 'bz2-garbage', 'dd-full', 'dd-records',
                  'sha256-mismatch', 'flashrom-written', 'flashrom-identical', 'flashrom-missing'] as $section)
            $this->assertNotSame('', trim(self::sections()[$section] ?? ''), $section);
    }

    public static function failedWrites(): array
    {
        return [
            'gzip on garbage' => ['gz-garbage', 'not a valid compressed image'],
            'xz on garbage' => ['xz-garbage', 'not a valid compressed image'],
            'bunzip2 on garbage' => ['bz2-garbage', 'not a valid compressed image'],
            'gzip cut short' => ['gz-cut', 'truncated or corrupted'],
            'xz cut short' => ['xz-cut', 'truncated or corrupted'],
            'bunzip2 cut short' => ['bz2-cut', 'truncated or corrupted'],
            'dd on a full storage' => ['dd-full', 'could not be written'],
        ];
    }

    #[DataProvider('failedWrites')]
    public function test_the_server_explains_a_failed_write_of_the_new_os(string $section, string $hint)
    {
        $this->assertStringContainsString($hint, (string) ScriptExecuteController::diagnoseWriteFailure(self::sections()[$section]),
            self::sections()[$section]);
    }

    public function test_the_write_counts_the_records_of_dd()
    {
        // write.blade.php: sed -n 's/^\([0-9]*\)+\([0-9]*\) records in$/\1 \2/p'
        $this->assertMatchesRegularExpression('/^[0-9]*\+[0-9]* records in$/m', self::sections()['dd-records']);
    }

    public function test_the_eeprom_flash_and_its_advice_read_the_new_os()
    {
        // the flash script greps flashrom's log for "identical" (ScriptExecuteController::startProvisoning); FailureAdvice
        // knows a missing chip and an image that differs from the project
        $this->assertStringContainsString('identical', self::sections()['flashrom-identical']);
        $this->assertStringNotContainsString('identical', self::sections()['flashrom-written']);
        $this->assertStringContainsString('VERIFIED.', self::sections()['flashrom-written']);
        $advice = new \ReflectionMethod(FailureAdvice::class, 'eepromAdvice');
        $this->assertStringContainsString('flashrom found no SPI flash chip', $advice->invoke(null, self::sections()['flashrom-missing']));
        $this->assertStringContainsString('does not match the active project', $advice->invoke(null, self::sections()['sha256-mismatch']));
    }
}
