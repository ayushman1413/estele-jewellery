@php
    $record = $getRecord();
    $endsAt = $record->bidding_end_at;
    $isOpen = $record->status === 'bidding_active' && $endsAt && now()->lessThan($endsAt);
@endphp

<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @if ($isOpen)
        <span
            x-data="{
                end: {{ $endsAt->getTimestamp() * 1000 }},
                text: '',
                tick() {
                    const diff = Math.max(0, Math.floor((this.end - Date.now()) / 1000));
                    const h = String(Math.floor(diff / 3600)).padStart(2, '0');
                    const m = String(Math.floor((diff % 3600) / 60)).padStart(2, '0');
                    const s = String(diff % 60).padStart(2, '0');
                    this.text = diff > 0 ? `${h}:${m}:${s}` : 'Closing…';
                }
            }"
            x-init="tick(); setInterval(() => tick(), 1000)"
            x-text="text"
            class="font-mono text-lg font-semibold text-warning-600 dark:text-warning-400"
        ></span>
    @elseif ($record->status === 'bidding_active')
        <span class="text-sm text-danger-600">Deadline passed — awaiting scheduler close</span>
    @else
        <span class="text-sm text-gray-500 dark:text-gray-400">Bidding closed</span>
    @endif
</x-dynamic-component>
