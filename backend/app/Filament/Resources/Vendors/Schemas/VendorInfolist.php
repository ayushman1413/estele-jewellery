<?php

namespace App\Filament\Resources\Vendors\Schemas;

use App\Models\OldJewelleryRequest;
use App\Models\Vendor;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VendorInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Details')->schema([
                TextEntry::make('name'),
                TextEntry::make('company_name')->placeholder('—'),
                TextEntry::make('mobile'),
                TextEntry::make('email')->placeholder('—'),
                TextEntry::make('whatsapp_number')->placeholder('—'),
                TextEntry::make('is_active')->label('Active')->formatStateUsing(fn (bool $state) => $state ? 'Yes' : 'No'),
                TextEntry::make('mobile_verified_at')
                    ->label('Mobile Verified')
                    ->formatStateUsing(fn ($state) => $state ? "Verified on {$state->format('d M Y')}" : 'Not verified'),
            ]),

            Section::make('Performance')->schema([
                TextEntry::make('invitations_sent')
                    ->label('Invitations Sent')
                    ->state(fn (Vendor $record) => $record->invitations()->count()),
                TextEntry::make('invitations_accepted')
                    ->label('Accepted')
                    ->state(fn (Vendor $record) => $record->invitations()->where('response_status', 'accepted')->count()),
                TextEntry::make('invitations_declined')
                    ->label('Declined')
                    ->state(fn (Vendor $record) => $record->invitations()->where('response_status', 'declined')->count()),
                TextEntry::make('bids_submitted')
                    ->label('Bids Submitted')
                    ->state(fn (Vendor $record) => $record->bids()->count()),
                TextEntry::make('bids_won')
                    ->label('Bids Won')
                    ->state(fn (Vendor $record) => OldJewelleryRequest::whereIn(
                        'winning_bid_id',
                        $record->bids()->pluck('id'),
                    )->count()),
                TextEntry::make('win_rate')
                    ->label('Win Rate')
                    ->state(function (Vendor $record) {
                        $submitted = $record->bids()->count();
                        if ($submitted === 0) {
                            return '—';
                        }
                        $won = OldJewelleryRequest::whereIn('winning_bid_id', $record->bids()->pluck('id'))->count();

                        return round(($won / $submitted) * 100).'%';
                    }),
            ]),
        ]);
    }
}
