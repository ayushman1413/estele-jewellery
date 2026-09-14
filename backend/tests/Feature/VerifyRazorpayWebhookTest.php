<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PaymentController::webhook() is the durable server-to-server confirmation
 * path — it must reject unsigned/forged payloads, apply real events, and
 * never let a replayed or out-of-order event corrupt an already-settled
 * order (a payment.failed arriving after payment.captured, or the same
 * captured event delivered twice, both of which Razorpay's own retry policy
 * makes routine, not hypothetical).
 */
class VerifyRazorpayWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_captured_event_marks_the_order_paid(): void
    {
        config(['services.razorpay.webhook_secret' => 'whsec_fake']);
        $order = $this->makePendingOrder('order_fake123');

        $response = $this->postSignedWebhook([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_fake456', 'order_id' => 'order_fake123']]],
        ]);

        $response->assertOk();
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('pay_fake456', $order->payment_reference);
    }

    public function test_an_unconfigured_webhook_secret_refuses_every_signature_including_one_computed_against_an_empty_key(): void
    {
        config(['services.razorpay.webhook_secret' => null]);
        $order = $this->makePendingOrder('order_fake123');

        $payload = [
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_fake456', 'order_id' => 'order_fake123']]],
        ];
        $body = json_encode($payload);

        // An attacker who notices the secret is blank can compute this
        // exact signature themselves — verifyWebhookSignature() must refuse
        // it outright rather than accepting a "valid" HMAC over an empty key.
        $forgedSignature = hash_hmac('sha256', $body, '');

        $response = $this->call('POST', route('webhooks.razorpay'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Razorpay-Signature' => $forgedSignature,
        ], $body);

        $response->assertStatus(400);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_invalid_signature_is_rejected_and_order_untouched(): void
    {
        config(['services.razorpay.webhook_secret' => 'whsec_fake']);
        $order = $this->makePendingOrder('order_fake123');

        $body = json_encode([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_fake456', 'order_id' => 'order_fake123']]],
        ]);

        $response = $this->call('POST', route('webhooks.razorpay'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Razorpay-Signature' => 'tampered',
        ], $body);

        $response->assertStatus(400);
        $order->refresh();
        $this->assertSame('pending', $order->payment_status);
    }

    public function test_replayed_captured_event_on_an_already_paid_order_is_a_no_op(): void
    {
        config(['services.razorpay.webhook_secret' => 'whsec_fake']);
        $order = $this->makePendingOrder('order_fake123');
        $order->update(['payment_status' => 'paid', 'payment_reference' => 'pay_fake456']);

        $response = $this->postSignedWebhook([
            'event' => 'payment.captured',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_fake456', 'order_id' => 'order_fake123']]],
        ]);

        $response->assertOk();
        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
    }

    public function test_failed_event_arriving_after_paid_does_not_downgrade_the_order(): void
    {
        config(['services.razorpay.webhook_secret' => 'whsec_fake']);
        $order = $this->makePendingOrder('order_fake123');
        $order->update(['payment_status' => 'paid', 'payment_reference' => 'pay_fake456']);

        $response = $this->postSignedWebhook([
            'event' => 'payment.failed',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_fake456', 'order_id' => 'order_fake123']]],
        ]);

        $response->assertOk();
        $order->refresh();
        $this->assertSame('paid', $order->payment_status, 'A late failed event must never downgrade an already-paid order.');
    }

    public function test_failed_event_marks_a_pending_order_as_failed(): void
    {
        config(['services.razorpay.webhook_secret' => 'whsec_fake']);
        $order = $this->makePendingOrder('order_fake123');

        $response = $this->postSignedWebhook([
            'event' => 'payment.failed',
            'payload' => ['payment' => ['entity' => ['id' => 'pay_fake456', 'order_id' => 'order_fake123']]],
        ]);

        $response->assertOk();
        $order->refresh();
        $this->assertSame('failed', $order->payment_status);
    }

    private function postSignedWebhook(array $payload)
    {
        $body = json_encode($payload);
        $signature = hash_hmac('sha256', $body, 'whsec_fake');

        return $this->call('POST', route('webhooks.razorpay'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Razorpay-Signature' => $signature,
        ], $body);
    }

    private function makePendingOrder(string $razorpayOrderId): Order
    {
        return Order::create([
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
            'payment_method' => 'razorpay',
            'payment_status' => 'pending',
            'razorpay_order_id' => $razorpayOrderId,
            'status' => 'placed',
        ]);
    }
}
