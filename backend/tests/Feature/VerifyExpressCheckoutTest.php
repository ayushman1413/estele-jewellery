<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Payment\FastrrCheckoutService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VerifyExpressCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.fastrr.api_key' => 'key',
            'services.fastrr.api_secret' => 'secret',
            'services.fastrr.base_url' => 'https://checkout-api.test',
            'services.fastrr.token_path' => '/api/v1/access-token',
            'services.fastrr.token_key' => 'token',
            'services.fastrr.catalog_token' => 'catalog-token',
        ]);
    }

    public function test_guest_is_sent_to_login_and_intent_survives(): void
    {
        $product = $this->makeProduct();

        $this->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('checkout.express.start', $product), ['quantity' => 2])
            ->assertRedirect(route('checkout.express'));

        $this->get(route('checkout.express'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('url.intended', route('checkout.express'))
            ->assertSessionHas('express_checkout.product_id', $product->id);
    }

    public function test_logged_in_user_without_address_is_sent_to_address_book_then_back(): void
    {
        $product = $this->makeProduct();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['express_checkout' => ['product_id' => $product->id, 'variant_id' => null, 'quantity' => 1]])
            ->get(route('checkout.express'))
            ->assertRedirect(route('account.addresses'))
            ->assertSessionHas('express_checkout_needs_address', true);

        $this->actingAs($user)
            ->withSession([
                'express_checkout' => ['product_id' => $product->id, 'variant_id' => null, 'quantity' => 1],
                'express_checkout_needs_address' => true,
            ])
            ->withoutMiddleware(ValidateCsrfToken::class)
            ->post(route('account.addresses.store'), [
                'label' => 'Home', 'line1' => '12 MG Road', 'city' => 'Pune', 'state' => 'Maharashtra',
                'postal_code' => '411001', 'country' => 'India', 'phone' => '9876543210',
            ])
            ->assertRedirect(route('checkout.express'));
    }

    public function test_user_with_address_gets_fastrr_token_page(): void
    {
        $product = $this->makeProduct();
        $user = $this->userWithAddress();

        Http::fake(['checkout-api.test/*' => Http::response(['token' => 'tok_123'])]);

        $this->actingAs($user)
            ->withSession(['express_checkout' => ['product_id' => $product->id, 'variant_id' => null, 'quantity' => 2]])
            ->get(route('checkout.express'))
            ->assertOk()
            ->assertSee('data-token="tok_123"', false)
            ->assertSee(config('services.fastrr.script_url'), false)
            ->assertSee('12 MG Road');

        Http::assertSent(function ($request) use ($product) {
            $body = json_decode($request->body(), true);
            $expected = base64_encode(hash_hmac('sha256', $request->body(), 'secret', true));

            return $request->hasHeader('X-Api-Key', 'key')
                && $request->hasHeader('X-Api-HMAC-SHA256', $expected)
                && $body['cart_data']['items'][0]['variant_id'] === (string) $product->id
                && $body['cart_data']['items'][0]['quantity'] === 2
                && $body['customer']['address']['pincode'] === '411001';
        });
    }

    public function test_token_failure_falls_back_to_native_checkout_with_cart_line(): void
    {
        $product = $this->makeProduct();
        $user = $this->userWithAddress();

        Http::fake(['checkout-api.test/*' => Http::response(['error' => 'down'], 500)]);

        $this->actingAs($user)
            ->withSession(['express_checkout' => ['product_id' => $product->id, 'variant_id' => null, 'quantity' => 3]])
            ->get(route('checkout.express'))
            ->assertRedirect(route('checkout.index'))
            ->assertSessionMissing('express_checkout');

        $this->assertSame(3, CartItem::where('product_id', $product->id)->value('quantity'));
    }

    public function test_catalog_feed_requires_credentials_and_lists_variants(): void
    {
        $product = $this->makeProduct();
        $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => 'V-1', 'price' => 1200, 'stock_quantity' => 4, 'attributes' => ['Size' => 'M']]);

        $this->getJson('/api/v1/fastrr/products')->assertStatus(401);

        $this->getJson('/api/v1/fastrr/products', ['X-Api-Key' => 'catalog-token'])
            ->assertOk()
            ->assertJsonPath('products.0.id', $product->id)
            ->assertJsonPath('products.0.variants.0.id', FastrrCheckoutService::VARIANT_OFFSET + $variant->id)
            ->assertJsonPath('products.0.variants.0.title', 'M')
            ->assertJsonPath('products.0.variants.0.inventory_quantity', 4);

        $this->getJson('/api/v1/fastrr/collections', ['X-Api-Key' => 'catalog-token'])->assertOk()->assertJsonStructure(['collections']);
    }

    public function test_order_webhook_verifies_hmac_and_records_the_order_once(): void
    {
        $product = $this->makeProduct();
        $user = $this->userWithAddress();

        $payload = [
            'order_id' => 'SR-1001',
            'payment_status' => 'paid',
            'total' => 2400,
            'customer' => ['name' => $user->name, 'email' => $user->email, 'phone' => '9876543210'],
            'shipping_address' => ['address1' => '12 MG Road', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001'],
            'line_items' => [['variant_id' => $product->id, 'quantity' => 2, 'price' => 1200]],
        ];
        $body = json_encode($payload);
        $hmac = base64_encode(hash_hmac('sha256', $body, 'secret', true));

        $this->call('POST', '/api/v1/fastrr/webhooks/order', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_API_HMAC_SHA256' => 'wrong'], $body)
            ->assertStatus(401);

        $this->call('POST', '/api/v1/fastrr/webhooks/order', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_API_HMAC_SHA256' => $hmac], $body)
            ->assertStatus(201);

        $order = Order::where('payment_reference', 'SR-1001')->firstOrFail();
        $this->assertSame('fastrr', $order->payment_method);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame($user->id, $order->user_id);
        $this->assertSame(2400.0, (float) $order->total);
        $this->assertSame(2, $order->items()->sum('quantity'));
        $this->assertSame(8, $product->fresh()->stock_quantity);

        $this->call('POST', '/api/v1/fastrr/webhooks/order', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_API_HMAC_SHA256' => $hmac], $body)
            ->assertOk();
        $this->assertSame(1, Order::count());

        $this->actingAs($user)
            ->get(route('checkout.express.complete', ['order_id' => 'SR-1001']))
            ->assertRedirect(route('checkout.confirmation', $order));
    }

    private function makeProduct(): Product
    {
        return Product::create([
            'title' => 'Express Test Ring',
            'slug' => 'express-test-ring-'.uniqid(),
            'sku' => 'SKU-EXP-'.uniqid(),
            'price' => 1200,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
    }

    private function userWithAddress(): User
    {
        $user = User::factory()->create(['phone' => '9876543210']);
        $user->addresses()->create([
            'label' => 'Home', 'line1' => '12 MG Road', 'city' => 'Pune', 'state' => 'Maharashtra',
            'postal_code' => '411001', 'country' => 'India', 'phone' => '9876543210', 'is_default' => true,
        ]);

        return $user;
    }
}
