<?php

namespace App\Services\Staff;

use App\Models\User;
use App\Models\Vendor;
use App\Notifications\StaffAccessInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Adds a staff member to the admin panel: a User to sign in as, the Spatie
 * role that decides what they may see, and the one-time link they use to
 * choose their own password.
 *
 * What the login can actually do is never decided here — that is entirely the
 * Filament Shield permissions attached to the role, which the admin edits on
 * the same Roles screen this is reached from.
 *
 * The vendor-facing sibling is App\Services\Vendors\PanelAccessService, which
 * additionally maintains the Vendor row and can notify over WhatsApp.
 */
class StaffAccessService
{
    /**
     * Create (or adopt) the login for a staff member and send the setup link.
     *
     * Safe to call repeatedly: an existing user with the same email is adopted
     * rather than colliding on the unique index, and re-inviting simply issues
     * a fresh token.
     */
    public function invite(string $name, string $email, string $roleName, bool $isResend = false): ?User
    {
        $user = DB::transaction(function () use ($name, $email, $roleName) {
            $user = User::where('email', $email)->first();

            if ($user) {
                // Don't overwrite a real name with a blank one on re-invite.
                if (filled($name)) {
                    $user->forceFill(['name' => $name])->save();
                }
            } else {
                $user = User::create([
                    'name' => $name,
                    'email' => $email,
                    // No password yet: the setup link is the only way in, and
                    // a null password cannot be guessed or brute-forced.
                    'password' => null,
                ]);
            }

            $this->assignRole($user, $roleName);

            return $user;
        });

        return $this->sendSetupLink($user, $roleName, $isResend) ? $user : null;
    }

    /**
     * Issue a fresh setup token and mail it. Returns false if the link could
     * not be sent — the caller surfaces that rather than claiming success.
     */
    public function sendSetupLink(User $user, string $roleName, bool $isResend = false): bool
    {
        if (blank($user->email)) {
            return false;
        }

        try {
            $token = Password::broker('panel_invites')->createToken($user);

            $user->notify(new StaffAccessInvitation(
                setupUrl: route('panel.password.setup', ['token' => $token, 'email' => $user->email]),
                roleLabel: Str::headline($roleName),
                isResend: $isResend,
            ));

            return true;
        } catch (\Throwable $e) {
            // A mail/queue failure must not roll back the account that was
            // just created — the admin can resend from the Roles screen.
            Log::warning('Failed to send staff access invitation.', [
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Add the chosen role, leaving every other role the user already holds
     * intact — assigning "marketing" to someone must not silently strip their
     * super_admin, and must not disturb the vendor/admin roles that
     * PanelAccessService owns.
     */
    private function assignRole(User $user, string $roleName): void
    {
        Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);

        $user->assignRole($roleName);
    }

    /**
     * Remove one role from a staff member. The account itself is left alone:
     * losing a role is not the same as losing the history attached to the user.
     *
     * When this was their last panel role the login can no longer reach the
     * panel at all (User::canAccessPanel requires one), so the password is
     * cleared too rather than leaving a usable credential on a dormant account.
     */
    public function revokeRole(User $user, string $roleName): void
    {
        $user->removeRole($roleName);

        if ($user->fresh()->roles()->exists()) {
            return;
        }

        $user->forceFill([
            'password' => null,
            'remember_token' => Str::random(60),
        ])->save();
    }

    /**
     * Roles an admin may hand out from the Roles screen.
     *
     * The vendor/admin roles are excluded on purpose: those are owned by the
     * Vendor record (PanelAccessService keeps them in step with access_role),
     * so granting them here would create a login the Vendors screen doesn't
     * know about.
     */
    public function assignableRoles(): array
    {
        return Role::query()
            ->where('guard_name', 'web')
            ->whereNotIn('name', Vendor::ACCESS_ROLES)
            ->orderBy('name')
            ->pluck('name', 'name')
            ->map(fn (string $name) => Str::headline($name))
            ->all();
    }
}
