@php
    $record = $getRecord();
    $media = $record->getFirstMedia('video');
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @if ($media)
        <video controls preload="metadata" class="w-full max-w-md rounded-lg border border-gray-200 bg-black dark:border-white/10">
            <source src="{{ route('admin.old-jewellery.video', $record) }}" type="{{ $media->mime_type }}">
        </video>
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $media->file_name }} · {{ number_format($media->size / 1024 / 1024, 2) }} MB</p>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">No video uploaded</p>
    @endif
</x-dynamic-component>
