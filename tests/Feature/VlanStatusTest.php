<?php

namespace Tests\Feature;

use App\Http\Livewire\VlanStatus;
use App\Models\User;
use App\Services\VlanInventory;
use App\Services\VlanNetworks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VlanStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_detected_vlans_without_requiring_privileges(): void
    {
        $this->app->instance(VlanInventory::class, new VlanInventory(fn ($cmd) => str_contains($cmd, 'link show')
            ? '[{"ifindex":2,"ifname":"eth0"},{"ifname":"eth0.42","link_index":2,"operstate":"UP","linkinfo":{"info_kind":"vlan","info_data":{"id":42}}}]'
            : '[{"ifname":"eth0.42","addr_info":[{"family":"inet","local":"172.20.0.1","prefixlen":16}]}]'));
        Livewire::test(VlanStatus::class)->assertSee('eth0.42')->assertSee('42')->assertSee('Provisioning');
    }

    public function test_unavailable_inventory_is_not_reported_as_an_empty_list(): void
    {
        $this->app->instance(VlanInventory::class, new VlanInventory(fn () => null));
        Livewire::test(VlanStatus::class)->assertSee('VLAN information is unavailable');
    }

    public function test_adding_a_vlan_requires_account_password_confirmation(): void
    {
        $helper = $this->createMock(VlanNetworks::class);
        $helper->method('available')->willReturn(true);
        $helper->method('list')->willReturn(['ok' => true, 'result' => []]);
        $helper->expects($this->never())->method('add');
        $this->app->instance(VlanNetworks::class, $helper);
        $this->actingAs(User::factory()->create());
        Livewire::test(VlanStatus::class)->set('parent', 'eth0')->set('vlanId', 42)
            ->set('address', '192.168.50.10/24')->call('add')->assertForbidden();
    }

    public function test_confirmed_vlan_goes_to_the_helper(): void
    {
        $helper = $this->createMock(VlanNetworks::class);
        $helper->method('available')->willReturn(true);
        $helper->method('list')->willReturn(['ok' => true, 'result' => []]);
        $helper->expects($this->once())->method('add')->with('eth0', 42, '192.168.50.10/24')
            ->willReturn(['ok' => true, 'result' => []]);
        $this->app->instance(VlanNetworks::class, $helper);
        $this->actingAs(User::factory()->create())->withSession(['auth.password_confirmed_at' => time()]);
        Livewire::test(VlanStatus::class)->set('parent', 'eth0')->set('vlanId', 42)
            ->set('address', '192.168.50.10/24')->call('add')->assertHasNoErrors()->assertSee('VLAN added.');
    }

    public function test_the_page_does_not_offer_removal_of_an_active_vlan(): void
    {
        $record = ['uuid' => 'd6e8fe66-09dc-40a6-9b80-358dff6da460', 'parent' => 'eth0', 'id' => 42,
            'interface' => 'cv42', 'address' => '192.168.50.10/24', 'present' => true, 'active' => true];
        $helper = $this->createMock(VlanNetworks::class);
        $helper->method('available')->willReturn(true);
        $helper->method('list')->willReturn(['ok' => true, 'result' => [$record]]);
        $this->app->instance(VlanNetworks::class, $helper);
        Livewire::test(VlanStatus::class)->assertSee('Disconnect before removal')->assertDontSee('Remove');
    }

    public function test_removal_also_requires_account_password_confirmation(): void
    {
        $helper = $this->createMock(VlanNetworks::class);
        $helper->method('available')->willReturn(true);
        $helper->method('list')->willReturn(['ok' => true, 'result' => []]);
        $helper->expects($this->never())->method('remove');
        $this->app->instance(VlanNetworks::class, $helper);
        $this->actingAs(User::factory()->create());
        Livewire::test(VlanStatus::class)->call('remove', 'd6e8fe66-09dc-40a6-9b80-358dff6da460')->assertForbidden();
    }
}
