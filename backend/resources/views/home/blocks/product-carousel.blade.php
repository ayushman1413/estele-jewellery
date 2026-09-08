@props(['block'])

@php $products = $block->items->pluck('itemable')->filter(); @endphp

@if($products->isNotEmpty())
  <section class="py-6 md:py-9">
    <div class="mx-auto w-full max-w-wrapper px-3 md:px-4">
      <x-section-header align="left" :eyebrow="$block->subtitle ?: 'Handpicked for you'" :title="$block->title ?: 'Bestsellers'" :cta-label="$block->cta_label ?: 'View all'" :cta-url="$block->cta_url ?: route('categories.index')" />
      <div @if($products->count() > 8) data-explore @endif>
        <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 md:grid-cols-4 md:gap-4 lg:grid-cols-5 xl:gap-5 {{ $products->count() > 8 ? 'explore-grid-4row' : '' }}" @if($products->count() > 8) data-explore-grid @endif>
          @foreach($products->take(20) as $product)
            <x-product-card :product="$product" />
          @endforeach
        </div>
        @if($products->count() > 8)
          <div class="mt-5 text-center md:mt-7">
            <button type="button" class="inline-flex items-center border-b border-gold pb-1 text-[12px] font-medium uppercase tracking-[0.14em] text-heading transition-colors hover:text-gold" data-explore-toggle data-more-label="Explore more" data-less-label="Show less" aria-expanded="false">Explore more</button>
          </div>
        @endif
      </div>
    </div>
  </section>
@endif
