<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Services\Payment\FastrrCheckoutService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The product page's "Checkout" button. One line goes straight to payment:
 *
 *   guest            → login (comes back here after OTP)
 *   no saved address → address book (comes back here after saving)
 *   otherwise        → Shiprocket Fastrr iframe, prefilled, on payment
 *
 * The chosen product/variant/quantity lives in the session between hops,
 * so the shopper never re-selects anything. If Fastrr cannot be reached the
 * line is dropped into the cart and the native checkout takes over.
 */
class ExpressCheckoutController extends Controller
{
    private const SESSION_KEY = 'express_checkout';

    public function __construct(private readonly FastrrCheckoutService $fastrr) {}

    public function start(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
            'product_variant_id' => ['nullable', 'integer'],
        ]);

        if (! $product->is_active) {
            return redirect()->route('home')->with('error', 'This product is no longer available.');
        }

        $variant = ($validated['product_variant_id'] ?? null)
            ? $product->variants()->find($validated['product_variant_id'])
            : null;

        if (($validated['product_variant_id'] ?? null) && ! $variant) {
            return redirect()->route('products.show', $product)->with('error', 'Please choose a valid option.');
        }

        $quantity = (int) ($validated['quantity'] ?? 1);
        $stock = $variant?->stock_quantity ?? $product->stock_quantity;

        if ($stock < $quantity) {
            return redirect()->route('products.show', $product)->with('error', 'Not enough stock for that quantity.');
        }

        $request->session()->put(self::SESSION_KEY, [
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'quantity' => $quantity,
        ]);

        return redirect()->route('checkout.express');
    }

    public function show(Request $request)
    {
        $intent = $request->session()->get(self::SESSION_KEY);

        if (! $intent) {
            return redirect()->route('home');
        }

        $product = Product::with('variants')->find($intent['product_id']);
        $variant = $intent['variant_id'] ? $product?->variants->firstWhere('id', $intent['variant_id']) : null;

        if (! $product || ! $product->is_active) {
            $request->session()->forget(self::SESSION_KEY);

            return redirect()->route('home')->with('error', 'This product is no longer available.');
        }

        if (! auth()->check()) {
            return redirect()->guest(route('login'))->with('success', 'Log in to continue to payment.');
        }

        $user = auth()->user();
        $address = $user->addresses()->orderByDesc('is_default')->orderByDesc('id')->first();

        if (! $address) {
            $request->session()->put('express_checkout_needs_address', true);

            return redirect()->route('account.addresses')->with('success', 'Add a delivery address to continue to payment.');
        }

        $request->session()->forget('express_checkout_needs_address');

        if (! $this->fastrr->isConfigured()) {
            return $this->fallback($request, $product, $variant, (int) $intent['quantity']);
        }

        try {
            $token = $this->fastrr->accessToken(
                $product,
                $variant,
                (int) $intent['quantity'],
                $user,
                $address,
                route('checkout.express.complete'),
            );
        } catch (\Throwable $e) {
            report($e);

            return $this->fallback($request, $product, $variant, (int) $intent['quantity']);
        }

        $product->load('media');

        return view('checkout.express', [
            'token' => $token,
            'scriptUrl' => $this->fastrr->scriptUrl(),
            'fallbackUrl' => route('checkout.express.fallback'),
            'product' => $product,
            'variant' => $variant,
            'quantity' => (int) $intent['quantity'],
            'address' => $address,
            'unitPrice' => (float) ($variant?->price ?? $product->price),
        ]);
    }

    /**
     * Native checkout takes over: the line is added to the cart exactly as
     * "Buy It Now" would have, then the regular checkout page opens.
     */
    public function fallback(Request $request, ?Product $product = null, $variant = null, ?int $quantity = null): RedirectResponse
    {
        $intent = $request->session()->pull(self::SESSION_KEY);

        $product ??= $intent ? Product::find($intent['product_id']) : null;

        if (! $product) {
            return redirect()->route('cart.index');
        }

        $variantId = $variant?->id ?? ($intent['variant_id'] ?? null);
        $quantity ??= (int) ($intent['quantity'] ?? 1);

        $cart = Cart::firstOrCreate(['session_id' => $request->session()->getId()]);

        $item = $cart->items()->firstOrNew([
            'product_id' => $product->id,
            'product_variant_id' => $variantId,
        ]);
        $item->quantity = ($item->exists ? $item->quantity : 0) + $quantity;
        $item->save();

        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('checkout.index')
            ->with('success', 'Express checkout is unavailable right now — continue with our regular checkout.');
    }

    /**
     * Fastrr sends the shopper back here after payment. The order webhook
     * usually lands first; if it has not yet, the page keeps refreshing for
     * a short while before falling back to the account's order list.
     */
    public function complete(Request $request)
    {
        $request->session()->forget(self::SESSION_KEY);

        $reference = (string) ($request->query('order_id') ?? $request->query('orderId') ?? $request->query('id') ?? $request->query('order') ?? '');

        $order = null;

        if ($reference !== '') {
            $order = Order::where('payment_method', 'fastrr')->where('payment_reference', $reference)->first();
        }

        if (! $order && auth()->check()) {
            $order = Order::where('payment_method', 'fastrr')
                ->where('user_id', auth()->id())
                ->where('created_at', '>=', now()->subMinutes(30))
                ->latest()
                ->first();
        }

        if ($order) {
            return redirect()->route('checkout.confirmation', $order)->with('success', 'Payment received. Your order is confirmed.');
        }

        return view('checkout.express-complete', [
            'attempt' => (int) $request->query('attempt', 0),
            'reference' => $reference,
        ]);
    }
}
