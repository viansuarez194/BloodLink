<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function create()
    {
        return view('auth.login');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'login'    => ['required', 'string', 'max:190'],
            'password' => ['required', 'string'],
        ], [
            'login.required'    => 'Enter your email or mobile number.',
            'password.required' => 'Enter your password.',
        ]);

        $login = trim($data['login']);

        // Email or mobile number?
        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            $field = 'email';
            $login = Str::lower($login);
        } else {
            $field = 'mobile';
            $login = User::normalizeMobile($login);
        }

        // Basic brute-force protection: 5 tries per minute per login + IP.
        $throttleKey = Str::lower($login) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'login' => "Too many attempts. Please try again in {$seconds} seconds.",
            ]);
        }

        if (! Auth::attempt([$field => $login, 'password' => $data['password']], $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'login' => 'These credentials do not match our records.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
