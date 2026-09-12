# Admin Invite Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A super admin can invite a new admin (name, email, role — existing or newly-created inline) from the Filament panel; the invitee gets an email, sets their own password via the link, and lands in `/admin` with their role's access already active.

**Architecture:** A new `admin_invitations` table + `AdminInvitation` model stores a hashed, expiring (48h) token per invite (same shape as the existing `OldJewelleryVendorInvitation`/`VendorInvitationService` pattern). A new Filament resource (`Admins`) lists invitations (pending + accepted, joined with `User`), lets a super admin create/resend/revoke invites and pick or create a role inline. A queued notification (`AdminInvited`, mirroring `VendorInvitedToBid`) emails the invite link. Two plain (non-Filament, non-auth-guarded) routes render the "set your password" form and process it — creating the `User`, assigning the role, logging in, and redirecting to `/admin`.

**Tech Stack:** Laravel 11, Filament v5, spatie/laravel-permission (via `HasRoles` on `User`), filament-shield (role/permission naming convention `{Action}:{Resource}`), Laravel queues (`QUEUE_CONNECTION=database`), Pest/PHPUnit (check `tests/Feature/*Test.php` for the project's actual test style before writing tests — this plan uses PHPUnit-style `test_...` methods matching `tests/Feature/VerifyReviewSubmissionTest.php`; if that file uses Pest functions instead, mirror that style).

**Spec:** `docs/superpowers/specs/2026-09-12-admin-invite-flow-design.md`

## Global Constraints

- Token pattern: plaintext `Str::random(64)`, stored as `hash('sha256', $plaintext)` in `token_hash`, plaintext never persisted (only passed to the notification) — exact pattern used by `App\Services\OldJewellery\VendorInvitationService::hashToken()`.
- Invite expiry: 48 hours from creation or resend.
- No `User` row exists until the invite is accepted — status is derived (`accepted_at` null = Pending, set = Active), never double-stored.
- New public routes go in `routes/web.php`, outside both the `guest` and `auth` middleware groups, with `throttle:10,60` (per spec) — following the existing `old-jewellery/vendor/{token}` block's convention of no `auth`/`guest` middleware for token-authenticated routes.
- `Select::createOptionForm()` / `createOptionUsing()` are the correct Filament v5 APIs (`Filament\Forms\Components\Select`, unchanged from v4) — confirmed against `vendor/filament/forms/src/Components/Select.php`.
- Role/permission naming stays consistent with `ShieldSeeder.php`'s `{Action}:{Resource}` convention; a newly-created role via the inline form must be usable by `ReviewPolicy`-style policies immediately (i.e., it's a real `Spatie\Permission\Models\Role` row with `guard_name = 'web'`).
- Every notification that sends mail implements `ShouldQueue` (existing project convention, see `VendorInvitedToBid`).

---

### Task 1: `admin_invitations` migration + `AdminInvitation` model

**Files:**
- Create: `database/migrations/2026_09_12_100000_create_admin_invitations_table.php`
- Create: `app/Models/AdminInvitation.php`
- Test: `tests/Unit/AdminInvitationTest.php`

