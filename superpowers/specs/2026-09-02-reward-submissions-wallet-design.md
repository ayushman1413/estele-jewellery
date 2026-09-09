# Reward Submissions & Wallet — Design

Date: 2026-09-02
Status: Approved

## Purpose

Let a customer submit unboxing proof (1 image + 1 video) for a delivered
order. Admin reviews it in Filament, approves with a discretionary reward
amount or rejects with a reason. Approved rewards credit the customer's
wallet. Wallet balance is usable (partially or fully) toward future
checkouts, deducted from order total, with a full transaction ledger and
auto-refund on order cancellation/return.

## Scope

Two tightly-coupled pieces, one spec: reward submissions feed the wallet,
and the wallet is spent at checkout. Both ship together.

## Data model

### `reward_submissions`

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `user_id` | FK → users | cascade delete |
| `order_id` | FK → orders, **unique** | one submission per order |
| `status` | string, default `pending` | `pending` \| `approved` \| `rejected` |
| `reward_amount` | decimal(10,2) nullable | set only on approval |
| `rejection_reason` | text nullable | set only on rejection |
| `reviewed_at` | timestamp nullable | |
| `created_at`/`updated_at` | | |

Indexes: `user_id`, `status`, unique `order_id`.

Media (Spatie MediaLibrary, same disks as `Review`/`Product`):
- collection `image` — singleFile, `useDisk('original_images')`, image mimes only.
- collection `video` — singleFile, `useDisk('original_images')`, `video/mp4`/`video/webm` only.

Model: `App\Models\RewardSubmission implements HasMedia`, `belongsTo(User)`,
`belongsTo(Order)`.

### `wallet_transactions`

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `user_id` | FK → users | cascade delete |
| `type` | string | `credit` \| `debit` |
| `amount` | decimal(10,2) | always positive; sign implied by `type` |
| `balance_after` | decimal(10,2) | snapshot for audit, no recomputation needed to display history |
| `reason` | string | `reward_approved` \| `order_payment` \| `order_refund` |
| `reference_type` | string nullable | e.g. `App\Models\RewardSubmission`, `App\Models\Order` |
| `reference_id` | unsignedBigInteger nullable | |
| `created_at` | | no `updated_at` — ledger rows are immutable |

Indexes: `user_id`, `[reference_type, reference_id]`.

### `users` table addition

- `wallet_balance` decimal(10,2) default 0 — cached/denormalized. Every write
  to it happens in the same DB transaction as the `wallet_transactions` insert
  that justifies it (see `WalletService` below). Never written directly
  outside that service.

### `orders` table addition

- `wallet_amount_used` decimal(10,2) default 0 — how much of this order's
  total was paid from wallet. Shown on invoice/order detail. Drives the
  cancel/return auto-refund amount.

## `WalletService`

Single point of truth for every balance mutation — nothing else touches
`users.wallet_balance` or inserts into `wallet_transactions` directly. Lives
at `app/Services/WalletService.php`.

```php
credit(User $user, float $amount, string $reason, ?Model $reference = null): WalletTransaction
debit(User $user, float $amount, string $reason, ?Model $reference = null): WalletTransaction
```

Both methods: `DB::transaction()`, lock the user row (`lockForUpdate()`),
validate (`debit` throws if `$amount > $user->wallet_balance`), update
`wallet_balance`, insert the ledger row with `balance_after` snapshot, return
the transaction record. This is the only place balance math happens —
callers (approval action, checkout, cancel hook) never do arithmetic on
`wallet_balance` themselves.

## Submission flow (customer)

Routes, under the existing `auth` middleware group in `routes/web.php`:

```
GET  /account/rewards            account.rewards.index
POST /account/rewards            account.rewards.store
```

`account/rewards.blade.php` (new section, linked from account nav):
- Lists the customer's `delivered` orders that have no `reward_submissions`
  row yet, each with an upload form (image input + video input).
- Lists past submissions with status badge: pending / approved (+ amount) /
  rejected (+ reason).
- Shows current `wallet_balance` and a paginated `wallet_transactions`
  history table (date, type, amount, reason, running balance).

`RewardSubmissionController::store`:
- `$order = Auth::user()->orders()->findOrFail($request->order_id)` — scoped
  to the authenticated user the same way `AccountController` already scopes
  order access (404 for another customer's order, not 403 — same as existing
  invoice/show routes).
- Reject with 422 unless `$order->status === 'delivered'`.
- Reject with 422 if a `reward_submissions` row already exists for this
  order (the unique DB constraint is the hard backstop; this check gives a
  clean validation error instead of a 500).
- Validation (server-side, the actual security boundary):
  - `image`: `required|image|max:3072` (KB → 3MB)
  - `video`: `required|mimes:mp4,webm|max:10240` (KB → 10MB)
- Creates `RewardSubmission` with `status = 'pending'`, attaches both files
  via `addMedia($file)->toMediaCollection('image'|'video')`.

Frontend validation (UX only, not trusted): `<input type="file" accept="image/*">`
/ `accept="video/mp4,video/webm"` plus a small inline JS check against
`file.size` (3MB / 10MB) that blocks submit and shows an inline error before
the network round-trip. The server re-validates unconditionally regardless
of what the client reports.

