<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\User;
use App\Services\Otp\OtpManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class OtpAuthController extends Controller
{
    public function __construct(private readonly OtpManager $otp) {}

    public function showPhone()
    {
        return view('auth.login');
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'digits_between:10,15'],
        ]);

        $phone = OtpManager::normalisePhone($validated['phone']);

        if (! $this->otp->issue($phone)) {
            throw ValidationException::withMessages([
                'phone' => 'We could not send the code right now. Please try again in a moment.',
            ]);
        }

        $request->session()->put('otp_phone', $phone);

        return redirect()->route('login.verify');
    }

    public function showVerify(Request $request)
    {
        $phone = $request->session()->get('otp_phone');

        if (! $phone) {
            return $this->startOver();
        }

        return view('auth.login-verify', ['phone' => $phone]);
    }

    public function verifyCode(Request $request): RedirectResponse
    {
        $phone = $request->session()->get('otp_phone');

        if (! $phone) {
            return $this->startOver();
        }

        $validated = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        // One generic message regardless of expired/wrong/too-many-attempts —
        // don't reveal which part was wrong.
        if (! $this->otp->verify($phone, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => 'That code is incorrect or has expired.',
            ]);
        }

        $user = User::where('phone', $phone)->first();
        $request->session()->forget('otp_phone');

        if (! $user) {
            // Not registered under this number yet — send to registration with
            // the verified number carried over and pre-filled, same as ZappDeal's
            // flow: register, don't silently create an account with no name/email.
            // 'registration_phone_verified' is what AuthController::register()
            // actually trusts (this OTP round is the one and only verification —
            // the registration form doesn't ask for OTP again).
            $request->session()->put('prefill_phone', $phone);
            $request->session()->put('registration_phone_verified', $phone);

            return redirect()->route('register')->with('success', 'Number verified — finish creating your account below.');
        }

        $oldSessionId = $request->session()->getId();
        Auth::login($user, remember: true);
        $request->session()->regenerate();
        Cart::transferSession($oldSessionId, $request->session()->getId());

        return redirect()->intended(route('account.index'))->with('success', 'Welcome back!');
    }

    public function resend(Request $request): RedirectResponse
    {
        $phone = $request->session()->get('otp_phone');

        if (! $phone) {
            return $this->startOver();
        }

        if (! $this->otp->issue($phone)) {
            return back()->with('error', 'We could not send a new code right now. Please try again in a moment.');
        }

        return back()->with('success', 'A new code has been sent.');
    }

    private function startOver(): RedirectResponse
    {
        return redirect()->route('login')->with('error', 'Please enter your mobile number again.');
    }
}
