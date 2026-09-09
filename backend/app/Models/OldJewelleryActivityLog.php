<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OldJewelleryActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'old_jewellery_request_id',
        'actor_type',
        'actor_id',
        'action',
        'from_status',
        'to_status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(OldJewelleryRequest::class, 'old_jewellery_request_id');
    }
}
