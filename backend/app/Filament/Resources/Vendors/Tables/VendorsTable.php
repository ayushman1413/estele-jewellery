<?php

namespace App\Filament\Resources\Vendors\Tables;

use App\Models\Vendor;
use App\Services\Otp\OtpManager;
use App\Services\Vendors\PanelAccessService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VendorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->paginationMode(PaginationMode::Simple)
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user')->withCount([
                'invitations',
                'invitations as accepted_count' => fn (Builder $q) => $q->where('response_status', 'accepted'),
            ]))
            ->columns([
                TextColumn::make('name')->searchable()->description(fn (Vendor $record) => $record->company_name),
                TextColumn::make('access_role')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state === Vendor::ACCESS_ROLE_ADMIN ? 'Admin' : 'Vendor')
                    ->color(fn (?string $state) => $state === Vendor::ACCESS_ROLE_ADMIN ? 'warning' : 'success'),
                TextColumn::make('login_state')
                    ->label('Login')
                    ->badge()
                    ->state(fn (Vendor $record) => match (true) {
                        blank($record->email) => 'No email',
                        $record->user === null => 'Not invited',
                        filled($record->user->password) => 'Active',
                        default => 'Invited',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Active' => 'success',
                        'Invited' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('mobile')->searchable(),
                TextColumn::make('whatsapp_number')->label('WhatsApp')->searchable()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('email')->searchable()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')->label('Active')->boolean(),
                IconColumn::make('mobile_verified_at')->label('Verified')->boolean()->state(fn (Vendor $record) => (bool) $record->mobile_verified_at),
                TextColumn::make('invitations_count')->label('Invited')->alignCenter(),
                TextColumn::make('accepted_count')->label('Accepted')->alignCenter(),
                TextColumn::make('created_at')->dateTime('d M Y, h:i A')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('access_role')
                    ->label('Role')
                    ->options([
                        Vendor::ACCESS_ROLE_VENDOR => 'Vendor',
                        Vendor::ACCESS_ROLE_ADMIN => 'Admin',
                    ]),
                TernaryFilter::make('is_active'),
                TernaryFilter::make('verified')
                    ->label('Mobile verified')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('mobile_verified_at'),
                        false: fn (Builder $query) => $query->whereNull('mobile_verified_at'),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ActionGroup::make([
                    self::resendSetupLinkAction(),
                    self::sendOtpAction(),
                    self::verifyOtpAction(),
                    DeleteAction::make(),
                ]),
            ]);
    }

    /**
     * Issues a fresh 48-hour setup link. Also the recovery path when the first
     * mail never arrived or the link expired — old tokens stop working as soon
     * as a new one is issued.
     */
    public static function resendSetupLinkAction(): Action
    {
        return Action::make('resend_setup_link')
            ->label('Resend setup link')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('info')
            ->visible(fn (Vendor $record) => filled($record->email))
            ->requiresConfirmation()
            ->modalHeading('Resend password setup link')
            ->modalDescription(fn (Vendor $record) => "A new link will be sent to {$record->email}"
                .(filled($record->whatsapp_number) ? " and WhatsApp {$record->whatsapp_number}" : '')
                .'. Any previous link stops working.')
            ->action(function (Vendor $record) {
                if (app(PanelAccessService::class)->grant($record, isResend: true)) {
                    Notification::make()->title("Setup link sent to {$record->email}")->success()->send();

                    return;
                }

                Notification::make()->title('Could not send the setup link.')->danger()->send();
            });
    }

    public static function sendOtpAction(): Action
    {
        return Action::make('send_otp')
            ->label('Send OTP')
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->color('info')
            ->visible(fn (Vendor $record) => ! $record->mobile_verified_at)
            ->requiresConfirmation()
            ->modalHeading('Send verification OTP')
            ->modalDescription(fn (Vendor $record) => "A 6-digit code will be sent to {$record->mobile}. Ask the vendor for the code, then use “Verify OTP”.")
            ->action(function (Vendor $record) {
                app(OtpManager::class)->issue($record->mobile);

                Notification::make()->title("OTP sent to {$record->mobile}")->success()->send();
            });
    }

    public static function verifyOtpAction(): Action
    {
        return Action::make('verify_otp')
            ->label('Verify OTP')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('success')
            ->visible(fn (Vendor $record) => ! $record->mobile_verified_at)
            ->modalHeading('Verify vendor mobile')
            ->schema([
                TextInput::make('code')
                    ->label('6-digit OTP')
                    ->required()
                    ->numeric()
                    ->length(6)
                    ->autofocus(),
            ])
            ->action(function (array $data, Vendor $record) {
                if (! app(OtpManager::class)->verify($record->mobile, (string) $data['code'])) {
                    Notification::make()->title('Invalid or expired OTP.')->danger()->send();

                    return;
                }

                $record->update(['mobile_verified_at' => now()]);

                Notification::make()->title('Mobile number verified.')->success()->send();
            });
    }
}
