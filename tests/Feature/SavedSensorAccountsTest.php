<?php

namespace Tests\Feature;

use App\Http\Livewire\ReprovisionAccounts;
use App\Models\{Setting,User};
use App\Services\Sensor\SavedAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;
use Tests\TestCase;

class SavedSensorAccountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); $this->actingAs(User::factory()->create());
    }

    public function test_defaults_enable_only_wlanpi_and_metadata_never_contains_passwords(): void
    {
        $a=app(SavedAccounts::class);
        $this->assertSame(['wlanpi'],array_column($a->enabled(),'user'));
        $this->assertSame(['wlanpi','pi'],array_column($a->listing(),'user'));
        foreach ($a->listing() as $row) $this->assertArrayNotHasKey('password',$row);
    }

    public function test_settings_encrypt_passwords_and_blank_edit_keeps_the_saved_secret(): void
    {
        $a=app(SavedAccounts::class); $a->save(null,'Bench','bench','private-sensor-secret',true);
        $stored=Setting::findOrFail(SavedAccounts::SETTING)->value;
        $this->assertStringNotContainsString('private-sensor-secret',$stored);
        $this->assertStringContainsString('private-sensor-secret',Crypt::decryptString($stored));
        $id=$a->listing()[2]['id']; $a->save($id,'Renamed','other','',true);
        $this->assertSame('private-sensor-secret',$a->enabled()[1]['password']);
        $a->move($id,-1); $this->assertSame($id,$a->listing()[1]['id']);
        $a->toggle($id); $this->assertSame(['wlanpi'],array_column($a->enabled(),'user'));
        $a->remove($id); $this->assertCount(2,$a->listing());
    }

    public function test_deleting_all_profiles_does_not_restore_factory_defaults(): void
    {
        $a=app(SavedAccounts::class); $a->remove('wlanpi'); $a->remove('raspberry-legacy');
        $this->assertSame([],$a->listing()); $this->assertSame([],$a->enabled());
    }

    public function test_corrupt_settings_fail_closed_instead_of_silently_restoring_defaults(): void
    {
        Setting::create(['key'=>SavedAccounts::SETTING,'value'=>'corrupt']);
        $this->expectExceptionMessage('cannot be read'); app(SavedAccounts::class)->enabled();
    }

    public function test_at_most_three_profiles_are_enabled_without_changing_existing_settings_on_failure(): void
    {
        $a=app(SavedAccounts::class); $a->toggle('raspberry-legacy'); $a->save(null,'Third','third','third-secret',true);
        try { $a->save(null,'Fourth','fourth','fourth-secret',true); $this->fail('Fourth enabled account accepted'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('at most 3',$e->getMessage()); }
        $this->assertCount(3,$a->enabled()); $this->assertCount(3,$a->listing());
    }

    public function test_duplicate_credentials_are_not_tried_twice(): void
    {
        $a=app(SavedAccounts::class); $a->save(null,'Duplicate','wlanpi','wlanpi',true);
        $this->assertCount(1,$a->enabled());
    }

    public function test_all_changes_require_the_web_account_password_confirmation(): void
    {
        foreach ([['save',['secret']],['toggle',['wlanpi']],['remove',['wlanpi']],['moveUp',['wlanpi']],['moveDown',['wlanpi']]] as [$method,$args]) {
            Livewire::test(ReprovisionAccounts::class)->call($method,...$args)->assertForbidden();
        }
        $this->assertNull(Setting::find(SavedAccounts::SETTING));
    }

    public function test_livewire_never_returns_a_new_or_stored_password(): void
    {
        $this->withSession(['auth.password_confirmed_at'=>time()]);
        $c=Livewire::test(ReprovisionAccounts::class)->call('add')->set('name','Custom')->set('user','custom')
            ->call('save','unique-profile-secret-123!')->assertHasNoErrors()->assertSet('form',false);
        $id=app(SavedAccounts::class)->listing()[2]['id'];
        $c->call('edit',$id)->assertSet('name','Custom');
        $this->assertStringNotContainsString('unique-profile-secret-123!',json_encode($c->snapshot));
        $this->assertStringNotContainsString('unique-profile-secret-123!',$c->html());
        $c->set('name','Changed')->call('save','')->assertHasNoErrors();
        $this->assertSame('unique-profile-secret-123!',app(SavedAccounts::class)->enabled()[1]['password']);
    }

    public function test_invalid_login_and_line_break_password_are_rejected(): void
    {
        $this->withSession(['auth.password_confirmed_at'=>time()]);
        Livewire::test(ReprovisionAccounts::class)->call('add')->set('name','Bad')->set('user','root@host')
            ->call('save',"bad\nsecret")->assertHasErrors(['user','password']);
        $this->assertNull(Setting::find(SavedAccounts::SETTING));
    }
}
