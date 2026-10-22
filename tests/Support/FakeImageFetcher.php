<?php

namespace Tests\Support;

use App\Services\ImageFetcher;

/** ImageFetcher for the tests of the fetch chain: what each slice does is scripted, every call is recorded */
class FakeImageFetcher extends ImageFetcher
{
    /** @var array what the next calls of download() do: a result array, a Closure(string $part, ?int $total, callable $progress, float $deadline): array, or an exception */
    public $script = [];
    /** @var array every download(): url, part, total, deadline */
    public $calls = [];
    /** @var array|\Throwable what probe() answers */
    public $probeAnswer = ['size' => 1000, 'ranges' => true, 'name' => 'image.img.gz', 'final' => 'https://example.org/image.img.gz'];

    public function __construct()
    {
    }

    public function download(string $url, string $part, ?int $total, callable $progress, float $deadline): array
    {
        $this->calls[] = compact('url', 'part', 'total', 'deadline');
        if (!$this->script) {
            throw new \LogicException('no step scripted for this slice');
        }
        $step = array_shift($this->script);
        if ($step instanceof \Throwable) {
            throw $step;
        }

        return $step instanceof \Closure ? $step($part, $total, $progress, $deadline) : $step;
    }

    public function probe(string $url): array
    {
        if ($this->probeAnswer instanceof \Throwable) {
            throw $this->probeAnswer;
        }

        return $this->probeAnswer;
    }

    /** a slice that appends $bytes to the part and is not the last */
    public static function writes(int $bytes, bool $done = false): \Closure
    {
        return function (string $part, ?int $total) use ($bytes, $done) {
            file_put_contents($part, str_repeat('x', $bytes), FILE_APPEND);

            return ['bytes' => filesize($part), 'done' => $done, 'restarted' => false, 'cancelled' => false];
        };
    }
}
