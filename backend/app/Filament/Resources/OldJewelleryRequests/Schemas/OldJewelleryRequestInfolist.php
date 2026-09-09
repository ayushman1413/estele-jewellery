<?php

namespace App\Filament\Resources\OldJewelleryRequests\Schemas;

use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OldJewelleryRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Request')
                ->schema([
                    TextEntry::make('request_number'),
                    TextEntry::make('user.name')->label('Customer'),
                    TextEntry::make('user.email')->label('Customer email'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('description'),
                    ImageEntry::make('image')->state(fn ($record) => $record->getFirstMediaUrl('image', 'thumb') ?: null),
                ]),
            Section::make('Bidding')
                ->schema([
                    TextEntry::make('bidding_start_at')->dateTime('d M Y, h:i A'),
                    TextEntry::make('bidding_end_at')->dateTime('d M Y, h:i A'),
                    TextEntry::make('final_amount')->formatStateUsing(fn ($state) => filled($state) ? '₹'.number_format((float) $state, 2) : '—'),
                    TextEntry::make('deduction_amount')->formatStateUsing(fn ($state) => filled($state) ? '₹'.number_format((float) $state, 2) : '—'),
                    TextEntry::make('credited_amount')->label('Wallet Credited')->formatStateUsing(fn ($state) => filled($state) ? '₹'.number_format((float) $state, 2) : '—'),
                ]),
            Section::make('Vendor Responses')
                ->schema([
                    RepeatableEntry::make('invitations')
                        ->schema([
                            TextEntry::make('vendor.name'),
                            TextEntry::make('response_status')->badge(),
                            TextEntry::make('responded_at')->dateTime('d M Y, h:i A'),
                        ]),
                ]),
            Section::make('Bids')
                ->schema([
                    RepeatableEntry::make('bids')
                        ->schema([
                            TextEntry::make('bidder_type')->badge(),
                            TextEntry::make('vendor.name')->label('Vendor')->placeholder('—'),
                            TextEntry::make('adminUser.name')->label('Admin')->placeholder('—'),
                            TextEntry::make('amount')->formatStateUsing(fn ($state) => '₹'.number_format((float) $state, 2)),
                            TextEntry::make('submitted_at')->dateTime('d M Y, h:i A'),
                        ]),
                ]),
            Section::make('Activity Log')
                ->schema([
                    RepeatableEntry::make('activityLogs')
                        ->schema([
                            TextEntry::make('action'),
                            TextEntry::make('actor_type'),
                            TextEntry::make('created_at')->dateTime('d M Y, h:i A'),
                        ]),
                ]),
        ]);
    }
}
