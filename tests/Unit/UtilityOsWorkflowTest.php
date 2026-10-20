<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** .github/workflows/utility-os.yml (2.7.0): the triggers, the permissions, and what the monthly pull request carries */
class UtilityOsWorkflowTest extends TestCase
{
    protected function workflow(): string
    {
        return file_get_contents(dirname(__DIR__, 2).'/.github/workflows/utility-os.yml');
    }

    public function test_it_runs_on_demand_monthly_and_on_pull_requests_that_change_the_os()
    {
        $w = $this->workflow();
        foreach (["  workflow_dispatch:\n", "    - cron: '17 3 1 * *'\n", "      - 'utility-os/**'\n", "      - 'tools/utility-os/**'\n"] as $line)
            $this->assertStringContainsString($line, $w);
        $this->assertStringContainsString("permissions:\n  contents: write\n  pull-requests: write\n", $w, 'nothing else: listing any permission sets the rest to none');
        $this->assertStringContainsString('runs-on: ubuntu-24.04', $w, 'x86_64: the Bootlin toolchain is built for it');
        $this->assertStringContainsString('timeout-minutes: 300', $w);
    }

    public function test_the_monthly_run_builds_only_when_something_moved_and_proposes_it_to_stage2()
    {
        $w = $this->workflow();
        $this->assertStringContainsString('sh tools/utility-os/update.sh --check', $w);
        $this->assertStringContainsString("ref: \${{ github.event_name == 'schedule' && 'stage2' || github.ref }}", $w);
        foreach (['sh tools/utility-os/update.sh |', 'sh tools/utility-os/build.sh', 'sh tools/utility-os/test.sh'] as $step)
            $this->assertStringContainsString($step, $w);
        $this->assertStringContainsString('git add utility-os/versions.env scriptexecute64 scriptexecute/usb scriptexecute/MANIFEST tests/fixtures/utility-os/messages.txt', $w);
        $this->assertStringContainsString('gh pr create --base stage2', $w);
        $this->assertStringContainsString('GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}', $w);
    }
}
