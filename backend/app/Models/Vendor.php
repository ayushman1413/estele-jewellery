<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

/**
 * A marketplace vendor who bids on old-jewellery requests. Vendors never log
 * in — they act only through a per-invitation signed token
 * (OldJewelleryVendorInvitation). Distinct from the "vendor" Spatie role
 * (reward-submission reviewers), which is an unrelated concept.
 */
class Vendor extends Model
{
    use Notifiable;

    protected $fillable = [
        'name',
        'company_name',
        'mobile',
        'mobile_verified_at',
        'email',
        'whatsapp_number',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'mobile_verified_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OldJewelleryVendorInvitation::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(OldJewelleryBid::class);
    }

    public function routeNotificationForMail(): ?string
    {
        return $this->email;
    }

    public function routeNotificationForWhatsapp(): ?string
    {
        return $this->whatsapp_number;
    }
}
