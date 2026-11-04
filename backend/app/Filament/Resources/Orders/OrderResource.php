<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Filament\Support\NavigationSeen;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    // Admin sidebar order requested: Dashboard, Orders, Products, Categories,
    // Collections, Coupons & Offers, Homepage Blocks, Banners, SEO, Settings,
    // Roles. Dashboard (Filament core default, navigationSort -2) and Roles
    // (Shield package resource, no navigationSort hook available) sort
    // themselves — see AdminPanelProvider for why Roles can't be pinned last
    // without risky vendor changes. Values spaced by 10 for future inserts.
    protected static ?int $navigationSort = 20;

    public static function getNavigationBadge(): ?string
    {
        return NavigationSeen::badge('orders', Order::query()->where('status', 'placed'));
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'New orders to accept';
    }

    public static function form(Schema $schema): Schema
    {
        return OrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
