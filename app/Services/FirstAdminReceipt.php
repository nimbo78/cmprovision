<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FirstAdminReceipt
{
    /** The broker holds its lock across this SQLite IMMEDIATE transaction. */
    public function handle(#[\SensitiveParameter] array $request): array
    {
        if ($request !== []) {
            Validator::make($request, [
                'claim' => ['required', 'regex:/^[0-9a-f]{32}$/D'],
                'name' => 'required|string|max:255', 'email' => 'required|string|email|max:255',
                'password_hash' => ['required', 'string', 'regex:/^\$2[ayb]\$\d{2}\$[.\/A-Za-z0-9]{53}$/D'],
            ])->validate();
        }
        return DB::transaction(function () use ($request) {
            $table = DB::table('first_admin_receipts');
            $receipt = $table->find(1);
            if ($receipt && $request && $receipt->claim === $request['claim']) {
                $user = User::find($receipt->user_id);
                if ($user && $user->email === $request['email'] && hash_equals($user->password, $request['password_hash'])) {
                    return ['result' => 'committed', 'claim' => $receipt->claim, 'id' => $user->id];
                }
            }
            if ($receipt || User::query()->exists()) {
                return ['result' => 'occupied'];
            }
            if (!$request) {
                return ['result' => 'empty'];
            }
            $user = User::create(['name' => $request['name'], 'email' => $request['email'], 'password' => $request['password_hash']]);
            $user->forceFill(['serial_console_access' => true])->save();
            $table->insert(['id' => 1, 'claim' => $request['claim'], 'user_id' => $user->id]);
            return ['result' => 'committed', 'claim' => $request['claim'], 'id' => $user->id];
        });
    }
}
