<?php

namespace App\Filament\Resources\Vendors\Pages;

use App\Filament\Resources\Vendors\VendorResource;
use App\Models\Vendor;
use App\Services\Vendors\PanelAccessService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateVendor extends CreateRecord
{
    protected static string $resource = VendorResource::class;

    /**
     * Creating the contact is what triggers the invite — the admin never sets
     * a password, the recipient chooses their own from the emailed link.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['access_role'] = Vendor::ACCESS_ROLE_VENDOR;

        return $data;
    }

    protected function afterCreate(): void
    {
        if (blank($this->record->email)) {
            Notification::make()
                ->title('Saved without a login')
                ->body('No email was given, so no setup link was sent. Add an email and save to invite them.')
                ->warning()
                ->send();

            return;
        }

        $sent = app(PanelAccessService::class)->grant($this->record);

        if ($sent) {
            Notification::make()
                ->title('Setup link sent')
                ->body("{$this->record->name} can now set a password from the link sent to {$this->record->email}.")
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title('Account created, but the link could not be sent')
            ->body('Use "Resend setup link" on the vendor once mail delivery is working.')
            ->danger()
            ->send();
    }
}
