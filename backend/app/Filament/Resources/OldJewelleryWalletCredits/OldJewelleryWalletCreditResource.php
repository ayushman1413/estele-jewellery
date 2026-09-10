<?php

namespace App\Filament\Resources\OldJewelleryWalletCredits;

use App\Filament\Resources\OldJewelleryWalletCredits\Pages\ListOldJewelleryWalletCredits;
use App\Filament\Resources\OldJewelleryWalletCredits\Tables\OldJewelleryWalletCreditsTable;
use App\Models\OldJewelleryWalletCredit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OldJewelleryWalletCreditResource extends Resource
{
    protected static ?string $model = OldJewelleryWalletCredit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Old Jewellery Credits';

    protected static ?string $modelLabel = 'Old Jewellery Credit';

    protected static ?string $pluralModelLabel = 'Old Jewellery Credits';

    protected static string|\UnitEnum|null $navigationGroup = 'Wallet';

    public static function table(Table $table): Table
    {
        return OldJewelleryWalletCreditsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOldJewelleryWalletCredits::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->can('ViewAny:OldJewelleryWalletCredit');
    }
}
