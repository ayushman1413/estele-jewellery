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

        $this->otp->issue($validated['phone']);
        $request->session()->put('otp_phone', $validated['phone']);

        return redirect()->route('login.verify');
    }

    public function showVerify(Request $request)
    {
        $phone = $request->session()->get('otp_phone');
        abort_unless($phone, 404);

        return view('auth.login-verify', ['phone' => $phone]);
    }

    public function verifyCode(Request $request): RedirectResponse
    {
        $phone = $request->session()->get('otp_phone');
        abort_unless($phone, 404);

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
        abort_unless($phone, 404);

        $this->otp->issue($phone);

        return back()->with('success', 'A new code has been sent.');
    }
}
