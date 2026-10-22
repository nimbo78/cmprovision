<?php

namespace App\Services;

use App\Support\ImageSourceException;
use App\Support\ImageUrlGuard;

/**
 * The transport of an image fetch (2.8.0), on curl: every address goes through ImageUrlGuard and the connection is pinned to
 * the address the guard chose; redirects are followed here, one checked hop at a time; a file is fetched from the byte it has
 * (Range), in slices that end at a deadline, and what arrives stays in the .part file whatever happens next.
 */
class ImageFetcher
{
    const MAX_REDIRECTS = 5;

    /* the curl errors that another try will not mend: the certificate is not accepted */
    const FATAL_CURL = [51, 58, 60, 83, 90, 91];

    protected $guard;
    protected $stallSeconds;
    protected $progressEvery;

    public function __construct(?ImageUrlGuard $guard = null, int $stallSeconds = 120, float $progressEvery = 1.0)
    {
        $this->guard = $guard ?? new ImageUrlGuard;
        $this->stallSeconds = $stallSeconds;
        $this->progressEvery = $progressEvery;
    }

    /**
     * What a file at an address is: its size, whether the server resumes, the name it goes by. A one-byte range request answers
     * all three (a HEAD does not work with every signed address).
     *
     * @return array{size: int, ranges: bool, name: ?string, final: string}
     */
    public function probe(string $url): array
    {
        $url = trim($url);
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $r = $this->hop($this->guard->check($url), ['Range: bytes=0-0'], null, null);
            if ($next = $this->redirect($url, $r)) {
                $url = $next;
                continue;
            }
            $this->requireAnswer($r, $url, [200, 206]);
            if ($r['status'] === 206 && preg_match('#^bytes \d+-\d+/(\d+)$#', $r['headers']['content-range'] ?? '', $m)) {
                $size = (int) $m[1];
            } elseif ($r['status'] === 200 && isset($r['headers']['content-length']) && ctype_digit($r['headers']['content-length'])) {
                $size = (int) $r['headers']['content-length'];
            } else {
                throw new ImageSourceException('The server does not tell the size of the file, so it cannot be fetched.', true);
            }

            return ['size' => $size, 'ranges' => $r['status'] === 206, 'name' => $this->nameOf($url, $r['headers']), 'final' => $url];
        }

