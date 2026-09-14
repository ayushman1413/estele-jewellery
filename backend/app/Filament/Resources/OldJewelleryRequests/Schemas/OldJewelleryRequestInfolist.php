<?php

namespace App\Filament\Resources\OldJewelleryRequests\Schemas;

use App\Filament\Resources\OldJewelleryRequests\OldJewelleryRequestResource;
use App\Models\OldJewelleryActivityLog;
use App\Models\OldJewelleryBid;
use App\Models\OldJewelleryRequest;
use App\Models\User;
use App\Models\Vendor;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OldJewelleryRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Customer')
                ->columns(3)
                ->schema([
                    TextEntry::make('request_number')->label('Request ID')->copyable(),
                    TextEntry::make('user.name')->label('Name'),
                    TextEntry::make('user.phone')->label('Phone')->placeholder('—')
                        ->visible(fn () => OldJewelleryRequestResource::currentVendorId() === null),
                    TextEntry::make('user.email')->label('Email')->placeholder('—')
                        ->visible(fn () => OldJewelleryRequestResource::currentVendorId() === null),
                    TextEntry::make('created_at')->label('Submitted at')->dateTime('d M Y, h:i A'),
                    TextEntry::make('status')->badge(),
                ]),

            Section::make('Jewellery')
                ->columns(2)
                ->schema([
                    TextEntry::make('description')->columnSpanFull()->placeholder('No description'),
                    // Photo and video render as one entry: small tiles side by
                    // side, each opening a full-page overlay on click.
                    ViewEntry::make('media')
                        ->label('Photo & video')
                        ->view('filament.old-jewellery.media')
                        ->columnSpanFull(),
                ]),

            Section::make('Bidding')
                ->columns(4)
                ->schema([
                    TextEntry::make('bidding_start_at')->label('Bidding start')->dateTime('d M Y, h:i A')->placeholder('—'),
                    TextEntry::make('bidding_end_at')->label('Bidding end')->dateTime('d M Y, h:i A')->placeholder('—'),
                    ViewEntry::make('countdown')->label('Time remaining')->view('filament.old-jewellery.countdown'),
                    TextEntry::make('closed_at')->label('Closed at')->dateTime('d M Y, h:i A')->placeholder('—'),

                    TextEntry::make('invited_count')->label('Vendors invited')
                        ->state(fn (OldJewelleryRequest $record) => $record->invitations->count()),
                    TextEntry::make('accepted_count')->label('Accepted')->color('success')
                        ->state(fn (OldJewelleryRequest $record) => $record->invitations->where('response_status', 'accepted')->count()),
                    TextEntry::make('declined_count')->label('Declined')->color('danger')
                        ->state(fn (OldJewelleryRequest $record) => $record->invitations->where('response_status', 'declined')->count()),
                    TextEntry::make('pending_count')->label('No response')->color('gray')
                        ->state(fn (OldJewelleryRequest $record) => $record->invitations->where('response_status', 'pending')->count()),

                    TextEntry::make('highest_bid')->label('Highest bid')->weight('bold')
                        ->state(fn (OldJewelleryRequest $record) => self::money($record->bids->where('is_valid', true)->max('amount')))
                        ->visible(fn () => OldJewelleryRequestResource::currentVendorId() === null),
                    TextEntry::make('admin_bid')->label('Admin bid')
                        ->state(fn (OldJewelleryRequest $record) => self::money($record->bids->where('bidder_type', 'admin')->max('amount')))
                        ->visible(fn () => OldJewelleryRequestResource::currentVendorId() === null),
                    TextEntry::make('final_amount')->label('Final selected bid')->weight('bold')->color('success')
                        ->formatStateUsing(fn ($state) => self::money($state))->placeholder('—'),
                    TextEntry::make('winner')->label('Winning bidder')
                        ->state(fn (OldJewelleryRequest $record) => self::bidderName($record->winningBid))
                        ->placeholder('—')
                        ->visible(fn () => OldJewelleryRequestResource::currentVendorId() === null),
                ]),

            Section::make('Wallet')
                ->columns(4)
                ->visible(fn () => OldJewelleryRequestResource::currentVendorId() === null)
                ->schema([
                    TextEntry::make('walletCredit.gross_amount')->label('Highest bid')->formatStateUsing(fn ($state) => self::money($state))->placeholder('—'),
                    TextEntry::make('walletCredit.deduction_amount')->label('10% deduction')->color('danger')->formatStateUsing(fn ($state) => self::money($state))->placeholder('—'),
                    TextEntry::make('walletCredit.credited_amount')->label('Wallet credit (90%)')->weight('bold')->color('success')->formatStateUsing(fn ($state) => self::money($state))->placeholder('—'),
                    TextEntry::make('walletCredit.remaining_amount')->label('Remaining balance')->formatStateUsing(fn ($state) => self::money($state))->placeholder('—'),
                    TextEntry::make('walletCredit.credited_at')->label('Credited on')->dateTime('d M Y, h:i A')->placeholder('Not credited yet'),
                    TextEntry::make('walletCredit.expires_at')->label('Expires on')->dateTime('d M Y, h:i A')->placeholder('—'),
                    TextEntry::make('walletCredit.status')->label('Wallet status')->badge()
                        ->color(fn (?string $state) => match ($state) {
                            'active' => 'success',
                            'partially_used' => 'warning',
                            'used' => 'gray',
                            'expired' => 'danger',
                            default => 'gray',
                        })
                        ->placeholder('Not credited yet'),
                ]),

            Section::make('Vendor Responses')
                ->collapsible()
                ->visible(fn () => OldJewelleryRequestResource::currentVendorId() === null)
                ->schema([
                    RepeatableEntry::make('invitations')
                        ->hiddenLabel()
                        ->table([
                            TableColumn::make('Vendor'),
                            TableColumn::make('Company'),
                            TableColumn::make('Response'),
                            TableColumn::make('Bid amount'),
                            TableColumn::make('Decline reason'),
                            TableColumn::make('Notified at'),
                            TableColumn::make('Responded at'),
                        ])
                        ->schema([
                            TextEntry::make('vendor.name'),
                            TextEntry::make('vendor.company_name')->placeholder('—'),
                            TextEntry::make('response_status')->badge()
                                ->color(fn (string $state) => match ($state) {
                                    'accepted' => 'success',
                                    'declined' => 'danger',
                                    default => 'gray',
                                }),
                            TextEntry::make('bid.amount')->formatStateUsing(fn ($state) => self::money($state))->placeholder('—'),
                            TextEntry::make('decline_reason')->placeholder('—'),
                            TextEntry::make('notified_at')->dateTime('d M Y, h:i A')->placeholder('—'),
                            TextEntry::make('responded_at')->dateTime('d M Y, h:i A')->placeholder('—'),
                        ])
                        ->placeholder('No vendors invited yet'),
                ]),

            Section::make('Bid Comparison')
                ->collapsible()
                ->visible(fn () => OldJewelleryRequestResource::currentVendorId() === null)
                ->schema([
                    RepeatableEntry::make('bids')
                        ->hiddenLabel()
                        ->state(fn (OldJewelleryRequest $record) => $record->bids->sortByDesc('amount')->values())
                        ->table([
                            TableColumn::make('Bidder'),
                            TableColumn::make('Type'),
                            TableColumn::make('Amount'),
                            TableColumn::make('Submitted at'),
                            TableColumn::make('Result'),
                        ])
                        ->schema([
                            TextEntry::make('bidder')->state(fn (OldJewelleryBid $record) => self::bidderName($record)),
                            TextEntry::make('bidder_type')->badge()->color(fn (string $state) => $state === 'admin' ? 'warning' : 'info'),
                            TextEntry::make('amount')->weight('bold')->formatStateUsing(fn ($state) => self::money($state)),
                            TextEntry::make('submitted_at')->dateTime('d M Y, h:i A'),
                            TextEntry::make('result')
                                ->badge()
                                ->state(fn (OldJewelleryBid $record) => match (true) {
                                    $record->request->winning_bid_id === $record->id => 'Winner',
                                    ! $record->is_valid => 'Invalid',
                                    default => 'Valid',
                                })
                                ->color(fn (string $state) => match ($state) {
                                    'Winner' => 'success',
                                    'Invalid' => 'danger',
                                    default => 'gray',
                                }),
                        ])
                        ->placeholder('No bids yet'),
                ]),

            Section::make('Activity Log')
                ->collapsible()
                ->visible(fn () => OldJewelleryRequestResource::currentVendorId() === null)
                ->schema([
                    RepeatableEntry::make('activityLogs')
                        ->hiddenLabel()
                        ->state(fn (OldJewelleryRequest $record) => $record->activityLogs->sortBy('created_at')->values())
                        ->table([
                            TableColumn::make('When'),
                            TableColumn::make('Actor'),
                            TableColumn::make('Event'),
                            TableColumn::make('Details'),
                        ])
                        ->schema([
                            TextEntry::make('created_at')->dateTime('d M Y, h:i A'),
                            TextEntry::make('actor_type')->badge()
                                ->color(fn (string $state) => match ($state) {
                                    'customer' => 'info',
                                    'vendor' => 'primary',
                                    'admin' => 'warning',
                                    default => 'gray',
                                }),
                            TextEntry::make('action')->formatStateUsing(fn (string $state) => self::eventLabel($state)),
                            TextEntry::make('details')->state(fn (OldJewelleryActivityLog $record) => self::logDetails($record))->placeholder('—'),
                        ])
                        ->placeholder('No activity yet'),
                ]),
        ]);
    }

    public static function money(mixed $amount): string
    {
        return $amount === null || $amount === '' ? '—' : '₹'.number_format((float) $amount, 2);
    }

    public static function bidderName(?OldJewelleryBid $bid): ?string
    {
        if (! $bid) {
            return null;
        }

        return $bid->bidder_type === 'admin'
            ? 'Admin'.($bid->adminUser?->name ? " ({$bid->adminUser->name})" : '')
            : ($bid->vendor?->name ?? 'Vendor');
    }

    public static function eventLabel(string $action): string
    {
        return match ($action) {
            'request_submitted' => 'Customer submitted request',
            'vendors_notified' => 'Vendors notified',
            'vendor_bid_submitted' => 'Vendor submitted bid',
            'vendor_declined' => 'Vendor declined',
            'admin_bid_submitted' => 'Admin submitted valuation',
            'bidding_closed' => 'Bidding closed',
            'no_valid_bids' => 'No valid bids — cancelled',
            'no_active_vendors' => 'No active vendors — cancelled',
            'bid_selected' => 'Highest bid selected',
            'wallet_credited' => 'Wallet credited',
            'wallet_expired' => 'Wallet credit expired',
            'wallet_spent_completed' => 'Wallet fully used',
            default => str($action)->replace('_', ' ')->ucfirst()->toString(),
        };
    }

    public static function logDetails(OldJewelleryActivityLog $log): ?string
    {
        $parts = [];

        if ($log->actor_type === 'vendor' && $log->actor_id) {
            $parts[] = Vendor::find($log->actor_id)?->name;
        }

        if ($log->actor_type === 'admin' && $log->actor_id) {
            $parts[] = User::find($log->actor_id)?->name;
        }

        foreach ($log->metadata ?? [] as $key => $value) {
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }

            $parts[] = in_array($key, ['amount', 'gross_amount', 'deduction_amount', 'credited_amount', 'remaining_amount'], true)
                ? str($key)->replace('_', ' ')->ucfirst().': '.self::money($value)
                : str($key)->replace('_', ' ')->ucfirst().': '.$value;
        }

        if ($log->from_status && $log->to_status) {
            $parts[] = "{$log->from_status} → {$log->to_status}";
        }

        return $parts ? implode(' · ', array_filter($parts)) : null;
    }
}
