@extends('layouts.app')

@section('meta_title', $oldJewelleryRequest->request_number.' | '.($siteSettings['site_name'] ?? 'Estele'))

@section('content')

  @php
    $steps = ['submitted', 'bidding_active', 'bidding_closed', 'bid_selected', 'wallet_credited', 'completed'];
    $currentIndex = array_search($oldJewelleryRequest->status, $steps, true);
    $isCancelled = $oldJewelleryRequest->status === 'cancelled';
    $isPolling = in_array($oldJewelleryRequest->status, ['pending', 'submitted', 'vendors_notified', 'bidding_active'], true);
    $stepLabels = [
        'submitted' => 'Submitted',
        'bidding_active' => 'Approved',
        'bidding_closed' => 'Closed',
        'bid_selected' => 'Selected',
        'wallet_credited' => 'Credited',
        'completed' => 'Completed',
    ];
  @endphp

  <nav class="mx-auto w-full max-w-wrapper px-3 md:px-4 flex flex-wrap items-center gap-1.5 py-4 text-[13px] text-muted" aria-label="Breadcrumb">
    <x-breadcrumb :items="[['label' => 'My Account', 'url' => route('account.index')], ['label' => 'Sell Your Jewellery', 'url' => route('account.sell-jewellery.landing')], ['label' => 'My Requests', 'url' => route('account.sell-jewellery.index')], ['label' => $oldJewelleryRequest->request_number]]" />
  </nav>

  <div class="mx-auto w-full max-w-2xl px-3 pb-10 md:px-4 md:pb-[60px]" data-poll-status data-request-number="{{ $oldJewelleryRequest->request_number }}" data-status-url="{{ route('account.sell-jewellery.status', $oldJewelleryRequest) }}" data-should-poll="{{ $isPolling ? '1' : '0' }}">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-[20px] uppercase tracking-[0.5px] md:text-[26px]">{{ $oldJewelleryRequest->request_number }}</h1>
      @include('account.sell-jewellery._status-badge', ['request' => $oldJewelleryRequest])
    </div>

    @if ($isCancelled)
      <div class="mb-6 rounded-lg border border-salebadge bg-red-50 p-4 text-[13px] text-salebadge">
        @if (($cancelReason ?? null) === 'no_active_vendors')
          This request was cancelled — no vendors are available to bid right now. Please try submitting again shortly, or contact support for help.
        @else
          This request was cancelled — no valid bids were received before the bidding window closed.
        @endif
      </div>
    @else
      <div class="mb-8 flex items-center justify-between gap-1" data-stepper>
        @foreach ($steps as $i => $step)
          <div class="flex flex-1 flex-col items-center text-center">
            <div class="mb-1 flex h-7 w-7 items-center justify-center rounded-full text-[12px] font-medium {{ $currentIndex !== false && $i <= $currentIndex ? 'bg-accent text-white' : 'bg-gray-100 text-muted' }}" data-step-index="{{ $i }}">
              {{ $i + 1 }}
            </div>
            <span class="text-[10px] uppercase tracking-[0.2px] text-muted">{{ $stepLabels[$step] }}</span>
          </div>
          @if (! $loop->last)
            <div class="mx-1 h-px flex-1 {{ $currentIndex !== false && $i < $currentIndex ? 'bg-accent' : 'bg-gray-100' }}"></div>
          @endif
        @endforeach
      </div>
    @endif

    @if ($oldJewelleryRequest->status === 'bidding_active' && $oldJewelleryRequest->bidding_end_at)
      <p class="mb-6 text-[13px] text-muted" data-bidding-countdown data-ends-at="{{ $oldJewelleryRequest->bidding_end_at->toIso8601String() }}">
        Bidding closes at {{ $oldJewelleryRequest->bidding_end_at->format('d M Y, h:i A') }}
      </p>
    @endif

    @php
      $statusPanel = match ($oldJewelleryRequest->status) {
          'pending', 'submitted' => [
              'tone' => 'border-amber-200 bg-amber-50',
              'icon' => 'bg-amber-100 text-amber-700',
              'title' => 'Waiting for approval',
              'text' => 'Our team is reviewing your submission. You will see an update here as soon as it is approved.',
              'path' => 'M12 8v4l2.5 2.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
          ],
          'vendors_notified', 'bidding_active' => [
              'tone' => 'border-green-200 bg-green-50',
              'icon' => 'bg-green-100 text-green-700',
              'title' => 'Approved',
              'text' => 'Your jewellery has been approved and shared with our vendors for valuation.',
              'path' => 'M5 12.5l4.5 4.5L19 7.5',
          ],
          'bidding_closed', 'bid_selected', 'wallet_pending' => [
              'tone' => 'border-amber-200 bg-amber-50',
              'icon' => 'bg-amber-100 text-amber-700',
              'title' => 'Finalising your offer',
              'text' => 'Bidding has closed. The best offer is being finalised and credited to your wallet.',
              'path' => 'M12 8v4l2.5 2.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
          ],
          'wallet_credited', 'completed' => [
              'tone' => 'border-green-200 bg-green-50',
              'icon' => 'bg-green-100 text-green-700',
              'title' => 'Credited to your wallet',
              'text' => 'Your offer has been credited. Use it on your next purchase.',
              'path' => 'M5 12.5l4.5 4.5L19 7.5',
          ],
          'wallet_expired' => [
              'tone' => 'border-red-200 bg-red-50',
              'icon' => 'bg-red-100 text-red-700',
              'title' => 'Wallet credit expired',
              'text' => 'The credit from this request was not used before it expired.',
              'path' => 'M6 6l12 12M18 6L6 18',
          ],
          default => [
              'tone' => 'border-red-200 bg-red-50',
              'icon' => 'bg-red-100 text-red-700',
              'title' => 'Cancelled',
              'text' => 'This request is no longer active.',
              'path' => 'M6 6l12 12M18 6L6 18',
          ],
      };
    @endphp

    <div class="mb-6 rounded-xl border {{ $statusPanel['tone'] }} p-4 md:p-5">
      <div class="flex items-start gap-3">
        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full {{ $statusPanel['icon'] }}">
          <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $statusPanel['path'] }}" /></svg>
        </span>
        <div class="min-w-0 flex-1">
          <p class="text-[15px] font-medium text-heading md:text-[16px]">{{ $statusPanel['title'] }}</p>
          <p class="mt-1 text-[13px] leading-snug text-muted">{{ $statusPanel['text'] }}</p>
        </div>
      </div>

      @if ($oldJewelleryRequest->description)
        <p class="mt-4 border-t border-line/60 pt-3 text-[13px] text-muted">{{ $oldJewelleryRequest->description }}</p>
      @endif

      <p class="mt-3 text-[12px] text-muted">Submitted {{ $oldJewelleryRequest->created_at->format('d M Y, h:i A') }}</p>
    </div>

    @if ($oldJewelleryRequest->final_amount)
      <div class="rounded-lg border border-line bg-pinksoft p-4">
        <div class="flex items-center justify-between text-[13px]">
          <span class="text-muted">Final offer</span>
          <span class="font-medium text-heading">₹{{ number_format((float) $oldJewelleryRequest->final_amount, 2) }}</span>
        </div>
        <div class="mt-1 flex items-center justify-between text-[13px]">
          <span class="text-muted">Processing deduction</span>
          <span class="text-heading">− ₹{{ number_format((float) $oldJewelleryRequest->deduction_amount, 2) }}</span>
        </div>
        <div class="mt-1 flex items-center justify-between border-t border-line pt-1 text-[13px]">
          <span class="font-medium text-heading">Credited to wallet</span>
          <span class="font-medium text-heading">₹{{ number_format((float) $oldJewelleryRequest->credited_amount, 2) }}</span>
        </div>
      </div>
    @endif
  </div>

  @push('scripts')
    <script>
      (function () {
        var el = document.querySelector('[data-poll-status]');
        if (!el || el.getAttribute('data-should-poll') !== '1') return;

        var countdownEl = document.querySelector('[data-bidding-countdown]');

        function tickCountdown() {
          if (!countdownEl) return;
          var endsAt = new Date(countdownEl.getAttribute('data-ends-at')).getTime();
          var remaining = endsAt - Date.now();
          if (remaining <= 0) return;
          var hrs = Math.floor(remaining / 3600000);
          var mins = Math.floor((remaining % 3600000) / 60000);
          countdownEl.textContent = 'Bidding closes in ' + hrs + 'h ' + mins + 'm';
        }

        function poll() {
          fetch(el.getAttribute('data-status-url'), { headers: { Accept: 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (body) {
              if (!body.success) return;
              var terminal = ['bidding_closed', 'bid_selected', 'wallet_pending', 'wallet_credited', 'wallet_expired', 'completed', 'cancelled'];
              if (terminal.indexOf(body.data.status) !== -1) {
                window.location.reload();
              }
            })
            .catch(function () {});
        }

        tickCountdown();
        setInterval(tickCountdown, 60000);
        setInterval(poll, 20000);
      })();
    </script>
  @endpush

@endsection
