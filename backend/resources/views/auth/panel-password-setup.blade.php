@extends('layouts.app')

@section('meta_title', 'Set Your Password | '.($siteSettings['site_name'] ?? 'Estele'))

@section('content')

<div class="bg-ivory py-10 md:py-16 min-h-[calc(100vh-220px)]">
  <div class="mx-auto w-full max-w-[450px] px-4">
    <div class="rounded-2xl border border-line bg-white p-6 sm:p-8 shadow-md">

      <h1 class="font-serif text-[24px] font-semibold text-heading text-center mb-1">Set Your Password</h1>
      <p class="text-[13px] text-muted text-center mb-6">Choose a password to finish setting up your account, then sign in.</p>

      @if($errors->any())
        <p class="mb-4 rounded-lg border border-line bg-pinksoft px-4 py-3 text-[13px] text-salebadge">
          {{ $errors->first() }}
        </p>
      @endif

      <form action="{{ route('panel.password.store') }}" method="post" class="space-y-4" data-loading-submit>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div>
          <label class="mb-1.5 block text-[13px] font-medium text-heading" for="email">Email</label>
          <input class="h-12 w-full rounded-lg border border-line-strong bg-white px-4 text-[14px] outline-none transition-colors placeholder:text-muted focus:border-accent focus:ring-1 focus:ring-accent" id="email" name="email" type="email" value="{{ old('email', $email) }}" required readonly>
        </div>

        <div>
          <label class="mb-1.5 block text-[13px] font-medium text-heading" for="password">New password</label>
          <input class="h-12 w-full rounded-lg border border-line-strong bg-white px-4 text-[14px] outline-none transition-colors placeholder:text-muted focus:border-accent focus:ring-1 focus:ring-accent" id="password" name="password" type="password" placeholder="At least 8 characters" required autofocus autocomplete="new-password">
        </div>

        <div>
          <label class="mb-1.5 block text-[13px] font-medium text-heading" for="password_confirmation">Confirm password</label>
          <input class="h-12 w-full rounded-lg border border-line-strong bg-white px-4 text-[14px] outline-none transition-colors placeholder:text-muted focus:border-accent focus:ring-1 focus:ring-accent" id="password_confirmation" name="password_confirmation" type="password" placeholder="Re-enter your password" required autocomplete="new-password">
        </div>

        <button class="mt-2 h-12 w-full rounded-lg bg-accent text-[13px] font-semibold uppercase tracking-[0.6px] text-white shadow-sm transition-colors hover:bg-accent-dark" type="submit">
          Set Password
        </button>
      </form>

      <p class="mt-6 text-center text-[12.5px] text-muted leading-relaxed">
        This link is valid for 48 hours. If it has expired, ask your
        {{ $siteSettings['site_name'] ?? 'Estele' }} contact to send a new one.
      </p>

    </div>
  </div>
</div>

@endsection
