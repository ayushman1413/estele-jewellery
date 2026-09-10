@extends('layouts.app')

@section('meta_title', 'Submit Your Jewellery | '.($siteSettings['site_name'] ?? 'Estele'))

@section('content')

  <nav class="mx-auto w-full max-w-wrapper px-3 md:px-4 flex flex-wrap items-center gap-1.5 py-4 text-[13px] text-muted" aria-label="Breadcrumb">
    <x-breadcrumb :items="[['label' => 'My Account', 'url' => route('account.index')], ['label' => 'Sell Your Jewellery', 'url' => route('account.sell-jewellery.landing')], ['label' => 'New Request']]" />
  </nav>

  <div class="mx-auto w-full max-w-2xl px-3 pb-10 md:px-4 md:pb-[60px]">
    <h1 class="mb-6 text-[20px] uppercase tracking-[0.5px] md:text-[26px]">Submit Your Jewellery</h1>

    @if ($errors->any())
      <div class="mb-4 rounded-lg border border-salebadge bg-red-50 p-4 text-[13px] text-salebadge">
        <ul class="list-disc space-y-1 pl-4">
          @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <form action="{{ route('account.sell-jewellery.store') }}" method="post" enctype="multipart/form-data" data-sell-jewellery-form>
      @csrf

      <div class="mb-5">
        <label class="mb-1.5 block text-[12px] font-medium uppercase tracking-[0.3px] text-heading" for="description">Description (optional)</label>
        <textarea class="w-full rounded-lg border border-line p-3 text-[14px]" id="description" name="description" rows="4" maxlength="2000">{{ old('description') }}</textarea>
      </div>

      <div class="mb-5">
        <label class="mb-1.5 block text-[12px] font-medium uppercase tracking-[0.3px] text-heading" for="image">Photo (optional, JPG/PNG/WebP, max 3MB)</label>
        <input class="w-full rounded-lg border border-line p-3 text-[13px]" type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp" data-file-preview="image">
        <img class="mt-3 hidden max-h-48 rounded-lg border border-line" data-preview-target="image" alt="Preview">
      </div>

      <div class="mb-6">
        <label class="mb-1.5 block text-[12px] font-medium uppercase tracking-[0.3px] text-heading" for="video">Video <span class="text-salebadge">*</span> (required, MP4/MOV, max 20MB)</label>
        <input class="w-full rounded-lg border border-line p-3 text-[13px]" type="file" id="video" name="video" accept=".mp4,.mov" required data-file-preview="video">
        <video class="mt-3 hidden max-h-48 w-full rounded-lg border border-line" data-preview-target="video" controls></video>
        <p class="mt-1 text-[12px] text-muted">A short video (turning the piece, showing any hallmark/stamp) helps vendors give you the best offer.</p>
      </div>

      <button class="inline-flex w-full items-center justify-center gap-2 border border-accent bg-accent px-6 py-3 text-[13px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:border-accent-dark hover:bg-accent-dark" type="submit">
        Submit Request
      </button>
    </form>
  </div>

  @push('scripts')
    <script>
      (function () {
        document.querySelectorAll('[data-file-preview]').forEach(function (input) {
          input.addEventListener('change', function () {
            var kind = input.getAttribute('data-file-preview');
            var target = document.querySelector('[data-preview-target="' + kind + '"]');
            if (!target || !input.files || !input.files[0]) return;

            var url = URL.createObjectURL(input.files[0]);
            target.src = url;
            target.classList.remove('hidden');
          });
        });
      })();
    </script>
  @endpush

@endsection
