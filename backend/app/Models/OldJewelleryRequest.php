<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class OldJewelleryRequest extends Model implements HasMedia
{
    use InteractsWithMedia;

    /**
     * Mirrors Order::ALLOWED_TRANSITIONS — enforced in updating() below so no
     * direct API/tinker/bulk update can skip or reverse the pipeline.
     */
    public const ALLOWED_TRANSITIONS = [
        'pending' => ['submitted', 'cancelled'],
        'submitted' => ['vendors_notified', 'cancelled'],
        'vendors_notified' => ['bidding_active', 'cancelled'],
        'bidding_active' => ['bidding_closed', 'cancelled'],
        'bidding_closed' => ['bid_selected', 'cancelled'],
        'bid_selected' => ['wallet_pending', 'cancelled'],
        'wallet_pending' => ['wallet_credited', 'cancelled'],
        'wallet_credited' => ['wallet_expired', 'completed'],
        'wallet_expired' => ['completed'],
        'completed' => [],
        'cancelled' => [],
    ];

    protected $fillable = [
        'user_id',
        'request_number',
        'description',
        'status',
        'bidding_start_at',
        'bidding_end_at',
        'winning_bid_id',
        'final_amount',
        'deduction_amount',
        'credited_amount',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'bidding_start_at' => 'datetime',
            'bidding_end_at' => 'datetime',
            'final_amount' => 'decimal:2',
            'deduction_amount' => 'decimal:2',
            'credited_amount' => 'decimal:2',
            'closed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'request_number';
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('image')
            ->useDisk('original_images')
            ->storeConversionsOnDisk('public')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->singleFile();

        $this->addMediaCollection('video')
            ->useDisk('original_images')
            ->acceptsMimeTypes(['video/mp4', 'video/quicktime'])
            ->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->performOnCollections('image')
            ->width(400)
            ->format('png')
            ->quality(78);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OldJewelleryVendorInvitation::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(OldJewelleryBid::class);
    }

    public function winningBid(): BelongsTo
    {
        return $this->belongsTo(OldJewelleryBid::class, 'winning_bid_id');
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(OldJewelleryActivityLog::class);
    }

    public function walletCredit(): HasOne
    {
        return $this->hasOne(OldJewelleryWalletCredit::class);
    }

    protected static function booted(): void
    {
        static::updating(function (OldJewelleryRequest $request) {
            if (! $request->isDirty('status')) {
                return;
            }

            $from = $request->getOriginal('status');
            $to = $request->status;

            if (! array_key_exists($from, self::ALLOWED_TRANSITIONS)) {
                return;
            }

            if (! in_array($to, self::ALLOWED_TRANSITIONS[$from], true)) {
                throw ValidationException::withMessages([
                    'status' => "Old jewellery request cannot move from \"{$from}\" to \"{$to}\".",
                ]);
            }
        });
    }
}
