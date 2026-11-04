<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $order->order_number }}</title>
    <style>
        {{-- DejaVu Sans (dompdf's bundled Unicode font) — Helvetica/Arial can't render ₹. --}}
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #222; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .muted { color: #666; }
        .header { overflow: hidden; margin-bottom: 24px; }
        .header .brand { float: left; }
        .header .meta { float: right; text-align: right; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        th, td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f5f5f5; text-transform: uppercase; font-size: 10px; letter-spacing: 0.5px; }
        .text-right { text-align: right; }
        .totals td { border: none; padding: 3px 8px; }
        .totals .label { text-align: right; color: #666; }
        .totals .grand td { border-top: 1px solid #333; font-weight: bold; font-size: 14px; padding-top: 8px; }
        .addr { margin-bottom: 24px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="brand">
            <h1>{{ $siteName ?? 'Estele' }}</h1>
            <p class="muted">Invoice</p>
        </div>
        <div class="meta">
            <p><strong>Order #:</strong> {{ $order->order_number }}</p>
            <p><strong>Date:</strong> {{ $order->created_at->format('d M Y') }}</p>
            <p><strong>Payment:</strong> {{ strtoupper($order->payment_method) }} ({{ ucfirst($order->payment_status) }})</p>
        </div>
    </div>

    <div class="addr">
        <strong>Billed / Shipped to</strong><br>
        {{ $order->customer_name }}<br>
        {{ $order->shipping_address_line1 }}{{ $order->shipping_address_line2 ? ', '.$order->shipping_address_line2 : '' }}<br>
        {{ $order->shipping_city }}, {{ $order->shipping_state }} {{ $order->shipping_postal_code }}<br>
        {{ $order->shipping_country }}<br>
        {{ $order->customer_email }} &middot; {{ $order->customer_phone }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th>SKU</th>
                <th class="text-right">Qty</th>
                <th class="text-right">Price</th>
                <th class="text-right">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
                <tr>
                    <td>{{ $item->product_title }}</td>
                    <td>{{ $item->sku }}</td>
                    <td class="text-right">{{ $item->quantity }}</td>
                    <td class="text-right">₹{{ number_format($item->price, 2) }}</td>
                    <td class="text-right">₹{{ number_format($item->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals" style="width: 260px; margin-left: auto;">
        <tr>
            <td class="label">Subtotal</td>
            <td class="text-right">₹{{ number_format($order->subtotal, 2) }}</td>
        </tr>
        @if($order->discount_amount > 0)
            <tr>
                <td class="label">Discount{{ $order->coupon_code ? " ({$order->coupon_code})" : '' }}</td>
                <td class="text-right">&minus; ₹{{ number_format($order->discount_amount, 2) }}</td>
            </tr>
        @endif
        <tr>
            <td class="label">Shipping</td>
            <td class="text-right">₹{{ number_format($order->shipping_fee, 2) }}</td>
        </tr>
        <tr class="grand">
            <td class="label">Total</td>
            <td class="text-right">₹{{ number_format($order->total, 2) }}</td>
        </tr>
        @if($order->wallet_amount_used > 0)
            <tr>
                <td class="label">Wallet Used</td>
                <td class="text-right">&minus; ₹{{ number_format($order->wallet_amount_used, 2) }}</td>
            </tr>
            <tr class="grand">
                <td class="label">Net Payable</td>
                <td class="text-right">₹{{ number_format($order->total - $order->wallet_amount_used, 2) }}</td>
            </tr>
        @endif
        @if($order->refunded_amount > 0)
            <tr>
                <td class="label">Refunded</td>
                <td class="text-right">&minus; ₹{{ number_format($order->refunded_amount, 2) }}</td>
            </tr>
        @endif
    </table>

    @if($order->order_note)
        <p class="muted"><strong>Order note:</strong> {{ $order->order_note }}</p>
    @endif
</body>
</html>
