<?php

namespace App\Models;

use App\Models\Concerns\HasSeoMeta;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Product extends Model implements HasMedia
{
    use HasSeoMeta;
    use InteractsWithMedia;
    use Searchable;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('gallery')
            ->useDisk('original_images')
            ->storeConversionsOnDisk('public');

        $this->addMediaCollection('video')
            ->useDisk('original_images')
            ->acceptsMimeTypes(['video/mp4', 'video/webm'])
            ->singleFile();
    }

    public function registerMediaConversions(?\Spatie\MediaLibrary\MediaCollections\Models\Media $media = null): void
    {
        // Sizes/quality follow the responsive image spec (§3.1).
        $this->addMediaConversion('card')->width(400)->format('webp')->quality(78);
        $this->addMediaConversion('mobile')->width(768)->format('webp')->quality(80);
        $this->addMediaConversion('tablet')->width(1024)->format('webp')->quality(82);
        $this->addMediaConversion('detail')->width(1600)->format('webp')->quality(83);
    }

    protected $fillable = [
        'title',
        'slug',
        'sku',
        'description',
        'price',
        'compare_at_price',
        'stock_quantity',
        'is_active',
        'is_featured',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function collections(): BelongsToMany
    {
        return $this->belongsToMany(Collection::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function approvedReviews(): HasMany
    {
        return $this->reviews()->where('status', 'approved');
    }

    public function reviewsCount(): int
    {
        return $this->approvedReviews()->count();
    }

    public function reviewsAverageRating(): ?float
    {
        $average = $this->approvedReviews()->avg('rating');

        return $average !== null ? round((float) $average, 1) : null;
    }

    public function searchableAs(): string
    {
        return 'products';
    }

    public function shouldBeSearchable(): bool
    {
        return $this->is_active;
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'sku' => $this->sku,
            'price' => (float) $this->price,
            'is_featured' => $this->is_featured,
            'categories' => $this->categories->pluck('name')->all(),
            // Added for the search-results filter panel (Phase 5): 'categories'
            // above is names, kept for text search; this is IDs, for exact
            // whereIn() filtering. in_stock/created_at back the "in stock only"
            // checkbox and "Newest" sort respectively. See config/scout.php's
            // filterableAttributes/sortableAttributes — these fields are inert
            // until that config is synced via `scout:sync-index-settings`.
            'category_ids' => $this->categories->pluck('id')->all(),
            // Mirrors CartController/CartItem's stock resolution: a product
            // with variants is in stock if any variant has stock, otherwise
            // fall back to the product's own stock_quantity.
            'in_stock' => $this->variants->isNotEmpty()
                ? $this->variants->contains(fn ($variant) => $variant->stock_quantity > 0)
                : $this->stock_quantity > 0,
            'created_at' => $this->created_at?->timestamp,
        ];
    }
}
