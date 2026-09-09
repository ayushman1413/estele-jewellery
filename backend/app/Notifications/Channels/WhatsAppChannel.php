<?php

namespace App\Notifications\Channels;

use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Notifications\Notification;

class WhatsAppChannel
{
    public function __construct(private readonly WhatsAppGateway $gateway) {}

    public function send(mixed $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $to = $notifiable->routeNotificationFor('whatsapp', $notification)
            ?? $notifiable->whatsapp_number
            ?? $notifiable->phone
            ?? null;

        if (! $to) {
            return;
        }

        $message = $notification->toWhatsApp($notifiable);

        $this->gateway->send($to, $message);
    }
}
