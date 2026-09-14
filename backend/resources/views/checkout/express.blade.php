@extends('layouts.app')

@section('meta_title', 'Secure Checkout | '.($siteSettings['site_name'] ?? 'Estele'))

@section('content')

  <div class="mx-auto w-full max-w-[560px] px-3 py-8 md:px-4 md:py-12" data-express-checkout data-token="{{ $token }}" data-fallback-url="{{ $fallbackUrl }}">
    <div class="mb-5 flex items-center gap-3 rounded-xl border border-line bg-paper p-3">
      <img class="h-16 w-16 shrink-0 rounded-lg border border-line object-cover" src="{{ $product->getFirstMediaUrl('gallery', 'card') }}" alt="{{ $product->title }}">
      <div class="min-w-0 flex-1">
        <p class="truncate text-[14px] font-medium text-heading">{{ $product->title }}</p>
        @if ($variant)
          <p class="text-[12px] text-muted">{{ collect($variant->attributes ?? [])->map(fn ($v, $k) => "{$k}: {$v}")->implode(', ') ?: $variant->sku }}</p>
        @endif
        <p class="mt-0.5 text-[13px] text-muted">Qty {{ $quantity }} · <span class="font-medium text-heading">₹{{ number_format($unitPrice * $quantity, 0) }}</span></p>
      </div>
    </div>

    <div class="mb-6 rounded-xl border border-line bg-ivory p-3 text-[12px] text-muted">
      <span class="font-medium uppercase tracking-[0.3px] text-heading">Deliver to</span>
      <p class="mt-1 leading-snug">{{ $address->line1 }}{{ $address->line2 ? ', '.$address->line2 : '' }}, {{ $address->city }}, {{ $address->state }} {{ $address->postal_code }}</p>
      <a class="mt-1 inline-block underline hover:text-accent" href="{{ route('account.addresses') }}">Change</a>
    </div>

    <div class="rounded-xl border border-line p-6 text-center" data-express-status>
      <span class="mx-auto mb-3 block h-9 w-9 animate-spin rounded-full border-[3px] border-line border-t-accent" aria-hidden="true"></span>
      <p class="text-[14px] font-medium text-heading">Opening secure payment…</p>
      <p class="mt-1 text-[12px] text-muted">UPI, cards, net banking and cash on delivery.</p>
    </div>

    <div class="mt-4 hidden text-center" data-express-retry>
      <button class="inline-flex w-full items-center justify-center border border-accent bg-accent px-6 py-3 text-[13px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:bg-accent-dark" type="button" data-express-open>
        Pay now
      </button>
      <a class="mt-3 inline-block text-[12px] text-muted underline hover:text-accent" href="{{ $fallbackUrl }}">Use regular checkout instead</a>
    </div>
  </div>

  @push('scripts')
    <script src="{{ $scriptUrl }}"></script>
    <script>
      (function () {
        var root = document.querySelector('[data-express-checkout]');
        if (!root) return;

        var token = root.getAttribute('data-token');
        var fallbackUrl = root.getAttribute('data-fallback-url');
        var status = root.querySelector('[data-express-status]');
        var retry = root.querySelector('[data-express-retry]');
        var opened = false;

        function open(event) {
          if (!window.HeadlessCheckout || typeof window.HeadlessCheckout.addToCart !== 'function') {
            window.location.href = fallbackUrl;
            return;
          }

          opened = true;
          window.HeadlessCheckout.addToCart(event || new Event('click'), token, { fallbackUrl: fallbackUrl }, function () {
            // Iframe closed without completing: let the shopper retry or
            // switch to the regular checkout.
            status.querySelector('p').textContent = 'Payment not completed.';
            retry.classList.remove('hidden');
          });
        }

        root.querySelector('[data-express-open]').addEventListener('click', open);

        // Some browsers only allow the iframe to grab focus after a user
        // gesture; try automatically, then show the button if nothing
        // happened within a moment.
        setTimeout(function () { open(); }, 300);
        setTimeout(function () {
          if (!document.querySelector('iframe[src*="fastrr"], iframe[src*="pickrr"], iframe[src*="shiprocket"]')) {
            retry.classList.remove('hidden');
            status.querySelector('p').textContent = 'Tap below to open secure payment.';
          }
        }, 2500);
      })();
    </script>
  @endpush

@endsection
