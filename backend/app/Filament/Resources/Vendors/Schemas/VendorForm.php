<?php

namespace App\Filament\Resources\Vendors\Schemas;

use App\Models\Vendor;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class VendorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Radio::make('access_role')
                ->label('Role')
                ->options([
                    Vendor::ACCESS_ROLE_VENDOR => 'Vendor — buys old jewellery',
                    Vendor::ACCESS_ROLE_ADMIN => 'Admin — panel access only',
                ])
                ->descriptions([
                    Vendor::ACCESS_ROLE_VENDOR => 'Receives every bidding notification: new requests, bid updates and outcomes.',
                    Vendor::ACCESS_ROLE_ADMIN => 'Account emails only (password setup and resends). Never invited to bid.',
                ])
                ->default(Vendor::ACCESS_ROLE_VENDOR)
                ->required()
                ->live(),

            TextInput::make('name')->required(),
            TextInput::make('company_name'),
            TextInput::make('mobile')->required()->unique(ignoreRecord: true)->tel(),

            TextInput::make('email')
                ->email()
                ->unique(ignoreRecord: true)
                ->helperText('Used to sign in. Leaving this blank means no panel login — the contact can still be invited to bid by token.'),

            TextInput::make('whatsapp_number')
                ->tel()
                ->helperText('The setup link is also sent here when a number is given.'),

            Toggle::make('is_active')->default(true),

            Placeholder::make('mobile_verified_at')
                ->label('Mobile Verified')
                ->content(fn ($record) => $record?->mobile_verified_at
                    ? "Verified on {$record->mobile_verified_at->format('d M Y')}"
                    : 'Not verified'),

            Placeholder::make('panel_login')
                ->label('Panel login')
                ->visible(fn ($record) => $record !== null)
                ->content(fn ($record) => match (true) {
                    blank($record?->email) => 'No email on file — no login.',
                    $record->user === null => 'Not created yet — save to send the setup link.',
                    filled($record->user->password) => 'Active. Password already set.',
                    default => 'Invited — waiting for the password to be set.',
                }),
        ]);
    }
}
