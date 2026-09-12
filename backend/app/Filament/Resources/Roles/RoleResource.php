<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\Pages\ViewRole;
use App\Models\User;
use App\Services\Staff\StaffAccessService;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as ShieldRoleResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
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
 * Shield's own screen only ever manages roles and permissions — there is no
 * way to put a person into one. The "People" column and its row action add
 * that: who holds this role, and inviting someone new into it. Everything
 * else is inherited from the vendor resource unchanged.
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
                    ->color(fn (int $state): string => $state > 0 ? 'success' : 'gray'),
            ])
            ->pushRecordActions([
                self::manageUsersAction(),
            ]);
    }

    /**
     * Lists who currently holds the role and invites someone new into it.
     *
     * Adding a person never sets a password: they get the same 48-hour setup
     * link the vendor flow uses and choose their own, so no credential travels
     * over mail.
     */
    public static function manageUsersAction(): Action
    {
        return Action::make('manage_users')
            ->label('People')
            ->icon(Heroicon::OutlinedUserGroup)
            ->color('info')
            ->modalHeading(fn (Role $record): string => "People with the {$record->name} role")
            ->modalSubmitActionLabel('Send invite')
            ->fillForm(fn (Role $record): array => [
                'current' => $record->users()
                    ->get(['users.id', 'users.name', 'users.email', 'users.password'])
                    ->map(fn (User $user): string => ($user->name ?: 'Unnamed')
                        .' — '.($user->email ?: 'no email')
                        .(filled($user->password) ? '' : '  (invite pending)'))
                    ->implode("\n") ?: 'Nobody has this role yet.',
            ])
            ->schema([
                Textarea::make('current')
                    ->label('Current members')
                    ->rows(4)
                    ->disabled()
                    ->dehydrated(false),

                TextInput::make('name')
                    ->label('Full name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->helperText('They receive a link to set their own password, valid 48 hours.'),
            ])
            ->action(function (array $data, Role $record): void {
                $user = app(StaffAccessService::class)->invite(
                    name: $data['name'],
                    email: $data['email'],
                    roleName: $record->name,
                );

                if ($user instanceof User) {
                    Notification::make()
                        ->title('Invite sent')
                        ->body("{$data['email']} can set a password and sign in with the {$record->name} role.")
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Could not send the invite')
                    ->body('The account may still have been created — open People again to resend the link.')
                    ->danger()
                    ->send();
            });
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
