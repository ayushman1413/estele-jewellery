@extends('layouts.app')

@section('meta_title', 'Login with Mobile Number | '.($siteSettings['site_name'] ?? 'Estele'))
@section('meta_description', 'Login or create an account using your mobile number and a one-time code.')

@section('content')

<div class="bg-ivory py-10 md:py-16 min-h-[calc(100vh-220px)]">
  <div class="mx-auto w-full max-w-[450px] px-4">
    <div class="rounded-2xl border border-line bg-white p-6 sm:p-8 shadow-md">

      <h1 class="font-serif text-[24px] font-semibold text-heading text-center mb-1">Welcome Back</h1>
      <p class="text-[13px] text-muted text-center mb-6">We'll text a one-time verification code to your phone.</p>

      <form action="{{ route('login.send') }}" method="post" class="space-y-4" data-loading-submit>
        @csrf

        <div>
          <label class="mb-1.5 block text-[13px] font-medium text-heading" for="phone">Mobile Number</label>
          <div class="flex">
            <span class="inline-flex h-12 items-center rounded-l-lg border border-r-0 border-line-strong bg-warmbeige/30 px-3.5 text-[13px] font-semibold text-heading">+91</span>
            <input class="h-12 w-full rounded-r-lg border border-line-strong bg-white px-4 text-[14px] outline-none transition-colors placeholder:text-muted focus:border-accent focus:ring-1 focus:ring-accent" id="phone" name="phone" type="tel" value="{{ old('phone') }}" placeholder="10-digit mobile number" inputmode="numeric" pattern="[0-9]*" maxlength="10" required autofocus>
          </div>
          @error('phone') <p class="mt-1 text-[12px] text-salebadge">{{ $message }}</p> @enderror
        </div>

        <button class="mt-2 h-12 w-full rounded-lg bg-accent text-[13px] font-semibold uppercase tracking-[0.6px] text-white shadow-sm transition-colors hover:bg-accent-dark" type="submit">
          Send OTP
        </button>
      </form>

      <p class="mt-6 text-center text-[12.5px] text-muted leading-relaxed">
        New here? You'll be guided to finish creating an account right after your number is verified.
      </p>

    </div>
  </div>
</div>

@endsection
