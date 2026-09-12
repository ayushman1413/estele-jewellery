# Admin Invite Flow — Design

Date: 2026-09-12
Repo: estele-jewellery/backend (Laravel + Filament v5, spatie/laravel-permission + filament-shield)

## Goal

Super admin can invite a new admin from the Filament panel by entering
name, email, and a role (existing role, or a brand-new role created
inline with permission checkboxes). On save, the invitee gets an email
with a link. Following the link lets them set their own password, then
logs them in and redirects to `/admin`, where they immediately have
whatever access their assigned role grants.

## Data model

New table `admin_invitations`:

| column       | type                          | notes                                   |
|--------------|-------------------------------|------------------------------------------|
| id           | bigint pk                     |                                          |
| name         | string                        |                                          |
| email        | string                        | unique together with pending (see below) |
| role_name    | string                        | Spatie role name to assign on accept     |
| token_hash   | string                        | sha256 of plaintext token, hidden        |
| expires_at   | timestamp                     | now() + 48 hours at creation/resend      |
| accepted_at  | timestamp nullable            | set when invite is completed             |
| invited_by   | foreignId nullable -> users   | who sent it                              |
| timestamps   |                                |                                          |

Model `App\Models\AdminInvitation`:
- `$hidden = ['token_hash']`
- `generateToken()` static: creates plaintext token (`Str::random(64)`),
  stores `hash('sha256', $token)`, returns plaintext (mirrors
  `OldJewelleryVendorInvitation`'s pattern).
- `scopePending()`: `whereNull('accepted_at')->where('expires_at', '>', now())`
- `scopeExpired()`: `whereNull('accepted_at')->where('expires_at', '<=', now())`
- `isValid(string $plaintextToken)`: hash-compares and checks not expired/accepted.

No `User` row is created until the invite is accepted — a `User` row
existing already implies "active", so status is derived, not stored
twice.

## Filament: "Admins" resource

New resource `app/Filament/Resources/Admins/AdminResource.php`, based
on the `User` model but scoped to roled users
(`User::query()->whereHas('roles')`) unioned in the table with pending
`AdminInvitation` rows. Simplest implementation: back the Filament
table with `AdminInvitation` as the source of truth for the list
(covers both pending and — once accepted — a joined `User`), since
every admin, past or present, has exactly one invitation row. This
avoids hand-rolling a SQL UNION across two Eloquent models inside
Filament's table builder.

Table columns: Name, Email, Role (badge), Status (Pending / Active
badge — Active when `accepted_at` is set), Invited At, Invited By.

Row actions:
- **Resend** (visible only when pending, including expired-but-unaccepted):
  regenerates token + `expires_at`, re-sends `AdminInvited` notification.
- **Revoke** (visible only when pending): deletes the invitation row.
  No `User` exists yet, so nothing else to clean up.
- **Edit role** (visible only when Active): lets a super admin change
  an existing admin's role — `syncRoles()` on the linked `User`.

New/Create page: form with Name, Email, Role `Select`. The Select's
options are existing role names, plus a Filament `CreateOptionAction`
("+ New role") that opens a modal with a role-name input and a
permission checkbox list (grouped by resource, same shape as
filament-shield's own role form) — saving it creates the `Spatie\Role`
immediately and selects it inline, matching "existing aur new bhi"
from the brainstorming answer.

On submit: validate email is not already an active admin (`User` with
that email + a role) and not already a pending invitation (or offer to
resend instead of erroring — resend is friendlier, done via a
duplicate-email check in the create action that redirects to a resend
confirmation rather than throwing a plain validation error). Create
`AdminInvitation`, dispatch `AdminInvited` notification (queued).

## Notification — `App\Notifications\AdminInvited`

`ShouldQueue`, constructor takes `AdminInvitation $invitation` and
`string $plaintextToken` (same shape as `VendorInvitedToBid`). Mail
body: invited-by name, role name, a button/link to
`route('admin.invite.accept', ['token' => $plaintextToken])`, and a
note that the link expires in 48 hours.

## Accept flow (public route, outside the Filament panel guard)

- `GET /admin/invite/accept/{token}` — a plain controller
  (`App\Http\Controllers\AdminInvitationController@show`), not a
  Filament page (Filament panels assume an authenticated context for
  most of their page infrastructure; a bare Blade view is simpler and
  matches how `products.reviews.store` etc. sit outside any panel).
  - Looks up invitation by hashing the incoming token and matching
    `token_hash`; 48h expiry and "already accepted" both render a
    same-shaped error view ("This invite link is no longer valid. Ask
    your admin to resend it.") — no distinction needed, avoids leaking
    which case it was.
  - Valid: renders a minimal form (name/email shown read-only, two
    password fields) posting to the `store` action below.
- `POST /admin/invite/accept/{token}` — `@store`:
  - Re-validates token (same check as `show`, race-safety against
    double-submit / already-expired-by-the-time-they-click-submit).
  - Validates `password` (`required, confirmed, min:8`).
  - `DB::transaction`: create `User` (name, email, password →
    `Hash::make`), `assignRole($invitation->role_name)`, set
    `$invitation->accepted_at = now()`, save.
  - `Auth::login($user)` (using the `web` guard — the same guard
    Filament's admin panel uses per `AdminPanelProvider`), then
    `redirect()->to('/admin')`.
  - Because `canAccessPanel()` is `$this->roles()->exists()` and the
    role was just assigned in the same transaction, the redirect lands
    the new admin straight into a panel session with their role's
    permissions already active — no extra wiring needed there.

Routes registered in `routes/web.php` (grouped, named
`admin.invite.show` / `admin.invite.accept`), throttled
(`throttle:10,60`) to blunt token brute-forcing, same spirit as the
existing review-submission throttle.

## Error handling

- Invalid/expired/already-accepted token → single generic error view,
  no auto-resend from that page (resend is a super-admin action from
  the Admins table, not self-service, since only an authenticated
  admin should be able to trigger a new invite mail).
- Duplicate invite for an email that already has a pending invitation
  → the create form blocks and points at the existing row's Resend
  action instead of creating a second, conflicting invitation.
- Mail send failure (e.g. SMTP misconfigured): since the notification
  is queued, a transient failure retries via the queue's normal
  retry/backoff; a permanent failure lands in `failed_jobs` — no new
  handling needed beyond what already exists for `VendorInvitedToBid`.

## Testing

- Feature test: super admin creates an invite → `AdminInvitation` row
  exists, `AdminInvited` queued with correct invitation+token.
- Feature test: visiting accept URL with a valid token renders the
  form; with an expired/invalid token renders the error view.
- Feature test: posting a valid password creates the `User` with the
  right role, marks `accepted_at`, logs the user in, and redirects to
  `/admin`.
- Feature test: resend regenerates the token (old token no longer
  valid, new one is) and keeps `accepted_at` null.
- Feature test: revoke deletes the pending invitation and it no longer
  appears in the Admins table.

## Out of scope

- Self-service password reset for existing admins (separate feature,
  not requested).
- Auditing/history of role changes.
- Rate-limiting invite creation itself (only the public accept route
  is throttled).
