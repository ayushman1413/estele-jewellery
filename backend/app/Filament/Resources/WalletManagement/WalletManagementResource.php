<?php

namespace App\Filament\Resources\WalletManagement;

use App\Filament\Resources\WalletManagement\Pages\ListWalletManagement;
use App\Filament\Resources\WalletManagement\Tables\WalletManagementTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WalletManagementResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?string $navigationLabel = 'Wallet Management';

    protected static ?string $modelLabel = 'Customer Wallet';

    protected static ?string $pluralModelLabel = 'Customer Wallets';

    // Was its own single-item 'Wallet' group — folded into 'Sales' next to
    // Customers, since both resources list the same underlying users
    // (customers with no panel roles) and a group with one item in it adds
    // a click for no organizational benefit.
    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 2;

    public static function table(Table $table): Table
    {
        return WalletManagementTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWalletManagement::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