        throw new ImageSourceException('Too many redirects.', true);
    }

    /**
     * Fetches into $part from the byte it already has until the file is whole, $deadline (a slice) or the callback says stop.
     * A server that does not resume, or a file that changed, starts the file again. A transfer that breaks keeps what arrived.
     *
     * @param callable(int): bool $progress gets the bytes in $part now, at most every progressEvery seconds; false stops the fetch
     * @return array{bytes: int, done: bool, restarted: bool, cancelled: bool}
     * @throws ImageSourceException
     */
    public function download(string $url, string $part, ?int $total, callable $progress, float $deadline): array
    {
        $state = (object) ['restarted' => false, 'cancelled' => false, 'ended' => false, 'changed' => false, 'handle' => null, 'last' => 0.0, 'offset' => 0];
        $state->offset = is_file($part) ? (int) filesize($part) : 0;
        if (!is_file($part)) {
            touch($part);
        }
        if ($total !== null && $state->offset > $total) {
            $this->truncate($part, $state);
        }
        if ($total !== null && $state->offset === $total) {
            return $this->result($part, true, $state);
        }
        if (microtime(true) >= $deadline) {
            return $this->result($part, false, $state);
        }

        $url = trim($url);
        $r = null;
        try {
            for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
                $sink = function (string $chunk, array $head) use ($part, $total, $progress, $deadline, $state): int {
                    return $this->receive($chunk, $head, $part, $total, $progress, $deadline, $state);
                };
                $r = $this->hop($this->guard->check($url), ['Range: bytes='.$state->offset.'-'], $sink, $deadline);
                if ($next = $this->redirect($url, $r)) {
                    $url = $next;
                    continue;
                }
                break;
            }
        } finally {
            if ($state->handle) {
                fclose($state->handle);   // what arrived is in the part, whatever happened
                $state->handle = null;
            }
        }
        if ($r['status'] >= 300 && $r['status'] < 400 && isset($r['headers']['location'])) {
            throw new ImageSourceException('Too many redirects.', true);
        }
        if ($r['status'] === 416 && $state->offset > 0) {
            $this->truncate($part, $state);   // the file got shorter than what we have

            return $this->result($part, false, $state);
        }
        if ($r['status'] !== 0 && !in_array($r['status'], [200, 206], true)) {
            $this->requireAnswer($r, $url, [200, 206]);
        }
        if ($state->changed || $state->cancelled || $state->ended) {
            return $this->result($part, false, $state);
        }
        if ($r['flag'] === 'deadline' || ($r['errno'] === 28 && microtime(true) >= $deadline)) {
            return $this->result($part, false, $state);   // the slice ran out, with data coming or while waiting for it
        }
        if ($r['errno'] !== 0) {
            throw $this->curlError($r, $url);
        }

        $size = (int) filesize($part);

        return $this->result($part, $total === null ? true : $size === $total, $state);
    }

    /** One request to one checked address; the body goes to $sink (final answers only) or is dropped */
    protected function hop(array $checked, array $headers, ?callable $sink, ?float $deadline): array
    {
        $head = ['status' => 0, 'headers' => []];
        $lastData = microtime(true);
        $flag = null;
        $ch = curl_init($checked['url']);
        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => array_merge(['Accept: */*'], $headers),
            CURLOPT_USERAGENT => 'cmprovision',
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_NOSIGNAL => true,
            // called about every second, data or not: the one place that sees a transfer that went quiet or a slice that ran out
            CURLOPT_NOPROGRESS => false,
            CURLOPT_XFERINFOFUNCTION => function ($ch, $dlTotal, $dlNow, $ulTotal, $ulNow) use (&$lastData, &$flag, $deadline): int {
                $now = microtime(true);
                if ($now - $lastData > $this->stallSeconds) {
                    $flag = 'stalled';

                    return 1;
                }
                if ($deadline !== null && $now >= $deadline) {
                    $flag = 'deadline';

                    return 1;
                }

                return 0;
            },
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$head, &$lastData): int {
                $lastData = microtime(true);
                if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $m)) {
                    $head = ['status' => (int) $m[1], 'headers' => []];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $head['headers'][strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$head, &$lastData, $sink): int {
                $lastData = microtime(true);
                $status = $head['status'];
                if ($sink === null || $status < 200 || $status >= 300) {
                    return $status >= 300 && $status < 400 ? strlen($chunk) : 0;   // a redirect's body is nothing; an error's or a probe's is not wanted
                }

                return $sink($chunk, $head);
            },
        ];
        $options[CURLOPT_TIMEOUT_MS] = $deadline === null ? 60000 : (int) max(1000, ceil(($deadline - microtime(true)) * 1000) + 5000);
        // https only; the tests' server speaks http on loopback
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = $checked['scheme'] === 'http' ? 'http,https' : 'https';
        } else {
            $options[CURLOPT_PROTOCOLS] = $checked['scheme'] === 'http' ? CURLPROTO_HTTP | CURLPROTO_HTTPS : CURLPROTO_HTTPS;
        }
        // the connection goes to the address that was checked, not to whatever the name says now
        if (!filter_var($checked['host'], FILTER_VALIDATE_IP)) {
            $ip = str_contains($checked['ip'], ':') ? '['.$checked['ip'].']' : $checked['ip'];
            $options[CURLOPT_RESOLVE] = [$checked['host'].':'.$checked['port'].':'.$ip];
        }
        curl_setopt_array($ch, $options);
        curl_exec($ch);
        $result = ['status' => $head['status'], 'headers' => $head['headers'], 'errno' => curl_errno($ch), 'error' => curl_error($ch), 'flag' => $flag, 'host' => $checked['host'], 'port' => $checked['port']];
        if ($result['status'] === 0) {
            $result['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        }
        // a body that was dropped on purpose is no failure
        if ($result['errno'] === 23 && $result['status'] !== 0) {
            $result['errno'] = $sink === null ? 0 : 23;
        }

        return $result;
    }

    /* the body of the final answer: checks what the server says, then appends to the part */
    protected function receive(string $chunk, array $head, string $part, ?int $total, callable $progress, float $deadline, object $state): int
    {
        if ($state->handle === null) {
            $status = (int) ($head['status'] ?? 0);
            if ($status === 206) {
                if (!preg_match('#^bytes (\d+)-\d+/(\d+|\*)$#', $head['headers']['content-range'] ?? '', $m) || (int) $m[1] !== $state->offset
                    || ($total !== null && $m[2] !== '*' && (int) $m[2] !== $total)) {
                    $this->truncate($part, $state);
                    $state->changed = true;

                    return 0;
                }
            } elseif ($status === 200) {
                $length = $head['headers']['content-length'] ?? null;
                if ($total !== null && $length !== null && ctype_digit($length) && (int) $length !== $total) {
                    $this->truncate($part, $state);
                    $state->changed = true;

                    return 0;
                }
                if ($state->offset > 0) {
                    $this->truncate($part, $state);   // it does not resume: the same answer holds the whole file
                }
            }
            $state->handle = fopen($part, 'ab');
            if ($state->handle === false) {
                $state->handle = null;
                throw new ImageSourceException('The image cannot be written: check the free space of the server.', true);
            }
        }
        if (microtime(true) >= $deadline) {
            $state->ended = true;

            return 0;
        }
        $written = fwrite($state->handle, $chunk);
        if ($written !== strlen($chunk)) {
            throw new ImageSourceException('The image cannot be written: check the free space of the server.', true);
        }
        $state->offset += $written;
        $now = microtime(true);
        if ($now - $state->last >= $this->progressEvery) {
            $state->last = $now;
            fflush($state->handle);
            if ($progress($state->offset) === false) {
                $state->cancelled = true;

                return 0;
            }
        }

        return $written;
    }

    protected function truncate(string $part, object $state): void
    {
        if ($state->handle) {
            fclose($state->handle);
            $state->handle = null;
        }
        file_put_contents($part, '');
        $state->offset = 0;
        $state->restarted = true;
    }

    protected function result(string $part, bool $done, object $state): array
    {
        clearstatcache(true, $part);

        return ['bytes' => (int) @filesize($part), 'done' => $done, 'restarted' => $state->restarted, 'cancelled' => $state->cancelled];
    }

    /* the address a redirect points to, null when the answer is no redirect; absolute, with the query kept for the fetch */
    protected function redirect(string $from, array $r): ?string
    {
        if (!in_array($r['status'], [301, 302, 303, 307, 308], true) || empty($r['headers']['location'])) {
            return null;
        }

        return self::absolute($from, $r['headers']['location']);
    }

    public static function absolute(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }
        $dir = substr($parts['path'] ?? '/', 0, strrpos($parts['path'] ?? '/', '/') + 1);

        return $origin.$dir.$location;
    }

    /* an answer that is not one of $allowed: why, in words; a client error will not mend by itself, a server error may */
    protected function requireAnswer(array $r, string $url, array $allowed): void
    {
        if (in_array($r['status'], $allowed, true)) {
            return;
        }
        if ($r['status'] === 0) {
            throw $this->curlError($r, $url);
        }
        $reasons = [400 => 'bad request', 401 => 'sign-in needed', 403 => 'forbidden', 404 => 'not found', 410 => 'gone', 429 => 'too many requests',
            500 => 'server error', 502 => 'bad gateway', 503 => 'unavailable', 504 => 'gateway timeout'];
        $text = 'The server answered '.$r['status'].(isset($reasons[$r['status']]) ? ' ('.$reasons[$r['status']].')' : '').' for '.ImageUrlGuard::redact($url).'.';
        throw new ImageSourceException($text, $r['status'] >= 400 && $r['status'] < 500 && $r['status'] !== 429);
    }

    protected function curlError(array $r, string $url): ImageSourceException
    {
        $detail = trim(preg_replace('/\?\S*/', '', $r['error']));   // curl may quote an address: its query is not for messages
        if ($r['flag'] === 'stalled' || $r['errno'] === 28 || $r['errno'] === 18 || $r['errno'] === 56) {
            $text = ($r['flag'] === 'stalled' || $r['errno'] === 28) ? 'The download stalled: no data for '.$this->stallSeconds.' seconds.' : 'The connection was lost during the download ('.$detail.').';
        } elseif (in_array($r['errno'], [7, 6, 35], true)) {
            $text = 'Cannot connect to '.$r['host'].':'.$r['port'].' ('.$detail.'): check the internet access of the server.';
        } elseif (in_array($r['errno'], self::FATAL_CURL, true)) {
            $text = 'The server\'s certificate was not accepted ('.$detail.').';
        } else {
            $text = 'The download failed ('.$detail.', curl '.$r['errno'].').';
        }

        return new ImageSourceException($text, in_array($r['errno'], self::FATAL_CURL, true));
    }

    /* the name a file goes by: the server's Content-Disposition, else the last part of the path */
    protected function nameOf(string $url, array $headers): ?string
    {
        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $headers['content-disposition'] ?? '', $m)) {
            $name = rawurldecode($m[1]);
        } else {
            $name = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
        }
        $name = trim(preg_replace('/[\x00-\x1f\x7f\/\\\\]+/', '', $name));

        return $name === '' ? null : $name;
    }
}
