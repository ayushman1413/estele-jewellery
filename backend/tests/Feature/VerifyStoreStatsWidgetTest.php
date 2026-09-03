<?php

namespace Tests\Feature;

use App\Filament\Widgets\StoreStatsWidget;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirms the admin dashboard's stat tiles are computed from live DB rows
 * (not hardcoded/dummy figures) — each stat here is asserted against rows
 * this test itself creates, so a hardcoded value would fail immediately.
 */
class VerifyStoreStatsWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_reflect_actual_database_rows(): void
    {
        $this->seed(ShieldSeeder::class);

        // 2 orders, one cancelled (excluded from revenue).
        $this->makeOrder(['status' => 'placed', 'total' => 1000]);
        $this->makeOrder(['status' => 'cancelled', 'total' => 500]);

        // 1 admin user (has a role, excluded from "Total Customers") + 2 plain customers.
        $admin = User::factory()->create();
        $admin->assignRole('super_admin');
        User::factory()->count(2)->create();

        // 3 reviews.
        $product = Product::create([
            'title' => 'Stat Test Product',
            'slug' => 'stat-test-product-'.uniqid(),
            'sku' => 'SKU-STAT-'.uniqid(),
            'price' => 500,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);
        Review::factory()->count(3)->create(['product_id' => $product->id]);

        $stats = (new StoreStatsWidget())->getStatsForTest();

        $byLabel = collect($stats)->keyBy(fn ($stat) => $stat->getLabel());

        $this->assertSame('2', $byLabel['Total Orders']->getValue());
        $this->assertSame('₹1,000.00', $byLabel['Total Revenue']->getValue());
        $this->assertSame('3', $byLabel['Total Reviews']->getValue());
        $this->assertSame('2', $byLabel['Total Customers']->getValue());
    }

    private function makeOrder(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'ORD-STAT-'.uniqid(),
            'customer_name' => 'Stat Test',
            'customer_email' => 'stat@example.com',
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
}
