@extends('layouts.app')

@section('meta_title', 'Sell Your Jewellery | '.($siteSettings['site_name'] ?? 'Estele'))

@section('content')

  <nav class="mx-auto w-full max-w-wrapper px-3 md:px-4 flex flex-wrap items-center gap-1.5 py-4 text-[13px] text-muted" aria-label="Breadcrumb">
    <x-breadcrumb :items="[['label' => 'My Account', 'url' => route('account.index')], ['label' => 'Sell Your Jewellery']]" />
  </nav>

  <div class="mx-auto w-full max-w-wrapper px-3 pb-10 md:px-4 md:pb-[60px]">
    <div class="mb-8 rounded-lg border border-line bg-pinksoft p-6 md:p-10">
      <h1 class="mb-3 text-[22px] uppercase tracking-[0.5px] md:text-[30px]">Sell Your Old Jewellery</h1>
      <p class="max-w-2xl text-[14px] text-muted">
        Get a fair, competitive offer for your old gold and jewellery. Upload a
        photo and a short video, our trusted vendors place bids within a few
        hours, and once you accept, the amount is credited straight to your
        Estele wallet.
      </p>
      <a class="mt-6 inline-flex items-center justify-center gap-2 border border-accent bg-accent px-6 py-2.5 text-[12px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:border-accent-dark hover:bg-accent-dark" href="{{ route('account.sell-jewellery.create') }}">
        Submit Your Jewellery
      </a>
    </div>

    <h2 class="mb-4 text-[16px] uppercase tracking-[0.4px] text-heading">How It Works</h2>
    <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <div class="rounded-lg border border-line p-4">
        <div class="mb-2 text-[24px] font-medium text-accent">1</div>
        <p class="text-[13px] font-medium uppercase tracking-[0.3px] text-heading">Submit Details</p>
        <p class="mt-1 text-[13px] text-muted">Upload a photo and a short video of your jewellery, with any details you'd like to share.</p>
      </div>
      <div class="rounded-lg border border-line p-4">
        <div class="mb-2 text-[24px] font-medium text-accent">2</div>
        <p class="text-[13px] font-medium uppercase tracking-[0.3px] text-heading">Vendors Bid</p>
        <p class="mt-1 text-[13px] text-muted">Our verified vendors review your submission and place bids within a 3-hour window.</p>
      </div>
      <div class="rounded-lg border border-line p-4">
        <div class="mb-2 text-[24px] font-medium text-accent">3</div>
        <p class="text-[13px] font-medium uppercase tracking-[0.3px] text-heading">Highest Bid Wins</p>
        <p class="mt-1 text-[13px] text-muted">The best offer is automatically selected once bidding closes.</p>
      </div>
      <div class="rounded-lg border border-line p-4">
        <div class="mb-2 text-[24px] font-medium text-accent">4</div>
        <p class="text-[13px] font-medium uppercase tracking-[0.3px] text-heading">Wallet Credited</p>
        <p class="mt-1 text-[13px] text-muted">The amount (minus a small processing deduction) is credited to your wallet, valid for 10 days.</p>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-4 text-[13px]">
      <a class="font-medium text-heading underline hover:text-accent" href="{{ route('account.sell-jewellery.index') }}">View My Requests</a>
      <a class="font-medium text-heading underline hover:text-accent" href="{{ route('account.sell-jewellery.wallet') }}">My Wallet &amp; Credits</a>
    </div>
  </div>

@endsection
