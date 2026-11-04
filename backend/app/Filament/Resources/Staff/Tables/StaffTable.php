<?php

namespace App\Filament\Resources\Staff\Tables;

use App\Filament\Resources\Staff\StaffResource;
use App\Models\User;
use App\Services\Staff\StaffAccessService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class StaffTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->paginationMode(PaginationMode::Simple)
            ->disabledSelection()
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (User $record) => $record->email),
                TextColumn::make('roles.name')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Str::headline($state))
                    ->color(fn (string $state) => $state === 'super_admin' ? 'warning' : 'info'),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (User $record) => filled($record->password) ? 'Active' : 'Invite pending')
                    ->color(fn (string $state) => $state === 'Active' ? 'success' : 'gray'),
                TextColumn::make('created_at')->label('Added')->date('d M Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => Str::headline($record->name)),
            ])
            ->recordActions([
                self::changeRoleAction(),
                ActionGroup::make([
                    self::resendInviteAction(),
                    self::removeAccessAction(),
                ]),
            ]);
    }

    private static function changeRoleAction(): Action
    {
        return Action::make('change_role')
            ->label('Change role')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->visible(fn (User $record) => StaffResource::canEdit($record) && ! $record->hasRole('super_admin'))
            ->fillForm(fn (User $record) => ['role' => $record->roles->pluck('name')->first()])
            ->schema([
                Select::make('role')
                    ->label('Role')
                    ->options(fn (): array => app(StaffAccessService::class)->assignableRoles())
                    ->required()
                    ->native(false),
            ])
            ->action(function (array $data, User $record): void {
                $record->syncRoles([$data['role']]);

                Notification::make()->title("{$record->name} is now ".Str::headline($data['role']))->success()->send();
            });
    }

    private static function resendInviteAction(): Action
    {
        return Action::make('resend_invite')
            ->label('Resend invite')
            ->icon(Heroicon::OutlinedEnvelope)
            ->visible(fn (User $record) => StaffResource::canEdit($record) && blank($record->password))
            ->requiresConfirmation()
            ->modalDescription(fn (User $record) => "A fresh password link goes to {$record->email}. The old link stops working.")
            ->action(function (User $record): void {
                $sent = app(StaffAccessService::class)->sendSetupLink($record, $record->roles->pluck('name')->first() ?? 'staff', isResend: true);

                $sent
                    ? Notification::make()->title("Invite resent to {$record->email}")->success()->send()
                    : Notification::make()->title('Could not send the invite.')->danger()->send();
            });
    }

    private static function removeAccessAction(): Action
    {
        return Action::make('remove_access')
            ->label('Remove access')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (User $record) => StaffResource::canDelete($record) && $record->id !== auth()->id() && ! $record->hasRole('super_admin'))
            ->requiresConfirmation()
            ->modalHeading(fn (User $record) => "Remove {$record->name}'s panel access?")
            ->modalDescription('They lose every role and their password, so they can no longer sign in. Their history stays.')
            ->action(function (User $record): void {
                $service = app(StaffAccessService::class);

                foreach ($record->roles->pluck('name') as $role) {
                    $service->revokeRole($record, $role);
                }

                Notification::make()->title('Access removed')->success()->send();
            });
    }
}
