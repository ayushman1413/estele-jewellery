<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\Order;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class OrderForm
{
    public const STATUS_LABELS = [
        'placed' => 'Placed',
        'accepted' => 'Accepted',
        'packed' => 'Packed',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'returned' => 'Returned',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Order')
                    ->columns(3)
                    ->schema([
                        TextInput::make('order_number')
                            ->disabled(),
                        Select::make('status')
                            ->options(function (?Order $record) {
                                if (! $record) {
                                    return self::STATUS_LABELS;
                                }

                                $allowed = [$record->status, ...(Order::ALLOWED_TRANSITIONS[$record->status] ?? [])];

                                return collect(self::STATUS_LABELS)->only($allowed)->all();
                            })
                            ->disabled(fn (?Order $record) => $record && empty(Order::ALLOWED_TRANSITIONS[$record->status] ?? []))
                            ->required()
                            ->live(),
                        TextInput::make('payment_method')
                            ->disabled(),

                        Select::make('payment_status')
                            ->options([
                                'pending' => 'Pending',
                                'paid' => 'Paid',
                                'failed' => 'Failed',
                                'refunded' => 'Refunded',
                                'partially_refunded' => 'Partially Refunded',
                            ])
                            ->helperText('Refunded / partially refunded are set automatically by the Refund action.')
                            ->disabled(fn (?Order $record) => $record && in_array($record->payment_status, ['refunded', 'partially_refunded'], true))
                            ->required(),
                        TextInput::make('payment_reference')
                            ->label('Payment reference')
                            ->visible(fn (?string $state) => filled($state))
                            ->disabled(),
                    ]),

                Section::make('Fulfilment')
                    ->columns(2)
                    ->visible(fn (Get $get) => in_array($get('status'), ['accepted', 'packed', 'shipped', 'delivered', 'returned']))
                    ->schema([
                        TextInput::make('tracking_number')
                            ->maxLength(255)
                            ->required(fn (Get $get) => in_array($get('status'), ['shipped', 'delivered', 'returned'])),
                        TextInput::make('carrier')
                            ->maxLength(255),
                        TextInput::make('tracking_status')
                            ->label('Last known Shiprocket status')
                            ->disabled()
                            ->visible(fn (?Order $record) => filled($record?->shiprocket_awb_code)),
                        TextInput::make('tracking_synced_at')
                            ->label('Tracking last refreshed')
                            ->disabled()
                            ->formatStateUsing(fn (?string $state) => $state ? \Illuminate\Support\Carbon::parse($state)->diffForHumans() : null)
                            ->visible(fn (?Order $record) => filled($record?->shiprocket_awb_code)),
                    ]),

                Section::make('Admin Notes')
                    ->schema([
                        Textarea::make('admin_notes')
                            ->label('')
                            ->helperText('Internal only — never shown to the customer.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Customer')
                    ->columns(3)
                    ->schema([
                        TextInput::make('customer_name')->disabled(),
                        TextInput::make('customer_email')->disabled(),
                        TextInput::make('customer_phone')->disabled(),
                    ]),

                Section::make('Shipping Address')
                    ->columns(2)
                    ->schema([
                        TextInput::make('shipping_address_line1')->label('Address Line 1')->disabled(),
                        TextInput::make('shipping_address_line2')->label('Address Line 2')->disabled(),
                        TextInput::make('shipping_city')->label('City')->disabled(),
                        TextInput::make('shipping_state')->label('State')->disabled(),
                        TextInput::make('shipping_postal_code')->label('Postal Code')->disabled(),
                        TextInput::make('shipping_country')->label('Country')->disabled(),
                    ]),

                Section::make('Order Note')
                    ->visible(fn ($record) => filled($record?->order_note))
                    ->schema([
                        Textarea::make('order_note')->disabled()->columnSpanFull(),
                    ]),

                Section::make('Items')
                    ->schema([
                        Repeater::make('items')
                            ->relationship('items')
                            ->disabled()
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->columns(4)
                            ->schema([
                                TextInput::make('product_title')->label('Product'),
                                TextInput::make('sku')->label('SKU'),
                                TextInput::make('quantity')->numeric(),
                                TextInput::make('subtotal')->numeric()->prefix('₹'),
                            ]),
                    ]),

                Section::make('Totals')
                    ->columns(3)
                    ->schema([
                        TextInput::make('subtotal')->numeric()->prefix('₹')->disabled(),
                        TextInput::make('discount_amount')
                            ->label('Discount')
                            ->numeric()
                            ->prefix('₹')
                            ->disabled()
                            ->visible(fn (?Order $record) => $record && (float) $record->discount_amount > 0),
                        TextInput::make('coupon_code')
                            ->label('Coupon')
                            ->disabled()
                            ->visible(fn (?Order $record) => $record && filled($record->coupon_code)),
                        TextInput::make('shipping_fee')->numeric()->prefix('₹')->disabled(),
                        TextInput::make('total')->numeric()->prefix('₹')->disabled(),
                        TextInput::make('refunded_amount')
                            ->label('Refunded')
                            ->numeric()
                            ->prefix('₹')
                            ->disabled()
                            ->visible(fn (?Order $record) => $record && (float) $record->refunded_amount > 0),
                        Textarea::make('refund_reason')
                            ->label('Refund reason')
                            ->disabled()
                            ->columnSpanFull()
                            ->visible(fn (?Order $record) => $record && (float) $record->refunded_amount > 0),
                    ]),
            ]);
    }
}
