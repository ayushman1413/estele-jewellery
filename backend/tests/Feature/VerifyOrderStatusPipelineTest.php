<?php

namespace Tests\Feature;

use App\Mail\OrderPacked;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Order::booted()'s status-transition guard is enforced at the model level
 * (not just the admin form's disabled-options UI) specifically so a direct
 * update — API, tinker, a bulk action — can't skip or reverse the pipeline.
 * Confirms the guard itself, the restock-on-cancel/return side effect for
 * both plain products and variants, and the COD auto-paid-on-delivery rule.
 */
class VerifyOrderStatusPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowed_transition_succeeds(): void
    {
        $order = $this->makeOrder(['status' => 'placed']);

        $order->update(['status' => 'packed']);

        $this->assertSame('packed', $order->fresh()->status);
    }

    public function test_disallowed_transition_is_rejected(): void
    {
        $order = $this->makeOrder(['status' => 'placed']);

        $this->expectException(ValidationException::class);

        $order->update(['status' => 'delivered']);
    }

    public function test_placed_order_can_be_accepted_then_packed(): void
    {
        $order = $this->makeOrder(['status' => 'placed']);

        $order->update(['status' => 'accepted']);
        $this->assertSame('accepted', $order->fresh()->status);

        $order->update(['status' => 'packed']);
        $this->assertSame('packed', $order->fresh()->status);
    }

    public function test_placed_order_cannot_skip_straight_to_packed(): void
    {
        $order = $this->makeOrder(['status' => 'placed']);

        $this->expectException(ValidationException::class);

        $order->update(['status' => 'packed']);
    }

    public function test_accepting_an_order_queues_the_packed_notification_email(): void
    {
        Mail::fake();

        $order = $this->makeOrder(['status' => 'placed', 'customer_email' => 'buyer@example.com']);

        $order->update(['status' => 'accepted']);

        Mail::assertQueued(OrderPacked::class, fn (OrderPacked $mail) => $mail->order->is($order));
    }

    public function test_moving_between_other_statuses_does_not_send_the_packed_email(): void
    {
        Mail::fake();

        $order = $this->makeOrder(['status' => 'shipped']);
        $order->update(['status' => 'delivered']);

        Mail::assertNotQueued(OrderPacked::class);
    }

    public function test_terminal_status_cannot_transition_further(): void
    {
        $order = $this->makeOrder(['status' => 'cancelled']);

        $this->expectException(ValidationException::class);

        $order->update(['status' => 'packed']);
    }

    public function test_cancelling_restocks_a_plain_product(): void
    {
        $product = Product::create($this->productAttrs('restock-plain', 5));
        $order = $this->makeOrder(['status' => 'placed']);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_title' => $product->title,
            'sku' => $product->sku,
            'price' => $product->price,
            'quantity' => 3,
            'subtotal' => $product->price * 3,
        ]);

        $order->update(['status' => 'cancelled']);

        $this->assertSame(8, $product->fresh()->stock_quantity);
    }

    public function test_returning_a_delivered_order_restocks_a_variant(): void
    {
        $product = Product::create($this->productAttrs('restock-variant', 20));
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $product->sku.'-VAR',
            'price' => $product->price,
            'stock_quantity' => 5,
        ]);
        $order = $this->makeOrder(['status' => 'shipped']);
        OrderItem::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_title' => $product->title,
            'sku' => $variant->sku,
            'price' => $variant->price,
            'quantity' => 2,
            'subtotal' => $variant->price * 2,
        ]);

        $order->update(['status' => 'delivered']);
        $order->update(['status' => 'returned']);

        $this->assertSame(7, $variant->fresh()->stock_quantity);
        $this->assertSame(20, $product->fresh()->stock_quantity, 'Restock must credit the variant, not the parent product, when a variant was ordered.');
    }

    public function test_delivering_a_cod_order_marks_payment_paid(): void
    {
        $order = $this->makeOrder(['status' => 'shipped', 'payment_method' => 'cod', 'payment_status' => 'pending']);

        $order->update(['status' => 'delivered']);

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_delivering_a_razorpay_order_does_not_touch_payment_status(): void
    {
        $order = $this->makeOrder(['status' => 'shipped', 'payment_method' => 'razorpay', 'payment_status' => 'paid']);

        $order->update(['status' => 'delivered']);

        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    private function makeOrder(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'ORD-TEST-'.uniqid(),
            'customer_name' => 'Verify Test',
            'customer_email' => 'verify@example.com',
            'customer_phone' => '9999999999',
            'shipping_address_line1' => 'Test St',
            'shipping_city' => 'Hyderabad',
            'shipping_state' => 'Telangana',
            'shipping_postal_code' => '500001',
            'shipping_country' => 'India',
            'subtotal' => 500,
            'discount_amount' => 0,
            'shipping_fee' => 0,
            'total' => 500,
            'payment_method' => 'cod',
            'payment_status' => 'pending',
            'status' => 'placed',
        ], $overrides));
    }

    private function productAttrs(string $tag, int $stock): array
    {
        return [
            'title' => 'Product '.$tag,
            'slug' => 'product-'.$tag.'-'.uniqid(),
            'sku' => 'SKU-'.strtoupper($tag).'-'.uniqid(),
            'price' => 500,
            'stock_quantity' => $stock,
            'is_active' => true,
        ];
    }
}
