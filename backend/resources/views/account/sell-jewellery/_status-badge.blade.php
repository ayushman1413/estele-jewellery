@php
  $statusClasses = [
      'pending' => 'bg-gray-100 text-gray-700',
      'submitted' => 'bg-gray-100 text-gray-700',
      'vendors_notified' => 'bg-gray-100 text-gray-700',
      'bidding_active' => 'bg-blue-100 text-blue-700',
      'bidding_closed' => 'bg-amber-100 text-amber-700',
      'bid_selected' => 'bg-amber-100 text-amber-700',
      'wallet_pending' => 'bg-amber-100 text-amber-700',
      'wallet_credited' => 'bg-green-100 text-green-700',
      'wallet_expired' => 'bg-red-100 text-red-700',
      'completed' => 'bg-emerald-100 text-emerald-800',
      'cancelled' => 'bg-red-100 text-red-800',
  ];
  $statusLabels = [
      'pending' => 'Pending',
      'submitted' => 'Submitted',
      'vendors_notified' => 'Vendors Notified',
      'bidding_active' => 'Bidding Active',
      'bidding_closed' => 'Bidding Closed',
      'bid_selected' => 'Bid Selected',
      'wallet_pending' => 'Wallet Pending',
      'wallet_credited' => 'Wallet Credited',
      'wallet_expired' => 'Wallet Expired',
      'completed' => 'Completed',
      'cancelled' => 'Cancelled',
  ];
  $badgeClass = $statusClasses[$request->status] ?? 'bg-gray-100 text-gray-700';
  $badgeLabel = $statusLabels[$request->status] ?? $request->status;
@endphp

@if (($asCard ?? false))
  <a href="{{ route('account.sell-jewellery.show', $request) }}" class="block rounded-lg border border-line p-4 transition-colors hover:border-accent">
    <div class="mb-2 flex items-center justify-between gap-2">
      <span class="text-[13px] font-medium uppercase tracking-[0.3px] text-heading">{{ $request->request_number }}</span>
      <span class="rounded-full px-2.5 py-0.5 text-[10px] font-medium uppercase tracking-[0.3px] {{ $badgeClass }}">{{ $badgeLabel }}</span>
    </div>
    <p class="text-[12px] text-muted">Submitted {{ $request->created_at->format('d M Y, h:i A') }}</p>
    @if ($request->final_amount)
      <p class="mt-2 text-[13px] font-medium text-heading">Final offer: ₹{{ number_format((float) $request->final_amount, 2) }}</p>
    @endif
  </a>
@else
  <span class="rounded-full px-2.5 py-0.5 text-[10px] font-medium uppercase tracking-[0.3px] {{ $badgeClass }}">{{ $badgeLabel }}</span>
@endif
