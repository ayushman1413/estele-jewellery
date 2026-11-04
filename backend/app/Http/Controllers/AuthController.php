<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showRegister()
    {
        return view('auth.register', [
            'prefillPhone' => session('prefill_phone'),
        ]);
    }

    /**
     * Create the account. Only reachable after OtpAuthController::verifyCode()
     * has already OTP-verified the phone and set 'registration_phone_verified'
     * — this form does not ask for OTP a second time.
     */
    public function register(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'digits:10', 'unique:users,phone'],
        ]);

        // Get the phone number that was actually verified through OTP.
        $verifiedPhone = $request->session()->get(
            'registration_phone_verified'
        );

        // Make sure the phone submitted in the form is the same
        // phone number that completed OTP verification.
        if (! $verifiedPhone || $verifiedPhone !== $validated['phone']) {
            throw ValidationException::withMessages([
                'phone' => 'Please verify your mobile number with OTP before creating your account.',
            ]);
        }

        // Mobile-only account — no email or password, login is always via OTP.
        $user = User::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
        ]);

        // Remove temporary OTP registration session data.
        $request->session()->forget([
            'registration_otp_phone',
            'registration_phone_verified',
            'prefill_phone',
        ]);

        // Log the new user in automatically.
        $oldSessionId = $request->session()->getId();

        Auth::login($user, remember: true);

        $request->session()->regenerate();

        Cart::transferSession(
            $oldSessionId,
            $request->session()->getId()
        );

        return redirect()
            ->intended(route('account.index'))
            ->with('success', 'Account created — welcome!');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('home')
            ->with('success', 'You have been logged out.');
    }
}
