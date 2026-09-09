# Old Jewellery & Wallet API

This is the API a frontend developer needs to build the "Sell Your Old
Jewellery" feature: a customer submits a video of old jewellery, the backend
invites vendors to bid on it, the highest bid gets accepted, and the payout
lands in the customer's in-app wallet as spendable credit. This document is
both a plain-language walkthrough of that flow and the full endpoint
reference for it.

Base URL: `{APP_URL}/api/v1`

This is a separate, new API surface (`routes/api.php`, Sanctum token auth) —
distinct from the rest of the storefront, which is session/cookie-based. If
you're integrating a `login`/`register` flow elsewhere in the app, see
`api-reference.md` at the repo root; everything in *this* document is scoped
to old-jewellery + wallet.

---

## 1. The flow, in plain language

There are three actors: the **customer** (uses your frontend, logged in),
the **vendor** (never logs in — acts entirely through a one-time link sent by
email/WhatsApp), and the **admin** (uses the Filament admin panel, not
covered here except for completeness).

1. **Customer submits a request.** They upload a required video (and
   optionally a photo + description) of the jewellery they want to sell.
   This creates an `OldJewelleryRequest` with status `pending`, which the
   backend immediately advances to `submitted` and then `vendors_notified`
   as it fires off invitations to every active vendor.
2. **A 3-hour bidding window opens.** The instant vendors are notified, the
   request moves to `bidding_active` and `bidding_end_at` is set to exactly
   3 hours from creation. **Your frontend's job during this window**: show
   the customer a countdown to `bidding_end_at` and poll (or otherwise
   refresh) the status/detail endpoint so they see bids/responses arrive
   live — there is no websocket/push channel, this is poll-based.
3. **Vendors respond via their unique link** (`/vendor/old-jewellery/{token}`
   — not something your customer-facing frontend calls, but useful to know
   exists: this is a separate, unauthenticated page a vendor opens from
   their email/WhatsApp). Each vendor can either submit one bid amount
   (`accept`) or `decline`, once, before the window closes. Vendors do not
   see each other's bids (blind auction). The admin can also submit a
   competing bid through the admin panel.
4. **The window closes** (automatically via a scheduled job at
   `bidding_end_at`, or an admin can force-close early). Status becomes
   `bidding_closed`, then the system picks a winner: the highest valid bid;
   ties go to whichever bid was submitted earliest. If nobody bid, the
   request ends at `no_valid_bids` and nothing is credited.
5. **Winning bid selected** (`bid_selected`) — `final_amount` is now set on
   the request to the winning bid amount.
6. **Wallet credit is calculated and applied** (`wallet_pending` →
   `wallet_credited`): a flat 10% platform fee is deducted, and the
   remaining 90% is credited to the customer's wallet. `deduction_amount`
   and `credited_amount` are now populated. The customer gets notified
   (email/WhatsApp) that money has landed in their wallet.
7. **The credit is spendable, with an expiry.** Old-jewellery wallet credit
   expires exactly **10 days** after it's credited. Your frontend should
   surface this expiry prominently (e.g. "₹990 expires in 6 days") using the
   old-jewellery-credits endpoint below — this is different from the
   customer's regular (non-expiring) wallet balance. At checkout, expiring
   old-jewellery credit is spent automatically before regular wallet
   balance, soonest-expiry-first — the frontend doesn't need to implement
   that logic, just needs to show the balance and expiry correctly.
8. **Terminal states**: `wallet_expired` (credit window passed unused —
   money is gone, this is a real outcome to design for in the UI),
   `completed` (credit was used), `no_valid_bids`, or `cancelled` (can
   happen at almost any stage).

### What the frontend needs to render at each stage

| Request status | What the customer should see |
|---|---|
| `pending` / `submitted` / `vendors_notified` | "Submitting your request…" — brief, transitional |
| `bidding_active` | Countdown to `bidding_end_at`; "vendors are reviewing" |
| `bidding_closed` | "Selecting the winning offer…" — brief, transitional |
| `bid_selected` | Winning amount (`final_amount`) shown, payout in progress |
| `wallet_pending` | Same as above, briefly |
| `wallet_credited` | Success: amount credited (`credited_amount`), expiry date |
| `wallet_expired` | Credit expired unused — no longer spendable |
| `completed` | Credit was used at checkout |
| `no_valid_bids` | No vendor bid — request closed with no payout |
| `cancelled` | Request was cancelled |

