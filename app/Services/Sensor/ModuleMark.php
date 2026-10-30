<?php

namespace App\Services\Sensor;

use App\Models\Cm;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * The provisioner's mark over LLDP (2.23.0). The re-provision agent leaves a service in the written system
 * (scriptexecute/module-mark.blade.php) that, for the first mark_boots boots of the project, gives lldpd the port description
 * "cmprovision mark <station> <boot>/<boots> <code>". The code is an HMAC of "<station> <boot>/<boots>" with the module's
 * key, so it is new on every boot; the module's key is an HMAC of its MAC with the mark key of the provisioners. The mark key
 * is kept here (Settings, encrypted with the APP_KEY) and set to the same value on every station that should know the
 * others' modules: any of them checks a mark, and a module holds only its own key. After its last boot the module drops
 * the mark, the key and the service.
 */
class ModuleMark
{
    const SETTING = 'module_mark_key';
    const TEXT = '/^cmprovision mark ([A-Za-z0-9][A-Za-z0-9._-]{0,31}) ([1-9][0-9]{0,2})\/([1-9][0-9]{0,2}) ([0-9a-f]{16})$/D';
    const MAX_BOOTS = 100;

    protected ?string $key;
    protected bool $loaded;

    /** $key: a key of its own instead of the one in Settings (tests) */
    public function __construct(?string $key = null)
    {
        $this->key = $key;
        $this->loaded = $key !== null;
    }

    /** The mark key: 64 hex digits, or null when this station has none */
    public function key(): ?string
    {
        if (!$this->loaded)
        {
            $this->loaded = true;
            try
            {
                $value = Setting::find(self::SETTING)?->value;
                $this->key = $value ? Crypt::decryptString($value) : null;
            }
            catch (\Throwable $e)
            {
                $this->key = null;   // another APP_KEY (a data-only restore elsewhere) or no settings table yet
            }
        }
        return $this->key;
    }

    /** A new key for this station; the stations that should know each other's modules take it with set() */
    public function generate(): string
    {
        $key = bin2hex(random_bytes(32));
        $this->store($key);
        return $key;
    }

    /** The key of another station: 64 hex digits */
    public function set(string $key): void
    {
        $key = strtolower(trim($key));
        if (!preg_match('/^[0-9a-f]{64}$/D', $key))
            throw new \InvalidArgumentException('A mark key is 64 hex digits.');
        $this->store($key);
    }

    protected function store(string $key): void
    {
        Setting::updateOrCreate(['key' => self::SETTING], ['value' => Crypt::encryptString($key)]);
        $this->key = $key;
        $this->loaded = true;
    }

    /** What two stations compare to know they have the same key, without showing it */
    public function fingerprint(): ?string
    {
        $key = $this->key();
        return $key === null ? null : substr(hash('sha256', $key), 0, 12);
    }

    /** The key of the module with this MAC (hex), or null without a mark key */
    public function moduleKey(string $mac): ?string
    {
        $key = $this->key();
        return $key === null ? null : hash_hmac('sha256', Cm::normalizeMac($mac), hex2bin($key));
    }

    /** The code of a boot: the first 16 hex digits of the HMAC of "<station> <boot>/<boots>" with the module's key */
    public static function code(string $moduleKey, string $station, int $boot, int $boots): string
    {
        return substr(hash_hmac('sha256', "$station $boot/$boots", hex2bin($moduleKey)), 0, 16);
    }

    /** The name the mark gives this station: its host name up to the first dot */
    public static function station(): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]/', '', explode('.', (string) gethostname())[0]);
        $name = substr(ltrim((string) $name, '._-'), 0, 32);
        return $name !== '' ? $name : 'cmprovision';
    }

    /** ['station', 'boot', 'boots', 'code'] of a port description that is a mark, or null */
    public static function parse(?string $text): ?array
    {
        if ($text === null || !preg_match(self::TEXT, $text, $m) || (int) $m[2] > (int) $m[3])
            return null;
        return ['station' => $m[1], 'boot' => (int) $m[2], 'boots' => (int) $m[3], 'code' => $m[4]];
    }

    /**
     * What the mark of a neighbour (Neighbors) says: ['station', 'boot', 'boots', 'verified'], or null when its port
     * description is no mark. verified: true when this station's key gives the same code for one of the neighbour's MACs
     * (the chassis and the port), false when it does not, null when this station has no key.
     */
    public function check(array $neighbour): ?array
    {
        $mark = self::parse($neighbour['port'] ?? null);
        if ($mark === null)
            return null;
        $verified = null;
        if ($this->key() !== null)
        {
            $verified = false;
            foreach (array_unique(array_filter([$neighbour['mac'] ?? null, $neighbour['port_mac'] ?? null])) as $mac)
                if (hash_equals(self::code($this->moduleKey($mac), $mark['station'], $mark['boot'], $mark['boots']), $mark['code']))
                    $verified = true;
        }
        return ['station' => $mark['station'], 'boot' => $mark['boot'], 'boots' => $mark['boots'], 'verified' => $verified];
    }
}
