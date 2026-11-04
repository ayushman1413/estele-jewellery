@extends('layouts.app')

@section('meta_title', 'Too Many Attempts | '.($siteSettings['site_name'] ?? 'Estele'))

@section('content')
  <div class="mx-auto w-full max-w-[520px] px-4 py-16 text-center md:py-24">
    <p class="mb-2 text-[13px] font-medium uppercase tracking-[0.5px] text-accent">429</p>
    <h1 class="mb-3 text-[22px] md:text-[28px]">Too Many Attempts</h1>
    <p class="mb-8 text-[14px] text-muted">You've made too many requests in a short time. Please wait a minute and try again.</p>
    <a class="inline-flex min-h-12 items-center justify-center gap-2 border border-accent bg-accent px-8 py-[13px] text-[13px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:border-accent-dark hover:bg-accent-dark" href="{{ url()->previous() !== url()->current() ? url()->previous() : route('home') }}">
      Go Back
    </a>
  </div>
@endsection
