<?php

namespace App\Http\Controllers\Api;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Payment\FastrrCheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Receives Fastrr's order webhook once a shopper has paid inside the iframe
 * and records the order here so it shows up in the admin, the customer's
 * account and stock. The payload is mapped defensively (several key names
 * are tried per field) because the exact schema lives in Shiprocket's
 * dashboard docs; anything we could not map is still kept verbatim in
 * admin_notes so no order is ever lost.
 */
class FastrrWebhookController extends ApiController
{
    public function __construct(private readonly FastrrCheckoutService $fastrr) {}

    public function order(Request $request): JsonResponse
    {
        $payload = $request->all();
        $data = data_get($payload, 'order', data_get($payload, 'data', $payload));

        $reference = (string) (data_get($data, 'order_id') ?? data_get($data, 'id') ?? data_get($data, 'order_number') ?? data_get($data, 'cart_id') ?? '');

        if ($reference === '') {
            Log::warning('Fastrr order webhook without an order id', ['payload' => $payload]);

            return $this->error('Missing order id.', status: 422);
        }

        if ($existing = Order::where('payment_method', 'fastrr')->where('payment_reference', $reference)->first()) {
            return $this->success(['order_number' => $existing->order_number], 'Already recorded.');
        }

        $lines = collect(data_get($data, 'line_items') ?? data_get($data, 'items') ?? data_get($data, 'cart_items') ?? data_get($data, 'products') ?? []);

        if ($lines->isEmpty()) {
            Log::warning('Fastrr order webhook without line items', ['reference' => $reference]);

            return $this->error('No line items.', status: 422);
        }

        $customer = data_get($data, 'customer', []);
        $shipping = data_get($data, 'shipping_address') ?? data_get($data, 'address') ?? data_get($data, 'shipping') ?? [];

        $email = $this->first($customer, ['email']) ?? $this->first($data, ['email', 'customer_email']);
        $phone = $this->first($shipping, ['phone', 'mobile']) ?? $this->first($customer, ['phone', 'mobile']) ?? $this->first($data, ['phone', 'customer_phone']);
        $name = trim(($this->first($shipping, ['name', 'full_name']) ?? $this->first($customer, ['name', 'full_name'])
            ?? trim(($this->first($customer, ['first_name']) ?? '').' '.($this->first($customer, ['last_name']) ?? ''))) ?: 'Customer');

        $user = $email ? User::where('email', $email)->first() : null;
        $user ??= $phone ? User::where('phone', $phone)->first() : null;

        $paymentStatus = strtolower((string) ($this->first($data, ['payment_status', 'financial_status', 'status']) ?? ''));
        $paid = in_array($paymentStatus, ['paid', 'success', 'successful', 'captured', 'completed', 'prepaid'], true);
        $isCod = str_contains(strtolower((string) ($this->first($data, ['payment_mode', 'payment_method', 'payment_type']) ?? '')), 'cod');

        try {
            $order = DB::transaction(function () use ($data, $lines, $reference, $user, $email, $phone, $name, $shipping, $paid, $isCod, $payload) {
                $order = Order::create([
                    'user_id' => $user?->id,
                    'order_number' => $this->orderNumber(),
                    'customer_name' => $name,
                    'customer_email' => $email ?? $user?->email ?? 'unknown@fastrr.local',
                    'customer_phone' => $phone ?? $user?->phone ?? '',
                    'shipping_address_line1' => $this->first($shipping, ['address1', 'line1', 'address_line1', 'address']) ?? '',
                    'shipping_address_line2' => $this->first($shipping, ['address2', 'line2', 'address_line2', 'landmark']),
                    'shipping_city' => $this->first($shipping, ['city']) ?? '',
                    'shipping_state' => $this->first($shipping, ['state', 'province']) ?? '',
                    'shipping_postal_code' => $this->first($shipping, ['pincode', 'zip', 'postal_code', 'zipcode']) ?? '',
                    'shipping_country' => 'India',
                    'order_note' => $this->first($data, ['note', 'order_note']),
                    'subtotal' => 0,
                    'discount_amount' => (float) ($this->first($data, ['discount', 'discount_amount', 'total_discounts']) ?? 0),
                    'shipping_fee' => (float) ($this->first($data, ['shipping_charges', 'shipping_fee', 'shipping_amount', 'total_shipping']) ?? 0),
                    'total' => 0,
                    'coupon_code' => $this->first($data, ['coupon_code', 'discount_code']),
                    'payment_method' => 'fastrr',
                    'payment_status' => $paid && ! $isCod ? 'paid' : 'pending',
                    'payment_reference' => $reference,
                    'status' => 'placed',
                    'admin_notes' => 'Shiprocket Checkout '.($isCod ? '(COD)' : '(prepaid)').' — raw webhook: '.Str::limit(json_encode($payload, JSON_UNESCAPED_SLASHES), 4000),
                ]);

                $subtotal = 0.0;

                foreach ($lines as $line) {
                    $variantId = $this->first($line, ['variant_id', 'variantId', 'sku_id', 'id']);
                    $quantity = max(1, (int) ($this->first($line, ['quantity', 'qty']) ?? 1));
                    $resolved = $variantId !== null ? $this->fastrr->resolveVariant($variantId) : null;

                    [$product, $variant] = $resolved ?? [null, null];

                    $price = (float) ($this->first($line, ['price', 'unit_price', 'selling_price']) ?? $variant?->price ?? $product?->price ?? 0);
                    $subtotal += $price * $quantity;

                    $order->items()->create([
                        'product_id' => $product?->id,
                        'product_variant_id' => $variant?->id,
                        'product_title' => $this->first($line, ['title', 'name', 'product_name']) ?? $product?->title ?? "Item {$variantId}",
                        'sku' => $this->first($line, ['sku']) ?? $variant?->sku ?? $product?->sku,
                        'price' => $price,
                        'quantity' => $quantity,
                        'subtotal' => $price * $quantity,
                    ]);

                    if ($variant) {
                        ProductVariant::whereKey($variant->id)->where('stock_quantity', '>=', $quantity)->decrement('stock_quantity', $quantity);
                    } elseif ($product) {
                        Product::whereKey($product->id)->where('stock_quantity', '>=', $quantity)->decrement('stock_quantity', $quantity);
                    }
                }

                $total = (float) ($this->first($data, ['total', 'total_price', 'order_total', 'grand_total', 'amount']) ?? ($subtotal - $order->discount_amount + $order->shipping_fee));

                $order->update(['subtotal' => $subtotal, 'total' => max($total, 0)]);

                return $order;
            });
        } catch (\Throwable $e) {
            report($e);
            Log::error('Fastrr order webhook could not be recorded', ['reference' => $reference, 'error' => $e->getMessage()]);

            return $this->error('Could not record order.', status: 500);
        }

        return $this->success(['order_number' => $order->order_number], 'Order recorded.', 201);
    }

    private function first(mixed $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = data_get($source, $key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function orderNumber(): string
    {
        do {
            $number = 'ORD-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
        } while (Order::where('order_number', $number)->exists());

        return $number;
    }
}
