<?php

namespace App\Filament\Resources\Banners\Schemas;

use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class BannerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->maxLength(255)
                    ->helperText('Internal label only — not shown on the site.'),
                TextInput::make('link_url')
                    ->label('Link URL')
                    ->maxLength(255)
                    ->helperText('e.g. /collections/necklace-sets'),
                TextInput::make('sort_order')
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(0),
                Toggle::make('is_active')
                    ->required()
                    ->default(true),
                SpatieMediaLibraryFileUpload::make('image')
                    ->label('Desktop Banner')
                    ->collection('image')
                    ->conversion('desktop')
                    ->image()
                    ->maxSize(1024) // 1MB
                    ->required()
                    ->helperText('Shown on tablet/desktop widths. Used as the mobile fallback too if no Mobile Banner is uploaded below.')
                    ->columnSpanFull(),
                SpatieMediaLibraryFileUpload::make('mobile_image')
                    ->label('Mobile Banner')
                    ->collection('mobile_image')
                    ->conversion('mobile')
                    ->image()
                    ->maxSize(1024) // 1MB
                    ->helperText('Optional — upload a separately cropped/composed image for mobile widths. Falls back to the Desktop Banner if left empty.')
                    ->columnSpanFull(),
                TextInput::make('image_alt_text')
                    ->label('Image alt text')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Shown publicly to screen readers / image search — the "Title" field above is internal-only.')
                    ->columnSpanFull(),
            ]);
    }
}
