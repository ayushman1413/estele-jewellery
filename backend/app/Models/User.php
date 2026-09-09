<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'phone', 'password', 'wallet_balance'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->roles()->exists();
    }

    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Order::class)->latest();
    }

    public function addresses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Address::class)->latest();
    }

    public function walletTransactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WalletTransaction::class)->latest();
    }

    public function rewardSubmissions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RewardSubmission::class)->latest();
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
