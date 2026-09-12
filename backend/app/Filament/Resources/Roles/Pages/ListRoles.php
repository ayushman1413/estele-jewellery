<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Models\User;
use App\Services\Staff\StaffAccessService;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ListRoles as ShieldListRoles;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class ListRoles extends ShieldListRoles
{
    protected static string $resource = RoleResource::class;

    /**
     * Shield's screen creates roles but has no way to put a person into one,
     * so an admin had no route to adding a colleague short of editing the
     * database. "Add staff member" fills that gap; "New role" is Shield's
     * own action, kept alongside it.
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->addStaffAction(),
            CreateAction::make(),
        ];
    }

    private function addStaffAction(): Action
    {
        return Action::make('add_staff')
            ->label('Add staff member')
            ->icon(Heroicon::OutlinedUserPlus)
            ->modalHeading('Add a staff member')
            ->modalDescription('They receive a link to choose their own password — no password is set or emailed here.')
            ->modalSubmitActionLabel('Send invite')
            ->schema([
                TextInput::make('name')
                    ->label('Full name')
                    ->required()
                    ->maxLength(255),

                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->helperText('This is the address they sign in with. The setup link is valid 48 hours.'),

                Select::make('role')
                    ->label('Role')
                    ->options(fn (): array => app(StaffAccessService::class)->assignableRoles())
                    ->required()
                    ->native(false)
                    ->helperText('What they can see is decided by this role’s permissions, edited on this screen.'),
            ])
            ->action(function (array $data): void {
                $user = app(StaffAccessService::class)->invite(
                    name: $data['name'],
                    email: $data['email'],
                    roleName: $data['role'],
                );

                if ($user instanceof User) {
                    Notification::make()
                        ->title('Invite sent')
                        ->body("{$data['email']} can now set a password and sign in.")
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Could not send the invite')
                    ->body('The account may still have been created — use People on the role to resend the link.')
                    ->danger()
                    ->send();
            });
    }
}
