<?php

namespace Tests\Feature;

use App\Models\Cm;
use App\Services\NetworkChangeGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetworkChangeGateTest extends TestCase
{
    use RefreshDatabase;

    private $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/cmp-network-gate-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        touch($this->directory.'/provisioning.lock');
    }

    protected function tearDown(): void
    {
        foreach (['pending', 'provisioning.lock'] as $file) {
            if (file_exists($this->directory.'/'.$file) || is_link($this->directory.'/'.$file)) {
                unlink($this->directory.'/'.$file);
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_start_waits_while_network_change_is_pending_without_creating_a_run(): void
    {
        touch($this->directory.'/pending');
        $this->app->instance(NetworkChangeGate::class, new NetworkChangeGate(true, $this->directory, false));
        $this->get('/scriptexecute?serial=10000000e611110c&model=CM4&mac=e4:5f:01:62:9f:d3')
            ->assertStatus(503)->assertHeader('Retry-After', '10');
        $this->assertSame(0, Cm::count());
    }

    public function test_lock_excludes_a_simultaneous_network_change_and_then_allows_start(): void
    {
        $gate = new NetworkChangeGate(true, $this->directory, false);
        $lock = fopen($this->directory.'/provisioning.lock', 'r');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        $this->assertSame(503, $gate->start(fn () => response('started'))->getStatusCode());
        flock($lock, LOCK_UN);
        fclose($lock);
        $this->assertSame('started', $gate->start(fn () => response('started'))->getContent());
    }

    public function test_existing_run_reports_are_not_blocked_by_a_pending_change(): void
    {
        $cm = Cm::create(['serial' => '10000000e611110c', 'mac' => 'e4:5f:01:62:9f:d3',
            'phase' => 'write', 'provisioning_started_at' => now()]);
        touch($this->directory.'/pending');
        $this->app->instance(NetworkChangeGate::class, new NetworkChangeGate(true, $this->directory, false));
        $this->get('/scriptexecute?serial='.$cm->serial.'&progress=write&sectors=2048')->assertOk();
        $this->assertSame(1048576, $cm->fresh()->progress_bytes);
    }

    public function test_missing_or_symlinked_lock_fails_closed_when_enabled(): void
    {
        $gate = new NetworkChangeGate(true, $this->directory, false);
        unlink($this->directory.'/provisioning.lock');
        $this->assertSame(503, $gate->start(fn () => response('started'))->getStatusCode());
        symlink('/dev/null', $this->directory.'/provisioning.lock');
        $this->assertSame(503, $gate->start(fn () => response('started'))->getStatusCode());
    }

    public function test_uninstalled_network_controller_preserves_existing_start_behaviour(): void
    {
        $gate = new NetworkChangeGate(false, $this->directory, false);
        touch($this->directory.'/pending');
        $this->assertSame('started', $gate->start(fn () => response('started'))->getContent());
    }
}
