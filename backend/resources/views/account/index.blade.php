@extends('layouts.app')

@section('meta_title', 'My Account | '.($siteSettings['site_name'] ?? 'Estele'))
@section('meta_description', 'View your order history and manage your account details.')

@section('content')

  <nav class="mx-auto w-full max-w-wrapper px-3 md:px-4 flex flex-wrap items-center gap-1.5 py-4 text-[13px] text-muted" aria-label="Breadcrumb">
    <x-breadcrumb :items="[['label' => 'My Account']]" />
  </nav>

  <div class="mx-auto w-full max-w-wrapper px-3 pb-10 md:px-4 md:pb-[60px]">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-[20px] uppercase tracking-[0.5px] md:text-[26px]">My Account</h1>
      <form action="{{ route('logout') }}" method="post">
        @csrf
        <button class="inline-flex items-center justify-center gap-2 border border-accent bg-transparent px-6 py-2.5 text-[12px] font-medium uppercase tracking-[0.5px] text-accent transition-colors hover:bg-accent hover:text-white" type="submit">
          Logout
        </button>
      </form>
    </div>

    {{-- md:grid-cols-[200px_1fr_320px] is an arbitrary value with no live Tailwind
         build to compile it here (this backend has no build of its own — see the
         "no live Tailwind build" note in home/index.blade.php); inline style
         sidesteps that the same way the hero banner section does. --}}
    <style>@media (min-width: 768px) { .account-layout-grid { grid-template-columns: 200px 1fr 320px; } }</style>
    <div class="account-layout-grid grid grid-cols-1 gap-8 md:gap-[34px]">
      {{-- Account nav — Overview (this page), Order History (below), Addresses,
           Profile/Password (both further right, already on this page). All one
           Blade view family; only Addresses is a separate route. --}}
      <nav class="hidden md:block" aria-label="Account">
        <ul class="space-y-1 text-[13px]">
          <li><a class="block rounded-md bg-pinksoft px-3 py-2.5 font-medium text-accent" href="{{ route('account.index') }}">Overview</a></li>
          <li><a class="block rounded-md px-3 py-2.5 text-heading transition-colors hover:bg-pinksoft" href="#order-history">Order History</a></li>
          <li><a class="block rounded-md px-3 py-2.5 text-heading transition-colors hover:bg-pinksoft" href="{{ route('account.rewards.index') }}">Rewards & Wallet</a></li>
          <li><a class="block rounded-md px-3 py-2.5 text-heading transition-colors hover:bg-pinksoft" href="{{ route('account.sell-jewellery.landing') }}">Sell Your Jewellery</a></li>
          <li><a class="block rounded-md px-3 py-2.5 text-heading transition-colors hover:bg-pinksoft" href="{{ route('account.addresses') }}">Addresses</a></li>
          <li><a class="block rounded-md px-3 py-2.5 text-heading transition-colors hover:bg-pinksoft" href="#profile-details">Profile</a></li>
        </ul>
      </nav>

      <section id="order-history">
        <div class="mb-4 flex items-center justify-between gap-3">
          <h2 class="text-[14px] font-medium uppercase tracking-[0.4px]">Order History</h2>
          <a class="text-[12px] font-medium text-heading underline hover:text-accent md:hidden" href="{{ route('account.addresses') }}">My Addresses</a>
          <a class="text-[12px] font-medium text-heading underline hover:text-accent md:hidden" href="{{ route('account.rewards.index') }}">Rewards & Wallet</a>
          <a class="text-[12px] font-medium text-heading underline hover:text-accent md:hidden" href="{{ route('account.sell-jewellery.landing') }}">Sell Your Jewellery</a>
        </div>

        @if($orders->isEmpty())
          <p class="rounded-lg border border-line bg-pinksoft px-4 py-4 text-[13px] text-heading">
            You haven't placed any orders yet. <a class="underline hover:text-accent" href="{{ route('home') }}">Start shopping</a>.
          </p>
        @else
          <div class="space-y-4">
            @foreach($orders as $order)
              <a class="block rounded-lg border border-line p-4 transition-colors hover:border-heading" href="{{ route('account.orders.show', $order) }}">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                  <span class="text-[13px] font-medium text-heading">Order #{{ $order->order_number }}</span>
                  <span class="inline-block rounded-full bg-pinksoft px-3 py-1 text-[11px] font-medium uppercase tracking-[0.3px] text-accent">{{ ucfirst($order->status) }}</span>
                </div>
                <p class="mb-1 text-[12px] text-muted">{{ $order->created_at->format('d M Y') }} &middot; {{ $order->items->count() }} item{{ $order->items->count() === 1 ? '' : 's' }}</p>
                <p class="text-[14px] font-medium text-price">₹{{ number_format($order->total, 0) }}</p>
              </a>
            @endforeach
          </div>
          <div class="mt-6">
            {{ $orders->links() }}
          </div>
        @endif
      </section>

      <aside class="space-y-6">
        <details class="marker-pm rounded-lg border border-line p-4" id="profile-details" open>
          <summary class="cursor-pointer text-[13px] font-medium uppercase tracking-[0.4px] text-heading">Profile Details</summary>
          <form class="mt-4" action="{{ route('account.profile') }}" method="post">
            @csrf
            @method('PATCH')
            <label class="mb-1.5 block text-[13px] font-medium text-heading" for="name">Full name</label>
            <input class="w-full border border-line-strong bg-white px-4 py-3 text-[14px] outline-none transition-colors placeholder:text-muted focus:border-heading mb-3.5" id="name" name="name" type="text" value="{{ old('name', auth()->user()->name) }}" required>
            @error('name') <p class="mb-3.5 -mt-2 text-[12px] text-salebadge">{{ $message }}</p> @enderror

            <label class="mb-1.5 block text-[13px] font-medium text-heading" for="phone">Mobile number</label>
            <input class="w-full border border-line-strong bg-pinksoft/40 px-4 py-3 text-[14px] text-muted mb-3.5" id="phone" type="text" value="{{ auth()->user()->phone }}" disabled>

            <button class="inline-flex w-full items-center justify-center gap-2 border border-accent bg-accent px-6 py-3 text-[12px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:border-accent-dark hover:bg-accent-dark" type="submit">
              Save Changes
            </button>
          </form>
        </details>
      </aside>
    </div>
  </div>

@endsection
