<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Order {{ $order->order_number }} packed</title>
</head>
<body style="font-family: Helvetica, Arial, sans-serif; font-size: 14px; color: #222;">
    <p>Hi {{ $order->customer_name }},</p>

    <p>Your order has been packed and is ready for shipping.</p>

    <p>
        <strong>Order #:</strong> {{ $order->order_number }}<br>
        <strong>Total:</strong> &#8377;{{ number_format((float) $order->total, 2) }}
    </p>

    <p>We'll send another update once it ships.</p>

    <p>Thank you for shopping with {{ config('app.name') }}.</p>
</body>
</html>
