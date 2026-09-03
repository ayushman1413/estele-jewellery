<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reviews always land as 'pending' (moderation-gated, per Filament ReviewResource's
 * canCreate()=false — customers can only ever create, never self-approve) and
 * is_verified_purchase is derived server-side from a real non-cancelled Order
 * containing the product under the same email, never trusted from the form.
 */
class VerifyReviewSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function makeProduct(): Product
    {
        return Product::create([
            'title' => 'Rose Gold Bracelet',
            'slug' => 'rose-gold-bracelet',
            'sku' => 'RGB-001',
            'description' => 'A bracelet.',
            'price' => 999,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
    }

    private function makeOrder(Product $product, string $email, string $status = 'delivered'): Order
    {
        $order = Order::create([
            'order_number' => 'ORD-'.uniqid(),
            'customer_name' => 'Jane Doe',
            'customer_email' => $email,
            'customer_phone' => '9999999999',
            'shipping_address_line1' => '1 Main St',
            'shipping_city' => 'Mumbai',
            'shipping_state' => 'MH',
            'shipping_postal_code' => '400001',
            'shipping_country' => 'IN',
            'subtotal' => 999,
            'discount_amount' => 0,
            'shipping_fee' => 0,
            'total' => 999,
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'status' => 'placed',
        ]);

        // status is guarded by Order::ALLOWED_TRANSITIONS — walk it forward
        // instead of writing an arbitrary terminal status directly.
        $path = $status === 'cancelled'
            ? ['cancelled']
            : array_slice(['accepted', 'packed', 'shipped', 'delivered'], 0, array_search($status, ['placed', 'accepted', 'packed', 'shipped', 'delivered']));

        foreach ($path as $step) {
            $order->update(['status' => $step]);
        }

        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_title' => $product->title,
            'sku' => $product->sku,
            'price' => $product->price,
            'quantity' => 1,
            'subtotal' => $product->price,
        ]);

        return $order->fresh();
    }

    public function test_review_submission_is_pending_by_default(): void
    {
        $product = $this->makeProduct();

        $this->post(route('products.reviews.store', $product), [
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'rating' => 5,
            'body' => 'Loved it!',
        ])->assertRedirect();

        $review = Review::first();
        $this->assertNotNull($review);
        $this->assertSame('pending', $review->status);
    }

    public function test_review_from_customer_with_matching_order_is_verified(): void
    {
        $product = $this->makeProduct();
        $this->makeOrder($product, 'jane@example.com', 'delivered');

        $this->post(route('products.reviews.store', $product), [
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'rating' => 4,
            'body' => 'Good quality.',
        ]);

        $this->assertTrue(Review::first()->is_verified_purchase);
    }

    public function test_review_without_matching_order_is_not_verified(): void
    {
        $product = $this->makeProduct();

        $this->post(route('products.reviews.store', $product), [
            'customer_name' => 'Someone',
            'customer_email' => 'stranger@example.com',
            'rating' => 4,
            'body' => 'Never bought this.',
        ]);

        $this->assertFalse(Review::first()->is_verified_purchase);
    }

    public function test_review_tied_to_cancelled_order_is_not_verified(): void
    {
        $product = $this->makeProduct();
        $this->makeOrder($product, 'jane@example.com', 'cancelled');

        $this->post(route('products.reviews.store', $product), [
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'rating' => 3,
            'body' => 'Order was cancelled.',
        ]);

        $this->assertFalse(Review::first()->is_verified_purchase);
    }

    public function test_rating_outside_one_to_five_is_rejected(): void
    {
        $product = $this->makeProduct();

        $this->post(route('products.reviews.store', $product), [
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'rating' => 6,
            'body' => 'Too many stars.',
        ])->assertSessionHasErrors('rating');

        $this->assertSame(0, Review::count());
    }

    public function test_honeypot_field_silently_blocks_bots(): void
    {
        $product = $this->makeProduct();

        $this->post(route('products.reviews.store', $product), [
            'customer_name' => 'Bot',
            'customer_email' => 'bot@example.com',
            'rating' => 5,
            'body' => 'Buy my stuff',
            'website' => 'https://spam.example.com',
        ])->assertStatus(422);

        $this->assertSame(0, Review::count());
    }

    public function test_only_approved_reviews_are_shown_on_the_product_page(): void
    {
        $product = $this->makeProduct();

        Review::create([
            'product_id' => $product->id,
            'customer_name' => 'Approved Customer',
            'customer_email' => 'approved@example.com',
            'rating' => 5,
            'body' => 'Approved review body.',
            'status' => 'approved',
        ]);
        Review::create([
            'product_id' => $product->id,
            'customer_name' => 'Pending Customer',
            'customer_email' => 'pending@example.com',
            'rating' => 3,
            'body' => 'Pending review body.',
            'status' => 'pending',
        ]);

        $response = $this->get(route('products.show', $product));

        $response->assertOk();
        $response->assertSee('Approved review body.');
        $response->assertDontSee('Pending review body.');
    }

    public function test_average_rating_only_counts_approved_reviews(): void
    {
        $product = $this->makeProduct();

        Review::create([
            'product_id' => $product->id, 'customer_name' => 'A', 'customer_email' => 'a@example.com',
            'rating' => 5, 'body' => 'x', 'status' => 'approved',
        ]);
        Review::create([
            'product_id' => $product->id, 'customer_name' => 'B', 'customer_email' => 'b@example.com',
            'rating' => 1, 'body' => 'x', 'status' => 'pending',
        ]);

        $this->assertSame(1, $product->reviewsCount());
        $this->assertSame(5.0, $product->reviewsAverageRating());
    }
}
