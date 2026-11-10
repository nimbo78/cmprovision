<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StationUpdatesControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StationUpdatesDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private function control(): StationUpdatesControl
    {
        $control = new class extends StationUpdatesControl {
            public array $calls = [];
            public bool $fail = false, $oversized = false;
            public function request(array $request): array
            {
                $this->calls[] = $request;
                if ($this->fail) throw new \RuntimeException('No readable diagnostic report is retained for this operation.');
                return ['schema' => 1, 'id' => $request['id'], 'failures' => $this->oversized ? [str_repeat('x', 65536)] : []];
            }
        };
        $this->app->instance(StationUpdatesControl::class, $control);
        return $control;
    }

    public function test_guests_and_unconfirmed_users_cannot_export_reports(): void
    {
        $control = $this->control();
        $url = '/settings/updates/'.str_repeat('a', 32).'/diagnostics';
        $this->get($url)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get($url)->assertRedirect('/user/confirm-password');
        $this->assertSame([], $control->calls);
    }

    public function test_confirmed_report_is_a_bounded_no_store_json_attachment(): void
    {
        $control = $this->control();
        $id = str_repeat('a', 32);
        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()])
            ->get('/settings/updates/'.$id.'/diagnostics')->assertOk()->assertJsonPath('id', $id)
            ->assertHeader('content-disposition', 'attachment; filename="cmprovision-update-'.$id.'-diagnostics.json"')
            ->assertHeader('cache-control', 'no-store, private');
        $this->assertSame([['action' => 'diagnostics', 'id' => $id]], $control->calls);
    }

    public function test_unsafe_and_unknown_or_oversized_reports_are_controlled_rejections(): void
    {
        $control = $this->control();
        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);
        $this->get('/settings/updates/not-an-id/diagnostics')->assertNotFound();
        $this->assertSame([], $control->calls);
        $control->fail = true;
        $this->getJson('/settings/updates/'.str_repeat('a', 32).'/diagnostics')->assertStatus(422);
        $control->fail = false;
        $control->oversized = true;
        $this->getJson('/settings/updates/'.str_repeat('a', 32).'/diagnostics')->assertStatus(422);
    }
}
