<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OldJewelleryWalletCredit extends Model
{
    protected $fillable = [
        'user_id',
        'old_jewellery_request_id',
        'wallet_transaction_id',
        'gross_amount',
        'deduction_amount',
        'credited_amount',
        'remaining_amount',
        'credited_at',
        'expires_at',
        'status',
        'reminder_3d_sent_at',
        'reminder_1d_sent_at',
        'reminder_0d_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:2',
            'deduction_amount' => 'decimal:2',
            'credited_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'credited_at' => 'datetime',
            'expires_at' => 'datetime',
            'reminder_3d_sent_at' => 'datetime',
            'reminder_1d_sent_at' => 'datetime',
            'reminder_0d_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(OldJewelleryRequest::class, 'old_jewellery_request_id');
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
