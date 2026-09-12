@php
    $record = $getRecord();
    $imageMedia = $record->getFirstMedia('image');
    $videoMedia = $record->getFirstMedia('video');

    // The original image sits on a private disk with no public URL, so the
    // full-size view streams through the admin route; only the small 'thumb'
    // conversion is web-reachable, which is all the tile needs.
    $imageThumbUrl = $record->getFirstMediaUrl('image', 'thumb') ?: null;
    $imageFullUrl = $imageMedia ? route('admin.old-jewellery.image', $record) : null;
    $videoUrl = $videoMedia ? route('admin.old-jewellery.video', $record) : null;
@endphp

{{-- Sizing and layout are inline styles, not utility classes: the Filament
     panel loads only its own stylesheet, which carries none of the storefront
     theme's utilities (w-40, aspect-square, object-cover), and arbitrary
     values like max-h-[90vh] compile nowhere in this backend at all — it has
     no live Tailwind build. Same workaround as the account page's grid. --}}
<x-dynamic-component :component="$getEntryWrapperView()" :entry="$entry">
    @if ($imageMedia || $videoMedia)
        {{-- Alpine state is scoped to this one entry: `open` holds 'image',
             'video' or null, which is what the overlay below renders. --}}
        <div
            x-data="{
                open: null,
                show(kind) {
                    this.open = kind;
                    document.body.style.overflow = 'hidden';
                },
                close() {
                    this.open = null;
                    document.body.style.overflow = '';
                    // Stop playback when the overlay is dismissed, otherwise
                    // audio keeps running behind the closed lightbox.
                    if (this.$refs.player) {
                        this.$refs.player.pause();
                        this.$refs.player.currentTime = 0;
                    }
                },
            }"
            x-on:keydown.escape.window="close()"
        >
            {{-- Side by side and deliberately small: these are previews, the
                 real viewing happens in the overlay. --}}
            <div style="display:flex; flex-wrap:wrap; align-items:flex-start; gap:12px;">
                @if ($imageMedia)
                    <button
                        type="button"
                        x-on:click="show('image')"
                        style="position:relative; width:132px; padding:0; border:1px solid rgba(127,127,127,.3); border-radius:8px; overflow:hidden; background:transparent; cursor:zoom-in; display:block;"
                        title="Click to view full size"
                    >
                        <img
                            src="{{ $imageThumbUrl ?? $imageFullUrl }}"
                            alt="Jewellery photo"
                            style="display:block; width:132px; height:132px; object-fit:cover;"
                        >
                        <span style="display:block; padding:4px 8px; font-size:11px; line-height:1.4; text-align:left; color:#9ca3af;">Photo</span>
                    </button>
                @endif

                @if ($videoMedia)
                    <button
                        type="button"
                        x-on:click="show('video')"
                        style="position:relative; width:132px; padding:0; border:1px solid rgba(127,127,127,.3); border-radius:8px; overflow:hidden; background:#000; cursor:pointer; display:block;"
                        title="Click to play"
                    >
                        {{-- preload="metadata" gives a still first frame to act
                             as the poster without fetching the whole file. --}}
                        <video
                            style="display:block; width:132px; height:132px; object-fit:cover; pointer-events:none;"
                            preload="metadata"
                            muted
                            playsinline
                        >
                            <source src="{{ $videoUrl }}#t=0.1">
                        </video>
                        <span style="position:absolute; top:0; left:0; width:132px; height:132px; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.35);">
                            <svg viewBox="0 0 24 24" fill="#fff" style="width:34px; height:34px; opacity:.92;">
                                <path d="M8 5v14l11-7z" />
                            </svg>
                        </span>
                        <span style="position:absolute; bottom:0; left:0; right:0; padding:4px 8px; font-size:11px; line-height:1.4; text-align:left; color:#e5e7eb; background:rgba(0,0,0,.55);">Video</span>
                    </button>
                @endif
            </div>

            {{-- Full-page overlay. Teleported to <body> so it escapes the
                 infolist's stacking/overflow context and genuinely covers the
                 page rather than being clipped inside the section card. --}}
            <template x-teleport="body">
                <div
                    x-show="open"
                    x-cloak
                    x-transition.opacity
                    style="position:fixed; inset:0; z-index:9999; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,.9); padding:24px;"
                    x-on:click.self="close()"
                >
                    <button
                        type="button"
                        x-on:click="close()"
                        style="position:absolute; top:16px; right:16px; width:40px; height:40px; display:flex; align-items:center; justify-content:center; border:0; border-radius:9999px; background:rgba(255,255,255,.12); color:#fff; cursor:pointer;"
                        aria-label="Close"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:22px; height:22px;">
                            <path d="M6 6l12 12M18 6L6 18" />
                        </svg>
                    </button>

                    @if ($imageMedia)
                        <img
                            x-show="open === 'image'"
                            src="{{ $imageFullUrl }}"
                            alt="Jewellery photo"
                            style="max-height:90vh; max-width:90vw; object-fit:contain; border-radius:8px;"
                        >
                    @endif

                    @if ($videoMedia)
                        {{-- No type attribute on <source>: screen-recording .mov
                             files are usually H.264/AAC in a QuickTime container,
                             which Chrome can decode — but it rejects
                             type="video/quicktime" outright without sniffing the
                             real codec. Omitting it lets the browser probe. --}}
                        <video
                            x-show="open === 'video'"
                            x-ref="player"
                            controls
                            autoplay
                            playsinline
                            controlsList="nodownload"
                            disablepictureinpicture
                            style="max-height:90vh; max-width:90vw; border-radius:8px; background:#000;"
                        >
                            <source src="{{ $videoUrl }}">
                        </video>
                    @endif
                </div>
            </template>
        </div>
    @else
        <p style="font-size:13px; color:#9ca3af;">No photo or video uploaded</p>
    @endif
</x-dynamic-component>