Poll `GET /old-jewellery/requests/{request_number}/status` (lightweight) for
the countdown/status-only view, and the full `show` endpoint when you need
everything (description, media URLs, bids) — e.g. on a request detail page.

---

## 2. Response envelope

All responses use this envelope:

Success:
```json
{ "success": true, "message": "...", "data": { ... } }
```

Validation error (422):
```json
{ "success": false, "message": "Validation failed.", "errors": { "video": ["A video is required."] } }
```

Domain error (409, e.g. bidding closed / already responded):
```json
{ "success": false, "message": "The bidding window for this request has closed." }
```

Authorization error (403):
```json
{ "success": false, "message": "This action is unauthorized." }
```

Unauthenticated (401):
```json
{ "success": false, "message": "Unauthenticated." }
```

## 3. Authentication

Customer and admin routes require a Sanctum bearer token:

```
Authorization: Bearer {token}
```

Vendor routes require no token — the `{token}` path segment IS the credential (a
long random string emailed/WhatsApp'd to the vendor). It expires when the
request's 3-hour bidding window closes and is single-use for accept/decline.

## 4. Customer Endpoints

### Create an old jewellery request

`POST /api/v1/old-jewellery/requests` — `auth:sanctum`, `multipart/form-data`

| Field | Type | Required | Notes |
|---|---|---|---|
| description | string | no | max 2000 chars |
| image | file | no | jpg/jpeg/png/webp, max 3MB |
| video | file | **yes** | mp4/mov, max 20MB |

curl:
```bash
curl -X POST "{APP_URL}/api/v1/old-jewellery/requests" \
  -H "Authorization: Bearer {token}" \
  -H "Accept: application/json" \
  -F "description=Gold-plated necklace" \
  -F "video=@/path/to/video.mp4" \
  -F "image=@/path/to/image.jpg"
```

Success (201):
```json
{
  "success": true,
  "message": "Old jewellery request created successfully.",
  "data": {
    "request_number": "OJ-2026-000123",
    "status": "vendors_notified",
    "bidding_start_at": "2026-09-09T10:00:00+00:00",
    "bidding_end_at": "2026-09-09T13:00:00+00:00",
    "final_amount": null,
    "credited_amount": null
  }
}
```

Validation error (422) — missing video:
```json
{ "success": false, "message": "Validation failed.", "errors": { "video": ["A video is required."] } }
```

### List my requests

`GET /api/v1/old-jewellery/requests` — `auth:sanctum` — paginated, 15/page.

### View one request

`GET /api/v1/old-jewellery/requests/{request_number}` — `auth:sanctum`

Returns 403 if the request belongs to another customer.

### Check status only

`GET /api/v1/old-jewellery/requests/{request_number}/status` — `auth:sanctum`

Lightweight endpoint — use this for polling/countdown UI instead of the full
`show` endpoint.

```json
{ "success": true, "data": { "request_number": "OJ-2026-000123", "status": "bidding_active", "bidding_end_at": "2026-09-09T13:00:00+00:00" } }
```

## 5. Wallet Endpoints

### Wallet balance

`GET /api/v1/wallet` — `auth:sanctum`

Total spendable balance (includes both regular and active old-jewellery
credit).

```json
{ "success": true, "data": { "balance": "990.00" } }
```

### Transaction history

`GET /api/v1/wallet/transactions` — `auth:sanctum` — paginated, 20/page.

### Old-jewellery-specific credits (with expiry)

`GET /api/v1/wallet/old-jewellery-credits` — `auth:sanctum` — paginated, 20/page.

Use this to render "your old jewellery credit expires in N days" UI — the
plain `/wallet` balance endpoint above doesn't break out expiry per credit.

```json
{
  "success": true,
  "data": [
    {
      "request_number": "OJ-2026-000123",
      "gross_amount": "1100.00",
      "deduction_amount": "110.00",
      "credited_amount": "990.00",
      "remaining_amount": "990.00",
      "credited_at": "2026-09-08T13:00:00+00:00",
      "expires_at": "2026-09-18T13:00:00+00:00",
      "status": "active"
    }
  ]
}
```

`status` will be `active`, `expired`, or `used` depending on whether the
credit is still spendable, expired unused, or already spent.

## 6. Vendor Endpoints (token-authenticated, no bearer token)

These are not called by the customer-facing frontend — they're the pages a
vendor opens from the link in their notification email/WhatsApp message.
Documented here for completeness / in case a vendor-facing mini-page is ever
built against this same API.

### View the request

`GET /api/v1/vendor/old-jewellery/{token}`

```json
{
  "success": true,
  "data": {
    "request_number": "OJ-2026-000123",
    "description": "Gold-plated necklace",
    "bidding_end_at": "2026-09-09T13:00:00+00:00",
    "response_status": "pending",
    "image_url": "https://.../thumb.png",
    "video_url": "https://.../old-jewellery/vendor-video/42?signature=..."
  }
}
```

Expired token (403):
```json
{ "success": false, "message": "This vendor link has expired." }
```

Invalid token (404):
```json
{ "success": false, "message": "Invalid or unrecognized vendor link." }
```

### Accept with a bid

`POST /api/v1/vendor/old-jewellery/{token}/accept`

| Field | Type | Required |
|---|---|---|
| amount | numeric > 0 | yes |

curl:
```bash
curl -X POST "{APP_URL}/api/v1/vendor/old-jewellery/{token}/accept" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"amount": 950}'
```

Already responded / expired (409/403):
```json
{ "success": false, "message": "This invitation has already been responded to." }
```

### Decline

`POST /api/v1/vendor/old-jewellery/{token}/decline`

| Field | Type | Required |
|---|---|---|
| reason | string | no, max 1000 chars |

## 7. Admin Endpoints

All require `auth:sanctum` + the corresponding Shield permission
(`ViewAny:OldJewelleryRequest` / `Update:OldJewelleryRequest`). Not relevant
to the customer-facing frontend — listed for completeness. Day-to-day admin
work happens in the Filament panel; these are the underlying API routes it
(or any admin tooling) calls.

### List all requests

`GET /api/v1/admin/old-jewellery`

### View one request (full detail: all bids, all vendor responses, admin bid)

`GET /api/v1/admin/old-jewellery/{request_number}`

### List bids for a request

`GET /api/v1/admin/old-jewellery/{request_number}/bids`

### Submit an admin valuation

`POST /api/v1/admin/old-jewellery/{request_number}/bid`

| Field | Type | Required |
|---|---|---|
| amount | numeric > 0 | yes |

Rejected after deadline (409):
```json
{ "success": false, "message": "The bidding window for this request has closed." }
```

### Force-close bidding now

`POST /api/v1/admin/old-jewellery/{request_number}/close`

Idempotent — calling this after the request is already closed returns the
request unchanged rather than erroring.

## 8. Business rules reference

- **Bidding window**: exactly 3 hours from request creation
  (`bidding_end_at = bidding_start_at + 3h`), closed automatically by a
  scheduled job, or earlier by an admin force-close.
- **One bid per vendor per request** — no revisions once submitted; this is
  enforced at the database level, not just in application code.
- **Highest valid bid wins**; ties are broken by earliest submission time.
  The admin's own bid competes on equal footing with vendor bids.
- **10% platform fee** is deducted from the winning bid; the remaining 90%
  is credited to the customer's wallet (e.g. a ₹1100 winning bid credits
  ₹990).
