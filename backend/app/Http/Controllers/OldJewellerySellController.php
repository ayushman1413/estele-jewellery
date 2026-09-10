<?php

namespace App\Http\Controllers;

use App\Http\Requests\Api\StoreOldJewelleryRequestRequest;
use App\Models\OldJewelleryRequest;
use App\Services\OldJewellery\OldJewelleryRequestService;
use App\Services\OldJewellery\VendorInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OldJewellerySellController extends Controller
{
    public function __construct(
        private readonly OldJewelleryRequestService $requestService,
        private readonly VendorInvitationService $invitationService,
    ) {}

    public function landing(): View
    {
        return view('account.sell-jewellery.landing');
    }

    public function create(): View
    {
        return view('account.sell-jewellery.create');
    }

    public function store(StoreOldJewelleryRequestRequest $request): RedirectResponse
    {
        $oldJewelleryRequest = $this->requestService->create(
            $request->user(),
            ['description' => $request->validated('description')],
            $request->file('image'),
            $request->file('video'),
        );

        $this->invitationService->inviteAll($oldJewelleryRequest->fresh());

        return redirect()
            ->route('account.sell-jewellery.show', $oldJewelleryRequest->fresh())
            ->with('success', 'Your request has been submitted — vendors are being notified now.');
    }

    public function index(): View
    {
        $requests = Auth::user()->oldJewelleryRequests()->latest()->paginate(10);

        return view('account.sell-jewellery.index', ['requests' => $requests]);
    }

    public function show(OldJewelleryRequest $oldJewelleryRequest): View
    {
        Gate::authorize('view', $oldJewelleryRequest);

        return view('account.sell-jewellery.show', ['oldJewelleryRequest' => $oldJewelleryRequest]);
    }

    public function status(OldJewelleryRequest $oldJewelleryRequest): \Illuminate\Http\JsonResponse
    {
        Gate::authorize('view', $oldJewelleryRequest);

        return response()->json([
            'success' => true,
            'data' => [
                'request_number' => $oldJewelleryRequest->request_number,
                'status' => $oldJewelleryRequest->status,
                'bidding_end_at' => $oldJewelleryRequest->bidding_end_at?->toIso8601String(),
            ],
        ]);
    }

    public function wallet(): View
    {
        $user = Auth::user();

        return view('account.sell-jewellery.wallet', [
            'balance' => $user->wallet_balance,
            'transactions' => $user->walletTransactions()->paginate(20),
            'credits' => $user->oldJewelleryWalletCredits()->with('request')->latest()->paginate(20),
        ]);
    }
}
