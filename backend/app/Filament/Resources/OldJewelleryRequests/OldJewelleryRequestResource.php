<?php

namespace App\Filament\Resources\OldJewelleryRequests;

use App\Filament\Resources\OldJewelleryRequests\Pages\ListOldJewelleryRequests;
use App\Filament\Resources\OldJewelleryRequests\Pages\ViewOldJewelleryRequest;
use App\Filament\Resources\OldJewelleryRequests\Schemas\OldJewelleryRequestInfolist;
use App\Filament\Resources\OldJewelleryRequests\Tables\OldJewelleryRequestsTable;
use App\Models\OldJewelleryRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class OldJewelleryRequestResource extends Resource
{
    protected static ?string $model = OldJewelleryRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|\UnitEnum|null $navigationGroup = 'Old Jewellery';

    protected static ?string $recordTitleAttribute = 'request_number';

    public static function table(Table $table): Table
    {
        return OldJewelleryRequestsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OldJewelleryRequestInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOldJewelleryRequests::route('/'),
            'view' => ViewOldJewelleryRequest::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * The shared App\Policies\OldJewelleryRequestPolicy::viewAny() hardcodes
     * true — correct for the customer API (Task 10), where the controller
     * query itself scopes results to the authenticated user's own requests,
     * but wrong here: it would let any authenticated panel user bypass the
     * ViewAny:OldJewelleryRequest Shield permission. Override explicitly
     * rather than touching the shared policy (that would break the API).
     */
    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('ViewAny:OldJewelleryRequest');
    }

    /**
     * Same reasoning as canViewAny(): the shared policy's view() checks
     * request ownership (customer use case), which would deny every admin
     * since admins don't "own" any request. Gate on the Shield permission
     * instead.
     */
    public static function canView(Model $record): bool
    {
        return (bool) auth()->user()?->can('View:OldJewelleryRequest');
    }
}
