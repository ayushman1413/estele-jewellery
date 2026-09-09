<?php

namespace App\Notifications;

use App\Models\OldJewelleryVendorInvitation;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VendorInvitedToBid extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly OldJewelleryVendorInvitation $invitation,
        public readonly string $plaintextToken,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['mail'];

        if (filled($notifiable->whatsapp_number ?? null)) {
            $channels[] = WhatsAppChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->invitation->request;

        return (new MailMessage)
            ->subject("New old jewellery request {$request->request_number} — respond within 3 hours")
            ->greeting("Hello {$this->invitation->vendor->name},")
            ->line("A customer has submitted an old jewellery item for valuation (request {$request->request_number}).")
            ->line("You have until {$request->bidding_end_at->format('d M Y, h:i A')} to respond.")
            ->action('View request and respond', $this->responseUrl())
            ->line('If you do not respond before the deadline, this request will be closed to you.');
    }

    public function toWhatsApp(object $notifiable): string
    {
        $request = $this->invitation->request;

        return "New old jewellery request {$request->request_number}. Respond by {$request->bidding_end_at->format('d M Y, h:i A')}: {$this->responseUrl()}";
    }

    private function responseUrl(): string
    {
        return rtrim(config('app.url'), '/')."/api/v1/vendor/old-jewellery/{$this->plaintextToken}";
    }
}
