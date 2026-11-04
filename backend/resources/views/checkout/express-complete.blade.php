@extends('layouts.app')

@section('meta_title', 'Confirming Your Order | '.($siteSettings['site_name'] ?? 'Estele'))

@section('content')

  @php $again = $attempt < 6; @endphp

  @if ($again)
    <meta http-equiv="refresh" content="3;url={{ route('checkout.express.complete', array_filter(['order_id' => $reference, 'attempt' => $attempt + 1])) }}">
  @endif

  <div class="mx-auto w-full max-w-[560px] px-3 py-14 text-center md:px-4 md:py-20">
    @if ($again)
      <span class="mx-auto mb-5 block h-10 w-10 animate-spin rounded-full border-[3px] border-line border-t-accent" aria-hidden="true"></span>
      <h1 class="mb-2 text-[22px] md:text-[26px]">Confirming your order…</h1>
      <p class="text-[14px] text-muted">Payment received. We are just recording your order — this takes a few seconds.</p>
    @else
      <div class="mx-auto mb-5 grid h-14 w-14 place-items-center rounded-full bg-pinksoft text-accent">
        <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
      </div>
      <h1 class="mb-2 text-[22px] md:text-[26px]">Thank you!</h1>
      <p class="mb-8 text-[14px] text-muted">Your payment went through. The order will appear in your account shortly.</p>
      <div class="flex flex-wrap items-center justify-center gap-3">
        @auth
          <a class="inline-flex items-center justify-center border border-accent bg-accent px-8 py-[13px] text-[13px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:bg-accent-dark" href="{{ route('account.index') }}">My Orders</a>
        @endauth
        <a class="inline-flex items-center justify-center border border-line-strong bg-white px-8 py-[13px] text-[13px] font-medium uppercase tracking-[0.5px] text-heading transition-colors hover:border-heading" href="{{ route('home') }}">Continue Shopping</a>
      </div>
    @endif
  </div>

@endsection
