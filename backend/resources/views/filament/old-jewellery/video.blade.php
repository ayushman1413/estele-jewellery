@php
    $record = $getRecord();
    $media = $record->getFirstMedia('video');
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @if ($media)
        @php
            // .mov (QuickTime) files play natively only in Safari — Chrome and
            // Firefox silently fail to render the <video> tag for this codec.
            // No server-side transcode is possible on this shared host
            // (ffmpeg absent, exec/proc_open disabled), so give every browser
            // a way to see the file: try inline playback, but always offer a
            // direct download as the reliable fallback.
            $isQuicktime = $media->mime_type === 'video/quicktime';
        @endphp
        <video controls preload="metadata" class="w-full max-w-md rounded-lg border border-gray-200 bg-black dark:border-white/10">
            <source src="{{ route('admin.old-jewellery.video', $record) }}" type="{{ $media->mime_type }}">
        </video>
        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
            <span>{{ $media->file_name }} · {{ number_format($media->size / 1024 / 1024, 2) }} MB</span>
            <a
                href="{{ route('admin.old-jewellery.video', ['oldJewelleryRequest' => $record, 'download' => 1]) }}"
                class="fi-link inline-flex items-center gap-1 font-medium text-primary-600 hover:underline dark:text-primary-400"
            >
                <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" />
                Download video
            </a>
        </div>
        @if ($isQuicktime)
            <p class="mt-1 text-xs text-warning-600 dark:text-warning-400">
                .mov file — may not play above in Chrome/Firefox. Use "Download video" to view it in any player.
            </p>
        @endif
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">No video uploaded</p>
    @endif
</x-dynamic-component>
