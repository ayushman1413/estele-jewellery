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
}
