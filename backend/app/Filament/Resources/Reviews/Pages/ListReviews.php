<?php

namespace App\Filament\Resources\Reviews\Pages;

use App\Filament\Resources\Reviews\ReviewResource;
use App\Filament\Support\NavigationSeen;
use Filament\Resources\Pages\ListRecords;

class ListReviews extends ListRecords
{
    protected static string $resource = ReviewResource::class;

    public function mount(): void
    {
        parent::mount();

        NavigationSeen::mark('reviews');
    }

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
