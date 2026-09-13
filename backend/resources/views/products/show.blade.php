@extends('layouts.app')

@section('meta_title', ($product->seoMeta?->title ?? $product->title).' | '.($siteSettings['site_name'] ?? 'Estele'))
@section('meta_description', $product->seoMeta?->description ?: ($product->description ?: $product->title))
@section('og_type', 'product')
@if($product->seoMeta?->og_image || $product->hasMedia('gallery'))
  @section('og_image', $product->seoMeta?->og_image ?? $product->getFirstMediaUrl('gallery', 'detail'))
@endif

@section('content')

  @php
    $primaryCategory = $product->categories->first();
    $discountPercent = $product->compare_at_price
      ? (int) round((($product->compare_at_price - $product->price) / $product->compare_at_price) * 100)
      : null;
    $galleryImages = $product->getMedia('gallery');
    $mainMedia = $galleryImages->first();
    $ratingAverage = $product->reviewsAverageRating();
    $ratingCount = $product->reviewsCount();
    $breadcrumbItems = array_filter([
      $primaryCategory ? ['label' => $primaryCategory->name, 'url' => route('categories.show', $primaryCategory)] : null,
      ['label' => $product->title],
    ]);
  @endphp

  <script type="application/ld+json">
    {!! json_encode(array_filter([
      '@context' => 'https://schema.org',
      '@type' => 'Product',
      'name' => $product->title,
      'image' => $galleryImages->map(fn ($media) => $media->getUrl('detail'))->all(),
      'description' => $product->description,
      'sku' => $product->sku,
      'brand' => ['@type' => 'Brand', 'name' => $siteSettings['site_name'] ?? 'Estele'],
      'offers' => [
        '@type' => 'Offer',
        'url' => route('products.show', $product),
        'priceCurrency' => 'INR',
        'price' => (string) $product->price,
        'availability' => $product->stock_quantity > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
      ],
      'aggregateRating' => $ratingCount > 0 ? [
        '@type' => 'AggregateRating',
        'ratingValue' => $ratingAverage,
        'reviewCount' => $ratingCount,
      ] : null,
      'review' => $reviews->isNotEmpty() ? $reviews->map(fn ($review) => [
        '@type' => 'Review',
        'author' => ['@type' => 'Person', 'name' => $review->customer_name],
        'datePublished' => $review->displayDate()->toIso8601String(),
        'reviewBody' => $review->body,
        'reviewRating' => [
          '@type' => 'Rating',
          'ratingValue' => $review->rating,
          'bestRating' => 5,
          'worstRating' => 1,
        ],
      ])->all() : null,
    ]), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}
  </script>

  <x-breadcrumb-schema :items="$breadcrumbItems" />

  <nav class="mx-auto w-full max-w-wrapper px-4 flex flex-wrap items-center gap-1.5 py-4 text-[13px] text-muted" aria-label="Breadcrumb">
    <x-breadcrumb :items="$breadcrumbItems" />
  </nav>

  {{--
    Gallery layout: real Estele product pages run a vertical thumbnail strip
    beside the main image on desktop, not a grid below it (verified live via
    browser). Reproduced with plain scoped CSS rather than new Tailwind
    utility classes — see the note on padding above for why: this backend's
    public/theme/app.css is a static copy of a separate project's compiled
    CSS, so a fresh utility class written only here would render as nothing.
    Individual thumbnail buttons keep their original Tailwind classes
    (aspect-square/border-accent/etc.) since those are already proven
    compiled in this exact file.

    Same reasoning covers everything else new below (.pdp-image-wrap /
    .pdp-zoom-* / .pdp-lightbox* / .pdp-title-row / .pdp-icon-*): plain scoped
    CSS/JS, not new Tailwind classes. These reproduce three things confirmed
    missing here vs. the real Estele PDP (checked live via browser) — a
    hover-to-zoom lens with a magnified side panel, an expand icon that opens
    the current image full-screen, and wishlist/share icons beside the title.
  --}}
  <style>
    .pdp-gallery { display: flex; flex-direction: column; gap: 10px; }
    .pdp-thumbs { display: flex; flex-direction: row; gap: 10px; overflow-x: auto; order: 2; }
    .pdp-thumbs .pdp-thumb { flex: 0 0 72px; width: 72px; }
    .pdp-image-wrap { position: relative; order: 1; }
    @media (min-width: 768px) {
      .pdp-gallery { flex-direction: row; align-items: flex-start; }
      .pdp-thumbs { flex-direction: column; overflow-x: visible; overflow-y: auto; order: 1; max-height: 600px; width: 84px; flex: 0 0 84px; }
      .pdp-thumbs .pdp-thumb { width: 100%; flex: 0 0 auto; }
      .pdp-image-wrap { order: 2; flex: 1 1 auto; min-width: 0; }
    }

    .pdp-icon-btn {
      display: flex; align-items: center; justify-content: center;
      height: 34px; width: 34px; padding: 0; border-radius: 999px;
      border: 1px solid var(--color-line); background: transparent;
      color: var(--color-heading); cursor: pointer;
      transition: color .15s ease, border-color .15s ease;
    }
    .pdp-icon-btn:hover, .pdp-icon-btn.is-active { border-color: var(--color-accent); color: var(--color-accent); }
    .pdp-icon-btn svg { height: 16px; width: 16px; }

    .pdp-expand-btn {
      position: absolute; top: 10px; right: 10px; z-index: 5;
      background: var(--color-white); border: 0;
      box-shadow: 0 1px 4px rgba(0, 0, 0, .18);
    }

    /* Hover-to-zoom lens + magnified side panel, matching estele.co's PDP gallery. */
    .pdp-zoom-lens {
      position: absolute; display: none; pointer-events: none; z-index: 4;
      border: 1px solid rgba(20, 20, 20, .45); background: rgba(255, 255, 255, .35);
    }
    .pdp-zoom-pane {
      position: absolute; display: none; top: 0; left: 100%; margin-left: 16px;
      width: 100%; height: 100%; z-index: 20; border-radius: 4px;
      background-color: var(--color-placeholder); background-repeat: no-repeat;
      box-shadow: 0 8px 30px rgba(0, 0, 0, .16);
    }
    @media (min-width: 1024px) {
      .pdp-main { cursor: zoom-in; }
    }

    .pdp-lightbox {
      position: fixed; inset: 0; z-index: 100; display: flex;
      align-items: center; justify-content: center;
      background: rgba(0, 0, 0, .85); padding: 24px;
    }
    .pdp-lightbox img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .pdp-lightbox-close {
      position: absolute; top: 16px; right: 20px; z-index: 1;
      background: none; border: 0; color: var(--color-white);
      font-size: 32px; line-height: 1; cursor: pointer;
    }

    .pdp-title-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
    .pdp-title-row h1 { margin: 0; }
    .pdp-icon-group { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
    .pdp-share-tip {
      position: absolute; bottom: calc(100% + 8px); left: 50%; transform: translateX(-50%);
      white-space: nowrap; background: var(--color-heading); color: var(--color-white);
      font-size: 11px; padding: 4px 8px; border-radius: 4px;
    }

    {{--
      Sticky buy box: on mobile the qty/Add-to-Cart/Buy-It-Now group pins to
      the bottom of the viewport while the rest of the page scrolls (standard
      PDP pattern — otherwise the purchase actions scroll away under the
      description/accordion content). Desktop keeps the normal in-flow
      layout since there's room beside the gallery already. The chat bubble
      and back-to-top button (both fixed bottom-right, see layouts/app.blade.php)
      get pushed up on mobile only here so the new bar doesn't sit under them —
      inline/scoped-CSS per this app's no-live-Tailwind-build constraint,
      matching the pattern already used for back-to-top's own offset.
    --}}
    .pdp-buy-box-spacer { display: none; }
    @media (max-width: 767px) {
      .pdp-buy-box {
        position: fixed; left: 0; right: 0; bottom: 0; z-index: 80;
        margin: 0; background: var(--color-white);
        padding: 10px 16px calc(10px + env(safe-area-inset-bottom));
        box-shadow: 0 -2px 10px rgba(0, 0, 0, .08); border-top: 1px solid var(--color-line);
      }
      .pdp-buy-box-spacer { display: block; height: 132px; }
      [data-chat] { bottom: 200px !important; }
      .back-to-top-btn { bottom: 260px !important; }
    }
  </style>

  {{--
    Wrapped in <article> (not just a plain <div>) so the site-wide wishlist heart
    click-handler in app.js — which walks up to the nearest article/li to find an
    <img> for its localStorage key — resolves to this product's own image here too,
    same as it does for product cards on listing pages.
  --}}
  <article class="mx-auto w-full max-w-wrapper px-4 grid grid-cols-1 gap-8 pb-10 md:grid-cols-2 md:gap-[46px] md:pb-[60px]">

    <div class="pdp-gallery">
      @if($galleryImages->count() > 1)
        <div class="pdp-thumbs">
          @foreach($galleryImages as $index => $media)
            <button class="pdp-thumb aspect-square overflow-hidden rounded border bg-placeholder transition-colors {{ $index === 0 ? 'border-accent' : 'border-transparent hover:border-accent' }}" type="button"
                    data-gallery-thumb data-full="{{ $media->getUrl('detail') }}">
              <img class="h-full w-full object-cover" src="{{ $media->getUrl('card') }}" alt="{{ $product->title }} view {{ $index + 1 }}" loading="lazy" width="160" height="160">
            </button>
          @endforeach
        </div>
      @endif
      <div class="pdp-image-wrap">
        {{-- Image area reduced ~20% via inline padding — see product-card.blade.php for why this isn't a Tailwind p-[...] class. --}}
        <div class="pdp-main aspect-square overflow-hidden rounded bg-placeholder" style="padding: 5.3%" id="pdp-zoom-frame">
          @if($mainMedia)
            <img class="h-full w-full object-cover" id="pdp-main-img"
                 src="{{ $mainMedia->getUrl('detail') }}"
                 srcset="{{ $mainMedia->getUrl('mobile') }} 768w, {{ $mainMedia->getUrl('tablet') }} 1024w, {{ $mainMedia->getUrl('detail') }} 1600w"
                 sizes="(max-width: 768px) 100vw, 50vw"
                 alt="{{ $product->title }}" width="1000" height="1000" fetchpriority="high">
          @endif
          <div class="pdp-zoom-lens" id="pdp-zoom-lens"></div>
        </div>
        @if($mainMedia)
          <button class="pdp-icon-btn pdp-expand-btn" type="button" id="pdp-expand-btn" aria-label="View full image">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 3H3v6M15 3h6v6M9 21H3v-6M15 21h6v-6"/></svg>
          </button>
          <div class="pdp-zoom-pane" id="pdp-zoom-pane"></div>
        @endif
      </div>
    </div>

    <div>
      <div class="pdp-title-row mb-1.5">
        <h1 class="font-serif text-[24px] md:text-[30px] font-semibold text-heading leading-tight">{{ $product->title }}</h1>
        <div class="pdp-icon-group">
          <button class="pdp-icon-btn" type="button" aria-label="Add to wishlist">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1.1 1.1L12 21.2l7.7-7.7 1.1-1.1a5.5 5.5 0 0 0 0-7.8z"/></svg>
          </button>
          <button class="pdp-icon-btn" type="button" id="pdp-share-btn" aria-label="Share">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="18" cy="5" r="2.4"/><circle cx="6" cy="12" r="2.4"/><circle cx="18" cy="19" r="2.4"/><path d="M8.1 10.7l7.8-4.4M8.1 13.3l7.8 4.4"/></svg>
          </button>
        </div>
      </div>
      @if($product->sku)
        <p class="mb-2.5 text-[12px] font-medium tracking-wider text-muted uppercase">SKU: {{ $product->sku }}</p>
      @endif

      @if($ratingCount > 0)
        <a class="mb-3 inline-flex items-center gap-2" href="#reviews">
          <x-review-stars :rating="$ratingAverage" :count="$ratingCount" />
        </a>
      @endif

      <div class="mb-1 flex flex-wrap items-baseline gap-3">
        <span class="font-serif text-[26px] font-semibold text-price md:text-[30px]">₹{{ number_format($product->price, 0) }}</span>
        @if($product->compare_at_price)
          <span class="text-[16px] text-muted line-through">₹{{ number_format($product->compare_at_price, 0) }}</span>
          <span class="bg-salebadge px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.1em] text-white">{{ $discountPercent }}% off</span>
        @endif
      </div>
      <p class="mb-5 text-[12px] text-muted">Inclusive of all taxes &middot; Free shipping available</p>

      {{-- Trust badge row --}}
      <div class="mb-6 grid grid-cols-3 gap-2 rounded-xl bg-warmbeige/40 p-3.5 border border-line text-center">
        <div>
          <div class="mx-auto mb-1 grid h-8 w-8 place-items-center rounded-full bg-white text-accent shadow-sm">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          </div>
          <p class="text-[11px] font-semibold text-heading">Skin Friendly</p>
          <p class="text-[10px] text-muted">24K Gold Plated</p>
        </div>
        <div>
          <div class="mx-auto mb-1 grid h-8 w-8 place-items-center rounded-full bg-white text-accent shadow-sm">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 4v6h6"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
          </div>
          <p class="text-[11px] font-semibold text-heading">Easy Returns</p>
          <p class="text-[10px] text-muted">7-Day Guarantee</p>
        </div>
        <div>
          <div class="mx-auto mb-1 grid h-8 w-8 place-items-center rounded-full bg-white text-accent shadow-sm">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="2"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/></svg>
          </div>
          <p class="text-[11px] font-semibold text-heading">Free Shipping</p>
          <p class="text-[10px] text-muted">Orders > ₹1,499</p>
        </div>
      </div>

      @include('partials.offers-banner')

      <form class="mt-4" action="{{ route('cart.store', $product) }}" method="post" data-cart-form data-checkout-url="{{ route('checkout.index') }}">
        @csrf

        @if($product->variants->isNotEmpty())
          <div class="mb-5">
            <span class="mb-2 block text-[13px] font-medium uppercase tracking-[0.4px]">Select Option</span>
            <div class="flex flex-wrap gap-2.5">
              @foreach($product->variants as $index => $variant)
                <label class="cursor-pointer rounded-md border-2 border-line-strong px-4 py-2 text-[13px] font-medium transition-colors has-[:checked]:border-accent has-[:checked]:bg-accent/5 has-[:checked]:text-accent text-heading hover:border-accent {{ $variant->stock_quantity <= 0 ? 'opacity-40' : '' }}">
                  <input class="sr-only" type="radio" name="product_variant_id" value="{{ $variant->id }}" {{ $index === 0 ? 'checked' : '' }} {{ $variant->stock_quantity <= 0 ? 'disabled' : '' }}>
                  {{ collect($variant->attributes ?? [])->map(fn($v, $k) => "{$k}: {$v}")->implode(', ') ?: $variant->sku }}
                </label>
              @endforeach
            </div>
          </div>
        @endif

        <div class="pdp-buy-box mb-3 flex flex-col gap-3">
          <div class="flex flex-col sm:flex-row gap-3">
            <div class="inline-flex h-12 w-32 shrink-0 items-center justify-between rounded-md border border-line-strong bg-white px-2 shadow-sm" data-qty>
              <button class="grid h-8 w-8 place-items-center rounded text-[16px] font-semibold text-heading transition-colors hover:bg-warmbeige" type="button" data-qty-minus aria-label="Decrease quantity">&minus;</button>
              <input class="w-10 border-0 text-center font-medium text-[14px] text-heading outline-none" type="number" name="quantity" value="1" min="1" aria-label="Quantity">
              <button class="grid h-8 w-8 place-items-center rounded text-[16px] font-semibold text-heading transition-colors hover:bg-warmbeige" type="button" data-qty-plus aria-label="Increase quantity">+</button>
            </div>
            <button class="flex-1 rounded-md bg-accent py-3.5 px-6 text-center text-[13px] font-semibold uppercase tracking-[0.6px] text-white shadow-sm transition-colors hover:bg-accent-dark" type="submit"
                    {{ $product->stock_quantity <= 0 ? 'disabled' : '' }}>
              {{ $product->stock_quantity > 0 ? 'Add to Cart' : 'Out of Stock' }}
            </button>
          </div>
          <button class="inline-flex w-full items-center justify-center gap-2 rounded-md bg-heading py-3.5 px-6 text-center text-[13px] font-semibold uppercase tracking-[0.6px] text-white shadow-sm transition-colors hover:bg-black disabled:opacity-60" type="submit" name="express" value="1" formaction="{{ route('checkout.express.start', $product) }}" data-express-submit
                  {{ $product->stock_quantity <= 0 ? 'disabled' : '' }}>
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13 2 4 14h6l-1 8 9-12h-6z"/></svg>
            Checkout
          </button>
          <p class="-mt-1 text-center text-[11px] text-muted">Fast &amp; secure · UPI, cards, net banking, COD</p>
        </div>
        <div class="pdp-buy-box-spacer" aria-hidden="true"></div>
      </form>

      <div class="mt-7 border-t border-line">
        @if($product->description)
          <details class="group border-b border-line" open>
            <summary class="flex items-center justify-between py-4 text-[13px] font-semibold uppercase tracking-[0.4px] text-heading cursor-pointer">
              <span>Description</span>
              <span class="text-[16px] text-accent transition-transform group-open:rotate-180">&minus;</span>
            </summary>
            <div class="pb-4 text-[13.5px] leading-[1.8] text-muted">
              <p>{{ $product->description }}</p>
            </div>
          </details>
        @endif
        <details class="group border-b border-line">
          <summary class="flex items-center justify-between py-4 text-[13px] font-semibold uppercase tracking-[0.4px] text-heading cursor-pointer">
            <span>Shipping &amp; 7-Day Returns</span>
            <span class="text-[16px] text-accent transition-transform group-open:rotate-180">+</span>
          </summary>
          <div class="pb-4 text-[13.5px] leading-[1.8] text-muted">
            <p>Free shipping on all prepaid orders across India. Orders are dispatched within 24-48 hours. Returns and exchanges accepted within 7 days of delivery, provided the product is unused and in original packaging.</p>
          </div>
        </details>
        <details class="group border-b border-line">
          <summary class="flex items-center justify-between py-4 text-[13px] font-semibold uppercase tracking-[0.4px] text-heading cursor-pointer">
            <span>Manufacturing Details</span>
            <span class="text-[16px] text-accent transition-transform group-open:rotate-180">+</span>
          </summary>
          <div class="pb-4 text-[13.5px] leading-[1.8] text-muted">
            <p>Adorn yourself with the allure of anti-tarnish jewelry, exuding beauty and durability. @if($product->sku) SKU: {{ $product->sku }}. @endif Every piece is quality-checked before dispatch.</p>
          </div>
        </details>
        <details class="group border-b border-line">
          <summary class="flex items-center justify-between py-4 text-[13px] font-semibold uppercase tracking-[0.4px] text-heading cursor-pointer">
            <span>Jewellery Care &amp; Maintenance</span>
            <span class="text-[16px] text-accent transition-transform group-open:rotate-180">+</span>
          </summary>
          <ul class="pb-4 space-y-2 text-[13px] text-muted">
            <li class="relative pl-5 before:absolute before:left-0 before:top-[7px] before:h-2 before:w-2 before:rounded-full before:bg-accent">Keep jewellery away from water &amp; humidity</li>
            <li class="relative pl-5 before:absolute before:left-0 before:top-[7px] before:h-2 before:w-2 before:rounded-full before:bg-accent">Remove jewellery before sleeping or physical activities</li>
            <li class="relative pl-5 before:absolute before:left-0 before:top-[7px] before:h-2 before:w-2 before:rounded-full before:bg-accent">Avoid direct contact with perfume, body lotions or chemicals</li>
            <li class="relative pl-5 before:absolute before:left-0 before:top-[7px] before:h-2 before:w-2 before:rounded-full before:bg-accent">Store separately in an air-tight jewellery box</li>
          </ul>
        </details>
      </div>
    </div>
  </article>

  @if($mainMedia)
    <div class="pdp-lightbox" id="pdp-lightbox" hidden>
      <button class="pdp-lightbox-close" type="button" data-lightbox-close aria-label="Close">&times;</button>
      <img id="pdp-lightbox-img" src="" alt="">
    </div>
  @endif

  @if($relatedProducts->isNotEmpty())
    <section class="py-12 md:py-16 bg-warmbeige/30 border-t border-line">
      <div class="mx-auto w-full max-w-wrapper px-4 md:px-8">
        <x-section-header title="You May Also Like" />
        <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 sm:gap-4 md:grid-cols-4 md:gap-5 lg:gap-6 xl:grid-cols-5 xl:gap-7 2xl:grid-cols-6">
          @foreach($relatedProducts as $related)
            <x-product-card :product="$related" />
          @endforeach
        </div>
      </div>
    </section>
  @endif

  {{--
    "Our Promise to You" trust strip. Uses a scoped grid (not the
    sm:grid-cols-3/md:grid-cols-5 Tailwind utilities) because in this
    backend's static-copy theme CSS (see the pdp-gallery note above),
    .sm\:grid-cols-3 happens to be emitted after .md\:grid-cols-5 in
    source order — so at desktop widths the sm: rule was winning the
    cascade and the grid never reached 5 equal columns, leaving an
    unbalanced 3+2 layout. Plain scoped CSS sidesteps that ordering
    landmine entirely.
  --}}
  <style>
    .promise-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
    @media (min-width: 640px) { .promise-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (min-width: 768px) { .promise-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 24px; } }
    .promise-card-icon { margin: 0 auto 10px; color: var(--color-accent); }
  </style>
  <section class="py-12 md:py-16 bg-ivory border-t border-line">
    <div class="mx-auto w-full max-w-wrapper px-4 md:px-8">
      <x-section-header title="Our Promise to You" />
      <div class="promise-grid">
        @foreach([
          ['title' => '24K Gold Plated', 'subtitle' => 'Precious long-lasting shine', 'icon' => 'M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z'],
          ['title' => 'Skin Friendly', 'subtitle' => 'Nickel & lead free formula', 'icon' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z'],
          ['title' => '35+ Years Legacy', 'subtitle' => 'Trusted by 5M+ happy women', 'icon' => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z'],
          ['title' => '7-Day Easy Returns', 'subtitle' => '100% exchange guarantee', 'icon' => 'M1 4v6h6M3.51 15a9 9 0 1 0 2.13-9.36L1 10'],
          ['title' => 'Free Shipping', 'subtitle' => 'Express Pan-India delivery', 'icon' => 'M1 3h15v13H1zM16 8h4l3 3v5h-7z'],
        ] as $promise)
          <div class="rounded-xl border border-line bg-white p-4 text-center shadow-sm">
            <svg class="promise-card-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="{{ $promise['icon'] }}"/></svg>
            <h3 class="font-serif text-[13px] font-semibold text-heading">{{ $promise['title'] }}</h3>
            <p class="mt-1 text-[11px] text-muted">{{ $promise['subtitle'] }}</p>
          </div>
        @endforeach
      </div>
    </div>
  </section>

  <section class="border-t border-line py-10 md:py-[60px]" id="reviews">
    <div class="mx-auto w-full max-w-[760px] px-4">
      <x-section-header
        title="Customer Reviews"
        :subtitle="$ratingCount > 0 ? number_format($ratingAverage, 1).' out of 5, based on '.$ratingCount.' review'.($ratingCount === 1 ? '' : 's') : 'No reviews yet — be the first to write one'"
      />

      @if(session('success'))
        <p class="mb-6 rounded-lg border border-line bg-pinksoft px-4 py-3 text-center text-[13px] text-heading">{{ session('success') }}</p>
      @endif

      @if($reviews->isNotEmpty())
        <ul class="mb-8 space-y-5">
          @foreach($reviews as $review)
            <li class="border-b border-line pb-5">
              <div class="mb-1.5 flex flex-wrap items-center gap-2">
                <x-review-stars :rating="$review->rating" />
                @if($review->is_verified_purchase)
                  <span class="text-[11px] font-medium uppercase tracking-[0.3px] text-accent">Verified Purchase</span>
                @endif
              </div>
              @if($review->title)
                <p class="mb-1 text-[14px] font-medium text-heading">{{ $review->title }}</p>
              @endif
              <p class="mb-2 text-[13.5px] leading-[1.7] text-muted">{{ $review->body }}</p>
              @if($review->hasMedia('photos'))
                <div class="mb-2 flex flex-wrap gap-2">
                  @foreach($review->getMedia('photos') as $photo)
                    <img class="h-16 w-16 rounded object-cover" src="{{ $photo->getUrl('thumb') }}" alt="Photo submitted with {{ $review->customer_name }}'s review" loading="lazy" width="64" height="64">
                  @endforeach
                </div>
              @endif
              <p class="text-[12px] text-muted">{{ $review->customer_name }} &middot; {{ $review->displayDate()->format('d M Y') }}</p>
            </li>
          @endforeach
        </ul>

        <div class="mb-8">
          {{ $reviews->links() }}
        </div>
      @endif

      <details {{ $errors->any() ? 'open' : '' }}>
        <summary class="mt-4 inline-flex items-center justify-center rounded-md border-2 border-accent px-6 py-3 text-[13px] font-semibold uppercase tracking-[0.6px] text-accent transition-colors hover:bg-accent hover:text-white">Write a Review</summary>
        <form class="pb-4.5" action="{{ route('products.reviews.store', $product) }}" method="post" enctype="multipart/form-data">
          @csrf
          <input class="hidden" type="text" name="website" tabindex="-1" autocomplete="off">

          <div class="mb-3.5 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <label class="mb-1.5 block text-[13px] font-medium text-heading" for="customer_name">Name</label>
              <input class="w-full border border-line-strong bg-white px-4 py-3 text-[14px] outline-none transition-colors placeholder:text-muted focus:border-heading" id="customer_name" name="customer_name" type="text" value="{{ old('customer_name') }}" required>
              @error('customer_name') <p class="mt-1 text-[12px] text-salebadge">{{ $message }}</p> @enderror
            </div>
            <div>
              <label class="mb-1.5 block text-[13px] font-medium text-heading" for="customer_email">Email</label>
              <input class="w-full border border-line-strong bg-white px-4 py-3 text-[14px] outline-none transition-colors placeholder:text-muted focus:border-heading" id="customer_email" name="customer_email" type="email" value="{{ old('customer_email') }}" required>
              @error('customer_email') <p class="mt-1 text-[12px] text-salebadge">{{ $message }}</p> @enderror
            </div>
          </div>

          <div class="mb-3.5">
            <label class="mb-1.5 block text-[13px] font-medium text-heading" for="rating">Rating</label>
            <select class="w-full border border-line-strong bg-white px-4 py-3 text-[14px] outline-none transition-colors focus:border-heading" id="rating" name="rating" required>
              <option value="">Select a rating</option>
              @for($i = 5; $i >= 1; $i--)
                <option value="{{ $i }}" {{ old('rating') == $i ? 'selected' : '' }}>{{ $i }} star{{ $i === 1 ? '' : 's' }}</option>
              @endfor
            </select>
            @error('rating') <p class="mt-1 text-[12px] text-salebadge">{{ $message }}</p> @enderror
          </div>

          <div class="mb-3.5">
            <label class="mb-1.5 block text-[13px] font-medium text-heading" for="title">Title (optional)</label>
            <input class="w-full border border-line-strong bg-white px-4 py-3 text-[14px] outline-none transition-colors placeholder:text-muted focus:border-heading" id="title" name="title" type="text" value="{{ old('title') }}">
            @error('title') <p class="mt-1 text-[12px] text-salebadge">{{ $message }}</p> @enderror
          </div>

          <div class="mb-3.5">
            <label class="mb-1.5 block text-[13px] font-medium text-heading" for="body">Review</label>
            <textarea class="w-full border border-line-strong bg-white px-4 py-3 text-[14px] outline-none transition-colors placeholder:text-muted focus:border-heading" id="body" name="body" rows="4" required>{{ old('body') }}</textarea>
            @error('body') <p class="mt-1 text-[12px] text-salebadge">{{ $message }}</p> @enderror
          </div>

          <div class="mb-5">
            <label class="mb-1.5 block text-[13px] font-medium text-heading" for="photos">Photos (optional, up to 3)</label>
            <input class="w-full border border-line-strong bg-white px-4 py-3 text-[13px] outline-none transition-colors focus:border-heading" id="photos" name="photos[]" type="file" accept="image/*" multiple>
            @error('photos') <p class="mt-1 text-[12px] text-salebadge">{{ $message }}</p> @enderror
            @error('photos.*') <p class="mt-1 text-[12px] text-salebadge">{{ $message }}</p> @enderror
          </div>

          <button class="inline-flex items-center justify-center gap-2 border border-accent bg-accent px-8 py-[13px] text-[13px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:border-accent-dark hover:bg-accent-dark" type="submit">
            Submit Review
          </button>
        </form>
      </details>
    </div>
  </section>

@endsection

@push('scripts')
  <script>
    (function () {
      // Hover-to-zoom: a lens follows the cursor over the main image, and a
      // magnified crop renders in a side panel — same interaction as estele.co's
      // PDP gallery. Desktop-only (min-width 1024px + hover-capable pointer);
      // the CSS media query above is a second guard in case JS resolves this
      // before layout settles.
      var frame = document.getElementById('pdp-zoom-frame');
      var mainImg = document.getElementById('pdp-main-img');
      var lens = document.getElementById('pdp-zoom-lens');
      var pane = document.getElementById('pdp-zoom-pane');
      var canZoom = frame && mainImg && lens && pane;
      var hoverCapable = window.matchMedia && window.matchMedia('(hover: hover) and (pointer: fine)').matches;
      var ZOOM = 2.4;

      function syncPaneImage() {
        pane.style.backgroundImage = 'url("' + (mainImg.currentSrc || mainImg.src) + '")';
      }

      if (canZoom) {
        syncPaneImage();
        mainImg.addEventListener('load', syncPaneImage);

        frame.addEventListener('mousemove', function (e) {
          if (!hoverCapable || window.innerWidth < 1024) return;
          var rect = frame.getBoundingClientRect();
          var x = e.clientX - rect.left;
          var y = e.clientY - rect.top;
          if (x < 0 || y < 0 || x > rect.width || y > rect.height) return;

          var lensW = rect.width / ZOOM;
          var lensH = rect.height / ZOOM;
          var lx = Math.min(Math.max(x - lensW / 2, 0), rect.width - lensW);
          var ly = Math.min(Math.max(y - lensH / 2, 0), rect.height - lensH);

          lens.style.width = lensW + 'px';
          lens.style.height = lensH + 'px';
          lens.style.left = lx + 'px';
          lens.style.top = ly + 'px';
          lens.style.display = 'block';
          pane.style.display = 'block';

          var bgX = rect.width - lensW > 0 ? (lx / (rect.width - lensW)) * 100 : 0;
          var bgY = rect.height - lensH > 0 ? (ly / (rect.height - lensH)) * 100 : 0;
          pane.style.backgroundSize = (ZOOM * 100) + '%';
          pane.style.backgroundPosition = bgX + '% ' + bgY + '%';
        });

        frame.addEventListener('mouseleave', function () {
          lens.style.display = 'none';
          pane.style.display = 'none';
        });
      }

      // Expand icon -> full-screen lightbox of the current main image.
      var expandBtn = document.getElementById('pdp-expand-btn');
      var lightbox = document.getElementById('pdp-lightbox');
      var lightboxImg = document.getElementById('pdp-lightbox-img');
      var lightboxClose = lightbox && lightbox.querySelector('[data-lightbox-close]');

      if (expandBtn && lightbox && lightboxImg && mainImg) {
        function openLightbox() {
          lightboxImg.src = mainImg.currentSrc || mainImg.src;
          lightboxImg.alt = mainImg.alt;
          lightbox.hidden = false;
          document.body.style.overflow = 'hidden';
        }
        function closeLightbox() {
          lightbox.hidden = true;
          document.body.style.overflow = '';
        }
        expandBtn.addEventListener('click', openLightbox);
        if (lightboxClose) lightboxClose.addEventListener('click', closeLightbox);
        lightbox.addEventListener('click', function (e) {
          if (e.target === lightbox) closeLightbox();
        });
        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && !lightbox.hidden) closeLightbox();
        });
      }

      // Share icon -> native share sheet where available, else copy the link.
      var shareBtn = document.getElementById('pdp-share-btn');
      if (shareBtn) {
        shareBtn.addEventListener('click', function () {
          var shareData = { title: document.title, url: window.location.href };
          if (navigator.share) {
            navigator.share(shareData).catch(function () {});
            return;
          }
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(shareData.url).then(function () {
              var tip = document.createElement('span');
              tip.textContent = 'Link copied';
              tip.className = 'pdp-share-tip';
              shareBtn.style.position = 'relative';
              shareBtn.appendChild(tip);
              setTimeout(function () { tip.remove(); }, 1600);
            }).catch(function () {});
          }
        });
      }
    })();
  </script>
@endpush
