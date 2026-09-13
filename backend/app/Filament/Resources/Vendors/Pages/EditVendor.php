<?php

namespace App\Filament\Resources\Vendors\Pages;

use App\Filament\Resources\Vendors\Tables\VendorsTable;
use App\Filament\Resources\Vendors\VendorResource;
use App\Services\Vendors\PanelAccessService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditVendor extends EditRecord
{
    protected static string $resource = VendorResource::class;

    /** Set in mutateFormDataBeforeSave, acted on in afterSave. */
    private bool $shouldInvite = false;

    protected function getHeaderActions(): array
    {
        return [
            VendorsTable::verifyOtpAction()->after(fn () => $this->refreshFormData(['mobile'])),
            VendorsTable::resendSetupLinkAction(),
            ViewAction::make()->label('Performance'),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['mobile'] ?? null) !== $this->record->mobile) {
            $data['mobile_verified_at'] = null;
        }

        // An email that was just added (or changed) is the one case where the
        // recipient has no working link yet, so that save re-invites. A role
        // change alone only needs the role synced, which grant() also does.
        $emailChanged = ($data['email'] ?? null) !== $this->record->email;
        $this->shouldInvite = filled($data['email'] ?? null)
            && ($emailChanged || $this->record->user === null);

        return $data;
    }

    protected function afterSave(): void
    {
        if (blank($this->record->email)) {
            return;
        }

        $service = app(PanelAccessService::class);

        if (! $this->shouldInvite) {
            // Keeps the linked user's role in step when the admin flips
            // between Vendor and Admin, without mailing them again.
            $service->grantWithoutNotifying($this->record);

            return;
        }

        if ($service->grant($this->record)) {
            Notification::make()
                ->title('Setup link sent')
                ->body("A password setup link was sent to {$this->record->email}.")
                ->success()
                ->send();
        }
    }
}
