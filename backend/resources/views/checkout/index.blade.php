@extends('layouts.app')

@section('meta_title', 'Checkout | '.($siteSettings['site_name'] ?? 'Estele'))

@section('content')

  <nav class="mx-auto w-full max-w-wrapper px-3 md:px-4 flex flex-wrap items-center gap-1.5 py-4 text-[13px] text-muted" aria-label="Breadcrumb">
    <x-breadcrumb :items="[['label' => 'Cart', 'url' => route('cart.index')], ['label' => 'Checkout']]" />
  </nav>

  <div class="mx-auto w-full max-w-wrapper px-3 md:px-4 pb-10 md:pb-[60px]">
    <h1 class="text-[20px] uppercase tracking-[0.5px] md:text-[26px] mb-5">Checkout</h1>

    <div class="grid grid-cols-1 gap-6 md:grid-cols-[1fr_340px] md:gap-[34px]">
      <form action="{{ route('checkout.store') }}" method="post" class="[&_.field-set]:mb-7" data-loading-submit>
        @csrf

        <fieldset class="field-set">
          <legend class="mb-3.5 text-[14px] font-medium uppercase tracking-[0.5px]">Contact</legend>
          <label class="mb-1.5 block text-[13px] font-medium text-heading" for="customer_email">Email</label>
          <input class="w-full border border-line-strong bg-white px-4 py-3 text-base outline-none transition-colors placeholder:text-muted focus:border-heading mb-3.5" id="customer_email" name="customer_email" type="email" placeholder="you@example.com" value="{{ old('customer_email') }}" autocomplete="email" required>
          @error('customer_email') <p class="mb-3.5 -mt-2 text-[12px] text-salebadge">{{ $message }}</p> @enderror
          <label class="mb-1.5 block text-[13px] font-medium text-heading" for="customer_phone">Phone</label>
          <input class="w-full border border-line-strong bg-white px-4 py-3 text-base outline-none transition-colors placeholder:text-muted focus:border-heading mb-3.5" id="customer_phone" name="customer_phone" type="tel" placeholder="+91" value="{{ old('customer_phone') }}" inputmode="tel" autocomplete="tel" required>
          @error('customer_phone') <p class="mb-3.5 -mt-2 text-[12px] text-salebadge">{{ $message }}</p> @enderror
        </fieldset>

        <fieldset class="field-set">
          <legend class="mb-3.5 text-[14px] font-medium uppercase tracking-[0.5px]">Shipping Address</legend>
          <div class="mb-3.5 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label class="mb-1.5 block text-[13px] font-medium text-heading" for="customer_first_name">First name</label>
              <input class="w-full border border-line-strong bg-white px-4 py-3 text-base outline-none transition-colors placeholder:text-muted focus:border-heading" id="customer_first_name" name="customer_first_name" type="text" value="{{ old('customer_first_name') }}" autocomplete="given-name" required>
              @error('customer_first_name') <p class="mt-1 text-[12px] text-salebadge">{{ $message }}</p> @enderror
            </div>
            <div>
              <label class="mb-1.5 block text-[13px] font-medium text-heading" for="customer_last_name">Last name</label>
              <input class="w-full border border-line-strong bg-white px-4 py-3 text-base outline-none transition-colors placeholder:text-muted focus:border-heading" id="customer_last_name" name="customer_last_name" type="text" value="{{ old('customer_last_name') }}" autocomplete="family-name" required>
              @error('customer_last_name') <p class="mt-1 text-[12px] text-salebadge">{{ $message }}</p> @enderror
            </div>
          </div>
          <label class="mb-1.5 block text-[13px] font-medium text-heading" for="shipping_address_line1">Address</label>
          <input class="w-full border border-line-strong bg-white px-4 py-3 text-base outline-none transition-colors placeholder:text-muted focus:border-heading mb-3.5" id="shipping_address_line1" name="shipping_address_line1" type="text" value="{{ old('shipping_address_line1') }}" autocomplete="address-line1" required>
          @error('shipping_address_line1') <p class="mb-3.5 -mt-2 text-[12px] text-salebadge">{{ $message }}</p> @enderror
          <div class="mb-3.5 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label class="mb-1.5 block text-[13px] font-medium text-heading" for="shipping_city">City</label>
              <input class="w-full border border-line-strong bg-white px-4 py-3 text-base outline-none transition-colors placeholder:text-muted focus:border-heading" id="shipping_city" name="shipping_city" type="text" value="{{ old('shipping_city') }}" autocomplete="address-level2" required>
              @error('shipping_city') <p class="mt-1 text-[12px] text-salebadge">{{ $message }}</p> @enderror
            </div>
            <div>
              <label class="mb-1.5 block text-[13px] font-medium text-heading" for="shipping_postal_code">PIN code</label>
              <input class="w-full border border-line-strong bg-white px-4 py-3 text-base outline-none transition-colors placeholder:text-muted focus:border-heading" id="shipping_postal_code" name="shipping_postal_code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" value="{{ old('shipping_postal_code') }}" autocomplete="postal-code" required>
              @error('shipping_postal_code') <p class="mt-1 text-[12px] text-salebadge">{{ $message }}</p> @enderror
              <p id="shipping_postal_code_status" class="mt-1 text-[12px] text-muted"></p>
            </div>
          </div>
          <label class="mb-1.5 block text-[13px] font-medium text-heading" for="shipping_state">State</label>
          <select class="w-full border border-line-strong bg-white px-4 py-3 text-base outline-none transition-colors placeholder:text-muted focus:border-heading" id="shipping_state" name="shipping_state" required>
            <option value="" disabled {{ old('shipping_state') ? '' : 'selected' }}>Select state</option>
            @foreach([
              'Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar',
              'Chandigarh', 'Chhattisgarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Goa',
              'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jammu and Kashmir', 'Jharkhand', 'Karnataka',
              'Kerala', 'Ladakh', 'Lakshadweep', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya',
              'Mizoram', 'Nagaland', 'Odisha', 'Puducherry', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu',
              'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
            ] as $state)
              <option value="{{ $state }}" {{ old('shipping_state') === $state ? 'selected' : '' }}>{{ $state }}</option>
            @endforeach
          </select>
          @error('shipping_state') <p class="mt-1 text-[12px] text-salebadge">{{ $message }}</p> @enderror
        </fieldset>

        <fieldset class="field-set">
          <legend class="mb-3.5 text-[14px] font-medium uppercase tracking-[0.5px]">Payment</legend>
          <ul class="space-y-2.5">
            <li>
              <label class="flex items-center gap-2.5 border border-line-strong p-3.5 text-[14px] {{ $onlinePaymentEnabled ? 'cursor-pointer' : 'cursor-not-allowed opacity-50' }}">
                <input class="accent-accent" type="radio" name="payment_method" value="razorpay" {{ ! $onlinePaymentEnabled ? 'disabled' : '' }} {{ old('payment_method') === 'razorpay' ? 'checked' : '' }}> UPI / Card / Netbanking
              </label>
            </li>
            <li>
              <label class="flex cursor-pointer items-center gap-2.5 border border-line-strong p-3.5 text-[14px]">
                <input class="accent-accent" type="radio" name="payment_method" value="cod" {{ old('payment_method', 'cod') === 'cod' ? 'checked' : '' }}> Cash on Delivery
              </label>
            </li>
          </ul>
          @unless($onlinePaymentEnabled)
            <p class="mt-2 text-[12px] text-muted">Online payment is coming soon.</p>
          @endunless
        </fieldset>

        <div class="field-set">
          <label class="mb-1.5 block text-[13px] font-medium text-heading" for="order_note">Order note (optional)</label>
          <textarea class="w-full border border-line-strong bg-white px-4 py-3 text-base outline-none transition-colors placeholder:text-muted focus:border-heading" id="order_note" name="order_note" rows="3">{{ old('order_note') }}</textarea>
        </div>

        @auth
          @if((float) auth()->user()->wallet_balance > 0)
            <div class="mb-4 rounded-lg border border-line p-4">
              <label class="mb-1.5 block text-[13px] font-medium text-heading" for="wallet_amount">
                Use wallet balance (available: ₹{{ number_format((float) auth()->user()->wallet_balance, 2) }})
              </label>
              <input class="w-full border border-line-strong bg-white px-4 py-2.5 text-[13px]" id="wallet_amount" name="wallet_amount" type="number" min="0" step="0.01" max="{{ auth()->user()->wallet_balance }}" placeholder="0.00" value="{{ old('wallet_amount') }}">
              @error('wallet_amount') <p class="mt-1 text-[12px] text-salebadge">{{ $message }}</p> @enderror
            </div>
          @endif
        @endauth

        <button class="inline-flex items-center justify-center gap-2 border border-accent bg-accent px-8 py-[13px] text-[13px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:border-accent-dark hover:bg-accent-dark w-full" type="submit">Place Order</button>
      </form>

      <aside class="rounded bg-pinksoft p-6 md:sticky md:top-[100px]">
        <h2 class="mb-4.5 text-[15px] uppercase tracking-[0.5px]">Order Summary</h2>
        <div class="mb-4.5 divide-y divide-line-strong/40">
          @foreach($items as $item)
            <div class="flex items-center justify-between gap-3 py-2 text-[13px]">
              <span class="text-heading">{{ $item->product->title }} &times; {{ $item->quantity }}</span>
              <span class="shrink-0 font-medium">₹{{ number_format($item->unitPrice() * $item->quantity, 0) }}</span>
            </div>
          @endforeach
        </div>
        <dl class="mb-4.5 space-y-2 text-[14px]">
          <div class="flex justify-between"><dt class="text-muted">Subtotal</dt><dd>₹{{ number_format($subtotal, 0) }}</dd></div>
          @if($discount > 0)
            <div class="flex justify-between text-[#1a7d3f]"><dt>Discount ({{ $couponCode }})</dt><dd>&minus;₹{{ number_format($discount, 0) }}</dd></div>
          @endif
          <div class="flex justify-between"><dt class="text-muted">Shipping{{ ($shipping['estimated'] ?? false) ? ' (estimated)' : '' }}</dt><dd>{{ $shipping['fee'] > 0 ? '₹'.number_format($shipping['fee'], 0) : 'Free' }}</dd></div>
          <div class="flex justify-between border-t border-line-strong pt-3.5 text-[16px] font-medium"><dt class="text-heading">Total</dt><dd>₹{{ number_format($subtotal - $discount + $shipping['fee'], 0) }}</dd></div>
        </dl>
        @if($shipping['estimated'] ?? false)
          <p class="-mt-3 mb-4.5 text-[11.5px] text-muted">Final shipping cost is confirmed once you enter your delivery address below.</p>
        @endif

        @include('partials.offers-banner')

        <div class="mb-1">
          <div class="mb-1.5 flex items-center justify-between">
            <label class="text-[13px] font-medium text-heading" for="checkout-coupon">Coupon code</label>
            <button class="text-[12px] text-muted underline transition-colors hover:text-accent" type="button" data-coupons-modal-open>View all coupons</button>
          </div>
          @if($couponCode)
            <div class="flex items-center justify-between border border-line-strong bg-white px-4 py-3 text-[14px]">
              <span>Applied: <strong>{{ $couponCode }}</strong></span>
              <form action="{{ route('cart.coupon.remove') }}" method="post">
                @csrf
                @method('delete')
                <button class="text-[12px] text-muted underline transition-colors hover:text-[#eb001b]" type="submit">Remove</button>
              </form>
            </div>
          @else
            <form action="{{ route('cart.coupon.apply') }}" method="post" class="flex gap-2" data-loading-submit>
              @csrf
              <input class="w-full border border-line-strong bg-white px-4 py-3 text-base outline-none transition-colors placeholder:text-muted focus:border-heading flex-1" id="checkout-coupon" name="code" type="text" placeholder="Enter code" required>
              <button class="inline-flex items-center justify-center gap-2 border border-accent bg-accent px-5 py-3 text-[12px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:border-accent-dark hover:bg-accent-dark" type="submit">Apply</button>
            </form>
          @endif
        </div>
      </aside>
    </div>
  </div>

  @push('scripts')
    <script>
      (function () {
        var postalInput = document.getElementById('shipping_postal_code');
        var cityInput = document.getElementById('shipping_city');
        var stateSelect = document.getElementById('shipping_state');
        var statusEl = document.getElementById('shipping_postal_code_status');
        var lastLookedUp = null;

        function setStatus(text) {
          statusEl.textContent = text;
        }

        function lookup(pincode) {
          if (pincode === lastLookedUp) return;
          lastLookedUp = pincode;
          setStatus('Looking up city/state…');

          fetch('{{ url('/checkout/pincode') }}/' + pincode, { headers: { 'Accept': 'application/json' } })
            .then(function (res) {
              if (!res.ok) throw new Error('not found');
              return res.json();
            })
            .then(function (data) {
              if (data.city && !cityInput.value) cityInput.value = data.city;
              if (data.state) {
                var matched = Array.from(stateSelect.options).some(function (opt) {
                  if (opt.value.toLowerCase() === data.state.toLowerCase()) {
                    stateSelect.value = opt.value;
                    return true;
                  }
                  return false;
                });
                setStatus(matched ? 'City/state auto-filled from PIN code.' : '');
              }
            })
            .catch(function () {
              setStatus('Could not find this PIN code — please fill city/state manually.');
            });
        }

        postalInput.addEventListener('input', function () {
          var value = postalInput.value.trim();
          if (/^[0-9]{6}$/.test(value)) {
            lookup(value);
          } else {
            setStatus('');
            lastLookedUp = null;
          }
        });
      })();
    </script>

    <script>
      // Autosaves the checkout form to localStorage as the customer types, and
      // restores it on the next load — a refresh (or an accidental tab close)
      // shouldn't cost someone their whole address entry. Only fills fields
      // that are still blank, so it never overrides old() values Laravel has
      // already repopulated after a failed-validation redirect.
      (function () {
        var STORAGE_KEY = 'checkout_form_draft';
        var form = document.querySelector('form[action="{{ route('checkout.store') }}"]');
        if (!form) return;

        var fields = Array.prototype.filter.call(form.elements, function (el) {
          return el.name && el.name !== '_token';
        });

        var draft = {};
        try {
          draft = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
        } catch (e) {
          draft = {};
        }

        // Radios/checkboxes are restored per group, and only when nothing in
        // the group is already checked — Blade's old() already renders a
        // checked attribute after a failed-validation redirect, and that
        // server value must win over a possibly-stale draft.
        var restoredGroups = {};

        fields.forEach(function (el) {
          if (!(el.name in draft)) return;

          if (el.type === 'radio' || el.type === 'checkbox') {
            if (restoredGroups[el.name]) return;
            var groupAlreadyChecked = fields.some(function (other) {
              return other.name === el.name && other.checked;
            });
            if (groupAlreadyChecked) {
              restoredGroups[el.name] = true;
              return;
            }
            el.checked = el.value === draft[el.name];
          } else if (!el.value) {
            el.value = draft[el.name];
          }
        });

        function save() {
          var data = {};
          fields.forEach(function (el) {
            if (el.type === 'radio' || el.type === 'checkbox') {
              if (el.checked) data[el.name] = el.value;
            } else {
              data[el.name] = el.value;
            }
          });
          localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
        }

        form.addEventListener('input', save);
        form.addEventListener('change', save);
        form.addEventListener('submit', function () {
          localStorage.removeItem(STORAGE_KEY);
        });
      })();
    </script>
  @endpush
@endsection
