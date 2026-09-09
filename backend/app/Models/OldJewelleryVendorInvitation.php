<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OldJewelleryVendorInvitation extends Model
{
    protected $fillable = [
        'old_jewellery_request_id',
        'vendor_id',
        'token_hash',
        'expires_at',
        'response_status',
        'decline_reason',
        'responded_at',
        'notified_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
            'notified_at' => 'datetime',
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

    public function bid(): HasOne
    {
        return $this->hasOne(OldJewelleryBid::class, 'invitation_id');
    }
}
