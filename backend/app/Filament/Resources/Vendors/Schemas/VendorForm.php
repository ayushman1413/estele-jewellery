<?php

namespace App\Filament\Resources\Vendors\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class VendorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Vendor')
                ->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('company_name')->label('Company')->maxLength(255),
                    TextInput::make('mobile')
                        ->required()
                        ->tel()
                        ->unique(ignoreRecord: true)
                        ->helperText(fn ($record) => $record?->mobile_verified_at
                            ? "Verified on {$record->mobile_verified_at->format('d M Y')}"
                            : 'Not verified yet — use "Verify mobile" after saving.'),
                    TextInput::make('whatsapp_number')
                        ->label('WhatsApp')
                        ->tel()
                        ->helperText('Bid invites and links also go here. Leave blank to use the mobile number.'),
                    Toggle::make('is_active')->label('Active — receives bid invitations')->default(true)->columnSpanFull(),
                ]),

            Section::make('Panel login')
                ->description('Optional. With an email the vendor gets a link to set a password and can see their requests in this panel.')
                ->columns(2)
                ->schema([
                    TextInput::make('email')->email()->unique(ignoreRecord: true)->maxLength(255),
                    Placeholder::make('panel_login')
                        ->label('Status')
                        ->content(fn ($record) => match (true) {
                            $record === null => 'Link is sent when you save with an email.',
                            blank($record->email) => 'No email — no login.',
                            $record->user === null => 'Not invited yet — save to send the link.',
                            filled($record->user->password) => 'Active — password set.',
                            default => 'Invited — waiting for password.',
                        }),
                ]),
        ]);
    }
}
