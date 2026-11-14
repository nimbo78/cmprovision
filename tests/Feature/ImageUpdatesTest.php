<?php
namespace Tests\Feature;

use App\Http\Livewire\Images;
use App\Models\Image;
use App\Models\Project;
use App\Models\User;
use App\Services\ImageUpdates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ImageUpdatesTest extends TestCase
{
    use RefreshDatabase;
    private const REPO = 'Example/sensors';
    private function image(string $tag='z-release', string $asset='sensor-arm64.img.xz'): Image
    {
        $image=new Image;
        $image->filename=$asset; $image->filename_extension='xz';
        $image->filename_on_server=bin2hex(random_bytes(8)).'.xz'; $image->sha256=str_repeat('a',64);
        $image->source_type='github'; $image->source=json_encode(['repo'=>self::REPO,'tag'=>$tag,'asset'=>$asset]);
        $image->save(); return $image;
    }
    private function release(string $tag, string $date, array $names, bool $pre=false): array
    {
        return ['tag_name'=>$tag,'name'=>$tag,'published_at'=>$date,'prerelease'=>$pre,'draft'=>false,
            'assets'=>array_map(fn ($name)=>['name'=>$name,'size'=>1000,'state'=>'uploaded',
                'browser_download_url'=>'https://github.com/'.self::REPO.'/releases/download/'.rawurlencode($tag).'/'.$name],$names)];
    }
    private function source(array $releases): void { Http::fake(['api.github.com/*'=>Http::response($releases)]); }

    public function test_explicit_check_finds_a_newer_matching_asset_without_changing_any_project(): void
    {
        $image=$this->image();
        $project=Project::create(['name'=>'production','device'=>'cm4','storage'=>'/dev/mmcblk0','image_id'=>$image->id,'label_moment'=>'never']);
        $this->source([$this->release('a-release','2026-11-01T10:00:00Z',['sensor-arm64.img.xz']),
            $this->release('z-release','2026-10-01T10:00:00Z',[$image->filename])]);
        $this->actingAs(User::factory()->create());
        $page=Livewire::test(Images::class); Http::assertNothingSent();
        $page->call('checkUpdate',$image->id);
        $result=app(ImageUpdates::class)->peek($image);
        $this->assertSame('available',$result['state'],json_encode($result));
        $page->assertSee('Newer matching image')->assertSee('a-release');
        $this->assertSame('sensor-arm64.img.xz',$result['candidate']['asset']);
        $this->assertSame($image->id,$project->fresh()->image_id);
        $this->assertSame(1,Image::count());
    }

    public static function incompatible(): array
    {
        return [['sensor-a-release-armhf.img.xz'],['sensor-a-release-arm64-full.img.xz'],
            ['other-a-release-arm64.img.xz']];
    }
    #[DataProvider('incompatible')]
    public function test_changed_architecture_or_variant_is_not_offered(string $asset): void
    {
        $image=$this->image();
        $this->source([$this->release('a-release','2026-11-01',[$asset]),$this->release('z-release','2026-10-01',[$image->filename])]);
        $this->assertNotSame('available',app(ImageUpdates::class)->check($image)['state']);
    }
    public function test_prereleases_and_older_releases_do_not_become_update_hints(): void
    {
        $image=$this->image();
        $this->source([$this->release('preview','2026-12-01',['sensor-preview-arm64.img.xz'],true),
            $this->release('old','2026-09-01',['sensor-old-arm64.img.xz']),$this->release('z-release','2026-10-01',[$image->filename])]);
        $this->assertSame('current',app(ImageUpdates::class)->check($image)['state']);
    }
    public function test_unknown_current_release_is_not_guessed_from_arbitrary_tag_sorting(): void
    {
        $image=$this->image();
        $this->source([$this->release('a-release','2026-11-01',['sensor-a-release-arm64.img.xz'])]);
        $this->assertSame('unknown',app(ImageUpdates::class)->check($image)['state']);
    }
    public function test_source_errors_are_visible_and_uploaded_images_make_no_http_request(): void
    {
        $image=$this->image();
        Http::fake(['api.github.com/*'=>Http::response('',503)]);
        $this->assertSame('error',app(ImageUpdates::class)->check($image)['state']);
        $image->source_type=null; $image->source=null; $image->save();
        $count=Http::recorded()->count();
        $this->assertSame('unsupported',app(ImageUpdates::class)->check($image)['state']);
        $this->assertCount($count,Http::recorded());
    }
    public function test_date_stamped_assets_match_but_architecture_inside_a_tag_is_preserved(): void
    {
        $image=$this->image('v1-arm64','2026-10-01-sensor-v1-arm64-lite.img.xz');
        $this->source([$this->release('v2-armhf','2026-11-01',['2026-11-01-sensor-v2-armhf-lite.img.xz']),
            $this->release('v1-arm64','2026-10-01',[$image->filename])]);
        $this->assertNotSame('available',app(ImageUpdates::class)->check($image)['state']);
    }

    public function test_numeric_release_prefix_matches_assets_that_omit_the_codename(): void
    {
        $image=$this->image('26.10-DeadEye','2026-10-01-wlanpi-26.10-arm64-lite.img.xz');
        $this->source([$this->release('26.11-Robin','2026-11-01',['2026-11-01-wlanpi-26.11-arm64-lite.img.xz']),
            $this->release('26.10-DeadEye','2026-10-01',[$image->filename])]);
        $this->assertSame('available',app(ImageUpdates::class)->check($image)['state']);
    }

    public function test_ambiguous_matching_assets_require_manual_review(): void
    {
        $image=$this->image();
        $this->source([$this->release('a-release','2026-11-01',['2026-11-01-sensor-arm64.img.xz','2026-11-02-sensor-arm64.img.xz']),
            $this->release('z-release','2026-10-01',[$image->filename])]);
        $this->assertSame('unknown',app(ImageUpdates::class)->check($image)['state']);
    }

    public function test_changing_source_invalidates_the_old_hint(): void
    {
        $image=$this->image();
        $this->source([$this->release('z-release','2026-10-01',[$image->filename])]);
        app(ImageUpdates::class)->check($image);
        $image->source=json_encode(['repo'=>self::REPO,'tag'=>'other','asset'=>$image->filename]); $image->save();
        $this->assertNull(app(ImageUpdates::class)->peek($image));
    }

    public static function unknownVariants(): array
    {
        return [['pi4','pi5'],['debian','ubuntu'],['vendorA','vendorB']];
    }
    #[DataProvider('unknownVariants')]
    public function test_variant_suffix_inside_the_tag_is_not_erased(string $old, string $new): void
    {
        $image=$this->image('v1.0-'.$old,'sensor-v1.0-'.$old.'-arm64.img.xz');
        $this->source([$this->release('v1.1-'.$new,'2026-11-01',['sensor-v1.1-'.$new.'-arm64.img.xz']),
            $this->release('v1.0-'.$old,'2026-10-01',[$image->filename])]);
        $this->assertNotSame('available',app(ImageUpdates::class)->check($image)['state']);
    }
}
