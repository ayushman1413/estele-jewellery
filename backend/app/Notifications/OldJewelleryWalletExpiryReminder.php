<?php

namespace App\Notifications;

use App\Models\OldJewelleryWalletCredit;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OldJewelleryWalletExpiryReminder extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly OldJewelleryWalletCredit $credit) {}

    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if (filled($notifiable->phone)) {
            $channels[] = WhatsAppChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your old jewellery wallet credit is expiring soon')
            ->greeting("Hello {$notifiable->name},")
            ->line("₹{$this->credit->remaining_amount} from request {$this->credit->request->request_number} expires on {$this->credit->expires_at->format('d M Y')}.")
            ->action('Use it now', url('/'));
    }

    public function toWhatsApp(object $notifiable): string
    {
        return "Reminder: ₹{$this->credit->remaining_amount} wallet credit from request {$this->credit->request->request_number} expires on {$this->credit->expires_at->format('d M Y')}. Use it before it expires!";
    }
}
