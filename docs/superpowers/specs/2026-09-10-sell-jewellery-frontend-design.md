# Sell-Jewellery Frontend + Admin Gaps — Design

Date: 2026-09-10

## Context

The old-jewellery-sell backend (models, services, jobs, API controllers,
policies) is fully built and live under `/api/v1/old-jewellery/*`,
`/api/v1/wallet/*`, `/api/v1/admin/old-jewellery/*`,
`/api/v1/vendor/old-jewellery/{token}/*`. No customer-facing UI, vendor UI,
or full admin UI exists yet. This spec covers building all three.

The live storefront is server-rendered Blade (`backend/resources/views`),
not the static `pages/*.html` prototype at the repo root. New pages follow
that stack.

## Sub-project 1 — Customer sell-flow (Blade)

### Routes

New file-scoped controller `OldJewellerySellController`
(`backend/app/Http/Controllers/OldJewellerySellController.php`), added to
the existing `auth` group in `backend/routes/web.php` alongside
`account.rewards.*`, using the same `account.*` name prefix:

```php
Route::middleware('auth')->group(function () {
    // ...existing...

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
});
```

`throttle:10,60` on `store` matches the existing API rate limit for the
same operation (`api.php:12`), since this is the same write path.

### Controller

`OldJewellerySellController` constructor-injects
`OldJewelleryRequestService` and `VendorInvitationService` — the exact
services `Api\OldJewelleryRequestController` already uses — and queries
Eloquent directly. It does not call the `Api\*` controllers (confirmed
codebase convention: §9 of investigation — `AccountController` and
`RewardSubmissionController` both query models/services directly, never
make internal HTTP calls).

- `landing()` — no data, static view.
- `create()` — no data, static form view.
- `store(StoreOldJewelleryRequestRequest $request)` — reuses the existing
  form request class (already has the right validation rules), calls
  `OldJewelleryRequestService::create()` then
  `VendorInvitationService::inviteAll()`, redirects to
  `account.sell-jewellery.show` with a flash success message. On
  `ValidationException`, Laravel's default redirect-back-with-errors
  applies (standard Blade form pattern, no extra handling needed).
- `index()` — `Auth::user()->oldJewelleryRequests()->latest()->paginate(10)`
  (relation already exists on `User`, confirmed at `app/Models/User.php:49`).
- `show(OldJewelleryRequest $oldJewelleryRequest)` — `Gate::authorize('view',
  $oldJewelleryRequest)` using the existing `OldJewelleryRequestPolicy`,
  then render.
