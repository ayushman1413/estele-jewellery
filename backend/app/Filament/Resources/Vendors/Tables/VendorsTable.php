<?php

namespace App\Filament\Resources\Vendors\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class VendorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('company_name')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('mobile')->searchable(),
                TextColumn::make('email')->searchable()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->boolean(),
                IconColumn::make('mobile_verified_at')->label('Verified')->boolean()->state(fn ($record) => (bool) $record->mobile_verified_at),
                TextColumn::make('created_at')->dateTime('d M Y, h:i A')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
