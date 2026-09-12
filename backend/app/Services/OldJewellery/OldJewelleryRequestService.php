<?php

namespace App\Services\OldJewellery;

use App\Models\OldJewelleryActivityLog;
use App\Models\OldJewelleryRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates old-jewellery requests. Video is compulsory by business rule —
 * validated here again even though the Form Request (Task 6) already
 * enforces it, since this service is also the target of direct calls
 * (tests, Filament, future internal tooling) that could otherwise bypass
 * HTTP-layer validation entirely.
 */
class OldJewelleryRequestService
{
    private const BIDDING_WINDOW_HOURS = 3;

    public function create(User $user, array $data, ?UploadedFile $image, ?UploadedFile $video): OldJewelleryRequest
    {
        if (! $video) {
            throw ValidationException::withMessages([
                'video' => ['A video is required.'],
            ]);
        }

        return DB::transaction(function () use ($user, $data, $image, $video) {
            $now = now();

            $request = OldJewelleryRequest::create([
                'user_id' => $user->id,
                'request_number' => $this->generateRequestNumber(),
                'description' => $data['description'] ?? null,
                'status' => 'pending',
                'bidding_start_at' => $now,
                'bidding_end_at' => $now->copy()->addHours(self::BIDDING_WINDOW_HOURS),
            ]);

            $request->addMedia($video)->toMediaCollection('video');

            if ($image) {
                $request->addMedia($image)->toMediaCollection('image');
            }

            $request->update(['status' => 'submitted']);

            OldJewelleryActivityLog::create([
                'old_jewellery_request_id' => $request->id,
                'actor_type' => 'customer',
                'actor_id' => $user->id,
                'action' => 'request_submitted',
                'from_status' => 'pending',
                'to_status' => 'submitted',
            ]);

            return $request->fresh();
        });
    }

    /**
     * A single global counter row, not one per year: the number carries no
     * year any more, so resetting it annually would hand 2027 the same "001"
     * that 2026 already used — and request_number is the route key, so a
     * duplicate would collide on lookups as well as the unique index.
     *
     * Zero-padded to 3 for readability only; past 999 it simply grows to
     * "1000" rather than wrapping or truncating.
     */
    public const SEQUENCE_KEY = 'global';

    /**
     * Format: zero-padded sequence, e.g. 001, 002 ... 999, 1000. Uses a
     * dedicated counter row (locked for update inside this transaction) so
     * two concurrent requests in the same second can never collide — the
     * counter increment and the read happen atomically under the row lock.
     */
    public function generateRequestNumber(): string
    {
        return DB::transaction(function () {
            $sequence = DB::table('old_jewellery_number_sequences')
                ->where('sequence_key', self::SEQUENCE_KEY)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                DB::table('old_jewellery_number_sequences')->insert([
                    'sequence_key' => self::SEQUENCE_KEY,
                    'last_value' => 0,
                ]);
                $nextValue = 1;
            } else {
                $nextValue = $sequence->last_value + 1;
            }

            DB::table('old_jewellery_number_sequences')
                ->where('sequence_key', self::SEQUENCE_KEY)
                ->update(['last_value' => $nextValue]);

            return sprintf('%03d', $nextValue);
        });
    }
}
