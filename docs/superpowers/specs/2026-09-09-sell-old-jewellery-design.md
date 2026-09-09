# Sell Your Old Jewellery — Backend Design

Status: approved. Scope: `backend/` Laravel app only. No public frontend work.

## 1. Summary

Customer submits old jewellery (video required, image optional) → all active
vendors invited via secure token links → 3-hour blind bidding → admin can also
bid → highest valid bid wins → 10% platform deduction → 90% credited to
customer wallet → credit expires in 10 days → usable at checkout.

## 2. Existing systems reused (audited facts)

- **Wallet**: `WalletService::credit()/debit()` (`app/Services/WalletService.php`)
  is the only path that mutates `users.wallet_balance` / writes
  `wallet_transactions`. Both methods lock the user row
  (`lockForUpdate()`) inside `DB::transaction`. Reused, extended — not
  duplicated.
- **Ledger**: `wallet_transactions` (append-only, `UPDATED_AT = null`,
  polymorphic `reference` morphTo). Old-jewellery credits/debits post normal
  rows here too, so `users.wallet_balance` stays the single source of truth
  for "spendable now."
- **Media pattern**: `RewardSubmission` (`app/Models/RewardSubmission.php`)
  is the direct precedent — `InteractsWithMedia`, `image`/`video`
  collections, originals on the private `original_images` disk, conversions
  on `public`. Old jewellery requests follow the identical pattern.
- **Status pattern**: `Order::ALLOWED_TRANSITIONS` const + `updating()` model
  hook (`app/Models/Order.php:15-23,125-152`). Reused verbatim as a pattern
  for `OldJewelleryRequest`.
- **Permissions**: Spatie `laravel-permission` + Filament Shield
  (`database/seeders/ShieldSeeder.php`). New resources get
  `{Action}:{Resource}` permissions auto-generated the same way; `super_admin`
  gets all.
- **Queued mail**: `Mail::to(...)->queue(...)` pattern (as in
  `WalletService.php:39`). Old-jewellery mail follows suit.

## 3. New infrastructure (none of this exists yet, all required)

- **Sanctum** — installed for customer-facing API token auth. Existing `web`
  session guard untouched; storefront/session login unaffected. New `api`
  guard added in `config/auth.php`, `sanctum` middleware on `routes/api.php`.
- **`routes/api.php`** — first API route file in the project; registered in
  `bootstrap/app.php` under an `api:` key, prefix `api/v1`.
- **`app/Jobs/`** — first Job classes (bidding close, wallet expiry,
  reminders). Queue connection stays `database` (already configured,
  Hostinger-safe — no Redis worker dependency required, though Redis stays
  available for cache).
- **`bootstrap/app.php` `->withSchedule()`** — first scheduler registration
  in the project.
