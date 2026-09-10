<?php

namespace App\Filament\Resources\OldJewelleryRequests\Tables;

use App\Models\OldJewelleryRequest;
use App\Services\OldJewellery\OldJewelleryBiddingService;
use App\Services\OldJewellery\OldJewelleryClosingService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class OldJewelleryRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->paginationMode(PaginationMode::Simple)
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['user', 'walletCredit'])
                ->withCount([
                    'invitations',
                    'invitations as responses_count' => fn (Builder $q) => $q->where('response_status', '!=', 'pending'),
                ])
                ->withMax(['bids as highest_bid' => fn (Builder $q) => $q->where('is_valid', true)], 'amount')
                ->withMax(['bids as admin_bid' => fn (Builder $q) => $q->where('bidder_type', 'admin')], 'amount'))
            ->columns([
                TextColumn::make('request_number')->label('Request ID')->searchable()->weight('bold'),
                TextColumn::make('user.name')->label('Customer')->searchable()->description(fn (OldJewelleryRequest $record) => $record->user?->phone),
                TextColumn::make('created_at')->label('Submitted')->dateTime('d M Y, h:i A')->sortable(),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        'bidding_active' => 'warning',
                        'bid_selected', 'wallet_credited', 'completed' => 'success',
                        'cancelled', 'wallet_expired' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('bidding_start_at')->label('Bid start')->dateTime('d M Y, h:i A')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('bidding_end_at')->label('Bid end')->dateTime('d M Y, h:i A')->sortable(),
                TextColumn::make('invitations_count')->label('Invited')->alignCenter(),
                TextColumn::make('responses_count')->label('Responses')->alignCenter(),
                TextColumn::make('highest_bid')->label('Highest bid')->formatStateUsing(fn ($state) => self::money($state))->placeholder('—'),
                TextColumn::make('admin_bid')->label('Admin bid')->formatStateUsing(fn ($state) => self::money($state))->placeholder('—')->toggleable(),
                TextColumn::make('final_amount')->label('Final bid')->formatStateUsing(fn ($state) => self::money($state))->placeholder('—'),
                TextColumn::make('credited_amount')->label('Wallet amount')->formatStateUsing(fn ($state) => self::money($state))->placeholder('—'),
                TextColumn::make('walletCredit.status')->label('Wallet status')->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'active' => 'success',
                        'partially_used' => 'warning',
                        'used' => 'gray',
                        'expired' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                TextColumn::make('walletCredit.expires_at')->label('Wallet expiry')->dateTime('d M Y')->placeholder('—'),
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
                Filter::make('bidding_open')
                    ->label('Bidding open now')
                    ->query(fn (Builder $query) => $query->where('status', 'bidding_active')->where('bidding_end_at', '>', now())),
            ])
            ->recordActions([
                ViewAction::make(),
                self::adminBidAction(),
                self::closeAction(),
            ]);
    }

    public static function money(mixed $amount): string
    {
        return $amount === null || $amount === '' ? '—' : '₹'.number_format((float) $amount, 2);
    }

    public static function adminBidAction(): Action
    {
        return Action::make('admin_bid')
            ->label('Submit Valuation')
            ->icon(Heroicon::OutlinedCurrencyRupee)
            ->color('warning')
            ->visible(fn (OldJewelleryRequest $record) => in_array($record->status, ['vendors_notified', 'bidding_active'], true) && now()->lessThan($record->bidding_end_at))
            ->schema([
                TextInput::make('amount')->label('Valuation amount (₹)')->numeric()->required()->minValue(0.01)->maxValue(OldJewelleryBiddingService::MAX_BID_AMOUNT),
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
            ->modalDescription('Bidding will be locked immediately, the highest valid bid selected, and 90% credited to the customer wallet. This cannot be undone.')
            ->visible(fn (OldJewelleryRequest $record) => $record->status === 'bidding_active')
            ->action(function (OldJewelleryRequest $record) {
                $result = app(OldJewelleryClosingService::class)->close($record, force: true);

                Notification::make()->title('Request is now: '.str($result->status)->replace('_', ' ')->title())->success()->send();
            });
    }
}
