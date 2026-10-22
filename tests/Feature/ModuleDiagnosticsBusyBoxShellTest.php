<?php

namespace Tests\Feature;

/* The same collector and LED poll as ModuleDiagnosticsShellTest, run by BusyBox ash like on the module: a bash-ism in
   the generated script is caught here, not on the first real module. */
class ModuleDiagnosticsBusyBoxShellTest extends ModuleDiagnosticsShellTest
{
    protected function setUp(): void
    {
        if (!trim((string) shell_exec('command -v busybox 2>/dev/null')))
            $this->markTestSkipped('Needs busybox.');
        parent::setUp();
    }

    protected function shell(): array
    {
        return [trim(shell_exec('command -v busybox')), 'sh'];
    }

    protected function shellHeader(): string
    {
        return "#!/bin/sh\n";   // the module's shell has no pipefail to test
    }
}
