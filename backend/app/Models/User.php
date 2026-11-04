<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'password', 'wallet_balance'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        // An invited contact exists before it has a password — it must not be
        // able to reach the panel until the setup link has actually been used.
        return filled($this->password) && $this->roles()->exists();
    }

    protected static function booted(): void
    {
        // The FK cascade would drop these rows without firing their model
        // events, leaving every photo and video they own on disk. Deleting
        // through Eloquent lets the media library remove the files too.
        static::deleting(function (User $user) {
            $user->oldJewelleryRequests()->get()->each->delete();
            $user->rewardSubmissions()->get()->each->delete();
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
    }

    /** The bidding vendor this login belongs to, if any. */
    public function vendor(): HasOne
    {
        return $this->hasOne(Vendor::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class)->latest();
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class)->latest();
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->latest();
    }

    public function rewardSubmissions(): HasMany
    {
        return $this->hasMany(RewardSubmission::class)->latest();
    }

    public function oldJewelleryRequests(): HasMany
    {
        return $this->hasMany(OldJewelleryRequest::class);
    }

    public function oldJewelleryWalletCredits(): HasMany
    {
        return $this->hasMany(OldJewelleryWalletCredit::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'wallet_balance' => 'decimal:2',
        ];
    }
}
