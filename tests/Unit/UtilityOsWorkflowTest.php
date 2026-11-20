<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_the_monthly_run_builds_only_when_something_moved_and_proposes_it_to_main()
    {
        $w = $this->workflow();
        $this->assertStringContainsString('sh tools/utility-os/update.sh --check', $w);
        $this->assertStringContainsString("ref: \${{ github.event_name == 'schedule' && 'main' || github.ref }}", $w);
        foreach (['sh tools/utility-os/update.sh |', 'sh tools/utility-os/build.sh', 'sh tools/utility-os/test.sh'] as $step)
            $this->assertStringContainsString($step, $w);
        $this->assertStringContainsString('git add utility-os/versions.env scriptexecute64 scriptexecute/usb scriptexecute/MANIFEST tests/fixtures/utility-os/messages.txt', $w);
        $this->assertStringContainsString('gh pr create --base main', $w);
        $this->assertStringContainsString('GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}', $w);
    }

    #[DataProvider('changedFiles')]
    public function test_changed_files_select_the_required_workflows(array $files, array $expected): void
    {
        $selected = [];
        foreach (['utility-os', 'fpms'] as $workflow) {
            $yaml = file_get_contents(dirname(__DIR__, 2).'/.github/workflows/'.$workflow.'.yml');
            $this->assertMatchesRegularExpression('/^  pull_request:\s*\n(.*?)(?=^  \w|^[^\s])/ms', $yaml);
            preg_match('/^  pull_request:\s*\n(.*?)(?=^  \w|^[^\s])/ms', $yaml, $block);
            preg_match_all("/'([^']+)'/", $block[1], $patterns);
            $this->assertNotEmpty($patterns[1], 'Read the real pull-request filters, not a test copy.');
            foreach ($files as $file) {
                $matched = false;
                foreach ($patterns[1] as $pattern) {
                    $exclude = str_starts_with($pattern, '!');
                    if (fnmatch($exclude ? substr($pattern, 1) : $pattern, $file)) $matched = !$exclude;
                }
                if ($matched) { $selected[] = $workflow; break; }
            }
        }
        $this->assertSame($expected, $selected, implode(', ', $files));
    }

    public static function changedFiles(): array
    {
        return [
            'HDMI test' => [['tests/tools/test_hdmi_wallboard.py'], ['fpms']],
            'HDMI graphics test' => [['tests/tools/test_hdmi_graphics.py'], ['fpms']],
            'FPMS test' => [['tests/tools/test_fpms_control.py'], ['fpms']],
            'future FPMS test' => [['tests/tools/test_fpms_touch.py'], ['fpms']],
            'display units' => [['tests/tools/check_display_systemd.py'], ['fpms']],
            'display source' => [['tools/hdmi-wallboard.py'], ['fpms']],
            'HDMI output module' => [['tools/hdmi_output.py'], ['fpms']],
            'HDMI brand image' => [['public/apple-touch-icon.png'], ['fpms']],
            'HDMI font' => [['public/fonts/nunito-v16-latin-700.woff'], ['fpms']],
            'display packaging rules' => [['debian/rules'], ['fpms']],
            'image access owns its checks' => [['tests/tools/test_image_access.py'], []],
            'wizard PHP test' => [['tests/Feature/StationSetupWizardTest.php'], []],
            'docs' => [['docs/rescue-wifi.md'], []],
            'OS configuration' => [['utility-os/board/cmprovision/linux.config'], ['utility-os']],
            'collector' => [['resources/shell/module-diagnostics.sh'], ['utility-os']],
            'USB protocol test' => [['tests/tools/test_usb_diagnostics.py'], ['utility-os']],
            'future USB protocol test' => [['tests/tools/test_usb_diagnostics_v2.py'], ['utility-os']],
            'USB receiver' => [['tools/usb-diagnostics.py'], ['utility-os']],
            'USB boot builder' => [['tools/utility-os/usb_image.py'], ['utility-os']],
            'network boot asset' => [['scriptexecute64/config.txt'], ['utility-os']],
            'legacy USB boot asset' => [['scriptexecute/usb/config.txt'], ['utility-os']],
            'diagnostic fixture' => [['tests/fixtures/utility-os/messages.txt'], ['utility-os']],
            'kernel workflow' => [['.github/workflows/utility-os.yml'], ['utility-os']],
            'display workflow' => [['.github/workflows/fpms.yml'], ['fpms']],
            'mixed' => [['tests/tools/test_hdmi_wallboard.py', 'utility-os/versions.env'], ['utility-os', 'fpms']],
        ];
    }
}