## Admin review (Filament)

New resource `app/Filament/Resources/RewardSubmissions/`, following the
`Review` resource's read-only-customer-fields + moderation-action pattern:

- `RewardSubmissionResource`: `canCreate() => false` (submissions only
  originate from the storefront).
- `Schemas/RewardSubmissionForm.php`: customer/order fields disabled,
  `SpatieMediaLibraryFileUpload::make('image')->disabled()`, video shown via
  a disabled file field or a direct link/preview (dompdf-style stream
  download action if inline video preview isn't practical in the form).
- `Tables/RewardSubmissionsTable.php`: columns for customer, order #,
  thumbnail, status badge, submitted date. Row actions:
  - **Approve** — modal with one required `reward_amount` numeric input
    (₹, `minValue(0.01)`). On submit: `DB::transaction` — update submission
    (`status=approved`, `reward_amount`, `reviewed_at`), call
    `WalletService::credit($submission->user, $amount, 'reward_approved', $submission)`.
  - **Reject** — modal with required `rejection_reason` textarea. On submit:
    update submission (`status=rejected`, `rejection_reason`, `reviewed_at`),
    queue `RewardSubmissionRejected` Mailable (mirrors `OrderPacked`: `Mailable
    implements ShouldQueue`, subject referencing the order number, plain
    Blade view with the reason).
  - Both actions `visible` only while `status === 'pending'` (mirrors the
    `Order` resource's status-gated action visibility).

## Wallet at checkout

`CheckoutController::index`: pass `$walletBalance = Auth::user()?->wallet_balance ?? 0`
to the view. Guest checkout (no `Auth::user()`) gets no wallet option at all.

Checkout view: optional "Use wallet balance" numeric input, client-side
capped at `min(walletBalance, orderTotal)` for UX, server re-validates.

`CheckoutController::store`, inside the existing `DB::transaction` (same
lock ordering already documented in the controller — no new lock added,
wallet debit happens after the order row exists so it can reference it):

1. Validate `wallet_amount` (optional, numeric, `min:0`).
2. Compute `total = subtotal - discountAmount + shippingFee` as today.
3. `walletAmountUsed = min((float) $request->wallet_amount, $total, Auth::user()->wallet_balance ?? 0)` —
   clamped server-side regardless of what the client sent.
4. `payable = $total - $walletAmountUsed`.
5. Create the `Order` with `wallet_amount_used = $walletAmountUsed`,
   `total = $total` unchanged (total always reflects the real order value;
   `wallet_amount_used` is the breakdown, not a discount to `total`).
6. If `$walletAmountUsed > 0`: `WalletService::debit($user, $walletAmountUsed, 'order_payment', $order)`.
7. If `$payable <= 0`: order is fully wallet-paid — set `payment_status =
   'paid'` immediately (`payment_method` stays whatever the customer picked,
   e.g. `cod`, for record purposes; no gateway call, no COD-on-delivery
   collection needed since nothing is owed).
8. Else: existing cod/razorpay path proceeds unchanged, but any amount
   shown to Razorpay is `$payable`, not `$total`.

## Cancel/return auto-refund

Extend `Order::booted()`'s existing `updating` hook, alongside the current
restock branch: when the transition target is in `RESTOCKING_STATUSES`
(`cancelled`, `returned`) and `$order->wallet_amount_used > 0`, call
`WalletService::credit($order->user, $order->wallet_amount_used,
'order_refund', $order)` — only if `$order->user` exists (guest orders with
no `user_id` never had a wallet debit to refund). Guard against double
refund: cancelled/returned are terminal states already (no further status
change possible per `ALLOWED_TRANSITIONS`), so this fires exactly once per
order, same guarantee the existing restock comment relies on.

## Error handling

- Submission validation failures → standard Laravel 422 redirect-back with
  errors (matches `ReviewController`/`CheckoutController` conventions).
- `WalletService::debit` insufficient-balance → thrown exception caught in
  checkout, surfaced as a form validation error, transaction rolled back
  (order not created) — never allow a negative wallet balance.
- Concurrent double-submit of the same order: unique constraint on
  `reward_submissions.order_id` is the hard backstop if two requests race
  past the pre-check.

## Testing

Mirrors the existing `tests/Feature/Verify*Test.php` style (real DB via
`RefreshDatabase`, no mocking of Eloquent):

- Submission eligibility: only `delivered` orders eligible, only the owning
  customer can submit, one submission per order enforced.
- Upload validation: image >3MB rejected, video >10MB rejected, wrong mime
  rejected — both via Filament/controller-level `UploadedFile::fake()`.
- Approval: sets `reward_amount`, credits wallet, ledger row correct,
  `balance_after` matches.
- Rejection: sets reason, queues the Mailable, no wallet change.
- Checkout: partial wallet use reduces payable correctly; full-cover wallet
  use skips payment gateway and marks paid; insufficient balance is clamped,
  never overdraws.
- Cancel/return: wallet portion refunded exactly once; guest orders
  (`user_id = null`) don't error.
