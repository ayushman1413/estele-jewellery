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
        'bidding_active' => 'Bidding Active',
        'bidding_closed' => 'Closed',
        'bid_selected' => 'Selected',
        'wallet_credited' => 'Credited',
        'completed' => 'Completed',
    ];
  @endphp

  <nav class="mx-auto w-full max-w-wrapper px-3 md:px-4 flex flex-wrap items-center gap-1.5 py-4 text-[13px] text-muted" aria-label="Breadcrumb">
    <x-breadcrumb :items="[['label' => 'My Account', 'url' => route('account.index')], ['label' => 'Sell Your Jewellery', 'url' => route('account.sell-jewellery.landing')], ['label' => 'My Requests', 'url' => route('account.sell-jewellery.index')], ['label' => $oldJewelleryRequest->request_number]]" />
  </nav>

  <div class="mx-auto w-full max-w-2xl px-3 pb-10 md:px-4 md:pb-[60px]" data-poll-status data-request-number="{{ $oldJewelleryRequest->request_number }}" data-status-url="{{ url('/api/v1/old-jewellery/requests/'.$oldJewelleryRequest->request_number.'/status') }}" data-should-poll="{{ $isPolling ? '1' : '0' }}">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-[20px] uppercase tracking-[0.5px] md:text-[26px]">{{ $oldJewelleryRequest->request_number }}</h1>
      @include('account.sell-jewellery._status-badge', ['request' => $oldJewelleryRequest])
    </div>

    @if ($isCancelled)
      <div class="mb-6 rounded-lg border border-salebadge bg-red-50 p-4 text-[13px] text-salebadge">
        This request was cancelled — no valid bids were received before the bidding window closed.
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

    <div class="mb-6 rounded-lg border border-line p-4">
      @if ($oldJewelleryRequest->getFirstMediaUrl('image', 'thumb'))
        <img class="mb-4 max-h-64 rounded-lg border border-line" src="{{ $oldJewelleryRequest->getFirstMediaUrl('image', 'thumb') }}" alt="Submitted jewellery">
      @endif

      @if ($oldJewelleryRequest->description)
        <p class="mb-3 text-[13px] text-muted">{{ $oldJewelleryRequest->description }}</p>
      @endif

      <p class="text-[12px] text-muted">Submitted {{ $oldJewelleryRequest->created_at->format('d M Y, h:i A') }}</p>
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