- **`app/Notifications/`** — first Notification classes (needed for a
  WhatsApp channel; Mailables can't carry a WhatsApp channel). Existing
  Mailables in `app/Mail/` are untouched; old-jewellery notifications use
  Laravel Notifications so both mail and WhatsApp channels can be declared
  on one class.
- **WhatsApp**: `WhatsAppGateway` interface + `LogWhatsAppGateway` stub
  (mirrors `LogOtpGateway`). No credentials exist today, so this ships as a
  log-only stub; swapping in a real provider later is a one-class,
  one-config-binding change.

## 4. Data model

### `vendors`
Standalone entity, admin-managed via Filament, **no login**. Acts only via
per-invitation signed tokens.

| column | notes |
|---|---|
| id | |
| name | |
| company_name | nullable |
| mobile | unique |
| mobile_verified_at | nullable timestamp |
| email | nullable |
| whatsapp_number | nullable |
| is_active | bool, indexed |
| timestamps | |

### `old_jewellery_requests`

| column | notes |
|---|---|
| id | |
| request_number | unique, format `OJ-2026-000123`, indexed |
| user_id | FK → users, indexed |
| description | text, customer notes |
| status | string, indexed — see §5 |
| bidding_start_at | timestamp, indexed |
| bidding_end_at | timestamp, indexed (start + 3h, server-computed) |
| winning_bid_id | nullable FK → old_jewellery_bids, set at close |
| final_amount | nullable decimal(12,2) — winning bid amount |
| deduction_amount | nullable decimal(12,2) — 10% |
| credited_amount | nullable decimal(12,2) — 90% |
| closed_at | nullable timestamp |
| timestamps | |

Media: `InteractsWithMedia`, collections `image` (single, optional, ≤3MB,
jpg/jpeg/png/webp) and `video` (single, **required**, ≤20MB, mp4/mov).
Same disk pattern as `RewardSubmission` (`original_images` private → `public`
conversions).

### `old_jewellery_vendor_invitations`

| column | notes |
|---|---|
| id | |
| old_jewellery_request_id | FK, indexed |
| vendor_id | FK, indexed |
| token_hash | unique, indexed — SHA-256 of the plaintext token; plaintext never stored |
| expires_at | timestamp, indexed (= request's `bidding_end_at`) |
| response_status | string: `pending`, `accepted`, `declined` |
| decline_reason | nullable text |
| responded_at | nullable timestamp |
| notified_at | nullable timestamp — email/WhatsApp dispatch marker |
| timestamps | |

Unique constraint `(old_jewellery_request_id, vendor_id)` — one invitation
per vendor per request. Plaintext token is generated with
`Str::random(64)` equivalent cryptographically secure random bytes, returned
once in the notification link, never persisted or logged in plaintext.

### `old_jewellery_bids`

| column | notes |
|---|---|
| id | |
| old_jewellery_request_id | FK, indexed |
| bidder_type | string: `vendor` \| `admin` |
| vendor_id | nullable FK (null when `bidder_type = admin`) |
| admin_user_id | nullable FK → users (null when `bidder_type = vendor`) |
| invitation_id | nullable FK → old_jewellery_vendor_invitations |
| amount | decimal(12,2) |
| is_valid | bool, default true — false only if later invalidated (e.g. request cancelled) |
| submitted_at | timestamp, indexed |
| timestamps | |

One row per accepted bid (vendor decline never creates a bid row — decline
lives on the invitation). Per decision, a vendor gets **exactly one** bid row
via a unique constraint `(old_jewellery_request_id, vendor_id)` where
`vendor_id` is not null — enforced at DB level, not just app level.

### `old_jewellery_activity_logs`

| column | notes |
|---|---|
| id | |
| old_jewellery_request_id | FK, indexed |
| actor_type | string: `customer` \| `vendor` \| `admin` \| `system` |
| actor_id | nullable unsignedBigInteger (polymorphic-ish but kept simple — id meaning depends on actor_type) |
| action | string, e.g. `request_submitted`, `vendors_notified`, `vendor_accepted`, `vendor_bid_submitted`, `vendor_declined`, `admin_bid_submitted`, `bidding_closed`, `bid_selected`, `wallet_calculated`, `wallet_credited`, `customer_notified`, `wallet_expired`, `wallet_used`, `no_valid_bids` |
| from_status | nullable string |
| to_status | nullable string |
| metadata | json, nullable |
| created_at | (no updated_at — append-only, matches `WalletTransaction` convention) |

Immutable: no update/delete routes anywhere; not editable in Filament (log
viewer only).

### `old_jewellery_wallet_credits`

Tracks the **expiring** portion of wallet balance separately from the
existing (non-expiring) reward-submission credits, while both still post to
the shared `wallet_transactions` ledger and `users.wallet_balance`.

| column | notes |
|---|---|
| id | |
| user_id | FK, indexed |
| old_jewellery_request_id | FK, unique (one credit per request) |
| wallet_transaction_id | FK → wallet_transactions (the credit-side ledger row) |
| gross_amount | decimal(12,2) — the winning bid |
| deduction_amount | decimal(12,2) |
| credited_amount | decimal(12,2) — amount actually added to wallet |
| remaining_amount | decimal(12,2) — decremented as this specific credit is spent |
| credited_at | timestamp |
| expires_at | timestamp, indexed — `credited_at + 10 days` |
| status | string, indexed: `active`, `partially_used`, `used`, `expired` |
| reminder_3d_sent_at | nullable timestamp |
| reminder_1d_sent_at | nullable timestamp |
| reminder_0d_sent_at | nullable timestamp |
| timestamps | |

Spend order (per decision): checkout debits **expiring credits first**,
soonest-`expires_at` first, decrementing `remaining_amount` on each row as
consumed, before falling through to general (non-expiring) wallet balance.
This bookkeeping layer never lets `users.wallet_balance` itself go negative
or diverge from the ledger — `WalletService::debit()` remains the sole
balance mutator; the credits table only tracks *which portion* of the
balance is time-boxed, for expiry-job purposes and FIFO selection.

### Indexes/FKs summary
`request_number`, `user_id`, `vendor_id`, `status`, `bidding_start_at`,
`bidding_end_at`, `token_hash`, `expires_at` (invitations), `expires_at`
(wallet credits) all indexed per spec requirement. All relations are real FKs
with `restrict`/`cascade` chosen per delete-safety (requests/bids/logs never
hard-deleted in practice; no destructive migrations against existing tables).

## 5. Status state machine (`old_jewellery_requests.status`)

Pattern copied from `Order::ALLOWED_TRANSITIONS`.

```
pending        → submitted, cancelled
submitted      → vendors_notified, cancelled
vendors_notified → bidding_active, cancelled
bidding_active → bidding_closed, cancelled
bidding_closed → bid_selected, cancelled           (bid_selected only if a valid bid exists)
bidding_closed → cancelled                          (no valid bids path — logged as no_valid_bids, terminal-ish but stays cancellable/closeable, no wallet action)
bid_selected   → wallet_pending
wallet_pending → wallet_credited
wallet_credited → wallet_expired                    (system, via expiry job)
wallet_credited → completed                          (optional manual close by admin, not required for wallet correctness)
wallet_expired → completed
cancelled, completed → (terminal)
```

`pending` is set at record creation (before media/DB commit finalizes) so a
row never exists in an ambiguous pre-status state; `submitted` fires the
instant the DB transaction that creates the request + media commits
successfully. Enforced in an `updating()` hook identical in shape to
`Order`'s, throwing `ValidationException` on an illegal transition. No
customer/vendor endpoint can set status directly — status only moves via
service-layer calls.

## 6. Services

- **`OldJewelleryRequestService`** — create request (validates video
  required server-side regardless of client, generates `request_number` via
  a collision-checked `OJ-{year}-{6-digit sequence}` scheme using a DB
  transaction + `lockForUpdate` on a small counter row, attaches media,
  sets `bidding_start_at`/`bidding_end_at` = now/+3h in UTC, moves status
  `pending → submitted`), fetch-for-customer (ownership-scoped), cancel.
- **`VendorInvitationService`** — given a request, fetch all `is_active`
  vendors, create one invitation row each, generate token, dispatch
  notification job per vendor. Idempotent: re-running for a request that
  already has invitations is a no-op (checked before insert).
- **`OldJewelleryBiddingService`** — vendor accept (validates token hash +
  not expired + invitation belongs to that vendor + `bidding_end_at` not
  passed + no existing bid row, inside a transaction with row locking on
  the invitation), vendor decline, admin bid (same amount validation, no
  token — uses authenticated admin session/Filament).  Every method
  independently re-checks `now() < bidding_end_at` — never trusts scheduler
  timing alone, per spec.
- **`OldJewelleryClosingService`** — closes a request: locks the request row
  (`lockForUpdate`), guarded by `status = 'bidding_active' AND bidding_end_at
  <= now()` in the same query used to select for update (so a duplicate job
  run finds zero rows and does nothing — idempotent by construction, not by
  a secondary "already processed" flag alone). Selects all `is_valid` bids
  (vendor accepted-with-amount rows + admin bid), picks max `amount`; tie
  broken by earliest `submitted_at` (documented). No valid bids → status to
  `cancelled` with `no_valid_bids` log entry, no wallet action. Valid bid →
  sets `winning_bid_id`/`final_amount`, moves to `bid_selected`, then calls
  wallet service.
- **`OldJewelleryWalletService`** — given a closed request + winning amount:
  computes `deduction = round(amount * 0.10, 2)`, `credited = amount -
  deduction` (subtraction, not a second multiply, so the two always sum
  exactly to `amount` regardless of rounding), calls existing
  `WalletService::credit()` (creates the `wallet_transactions` row +
  updates `users.wallet_balance`) inside the same DB transaction as creating
  the `old_jewellery_wallet_credits` row, moves status
  `wallet_pending → wallet_credited`. Idempotency guard: unique
  `old_jewellery_request_id` on `old_jewellery_wallet_credits` plus a
  `status IN (bid_selected, wallet_pending)` precondition on the update —
  a second call finds the row already `wallet_credited` and returns early
  without a second credit.
- **`OldJewelleryNotificationService`** — thin wrapper choosing
  mail/WhatsApp channels per recipient type (vendor invite, admin
  new-request alert, customer final-valuation), always queued.

Money: all `decimal(12,2)` columns, PHP-side arithmetic on scaled integers
via `bcmath`-safe rounding (`round(..., 2)` on decimal-cast Eloquent
attributes, which PHP treats as string-backed via the model's `decimal:2`
cast) — no floats persisted.

## 7. API surface (new `routes/api.php`, prefix `/api/v1`)

Auth: Sanctum bearer tokens for customer/admin routes (`auth:sanctum`
middleware); vendor routes use the signed token in the URL — **not**
Sanctum, checked manually in a dedicated middleware
(`VendorTokenAuth`) since vendors have no account.

```
POST   /api/v1/old-jewellery/requests                    [auth:sanctum]
GET    /api/v1/old-jewellery/requests                     [auth:sanctum]
GET    /api/v1/old-jewellery/requests/{request_number}    [auth:sanctum]  (ownership-checked via Policy)
GET    /api/v1/old-jewellery/requests/{request_number}/status [auth:sanctum]

GET    /api/v1/wallet                                      [auth:sanctum]
GET    /api/v1/wallet/transactions                          [auth:sanctum]
GET    /api/v1/wallet/old-jewellery-credits                 [auth:sanctum]

GET    /api/v1/vendor/old-jewellery/{token}                [vendor.token]
POST   /api/v1/vendor/old-jewellery/{token}/accept          [vendor.token]
POST   /api/v1/vendor/old-jewellery/{token}/decline          [vendor.token]

POST   /api/v1/admin/old-jewellery/{request_number}/bid     [auth:sanctum, permission]
GET    /api/v1/admin/old-jewellery                           [auth:sanctum, permission]
GET    /api/v1/admin/old-jewellery/{request_number}          [auth:sanctum, permission]
POST   /api/v1/admin/old-jewellery/{request_number}/close    [auth:sanctum, permission]
GET    /api/v1/admin/old-jewellery/{request_number}/bids     [auth:sanctum, permission]
```

Route-model-binding uses `request_number` (mirrors existing
`order_number` pattern on `Order::getRouteKeyName()`), not raw numeric IDs.
Rate limiting: `throttle` on request creation and vendor accept/decline
(brute-force token guessing mitigation), matching existing throttle usage in
`routes/web.php`.

Response envelope, error format, status codes: exactly as specified in the
prompt's API Response Standard section (`{success, message, data}` /
`{success, message, errors}`), via a base `ApiController` + custom
`ApiResource` wrapping.

## 8. Filament admin

New resources: `OldJewelleryRequests` (list with all spec'd columns; detail
view with media, vendor responses, bids, admin-bid submission form, activity
log relation-manager), `Vendors` (CRUD). Wallet: extend existing
`WalletManagementResource`/table to surface old-jewellery credits (not a new
resource — reuse). All gated by Shield-generated permissions
(`ShieldSeeder::RESOURCES` gains `OldJewelleryRequest`, `Vendor`).

## 9. Jobs & scheduler

- `CloseExpiredOldJewelleryBiddingJob` — scheduled every 5 minutes, queries
  `bidding_active` requests past `bidding_end_at`, dispatches
  `OldJewelleryClosingService` per row.
- `ExpireOldJewelleryWalletCreditsJob` — scheduled daily, marks
  `active`/`partially_used` credits past `expires_at` as `expired`, debits
  the remaining amount from `users.wallet_balance` via
  `WalletService::debit()` (only the *unused remainder*, never touching
  already-spent portion), logs activity.
- `SendOldJewelleryWalletReminderJob` — scheduled daily, for credits expiring
  in 3 days / 1 day / today and not yet reminded at that tier, sends
  notification, stamps the corresponding `reminder_*_sent_at`.

All three: `ShouldBeUnique` (Laravel's built-in job-level dedup) is
insufficient alone since scheduler-delay overlap is explicitly a stated
risk — real idempotency comes from the query preconditions above (a job
that finds no matching rows does nothing), which holds even under manual
re-runs, overlapping cron, or a delayed cron catching up two runs at once.

## 10. Wallet checkout integration

`CheckoutController` (`app/Http/Controllers/CheckoutController.php:252-289`)
already resolves a `wallet_amount` against `users.wallet_balance` and calls
`WalletService::debit()`. This is extended (not replaced) so that the debit
path first consumes matching `old_jewellery_wallet_credits` rows
(soonest-`expires_at` first, decrementing `remaining_amount` and flipping
`status` to `partially_used`/`used` as it goes) before falling through to
the non-expiring remainder — but the actual `users.wallet_balance` mutation
and the `wallet_transactions` row are still written exactly once per
checkout, by the existing `WalletService::debit()` call, inside the same
transaction/row-lock. Expired credits are excluded from the "usable" total
computed server-side; frontend-sent wallet amounts are always clamped
server-side as today.

## 11. Notifications

Laravel Notification classes (mail + WhatsApp channel):
`VendorInvitedToBid`, `AdminNewOldJewelleryRequest`,
`CustomerOldJewelleryFinalized`. All `ShouldQueue`. WhatsApp channel backed
by `WhatsAppGateway` interface; `LogWhatsAppGateway` stub bound in
`AppServiceProvider` when no provider env vars are set (mirrors
`LogOtpGateway`/`OtpManager` binding pattern already in the codebase).

New `.env.example` vars: `WHATSAPP_PROVIDER`, `WHATSAPP_API_KEY`,
`WHATSAPP_API_URL`, `WHATSAPP_FROM_NUMBER` (all blank by default → stub
active, documented in API docs / README section).

## 12. Security recap

- Vendor token: cryptographically random, stored only as SHA-256 hash,
  scoped to exactly one `(request, vendor)` pair, expires at
  `bidding_end_at`, single-use for the accept/decline action (subsequent
  calls find `response_status != pending` and reject).
- Every bid-mutating endpoint re-checks `now() < bidding_end_at`
  server-side regardless of scheduler state.
- Policies: `OldJewelleryRequestPolicy` (customer owns via `user_id`),
  admin routes gated by Shield permissions, vendor routes gated by the
  token middleware (no Sanctum identity involved at all for vendors).
- API Resources strip: token hashes, other vendors' bids/identities, admin
  bid (from vendor-facing payloads), internal file paths (media served via
  signed temporary URLs only).
- Mass assignment: explicit `$fillable` per new model, no `$guarded = []`.
- All financial mutations wrapped in `DB::transaction` + row locks
  (`lockForUpdate`) on the request row during closing and on the user row
  during wallet credit/debit (already true of `WalletService`).

## 13. Testing

PHPUnit (matches existing `phpunit.xml`/`tests/TestCase.php` — no Pest
introduced). Feature tests per the prompt's Testing section list, placed
under `tests/Feature/OldJewellery/` and `tests/Feature/Wallet/`, following
the naming style already used (`VerifyXTest.php`).

## 14. Out of scope / explicitly not built

- No public frontend of any kind.
- No vendor login/dashboard — token-link only, per decision.
- No bid revision/history UI — one bid per vendor, per decision.
- No real WhatsApp provider wiring — stub only, until credentials provided.
- Existing `feat/reward-submissions-wallet` worktree is not touched, merged,
  or reconciled by this work — flagged to the user separately as a
  pre-existing divergent branch to clean up independently.
