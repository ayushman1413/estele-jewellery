<?php

namespace App\Filament\Resources\OldJewelleryWalletCredits\Tables;

use App\Models\OldJewelleryWalletCredit;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OldJewelleryWalletCreditsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->paginationMode(PaginationMode::Simple)
            ->defaultSort('expires_at', 'asc')
            ->columns([
                TextColumn::make('user.name')->label('Customer')->searchable(),
                TextColumn::make('request.request_number')->label('Request')->searchable(),
                TextColumn::make('gross_amount')->formatStateUsing(fn ($state) => '₹'.number_format((float) $state, 2)),
                TextColumn::make('deduction_amount')->formatStateUsing(fn ($state) => '₹'.number_format((float) $state, 2)),
                TextColumn::make('credited_amount')->formatStateUsing(fn ($state) => '₹'.number_format((float) $state, 2)),
                TextColumn::make('remaining_amount')->formatStateUsing(fn ($state) => '₹'.number_format((float) $state, 2)),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'partially_used' => 'warning',
                        'used' => 'gray',
                        'expired' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('credited_at')->dateTime('d M Y, h:i A')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('expires_at')
                    ->dateTime('d M Y, h:i A')
                    ->color(fn (OldJewelleryWalletCredit $record) => in_array($record->status, ['active', 'partially_used'], true) && now()->diffInDays($record->expires_at, false) <= 2 ? 'danger' : null)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Active',
                    'partially_used' => 'Partially Used',
                    'used' => 'Used',
                    'expired' => 'Expired',
                ]),
                Filter::make('expiring_soon')
                    ->label('Expiring soon (≤3 days)')
                    ->query(fn ($query) => $query
                        ->whereIn('status', ['active', 'partially_used'])
                        ->where('expires_at', '<=', now()->addDays(3))),
            ]);
    }
}
