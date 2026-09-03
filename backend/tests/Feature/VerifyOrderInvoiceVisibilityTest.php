<?php

namespace Tests\Feature;

use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\ShieldSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin-panel invoice action (table row icon + edit-page header button)
 * only appears once the order has moved past "placed" — i.e. once the admin
 * has accepted it. See Order::ALLOWED_TRANSITIONS / OrdersTable::streamInvoice.
 */
class VerifyOrderInvoiceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $this->seed(ShieldSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $this->actingAs($user);

        return $user;
    }

    public function test_invoice_action_hidden_while_order_is_only_placed(): void
    {
        $this->actingAsSuperAdmin();
        $order = $this->makeOrder(['status' => 'placed']);

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->assertActionHidden('invoice');
    }

    public function test_invoice_action_visible_once_order_is_accepted(): void
    {
        $this->actingAsSuperAdmin();
        $order = $this->makeOrder(['status' => 'accepted']);

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->assertActionVisible('invoice');
    }

    private function makeOrder(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'ORD-INV-'.uniqid(),
            'customer_name' => 'Invoice Test',
            'customer_email' => 'invoice@example.com',
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
