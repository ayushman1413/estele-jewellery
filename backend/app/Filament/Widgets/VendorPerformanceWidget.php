<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Vendors\VendorResource;
use App\Models\OldJewelleryRequest;
use App\Models\Vendor;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class VendorPerformanceWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Vendor Performance';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('ViewAny:Vendor');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Vendor::query()
                ->withCount([
                    'invitations',
                    'invitations as accepted_count' => fn (Builder $q) => $q->where('response_status', 'accepted'),
                    'invitations as declined_count' => fn (Builder $q) => $q->where('response_status', 'declined'),
                    'bids as won_count' => fn (Builder $q) => $q->whereIn(
                        'old_jewellery_bids.id',
                        OldJewelleryRequest::whereNotNull('winning_bid_id')->select('winning_bid_id'),
                    ),
                ])
                ->withAvg('bids', 'amount'))
            ->paginationMode(PaginationMode::Simple)
            ->defaultSort('won_count', 'desc')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->columns([
                TextColumn::make('name')->label('Vendor')->description(fn (Vendor $record) => $record->company_name)->searchable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('invitations_count')->label('Invited')->alignCenter()->sortable(),
                TextColumn::make('accepted_count')->label('Accepted')->alignCenter()->sortable(),
                TextColumn::make('declined_count')->label('Declined')->alignCenter()->sortable(),
                TextColumn::make('won_count')->label('Won')->alignCenter()->sortable()->weight('bold'),
                TextColumn::make('response_rate')->label('Response rate')->alignCenter()
                    ->state(fn (Vendor $record) => $record->invitations_count > 0
                        ? round((($record->accepted_count + $record->declined_count) / $record->invitations_count) * 100).'%'
                        : '—'),
                TextColumn::make('win_rate')->label('Win rate')->alignCenter()
                    ->state(fn (Vendor $record) => $record->accepted_count > 0
                        ? round(($record->won_count / $record->accepted_count) * 100).'%'
                        : '—'),
                TextColumn::make('bids_avg_amount')->label('Avg bid')
                    ->formatStateUsing(fn ($state) => filled($state) ? '₹'.number_format((float) $state, 2) : '—')
                    ->placeholder('—'),
            ])
            ->recordUrl(fn (Vendor $record) => VendorResource::getUrl('view', ['record' => $record]));
    }
}
