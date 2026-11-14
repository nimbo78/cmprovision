<?php
namespace App\Http\Livewire;

use App\Models\Image;
use App\Services\ImageRemoval;
use App\Services\ImageSpace;
use App\Services\StationStorage as Storage;
use Illuminate\Support\Facades\Cache;
use Laravel\Jetstream\ConfirmsPasswords;
use Livewire\Attributes\Locked;
use Livewire\Component;

class StationStorage extends Component
{
    use ConfirmsPasswords;
    #[Locked] public ?array $status = null;
    #[Locked] public array $preview = [];
    public bool $previewOpen = false;
    public ?string $notice = null, $error = null;

    public function mount(): void { $this->status = Cache::get(Storage::CACHE_KEY); }

    public function refreshStatus(bool $force = false): void
    {
        abort_unless(auth()->check(), 403);
        $this->error = null;
        try { $this->status = app(Storage::class)->status($force); }
        catch (\RuntimeException $error) { $this->error = $error->getMessage(); }
    }

    public function previewCleanup(): void
    {
        abort_unless(auth()->check(), 403);
        $space = app(ImageSpace::class);
        $this->preview = $space->unused()->take(250)->map(fn ($image) => [
            'id'=>$image->id, 'name'=>$image->filename, 'bytes'=>$space->bytes([$image]),
        ])->all();
        $this->previewOpen = true;
        $this->notice = null;
    }

    public function clean(): void
    {
        abort_unless(auth()->check() && $this->previewOpen && $this->preview !== [], 403);
        $this->ensurePasswordIsConfirmed();
        $removed = $protected = $bytes = 0;
        foreach ($this->preview as $item) {
            try {
                $image = Image::find($item['id']);
                $size = $image ? app(ImageSpace::class)->bytes([$image]) : 0;
                if (app(ImageRemoval::class)->remove($item['id'])) { $removed++; $bytes += $size; }
            } catch (\RuntimeException $claimed) { $protected++; }
        }
        $this->preview = []; $this->previewOpen = false;
        Cache::forget(Storage::CACHE_KEY); $this->status = null;
        $this->notice = 'Removed '.$removed.' unused images ('.Image::size($bytes).').'
            .($protected ? ' '.$protected.' images became protected and were kept.' : '');
    }

    public function render() { return view('livewire.station-storage', ['categories'=>Storage::CATEGORIES]); }
}
