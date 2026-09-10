@extends('layouts.vendor-minimal')

@section('meta_title', $invitation->request->request_number.' | Vendor Bid | Estele')

@section('content')

  @php
    $bidRequest = $invitation->request;
    $isOpen = $invitation->response_status === 'pending'
        && $bidRequest->status === 'bidding_active'
        && now()->lessThan($invitation->expires_at);
    $videoUrl = $bidRequest->hasMedia('video')
        ? \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'old-jewellery.vendor.video',
            now()->addMinutes(30),
            ['invitation' => $invitation->id],
        )
        : null;
  @endphp

  @if (session('success'))
    <div class="mb-4 rounded-lg border border-line bg-green-50 p-3 text-[13px] text-green-700">{{ session('success') }}</div>
  @endif
  @if (session('error'))
    <div class="mb-4 rounded-lg border border-salebadge bg-red-50 p-3 text-[13px] text-salebadge">{{ session('error') }}</div>
  @endif
  @if ($errors->any())
    <div class="mb-4 rounded-lg border border-salebadge bg-red-50 p-3 text-[13px] text-salebadge">
      <ul class="list-disc space-y-1 pl-4">
        @foreach ($errors->all() as $error)
          <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
  @endif

  <h1 class="mb-1 text-[18px] uppercase tracking-[0.4px] text-heading">{{ $bidRequest->request_number }}</h1>
  <p class="mb-4 text-[12px] text-muted">Bidding closes {{ $bidRequest->bidding_end_at?->format('d M Y, h:i A') }}</p>

  <div class="mb-5 rounded-lg border border-line p-4">
    @if ($bidRequest->getFirstMediaUrl('image', 'thumb'))
      <img class="mb-3 max-h-56 w-full rounded-lg object-cover" src="{{ $bidRequest->getFirstMediaUrl('image', 'thumb') }}" alt="Jewellery photo">
    @endif

    @if ($videoUrl)
      <video class="mb-3 w-full rounded-lg" controls src="{{ $videoUrl }}"></video>
    @endif

    @if ($bidRequest->description)
      <p class="text-[13px] text-muted">{{ $bidRequest->description }}</p>
    @endif
  </div>

  @if ($isOpen)
    <form class="mb-5 rounded-lg border border-line p-4" action="{{ route('old-jewellery.vendor.accept', request()->route('token')) }}" method="post">
      @csrf
      <label class="mb-1.5 block text-[12px] font-medium uppercase tracking-[0.3px] text-heading" for="amount">Your Bid (₹)</label>
      <input class="mb-3 w-full rounded-lg border border-line p-3 text-[14px]" type="number" id="amount" name="amount" min="1" max="1000000" step="0.01" required>
      <button class="inline-flex w-full items-center justify-center gap-2 border border-accent bg-accent px-6 py-2.5 text-[12px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:border-accent-dark hover:bg-accent-dark" type="submit">
        Submit Bid
      </button>
    </form>

    <details class="rounded-lg border border-line p-4">
      <summary class="cursor-pointer text-[12px] font-medium uppercase tracking-[0.3px] text-muted">Can't offer on this one?</summary>
      <form class="mt-3" action="{{ route('old-jewellery.vendor.decline', request()->route('token')) }}" method="post">
        @csrf
        <textarea class="mb-3 w-full rounded-lg border border-line p-3 text-[13px]" name="reason" rows="3" maxlength="1000" placeholder="Reason (optional)"></textarea>
        <button class="inline-flex w-full items-center justify-center gap-2 border border-line px-6 py-2.5 text-[12px] font-medium uppercase tracking-[0.5px] text-heading transition-colors hover:border-salebadge hover:text-salebadge" type="submit">
          Decline
        </button>
      </form>
    </details>
  @elseif ($invitation->response_status === 'accepted')
    <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-[13px] text-green-700">
      You submitted a bid on {{ $invitation->responded_at?->format('d M Y, h:i A') }}.
    </div>
  @elseif ($invitation->response_status === 'declined')
    <div class="rounded-lg border border-line p-4 text-[13px] text-muted">
      You declined this request{{ $invitation->decline_reason ? ' — "'.$invitation->decline_reason.'"' : '' }}.
    </div>
  @else
    <div class="rounded-lg border border-line p-4 text-[13px] text-muted">
      This bidding window has closed.
    </div>
  @endif

@endsection
