@extends('layouts.app')

@section('meta_title', 'My Wallet | '.($siteSettings['site_name'] ?? 'Estele'))

@section('content')

  <nav class="mx-auto w-full max-w-wrapper px-3 md:px-4 flex flex-wrap items-center gap-1.5 py-4 text-[13px] text-muted" aria-label="Breadcrumb">
    <x-breadcrumb :items="[['label' => 'My Account', 'url' => route('account.index')], ['label' => 'Sell Your Jewellery', 'url' => route('account.sell-jewellery.landing')], ['label' => 'Wallet']]" />
  </nav>

  <div class="mx-auto w-full max-w-wrapper px-3 pb-10 md:px-4 md:pb-[60px]">
    <h1 class="mb-6 text-[20px] uppercase tracking-[0.5px] md:text-[26px]">My Wallet</h1>

    <div class="mb-8 rounded-lg border border-line bg-pinksoft p-6">
      <p class="text-[12px] uppercase tracking-[0.3px] text-muted">Wallet Balance</p>
      <p class="mt-1 text-[28px] font-medium text-heading">₹{{ number_format((float) $balance, 2) }}</p>
    </div>

    <h2 class="mb-4 text-[16px] uppercase tracking-[0.4px] text-heading">Old Jewellery Credits</h2>
    @if ($credits->isEmpty())
      <p class="mb-8 text-[13px] text-muted">No jewellery-sale credits yet.</p>
    @else
      <div class="mb-8 grid grid-cols-1 gap-4 md:grid-cols-2">
        @foreach ($credits as $credit)
          @php
            $daysLeft = $credit->expires_at ? (int) floor(now()->diffInDays($credit->expires_at, false)) : null;
            $expiryClass = $daysLeft !== null && $daysLeft <= 1 ? 'text-red-700' : ($daysLeft !== null && $daysLeft <= 3 ? 'text-amber-700' : 'text-muted');
            $percentUsed = $credit->credited_amount > 0 ? round((1 - ((float) $credit->remaining_amount / (float) $credit->credited_amount)) * 100) : 0;
          @endphp
          <div class="rounded-lg border border-line p-4">
            <div class="mb-2 flex items-center justify-between gap-2">
              @if ($credit->request)
                <a class="text-[13px] font-medium uppercase tracking-[0.3px] text-heading underline hover:text-accent" href="{{ route('account.sell-jewellery.show', $credit->request) }}">{{ $credit->request->request_number }}</a>
              @else
                <span class="text-[13px] font-medium uppercase tracking-[0.3px] text-heading">Credit</span>
              @endif
              <span class="text-[12px] font-medium {{ $expiryClass }}">
                @if ($credit->status === 'expired') Expired @elseif ($daysLeft !== null) {{ max($daysLeft, 0) }}d left @endif
              </span>
            </div>
            <div class="mb-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
              <div class="h-full bg-accent" style="width: {{ min(max($percentUsed, 0), 100) }}%"></div>
            </div>
            <p class="text-[13px] text-muted">₹{{ number_format((float) $credit->remaining_amount, 2) }} remaining of ₹{{ number_format((float) $credit->credited_amount, 2) }}</p>
          </div>
        @endforeach
      </div>
      <div class="mb-8">{{ $credits->links() }}</div>
    @endif

    <h2 class="mb-4 text-[16px] uppercase tracking-[0.4px] text-heading">Transactions</h2>
    @if ($transactions->isEmpty())
      <p class="text-[13px] text-muted">No transactions yet.</p>
    @else
      <div class="overflow-x-auto">
        <table class="w-full text-[13px]">
          <thead>
            <tr class="border-b border-line text-left text-[11px] uppercase tracking-[0.3px] text-muted">
              <th class="py-2 pr-4">Date</th>
              <th class="py-2 pr-4">Type</th>
              <th class="py-2 pr-4">Amount</th>
              <th class="py-2 pr-4">Balance After</th>
              <th class="py-2">Reason</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($transactions as $txn)
              <tr class="border-b border-line">
                <td class="py-2 pr-4 text-muted">{{ $txn->created_at->format('d M Y, h:i A') }}</td>
                <td class="py-2 pr-4 {{ $txn->type === 'credit' ? 'text-green-700' : 'text-salebadge' }}">{{ ucfirst($txn->type) }}</td>
                <td class="py-2 pr-4">₹{{ number_format((float) $txn->amount, 2) }}</td>
                <td class="py-2 pr-4">₹{{ number_format((float) $txn->balance_after, 2) }}</td>
                <td class="py-2 text-muted">{{ $txn->reason }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="mt-4">{{ $transactions->links() }}</div>
    @endif
  </div>

@endsection
