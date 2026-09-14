<?php

namespace App\Observers;

use App\Models\HomepageBlock;
use Illuminate\Support\Facades\Cache;

class HomepageBlockObserver
{
    public function saved(HomepageBlock $block): void
    {
        Cache::tags(['home'])->flush();
    }

    public function deleting(HomepageBlock $block): void
    {
        // Items carry images; the FK cascade alone would orphan them.
        $block->items()->get()->each->delete();
    }

    public function deleted(HomepageBlock $block): void
    {
        Cache::tags(['home'])->flush();
    }
}
