# Sell-Jewellery Frontend + Admin Gaps Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the customer-facing "sell your jewellery" Blade pages, the public vendor bid-response page, and fill the three identified Filament admin gaps (wallet-credit visibility, vendor performance, mobile-verification display) — all as presentation/routing layers over the already-complete backend (services, policies, models, jobs).

**Architecture:** Two new plain (non-API) controllers — `OldJewellerySellController` (customer, `auth`-gated) and `VendorBidController` (public, token-gated) — call the existing `App\Services\OldJewellery\*` services directly and render new Blade views under `resources/views/account/sell-jewellery/` and `resources/views/vendor/`. Admin gaps are three additions to `app/Filament/Resources/`: one new list-only resource (`OldJewelleryWalletCreditResource`, mirroring the existing `WalletManagementResource` skeleton) and two edits to the existing `VendorResource` (a new View page + form/table field).

**Tech Stack:** Laravel 13 (Blade, no Alpine/Vue), Filament 5.7 (Schemas/Tables v5 API), Tailwind (compiled via root `npm run build`, copied into `backend/public/theme/`), Spatie Media Library (already-registered `image`/`video` collections), Spatie Permission (Shield-gated Filament resources), PHPUnit `RefreshDatabase` feature tests (`Tests\TestCase`).

**Spec:** `docs/superpowers/specs/2026-09-10-sell-jewellery-frontend-design.md`

## Global Constraints

