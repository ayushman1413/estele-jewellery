@props(['block', 'categories'])

@if($categories->isNotEmpty())
  <section class="bg-ivory py-6 md:py-9">
    <div class="mx-auto w-full max-w-wrapper px-3 md:px-4">
      <x-section-header eyebrow="Curated Selections" :title="$block->title ?: 'Shop by Category'" :subtitle="$block->subtitle" :cta-label="$block->cta_label" :cta-url="$block->cta_url" />
      <div class="relative" data-carousel>
        <button class="absolute -left-1 top-1/2 z-[3] grid h-7 w-7 -translate-y-1/2 place-items-center rounded-full border border-line bg-white text-heading shadow-sm transition-colors hover:border-rose hover:bg-rose hover:text-white disabled:pointer-events-none disabled:opacity-0 md:-left-2 md:h-10 md:w-10 xl:-left-[18px]" type="button" data-carousel-prev aria-label="Previous">
          <svg class="h-3 w-3 md:h-4 md:w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg>
        </button>
        <div class="carousel-track gap-2 sm:gap-2.5 md:gap-3 lg:gap-4 xl:gap-5" data-carousel-track>
          @foreach($categories as $category)
            {{-- Circles are browse targets, not products, so they run denser
                 than the product cards below them at every width. --}}
            <div class="flex-[0_0_calc((100%-3*8px)/4)] sm:flex-[0_0_calc((100%-3*10px)/4)] md:flex-[0_0_calc((100%-4*12px)/5)] lg:flex-[0_0_calc((100%-6*16px)/7)] xl:flex-[0_0_calc((100%-6*20px)/7)] 2xl:flex-[0_0_calc((100%-6*20px)/7)]">
              <x-collection-tile :category="$category" />
            </div>
          @endforeach
        </div>
        <button class="absolute -right-1 top-1/2 z-[3] grid h-7 w-7 -translate-y-1/2 place-items-center rounded-full border border-line bg-white text-heading shadow-sm transition-colors hover:border-rose hover:bg-rose hover:text-white disabled:pointer-events-none disabled:opacity-0 md:-right-2 md:h-10 md:w-10 xl:-right-[18px]" type="button" data-carousel-next aria-label="Next">
          <svg class="h-3 w-3 md:h-4 md:w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
        </button>
      </div>
    </div>
  </section>
@endif
