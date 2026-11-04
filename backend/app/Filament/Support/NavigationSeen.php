<?php

namespace App\Filament\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Sidebar badges count only what arrived since the admin last opened that
 * list, so opening the page clears the number and new items bring it back.
 * Tracked per admin in the cache — no schema, survives logins.
 */
class NavigationSeen
{
    public static function mark(string $key): void
    {
        if ($id = auth()->id()) {
            Cache::forever(self::cacheKey($id, $key), now()->toIso8601String());
        }
    }

    public static function badge(string $key, Builder $pending): ?string
    {
        $id = auth()->id();

        if (! $id) {
            return null;
        }

        $seen = Cache::get(self::cacheKey($id, $key));

        if ($seen) {
            $pending->where('created_at', '>', CarbonImmutable::parse($seen));
        }

        $count = $pending->count();

        return $count > 0 ? (string) $count : null;
    }

    private static function cacheKey(int|string $userId, string $key): string
    {
        return "admin.nav-seen.{$userId}.{$key}";
    }
}
