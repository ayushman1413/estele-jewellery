<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Order extends Model
{
    /**
     * Valid next-status moves. Enforced here (not just in the admin UI) so a
     * direct API/tinker/bulk-action update can't skip or reverse the pipeline.
     */
    public const ALLOWED_TRANSITIONS = [
        'placed' => ['accepted', 'cancelled'],
        'accepted' => ['packed', 'cancelled'],
        'packed' => ['shipped', 'cancelled'],
        'shipped' => ['delivered', 'returned'],
        'delivered' => ['returned'],
        'cancelled' => [],
        'returned' => [],
    ];

    public const RESTOCKING_STATUSES = ['cancelled', 'returned'];

    protected $fillable = [
        'user_id',
        'order_number',
        'customer_name',
        'customer_email',
        'customer_phone',
        'shipping_address_line1',
        'shipping_address_line2',
        'shipping_city',
        'shipping_state',
        'shipping_postal_code',
        'shipping_country',
        'order_note',
        'subtotal',
        'coupon_code',
        'discount_amount',
        'shipping_fee',
        'total',
        'payment_method',
        'payment_status',
        'payment_reference',
        'razorpay_order_id',
        'refunded_amount',
        'refund_reason',
        'status',
        'tracking_number',
        'carrier',
        'shiprocket_shipment_id',
        'shiprocket_awb_code',
        'tracking_status',
        'tracking_synced_at',
        'admin_notes',
        'cancellation_requested_at',
        'cancellation_reason',
    ];

    /**
     * Statuses a customer can still request cancellation/return from — mirrors
     * ALLOWED_TRANSITIONS but from the customer's side: once shipped, "cancel"
     * isn't offered (packed/placed only), "return" only after delivered.
     */
    public const CANCELLABLE_STATUSES = ['placed', 'accepted', 'packed'];

    public const RETURNABLE_STATUSES = ['delivered'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'total' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'tracking_synced_at' => 'datetime',
            'cancellation_requested_at' => 'datetime',
        ];
    }

    /**
     * Whether the customer can currently submit a cancellation/return request
     * — false once one is already pending (cancellation_requested_at set) or
     * the order is past the window (see CANCELLABLE_STATUSES/RETURNABLE_STATUSES).
     */
    public function canRequestCancellation(): bool
    {
        return $this->cancellation_requested_at === null
            && in_array($this->status, [...self::CANCELLABLE_STATUSES, ...self::RETURNABLE_STATUSES], true);
    }

    public function getRouteKeyName(): string
    {
        return 'order_number';
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function couponUsages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }

    protected static function booted(): void
    {
        static::updating(function (Order $order) {
            if (! $order->isDirty('status')) {
                return;
            }

            $from = $order->getOriginal('status');
            $to = $order->status;

            // Unknown legacy value on $from: don't block, just don't apply
            // the transition side effects below either.
            if (! array_key_exists($from, self::ALLOWED_TRANSITIONS)) {
                return;
            }

            if (! in_array($to, self::ALLOWED_TRANSITIONS[$from], true)) {
                throw ValidationException::withMessages([
                    'status' => "Order cannot move from \"{$from}\" to \"{$to}\".",
                ]);
            }

            if (in_array($to, self::RESTOCKING_STATUSES, true)) {
                $order->restock();
            }

            if ($to === 'delivered' && $order->payment_method === 'cod' && $order->payment_status === 'pending') {
                $order->payment_status = 'paid';
            }
        });

        // Once accepted, the customer gets the "packed and ready for shipping"
        // notice and the invoice becomes downloadable (see OrdersTable::streamInvoice
        // visibility). Sent from `updated` (after commit) so it never fires if the
        // transition guard above rejects the move.
        static::updated(function (Order $order) {
            if ($order->wasChanged('status') && $order->status === 'accepted' && filled($order->customer_email)) {
                \Illuminate\Support\Facades\Mail::to($order->customer_email)->queue(new \App\Mail\OrderPacked($order));
            }
        });
    }

    /**
     * Puts each line item's quantity back into stock. Only ever called from
     * the transition guard above, which by construction only allows this to
     * fire once per order (cancelled/returned are terminal — no further
     * status change, and thus no repeat restock, is possible afterward).
     */
    protected function restock(): void
    {
        $this->loadMissing('items.product', 'items.variant');

        foreach ($this->items as $item) {
            if ($item->variant) {
                $item->variant->increment('stock_quantity', $item->quantity);
            } elseif ($item->product) {
                $item->product->increment('stock_quantity', $item->quantity);
            }
        }
    }

    /**
     * Records a (possibly partial) refund against the order. Callers must
     * validate $amount against the remaining refundable balance themselves
     * (EditOrder's "Refund" action does this before calling in) — this
     * method only applies the already-validated amount and flips
     * payment_status accordingly.
     */
    public function applyRefund(float $amount, string $reason): void
    {
        $newRefunded = (float) $this->refunded_amount + $amount;

        $this->update([
            'refunded_amount' => $newRefunded,
            'refund_reason' => $reason,
            'payment_status' => $newRefunded >= (float) $this->total ? 'refunded' : 'partially_refunded',
        ]);
    }
}
