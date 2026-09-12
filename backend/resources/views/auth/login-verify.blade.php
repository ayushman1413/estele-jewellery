@extends('layouts.app')

@section('meta_title', 'Verify Code | '.($siteSettings['site_name'] ?? 'Estele'))

@section('content')

<div class="bg-ivory py-10 md:py-16 min-h-[calc(100vh-220px)]">
  <div class="mx-auto w-full max-w-[450px] px-4">
    <div class="rounded-2xl border border-line bg-white p-6 sm:p-8 shadow-md">

      <h1 class="font-serif text-[24px] font-semibold text-heading text-center mb-1">Enter Verification Code</h1>
      <p class="text-[13px] text-muted text-center mb-6">We sent a 6-digit code to <strong class="text-heading">{{ $phone }}</strong>.</p>

      <form action="{{ route('login.verify.attempt') }}" method="post" class="space-y-4" data-loading-submit>
        @csrf

        <div>
          <label class="mb-1.5 block text-[13px] font-medium text-heading text-center" for="code">One-Time Code</label>
          <input class="h-14 w-full rounded-lg border border-line-strong bg-white px-4 text-center text-[22px] font-semibold text-heading outline-none transition-colors placeholder:text-muted focus:border-accent focus:ring-1 focus:ring-accent" style="letter-spacing: 0.5em" id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" placeholder="••••••" required autofocus>
          @error('code') <p class="mt-1 text-[12px] text-salebadge text-center">{{ $message }}</p> @enderror
        </div>

        <button class="mt-2 h-12 w-full rounded-lg bg-accent text-[13px] font-semibold uppercase tracking-[0.6px] text-white shadow-sm transition-colors hover:bg-accent-dark" type="submit">
          Verify &amp; Continue
        </button>
      </form>

      <form class="mt-4" action="{{ route('login.resend') }}" method="post">
        @csrf
        <button class="w-full text-center text-[13px] font-medium text-accent hover:underline" type="submit">Didn't receive code? Resend</button>
      </form>

      <p class="mt-6 text-center text-[13px] text-muted">
        Wrong mobile number? <a class="font-semibold text-accent underline hover:text-accent-dark" href="{{ route('login') }}">Start over</a>
      </p>

    </div>
  </div>
</div>

@endsection
