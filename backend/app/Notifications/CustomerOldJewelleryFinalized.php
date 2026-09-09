<?php

namespace App\Notifications;

use App\Models\OldJewelleryWalletCredit;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerOldJewelleryFinalized extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly OldJewelleryWalletCredit $credit) {}

    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if (filled($notifiable->phone ?? null)) {
            $channels[] = WhatsAppChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->credit->request;

        return (new MailMessage)
            ->subject("Your old jewellery valuation is complete — {$request->request_number}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your old jewellery request {$request->request_number} has been valued at ₹{$this->credit->gross_amount}.")
            ->line("A 10% platform fee of ₹{$this->credit->deduction_amount} was deducted.")
            ->line("₹{$this->credit->credited_amount} has been credited to your wallet.")
            ->line("This credit expires on {$this->credit->expires_at->format('d M Y')}.")
            ->action('Shop now', url('/'));
    }

    public function toWhatsApp(object $notifiable): string
    {
        $request = $this->credit->request;

        return "Your old jewellery request {$request->request_number} was valued at ₹{$this->credit->gross_amount}. ₹{$this->credit->credited_amount} credited to your wallet (expires {$this->credit->expires_at->format('d M Y')}).";
    }
}
