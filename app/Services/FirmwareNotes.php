<?php

namespace App\Services;

use App\Models\Firmware;

/**
 * The release notes of the Pi 4 bootloader (firmware-2711/release-notes.md of rpi-eeprom), kept in the firmware
 * store beside the images. An entry is a "## <date><separator><title> (<channel>)" heading and what follows it; the
 * date of an entry is the date of the image it describes (pieeprom-<date>.bin), a "Promote <date> to ..." entry
 * tells where an image went later. The notes are text for people: nothing here decides which image is used.
 */
class FirmwareNotes
{
    const FILE = 'release-notes.md';

    protected $path;

    /** @var array|null the entries of the file, newest first, as the file lists them */
    protected $entries;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? Firmware::basedir().'/'.self::FILE;
    }

    /**
     * @return array<int, array{date: string, title: string, channel: ?string, promotion: bool, promotes: ?string, intro: string, items: array<int, array{title: string, detail: string}>}>
     */
    public static function parse(string $markdown): array
    {
        $entries = [];
        $current = null;
        foreach (preg_split('/\R/', $markdown) as $line) {
            if (str_starts_with($line, '## ')) {
                if ($current) {
                    $entries[] = self::entry($current);
                }
                $current = preg_match('/^## (\d{4}-\d{2}-\d{2})\b(.*)$/', $line, $m) ? ['date' => $m[1], 'heading' => $m[2], 'body' => []] : null;
            } elseif ($current) {
                $current['body'][] = $line;
            }
        }
        if ($current) {
            $entries[] = self::entry($current);
        }

        return $entries;
    }

    /** the date an image file is named after: pieeprom-2026-09-23.bin */
    public static function dateOf(string $fileName): ?string
    {
        return preg_match('/^pieeprom-(\d{4}-\d{2}-\d{2})/', $fileName, $m) ? $m[1] : null;
    }

    public function entries(): array
    {
        if ($this->entries === null) {
            $text = @file_get_contents($this->path);
            $this->entries = $text === false ? [] : self::parse($text);
        }

        return $this->entries;
    }

    /** the date of the newest entry, null without notes */
    public function latestDate(): ?string
    {
        $dates = array_column($this->entries(), 'date');

        return $dates ? max($dates) : null;
    }

    /**
     * What the notes say of an image file: the entries of its date and the promotions that name it.
     * Null when the file has no date in its name or there are no notes at all.
     *
     * @return array{date: string, changes: array, promotions: array}|null
     */
    public function forFile(string $fileName): ?array
    {
        $date = self::dateOf($fileName);
        if ($date === null || !$this->entries()) {
            return null;
        }
        $changes = $promotions = [];
        foreach ($this->entries() as $entry) {
            if ($entry['promotes'] === $date) {
                $promotions[] = $entry;
            } elseif ($entry['date'] === $date && !$entry['promotion']) {
                $changes[] = $entry;
            }
        }

        return ['date' => $date, 'changes' => $changes, 'promotions' => $promotions];
    }

    /** the entries of releases after $from up to and including $to, newest first, without promotions */
    public function between(string $from, string $to): array
    {
        return array_values(array_filter($this->entries(), function ($entry) use ($from, $to) {
            return !$entry['promotion'] && $entry['date'] > $from && $entry['date'] <= $to;
        }));
    }

    protected static function entry(array $raw): array
    {
        $title = trim(preg_replace('/^[\s:\-]+/', '', $raw['heading']));
        $channel = null;
        // the channel a heading names, last: "(latest)", "- (latest)" or "- STABLE"
        if (preg_match('/\s*-?\s*\((latest|default|stable|beta|critical)\)\s*$/i', $title, $m)
            || preg_match('/\s+-\s+(LATEST|DEFAULT|STABLE|BETA|CRITICAL)\s*$/', $title, $m)) {
            $title = trim(substr($title, 0, -strlen($m[0])));
            $channel = strtolower($m[1]);
        }
        $promotion = (bool) preg_match('/\bpromote\b|create new release from/i', $title);
        [$intro, $items] = self::body($raw['body']);

        return [
            'date' => $raw['date'],
            'title' => $title,
            'channel' => $channel,
            'promotion' => $promotion,
            // the image a promotion names, by the date in its title; null for "Promote to stable" without one
            'promotes' => $promotion && preg_match('/\d{4}-\d{2}-\d{2}/', $title, $m) ? $m[0] : null,
            'intro' => $intro,
            'items' => $items,
        ];
    }

    /** paragraphs before the first bullet are the intro; "* title" starts an item, indented and later lines are its detail */
    protected static function body(array $lines): array
    {
        $intro = [];
        $items = [];
        $item = null;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            if (preg_match('/^[*-] +(\S.*)$/', $line, $m)) {
                if ($item) {
                    $items[] = $item;
                }
                $item = ['title' => trim($m[1]), 'lines' => []];
            } elseif ($item) {
                $item['lines'][] = trim($line);
            } else {
                $intro[] = trim($line);
            }
        }
        if ($item) {
            $items[] = $item;
        }

        return [
            implode("\n", $intro),
            array_map(function ($item) {
                return ['title' => $item['title'], 'detail' => implode("\n", $item['lines'])];
            }, $items),
        ];
    }
}
