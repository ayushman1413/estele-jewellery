<?php

namespace App\Filament\Resources\RewardSubmissions\Pages;

use App\Filament\Resources\RewardSubmissions\RewardSubmissionResource;
use App\Filament\Support\NavigationSeen;
use Filament\Resources\Pages\ListRecords;

class ListRewardSubmissions extends ListRecords
{
    protected static string $resource = RewardSubmissionResource::class;

    public function mount(): void
    {
        parent::mount();

        NavigationSeen::mark('reward-submissions');
    }
}