- Video is **required** on the create-request form; image is optional. Matches `StoreOldJewelleryRequestRequest` rules exactly: `description` nullable/max 2000, `image` nullable/mimes jpg,jpeg,png,webp/max 3072KB, `video` required/mimes mp4,mov,quicktime/max 20480KB.
- Bid amount ceiling is **1,000,000** (`OldJewelleryBiddingService::MAX_BID_AMOUNT`) — client-side hints only; the service is the real enforcement and already throws `\DomainException` past this.
- Customer never sees vendor identities, individual bid amounts, or video (per `OldJewelleryRequestResource`'s deliberate exclusions) — only `image_url`, `has_video`, and the final settled amounts once available.
- Vendors have no login/session, ever. All vendor-page authorization is via the per-invitation plaintext token, never a route-model-bound ID.
- Blade controllers query `App\Services\OldJewellery\*` services and Eloquent models directly — **never** call the `Api\*` controllers or make an internal HTTP request. This is the established codebase convention (`AccountController`, `RewardSubmissionController` both do this).
- New Tailwind classes used in any new Blade view must already exist compiled in `backend/public/theme/app.css`, or must be added to the root Tailwind build and `npm run build` + copy-to-`public/theme` must run before those classes render — hand-editing `public/theme/*.css` directly does not work (see spec §5). This plan uses **only Tailwind utility classes already present in `account/addresses.blade.php` / `account/rewards.blade.php` / `checkout/index.blade.php`** (spacing, color, and typography utilities already compiled in) to avoid needing a rebuild; Task 11 explicitly verifies this.
- Status badge palette (used identically in customer views and the admin wallet-credit table) — Tailwind classes, not custom CSS:

  | Status | Classes |
  |---|---|
  | `pending`, `submitted`, `vendors_notified` | `bg-gray-100 text-gray-700` |
  | `bidding_active` | `bg-blue-100 text-blue-700` |
  | `bidding_closed`, `bid_selected`, `wallet_pending` | `bg-amber-100 text-amber-700` |
  | `wallet_credited` | `bg-green-100 text-green-700` |
  | `wallet_expired` | `bg-red-100 text-red-700` |
  | `completed` | `bg-emerald-100 text-emerald-800` |
  | `cancelled` | `bg-red-100 text-red-800` |
  | wallet-credit `active` | `bg-green-100 text-green-700` |
  | wallet-credit `partially_used` | `bg-amber-100 text-amber-700` |
  | wallet-credit `used` | `bg-gray-100 text-gray-700` |
  | wallet-credit `expired` | `bg-red-100 text-red-700` |

  All of `bg-gray-100`, `text-gray-700`, `bg-blue-100`, `text-blue-700`, `bg-amber-100`, `text-amber-700`, `bg-green-100`, `text-green-700`, `bg-red-100`, `text-red-700`/`text-red-800`, `bg-emerald-100`, `text-emerald-800` are default Tailwind palette utilities already covered by the project's `@source` globs (they compile from any Blade file the build scans, and standard palette utilities are commonly already present from elsewhere in this large storefront) — Task 11 confirms this by running the actual build.

---

## File Structure

**New files:**
- `backend/app/Http/Controllers/OldJewellerySellController.php` — customer sell-flow pages
- `backend/app/Http/Controllers/VendorBidController.php` — public vendor bid-response page
- `backend/resources/views/account/sell-jewellery/landing.blade.php`
- `backend/resources/views/account/sell-jewellery/create.blade.php`
- `backend/resources/views/account/sell-jewellery/index.blade.php`
- `backend/resources/views/account/sell-jewellery/show.blade.php`
- `backend/resources/views/account/sell-jewellery/wallet.blade.php`
- `backend/resources/views/layouts/vendor-minimal.blade.php` — standalone layout, no storefront chrome
- `backend/resources/views/vendor/old-jewellery-bid.blade.php`
- `backend/resources/views/vendor/errors/link-invalid.blade.php`
- `backend/app/Filament/Resources/OldJewelleryWalletCredits/OldJewelleryWalletCreditResource.php`
- `backend/app/Filament/Resources/OldJewelleryWalletCredits/Pages/ListOldJewelleryWalletCredits.php`
- `backend/app/Filament/Resources/OldJewelleryWalletCredits/Tables/OldJewelleryWalletCreditsTable.php`
- `backend/app/Filament/Resources/Vendors/Pages/ViewVendor.php`
- `backend/app/Filament/Resources/Vendors/Schemas/VendorInfolist.php`
- `backend/tests/Feature/OldJewellery/OldJewellerySellPageTest.php`
- `backend/tests/Feature/OldJewellery/VendorBidPageTest.php`
- `backend/tests/Feature/OldJewellery/FilamentOldJewelleryWalletCreditResourceTest.php`
- `backend/tests/Feature/OldJewellery/FilamentVendorPerformanceTest.php`

**Modified files:**
- `backend/routes/web.php` — add customer sell-jewellery routes + public vendor routes
- `backend/database/seeders/ShieldSeeder.php` — add `OldJewelleryWalletCredit` to `RESOURCES`
- `backend/app/Filament/Resources/Vendors/VendorResource.php` — register `view` page
- `backend/app/Filament/Resources/Vendors/Schemas/VendorForm.php` — add `mobile_verified_at` placeholder
- `backend/app/Filament/Resources/Vendors/Tables/VendorsTable.php` — add verified icon column + `ViewAction`

---

### Task 1: Customer sell-flow routes + controller skeleton

**Files:**
- Modify: `backend/routes/web.php` (add import + route group, inside existing `Route::middleware('auth')->group(...)` block that already contains `account.rewards.*`)
- Create: `backend/app/Http/Controllers/OldJewellerySellController.php`
- Test: `backend/tests/Feature/OldJewellery/OldJewellerySellPageTest.php`

**Interfaces:**
- Consumes: `App\Services\OldJewellery\OldJewelleryRequestService::create(User $user, array $data, ?UploadedFile $image, ?UploadedFile $video): OldJewelleryRequest`; `App\Services\OldJewellery\VendorInvitationService::inviteAll(OldJewelleryRequest $request): Collection`; `App\Http\Requests\Api\StoreOldJewelleryRequestRequest` (reused as-is, same namespace); `App\Policies\OldJewelleryRequestPolicy` (via `Gate::authorize('view', ...)`, already registered); `User::oldJewelleryRequests()`, `User::walletTransactions()`, `User::oldJewelleryWalletCredits()` (all already exist on `App\Models\User`).
- Produces: named routes `account.sell-jewellery.landing`, `account.sell-jewellery.create`, `account.sell-jewellery.store`, `account.sell-jewellery.index`, `account.sell-jewellery.show`, `account.sell-jewellery.wallet`. Views expected by later tasks: `account.sell-jewellery.landing` (no data), `account.sell-jewellery.create` (no data), `account.sell-jewellery.index` (`$requests` paginator of `OldJewelleryRequest`), `account.sell-jewellery.show` (`$oldJewelleryRequest` model, eager-loaded with nothing extra — customer view never needs bids/invitations), `account.sell-jewellery.wallet` (`$balance` float, `$transactions` paginator of `WalletTransaction`, `$credits` paginator of `OldJewelleryWalletCredit`).

- [ ] **Step 1: Write the failing test for route access + ownership**

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OldJewellerySellPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_for_landing_page(): void
    {
        $this->get(route('account.sell-jewellery.landing'))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_view_landing_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.sell-jewellery.landing'))
            ->assertOk();
    }

    public function test_authenticated_user_can_view_create_form(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('account.sell-jewellery.create'))
            ->assertOk();
    }

    public function test_owner_can_view_their_request(): void
    {
        $user = User::factory()->create();
        $request = $this->makeRequest(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('account.sell-jewellery.show', $request))
            ->assertOk()
            ->assertSee($request->request_number);
    }

    public function test_a_user_cannot_view_another_users_request(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $request = $this->makeRequest(['user_id' => $owner->id]);

        $this->actingAs($stranger)
            ->get(route('account.sell-jewellery.show', $request))
            ->assertForbidden();
    }

    public function test_index_lists_only_the_users_own_requests(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->makeRequest(['user_id' => $user->id]);
        $this->makeRequest(['user_id' => $other->id]);

        $this->actingAs($user)
            ->get(route('account.sell-jewellery.index'))
            ->assertOk()
            ->assertSee($mine->request_number);
    }

    public function test_wallet_page_shows_balance_and_credits(): void
    {
        $user = User::factory()->create(['wallet_balance' => 500]);

        $this->actingAs($user)
            ->get(route('account.sell-jewellery.wallet'))
            ->assertOk()
            ->assertSee('500');
    }

    private function makeRequest(array $overrides = []): OldJewelleryRequest
    {
        return OldJewelleryRequest::create(array_merge([
            'request_number' => 'OJ-TEST-'.uniqid(),
            'description' => 'A gold chain',
            'status' => 'bidding_active',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ], $overrides));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=OldJewellerySellPageTest`
Expected: FAIL — routes don't exist yet (`Route [account.sell-jewellery.landing] not defined.`).

- [ ] **Step 3: Add routes to `backend/routes/web.php`**

Add the import near the other `App\Http\Controllers` imports (alphabetical, after `NewsletterController`):

```php
use App\Http\Controllers\OldJewellerySellController;
```

Add inside the existing `Route::middleware('auth')->group(function () { ... })` block, after the `account.rewards.store` route:

```php
    Route::get('/account/sell-jewellery', [OldJewellerySellController::class, 'landing'])
        ->name('account.sell-jewellery.landing');

    Route::get('/account/sell-jewellery/new', [OldJewellerySellController::class, 'create'])
        ->name('account.sell-jewellery.create');

    Route::post('/account/sell-jewellery', [OldJewellerySellController::class, 'store'])
        ->name('account.sell-jewellery.store')
        ->middleware('throttle:10,60');

    Route::get('/account/sell-jewellery/requests', [OldJewellerySellController::class, 'index'])
        ->name('account.sell-jewellery.index');

    Route::get('/account/sell-jewellery/requests/{oldJewelleryRequest:request_number}', [OldJewellerySellController::class, 'show'])
        ->name('account.sell-jewellery.show');

    Route::get('/account/sell-jewellery/wallet', [OldJewellerySellController::class, 'wallet'])
        ->name('account.sell-jewellery.wallet');
```

- [ ] **Step 4: Create the controller**

```php
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
```

- [ ] **Step 5: Create placeholder views so the route tests can pass**

These are the real, final views for `landing`, `create`, `index`, `wallet` — write the full markup now since it's needed for Step 6 anyway; `show.blade.php` is written in Task 2 to keep this task's diff focused on routing/controller/list-style pages. Create `backend/resources/views/account/sell-jewellery/landing.blade.php`:

```blade
@extends('layouts.app')

@section('meta_title', 'Sell Your Jewellery | '.($siteSettings['site_name'] ?? 'Estele'))

@section('content')

  <nav class="mx-auto w-full max-w-wrapper px-3 md:px-4 flex flex-wrap items-center gap-1.5 py-4 text-[13px] text-muted" aria-label="Breadcrumb">
    <x-breadcrumb :items="[['label' => 'My Account', 'url' => route('account.index')], ['label' => 'Sell Your Jewellery']]" />
  </nav>

  <div class="mx-auto w-full max-w-wrapper px-3 pb-10 md:px-4 md:pb-[60px]">
    <div class="mb-8 rounded-lg border border-line bg-pinksoft p-6 md:p-10">
      <h1 class="mb-3 text-[22px] uppercase tracking-[0.5px] md:text-[30px]">Sell Your Old Jewellery</h1>
      <p class="max-w-2xl text-[14px] text-muted">
        Get a fair, competitive offer for your old gold and jewellery. Upload a
        photo and a short video, our trusted vendors place bids within a few
        hours, and once you accept, the amount is credited straight to your
        Estele wallet.
      </p>
      <a class="mt-6 inline-flex items-center justify-center gap-2 border border-accent bg-accent px-6 py-2.5 text-[12px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:border-accent-dark hover:bg-accent-dark" href="{{ route('account.sell-jewellery.create') }}">
        Submit Your Jewellery
      </a>
    </div>

    <h2 class="mb-4 text-[16px] uppercase tracking-[0.4px] text-heading">How It Works</h2>
    <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <div class="rounded-lg border border-line p-4">
        <div class="mb-2 text-[24px] font-medium text-accent">1</div>
        <p class="text-[13px] font-medium uppercase tracking-[0.3px] text-heading">Submit Details</p>
        <p class="mt-1 text-[13px] text-muted">Upload a photo and a short video of your jewellery, with any details you'd like to share.</p>
      </div>
      <div class="rounded-lg border border-line p-4">
        <div class="mb-2 text-[24px] font-medium text-accent">2</div>
        <p class="text-[13px] font-medium uppercase tracking-[0.3px] text-heading">Vendors Bid</p>
        <p class="mt-1 text-[13px] text-muted">Our verified vendors review your submission and place bids within a 3-hour window.</p>
      </div>
      <div class="rounded-lg border border-line p-4">
        <div class="mb-2 text-[24px] font-medium text-accent">3</div>
        <p class="text-[13px] font-medium uppercase tracking-[0.3px] text-heading">Highest Bid Wins</p>
        <p class="mt-1 text-[13px] text-muted">The best offer is automatically selected once bidding closes.</p>
      </div>
      <div class="rounded-lg border border-line p-4">
        <div class="mb-2 text-[24px] font-medium text-accent">4</div>
        <p class="text-[13px] font-medium uppercase tracking-[0.3px] text-heading">Wallet Credited</p>
        <p class="mt-1 text-[13px] text-muted">The amount (minus a small processing deduction) is credited to your wallet, valid for 10 days.</p>
      </div>
    </div>

    <div class="flex flex-wrap items-center gap-4 text-[13px]">
      <a class="font-medium text-heading underline hover:text-accent" href="{{ route('account.sell-jewellery.index') }}">View My Requests</a>
      <a class="font-medium text-heading underline hover:text-accent" href="{{ route('account.sell-jewellery.wallet') }}">My Wallet &amp; Credits</a>
    </div>
  </div>

@endsection
```

Create `backend/resources/views/account/sell-jewellery/create.blade.php`:

```blade
@extends('layouts.app')

@section('meta_title', 'Submit Your Jewellery | '.($siteSettings['site_name'] ?? 'Estele'))

@section('content')

  <nav class="mx-auto w-full max-w-wrapper px-3 md:px-4 flex flex-wrap items-center gap-1.5 py-4 text-[13px] text-muted" aria-label="Breadcrumb">
    <x-breadcrumb :items="[['label' => 'My Account', 'url' => route('account.index')], ['label' => 'Sell Your Jewellery', 'url' => route('account.sell-jewellery.landing')], ['label' => 'New Request']]" />
  </nav>

  <div class="mx-auto w-full max-w-2xl px-3 pb-10 md:px-4 md:pb-[60px]">
    <h1 class="mb-6 text-[20px] uppercase tracking-[0.5px] md:text-[26px]">Submit Your Jewellery</h1>

    @if ($errors->any())
      <div class="mb-4 rounded-lg border border-salebadge bg-red-50 p-4 text-[13px] text-salebadge">
        <ul class="list-disc space-y-1 pl-4">
          @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <form action="{{ route('account.sell-jewellery.store') }}" method="post" enctype="multipart/form-data" data-sell-jewellery-form>
      @csrf

      <div class="mb-5">
        <label class="mb-1.5 block text-[12px] font-medium uppercase tracking-[0.3px] text-heading" for="description">Description (optional)</label>
        <textarea class="w-full rounded-lg border border-line p-3 text-[14px]" id="description" name="description" rows="4" maxlength="2000">{{ old('description') }}</textarea>
      </div>

      <div class="mb-5">
        <label class="mb-1.5 block text-[12px] font-medium uppercase tracking-[0.3px] text-heading" for="image">Photo (optional, JPG/PNG/WebP, max 3MB)</label>
        <input class="w-full rounded-lg border border-line p-3 text-[13px]" type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp" data-file-preview="image">
        <img class="mt-3 hidden max-h-48 rounded-lg border border-line" data-preview-target="image" alt="Preview">
      </div>

      <div class="mb-6">
        <label class="mb-1.5 block text-[12px] font-medium uppercase tracking-[0.3px] text-heading" for="video">Video <span class="text-salebadge">*</span> (required, MP4/MOV, max 20MB)</label>
        <input class="w-full rounded-lg border border-line p-3 text-[13px]" type="file" id="video" name="video" accept=".mp4,.mov" required data-file-preview="video">
        <video class="mt-3 hidden max-h-48 w-full rounded-lg border border-line" data-preview-target="video" controls></video>
        <p class="mt-1 text-[12px] text-muted">A short video (turning the piece, showing any hallmark/stamp) helps vendors give you the best offer.</p>
      </div>

      <button class="inline-flex w-full items-center justify-center gap-2 border border-accent bg-accent px-6 py-3 text-[13px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:border-accent-dark hover:bg-accent-dark" type="submit">
        Submit Request
      </button>
    </form>
  </div>

  @push('scripts')
    <script>
      (function () {
        document.querySelectorAll('[data-file-preview]').forEach(function (input) {
          input.addEventListener('change', function () {
            var kind = input.getAttribute('data-file-preview');
            var target = document.querySelector('[data-preview-target="' + kind + '"]');
            if (!target || !input.files || !input.files[0]) return;

            var url = URL.createObjectURL(input.files[0]);
            target.src = url;
            target.classList.remove('hidden');
          });
        });
      })();
    </script>
  @endpush

@endsection
```

Create `backend/resources/views/account/sell-jewellery/index.blade.php`:

```blade
@extends('layouts.app')

@section('meta_title', 'My Sell Requests | '.($siteSettings['site_name'] ?? 'Estele'))

@section('content')

  <nav class="mx-auto w-full max-w-wrapper px-3 md:px-4 flex flex-wrap items-center gap-1.5 py-4 text-[13px] text-muted" aria-label="Breadcrumb">
    <x-breadcrumb :items="[['label' => 'My Account', 'url' => route('account.index')], ['label' => 'Sell Your Jewellery', 'url' => route('account.sell-jewellery.landing')], ['label' => 'My Requests']]" />
  </nav>

  <div class="mx-auto w-full max-w-wrapper px-3 pb-10 md:px-4 md:pb-[60px]">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-[20px] uppercase tracking-[0.5px] md:text-[26px]">My Sell Requests</h1>
      <a class="text-[12px] font-medium uppercase tracking-[0.3px] text-heading underline hover:text-accent" href="{{ route('account.sell-jewellery.create') }}">+ New Request</a>
    </div>

    @if ($requests->isEmpty())
      <div class="rounded-lg border border-line p-8 text-center">
        <p class="mb-4 text-[13px] text-muted">You haven't submitted any jewellery yet.</p>
        <a class="inline-flex items-center justify-center gap-2 border border-accent bg-accent px-6 py-2.5 text-[12px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:border-accent-dark hover:bg-accent-dark" href="{{ route('account.sell-jewellery.create') }}">
          Submit Your Jewellery
        </a>
      </div>
    @else
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        @foreach ($requests as $request)
          @include('account.sell-jewellery._status-badge', ['request' => $request, 'asCard' => true])
        @endforeach
      </div>

      <div class="mt-8">
        {{ $requests->links() }}
      </div>
    @endif
  </div>

@endsection
```

Create the shared status-badge partial `backend/resources/views/account/sell-jewellery/_status-badge.blade.php` (used by `index.blade.php` above and `show.blade.php` in Task 2):

```blade
@php
  $statusClasses = [
      'pending' => 'bg-gray-100 text-gray-700',
      'submitted' => 'bg-gray-100 text-gray-700',
      'vendors_notified' => 'bg-gray-100 text-gray-700',
      'bidding_active' => 'bg-blue-100 text-blue-700',
      'bidding_closed' => 'bg-amber-100 text-amber-700',
      'bid_selected' => 'bg-amber-100 text-amber-700',
      'wallet_pending' => 'bg-amber-100 text-amber-700',
      'wallet_credited' => 'bg-green-100 text-green-700',
      'wallet_expired' => 'bg-red-100 text-red-700',
      'completed' => 'bg-emerald-100 text-emerald-800',
      'cancelled' => 'bg-red-100 text-red-800',
  ];
  $statusLabels = [
      'pending' => 'Pending',
      'submitted' => 'Submitted',
      'vendors_notified' => 'Vendors Notified',
      'bidding_active' => 'Bidding Active',
      'bidding_closed' => 'Bidding Closed',
      'bid_selected' => 'Bid Selected',
      'wallet_pending' => 'Wallet Pending',
      'wallet_credited' => 'Wallet Credited',
      'wallet_expired' => 'Wallet Expired',
      'completed' => 'Completed',
      'cancelled' => 'Cancelled',
  ];
  $badgeClass = $statusClasses[$request->status] ?? 'bg-gray-100 text-gray-700';
  $badgeLabel = $statusLabels[$request->status] ?? $request->status;
@endphp

@if (($asCard ?? false))
  <a href="{{ route('account.sell-jewellery.show', $request) }}" class="block rounded-lg border border-line p-4 transition-colors hover:border-accent">
    <div class="mb-2 flex items-center justify-between gap-2">
      <span class="text-[13px] font-medium uppercase tracking-[0.3px] text-heading">{{ $request->request_number }}</span>
      <span class="rounded-full px-2.5 py-0.5 text-[10px] font-medium uppercase tracking-[0.3px] {{ $badgeClass }}">{{ $badgeLabel }}</span>
    </div>
    <p class="text-[12px] text-muted">Submitted {{ $request->created_at->format('d M Y, h:i A') }}</p>
    @if ($request->final_amount)
      <p class="mt-2 text-[13px] font-medium text-heading">Final offer: ₹{{ number_format((float) $request->final_amount, 2) }}</p>
    @endif
  </a>
@else
  <span class="rounded-full px-2.5 py-0.5 text-[10px] font-medium uppercase tracking-[0.3px] {{ $badgeClass }}">{{ $badgeLabel }}</span>
@endif
```

Create `backend/resources/views/account/sell-jewellery/wallet.blade.php`:

```blade
@extends('layouts.app')

@section('meta_title', 'My Wallet | '.($siteSettings['site_name'] ?? 'Estele'))

@section('content')

  <nav class="mx-auto w-full max-w-wrapper px-3 md:px-4 flex flex-wrap items-center gap-1.5 py-4 text-[13px] text-muted" aria-label="Breadcrumb">
    <x-breadcrumb :items="[['label' => 'My Account', 'url' => route('account.index')], ['label' => 'Sell Your Jewellery', 'url' => route('account.sell-jewellery.landing')], ['label' => 'Wallet']]" />
  </nav>

  <div class="mx-auto w-full max-w-wrapper px-3 pb-10 md:px-4 md:pb-[60px]">
    <h1 class="mb-6 text-[20px] uppercase tracking-[0.5px] md:text-[26px]">My Wallet</h1>

    <div class="mb-8 rounded-lg border border-line bg-pinksoft p-6">
      <p class="text-[12px] uppercase tracking-[0.3px] text-muted">Wallet Balance</p>
      <p class="mt-1 text-[28px] font-medium text-heading">₹{{ number_format((float) $balance, 2) }}</p>
    </div>

    <h2 class="mb-4 text-[16px] uppercase tracking-[0.4px] text-heading">Old Jewellery Credits</h2>
    @if ($credits->isEmpty())
      <p class="mb-8 text-[13px] text-muted">No jewellery-sale credits yet.</p>
    @else
      <div class="mb-8 grid grid-cols-1 gap-4 md:grid-cols-2">
        @foreach ($credits as $credit)
          @php
            $daysLeft = $credit->expires_at ? now()->diffInDays($credit->expires_at, false) : null;
            $expiryClass = $daysLeft !== null && $daysLeft <= 1 ? 'text-red-700' : ($daysLeft !== null && $daysLeft <= 3 ? 'text-amber-700' : 'text-muted');
            $percentUsed = $credit->credited_amount > 0 ? round((1 - ((float) $credit->remaining_amount / (float) $credit->credited_amount)) * 100) : 0;
          @endphp
          <div class="rounded-lg border border-line p-4">
            <div class="mb-2 flex items-center justify-between gap-2">
              @if ($credit->request)
                <a class="text-[13px] font-medium uppercase tracking-[0.3px] text-heading underline hover:text-accent" href="{{ route('account.sell-jewellery.show', $credit->request) }}">{{ $credit->request->request_number }}</a>
              @else
                <span class="text-[13px] font-medium uppercase tracking-[0.3px] text-heading">Credit</span>
              @endif
              <span class="text-[12px] font-medium {{ $expiryClass }}">
                @if ($credit->status === 'expired') Expired @elseif ($daysLeft !== null) {{ max($daysLeft, 0) }}d left @endif
              </span>
            </div>
            <div class="mb-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
              <div class="h-full bg-accent" style="width: {{ min(max($percentUsed, 0), 100) }}%"></div>
            </div>
            <p class="text-[13px] text-muted">₹{{ number_format((float) $credit->remaining_amount, 2) }} remaining of ₹{{ number_format((float) $credit->credited_amount, 2) }}</p>
          </div>
        @endforeach
      </div>
      <div class="mb-8">{{ $credits->links() }}</div>
    @endif

    <h2 class="mb-4 text-[16px] uppercase tracking-[0.4px] text-heading">Transactions</h2>
    @if ($transactions->isEmpty())
      <p class="text-[13px] text-muted">No transactions yet.</p>
    @else
      <div class="overflow-x-auto">
        <table class="w-full text-[13px]">
          <thead>
            <tr class="border-b border-line text-left text-[11px] uppercase tracking-[0.3px] text-muted">
              <th class="py-2 pr-4">Date</th>
              <th class="py-2 pr-4">Type</th>
              <th class="py-2 pr-4">Amount</th>
              <th class="py-2 pr-4">Balance After</th>
              <th class="py-2">Reason</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($transactions as $txn)
              <tr class="border-b border-line">
                <td class="py-2 pr-4 text-muted">{{ $txn->created_at->format('d M Y, h:i A') }}</td>
                <td class="py-2 pr-4 {{ $txn->type === 'credit' ? 'text-green-700' : 'text-salebadge' }}">{{ ucfirst($txn->type) }}</td>
                <td class="py-2 pr-4">₹{{ number_format((float) $txn->amount, 2) }}</td>
                <td class="py-2 pr-4">₹{{ number_format((float) $txn->balance_after, 2) }}</td>
                <td class="py-2 text-muted">{{ $txn->reason }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="mt-4">{{ $transactions->links() }}</div>
    @endif
  </div>

@endsection
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=OldJewellerySellPageTest`
Expected: all pass except `test_owner_can_view_their_request` and `test_a_user_cannot_view_another_users_request`, which need `show.blade.php` (Task 2) — run those two individually and confirm they currently fail only on the missing view (`View [account.sell-jewellery.show] not found.`), not on routing/auth/ownership logic:

Run: `cd backend && php artisan test --filter=test_a_user_cannot_view_another_users_request`
Expected: PASS already (403 doesn't require the view to render, `Gate::authorize` throws before `view()` is called).

Run: `cd backend && php artisan test --filter=test_owner_can_view_their_request`
Expected: FAIL with "View [account.sell-jewellery.show] not found" — expected, resolved in Task 2.

- [ ] **Step 7: Commit**

```bash
git add backend/routes/web.php backend/app/Http/Controllers/OldJewellerySellController.php backend/resources/views/account/sell-jewellery/ backend/tests/Feature/OldJewellery/OldJewellerySellPageTest.php
git commit -m "feat: add customer sell-jewellery landing, create, list, and wallet pages"
```

---

### Task 2: Request detail/tracker page with live polling

**Files:**
- Create: `backend/resources/views/account/sell-jewellery/show.blade.php`
- Modify: `backend/tests/Feature/OldJewellery/OldJewellerySellPageTest.php` (add stepper/status assertions)

**Interfaces:**
- Consumes: `account.sell-jewellery.show` route (Task 1), `$oldJewelleryRequest` (`OldJewelleryRequest` model, fields: `request_number, description, status, bidding_start_at, bidding_end_at, final_amount, deduction_amount, credited_amount, closed_at, created_at`), `$oldJewelleryRequest->getFirstMediaUrl('image', 'thumb')` (Spatie Media Library method, already registered on the model), existing API endpoint `GET /api/v1/old-jewellery/requests/{request_number}/status` (returns `{success, data: {request_number, status, bidding_end_at}}` per `OldJewelleryRequestController::status`) for the client-side poll.
- Produces: nothing consumed by later tasks (leaf view).

- [ ] **Step 1: Add assertions to the existing test file**

Add to `backend/tests/Feature/OldJewellery/OldJewellerySellPageTest.php`, inside the class body:

```php
    public function test_show_page_renders_stepper_for_bidding_active_status(): void
    {
        $user = User::factory()->create();
        $request = $this->makeRequest(['user_id' => $user->id, 'status' => 'bidding_active']);

        $this->actingAs($user)
            ->get(route('account.sell-jewellery.show', $request))
            ->assertOk()
            ->assertSee('Bidding Active')
            ->assertSee('data-poll-status', false);
    }

    public function test_show_page_displays_final_amount_when_completed(): void
    {
        $user = User::factory()->create();
        $request = $this->makeRequest([
            'user_id' => $user->id,
            'status' => 'completed',
            'final_amount' => 15000,
            'deduction_amount' => 1500,
            'credited_amount' => 13500,
        ]);

        $this->actingAs($user)
            ->get(route('account.sell-jewellery.show', $request))
            ->assertOk()
            ->assertSee('15,000')
            ->assertSee('13,500');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd backend && php artisan test --filter=OldJewellerySellPageTest`
Expected: FAIL — `View [account.sell-jewellery.show] not found.`

- [ ] **Step 3: Create the view**

```blade
@extends('layouts.app')

@section('meta_title', $oldJewelleryRequest->request_number.' | '.($siteSettings['site_name'] ?? 'Estele'))

@section('content')

  @php
    $steps = ['submitted', 'bidding_active', 'bidding_closed', 'bid_selected', 'wallet_credited', 'completed'];
    $currentIndex = array_search($oldJewelleryRequest->status, $steps, true);
    $isCancelled = $oldJewelleryRequest->status === 'cancelled';
    $isPolling = in_array($oldJewelleryRequest->status, ['pending', 'submitted', 'vendors_notified', 'bidding_active'], true);
    $stepLabels = [
        'submitted' => 'Submitted',
        'bidding_active' => 'Bidding',
        'bidding_closed' => 'Closed',
        'bid_selected' => 'Selected',
        'wallet_credited' => 'Credited',
        'completed' => 'Completed',
    ];
  @endphp

  <nav class="mx-auto w-full max-w-wrapper px-3 md:px-4 flex flex-wrap items-center gap-1.5 py-4 text-[13px] text-muted" aria-label="Breadcrumb">
    <x-breadcrumb :items="[['label' => 'My Account', 'url' => route('account.index')], ['label' => 'Sell Your Jewellery', 'url' => route('account.sell-jewellery.landing')], ['label' => 'My Requests', 'url' => route('account.sell-jewellery.index')], ['label' => $oldJewelleryRequest->request_number]]" />
  </nav>

  <div class="mx-auto w-full max-w-2xl px-3 pb-10 md:px-4 md:pb-[60px]" data-poll-status data-request-number="{{ $oldJewelleryRequest->request_number }}" data-status-url="{{ url('/api/v1/old-jewellery/requests/'.$oldJewelleryRequest->request_number.'/status') }}" data-should-poll="{{ $isPolling ? '1' : '0' }}">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
      <h1 class="text-[20px] uppercase tracking-[0.5px] md:text-[26px]">{{ $oldJewelleryRequest->request_number }}</h1>
      @include('account.sell-jewellery._status-badge', ['request' => $oldJewelleryRequest])
    </div>

    @if ($isCancelled)
      <div class="mb-6 rounded-lg border border-salebadge bg-red-50 p-4 text-[13px] text-salebadge">
        This request was cancelled — no valid bids were received before the bidding window closed.
      </div>
    @else
      <div class="mb-8 flex items-center justify-between gap-1" data-stepper>
        @foreach ($steps as $i => $step)
          <div class="flex flex-1 flex-col items-center text-center">
            <div class="mb-1 flex h-7 w-7 items-center justify-center rounded-full text-[12px] font-medium {{ $currentIndex !== false && $i <= $currentIndex ? 'bg-accent text-white' : 'bg-gray-100 text-muted' }}" data-step-index="{{ $i }}">
              {{ $i + 1 }}
            </div>
            <span class="text-[10px] uppercase tracking-[0.2px] text-muted">{{ $stepLabels[$step] }}</span>
          </div>
          @if (! $loop->last)
            <div class="mx-1 h-px flex-1 {{ $currentIndex !== false && $i < $currentIndex ? 'bg-accent' : 'bg-gray-100' }}"></div>
          @endif
        @endforeach
      </div>
    @endif

    @if ($oldJewelleryRequest->status === 'bidding_active' && $oldJewelleryRequest->bidding_end_at)
      <p class="mb-6 text-[13px] text-muted" data-bidding-countdown data-ends-at="{{ $oldJewelleryRequest->bidding_end_at->toIso8601String() }}">
        Bidding closes at {{ $oldJewelleryRequest->bidding_end_at->format('d M Y, h:i A') }}
      </p>
    @endif

    <div class="mb-6 rounded-lg border border-line p-4">
      @if ($oldJewelleryRequest->getFirstMediaUrl('image', 'thumb'))
        <img class="mb-4 max-h-64 rounded-lg border border-line" src="{{ $oldJewelleryRequest->getFirstMediaUrl('image', 'thumb') }}" alt="Submitted jewellery">
      @endif

      @if ($oldJewelleryRequest->description)
        <p class="mb-3 text-[13px] text-muted">{{ $oldJewelleryRequest->description }}</p>
      @endif

      <p class="text-[12px] text-muted">Submitted {{ $oldJewelleryRequest->created_at->format('d M Y, h:i A') }}</p>
    </div>

    @if ($oldJewelleryRequest->final_amount)
      <div class="rounded-lg border border-line bg-pinksoft p-4">
        <div class="flex items-center justify-between text-[13px]">
          <span class="text-muted">Final offer</span>
          <span class="font-medium text-heading">₹{{ number_format((float) $oldJewelleryRequest->final_amount, 2) }}</span>
        </div>
        <div class="mt-1 flex items-center justify-between text-[13px]">
          <span class="text-muted">Processing deduction</span>
          <span class="text-heading">− ₹{{ number_format((float) $oldJewelleryRequest->deduction_amount, 2) }}</span>
        </div>
        <div class="mt-1 flex items-center justify-between border-t border-line pt-1 text-[13px]">
          <span class="font-medium text-heading">Credited to wallet</span>
          <span class="font-medium text-heading">₹{{ number_format((float) $oldJewelleryRequest->credited_amount, 2) }}</span>
        </div>
      </div>
    @endif
  </div>

  @push('scripts')
    <script>
      (function () {
        var el = document.querySelector('[data-poll-status]');
        if (!el || el.getAttribute('data-should-poll') !== '1') return;

        var countdownEl = document.querySelector('[data-bidding-countdown]');

        function tickCountdown() {
          if (!countdownEl) return;
          var endsAt = new Date(countdownEl.getAttribute('data-ends-at')).getTime();
          var remaining = endsAt - Date.now();
          if (remaining <= 0) return;
          var hrs = Math.floor(remaining / 3600000);
          var mins = Math.floor((remaining % 3600000) / 60000);
          countdownEl.textContent = 'Bidding closes in ' + hrs + 'h ' + mins + 'm';
        }

        function poll() {
          fetch(el.getAttribute('data-status-url'), { headers: { Accept: 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (body) {
              if (!body.success) return;
              var terminal = ['bidding_closed', 'bid_selected', 'wallet_pending', 'wallet_credited', 'wallet_expired', 'completed', 'cancelled'];
              if (terminal.indexOf(body.data.status) !== -1) {
                window.location.reload();
              }
            })
            .catch(function () {});
        }

        tickCountdown();
        setInterval(tickCountdown, 60000);
        setInterval(poll, 20000);
      })();
    </script>
  @endpush

@endsection
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=OldJewellerySellPageTest`
Expected: PASS — all 9 tests in this file green.

- [ ] **Step 5: Commit**

```bash
git add backend/resources/views/account/sell-jewellery/show.blade.php backend/tests/Feature/OldJewellery/OldJewellerySellPageTest.php
git commit -m "feat: add sell-jewellery request detail page with status stepper and polling"
```

---

### Task 3: Vendor bid-response page — routes, controller, error view

**Files:**
- Create: `backend/app/Http/Controllers/VendorBidController.php`
- Create: `backend/resources/views/layouts/vendor-minimal.blade.php`
- Create: `backend/resources/views/vendor/errors/link-invalid.blade.php`
- Modify: `backend/routes/web.php` (add public vendor routes, outside the `auth` group)
- Test: `backend/tests/Feature/OldJewellery/VendorBidPageTest.php`

**Interfaces:**
- Consumes: `App\Services\OldJewellery\OldJewelleryBiddingService::findInvitationByToken(string $plaintextToken): ?OldJewelleryVendorInvitation`, `::accept(OldJewelleryVendorInvitation $invitation, float $amount): OldJewelleryBid`, `::decline(OldJewelleryVendorInvitation $invitation, ?string $reason): OldJewelleryVendorInvitation` (all already exist, throw `\DomainException` on business-rule failure); `App\Http\Requests\Api\VendorAcceptBidRequest`, `App\Http\Requests\Api\VendorDeclineRequest` (reused as-is); `OldJewelleryVendorInvitation` fields `response_status, expires_at, decline_reason, responded_at` and relation `request` (`belongsTo(OldJewelleryRequest::class)`).
- Produces: named routes `old-jewellery.vendor.show`, `old-jewellery.vendor.accept`, `old-jewellery.vendor.decline`. View `vendor.old-jewellery-bid` expecting `$invitation` (with `request` eager-loaded) — written in Task 4.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\Vendor;
use App\Services\OldJewellery\VendorInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorBidPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_token_renders_the_bid_page(): void
    {
        [$invitation, $token] = $this->makeInvitation();

        $this->get(route('old-jewellery.vendor.show', $token))
            ->assertOk()
            ->assertSee($invitation->request->request_number);
    }

    public function test_invalid_token_renders_the_error_view_not_json(): void
    {
        $this->get(route('old-jewellery.vendor.show', 'not-a-real-token'))
            ->assertOk()
            ->assertViewIs('vendor.errors.link-invalid');
    }

    public function test_expired_invitation_renders_the_error_view(): void
    {
        [$invitation, $token] = $this->makeInvitation(['expires_at' => now()->subHour()]);

        $this->get(route('old-jewellery.vendor.show', $token))
            ->assertOk()
            ->assertViewIs('vendor.errors.link-invalid');
    }

    public function test_vendor_can_accept_with_a_bid_amount(): void
    {
        [$invitation, $token] = $this->makeInvitation();

        $this->post(route('old-jewellery.vendor.accept', $token), ['amount' => 12000])
            ->assertRedirect(route('old-jewellery.vendor.show', $token));

        $this->assertSame('accepted', $invitation->fresh()->response_status);
    }

    public function test_vendor_can_decline_with_a_reason(): void
    {
        [$invitation, $token] = $this->makeInvitation();

        $this->post(route('old-jewellery.vendor.decline', $token), ['reason' => 'Too low quality'])
            ->assertRedirect(route('old-jewellery.vendor.show', $token));

        $this->assertSame('declined', $invitation->fresh()->response_status);
    }

    public function test_a_second_response_is_rejected_with_a_flashed_error(): void
    {
        [$invitation, $token] = $this->makeInvitation();
        $invitation->update(['response_status' => 'accepted', 'responded_at' => now()]);

        $this->post(route('old-jewellery.vendor.accept', $token), ['amount' => 500])
            ->assertRedirect(route('old-jewellery.vendor.show', $token))
            ->assertSessionHas('error');
    }

    /**
     * @return array{0: OldJewelleryVendorInvitation, 1: string}
     */
    private function makeInvitation(array $overrides = []): array
    {
        $vendor = Vendor::create([
            'name' => 'Test Vendor',
            'mobile' => '9'.random_int(100000000, 999999999),
            'is_active' => true,
        ]);

        $request = OldJewelleryRequest::create([
            'request_number' => 'OJ-TEST-'.uniqid(),
            'status' => 'bidding_active',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);

        $plaintext = 'test-plaintext-token-'.uniqid();

        $invitation = OldJewelleryVendorInvitation::create(array_merge([
            'old_jewellery_request_id' => $request->id,
            'vendor_id' => $vendor->id,
            'token_hash' => app(VendorInvitationService::class)->hashToken($plaintext),
            'expires_at' => $request->bidding_end_at,
            'response_status' => 'pending',
        ], $overrides));

        return [$invitation, $plaintext];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=VendorBidPageTest`
Expected: FAIL — `Route [old-jewellery.vendor.show] not defined.`

- [ ] **Step 3: Add routes to `backend/routes/web.php`**

Add the import:

```php
use App\Http\Controllers\VendorBidController;
```

Add outside any `auth`-gated group (near the existing `old-jewellery.vendor.video` route at the bottom of the file):

```php
Route::get('/old-jewellery/vendor/{token}', [VendorBidController::class, 'show'])
    ->name('old-jewellery.vendor.show');

Route::post('/old-jewellery/vendor/{token}/accept', [VendorBidController::class, 'accept'])
    ->name('old-jewellery.vendor.accept')
    ->middleware('throttle:30,1');

Route::post('/old-jewellery/vendor/{token}/decline', [VendorBidController::class, 'decline'])
    ->name('old-jewellery.vendor.decline')
    ->middleware('throttle:30,1');
```

- [ ] **Step 4: Create the minimal layout**

```blade
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('meta_title', 'Vendor Bid | Estele')</title>
  <link rel="stylesheet" href="{{ asset('theme/app.css') }}?v={{ @filemtime(public_path('theme/app.css')) }}">
</head>
<body class="bg-white text-heading">
  <header class="border-b border-line px-4 py-4">
    <span class="text-[16px] font-medium uppercase tracking-[0.5px]">Estele</span>
  </header>

  <main class="mx-auto w-full max-w-lg px-4 py-6">
    @yield('content')
  </main>

  <footer class="mt-10 border-t border-line px-4 py-4 text-center text-[11px] text-muted">
    &copy; {{ date('Y') }} Estele
  </footer>

  @stack('scripts')
</body>
</html>
```

- [ ] **Step 5: Create the error view**

```blade
@extends('layouts.vendor-minimal')

@section('meta_title', 'Link Invalid | Estele')

@section('content')
  <div class="rounded-lg border border-line p-6 text-center">
    <p class="mb-2 text-[16px] font-medium uppercase tracking-[0.3px] text-heading">This link is invalid or has expired</p>
    <p class="text-[13px] text-muted">If you believe this is a mistake, please contact Estele support.</p>
  </div>
@endsection
```

- [ ] **Step 6: Create the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Http\Requests\Api\VendorAcceptBidRequest;
use App\Http\Requests\Api\VendorDeclineRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Services\OldJewellery\OldJewelleryBiddingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class VendorBidController extends Controller
{
    public function __construct(private readonly OldJewelleryBiddingService $biddingService) {}

    public function show(string $token): View
    {
        $invitation = $this->resolveInvitation($token);

        if (! $invitation) {
            return view('vendor.errors.link-invalid');
        }

        return view('vendor.old-jewellery-bid', ['invitation' => $invitation->load('request')]);
    }

    public function accept(VendorAcceptBidRequest $request, string $token): RedirectResponse
    {
        $invitation = $this->resolveInvitation($token);

        if (! $invitation) {
            return redirect()->route('old-jewellery.vendor.show', $token);
        }

        try {
            $this->biddingService->accept($invitation, (float) $request->validated('amount'));
        } catch (\DomainException $e) {
            return redirect()->route('old-jewellery.vendor.show', $token)->with('error', $e->getMessage());
        }

        return redirect()->route('old-jewellery.vendor.show', $token)->with('success', 'Your bid has been submitted.');
    }

    public function decline(VendorDeclineRequest $request, string $token): RedirectResponse
    {
        $invitation = $this->resolveInvitation($token);

        if (! $invitation) {
            return redirect()->route('old-jewellery.vendor.show', $token);
        }

        try {
            $this->biddingService->decline($invitation, $request->validated('reason'));
        } catch (\DomainException $e) {
            return redirect()->route('old-jewellery.vendor.show', $token)->with('error', $e->getMessage());
        }

        return redirect()->route('old-jewellery.vendor.show', $token)->with('success', 'Your response has been recorded.');
    }

    /**
     * Resolves the token the same way VendorTokenAuth does for the API route,
     * but returns null instead of a JSON 404/403 response — this controller
     * renders an HTML error view on failure instead (see link-invalid.blade.php),
     * since VendorTokenAuth's failure responses are hard-coded JSON and are not
     * reused here. See spec §2 "Problem with reusing vendor.token middleware directly".
     */
    private function resolveInvitation(string $token): ?OldJewelleryVendorInvitation
    {
        $invitation = $this->biddingService->findInvitationByToken($token);

        if (! $invitation || now()->greaterThanOrEqualTo($invitation->expires_at)) {
            return null;
        }

        return $invitation;
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=VendorBidPageTest`
Expected: FAIL only on `test_valid_token_renders_the_bid_page` and `test_vendor_can_accept_with_a_bid_amount` / `test_vendor_can_decline_with_a_reason` (need `vendor.old-jewellery-bid` view — Task 4). Confirm `test_invalid_token_renders_the_error_view_not_json` and `test_expired_invitation_renders_the_error_view` PASS now.

- [ ] **Step 8: Commit**

```bash
git add backend/app/Http/Controllers/VendorBidController.php backend/resources/views/layouts/vendor-minimal.blade.php backend/resources/views/vendor/errors/link-invalid.blade.php backend/routes/web.php backend/tests/Feature/OldJewellery/VendorBidPageTest.php
git commit -m "feat: add vendor bid-response controller, routes, and invalid-link error page"
```

---

### Task 4: Vendor bid-response view

**Files:**
- Create: `backend/resources/views/vendor/old-jewellery-bid.blade.php`

**Interfaces:**
- Consumes: `$invitation` (`OldJewelleryVendorInvitation` with `request` eager-loaded) from Task 3's `VendorBidController::show()`. Fields used: `$invitation->response_status` (`pending`|`accepted`|`declined`), `$invitation->decline_reason`, `$invitation->responded_at`, `$invitation->request->description`, `$invitation->request->status`, `$invitation->request->bidding_end_at`, `$invitation->request->getFirstMediaUrl('image', 'thumb')`, `$invitation->request->request_number`. Video is fetched via a temporary signed URL built the same way `OldJewelleryVendorViewResource` does it: `URL::temporarySignedRoute('old-jewellery.vendor.video', now()->addMinutes(30), ['invitation' => $invitation->id])` — this reuses the existing signed route/`VendorMediaController@video`, not a new endpoint.
- Produces: nothing (leaf view).

- [ ] **Step 1: Run the still-failing tests from Task 3 to confirm the exact gap**

Run: `cd backend && php artisan test --filter=VendorBidPageTest`
Expected: FAIL — `View [vendor.old-jewellery-bid] not found.`

- [ ] **Step 2: Create the view**

```blade
@extends('layouts.vendor-minimal')

@section('meta_title', $invitation->request->request_number.' | Vendor Bid | Estele')

@section('content')

  @php
    $bidRequest = $invitation->request;
    $isOpen = $invitation->response_status === 'pending'
        && $bidRequest->status === 'bidding_active'
        && now()->lessThan($invitation->expires_at);
    $videoUrl = $bidRequest->hasMedia('video')
        ? \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'old-jewellery.vendor.video',
            now()->addMinutes(30),
            ['invitation' => $invitation->id],
        )
        : null;
  @endphp

  @if (session('success'))
    <div class="mb-4 rounded-lg border border-line bg-green-50 p-3 text-[13px] text-green-700">{{ session('success') }}</div>
  @endif
  @if (session('error'))
    <div class="mb-4 rounded-lg border border-salebadge bg-red-50 p-3 text-[13px] text-salebadge">{{ session('error') }}</div>
  @endif

  <h1 class="mb-1 text-[18px] uppercase tracking-[0.4px] text-heading">{{ $bidRequest->request_number }}</h1>
  <p class="mb-4 text-[12px] text-muted">Bidding closes {{ $bidRequest->bidding_end_at?->format('d M Y, h:i A') }}</p>

  <div class="mb-5 rounded-lg border border-line p-4">
    @if ($bidRequest->getFirstMediaUrl('image', 'thumb'))
      <img class="mb-3 max-h-56 w-full rounded-lg object-cover" src="{{ $bidRequest->getFirstMediaUrl('image', 'thumb') }}" alt="Jewellery photo">
    @endif

    @if ($videoUrl)
      <video class="mb-3 w-full rounded-lg" controls src="{{ $videoUrl }}"></video>
    @endif

    @if ($bidRequest->description)
      <p class="text-[13px] text-muted">{{ $bidRequest->description }}</p>
    @endif
  </div>

  @if ($isOpen)
    <form class="mb-5 rounded-lg border border-line p-4" action="{{ route('old-jewellery.vendor.accept', request()->route('token')) }}" method="post">
      @csrf
      <label class="mb-1.5 block text-[12px] font-medium uppercase tracking-[0.3px] text-heading" for="amount">Your Bid (₹)</label>
      <input class="mb-3 w-full rounded-lg border border-line p-3 text-[14px]" type="number" id="amount" name="amount" min="1" max="1000000" step="0.01" required>
      <button class="inline-flex w-full items-center justify-center gap-2 border border-accent bg-accent px-6 py-2.5 text-[12px] font-medium uppercase tracking-[0.5px] text-white transition-colors hover:border-accent-dark hover:bg-accent-dark" type="submit">
        Submit Bid
      </button>
    </form>

    <details class="rounded-lg border border-line p-4">
      <summary class="cursor-pointer text-[12px] font-medium uppercase tracking-[0.3px] text-muted">Can't offer on this one?</summary>
      <form class="mt-3" action="{{ route('old-jewellery.vendor.decline', request()->route('token')) }}" method="post">
        @csrf
        <textarea class="mb-3 w-full rounded-lg border border-line p-3 text-[13px]" name="reason" rows="3" maxlength="1000" placeholder="Reason (optional)"></textarea>
        <button class="inline-flex w-full items-center justify-center gap-2 border border-line px-6 py-2.5 text-[12px] font-medium uppercase tracking-[0.5px] text-heading transition-colors hover:border-salebadge hover:text-salebadge" type="submit">
          Decline
        </button>
      </form>
    </details>
  @elseif ($invitation->response_status === 'accepted')
    <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-[13px] text-green-700">
      You submitted a bid on {{ $invitation->responded_at?->format('d M Y, h:i A') }}.
    </div>
  @elseif ($invitation->response_status === 'declined')
    <div class="rounded-lg border border-line p-4 text-[13px] text-muted">
      You declined this request{{ $invitation->decline_reason ? ' — "'.$invitation->decline_reason.'"' : '' }}.
    </div>
  @else
    <div class="rounded-lg border border-line p-4 text-[13px] text-muted">
      This bidding window has closed.
    </div>
  @endif

@endsection
```

- [ ] **Step 3: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=VendorBidPageTest`
Expected: PASS — all 6 tests in this file green.

- [ ] **Step 4: Commit**

```bash
git add backend/resources/views/vendor/old-jewellery-bid.blade.php
git commit -m "feat: add vendor bid-response page view"
```

---

### Task 5: `ShieldSeeder` — register `OldJewelleryWalletCredit` permissions

**Files:**
- Modify: `backend/database/seeders/ShieldSeeder.php:24-30`
- Test: `backend/tests/Feature/OldJewellery/FilamentOldJewelleryWalletCreditResourceTest.php` (created in Task 6, but this task's step 1 test lives there too — see Task 6 Step 1, which depends on this task's change to pass)

**Interfaces:**
- Consumes: nothing new.
- Produces: permission rows `ViewAny:OldJewelleryWalletCredit`, `View:OldJewelleryWalletCredit`, etc. (all 12 actions from `ACTIONS`), synced onto `super_admin`. Required by Task 6's Filament resource gating.

- [ ] **Step 1: Modify `RESOURCES` constant**

In `backend/database/seeders/ShieldSeeder.php`, change:

```php
    private const RESOURCES = [
        'Banner', 'Category', 'Collection', 'Coupon', 'Offer',
        'HomepageBlock', 'Order', 'Product', 'Role', 'Setting',
        'BlogCategory', 'Blog', 'CmsPage', 'FaqCategory', 'Faq', 'Review',
        'Popup', 'NewsletterSubscriber', 'Redirect', 'Customer',
        'RewardSubmission', 'Vendor', 'OldJewelleryRequest',
    ];
```

to:

```php
    private const RESOURCES = [
        'Banner', 'Category', 'Collection', 'Coupon', 'Offer',
        'HomepageBlock', 'Order', 'Product', 'Role', 'Setting',
        'BlogCategory', 'Blog', 'CmsPage', 'FaqCategory', 'Faq', 'Review',
        'Popup', 'NewsletterSubscriber', 'Redirect', 'Customer',
        'RewardSubmission', 'Vendor', 'OldJewelleryRequest', 'OldJewelleryWalletCredit',
    ];
```

- [ ] **Step 2: Run the existing Shield-related test suite to confirm no regression**

Run: `cd backend && php artisan test --filter=FilamentOldJewelleryResourceTest`
Expected: PASS (unaffected — this only adds new permissions, doesn't remove/rename existing ones).

- [ ] **Step 3: Commit**

```bash
git add backend/database/seeders/ShieldSeeder.php
git commit -m "feat: register OldJewelleryWalletCredit permissions in ShieldSeeder"
```

---

### Task 6: `OldJewelleryWalletCreditResource` (Filament, list-only)

**Files:**
- Create: `backend/app/Filament/Resources/OldJewelleryWalletCredits/OldJewelleryWalletCreditResource.php`
- Create: `backend/app/Filament/Resources/OldJewelleryWalletCredits/Pages/ListOldJewelleryWalletCredits.php`
- Create: `backend/app/Filament/Resources/OldJewelleryWalletCredits/Tables/OldJewelleryWalletCreditsTable.php`
- Test: `backend/tests/Feature/OldJewellery/FilamentOldJewelleryWalletCreditResourceTest.php`

**Interfaces:**
- Consumes: `OldJewelleryWalletCredit` model (fields: `user_id, old_jewellery_request_id, gross_amount, deduction_amount, credited_amount, remaining_amount, credited_at, expires_at, status`; relations `user()`, `request()`); Shield permission `ViewAny:OldJewelleryWalletCredit` (Task 5).
- Produces: Filament resource registered under navigation group `'Wallet'`, route `/admin/old-jewellery-wallet-credits`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryWalletCredit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentOldJewelleryWalletCreditResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_access_the_wallet_credits_index(): void
    {
        $admin = User::factory()->create();
        Permission::firstOrCreate(['name' => 'ViewAny:OldJewelleryWalletCredit', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $role->givePermissionTo('ViewAny:OldJewelleryWalletCredit');
        $admin->assignRole($role);

        $response = $this->actingAs($admin)->get('/admin/old-jewellery-wallet-credits');

        $response->assertOk();
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $user = User::factory()->create();
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user->assignRole('super_admin');

        $response = $this->actingAs($user)->get('/admin/old-jewellery-wallet-credits');

        $response->assertForbidden();
    }

    public function test_index_shows_credit_rows_with_expiry_status(): void
    {
        $admin = User::factory()->create();
        Permission::firstOrCreate(['name' => 'ViewAny:OldJewelleryWalletCredit', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $role->givePermissionTo('ViewAny:OldJewelleryWalletCredit');
        $admin->assignRole($role);

        $customer = User::factory()->create(['name' => 'Jane Customer']);
        $request = OldJewelleryRequest::create([
            'request_number' => 'OJ-TEST-CREDIT-1',
            'user_id' => $customer->id,
            'status' => 'completed',
            'bidding_start_at' => now()->subDays(5),
            'bidding_end_at' => now()->subDays(5)->addHours(3),
        ]);
        OldJewelleryWalletCredit::create([
            'user_id' => $customer->id,
            'old_jewellery_request_id' => $request->id,
            'gross_amount' => 10000,
            'deduction_amount' => 1000,
            'credited_amount' => 9000,
            'remaining_amount' => 9000,
            'credited_at' => now()->subDays(5),
            'expires_at' => now()->addDay(),
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->get('/admin/old-jewellery-wallet-credits')
            ->assertOk()
            ->assertSee('OJ-TEST-CREDIT-1')
            ->assertSee('Jane Customer');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=FilamentOldJewelleryWalletCreditResourceTest`
Expected: FAIL — 404, resource/route doesn't exist.

- [ ] **Step 3: Create the resource**

```php
<?php

namespace App\Filament\Resources\OldJewelleryWalletCredits;

use App\Filament\Resources\OldJewelleryWalletCredits\Pages\ListOldJewelleryWalletCredits;
use App\Filament\Resources\OldJewelleryWalletCredits\Tables\OldJewelleryWalletCreditsTable;
use App\Models\OldJewelleryWalletCredit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OldJewelleryWalletCreditResource extends Resource
{
    protected static ?string $model = OldJewelleryWalletCredit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Old Jewellery Credits';

    protected static ?string $modelLabel = 'Old Jewellery Credit';

    protected static ?string $pluralModelLabel = 'Old Jewellery Credits';

    protected static string|\UnitEnum|null $navigationGroup = 'Wallet';

    public static function table(Table $table): Table
    {
        return OldJewelleryWalletCreditsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOldJewelleryWalletCredits::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('ViewAny:OldJewelleryWalletCredit');
    }
}
```

- [ ] **Step 4: Create the list page**

```php
<?php

namespace App\Filament\Resources\OldJewelleryWalletCredits\Pages;

use App\Filament\Resources\OldJewelleryWalletCredits\OldJewelleryWalletCreditResource;
use Filament\Resources\Pages\ListRecords;

class ListOldJewelleryWalletCredits extends ListRecords
{
    protected static string $resource = OldJewelleryWalletCreditResource::class;
}
```

- [ ] **Step 5: Create the table**

```php
<?php

namespace App\Filament\Resources\OldJewelleryWalletCredits\Tables;

use App\Models\OldJewelleryWalletCredit;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OldJewelleryWalletCreditsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->paginationMode(PaginationMode::Simple)
            ->defaultSort('expires_at', 'asc')
            ->columns([
                TextColumn::make('user.name')->label('Customer')->searchable(),
                TextColumn::make('request.request_number')->label('Request')->searchable(),
                TextColumn::make('gross_amount')->formatStateUsing(fn ($state) => '₹'.number_format((float) $state, 2)),
                TextColumn::make('deduction_amount')->formatStateUsing(fn ($state) => '₹'.number_format((float) $state, 2)),
                TextColumn::make('credited_amount')->formatStateUsing(fn ($state) => '₹'.number_format((float) $state, 2)),
                TextColumn::make('remaining_amount')->formatStateUsing(fn ($state) => '₹'.number_format((float) $state, 2)),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'partially_used' => 'warning',
                        'used' => 'gray',
                        'expired' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('credited_at')->dateTime('d M Y, h:i A')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('expires_at')
                    ->dateTime('d M Y, h:i A')
                    ->color(fn (OldJewelleryWalletCredit $record) => in_array($record->status, ['active', 'partially_used'], true) && now()->diffInDays($record->expires_at, false) <= 2 ? 'danger' : null)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Active',
                    'partially_used' => 'Partially Used',
                    'used' => 'Used',
                    'expired' => 'Expired',
                ]),
                Filter::make('expiring_soon')
                    ->label('Expiring soon (≤3 days)')
                    ->query(fn ($query) => $query
                        ->whereIn('status', ['active', 'partially_used'])
                        ->where('expires_at', '<=', now()->addDays(3))),
            ]);
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=FilamentOldJewelleryWalletCreditResourceTest`
Expected: PASS — all 3 tests green.

- [ ] **Step 7: Commit**

```bash
git add backend/app/Filament/Resources/OldJewelleryWalletCredits/ backend/tests/Feature/OldJewellery/FilamentOldJewelleryWalletCreditResourceTest.php
git commit -m "feat: add OldJewelleryWalletCredit admin resource for expiry/status visibility"
```

---

### Task 7: Vendor performance view + `mobile_verified_at` surfaced

**Files:**
- Create: `backend/app/Filament/Resources/Vendors/Pages/ViewVendor.php`
- Create: `backend/app/Filament/Resources/Vendors/Schemas/VendorInfolist.php`
- Modify: `backend/app/Filament/Resources/Vendors/VendorResource.php` (register `view` page, add `infolist()` method)
- Modify: `backend/app/Filament/Resources/Vendors/Schemas/VendorForm.php` (add `mobile_verified_at` placeholder)
- Modify: `backend/app/Filament/Resources/Vendors/Tables/VendorsTable.php` (add verified column + `ViewAction`)
- Test: `backend/tests/Feature/OldJewellery/FilamentVendorPerformanceTest.php`

**Interfaces:**
- Consumes: `Vendor` model relations `invitations()` (`HasMany<OldJewelleryVendorInvitation>`), `bids()` (`HasMany<OldJewelleryBid>`); `OldJewelleryVendorInvitation::response_status` (`pending`|`accepted`|`declined`); `OldJewelleryRequest::winning_bid_id`.
- Produces: route `/admin/vendors/{record}` (view page).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\OldJewellery;

use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\OldJewelleryVendorInvitation;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FilamentVendorPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_page_shows_performance_numbers(): void
    {
        $admin = $this->makeAdmin();
        $vendor = Vendor::create(['name' => 'Acme Gold', 'mobile' => '9111111111', 'is_active' => true]);

        $requestOne = OldJewelleryRequest::create([
            'request_number' => 'OJ-TEST-PERF-1',
            'status' => 'bidding_active',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);
        $requestTwo = OldJewelleryRequest::create([
            'request_number' => 'OJ-TEST-PERF-2',
            'status' => 'bidding_active',
            'bidding_start_at' => now(),
            'bidding_end_at' => now()->addHours(3),
        ]);

        OldJewelleryVendorInvitation::create([
            'old_jewellery_request_id' => $requestOne->id,
            'vendor_id' => $vendor->id,
            'token_hash' => hash('sha256', 'a'),
            'expires_at' => $requestOne->bidding_end_at,
            'response_status' => 'accepted',
        ]);
        OldJewelleryVendorInvitation::create([
            'old_jewellery_request_id' => $requestTwo->id,
            'vendor_id' => $vendor->id,
            'token_hash' => hash('sha256', 'b'),
            'expires_at' => $requestTwo->bidding_end_at,
            'response_status' => 'declined',
        ]);

        $winningBid = OldJewelleryBid::create([
            'old_jewellery_request_id' => $requestOne->id,
            'bidder_type' => 'vendor',
            'vendor_id' => $vendor->id,
            'amount' => 5000,
            'submitted_at' => now(),
        ]);
        $requestOne->update(['winning_bid_id' => $winningBid->id]);

        $response = $this->actingAs($admin)->get("/admin/vendors/{$vendor->id}");

        $response->assertOk()
            ->assertSee('2') // invitations sent
            ->assertSee('1'); // bids won
    }

    public function test_mobile_verified_column_appears_on_the_list(): void
    {
        $admin = $this->makeAdmin();
        Vendor::create(['name' => 'Unverified Co', 'mobile' => '9222222222', 'is_active' => true]);

        $this->actingAs($admin)
            ->get('/admin/vendors')
            ->assertOk();
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        Permission::firstOrCreate(['name' => 'ViewAny:Vendor', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'View:Vendor', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $role->givePermissionTo(['ViewAny:Vendor', 'View:Vendor']);
        $admin->assignRole($role);

        return $admin;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php artisan test --filter=FilamentVendorPerformanceTest`
Expected: FAIL — `test_view_page_shows_performance_numbers` 404s (no view page registered yet).

- [ ] **Step 3: Create the infolist**

```php
<?php

namespace App\Filament\Resources\Vendors\Schemas;

use App\Models\Vendor;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VendorInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Details')->schema([
                TextEntry::make('name'),
                TextEntry::make('company_name')->placeholder('—'),
                TextEntry::make('mobile'),
                TextEntry::make('email')->placeholder('—'),
                TextEntry::make('whatsapp_number')->placeholder('—'),
                TextEntry::make('is_active')->label('Active')->formatStateUsing(fn (bool $state) => $state ? 'Yes' : 'No'),
                TextEntry::make('mobile_verified_at')
                    ->label('Mobile Verified')
                    ->formatStateUsing(fn ($state) => $state ? "Verified on {$state->format('d M Y')}" : 'Not verified'),
            ]),

            Section::make('Performance')->schema([
                TextEntry::make('invitations_sent')
                    ->label('Invitations Sent')
                    ->state(fn (Vendor $record) => $record->invitations()->count()),
                TextEntry::make('invitations_accepted')
                    ->label('Accepted')
                    ->state(fn (Vendor $record) => $record->invitations()->where('response_status', 'accepted')->count()),
                TextEntry::make('invitations_declined')
                    ->label('Declined')
                    ->state(fn (Vendor $record) => $record->invitations()->where('response_status', 'declined')->count()),
                TextEntry::make('bids_submitted')
                    ->label('Bids Submitted')
                    ->state(fn (Vendor $record) => $record->bids()->count()),
                TextEntry::make('bids_won')
                    ->label('Bids Won')
                    ->state(fn (Vendor $record) => \App\Models\OldJewelleryRequest::whereIn(
                        'winning_bid_id',
                        $record->bids()->pluck('id'),
                    )->count()),
                TextEntry::make('win_rate')
                    ->label('Win Rate')
                    ->state(function (Vendor $record) {
                        $submitted = $record->bids()->count();
                        if ($submitted === 0) {
                            return '—';
                        }
                        $won = \App\Models\OldJewelleryRequest::whereIn('winning_bid_id', $record->bids()->pluck('id'))->count();

                        return round(($won / $submitted) * 100).'%';
                    }),
            ]),
        ]);
    }
}
```

- [ ] **Step 4: Create the view page**

```php
<?php

namespace App\Filament\Resources\Vendors\Pages;

use App\Filament\Resources\Vendors\VendorResource;
use Filament\Resources\Pages\ViewRecord;

class ViewVendor extends ViewRecord
{
    protected static string $resource = VendorResource::class;
}
```

- [ ] **Step 5: Wire the view page + infolist into `VendorResource`**

In `backend/app/Filament/Resources/Vendors/VendorResource.php`, add the import:

```php
use App\Filament\Resources\Vendors\Pages\ViewVendor;
use App\Filament\Resources\Vendors\Schemas\VendorInfolist;
```

Add the `infolist()` method (alongside the existing `form()`/`table()`):

```php
    public static function infolist(Schema $schema): Schema
    {
        return VendorInfolist::configure($schema);
    }
```

Change `getPages()` to:

```php
    public static function getPages(): array
    {
        return [
            'index' => ListVendors::route('/'),
            'create' => CreateVendor::route('/create'),
            'view' => ViewVendor::route('/{record}'),
            'edit' => EditVendor::route('/{record}/edit'),
        ];
    }
```

- [ ] **Step 6: Add `mobile_verified_at` placeholder to `VendorForm`**

In `backend/app/Filament/Resources/Vendors/Schemas/VendorForm.php`, add the import:

```php
use Filament\Forms\Components\Placeholder;
```

Add to the `components([...])` array, after `Toggle::make('is_active')->default(true),`:

```php
            Placeholder::make('mobile_verified_at')
                ->label('Mobile Verified')
                ->content(fn ($record) => $record?->mobile_verified_at
                    ? "Verified on {$record->mobile_verified_at->format('d M Y')}"
                    : 'Not verified'),
```

- [ ] **Step 7: Add verified icon column + `ViewAction` to `VendorsTable`**

In `backend/app/Filament/Resources/Vendors/Tables/VendorsTable.php`, add the import:

```php
use Filament\Actions\ViewAction;
```

Add to `columns([...])`, after `IconColumn::make('is_active')->boolean(),`:

```php
                IconColumn::make('mobile_verified_at')->label('Verified')->boolean()->state(fn ($record) => (bool) $record->mobile_verified_at),
```

Add `ViewAction::make(),` as the first entry in `recordActions([...])`:

```php
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `cd backend && php artisan test --filter=FilamentVendorPerformanceTest`
Expected: PASS — both tests green.

Run: `cd backend && php artisan test --filter=FilamentOldJewelleryResourceTest`
Expected: PASS (no regression on the unrelated existing Filament test).

- [ ] **Step 9: Commit**

```bash
git add backend/app/Filament/Resources/Vendors/
git commit -m "feat: add vendor performance view page and surface mobile_verified_at"
```

---

### Task 8: Full regression pass + Tailwind build verification

**Files:** none created/modified — verification only.

**Interfaces:** none.

- [ ] **Step 1: Run the full backend test suite**

Run: `cd backend && php artisan test`
Expected: PASS, zero failures, zero new skips beyond any pre-existing ones.

- [ ] **Step 2: Verify the Tailwind classes used in this plan's new Blade views actually compile**

From the repo root (not `backend/`), run the existing build (per spec §5, this is the real build pipeline; `backend/resources/js` is a dead stub):

Run: `cd /Users/ayushman/Desktop/estele-jewellery && npm run build`
Expected: build succeeds with no errors. Then check the compiled output contains the new-to-this-plan status classes not previously confirmed present elsewhere (`bg-blue-100`, `text-blue-700`, `bg-amber-100`, `text-amber-700`, `bg-emerald-100`, `text-emerald-800`):

Run: `grep -o "bg-blue-100" dist/app.css | head -1 && grep -o "text-blue-700" dist/app.css | head -1 && grep -o "bg-amber-100" dist/app.css | head -1 && grep -o "text-amber-700" dist/app.css | head -1 && grep -o "bg-emerald-100" dist/app.css | head -1 && grep -o "text-emerald-800" dist/app.css | head -1`
Expected: each grep prints the matched class name (confirms Tailwind's `@source` scan over `backend/resources/views/**/*.blade.php` picked up the new files and generated these utilities). If any is missing, the class was likely written with a typo (e.g. a color name Tailwind v4 doesn't ship) — fix the class name in the relevant Blade file and re-run this step; do not hand-edit the compiled CSS.

- [ ] **Step 3: Copy the rebuilt bundle into the Laravel-served path**

Run: `cp dist/app.css backend/public/theme/app.css && cp dist/app.js backend/public/theme/app.js`
Expected: both files updated (confirm with `ls -la backend/public/theme/app.css backend/public/theme/app.js` showing a fresh mtime).

- [ ] **Step 4: Commit the rebuilt theme assets**

```bash
git add dist/app.css dist/app.js backend/public/theme/app.css backend/public/theme/app.js
git commit -m "build: rebuild theme assets for sell-jewellery status badge classes"
```

---

## Self-Review Notes

- **Spec coverage:** All 3 sub-projects from the spec have tasks — customer sell-flow (Tasks 1-2), vendor bid page (Tasks 3-4), admin gaps (Tasks 5-7). The spec's explicit "out of scope" list (manual expire action, verification workflow, selective vendor invitation, editing raw request fields) has no corresponding task, correctly.
- **Placeholder scan:** No TBD/TODO; every step has complete, runnable code; no "similar to Task N" — every Blade view, controller, and Filament class is written in full even where visually repetitive (status badge palette) because different files need it independently.
- **Type/name consistency check:** `OldJewellerySellController` methods (`landing, create, store, index, show, wallet`) match the routes in Task 1 exactly. `VendorBidController` methods (`show, accept, decline`) match Task 3's routes. View names (`account.sell-jewellery.landing/create/index/show/wallet`, `vendor.old-jewellery-bid`, `vendor.errors.link-invalid`) are consistent between controller `view()` calls and the files created. `OldJewelleryWalletCreditResource`/`ListOldJewelleryWalletCredits`/`OldJewelleryWalletCreditsTable` namespaces and class references match across Task 6's three files. `VendorInfolist`/`ViewVendor` names match what Task 7 wires into `VendorResource::infolist()`/`getPages()`.
- **Gap fixed during self-review:** `ShieldSeeder` needed `OldJewelleryWalletCredit` added to `RESOURCES` before Task 6's resource could be permission-gated at all (Filament Shield permissions are seeded, not auto-created) — added as Task 5, ordered before Task 6 which depends on it.
