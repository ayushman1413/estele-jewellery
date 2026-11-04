@extends('layouts.app')

@section('meta_title', 'Create Account | '.($siteSettings['site_name'] ?? 'Estele'))
@section('meta_description', 'Create an account to track your orders and check out faster.')

@section('content')

<div class="bg-ivory py-10 md:py-16 min-h-[calc(100vh-220px)]">
    <div class="mx-auto w-full max-w-[450px] px-4">

        <div class="rounded-2xl border border-line bg-white p-6 sm:p-8 shadow-md">

            <h1 class="font-serif text-[24px] font-semibold text-heading text-center mb-1">
                Create Your Account
            </h1>

            <p class="text-[13px] text-muted text-center mb-6">
                Join Estele for exclusive member offers &amp; faster checkout.
            </p>

            @if($errors->any())
                <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-[13px] text-red-700">
                    Please correct the highlighted fields below.
                </div>
            @endif

            {{-- Phone is already OTP-verified by the /login flow before landing
                 here — no second OTP step, just finish the profile. --}}
            <form action="{{ route('register.attempt') }}" method="POST" class="space-y-4" data-loading-submit>
                @csrf

                <div>
                    <label class="mb-1.5 block text-[13px] font-medium text-heading" for="name">Full Name</label>
                    <input
                        class="h-12 w-full rounded-lg border border-line-strong bg-white px-4 text-[14px] outline-none transition-colors placeholder:text-muted focus:border-accent focus:ring-1 focus:ring-accent"
                        id="name" name="name" type="text" value="{{ old('name') }}"
                        placeholder="First and last name" required autofocus>
                    @error('name') <p class="mt-1 text-[12px] text-salebadge">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-1.5 block text-[13px] font-medium text-heading" for="phone">Mobile Number</label>
                    <div class="flex">
                        <span class="inline-flex h-12 items-center rounded-l-lg border border-r-0 border-line-strong bg-warmbeige/30 px-3.5 text-[13px] font-semibold text-heading">+91</span>
                        <input
                            class="h-12 w-full rounded-r-lg border border-line-strong bg-pinksoft/40 px-4 text-[14px] text-heading outline-none"
                            id="phone" name="phone" type="tel" value="{{ old('phone', $prefillPhone) }}"
                            inputmode="numeric" pattern="[0-9]{10}" maxlength="10" required readonly>
                    </div>
                    <p class="mt-1 text-[12px] text-muted">Verified via OTP.</p>
                    @error('phone') <p class="mt-1 text-[12px] text-salebadge">{{ $message }}</p> @enderror
                </div>

                <button type="submit" class="mt-2 h-12 w-full rounded-lg bg-accent text-[13px] font-semibold uppercase tracking-[0.6px] text-white shadow-sm transition-colors hover:bg-accent-dark">
                    Create Account
                </button>
            </form>

            <p class="mt-6 text-center text-[13px] text-muted">
                Already have an account?
                <a class="font-semibold text-accent underline hover:text-accent-dark" href="{{ route('login') }}">Log in</a>
            </p>

        </div>

    </div>
</div>

@endsection
