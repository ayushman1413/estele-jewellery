<?php

namespace App\Filament\Resources\Vendors\Pages;

use App\Filament\Resources\Vendors\Tables\VendorsTable;
use App\Filament\Resources\Vendors\VendorResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewVendor extends ViewRecord
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            VendorsTable::sendOtpAction(),
            VendorsTable::verifyOtpAction()->after(fn () => $this->refreshFormData(['mobile_verified_at'])),
            EditAction::make(),
        ];
    }
}
