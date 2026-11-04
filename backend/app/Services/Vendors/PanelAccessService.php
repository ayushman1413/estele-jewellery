<?php

namespace App\Services\Vendors;

use App\Models\User;
use App\Models\Vendor;
use App\Notifications\PanelAccessInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Gives a Vendor row a panel login: a User to sign in as, the Spatie role that
 * decides what it may see, and the one-time link it uses to choose a password.
 *
 * What the login can actually do is never decided here — that is entirely the
 * Filament Shield permissions attached to the role, which the admin edits.
 */
class PanelAccessService
{
    /**
     * Create (or reattach) the login for a vendor and send the setup link.
     * Safe to call repeatedly: an existing linked user is reused rather than
     * duplicated.
     *
     * Returns false when the vendor has no email — the link has nowhere to go,
     * and the vendor still works through its signed invitation token.
     *
     * @throws \RuntimeException if the email belongs to a user this vendor
     *         does not already own — see ensureUser().
     */
    public function grant(Vendor $vendor, bool $isResend = false): bool
    {
        $user = $this->ensureUser($vendor);

        if (! $user) {
            return false;
        }

        return $this->sendSetupLink($vendor->fresh('user'), $user, $isResend);
    }

    /**
     * Create/sync the login without mailing anything — used when only the role
     * changed, so an existing contact is not spammed with a fresh link.
     */
    public function grantWithoutNotifying(Vendor $vendor): bool
    {
        return $this->ensureUser($vendor) !== null;
    }

    /**
     * Never adopts a user this vendor doesn't already own. A brand-new vendor
     * row always gets a brand-new user — reusing whoever else happens to hold
     * that email (a customer, staff, or a super_admin) would hand that
     * account's login to whoever the admin sends the invite to.
     *
     * @throws \RuntimeException if the email is already someone else's login.
     */
    private function ensureUser(Vendor $vendor): ?User
    {
        if (blank($vendor->email)) {
            return null;
        }

        return DB::transaction(function () use ($vendor) {
            $user = $vendor->user;

            if ($user) {
                // Keep the login's address in step with the vendor record, so
                // a corrected email does not leave the user signing in with
                // the old one — but only once we know no other account is
                // already sitting on that address.
                $collision = User::where('email', $vendor->email)
                    ->whereKeyNot($user->id)
                    ->exists();

                if ($collision) {
                    throw new \RuntimeException(
                        'That email already belongs to a different account. Use a different address for this vendor.'
                    );
                }

                $user->forceFill(['email' => $vendor->email])->save();
            } else {
                if (User::where('email', $vendor->email)->exists()) {
                    throw new \RuntimeException(
                        'That email already belongs to an existing account. Use a different address for this vendor.'
                    );
                }

                $user = User::create([
                    'name' => $vendor->name,
                    'email' => $vendor->email,
                    // No password yet: the setup link is the only way in, and
                    // a null password cannot be guessed or brute-forced.
                    'password' => null,
                ]);
            }

            $this->syncRole($user, $vendor->access_role);

            if ($vendor->user_id !== $user->id) {
                $vendor->forceFill(['user_id' => $user->id])->save();
            }

            return $user;
        });
    }

    /**
     * Issue a fresh setup token and notify the vendor over mail (and WhatsApp
     * when a number is on file). Returns false if the link could not be sent —
     * the caller surfaces that rather than claiming success.
     */
    public function sendSetupLink(Vendor $vendor, ?User $user = null, bool $isResend = false): bool
    {
        $user ??= $vendor->user;

        if (! $user || blank($user->email)) {
            return false;
        }

        try {
            $token = Password::broker('panel_invites')->createToken($user);

            $vendor->notify(new PanelAccessInvitation(
                vendor: $vendor,
                setupUrl: route('panel.password.setup', ['token' => $token, 'email' => $user->email]),
                isResend: $isResend,
            ));

            return true;
        } catch (\Throwable $e) {
            // A mail/queue failure must not roll back the account that was
            // just created — the admin can resend from the vendor page.
            Log::warning('Failed to send panel access invitation.', [
                'vendor_id' => $vendor->id,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Point the user at exactly one of the two access roles. Roles are created
     * on demand with no permissions attached, so a brand-new role grants
     * nothing until an admin ticks boxes in Shield.
     */
    private function syncRole(User $user, string $accessRole): void
    {
        $roleName = $accessRole === Vendor::ACCESS_ROLE_ADMIN
            ? Vendor::ACCESS_ROLE_ADMIN
            : Vendor::ACCESS_ROLE_VENDOR;

        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

        // Only ever swap between the two roles this service manages — a
        // super_admin who also happens to be a vendor contact keeps that role.
        $managed = Vendor::ACCESS_ROLES;
        $keep = $user->roles->pluck('name')->reject(fn ($n) => in_array($n, $managed, true));

        $user->syncRoles($keep->push($role->name)->unique()->all());
    }

    /**
     * Revoke panel access without deleting history: the user keeps existing,
     * but loses the managed role and any usable password.
     */
    public function revoke(Vendor $vendor): void
    {
        $user = $vendor->user;

        if (! $user) {
            return;
        }

        $keep = $user->roles->pluck('name')
            ->reject(fn ($n) => in_array($n, Vendor::ACCESS_ROLES, true));

        $user->syncRoles($keep->all());
        $user->forceFill(['password' => null, 'remember_token' => Str::random(60)])->save();
    }
}
