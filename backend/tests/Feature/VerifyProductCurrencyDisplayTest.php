<?php

namespace Tests\Feature;

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Product prices display in ₹ (INR), not $ — the admin Products table and
 * form previously defaulted to USD formatting/prefix.
 */
class VerifyProductCurrencyDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_table_formats_price_as_inr(): void
    {
        $this->seed(ShieldSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $this->actingAs($user);

        Product::create([
            'title' => 'Currency Test Product',
            'slug' => 'currency-test-product',
            'sku' => 'SKU-CURRENCY',
            'price' => 1234.50,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        Livewire::test(ListProducts::class)
            ->assertSee('₹1,234.50')
            ->assertDontSee('$1,234.50');
    }
}
