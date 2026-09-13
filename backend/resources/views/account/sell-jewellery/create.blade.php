@extends('layouts.app')

@section('meta_title', 'Submit Your Jewellery | '.($siteSettings['site_name'] ?? 'Estele'))

@section('content')

  <nav class="mx-auto w-full max-w-wrapper px-3 md:px-4 flex flex-wrap items-center gap-1.5 py-4 text-[13px] text-muted" aria-label="Breadcrumb">
    <x-breadcrumb :items="[['label' => 'My Account', 'url' => route('account.index')], ['label' => 'Sell Your Jewellery', 'url' => route('account.sell-jewellery.landing')], ['label' => 'New Request']]" />
  </nav>

  <div class="mx-auto w-full max-w-2xl px-3 pb-10 md:px-4 md:pb-[60px]">
    <h1 class="mb-1 text-[20px] uppercase tracking-[0.5px] md:text-[26px]">Submit Your Jewellery</h1>
    <p class="mb-6 text-[13px] text-muted">Add a short video and a photo. Our vendors value your piece from what they see.</p>

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

      <div class="mb-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div data-media-picker="video">
          <label class="group relative block cursor-pointer overflow-hidden rounded-xl border-2 border-dashed border-line-strong bg-ivory transition-colors hover:border-accent has-[:focus-visible]:border-accent has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-accent/30 data-[filled]:border-solid data-[filled]:border-accent data-[filled]:bg-paper data-[dragging]:border-accent data-[dragging]:bg-pinksoft" data-dropzone>
            <input class="sr-only" type="file" id="video" name="video" accept=".mp4,.mov,video/mp4,video/quicktime" required data-file-input>

            <div class="flex min-h-[220px] flex-col items-center justify-center px-4 py-6 text-center" data-empty>
              <span class="mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-pinksoft text-accent transition-transform group-hover:scale-105">
                <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <rect x="3" y="6" width="13" height="12" rx="2" />
                  <path d="m16 10 5-3v10l-5-3" />
                </svg>
              </span>
              <span class="text-[13px] font-medium uppercase tracking-[0.4px] text-heading">Add video</span>
              <span class="mt-1 text-[12px] text-muted">Tap to record or choose</span>
              <span class="mt-3 rounded-full bg-paper px-2.5 py-0.5 text-[10px] uppercase tracking-[0.3px] text-accent ring-1 ring-accent/30">Required</span>
              <span class="mt-2 text-[11px] text-muted">MP4 / MOV · up to 20MB</span>
            </div>

            <div class="hidden" data-filled>
              <div class="relative aspect-square w-full bg-heading">
                <video class="absolute inset-0 h-full w-full object-cover" data-preview muted playsinline preload="metadata"></video>
                <span class="absolute inset-0 flex items-center justify-center bg-black/25">
                  <span class="flex h-12 w-12 items-center justify-center rounded-full bg-paper/90 text-heading">
                    <svg class="ml-0.5 h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z" /></svg>
                  </span>
                </span>
                <span class="absolute left-2 top-2 rounded-full bg-paper/95 px-2 py-0.5 text-[10px] font-medium uppercase tracking-[0.3px] text-heading">Video</span>
              </div>
              <div class="flex items-center gap-2 px-3 py-2.5">
                <div class="min-w-0 flex-1">
                  <p class="truncate text-[12px] font-medium text-heading" data-file-name></p>
                  <p class="text-[11px] text-muted" data-file-size></p>
                </div>
                <span class="shrink-0 text-[11px] font-medium uppercase tracking-[0.3px] text-accent underline">Change</span>
                <button class="shrink-0 rounded-full p-1 text-muted transition-colors hover:bg-greysoft hover:text-heading" type="button" aria-label="Remove video" data-remove>
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                </button>
              </div>
            </div>
          </label>
          <p class="mt-2 text-[11px] leading-snug text-muted">Turn the piece slowly and show any hallmark or stamp — it helps vendors give their best offer.</p>
        </div>

        <div data-media-picker="image">
          <label class="group relative block cursor-pointer overflow-hidden rounded-xl border-2 border-dashed border-line-strong bg-ivory transition-colors hover:border-accent has-[:focus-visible]:border-accent has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-accent/30 data-[filled]:border-solid data-[filled]:border-accent data-[filled]:bg-paper data-[dragging]:border-accent data-[dragging]:bg-pinksoft" data-dropzone>
            <input class="sr-only" type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" data-file-input>

            <div class="flex min-h-[220px] flex-col items-center justify-center px-4 py-6 text-center" data-empty>
              <span class="mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-gold-light text-gold-hover transition-transform group-hover:scale-105">
                <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                  <path d="M4 8a2 2 0 0 1 2-2h2l1.5-2h5L16 6h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z" />
                  <circle cx="12" cy="12.5" r="3.5" />
                </svg>
              </span>
              <span class="text-[13px] font-medium uppercase tracking-[0.4px] text-heading">Add photo</span>
              <span class="mt-1 text-[12px] text-muted">Tap to take or choose</span>
              <span class="mt-3 rounded-full bg-paper px-2.5 py-0.5 text-[10px] uppercase tracking-[0.3px] text-muted ring-1 ring-line-strong">Optional</span>
              <span class="mt-2 text-[11px] text-muted">JPG / PNG / WebP · up to 3MB</span>
            </div>

            <div class="hidden" data-filled>
              <div class="relative aspect-square w-full bg-greysoft">
                <img class="absolute inset-0 h-full w-full object-cover" data-preview alt="Selected photo">
                <span class="absolute left-2 top-2 rounded-full bg-paper/95 px-2 py-0.5 text-[10px] font-medium uppercase tracking-[0.3px] text-heading">Photo</span>
              </div>
              <div class="flex items-center gap-2 px-3 py-2.5">
                <div class="min-w-0 flex-1">
                  <p class="truncate text-[12px] font-medium text-heading" data-file-name></p>
                  <p class="text-[11px] text-muted" data-file-size></p>
                </div>
                <span class="shrink-0 text-[11px] font-medium uppercase tracking-[0.3px] text-accent underline">Change</span>
                <button class="shrink-0 rounded-full p-1 text-muted transition-colors hover:bg-greysoft hover:text-heading" type="button" aria-label="Remove photo" data-remove>
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
                </button>
              </div>
            </div>
          </label>
          <p class="mt-2 text-[11px] leading-snug text-muted">A clear, well-lit shot of the full piece.</p>
        </div>
      </div>

      <div class="mb-6">
        <label class="mb-1.5 flex items-baseline justify-between text-[12px] font-medium uppercase tracking-[0.3px] text-heading" for="description">
          <span>Description</span>
          <span class="text-[10px] font-normal normal-case tracking-normal text-muted">Optional</span>
        </label>
        <textarea class="w-full resize-none rounded-lg border border-line bg-paper p-3 text-[14px] leading-snug placeholder:text-muted/70 focus:border-accent focus:outline-none focus:ring-2 focus:ring-accent/20" id="description" name="description" rows="3" maxlength="2000" placeholder="Metal, weight, purity, age — anything that helps value it">{{ old('description') }}</textarea>
      </div>

      <button class="inline-flex w-full items-center justify-center gap-2 border border-accent bg-accent px-6 py-3.5 text-[13px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:border-accent-dark hover:bg-accent-dark disabled:cursor-not-allowed disabled:opacity-60" type="submit" data-submit>
        Submit Request
      </button>
    </form>
  </div>

  @push('scripts')
    <script>
      (function () {
        function formatSize(bytes) {
          if (bytes < 1024 * 1024) return Math.max(1, Math.round(bytes / 1024)) + ' KB';
          return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
        }

        document.querySelectorAll('[data-media-picker]').forEach(function (picker) {
          var zone = picker.querySelector('[data-dropzone]');
          var input = picker.querySelector('[data-file-input]');
          var empty = picker.querySelector('[data-empty]');
          var filled = picker.querySelector('[data-filled]');
          var preview = picker.querySelector('[data-preview]');
          var nameEl = picker.querySelector('[data-file-name]');
          var sizeEl = picker.querySelector('[data-file-size]');
          var removeBtn = picker.querySelector('[data-remove]');
          var objectUrl = null;

          function clearPreview() {
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = null;
            preview.removeAttribute('src');
            if (preview.tagName === 'VIDEO') preview.load();
          }

          function render() {
            var file = input.files && input.files[0];
            clearPreview();

            if (!file) {
              zone.removeAttribute('data-filled');
              empty.classList.remove('hidden');
              filled.classList.add('hidden');
              return;
            }

            objectUrl = URL.createObjectURL(file);
            preview.src = objectUrl;
            nameEl.textContent = file.name;
            sizeEl.textContent = formatSize(file.size);
            zone.setAttribute('data-filled', '');
            empty.classList.add('hidden');
            filled.classList.remove('hidden');
          }

          input.addEventListener('change', render);

          removeBtn.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            input.value = '';
            render();
          });

          ['dragenter', 'dragover'].forEach(function (name) {
            zone.addEventListener(name, function (event) {
              event.preventDefault();
              zone.setAttribute('data-dragging', '');
            });
          });

          ['dragleave', 'drop'].forEach(function (name) {
            zone.addEventListener(name, function () {
              zone.removeAttribute('data-dragging');
            });
          });

          zone.addEventListener('drop', function (event) {
            event.preventDefault();
            if (!event.dataTransfer || !event.dataTransfer.files.length) return;
            input.files = event.dataTransfer.files;
            render();
          });
        });

        var form = document.querySelector('[data-sell-jewellery-form]');
        var submit = form && form.querySelector('[data-submit]');
        if (form && submit) {
          form.addEventListener('submit', function () {
            submit.disabled = true;
            submit.textContent = 'Uploading…';
          });
        }
      })();
    </script>
  @endpush

@endsection
