<?php

namespace App\Notifications;

use App\Models\OldJewelleryRequest;
use App\Models\Setting;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;

/**
 * Tells a vendor how the bidding they took part in ended — won or lost. Sent
 * once per invited vendor when a request closes, so a bidder never has to
 * poll the panel to find out.
 */
class VendorBidOutcome extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly OldJewelleryRequest $request,
        public readonly bool $won,
        public readonly ?string $amount = null,
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
        $brand = $this->brandName();
        $number = $this->request->request_number;

        $mail = (new MailMessage)
            ->subject($this->won
                ? "You won request {$number}"
                : "Request {$number} has been awarded")
            ->greeting("Hello {$notifiable->name},");

        if ($this->won) {
            return $mail
                ->line("Your bid on request {$number} was the highest and has been accepted.")
                ->line('Winning amount: ₹'.number_format((float) $this->amount, 2))
                ->line("The {$brand} team will be in touch with the next steps.");
        }

        return $mail
            ->line("Bidding on request {$number} has closed and the item went to another bidder.")
            ->line('Thank you for taking part — we will notify you about the next request.');
    }

    public function toWhatsApp(object $notifiable): string
    {
        $number = $this->request->request_number;

        return $this->won
            ? "You won request {$number} at ₹".number_format((float) $this->amount, 2).'. Our team will contact you shortly.'
            : "Request {$number} has closed and was awarded to another bidder.";
    }

    private function brandName(): string
    {
        $settings = Cache::remember(
            'site.settings',
            3600,
            fn () => Setting::pluck('value', 'key')->toArray(),
        );

        return $settings['site_name'] ?? config('app.name');
    }
}
