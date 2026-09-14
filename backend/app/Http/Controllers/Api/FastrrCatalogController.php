<?php

namespace App\Http\Controllers\Api;

use App\Models\Collection;
use App\Models\Product;
use App\Services\Payment\FastrrCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The three catalogue feeds Fastrr reads to know what it may sell: every
 * product, every collection, and the products inside one collection. The
 * shape follows Shopify's product JSON, which is the model Fastrr is built
 * around; a product with no variants is exposed as a single variant whose
 * id is the product id (see FastrrCheckoutService::variantIdFor).
 */
class FastrrCatalogController extends ApiController
{
    public function __construct(private readonly FastrrCheckoutService $fastrr) {}

    public function products(Request $request): JsonResponse
    {
        $page = Product::query()
            ->where('is_active', true)
            ->with(['variants', 'media'])
            ->when($request->integer('since_id') > 0, fn ($q) => $q->where('id', '>', $request->integer('since_id')))
            ->orderBy('id')
            ->paginate(min(max($request->integer('limit', 50), 1), 250));

        return response()->json([
            'products' => $page->getCollection()->map(fn (Product $p) => $this->product($p))->values(),
            'page' => $page->currentPage(),
            'total_pages' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }

    public function collections(Request $request): JsonResponse
    {
        $collections = Collection::query()->active()->orderBy('sort_order')->orderBy('name')->get();

        return response()->json([
            'collections' => $collections->map(fn (Collection $c) => [
                'id' => $c->id,
                'title' => $c->name,
                'handle' => $c->slug,
                'body_html' => $c->description,
                'updated_at' => $c->updated_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function collectionProducts(Request $request, Collection $collection): JsonResponse
    {
        $page = $collection->products()
            ->where('is_active', true)
            ->with(['variants', 'media'])
            ->orderBy('products.id')
            ->paginate(min(max($request->integer('limit', 50), 1), 250));

        return response()->json([
            'collection' => ['id' => $collection->id, 'title' => $collection->name, 'handle' => $collection->slug],
            'products' => $page->getCollection()->map(fn (Product $p) => $this->product($p))->values(),
            'page' => $page->currentPage(),
            'total_pages' => $page->lastPage(),
            'total' => $page->total(),
        ]);
    }

    private function product(Product $product): array
    {
        $images = $product->getMedia('gallery')->map(fn ($m) => ['id' => $m->id, 'src' => $m->getUrl('detail')])->values();

        $variants = $product->variants->isEmpty()
            ? collect([[
                'id' => $this->fastrr->variantIdFor($product, null),
                'product_id' => $product->id,
                'title' => 'Default',
                'sku' => $product->sku,
                'price' => number_format((float) $product->price, 2, '.', ''),
                'compare_at_price' => $product->compare_at_price ? number_format((float) $product->compare_at_price, 2, '.', '') : null,
                'inventory_quantity' => (int) $product->stock_quantity,
                'available' => $product->stock_quantity > 0,
                'requires_shipping' => true,
                'taxable' => true,
                'weight' => 0,
                'weight_unit' => 'g',
            ]])
            : $product->variants->map(fn ($v) => [
                'id' => $this->fastrr->variantIdFor($product, $v),
                'product_id' => $product->id,
                'title' => collect($v->attributes ?? [])->values()->implode(' / ') ?: ($v->sku ?? 'Default'),
                'sku' => $v->sku,
                'price' => number_format((float) $v->price, 2, '.', ''),
                'compare_at_price' => $product->compare_at_price ? number_format((float) $product->compare_at_price, 2, '.', '') : null,
                'inventory_quantity' => (int) $v->stock_quantity,
                'available' => $v->stock_quantity > 0,
                'requires_shipping' => true,
                'taxable' => true,
                'weight' => 0,
                'weight_unit' => 'g',
                'options' => (object) ($v->attributes ?? []),
            ])->values();

        return [
            'id' => $product->id,
            'title' => $product->title,
            'handle' => $product->slug,
            'body_html' => $product->description,
            'vendor' => config('app.name'),
            'product_type' => 'Jewellery',
            'status' => 'active',
            'url' => route('products.show', $product),
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
            'image' => $images->first(),
            'images' => $images,
            'variants' => $variants,
        ];
    }
}