- **Wallet credit expires exactly 10 days** after it is credited
  (`expires_at = credited_at + 10 days`). Unused credit past that date is
  gone (`wallet_expired`) — this is a real, user-visible outcome the
  frontend should design for, not an edge case to ignore.
- **Spend order at checkout**: expiring old-jewellery wallet credits are
  spent before any non-expiring wallet balance, soonest-expiry-first. The
  frontend does not need to implement this — the backend handles it
  automatically at checkout — but should show the customer their expiring
  balance so this isn't a surprise.
- **Full request status lifecycle** (for reference — most of these are
  transient and won't be visible long enough to matter in the UI, see the
  table in §1):
  `pending → submitted → vendors_notified → bidding_active → bidding_closed
  → bid_selected → wallet_pending → wallet_credited → (wallet_expired |
  completed)`, with `no_valid_bids` reachable from `bidding_closed` and
  `cancelled` reachable from most non-terminal states.

## 9. Environment variables

Backend/ops reference — not needed by the frontend, included for completeness.

| Variable | Purpose | Default behavior when blank |
|---|---|---|
| `WHATSAPP_PROVIDER` | WhatsApp Business API provider name | Falls back to logging messages instead of sending |
| `WHATSAPP_API_KEY` | Provider API key | — |
| `WHATSAPP_API_URL` | Provider API base URL | — |
| `WHATSAPP_FROM_NUMBER` | Sending number | — |

No WhatsApp provider is wired up yet (by product decision) — vendor
notifications currently only go out by email until credentials are supplied
and a real gateway class is added alongside the existing log-only stub.