**Interfaces:**
- Produces: `AdminInvitation` model with fillable `['name', 'email', 'role_name', 'token_hash', 'expires_at', 'accepted_at', 'invited_by']`, hidden `['token_hash']`, casts `expires_at`/`accepted_at` to `datetime`, relation `invitedBy(): BelongsTo` (to `User::class`, foreign key `invited_by`), scopes `scopePending(Builder $query): Builder` and `scopeExpired(Builder $query): Builder`, instance method `isValid(string $plaintextToken): bool`.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('role_name');
            $table->string('token_hash');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('email');
            $table->index(['email', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_invitations');
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: `2026_09_12_100000_create_admin_invitations_table ... DONE`

- [ ] **Step 3: Write the failing model test**

```php
<?php

namespace Tests\Unit;

use App\Models\AdminInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_token_stores_hash_and_returns_plaintext(): void
    {
        $invitation = AdminInvitation::create([
            'name' => 'Jane Admin',
            'email' => 'jane@example.com',
            'role_name' => 'marketing',
            'token_hash' => 'placeholder',
            'expires_at' => now()->addHours(48),
        ]);

        $plaintext = AdminInvitation::generateTokenFor($invitation);

        $this->assertNotEquals('placeholder', $invitation->fresh()->token_hash);
        $this->assertEquals(hash('sha256', $plaintext), $invitation->fresh()->token_hash);
    }

    public function test_is_valid_rejects_wrong_token(): void
    {
        $invitation = AdminInvitation::create([
            'name' => 'Jane Admin',
            'email' => 'jane@example.com',
            'role_name' => 'marketing',
            'token_hash' => hash('sha256', 'correct-token'),
            'expires_at' => now()->addHours(48),
        ]);

        $this->assertTrue($invitation->isValid('correct-token'));
        $this->assertFalse($invitation->isValid('wrong-token'));
    }

    public function test_is_valid_rejects_expired_token(): void
    {
        $invitation = AdminInvitation::create([
            'name' => 'Jane Admin',
            'email' => 'jane@example.com',
            'role_name' => 'marketing',
            'token_hash' => hash('sha256', 'a-token'),
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertFalse($invitation->isValid('a-token'));
    }

    public function test_is_valid_rejects_already_accepted(): void
    {
        $invitation = AdminInvitation::create([
            'name' => 'Jane Admin',
            'email' => 'jane@example.com',
            'role_name' => 'marketing',
            'token_hash' => hash('sha256', 'a-token'),
            'expires_at' => now()->addHours(48),
            'accepted_at' => now(),
        ]);

        $this->assertFalse($invitation->isValid('a-token'));
    }

    public function test_pending_scope_excludes_accepted_and_expired(): void
    {
        $pending = AdminInvitation::create([
            'name' => 'Pending', 'email' => 'p@example.com', 'role_name' => 'marketing',
            'token_hash' => 'h1', 'expires_at' => now()->addHours(48),
        ]);
        AdminInvitation::create([
            'name' => 'Accepted', 'email' => 'a@example.com', 'role_name' => 'marketing',
            'token_hash' => 'h2', 'expires_at' => now()->addHours(48), 'accepted_at' => now(),
        ]);
        AdminInvitation::create([
            'name' => 'Expired', 'email' => 'e@example.com', 'role_name' => 'marketing',
            'token_hash' => 'h3', 'expires_at' => now()->subMinute(),
        ]);

        $result = AdminInvitation::pending()->pluck('id');

        $this->assertEquals([$pending->id], $result->all());
    }
}
```

- [ ] **Step 4: Run test to verify it fails**

Run: `php artisan test tests/Unit/AdminInvitationTest.php`
Expected: FAIL — `Class "App\Models\AdminInvitation" not found`

- [ ] **Step 5: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class AdminInvitation extends Model
{
    protected $fillable = [
        'name',
        'email',
        'role_name',
        'token_hash',
        'expires_at',
        'accepted_at',
        'invited_by',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '<=', now());
    }

    public function isValid(string $plaintextToken): bool
    {
        if ($this->accepted_at !== null) {
            return false;
        }

        if (now()->greaterThanOrEqualTo($this->expires_at)) {
            return false;
        }

        return hash_equals($this->token_hash, hash('sha256', $plaintextToken));
    }

    public static function hashToken(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public static function generateTokenFor(self $invitation): string
    {
        $plaintext = Str::random(64);

        $invitation->update(['token_hash' => self::hashToken($plaintext)]);

        return $plaintext;
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Unit/AdminInvitationTest.php`
Expected: PASS (5 tests)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_09_12_100000_create_admin_invitations_table.php app/Models/AdminInvitation.php tests/Unit/AdminInvitationTest.php
git commit -m "feat: add admin_invitations table and AdminInvitation model

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: `AdminInvited` notification

**Files:**
- Create: `app/Notifications/AdminInvited.php`
- Test: `tests/Unit/AdminInvitedNotificationTest.php`

**Interfaces:**
- Consumes: `AdminInvitation` model (Task 1) — reads `name`, `email`, `role_name`, `invitedBy` relation.
- Produces: `AdminInvited` notification class, constructor `(AdminInvitation $invitation, string $plaintextToken)`, `toMail()` building a `MailMessage` with an action URL to `route('admin.invite.show', ['token' => $plaintextToken])` (route defined in Task 4).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Models\AdminInvitation;
use App\Notifications\AdminInvited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Tests\TestCase;

class AdminInvitedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mail_contains_accept_link_and_role(): void
    {
        $invitation = AdminInvitation::create([
            'name' => 'Jane Admin',
            'email' => 'jane@example.com',
            'role_name' => 'marketing',
            'token_hash' => 'irrelevant-for-this-test',
            'expires_at' => now()->addHours(48),
        ]);

        $notification = new AdminInvited($invitation, 'plaintext-token-123');
        $mail = $notification->toMail(new AnonymousNotifiable);

        $rendered = $mail->render();

        $this->assertStringContainsString('plaintext-token-123', $rendered);
        $this->assertStringContainsString('marketing', $rendered);
    }

    public function test_implements_should_queue(): void
    {
        $invitation = AdminInvitation::create([
            'name' => 'Jane Admin',
            'email' => 'jane@example.com',
            'role_name' => 'marketing',
            'token_hash' => 'x',
            'expires_at' => now()->addHours(48),
        ]);

        $notification = new AdminInvited($invitation, 'token');

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $notification);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/AdminInvitedNotificationTest.php`
Expected: FAIL — `Class "App\Notifications\AdminInvited" not found`

- [ ] **Step 3: Write the notification**

```php
<?php

namespace App\Notifications;

use App\Models\AdminInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly AdminInvitation $invitation,
        public readonly string $plaintextToken,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $inviterName = $this->invitation->invitedBy?->name ?? 'An administrator';

        return (new MailMessage)
            ->subject('You have been invited to the Estele admin panel')
            ->greeting("Hello {$this->invitation->name},")
            ->line("{$inviterName} has invited you to join the Estele admin panel as \"{$this->invitation->role_name}\".")
            ->action('Set your password and log in', $this->acceptUrl())
            ->line('This invite link expires in 48 hours.')
            ->line('If you were not expecting this invite, you can ignore this email.');
    }

    private function acceptUrl(): string
    {
        return route('admin.invite.show', ['token' => $this->plaintextToken]);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/AdminInvitedNotificationTest.php`
Expected: FAIL still — `route('admin.invite.show', ...)` doesn't exist yet. This is expected at this point in the plan; the route is added in Task 4. Skip ahead: add a temporary named route stub is unnecessary — instead reorder: this test needs the route to exist to call `route()`. Fix: define the route in Task 4 first if running tests standalone, or run the full suite after Task 4. For this task in isolation, verify only the queueable check passes and treat the `toMail` test as pending until Task 4:

Run: `php artisan test --filter test_implements_should_queue`
Expected: PASS

(The `test_mail_contains_accept_link_and_role` test will be confirmed passing at the end of Task 4's steps — noted there.)

- [ ] **Step 5: Commit**

```bash
git add app/Notifications/AdminInvited.php tests/Unit/AdminInvitedNotificationTest.php
git commit -m "feat: add AdminInvited notification

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 3: Public accept routes + controller (show + store)

**Files:**
- Create: `app/Http/Controllers/AdminInvitationController.php`
- Create: `resources/views/admin-invitations/accept.blade.php`
- Create: `resources/views/admin-invitations/invalid.blade.php`
- Modify: `routes/web.php` (append new route block, following the existing "Old Jewellery — Vendor Media" block's convention)
- Test: `tests/Feature/AdminInvitationAcceptTest.php`

**Interfaces:**
- Consumes: `AdminInvitation` (Task 1), `route('admin.invite.show')` / `route('admin.invite.accept')` names (this task defines them, consumed by Task 2's `AdminInvited::acceptUrl()`).
- Produces: routes named `admin.invite.show` (GET `/admin/invite/accept/{token}`) and `admin.invite.accept` (POST, same path). On successful POST: creates a `User`, assigns role, logs in via `Auth::guard('web')->login($user)`, redirects to `/admin`.

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature;

use App\Models\AdminInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInvitationAcceptTest extends TestCase
{
    use RefreshDatabase;

    private function makeInvitation(string $plaintextToken, array $overrides = []): AdminInvitation
    {
        Role::firstOrCreate(['name' => 'marketing', 'guard_name' => 'web']);

        return AdminInvitation::create(array_merge([
            'name' => 'Jane Admin',
            'email' => 'jane@example.com',
            'role_name' => 'marketing',
            'token_hash' => AdminInvitation::hashToken($plaintextToken),
            'expires_at' => now()->addHours(48),
        ], $overrides));
    }

    public function test_show_renders_form_for_valid_token(): void
    {
        $this->makeInvitation('valid-token-123');

        $response = $this->get(route('admin.invite.show', ['token' => 'valid-token-123']));

        $response->assertOk();
        $response->assertSee('Jane Admin');
    }

    public function test_show_renders_invalid_view_for_unknown_token(): void
    {
        $response = $this->get(route('admin.invite.show', ['token' => 'does-not-exist']));

        $response->assertOk();
        $response->assertSee('no longer valid');
    }

    public function test_show_renders_invalid_view_for_expired_token(): void
    {
        $this->makeInvitation('expired-token', ['expires_at' => now()->subMinute()]);

        $response = $this->get(route('admin.invite.show', ['token' => 'expired-token']));

        $response->assertSee('no longer valid');
    }

    public function test_accept_creates_user_with_role_and_redirects_to_admin(): void
    {
        $this->makeInvitation('accept-me-token');

        $response = $this->post(route('admin.invite.accept', ['token' => 'accept-me-token']), [
            'password' => 'a-secure-password',
            'password_confirmation' => 'a-secure-password',
        ]);

        $response->assertRedirect('/admin');

        $user = User::where('email', 'jane@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('marketing'));
        $this->assertAuthenticatedAs($user);

        $invitation = AdminInvitation::where('email', 'jane@example.com')->first();
        $this->assertNotNull($invitation->accepted_at);
    }

    public function test_accept_rejects_mismatched_password_confirmation(): void
    {
        $this->makeInvitation('mismatch-token');

        $response = $this->post(route('admin.invite.accept', ['token' => 'mismatch-token']), [
            'password' => 'a-secure-password',
            'password_confirmation' => 'different-password',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertNull(User::where('email', 'jane@example.com')->first());
    }

    public function test_accept_rejects_expired_token(): void
    {
        $this->makeInvitation('expired-accept-token', ['expires_at' => now()->subMinute()]);

        $response = $this->post(route('admin.invite.accept', ['token' => 'expired-accept-token']), [
            'password' => 'a-secure-password',
            'password_confirmation' => 'a-secure-password',
        ]);

        $response->assertOk();
        $response->assertSee('no longer valid');
        $this->assertNull(User::where('email', 'jane@example.com')->first());
    }

    public function test_accept_rejects_already_accepted_token(): void
    {
        $this->makeInvitation('reused-token', ['accepted_at' => now()]);

        $response = $this->post(route('admin.invite.accept', ['token' => 'reused-token']), [
            'password' => 'a-secure-password',
            'password_confirmation' => 'a-secure-password',
        ]);

        $response->assertSee('no longer valid');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/AdminInvitationAcceptTest.php`
Expected: FAIL — route `admin.invite.show` not defined.

- [ ] **Step 3: Write the controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\AdminInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AdminInvitationController extends Controller
{
    public function show(string $token): View
    {
        $invitation = $this->findValidInvitation($token);

        if (! $invitation) {
            return view('admin-invitations.invalid');
        }

        return view('admin-invitations.accept', [
            'invitation' => $invitation,
            'token' => $token,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse|View
    {
        $invitation = $this->findValidInvitation($token);

        if (! $invitation) {
            return view('admin-invitations.invalid');
        }

        $validated = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = DB::transaction(function () use ($invitation, $validated) {
            $user = User::create([
                'name' => $invitation->name,
                'email' => $invitation->email,
                'password' => Hash::make($validated['password']),
            ]);

            $user->assignRole($invitation->role_name);

            $invitation->update(['accepted_at' => now()]);

            return $user;
        });

        Auth::guard('web')->login($user);

        return redirect('/admin');
    }

    private function findValidInvitation(string $token): ?AdminInvitation
    {
        $hash = AdminInvitation::hashToken($token);

        $invitation = AdminInvitation::where('token_hash', $hash)->first();

        if (! $invitation || ! $invitation->isValid($token)) {
            return null;
        }

        return $invitation;
    }
}
```

- [ ] **Step 4: Write the accept view**

```blade
{{-- resources/views/admin-invitations/accept.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Set your password — Estele Admin</title>
</head>
<body>
    <h1>Welcome, {{ $invitation->name }}</h1>
    <p>You've been invited as "{{ $invitation->role_name }}". Set a password to activate your account.</p>

    @if ($errors->any())
        <ul>
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('admin.invite.accept', ['token' => $token]) }}">
        @csrf
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required minlength="8">

        <label for="password_confirmation">Confirm password</label>
        <input type="password" id="password_confirmation" name="password_confirmation" required minlength="8">

        <button type="submit">Set password and log in</button>
    </form>
</body>
</html>
```

- [ ] **Step 5: Write the invalid-token view**

```blade
{{-- resources/views/admin-invitations/invalid.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invite link invalid — Estele Admin</title>
</head>
<body>
    <h1>This invite link is no longer valid</h1>
    <p>It may have expired or already been used. Ask your admin to resend the invite.</p>
</body>
</html>
```

- [ ] **Step 6: Add the routes**

Append to `routes/web.php`, after the existing "Old Jewellery — Vendor Media" block (following that block's no-`auth`/no-`guest` convention for token-authenticated routes):

```php
/*
|--------------------------------------------------------------------------
| Admin Invitations (token-based, no session auth required)
|--------------------------------------------------------------------------
*/

Route::get('/admin/invite/accept/{token}', [AdminInvitationController::class, 'show'])
    ->name('admin.invite.show')
    ->middleware('throttle:10,60');

Route::post('/admin/invite/accept/{token}', [AdminInvitationController::class, 'store'])
    ->name('admin.invite.accept')
    ->middleware('throttle:10,60');
```

Add the corresponding `use` import at the top of `routes/web.php`:

```php
use App\Http\Controllers\AdminInvitationController;
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test tests/Feature/AdminInvitationAcceptTest.php`
Expected: PASS (7 tests)

- [ ] **Step 8: Re-run Task 2's notification test now that the route exists**

Run: `php artisan test tests/Unit/AdminInvitedNotificationTest.php`
Expected: PASS (both tests — `test_mail_contains_accept_link_and_role` now resolves `route('admin.invite.show', ...)` successfully)

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/AdminInvitationController.php resources/views/admin-invitations routes/web.php tests/Feature/AdminInvitationAcceptTest.php
git commit -m "feat: add public admin invite accept routes and controller

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 4: `AdminResource` Filament resource — list (pending + active)

**Files:**
- Create: `app/Filament/Resources/Admins/AdminResource.php`
- Create: `app/Filament/Resources/Admins/Tables/AdminsTable.php`
- Create: `app/Filament/Resources/Admins/Pages/ListAdmins.php`
- Create: `app/Policies/AdminInvitationPolicy.php`
- Modify: `database/seeders/ShieldSeeder.php` (add `AdminInvitation` to `RESOURCES`, grant its permissions to `super_admin` only)
- Modify: `app/Providers/AppServiceProvider.php` — none needed (no observer required for this model)
- Test: `tests/Feature/AdminResourceListTest.php`

**Interfaces:**
- Consumes: `AdminInvitation` model (Task 1).
- Produces: Filament resource registered under nav group "Content" alongside `ReviewResource`, model `AdminInvitation::class`, list page showing Name/Email/Role/Status/Invited At/Invited By.

- [ ] **Step 1: Add `AdminInvitation` to the permission seeder**

In `database/seeders/ShieldSeeder.php`, find the `RESOURCES` array (contains `'Review'`, `'Vendor'`, etc.) and add `'AdminInvitation'` to it. Then find the `super_admin` section that does `$superAdmin->syncPermissions($permissionNames)` — no change needed there since it already grants every generated permission. Find the `marketing` role's curated `syncPermissions([...])` call and confirm `AdminInvitation` permissions are NOT in that list (marketing should not manage admins) — do not add them.

Run: `grep -n "'Review'," database/seeders/ShieldSeeder.php` to find the exact line, then add `'AdminInvitation',` alongside it in the `RESOURCES` array.

- [ ] **Step 2: Re-run the seeder to create the new permissions**

Run: `php artisan db:seed --class=ShieldSeeder`
Expected: completes without error; `Create:AdminInvitation`, `ViewAny:AdminInvitation`, etc. now exist in the `permissions` table and are attached to `super_admin`.

- [ ] **Step 3: Write the policy**

```php
<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AdminInvitation;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AdminInvitationPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('ViewAny:AdminInvitation');
    }

    public function view(AuthUser $authUser, AdminInvitation $invitation): bool
    {
        return $authUser->can('View:AdminInvitation');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('Create:AdminInvitation');
    }

    public function update(AuthUser $authUser, AdminInvitation $invitation): bool
    {
        return $authUser->can('Update:AdminInvitation');
    }

    public function delete(AuthUser $authUser, AdminInvitation $invitation): bool
    {
        return $authUser->can('Delete:AdminInvitation');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('DeleteAny:AdminInvitation');
    }
}
```

- [ ] **Step 4: Write the failing feature test**

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\Admins\AdminResource;
use App\Models\AdminInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminResourceListTest extends TestCase
{
    use RefreshDatabase;

    private function superAdminUser(): User
    {
        $this->seed(\Database\Seeders\ShieldSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_super_admin_can_view_admins_list(): void
    {
        $admin = $this->superAdminUser();

        AdminInvitation::create([
            'name' => 'Pending Person',
            'email' => 'pending@example.com',
            'role_name' => 'marketing',
            'token_hash' => 'hash',
            'expires_at' => now()->addHours(48),
        ]);

        $this->actingAs($admin)
            ->get(AdminResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Pending Person')
            ->assertSee('Pending');
    }

    public function test_marketing_role_cannot_view_admins_list(): void
    {
        $this->seed(\Database\Seeders\ShieldSeeder::class);
        Role::firstOrCreate(['name' => 'marketing', 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->assignRole('marketing');

        $this->actingAs($user)
            ->get(AdminResource::getUrl('index'))
            ->assertForbidden();
    }
}
```

- [ ] **Step 5: Run test to verify it fails**

Run: `php artisan test tests/Feature/AdminResourceListTest.php`
Expected: FAIL — `Class "App\Filament\Resources\Admins\AdminResource" not found`

- [ ] **Step 6: Write the table class**

```php
<?php

namespace App\Filament\Resources\Admins\Tables;

use App\Models\AdminInvitation;
use App\Models\User;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AdminsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                BadgeColumn::make('role_name')->label('Role'),
                BadgeColumn::make('status')
                    ->label('Status')
                    ->state(fn (AdminInvitation $record): string => $record->accepted_at ? 'Active' : 'Pending')
                    ->colors([
                        'success' => 'Active',
                        'warning' => 'Pending',
                    ]),
                TextColumn::make('created_at')->label('Invited At')->dateTime('d M Y, h:i A'),
                TextColumn::make('invitedBy.name')->label('Invited By')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
```

- [ ] **Step 7: Write the resource and list page**

```php
<?php

namespace App\Filament\Resources\Admins;

use App\Filament\Resources\Admins\Pages\ListAdmins;
use App\Filament\Resources\Admins\Tables\AdminsTable;
use App\Models\AdminInvitation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AdminResource extends Resource
{
    protected static ?string $model = AdminInvitation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $modelLabel = 'Admin';

    protected static ?string $pluralModelLabel = 'Admins';

    public static function table(Table $table): Table
    {
        return AdminsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdmins::route('/'),
        ];
    }
}
```

```php
<?php

namespace App\Filament\Resources\Admins\Pages;

use App\Filament\Resources\Admins\AdminResource;
use Filament\Resources\Pages\ListRecords;

class ListAdmins extends ListRecords
{
    protected static string $resource = AdminResource::class;
}
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test tests/Feature/AdminResourceListTest.php`
Expected: PASS (2 tests)

- [ ] **Step 9: Commit**

```bash
git add app/Filament/Resources/Admins app/Policies/AdminInvitationPolicy.php database/seeders/ShieldSeeder.php tests/Feature/AdminResourceListTest.php
git commit -m "feat: add Admins Filament resource with pending/active list

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 5: Create-invite form (existing role select + inline "new role" with permission checkboxes)

**Files:**
- Create: `app/Filament/Resources/Admins/Schemas/AdminInvitationForm.php`
- Create: `app/Filament/Resources/Admins/Pages/CreateAdmin.php`
- Modify: `app/Filament/Resources/Admins/AdminResource.php` (add `form()` method and `create` page)
- Test: `tests/Feature/AdminInvitationCreateTest.php`

**Interfaces:**
- Consumes: `AdminInvitation` model, `AdminInvited` notification (Task 2), `AdminInvitation::generateTokenFor()` (Task 1).
- Produces: Filament create page at `AdminResource::getUrl('create')`, on submit dispatches `AdminInvited` notification and creates an `AdminInvitation` row with `invited_by` set to the acting user.

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\Admins\AdminResource;
use App\Models\AdminInvitation;
use App\Models\User;
use App\Notifications\AdminInvited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminInvitationCreateTest extends TestCase
{
    use RefreshDatabase;

    private function superAdminUser(): User
    {
        $this->seed(\Database\Seeders\ShieldSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_creating_invitation_with_existing_role_queues_notification(): void
    {
        Notification::fake();
        $admin = $this->superAdminUser();

        $response = $this->actingAs($admin)
            ->post(AdminResource::getUrl('create'), [
                'name' => 'New Admin',
                'email' => 'newadmin@example.com',
                'role_name' => 'marketing',
            ]);

        $invitation = AdminInvitation::where('email', 'newadmin@example.com')->first();

        $this->assertNotNull($invitation);
        $this->assertEquals('marketing', $invitation->role_name);
        $this->assertEquals($admin->id, $invitation->invited_by);
        $this->assertNull($invitation->accepted_at);
        $this->assertTrue($invitation->expires_at->isFuture());

        Notification::assertSentTo(
            new \Illuminate\Notifications\AnonymousNotifiable,
            AdminInvited::class,
            fn () => true
        );
    }

    public function test_creating_invitation_for_email_with_pending_invite_is_blocked(): void
    {
        Notification::fake();
        $admin = $this->superAdminUser();

        AdminInvitation::create([
            'name' => 'Existing Pending',
            'email' => 'dupe@example.com',
            'role_name' => 'marketing',
            'token_hash' => 'x',
            'expires_at' => now()->addHours(48),
        ]);

        $this->actingAs($admin)
            ->post(AdminResource::getUrl('create'), [
                'name' => 'Second Try',
                'email' => 'dupe@example.com',
                'role_name' => 'marketing',
            ])
            ->assertSessionHasErrors('email');

        $this->assertEquals(1, AdminInvitation::where('email', 'dupe@example.com')->count());
    }
}
```

Note: `Notification::assertSentTo` with `AnonymousNotifiable` works because the notification is sent via `Notification::route('mail', $invitation->email)->notify(...)` (see Step 3) rather than `$user->notify()`, since there is no `User` yet to notify.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/AdminInvitationCreateTest.php`
Expected: FAIL — create page / route doesn't exist yet.

- [ ] **Step 3: Write the form schema**

```php
<?php

namespace App\Filament\Resources\Admins\Schemas;

use App\Models\AdminInvitation;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AdminInvitationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Invite Admin')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->rules([
                            fn () => function (string $attribute, $value, \Closure $fail) {
                                $hasPendingInvite = AdminInvitation::pending()->where('email', $value)->exists();
                                $hasActiveAdmin = \App\Models\User::where('email', $value)->whereHas('roles')->exists();

                                if ($hasPendingInvite || $hasActiveAdmin) {
                                    $fail('An invitation or admin account already exists for this email.');
                                }
                            },
                        ]),
                    Select::make('role_name')
                        ->label('Role')
                        ->options(fn () => Role::query()->pluck('name', 'name'))
                        ->searchable()
                        ->required()
                        ->createOptionForm([
                            TextInput::make('name')
                                ->label('Role name')
                                ->required()
                                ->unique('roles', 'name'),
                            CheckboxList::make('permissions')
                                ->label('Permissions')
                                ->options(fn () => Permission::query()->pluck('name', 'name'))
                                ->searchable()
                                ->bulkToggleable()
                                ->columns(2)
                                ->columnSpanFull(),
                        ])
                        ->createOptionUsing(function (array $data) {
                            $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
                            $role->syncPermissions($data['permissions'] ?? []);

                            return $role->name;
                        }),
                ]),
        ]);
    }
}
```

- [ ] **Step 4: Write the create page**

```php
<?php

