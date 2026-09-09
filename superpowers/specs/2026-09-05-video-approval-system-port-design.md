# Video Approval System — Port to `ayush-feat` + Mobile-First UI — Design

Date: 2026-09-05
Status: Approved

## Context

The full Video Approval System (reward submissions + wallet + multi-vendor
approval) already exists, fully built and tested, on branch
`feat/reward-submissions-wallet` (pushed to origin, working tree clean, last
commit `45a737d`). It was built to spec in two passes:

- `docs/superpowers/specs/2026-09-02-reward-submissions-wallet-design.md`
- `docs/superpowers/specs/2026-09-04-video-approval-system-design.md`

Both docs (available in that branch/worktree) remain the source of truth for
backend behavior and are not repeated here. This spec covers only:

1. **Porting** that branch's backend work onto `ayush-feat`, whose git
   history is disjoint from the feature branch (different root commit — no
   `git merge-base` exists), so a normal merge is not usable.
2. **Rebuilding the UI** against `ayush-feat`'s current burgundy/ivory/gold
   design system (`5602620`, `1d2f0c6`), which postdates the feature
   branch's views — the old branch's Blade templates were written against
   an earlier visual design and must not be copied as-is.
3. **Widening mobile-responsive scope** beyond the original pass (which
   covered only the upload widget + approval table/modal) to every screen
   the user listed: customer dashboard, upload, preview, approval status,
   admin login, super admin dashboard, approval/denial forms, vendor
   wallet/status.

Confirmed with user:
- Approval rule stays as built: **any one** reviewer (vendor or
  super_admin) approving/denying locks the request for everyone else — not
  a two-signoff requirement.
- Mobile audit widens to all listed screens, not just the original narrow
  set.
- Backend logic ports as-is (already tested); only views are rebuilt.

## What ports unchanged (backend)

Straight copy from the feature-branch worktree onto `ayush-feat`, adjusted
only for any path/namespace drift found during porting:

**Models / Services / Policies**
- `app/Models/RewardSubmission.php`, `app/Models/WalletTransaction.php`
- `app/Models/User.php`, `app/Models/Order.php` — wallet relations/casts
  (merge into current versions, don't overwrite — both files have diverged
  since the branch point)
- `app/Services/WalletService.php`
- `app/Policies/RewardSubmissionPolicy.php`

**Filament**
- `app/Filament/Resources/RewardSubmissions/**`
- `app/Filament/Resources/WalletManagement/**`
- `app/Filament/Resources/Orders/Pages/EditOrder.php`,
  `Schemas/OrderForm.php` — wallet_amount_used display (merge into current)
- `database/seeders/ShieldSeeder.php` — add `vendor` role (merge)

**Controllers / Mail**
- `app/Http/Controllers/RewardSubmissionController.php`
- `app/Http/Controllers/CheckoutController.php` — wallet debit at checkout
  (merge into current, which has diverged)
- `app/Mail/NewRewardSubmissionNotification.php`,
  `RewardSubmissionRejected.php`, `WalletCredited.php`

**Migrations** (all additive, safe to copy verbatim, only new tables/columns)
- `create_reward_submissions_table`
- `create_wallet_transactions_table`
- `add_wallet_balance_to_users_table`
- `add_wallet_amount_used_to_orders_table`
- `add_reviewed_by_to_reward_submissions_table`

**Routes** — `account.rewards.index`/`.store` additions to `routes/web.php`
(merge, current file has diverged)

**Tests** — all `VerifyReward*Test.php`, `VerifyWallet*Test.php`,
`VerifyCheckoutWalletUsageTest.php` port as-is; re-run against `ayush-feat`
to confirm nothing in the divergence broke them.

**Non-UI email templates** — `resources/views/emails/new-reward-submission.blade.php`,
`reward-submission-rejected.blade.php`, `wallet-credited.blade.php` port
as-is (plain transactional emails, not part of the storefront design
system; light pass only to confirm they render on a phone-width email
client, per the mobile-responsive scope).

## What gets rebuilt (UI)

Every customer- and admin-facing screen is rebuilt fresh against current
`ayush-feat` conventions rather than copied, because the design system
changed after the feature branch was written. Reference patterns: the
tokens and structure already visible in `account/index.blade.php`
(`accent`/`heading`/`pinksoft`/`line`/`price`/`salebadge`/`muted`, uppercase
tracked headers, `rounded-lg` bordered cards, `text-[13px]`/`[14px]` scale,
single-column-collapsing grids).

### Customer dashboard (`account/rewards.blade.php`)

New page, linked from `account/index.blade.php`'s nav list (same pattern as
the existing Addresses link) and from the mobile-only shortcut link pattern
already used for Addresses. Layout mirrors `account/index.blade.php`'s
overall shell (breadcrumb, `max-w-wrapper`, heading row) rather than
inventing a new page chrose.

Sections, stacked on mobile / two-column on desktop:
- **Eligible orders to submit** — delivered orders with no submission yet,
  card-per-order (same `rounded-lg border border-line` card as order
  history), each with an inline upload form.
- **Past submissions** — card list, status badge using the same badge
  pattern as order status (`bg-pinksoft`/`text-accent` pending, green
  success / red danger equivalents for approved/rejected using tokens
  already in the Tailwind theme), approved amount shown in `text-price`
  style, rejection reason shown when present.
