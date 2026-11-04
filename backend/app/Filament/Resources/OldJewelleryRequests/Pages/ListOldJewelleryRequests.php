<?php

namespace App\Filament\Resources\OldJewelleryRequests\Pages;

use App\Filament\Resources\OldJewelleryRequests\OldJewelleryRequestResource;
use App\Filament\Support\NavigationSeen;
use Filament\Resources\Pages\ListRecords;

class ListOldJewelleryRequests extends ListRecords
{
    protected static string $resource = OldJewelleryRequestResource::class;

    public function mount(): void
    {
        parent::mount();

        NavigationSeen::mark('old-jewellery-requests');
    }
}
