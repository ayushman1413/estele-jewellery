<?php

namespace App\Filament\Resources\Vendors\Pages;

use App\Filament\Resources\Vendors\Tables\VendorsTable;
use App\Filament\Resources\Vendors\VendorResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditVendor extends EditRecord
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            VendorsTable::sendOtpAction(),
            VendorsTable::verifyOtpAction()->after(fn () => $this->refreshFormData(['mobile_verified_at'])),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['mobile'] ?? null) !== $this->record->mobile) {
            $data['mobile_verified_at'] = null;
        }

        return $data;
    }
}
