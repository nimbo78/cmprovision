<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\SerialConsoleBroker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SerialConsoleController extends Controller
{
    private const KEY = ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/'];
    private const PORT = ['required', 'string', 'regex:~\Aby-(?:path|id)/(?!\.{1,2}\z)[\x20-\x2e\x30-\x7e]{1,255}\z~'];
    private const SEQUENCE = 'required|integer:strict|min:1|max:2147483647';

    public function index()
    {
        return response()->view('serial-console')->header('Cache-Control', 'no-store, private')
            ->header('X-Frame-Options', 'DENY')->header('Referrer-Policy', 'same-origin');
    }

    public function ports(SerialConsoleBroker $broker): JsonResponse
    {
        return $this->reply($broker->ports());
    }

    public function label(Request $request, SerialConsoleBroker $broker): JsonResponse
    {
        $body = $this->validated($request, ['port' => self::PORT, 'generation' => self::KEY,
            'label' => ['sometimes', 'string', 'max:64', 'not_regex:/[\p{C}]/u'], 'wake_on_connect' => 'sometimes|boolean:strict']);
        if ($body instanceof JsonResponse) return $body;
        if (!isset($body['label']) && !isset($body['wake_on_connect'])) return $this->invalid();
        $inventory = $broker->request(['op' => 'ports']);
        if (!$inventory['ok']) return $this->reply($inventory);
        $current = collect($inventory['ports'])->first(fn ($p) => $p['id'] === $body['port'] && $p['generation'] === $body['generation']);
        if (!$current) return $this->reply(['ok' => false, 'error' => 'conflict', 'message' => 'USB connection changed.']);
        if (($body['wake_on_connect'] ?? false) && !isset($current['wake_scope'])) {
            return $this->reply(['ok' => false, 'error' => 'unavailable', 'message' => 'Restart the console service to enable wake on connect.']);
        }
        DB::transaction(function () use ($body, $current) {
            $changes = [];
            if (isset($body['label'])) $changes[SerialConsoleBroker::labelKey($body['port'])] = trim($body['label']);
            if (isset($body['wake_on_connect'])) {
                $changes[SerialConsoleBroker::wakeKey($body['port'])] = $body['wake_on_connect'] ? $current['wake_scope'] : '';
            }
            foreach ($changes as $key => $value) {
                if ($value === '') Setting::whereKey($key)->delete();
                else Setting::upsert([['key' => $key, 'value' => $value]], ['key'], ['value']);
            }
        });
        return $this->reply(['ok' => true]);
    }

    public function operation(Request $request, SerialConsoleBroker $broker, string $operation): JsonResponse
    {
        $rules = match ($operation) {
            'open' => ['port' => self::PORT, 'generation' => self::KEY, 'mode' => 'required|in:connect,identify',
                'baud' => ['required', Rule::in(['auto', 9600, 19200, 38400, 57600, 115200])],
                'frame' => ['required', 'string', 'regex:/\A(?:auto|[5-8][neo][12])\z/'], 'wake' => 'sometimes|boolean:strict'],
            'poll' => ['session' => self::KEY, 'cursor' => 'required|integer:strict|min:0'],
            'close' => ['session' => self::KEY],
            'write' => ['session' => self::KEY, 'seq' => self::SEQUENCE, 'data' => 'required|string|max:5464'],
            'file_preview' => ['session' => self::KEY, 'seq' => self::SEQUENCE, 'filename' => ['required', 'string', 'max:255', 'not_regex:/[\p{C}]/u'],
                'text' => 'required|string'],
            'file_page' => ['session' => self::KEY, 'page' => 'required|integer:strict|min:0'],
            'file_action' => ['session' => self::KEY, 'seq' => self::SEQUENCE,
                'action' => 'required|in:start,pause,next,stop,clear,yes,no,enter,space,correct,filename',
                'value' => 'sometimes|string|max:8192', 'mode' => 'sometimes|in:bulk,smart,step', 'allow_default' => 'sometimes|boolean:strict'],
        };
        $body = $this->validated($request, $rules);
        if ($body instanceof JsonResponse) return $body;
        if ($operation === 'open' && $body['baud'] !== 'auto' && !is_int($body['baud'])) return $this->invalid();
        if ($operation === 'open' && ($body['wake'] ?? false)) {
            if ($body['mode'] !== 'connect') return $this->invalid();
            $inventory = $broker->ports();
            if (!$inventory['ok']) return $this->reply($inventory);
            $port = collect($inventory['ports'])->firstWhere('id', $body['port']);
            $body['wake'] = $port && $port['generation'] === $body['generation'] && $port['wake_on_connect'];
        }
        if ($operation === 'file_preview' && strlen($body['text']) > 1048576) return $this->invalid();
        if ($operation === 'write') {
            $bytes = base64_decode($body['data'], true);
            if ($bytes === false || strlen($bytes) > 4096 || base64_encode($bytes) !== $body['data']) return $this->invalid();
        }
        $userId = $request->user()->getAuthIdentifier();
        $result = $broker->request(['op' => $operation, 'owner' => SerialConsoleBroker::owner($request, $userId)] + $body);
        if ($operation === 'open' || $operation === 'close') {
            $lease = $result['session'] ?? $body['session'] ?? null;
            Log::info('USB console '.$operation, array_filter([
                'user_id' => $userId, 'port' => $body['port'] ?? null,
                'connection' => $lease === null ? null : substr(hash('sha256', $lease), 0, 24),
                'result' => $result['ok'] ? 'accepted' : ($result['error'] ?? 'unavailable'),
            ], static fn ($value) => $value !== null));
        }
        return $this->reply($result);
    }

    private function validated(Request $request, array $rules): array|JsonResponse
    {
        $origin = $request->headers->get('Origin');
        if (($origin !== null && $origin !== $request->getSchemeAndHttpHost()) || $request->header('Sec-Fetch-Site') === 'cross-site') {
            return $this->reply(['ok' => false, 'error' => 'forbidden', 'message' => 'A same-origin console request is required.']);
        }
        // A byte of UTF-8 file text can occupy up to six bytes in JSON escapes.
        if (!$request->isJson() || $request->query() !== [] || strlen($request->getContent()) > 6 * 1048576 + 8192) return $this->invalid();
        // read the original JSON so terminal whitespace survives Laravel's form normalisation
        try {
            $body = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->invalid();
        }
        if (!is_array($body) || array_diff_key($body, $rules) || Validator::make($body, $rules)->fails()) {
            return $this->invalid();
        }
        return $body;
    }

    private function invalid(): JsonResponse
    {
        return $this->reply(['ok' => false, 'error' => 'invalid', 'seq_consumed' => false, 'message' => 'Invalid console request.']);
    }

    private function reply(array $result): JsonResponse
    {
        $status = $result['ok'] ? 200 : match ($result['error'] ?? '') {
            'invalid' => 422, 'expired' => 410, 'conflict', 'busy' => 409, 'forbidden' => 403, default => 503,
        };
        return response()->json($result, $status)->header('Cache-Control', 'no-store, private');
    }
}
