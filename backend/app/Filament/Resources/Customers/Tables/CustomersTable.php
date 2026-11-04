<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Simple pagination avoids Filament's "Showing X to Y of Z"
            // footer, which calls Number::format() unconditionally and so
            // requires the intl PHP extension — same fix already applied to
            // every other admin table in this app (see ProductsTable/
            // OrdersTable) rather than adding a fresh hard dependency here.
            ->paginationMode(PaginationMode::Simple)
            // Bulk-select checkbox column disabled: its "Select all N records"
            // banner calls Number::format() unconditionally regardless of
            // whether any bulk actions are registered, hard-requiring the intl
            // PHP extension this environment doesn't have. toolbarActions()
            // removal alone doesn't turn off selection in Filament v5 — this
            // does.
            ->disabledSelection()
            // Customers are users with no panel role — the same distinction
            // WalletManagementTable already draws between staff and shoppers.
            ->query(fn () => User::query()->whereDoesntHave('roles'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('phone')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('orders_count')
                    ->label('Orders')
                    ->counts('orders')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime('d M Y, h:i A')
                    ->sortable(),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->modalHeading(fn (User $record) => "Delete {$record->name}?")
                    ->modalDescription('Their addresses, wallet, reward submissions and jewellery requests are removed with the account. Past orders are kept for records. This cannot be undone.')
                    ->successNotificationTitle('Customer deleted'),
            ]);
    }
}
