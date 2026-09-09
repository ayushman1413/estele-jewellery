<?php

namespace App\Filament\Resources\OldJewelleryRequests\Tables;

use App\Models\OldJewelleryRequest;
use App\Services\OldJewellery\OldJewelleryBiddingService;
use App\Services\OldJewellery\OldJewelleryClosingService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OldJewelleryRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->paginationMode(PaginationMode::Simple)
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('request_number')->searchable(),
                TextColumn::make('user.name')->label('Customer')->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('bidding_start_at')->dateTime('d M Y, h:i A')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('bidding_end_at')->dateTime('d M Y, h:i A'),
                TextColumn::make('invitations_count')->counts('invitations')->label('Vendors Invited'),
                TextColumn::make('final_amount')->formatStateUsing(fn ($state) => filled($state) ? '₹'.number_format((float) $state, 2) : '—'),
                TextColumn::make('credited_amount')->label('Wallet Credited')->formatStateUsing(fn ($state) => filled($state) ? '₹'.number_format((float) $state, 2) : '—'),
                TextColumn::make('created_at')->label('Submitted')->dateTime('d M Y, h:i A')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'submitted' => 'Submitted',
                    'vendors_notified' => 'Vendors Notified',
                    'bidding_active' => 'Bidding Active',
                    'bidding_closed' => 'Bidding Closed',
                    'bid_selected' => 'Bid Selected',
                    'wallet_pending' => 'Wallet Pending',
                    'wallet_credited' => 'Wallet Credited',
                    'wallet_expired' => 'Wallet Expired',
                    'completed' => 'Completed',
                    'cancelled' => 'Cancelled',
                ]),
            ])
            ->recordActions([
                self::adminBidAction(),
                self::closeAction(),
            ]);
    }

    public static function adminBidAction(): Action
    {
        return Action::make('admin_bid')
            ->label('Submit Valuation')
            ->icon(Heroicon::OutlinedCurrencyRupee)
            ->color('warning')
            ->visible(fn (OldJewelleryRequest $record) => in_array($record->status, ['vendors_notified', 'bidding_active'], true) && now()->lessThan($record->bidding_end_at))
            ->schema([
                TextInput::make('amount')->label('Valuation amount (₹)')->numeric()->required()->minValue(0.01),
            ])
            ->action(function (array $data, OldJewelleryRequest $record) {
                try {
                    app(OldJewelleryBiddingService::class)->submitAdminBid($record, auth()->user(), (float) $data['amount']);
                } catch (\DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Valuation submitted.')->success()->send();
            });
    }

    public static function closeAction(): Action
    {
        return Action::make('close_bidding')
            ->label('Close Bidding Now')
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (OldJewelleryRequest $record) => $record->status === 'bidding_active')
            ->action(function (OldJewelleryRequest $record) {
                $result = app(OldJewelleryClosingService::class)->close($record);

                Notification::make()->title("Request is now: {$result->status}")->success()->send();
            });
    }
}
