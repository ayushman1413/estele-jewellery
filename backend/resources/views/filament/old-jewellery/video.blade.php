@php
    $record = $getRecord();
    $media = $record->getFirstMedia('video');
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @if ($media)
        @php
            // Screen-recording .mov files are almost always H.264/AAC inside a
            // QuickTime container — a codec Chrome/Firefox CAN decode. But if
            // the <source> declares type="video/quicktime", Chrome rejects it
            // outright without ever sniffing the actual codec. Omitting the
            // type attribute lets the browser probe the real container/codec
            // instead of trusting the (often browser-unsupported) MIME label,
            // so playback works in Chrome/Firefox whenever the codec allows it.
            $videoUrl = route('admin.old-jewellery.video', $record);
        @endphp
        <video
            controls
            preload="metadata"
            playsinline
            class="aspect-video w-full rounded-lg border border-gray-200 bg-black dark:border-white/10"
            x-data
            x-on:error.capture="$el.dispatchEvent(new CustomEvent('video-unplayable', {bubbles: true}))"
            x-on:video-unplayable.window="$refs.fallback.hidden = false"
        >
            <source src="{{ $videoUrl }}">
        </video>
        <p x-data x-ref="fallback" hidden class="mt-2 text-sm text-warning-600 dark:text-warning-400">
            This browser can't play the video above — use "Download video" below to view it in a media player.
        </p>
        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
            <span>{{ $media->file_name }} · {{ number_format($media->size / 1024 / 1024, 2) }} MB</span>
            <a
                href="{{ route('admin.old-jewellery.video', ['oldJewelleryRequest' => $record, 'download' => 1]) }}"
                class="fi-link inline-flex items-center gap-1 font-medium text-primary-600 hover:underline dark:text-primary-400"
            >
                <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" />
                Download video (optional)
            </a>
        </div>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">No video uploaded</p>
    @endif
</x-dynamic-component>
