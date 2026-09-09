<?php

namespace App\Filament\Resources\Vendors\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class VendorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            TextInput::make('company_name'),
            TextInput::make('mobile')->required()->unique(ignoreRecord: true)->tel(),
            TextInput::make('email')->email(),
            TextInput::make('whatsapp_number')->tel(),
            Toggle::make('is_active')->default(true),
        ]);
    }
}
