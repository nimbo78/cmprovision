<?php

namespace App\Http\Controllers;

use App\Actions\Fortify\PasswordValidationRules;
use App\Models\User;
use App\Services\FirstAdmin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class FirstAdminController extends Controller
{
    use PasswordValidationRules;

    public function show(FirstAdmin $bootstrap)
    {
        $state = $bootstrap->state();
        if ($state === 'closed') return redirect()->route('login')->header('Cache-Control', 'no-store');
        return response()->view('auth.first-admin', ['available' => $state === 'armed'], $state === 'armed' ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    public function claim(Request $request, FirstAdmin $bootstrap)
    {
        if ($bootstrap->state() !== 'armed') return redirect()->route('login')->header('Cache-Control', 'no-store');
        $validator = Validator::make($request->only(['setup_code', 'name', 'email', 'password', 'password_confirmation']), [
            'setup_code' => ['required', 'string', 'max:64'], 'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => [...$this->passwordRules(), function ($attribute, $value, $fail) {
                if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                    $fail('Use a password of at most 72 bytes without null characters.');
                }
            }],
        ]);
        if ($validator->fails()) {
            return redirect()->route('first-admin')->withErrors($validator)->withInput($request->only('name', 'email'))
                ->header('Cache-Control', 'no-store');
        }
        $data = $validator->validated();
        $hash = Hash::make($data['password']);
        $result = $bootstrap->claim($data['setup_code'], $data['name'], $data['email'], $hash);
        if ($result['state'] === 'claimed' && is_int($result['id'] ?? null)) {
            $user = User::find($result['id']);
            if ($user && $user->email === $data['email'] && hash_equals($user->password, $hash)) {
                Auth::guard('web')->login($user);
                $request->session()->regenerate();
                return redirect()->route('settings.setup')->header('Cache-Control', 'no-store');
            }
        }
        if ($result['state'] === 'closed' || $result['state'] === 'claimed') return redirect()->route('login')->header('Cache-Control', 'no-store');
        $message = match ($result['state']) {
            'limited' => 'Too many incorrect codes. Try again in one minute.',
            'invalid' => 'Check the setup code and try again.',
            default => 'Station setup is unavailable. If you already submitted this form, try signing in.',
        };
        return redirect()->route('first-admin')->withErrors(['setup_code' => $message])
            ->withInput($request->only('name', 'email'))->header('Cache-Control', 'no-store');
    }
}
