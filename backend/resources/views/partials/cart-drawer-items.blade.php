@if($items->isEmpty())
  <div class="flex flex-1 flex-col items-center justify-center gap-4 px-6 py-16 text-center">
    <p class="text-[14px] text-muted">Your cart is empty.</p>
    <a class="inline-flex items-center justify-center gap-2 border border-accent bg-accent px-6 py-3 text-[12px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:border-accent-dark hover:bg-accent-dark" href="{{ route('home') }}" data-cart-close>
      Continue Shopping
    </a>
  </div>
@else
  <div class="flex-1 divide-y divide-line overflow-y-auto px-5">
    @foreach($items as $item)
      <div class="flex gap-3 py-4" data-cart-drawer-item="{{ $item->id }}">
        <a class="block aspect-square w-16 shrink-0 overflow-hidden rounded bg-placeholder" href="{{ route('products.show', $item->product) }}">
          @if($item->product->hasMedia('gallery'))
            <img class="h-full w-full object-cover" src="{{ $item->product->getFirstMediaUrl('gallery', 'card') }}" alt="{{ $item->product->title }}" width="128" height="128">
          @endif
        </a>
        <div class="flex flex-1 flex-col justify-between">
          <div>
            <a class="text-[13px] leading-snug text-heading transition-colors hover:text-accent" href="{{ route('products.show', $item->product) }}">{{ $item->product->title }}</a>
            @if($item->variant)
              <p class="mt-0.5 text-[11.5px] text-muted">{{ collect($item->variant->attributes ?? [])->map(fn($v, $k) => "{$k}: {$v}")->implode(', ') }}</p>
            @endif
            <p class="mt-1 flex items-center gap-1.5 text-[13px]">
              <span class="font-medium text-price">₹{{ number_format($item->unitPrice(), 0) }}</span>
              @if($item->product->compare_at_price)
                <span class="text-[11.5px] text-muted line-through">₹{{ number_format($item->product->compare_at_price, 0) }}</span>
              @endif
            </p>
          </div>
          <div class="flex items-center justify-between">
            <div class="inline-flex items-center border border-line-strong" data-cart-qty-stepper data-item-id="{{ $item->id }}" data-max="{{ $item->availableStock() }}">
              <button class="grid h-10 w-10 place-items-center text-[15px] text-heading transition-colors hover:text-accent" type="button" data-cart-qty-decrement aria-label="Decrease quantity">&minus;</button>
              <span class="grid w-8 place-items-center text-[13px]" data-cart-qty-value>{{ $item->quantity }}</span>
              <button class="grid h-10 w-10 place-items-center text-[15px] text-heading transition-colors hover:text-accent" type="button" data-cart-qty-increment aria-label="Increase quantity">&plus;</button>
            </div>
            <button class="text-[11px] uppercase tracking-[0.3px] text-muted transition-colors hover:text-salebadge" type="button" data-cart-remove data-item-id="{{ $item->id }}">
              Remove
            </button>
          </div>
        </div>
      </div>
    @endforeach
  </div>

  <div class="border-t border-line px-5 py-4">
    @if(($activeOffers ?? []) !== [])
      <div class="mb-3 rounded border border-dashed border-line-strong p-2.5">
        <p class="mb-1.5 text-[10.5px] font-medium uppercase tracking-[0.5px] text-heading">Available Offers</p>
        <ul class="space-y-1 text-[11px] text-muted">
          @foreach($activeOffers as $offer)
            <li class="flex gap-1"><span>&bull;</span><span>{{ $offer }}</span></li>
          @endforeach
        </ul>
      </div>
    @endif

    <div class="mb-3" data-cart-coupon-box>
      <div class="mb-1 flex items-center justify-between">
        <span class="text-[11.5px] font-medium text-heading">Coupon code</span>
        <button class="text-[11px] text-muted underline transition-colors hover:text-accent" type="button" data-coupons-modal-open>View all coupons</button>
      </div>
      @if($couponCode ?? null)
        <div class="mb-3 flex items-center justify-between border border-line-strong bg-white px-3 py-2 text-[12.5px]">
          <span>Applied: <strong>{{ $couponCode }}</strong></span>
          <button class="text-muted underline transition-colors hover:text-[#eb001b]" type="button" data-coupon-remove>Remove</button>
        </div>
      @else
        <div class="mb-1 flex gap-1.5">
          <input class="w-full flex-1 border border-line-strong bg-white px-3 py-2.5 text-[12.5px] outline-none transition-colors placeholder:text-muted focus:border-heading" type="text" placeholder="Coupon code" data-coupon-input>
          <button class="inline-flex items-center justify-center border border-accent bg-accent px-4 py-2.5 text-[11px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:border-accent-dark hover:bg-accent-dark" type="button" data-coupon-apply>Apply</button>
        </div>
        <p class="hidden text-[11.5px] text-salebadge" data-coupon-error></p>
      @endif
    </div>
    @if(($discount ?? 0) > 0)
      <div class="mb-2 flex items-center justify-between text-[13px] text-[#1a7d3f]">
        <span>Discount ({{ $couponCode }})</span>
        <span data-cart-discount>&minus;₹{{ number_format($discount, 0) }}</span>
      </div>
    @endif
    <div class="mb-4 flex items-center justify-between text-[15px]">
      <span class="font-medium uppercase tracking-[0.3px]">Subtotal</span>
      <span class="font-medium text-price" data-cart-subtotal>₹{{ number_format($subtotal - ($discount ?? 0), 0) }}</span>
    </div>
    <div class="flex flex-col gap-2">
      <a class="flex items-center justify-center gap-2 border border-line-strong px-6 py-3 text-[12px] font-medium uppercase tracking-[0.5px] text-heading transition-colors hover:border-accent hover:text-accent" href="{{ route('cart.index') }}">
        View Cart
      </a>
      <a class="flex items-center justify-center gap-2 border border-accent bg-accent px-6 py-3 text-[12px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:border-accent-dark hover:bg-accent-dark" href="{{ route('checkout.index') }}">
        Checkout
      </a>
    </div>
  </div>
@endif
