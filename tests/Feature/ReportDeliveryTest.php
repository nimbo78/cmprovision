<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReportDeliveryTest extends TestCase
{
    public static function responses(): array
    {
        $cases = [];
        foreach (['scriptexecute.blade.php', 'scriptexecute/usbprep.blade.php'] as $template)
        {
            $cases[$template.' recovers'] = [$template, '503,503,503,200', true, 4];
            $cases[$template.' fails unavailable'] = [$template, '503', false, 9];
            $cases[$template.' stops stale'] = [$template, '409', false, 1];
            $cases[$template.' success'] = [$template, '200', true, 1];
        }
        return $cases;
    }

    #[DataProvider('responses')]
    public function test_real_curl_delivery_under_busybox_sh(string $template, string $statuses, bool $success, int $count): void
    {
        foreach (['curl', 'python3', 'busybox'] as $command)
            if ((new Process(['sh', '-c', 'command -v "$1"', 'test', $command]))->run() !== 0)
                $this->markTestSkipped($command.' is required');
        $dir = sys_get_temp_dir().'/cmprovision-report-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        // Bound only the outer loop and delay. The actual curl and product function are unchanged.
        file_put_contents($dir.'/seq', "#!/bin/sh\nprintf '1\\n2\\n3\\n'\n");
        file_put_contents($dir.'/sleep', "#!/bin/sh\nexit 0\n");
        chmod($dir.'/seq', 0700);
        chmod($dir.'/sleep', 0700);
        $server = new Process(['python3', '-u', base_path('tests/fixtures/report-server.py'), $statuses, $dir.'/requests']);
        try
        {
            $server->start();
            for ($i = 0; $i < 300 && trim($server->getOutput()) === ''; $i++) usleep(10000);
            $port = trim($server->getOutput());
            $this->assertMatchesRegularExpression('/^[0-9]+$/', $port, $server->getErrorOutput());
            $source = file_get_contents(resource_path('views/'.$template));
            $this->assertSame(1, preg_match('/^report\(\) \{\n.*?^\}/ms', $source, $match));
            $run = new Process(['busybox', 'sh', '-c', $match[0]."\n".'report "$1"', 'test', 'http://127.0.0.1:'.$port.'/report'],
                null, ['PATH' => $dir.':'.getenv('PATH'), 'REPORT_TOKEN' => str_repeat('b', 40)]);
            $run->setTimeout(20)->run();
            $this->assertSame($success, $run->isSuccessful(), $run->getOutput().$run->getErrorOutput());
            $received = json_decode(file_get_contents($dir.'/requests'), true);
            $this->assertSame($count, $received['count']);
            $this->assertSame(str_repeat('b', 40), $received['run']);
        }
        finally
        {
            $server->stop(0);
            foreach (glob($dir.'/*') as $file) unlink($file);
            rmdir($dir);
        }
    }
}