- **Wallet balance** — a prominent stat card at the top (balance in
  `text-price` large weight) plus a paginated transaction history table
  below (date, type, amount, reason, running balance), using the same
  table treatment as order history rows collapse to cards on mobile.

### Video/image upload + preview

Inline within each eligible-order card:
- `<input type="file" accept="image/*">` and `accept="video/mp4,video/webm">`,
  styled to match existing form inputs
  (`border border-line-strong bg-white px-4 py-3 text-[14px]`).
- Immediate client-side preview after file selection — `<img>` for the
  image, `<video controls>` for the video, both `max-w-full` and capped
  height so a phone-width viewport never needs horizontal scroll.
  Also enforces the existing 3MB/10MB client-side size check (UX only,
  server re-validates) with an inline error message using the
  `text-salebadge` error-text convention already used for form errors.
- Submit button matches the full-width mobile button convention already
  used in the Profile/Password forms (`inline-flex w-full ... bg-accent`).

### Approval status (customer-visible)

Same badge component used across the dashboard — pending (`pinksoft`/
`accent`), approved (success token + amount), rejected (danger token +
reason) — reused identically wherever status appears (dashboard list,
order detail if linked).

### Admin login

Filament's own auth screen is already responsive by default (confirmed:
out of scope to rebuild per the prior spec's scoping decision, and
re-confirmed here — no product-facing customization exists to preserve, so
a custom mobile pass here would be re-theming Filament chrome, not this
feature). This item is included in the audit checklist (below) as a
verify-only step: load it at a phone viewport, confirm no horizontal
scroll or clipped inputs, fix only if actually broken.

### Admin video approval screen / Super admin dashboard

`RewardSubmissionsTable` (list view) and the Approve/Deny modals:
- Table columns already have `toggleable(isToggledHiddenByDefault: true)`
  applied to secondary columns (order #, reviewed-by, timestamps) from the
  prior pass — carries over unchanged.
- New this pass: verify the **primary always-visible columns** (customer,
  status, reward amount) plus the row actions (Approve/Deny/Edit) don't
  force horizontal scroll at a 375px viewport; Filament tables scroll
  horizontally by default when content overflows, which is the failure
  mode to check for and fix by moving more columns to
  `toggleable(isToggledHiddenByDefault: true)` if needed.
- Approve/Deny modal forms (amount input, reason textarea): Filament modals
  are full-width on mobile by default — verify the numeric/textarea inputs
  and submit button are comfortably tappable, not a purely visual check.
- Image/video preview inside the row/modal: same `max-w-full`-capped
  treatment as the customer upload preview, so a reviewer can view the
  submitted proof without pinch-zooming on a phone.

### Vendor wallet/status

"Vendor" here is the internal reviewer role, which has no wallet of its
own (confirmed out of scope in the prior spec — vendor is not a
marketplace seller). "Vendor wallet/status" in the user's ask maps to two
things already covered above: (a) the reviewed-by/reviewed-at/status
columns in the admin table showing what a given vendor has done, and (b)
the customer wallet page. No separate vendor-facing wallet screen exists
or is being added — flagged here explicitly so it isn't silently dropped
nor misread as new scope.

## Mobile-responsive audit checklist

Concrete pass/fail check for every listed screen, run at a 375px-wide
viewport in addition to desktop:

- [ ] Customer dashboard (`account/rewards.blade.php`) — no horizontal
      scroll, cards stack, wallet stat card readable
- [ ] Upload form — file inputs full-width, tappable, no overflow
- [ ] Video/image preview — capped height, `max-w-full`, controls usable
- [ ] Approval status badges — legible at small width, don't wrap oddly
- [ ] Admin login — verify only (Filament default), fix if broken
- [ ] Super admin / vendor list table — no forced horizontal scroll on
      primary columns
- [ ] Approve/Deny modal forms — inputs and buttons comfortably tappable
- [ ] Wallet transaction history table — collapses sanely, no clipped
      columns

## Error handling

Unchanged from the ported backend specs — see the two referenced design
docs for the full list (validation, insufficient balance, concurrent
double-submit, concurrent double-approval). No new error paths introduced
by the port or the UI rebuild.

## Testing

- Port all existing `VerifyReward*Test.php` / `VerifyWallet*Test.php` /
  `VerifyCheckoutWalletUsageTest.php` files verbatim; run full suite on
  `ayush-feat` post-port to confirm the divergence (admin tables,
  pagination, INR formatting changes already on `ayush-feat`) hasn't broken
  anything they touch.
- No new backend test cases needed (approval-lock, attribution, and
  notification behavior are already covered by the ported suite).
- UI verification is manual per the checklist above (Playwright screenshot
  pass at mobile + desktop viewports for the rebuilt views), not new
  automated tests — this repo's existing pattern for Filament/Blade UI
  work (see the `05195ed` custom-review-feature commit) is
  Playwright-verified-by-hand, not asserted in PHPUnit.

## Out of scope (carried over from the prior spec, still true)

- No real token-based JSON/mobile API (Sanctum, `/api/vendor/*`) — the
  vendor mobile app is future work; this pass keeps the locking/attribution
  logic in the model layer so an API can reuse it later without a rewrite.
- No dual-approval / two-signoff workflow — single-reviewer lock is the
  confirmed behavior.
- No super-admin override/reopen of an already-reviewed submission.
