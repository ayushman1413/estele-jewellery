<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OldJewelleryBid extends Model
{
    protected $fillable = [
        'old_jewellery_request_id',
        'bidder_type',
        'vendor_id',
        'admin_user_id',
        'invitation_id',
        'amount',
        'is_valid',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_valid' => 'boolean',
            'submitted_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(OldJewelleryRequest::class, 'old_jewellery_request_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(OldJewelleryVendorInvitation::class, 'invitation_id');
    }
}
