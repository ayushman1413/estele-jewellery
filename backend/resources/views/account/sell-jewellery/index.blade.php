@extends('layouts.app')

@section('meta_title', 'My Sell Requests | '.($siteSettings['site_name'] ?? 'Estele'))

@section('content')

  <nav class="mx-auto w-full max-w-wrapper px-3 md:px-4 flex flex-wrap items-center gap-1.5 py-4 text-[13px] text-muted" aria-label="Breadcrumb">
    <x-breadcrumb :items="[['label' => 'My Account', 'url' => route('account.index')], ['label' => 'Sell Your Jewellery', 'url' => route('account.sell-jewellery.landing')], ['label' => 'My Requests']]" />
  </nav>

  <div class="mx-auto w-full max-w-wrapper px-3 pb-10 md:px-4 md:pb-[60px]">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-[20px] uppercase tracking-[0.5px] md:text-[26px]">My Sell Requests</h1>
      <a class="text-[12px] font-medium uppercase tracking-[0.3px] text-heading underline hover:text-accent" href="{{ route('account.sell-jewellery.create') }}">+ New Request</a>
    </div>

    @if ($requests->isEmpty())
      <div class="rounded-lg border border-line p-8 text-center">
        <p class="mb-4 text-[13px] text-muted">You haven't submitted any jewellery yet.</p>
        <a class="inline-flex items-center justify-center gap-2 border border-accent bg-accent px-6 py-2.5 text-[12px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:border-accent-dark hover:bg-accent-dark" href="{{ route('account.sell-jewellery.create') }}">
          Submit Your Jewellery
        </a>
      </div>
    @else
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        @foreach ($requests as $request)
          @include('account.sell-jewellery._status-badge', ['request' => $request, 'asCard' => true])
        @endforeach
      </div>

      <div class="mt-8">
        {{ $requests->links() }}
      </div>
    @endif
  </div>

@endsection