namespace App\Filament\Resources\Admins\Pages;

use App\Filament\Resources\Admins\AdminResource;
use App\Models\AdminInvitation;
use App\Notifications\AdminInvited;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Notifications\AnonymousNotifiable;

class CreateAdmin extends CreateRecord
{
    protected static string $resource = AdminResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['token_hash'] = 'placeholder';
        $data['expires_at'] = now()->addHours(48);
        $data['invited_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $plaintext = AdminInvitation::generateTokenFor($this->record);

        (new AnonymousNotifiable)
            ->route('mail', $this->record->email)
            ->notify(new AdminInvited($this->record->fresh(), $plaintext));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
```

- [ ] **Step 5: Wire the resource's `form()` method and `create` page**

In `app/Filament/Resources/Admins/AdminResource.php`, add:

```php
use App\Filament\Resources\Admins\Pages\CreateAdmin;
use App\Filament\Resources\Admins\Schemas\AdminInvitationForm;
use Filament\Schemas\Schema;
```

Add the `form()` method to the class:

```php
public static function form(Schema $schema): Schema
{
    return AdminInvitationForm::configure($schema);
}
```

Update `getPages()`:

```php
public static function getPages(): array
{
    return [
        'index' => ListAdmins::route('/'),
        'create' => CreateAdmin::route('/create'),
    ];
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/AdminInvitationCreateTest.php`
Expected: PASS (2 tests)

- [ ] **Step 7: Run the full test suite to check for regressions**

Run: `php artisan test`
Expected: all tests pass, including Tasks 1-4's tests.

- [ ] **Step 8: Commit**

```bash
git add app/Filament/Resources/Admins tests/Feature/AdminInvitationCreateTest.php
git commit -m "feat: add admin invite create form with inline role creation

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 6: Resend and Revoke row actions

**Files:**
- Modify: `app/Filament/Resources/Admins/Tables/AdminsTable.php` (add row actions)
- Test: `tests/Feature/AdminInvitationRowActionsTest.php`

**Interfaces:**
- Consumes: `AdminInvitation::generateTokenFor()` (Task 1), `AdminInvited` notification (Task 2).
- Produces: two Filament table `Action`s, `resend` and `revoke`, each visible only when `accepted_at` is null (pending, including expired-but-unaccepted).

- [ ] **Step 1: Write the failing feature test**

```php
<?php

namespace Tests\Feature;

use App\Filament\Resources\Admins\AdminResource;
use App\Models\AdminInvitation;
use App\Models\User;
use App\Notifications\AdminInvited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class AdminInvitationRowActionsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdminUser(): User
    {
        $this->seed(\Database\Seeders\ShieldSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('super_admin');

        return $user;
    }

    public function test_resend_regenerates_token_and_notifies(): void
    {
        Notification::fake();
        $admin = $this->superAdminUser();

        $invitation = AdminInvitation::create([
            'name' => 'Pending Person',
            'email' => 'pending@example.com',
            'role_name' => 'marketing',
            'token_hash' => AdminInvitation::hashToken('old-token'),
            'expires_at' => now()->addHours(48),
        ]);

        $this->actingAs($admin);

        Livewire::test(\App\Filament\Resources\Admins\Pages\ListAdmins::class)
            ->callTableAction('resend', $invitation);

        $invitation->refresh();

        $this->assertNotEquals(AdminInvitation::hashToken('old-token'), $invitation->token_hash);
        $this->assertTrue($invitation->expires_at->isAfter(now()->addHours(47)));

        Notification::assertSentOnDemand(AdminInvited::class);
    }

    public function test_revoke_deletes_pending_invitation(): void
    {
        $admin = $this->superAdminUser();

        $invitation = AdminInvitation::create([
            'name' => 'Pending Person',
            'email' => 'pending@example.com',
            'role_name' => 'marketing',
            'token_hash' => 'x',
            'expires_at' => now()->addHours(48),
        ]);

        $this->actingAs($admin);

        Livewire::test(\App\Filament\Resources\Admins\Pages\ListAdmins::class)
            ->callTableAction('revoke', $invitation);

        $this->assertNull(AdminInvitation::find($invitation->id));
    }

    public function test_resend_and_revoke_not_visible_for_active_admin(): void
    {
        $admin = $this->superAdminUser();

        $invitation = AdminInvitation::create([
            'name' => 'Active Person',
            'email' => 'active@example.com',
            'role_name' => 'marketing',
            'token_hash' => 'x',
            'expires_at' => now()->addHours(48),
            'accepted_at' => now(),
        ]);

        $this->actingAs($admin);

        Livewire::test(\App\Filament\Resources\Admins\Pages\ListAdmins::class)
            ->assertTableActionHidden('resend', $invitation)
            ->assertTableActionHidden('revoke', $invitation);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/AdminInvitationRowActionsTest.php`
Expected: FAIL — actions `resend`/`revoke` don't exist.

- [ ] **Step 3: Add the row actions to the table**

Modify `app/Filament/Resources/Admins/Tables/AdminsTable.php` — add imports and an `->actions([...])` call:

```php
use App\Models\AdminInvitation;
use App\Notifications\AdminInvited;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Illuminate\Notifications\AnonymousNotifiable;
```

Add to the `configure()` method, after `->defaultSort(...)`:

```php
->recordActions([
    Action::make('resend')
        ->label('Resend Invite')
        ->icon('heroicon-o-paper-airplane')
        ->visible(fn (AdminInvitation $record): bool => $record->accepted_at === null)
        ->requiresConfirmation()
        ->action(function (AdminInvitation $record) {
            $record->update(['expires_at' => now()->addHours(48)]);
            $plaintext = AdminInvitation::generateTokenFor($record);

            (new AnonymousNotifiable)
                ->route('mail', $record->email)
                ->notify(new AdminInvited($record->fresh(), $plaintext));
        }),
    DeleteAction::make('revoke')
        ->label('Revoke')
        ->visible(fn (AdminInvitation $record): bool => $record->accepted_at === null),
])
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/AdminInvitationRowActionsTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Resources/Admins/Tables/AdminsTable.php tests/Feature/AdminInvitationRowActionsTest.php
git commit -m "feat: add resend and revoke actions to admin invitations table

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 7: Manual smoke test with real SMTP

**Files:** none (verification only)

**Interfaces:** none — this task exercises Tasks 1-6 end-to-end against the real Gmail SMTP configured in `.env`.

- [ ] **Step 1: Confirm mail config is live**

Run: `php artisan config:clear` (ensures no stale cached config overrides the `.env` SMTP values)

- [ ] **Step 2: Create a real invite through the admin UI**

Log into `/admin` as the `super_admin` user, go to Content → Admins → New, invite a test email you control (e.g. the same Gmail address used for `MAIL_USERNAME`, or another inbox you can check), pick the `marketing` role, submit.

- [ ] **Step 3: Confirm the mail arrives**

Check the inbox for the "You have been invited to the Estele admin panel" email. If `QUEUE_CONNECTION=database` and no queue worker is running, the job sits in the `jobs` table — run `php artisan queue:work --once` to process it and confirm delivery.

- [ ] **Step 4: Complete the accept flow**

Click the link in the email, set a password, submit, confirm you land on `/admin` and are logged in with the `marketing` role's restricted navigation (no Orders/Coupons/Settings visible, per `ShieldSeeder`'s marketing permissions).

- [ ] **Step 5: Confirm status flips to Active**

Back in Content → Admins (logged in as `super_admin`), confirm the invited row now shows "Active" instead of "Pending", and the Resend/Revoke actions are no longer visible for that row.

- [ ] **Step 6: No commit for this task** — it is a verification-only step. If any issue is found, fix it in the relevant task's files and re-run that task's automated tests before re-attempting this smoke test.

---

## Self-Review Notes

- **Spec coverage:** invite creation (Task 5), existing+new role selection (Task 5's `createOptionForm`), 48h expiry (Task 1/6), resend (Task 6), revoke (Task 6), Pending/Active badge (Task 4), mail via queued notification (Task 2), accept flow creating User + role + login + redirect to `/admin` (Task 3), real SMTP smoke test (Task 7). All spec sections have a task.
- **Type consistency:** `AdminInvitation::hashToken()` (static) and `generateTokenFor()` (static, mutates + returns plaintext) are defined once in Task 1 and reused identically in Tasks 3, 5, 6 — no renamed variants introduced later.
- **Out of scope items from the spec** (self-service password reset, role-change audit log, rate-limiting invite creation) are intentionally not tasked here.
