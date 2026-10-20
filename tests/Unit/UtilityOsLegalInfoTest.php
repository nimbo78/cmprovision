<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class UtilityOsLegalInfoTest extends TestCase
{
    public function test_source_supplement_checks_pins_and_vendor_metadata()
    {
        if (!is_executable('/usr/bin/python3') || !is_executable('/usr/bin/git'))
            $this->markTestSkipped('needs python3 and git');
        exec('python3 -I '.escapeshellarg(dirname(__DIR__).'/utility-os/test_legal_info.py').' 2>&1', $output, $code);
        $this->assertSame(0, $code, implode("\n", $output));
    }
}
