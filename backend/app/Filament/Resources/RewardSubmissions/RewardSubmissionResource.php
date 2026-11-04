<?php

namespace App\Filament\Resources\RewardSubmissions;

use App\Filament\Resources\RewardSubmissions\Pages\EditRewardSubmission;
use App\Filament\Resources\RewardSubmissions\Pages\ListRewardSubmissions;
use App\Filament\Resources\RewardSubmissions\Schemas\RewardSubmissionForm;
use App\Filament\Resources\RewardSubmissions\Tables\RewardSubmissionsTable;
use App\Filament\Support\NavigationSeen;
use App\Models\RewardSubmission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RewardSubmissionResource extends Resource
{
    protected static ?string $model = RewardSubmission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|\UnitEnum|null $navigationGroup = 'Content';

    public static function getNavigationBadge(): ?string
    {
        return NavigationSeen::badge('reward-submissions', RewardSubmission::query()->where('status', 'pending'));
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Submissions waiting for review';
    }

    public static function form(Schema $schema): Schema
    {
        return RewardSubmissionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RewardSubmissionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRewardSubmissions::route('/'),
            'edit' => EditRewardSubmission::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
