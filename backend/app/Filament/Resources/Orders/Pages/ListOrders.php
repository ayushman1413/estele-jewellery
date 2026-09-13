<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\NavigationSeen;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function mount(): void
    {
        parent::mount();

        NavigationSeen::mark('orders');
    }

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