- `wallet()` — `$user->wallet_balance`,
  `$user->walletTransactions()->latest()->paginate(20)`,
  `$user->oldJewelleryWalletCredits()->latest()->paginate(20)` (mirrors
  `RewardSubmissionController::index()`'s direct-Eloquent style).

### Views

All under `backend/resources/views/account/sell-jewellery/`, all
`@extends('layouts.app')` + `@section('meta_title', ...)` +
`@section('content')`, breadcrumb via `<x-breadcrumb :items="[...]" />`
exactly like `account/addresses.blade.php`.

1. **`landing.blade.php`** — hero banner, "How it works" 4-step strip
   (submit photo+video → vendors bid within 3 hours → highest bid wins →
   wallet credited in 10-day-valid credit), trust/safety copy, CTA button
   to `account.sell-jewellery.create`. Static content, no API calls.

2. **`create.blade.php`** — form (`multipart/form-data`, POST to
   `account.sell-jewellery.store`): `description` textarea (optional,
   max 2000 shown as a live counter), `image` file input (optional,
   accept `.jpg,.jpeg,.png,.webp`, client preview thumbnail), `video`
   file input (**required**, accept `.mp4,.mov`, client preview via
   `<video>` + object URL, max size hint "20MB"). Client-side size/type
   checks before submit (belt-and-braces; server validation via
   `StoreOldJewelleryRequestRequest` is the real gate). JS as inline
   `<script>` in `@push('scripts')`, using `data-*` hooks
   (`data-sell-jewellery-form`, `data-file-preview="image"` /
   `"video"`), matching the `checkout/index.blade.php` convention.

3. **`index.blade.php`** — paginated card/table list of the user's
   requests: `request_number`, status badge (color per state — see
   Status badge palette below), `created_at`, `final_amount` if set,
   link to `show`. Uses `<x-pagination-links>` if paginated same as other
   account list pages. Empty state with CTA to `create` when no requests
   exist.

4. **`show.blade.php`** — request detail + visual stepper. Stepper steps:
   Submitted → Bidding → Closed → Selected → Credited → Completed
   (cancelled requests get a distinct red terminal state, not on the
   happy-path stepper). Shows `description`, `image_url` thumbnail (never
   shows video to customer — matches `OldJewelleryRequestResource`'s
   deliberate exclusion), `bidding_end_at` countdown while
   `bidding_active` (client-side `setInterval`, matches the pattern
   below), `final_amount` / `deduction_amount` / `credited_amount` once
   present. While status is `submitted`, `vendors_notified`, or
   `bidding_active`, the page polls `GET
   /api/v1/old-jewellery/requests/{request_number}/status` every 20s via
   `fetch` (same-origin, CSRF header from meta tag — same helper pattern
   as `src/app.js`'s `request()`) and live-updates the stepper/countdown
   without a full reload; polling stops once a terminal-ish status is
   reached client-side.

5. **`wallet.blade.php`** — balance card at top, two sections below:
   "Transactions" (paginated table: type, amount, balance_after, reason,
   date) and "Old Jewellery Credits" (paginated cards: request_number
   link back to `show`, gross/deduction/credited/remaining amounts, a
   progress bar for `remaining_amount / credited_amount`, `expires_at`
   with a countdown badge that turns amber at ≤3 days and red at ≤1 day —
   matching the existing `reminder_3d`/`reminder_1d`/`reminder_0d` cadence
   the backend already uses for its own notifications, so the UI urgency
   matches what the customer is separately being notified about).

### Status badge palette (shared across customer + admin views)

| Status | Color |
|---|---|
| `pending`, `submitted`, `vendors_notified` | slate/gray |
| `bidding_active` | blue |
| `bidding_closed`, `bid_selected` | amber |
| `wallet_pending` | amber |
| `wallet_credited` | green |
| `wallet_expired` | red |
| `completed` | emerald/dark-green |
| `cancelled` | red/neutral-dark |

## Sub-project 2 — Vendor bid-response page (token-based, no login)

### Problem with reusing `vendor.token` middleware directly

`VendorTokenAuth` always returns raw JSON on failure
(`response()->json(...)`, unconditionally — not gated on `$request->is('api/*')`),
which is wrong UX for an HTML page. Rather than modify shared middleware
(risk to the existing API route) or fork it, the new Blade controller
resolves the token itself using the same underlying service call the
middleware uses (`OldJewelleryBiddingService::findInvitationByToken()`),
and renders an HTML error view on failure. This keeps
`VendorTokenAuth`/the API route untouched and avoids a middleware that
behaves differently depending on which route mounts it.

### Route (`web.php`, no `auth` middleware — public, token is the credential)

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

### Controller

`VendorBidController` (new), constructor-injects `OldJewelleryBiddingService`.
Each action calls `findInvitationByToken($token)`; on null/expired
result, renders a small standalone `errors.vendor-link` Blade view
("This link is invalid or has expired") instead of JSON. `accept`/`decline`
validate the same way `VendorAcceptBidRequest`/`VendorDeclineRequest` do
(reuse those form request classes) and call
`OldJewelleryBiddingService::accept()` / `decline()`, catching
`\DomainException` into a flashed error + redirect back to `show`.

### View

`backend/resources/views/vendor/old-jewellery-bid.blade.php` — its own
minimal layout (`@extends('layouts.vendor-minimal')`, a new tiny layout
with just logo header + footer, no cart/account/search chrome — vendors
are not customers). Mobile-first (opened from WhatsApp). Shows:
description, image, `<video controls>` pointed at the signed
`video_url` (already generated by `OldJewelleryVendorViewResource`
logic — controller builds the same signed route), countdown to
`bidding_end_at`.

State-dependent body:
- `response_status === 'pending'` and not expired and request still
  `bidding_active`: bid-amount number input (client min `1`, max
  `1,000,000` matching `MAX_BID_AMOUNT`) + "Submit Bid" button (POST
  accept), and a separate small "Can't offer / Decline" disclosure with
  a reason textarea + button (POST decline).
- Otherwise (already responded, expired, or bidding closed): read-only
  summary of what happened — "You bid ₹X on <date>" / "You declined" /
  "This bidding window has closed."

No polling needed here — it's a single-visit action page, not a tracker.

## Sub-project 3 — Admin panel gaps (Filament)

### 3a. `OldJewelleryWalletCreditResource` (new, list-only)

Mirrors `WalletManagementResource` structure exactly:

- `backend/app/Filament/Resources/OldJewelleryWalletCredits/OldJewelleryWalletCreditResource.php`
  — `$model = OldJewelleryWalletCredit::class`, `$navigationGroup =
  'Wallet'` (same group as `WalletManagementResource`, so they sit
  together in the sidebar), `canCreate() => false`.
- `Pages/ListOldJewelleryWalletCredits.php` extends `ListRecords`.
- `Tables/OldJewelleryWalletCreditsTable.php`: columns `user.name,
  request.request_number (link to OldJewelleryRequestResource view),
  gross_amount, deduction_amount, credited_amount, remaining_amount,
  status (badge, same palette table above), credited_at, expires_at`
  (colored: red text/badge when `expires_at` is within 2 days and status
  is still `active`/`partially_used`). Filters: `SelectFilter` on
  `status`, plus a `Filter` toggle "Expiring soon (≤3 days)". No row
  actions initially (read-only operational view) — matches the "read-only"
  framing agreed in the design discussion; not adding a manual-expire
  action since no backend service method exists for that today and adding
  one is out of scope for a frontend/UI task.
- Gate this resource on Shield the same way
  `OldJewelleryRequestResource` is gated (`ViewAny:OldJewelleryWalletCredit`
  permission) — matches the existing project convention of Shield-gating
  every Filament resource rather than the ownership policy.

### 3b. Vendor performance view (`VendorResource`)

Add a `View` page (currently missing — only List/Create/Edit exist):

- `Pages/ViewVendor.php` extends `ViewRecord`.
- Register `'view' => ViewVendor::route('/{record}')` in `getPages()`.
- New `Schemas/VendorInfolist.php`: a details section (name, company,
  mobile, email, whatsapp, is_active, mobile_verified_at) plus a
  "Performance" section with computed stats: invitations sent
  (`invitations()->count()`), accepted, declined, bids submitted
  (`bids()->count()`), bids won (`bids()->where('id', '=', request's
  winning_bid_id)` — count requests where `winning_bid_id` is one of this
  vendor's bid IDs), win rate (won / submitted, formatted %). All
  computed inline in the infolist via `TextEntry::make(...)->state(fn
  (Vendor $record) => ...)` closures — no new DB columns or migrations.
- Table gets a `ViewAction` alongside the existing `EditAction`/`DeleteAction`.

### 3c. `mobile_verified_at` surfaced

- `VendorForm.php`: add a `Placeholder::make('mobile_verified_at')` showing
  "Verified on {date}" or "Not verified" — read-only, since there's no
  verification-trigger workflow to build (out of scope; backend doesn't
  expose a way to verify from admin either, and inventing one is new
  backend behavior, not a UI gap).
- `VendorsTable.php`: add an `IconColumn::make('mobile_verified_at')
  ->boolean()` (state cast to bool via `!is_null(...)`), label "Verified".

## Testing approach

- **Sub-project 1**: Feature tests per route in
  `backend/tests/Feature/OldJewellerySellPageTest.php` — guest redirected
  to login; owner sees own request; non-owner gets 403 on `show`; `store`
  creates a request and redirects; `index`/`wallet` paginate correctly.
- **Sub-project 2**: `backend/tests/Feature/VendorBidPageTest.php` —
  valid token renders page; invalid/expired token renders the error view
  (not JSON); accept/decline happy paths redirect with flash message;
  double-response is rejected (matches existing service-level guard).
- **Sub-project 3**: `backend/tests/Feature/Filament/...` — resource list
  renders for a permitted admin and 403s for one without the Shield
  permission (matches the existing pattern for
  `OldJewelleryRequestResource`'s own tests, if any exist — check and
  mirror). Vendor view page renders performance numbers matching a
  seeded fixture.

No new backend business logic is introduced by this spec — every
sub-project is UI/routing/presentation over already-existing services,
policies, and models. The one net-new artifact that isn't pure
presentation is `VendorBidController`'s error-view branch replacing
`VendorTokenAuth`'s JSON-only failure response for the HTML page case;
that's a controller-level presentational choice, not new domain logic.

## Out of scope (explicitly deferred, not silently dropped)

- Manual "expire credit now" / "extend expiry" admin action (no backend
  service method exists; would be new domain logic).
- Mobile-verification *workflow* (OTP-verify-a-vendor flow) — only the
  existing `mobile_verified_at` field is surfaced read-only.
- Manual/selective vendor invitation UI — backend always invites all
  active vendors; changing that is a backend behavior change, not a UI gap.
- Editing `OldJewelleryRequest` raw fields from Filament — deliberately
  absent today (mutations go through actions only); not changing that.
