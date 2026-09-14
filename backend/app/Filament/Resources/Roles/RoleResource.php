<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Pages\ViewRole;
use App\Filament\Resources\Staff\StaffResource;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as ShieldRoleResource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;
use Spatie\Permission\Models\Role;

/**
 * Publishes filament-shield's own RoleResource (see
 * Utils::isResourcePublished()) purely to route around this local
 * environment's missing ext-intl extension, which
 * Illuminate\Support\Number::format() requires — the same fix already
 * applied to every other table in this codebase (commits 264d23b,
 * 8d6fc72). Two call sites hit it here: the pagination footer (fixed by
 * ->paginationMode(Simple)) and the bulk-selection banner, which renders
 * as soon as any ->toolbarActions() are registered — removing
 * DeleteBulkAction alone doesn't disable selection in Filament v5,
 * ->disabledSelection() does.
 *
 * Roles stay purely about permissions here; putting people into a role is
 * the Staff screen's job. The "People" column just counts holders and links
 * across. Everything else is inherited from the vendor resource unchanged.
 */
class RoleResource extends ShieldRoleResource
{
    #[Override]
    public static function table(Table $table): Table
    {
        // parent::table() is called exactly once and then appended to:
        // ->columns() and ->recordActions() both CLEAR what is already
        // registered before adding (see HasColumns/HasRecordActions), so
        // re-declaring them would drop Shield's own columns and actions.
        return parent::table($table)
            ->paginationMode(PaginationMode::Simple)
            ->disabledSelection()
            ->toolbarActions([])
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('users'))
            ->pushColumns([
                TextColumn::make('users_count')
                    ->label('People')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray')
                    ->url(fn (Role $record): string => StaffResource::getUrl(parameters: ['tableFilters' => ['role' => ['value' => $record->id]]]))
                    ->tooltip('See who holds this role'),
            ]);
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Team';
    }

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
