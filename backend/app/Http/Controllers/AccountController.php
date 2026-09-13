<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $orders = $user->orders()->with('items')->paginate(10);

        // One grouped query rather than a count per tile — the overview shows
        // four status tiles and every status not returned is simply zero.
        $statusCounts = $user->orders()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('account.index', [
            'orders' => $orders,
            'statusCounts' => $statusCounts,
            'walletBalance' => $user->wallet_balance,
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $user->update($validated);

        return redirect()->route('account.index')->with('success', 'Profile updated.');
    }

    public function orderShow(Order $order)
    {
        $this->authorizeOwnOrder($order);

        $order->load('items');

        return view('account.order-show', ['order' => $order]);
    }

    public function orderInvoice(Order $order)
    {
        $this->authorizeOwnOrder($order);

        $order->load('items');

        return response()->streamDownload(function () use ($order) {
            echo Pdf::loadView('orders.invoice', ['order' => $order])->output();
        }, "invoice-{$order->order_number}.pdf", ['Content-Type' => 'application/pdf']);
    }

    public function requestCancellation(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOwnOrder($order);

        abort_unless($order->canRequestCancellation(), 422);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        // Deliberately not touching `status` here — that's guarded by
        // Order::ALLOWED_TRANSITIONS and only an admin action should move it.
        // This just flags the order; see OrdersTable for how admins see/action it.
        $order->update([
            'cancellation_requested_at' => now(),
            'cancellation_reason' => $validated['reason'],
        ]);

        return redirect()->route('account.orders.show', $order)
            ->with('success', 'Your request has been sent — our team will review it shortly.');
    }

    public function addresses()
    {
        return view('account.addresses', ['addresses' => Auth::user()->addresses]);
    }

    public function addressStore(Request $request): RedirectResponse
    {
        $validated = $this->validateAddress($request);

        $address = Auth::user()->addresses()->create($validated);

        if ($address->is_default) {
            $this->clearOtherDefaults($address);
        }

        if ($request->session()->pull('express_checkout_needs_address')) {
            return redirect()->route('checkout.express')->with('success', 'Address saved — continuing to payment.');
        }

        return redirect()->route('account.addresses')->with('success', 'Address added.');
    }

    public function addressUpdate(Request $request, Address $address): RedirectResponse
    {
        $this->authorizeOwnAddress($address);

        $validated = $this->validateAddress($request);
        $address->update($validated);

        if ($address->is_default) {
            $this->clearOtherDefaults($address);
        }

        return redirect()->route('account.addresses')->with('success', 'Address updated.');
    }

    public function addressDestroy(Address $address): RedirectResponse
    {
        $this->authorizeOwnAddress($address);

        $address->delete();

        return redirect()->route('account.addresses')->with('success', 'Address removed.');
    }

    private function validateAddress(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:50'],
            'line1' => ['required', 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'postal_code' => ['required', 'digits:6'],
            'country' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'digits_between:10,15'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }

    private function clearOtherDefaults(Address $keep): void
    {
        Auth::user()->addresses()->where('id', '!=', $keep->id)->update(['is_default' => false]);
    }

    private function authorizeOwnOrder(Order $order): void
    {
        abort_unless($order->user_id === Auth::id(), 404);
    }

    private function authorizeOwnAddress(Address $address): void
    {
        abort_unless($address->user_id === Auth::id(), 404);
    }
}
