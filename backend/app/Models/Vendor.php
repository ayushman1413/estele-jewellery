<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;

/**
 * A marketplace vendor who bids on old-jewellery requests.
 *
 * Two ways in, both supported at once:
 *  - the per-invitation signed token (OldJewelleryVendorInvitation), which
 *    needs no account at all, and
 *  - a linked User ($this->user) that logs into the admin panel, created when
 *    the admin supplies an email. What that login can see is decided purely by
 *    the Filament Shield permissions on its role — nothing here grants access.
 *
 * ACCESS_ROLE_* picks which notifications this contact receives, not what it
 * may do: 'vendor' gets the bidding stream, 'admin' only account mail.
 */
class Vendor extends Model
{
    use Notifiable;

    public const ACCESS_ROLE_VENDOR = 'vendor';

    public const ACCESS_ROLE_ADMIN = 'admin';

    public const ACCESS_ROLES = [self::ACCESS_ROLE_VENDOR, self::ACCESS_ROLE_ADMIN];

    protected $fillable = [
        'user_id',
        'name',
        'company_name',
        'mobile',
        'mobile_verified_at',
        'email',
        'whatsapp_number',
        'is_active',
        'access_role',
    ];

    protected function casts(): array
    {
        return [
            'mobile_verified_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Only 'vendor' contacts are part of the bidding stream. An 'admin'
     * contact is a panel login that happens to live in this table, so it must
     * never be invited to bid or notified about requests.
     */
    public function receivesBiddingNotifications(): bool
    {
        return $this->access_role === self::ACCESS_ROLE_VENDOR;
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
