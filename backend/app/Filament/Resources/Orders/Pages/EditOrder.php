<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use App\Models\Setting;
use App\Services\Shipping\ShiprocketService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invoice')
                ->label('Download Invoice')
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->visible(fn (Order $record) => $record->status !== 'placed')
                ->action(fn (Order $record) => OrdersTable::streamInvoice($record)),

            Action::make('createShipment')
                ->label('Create Shipment')
                ->icon(Heroicon::OutlinedTruck)
                ->color('gray')
                ->visible(fn (Order $record) => self::shiprocketActive()
                    && $record->status === 'packed'
                    && blank($record->shiprocket_shipment_id))
                ->requiresConfirmation()
                ->modalDescription('This books the shipment with Shiprocket using the order\'s current address and items. Do this once the order is physically packed and ready for pickup.')
                ->action(function (Order $record) {
                    try {
                        $result = app(ShiprocketService::class)->createShipment($record);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Could not create the Shiprocket shipment.')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $record->update([
                        'shiprocket_shipment_id' => $result['shipment_id'],
                        'shiprocket_awb_code' => $result['awb_code'],
                        'tracking_number' => $result['awb_code'] ?? $record->tracking_number,
                        'carrier' => $result['courier_name'] ?? $record->carrier,
                    ]);

                    Notification::make()
                        ->title('Shipment created on Shiprocket.')
                        ->success()
                        ->send();
                }),

            Action::make('refreshTracking')
                ->label('Refresh Tracking')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (Order $record) => filled($record->shiprocket_awb_code))
                ->action(function (Order $record) {
                    try {
                        $result = app(ShiprocketService::class)->trackShipment($record->shiprocket_awb_code);
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Could not refresh tracking from Shiprocket.')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $record->update([
                        'tracking_status' => $result['status'],
                        'tracking_synced_at' => now(),
                    ]);

                    Notification::make()
                        ->title('Tracking status: '.($result['status'] ?? 'unknown'))
                        ->success()
                        ->send();
                }),

            // Bookkeeping-only: for payment_method === 'razorpay' this records
            // the refund on the order but never calls Razorpay's Refund API,
            // so money isn't actually returned to the customer's instrument
            // yet — that still has to be done manually via the Razorpay
            // dashboard. Wiring an actual API call here (plus its own
            // refund.processed/refund.failed webhook handling) is a
            // documented follow-up, deferred out of this integration's first
            // slice the same way ShiprocketService::createShipment() was
            // originally left unwired before being closed in a later pass.
            Action::make('refund')
                ->label('Refund')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('danger')
                ->visible(fn (Order $record) => in_array($record->payment_status, ['paid', 'partially_refunded'], true))
                ->schema([
                    TextInput::make('amount')
                        ->label('Refund amount (₹)')
                        ->numeric()
                        ->required()
                        ->minValue(0.01),
                    Textarea::make('reason')
                        ->label('Reason')
                        ->required(),
                ])
                ->action(function (array $data, Order $record) {
                    $alreadyRefunded = (float) ($record->refunded_amount ?? 0);
                    $maxRefundable = (float) $record->total - $alreadyRefunded;
                    $amount = (float) $data['amount'];

                    if ($amount > $maxRefundable) {
                        Notification::make()
                            ->title('Refund amount exceeds the remaining refundable balance (₹'.number_format($maxRefundable, 2).').')
                            ->danger()
                            ->send();

                        return;
                    }

                    $record->applyRefund($amount, $data['reason']);

                    Notification::make()
                        ->title('Refund recorded.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Mirrors the isConfigured() + Setting check ShippingManager uses to
     * decide whether Shiprocket is the live provider — the "Create Shipment"
     * action must only appear when quotes are actually coming from
     * Shiprocket, not from the flat-rate fallback.
     */
    private static function shiprocketActive(): bool
    {
        $settings = Cache::remember('site.settings', 3600, fn () => Setting::pluck('value', 'key')->toArray());

        return ($settings['shipping_provider'] ?? 'flat') === 'shiprocket'
            && app(ShiprocketService::class)->isConfigured();
    }
}
