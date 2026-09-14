{{--
  Shared order line-items/totals/shipping-address card — used by both the
  guest checkout confirmation page and the logged-in order detail page
  (account.orders.show), so the two don't drift out of sync.
--}}
@props(['order'])

<div class="rounded-lg border border-line p-5 text-left">
  <h2 class="mb-4 text-[13px] font-medium uppercase tracking-[0.3px]">Order Details</h2>
  <div class="divide-y divide-line">
    @foreach($order->items as $item)
      <div class="flex items-center justify-between gap-3 py-2.5 text-[13px]">
        <span class="text-heading">{{ $item->product_title }} &times; {{ $item->quantity }}</span>
        <span class="shrink-0 font-medium text-price">₹{{ number_format($item->subtotal, 0) }}</span>
      </div>
    @endforeach
  </div>
  @if($order->discount_amount > 0)
    <div class="flex items-center justify-between py-2.5 text-[13px] text-[#1a7d3f]">
      <span>Discount ({{ $order->coupon_code }})</span>
      <span>&minus;₹{{ number_format($order->discount_amount, 0) }}</span>
    </div>
  @endif
  <div class="flex items-center justify-between py-2.5 text-[13px]">
    <span class="text-muted">Shipping</span>
    <span>{{ $order->shipping_fee > 0 ? '₹'.number_format($order->shipping_fee, 0) : 'Free' }}</span>
  </div>
  <div class="flex items-center justify-between border-t border-line pt-3 text-[15px]">
    <span class="font-medium text-heading">Total</span>
    <span class="font-medium text-price">₹{{ number_format($order->total, 0) }}</span>
  </div>
  <p class="mt-4 text-[12px] text-muted">
    Payment method: {{ match ($order->payment_method) { 'razorpay' => 'Online Payment (Razorpay)', 'fastrr' => 'Shiprocket Checkout', default => 'Cash on Delivery' } }}
    @if($order->payment_method === 'razorpay' && $order->payment_reference)
      &middot; Ref: {{ $order->payment_reference }}
    @endif
  </p>
  <p class="mt-3 text-[13px] text-muted">
    Shipping to {{ $order->shipping_address_line1 }}{{ $order->shipping_address_line2 ? ', '.$order->shipping_address_line2 : '' }},
    {{ $order->shipping_city }}, {{ $order->shipping_state }} {{ $order->shipping_postal_code }}, {{ $order->shipping_country }}
  </p>
</div>
