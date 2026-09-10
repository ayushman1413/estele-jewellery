<?php

namespace App\Filament\Resources\OldJewelleryRequests\Pages;

use App\Filament\Resources\OldJewelleryRequests\OldJewelleryRequestResource;
use App\Filament\Resources\OldJewelleryRequests\Tables\OldJewelleryRequestsTable;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewOldJewelleryRequest extends ViewRecord
{
    protected static string $resource = OldJewelleryRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OldJewelleryRequestsTable::adminBidAction()->after(fn () => $this->reloadPage()),
            OldJewelleryRequestsTable::closeAction()->after(fn () => $this->reloadPage()),
        ];
    }

    private function reloadPage(): void
    {
        $this->redirect(static::getResource()::getUrl('view', ['record' => $this->record]));
    }

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load([
            'user',
            'walletCredit',
            'winningBid.vendor',
            'winningBid.adminUser',
            'invitations.vendor',
            'invitations.bid',
            'bids.vendor',
            'bids.adminUser',
            'bids.request',
            'activityLogs',
        ]);
    }
}
