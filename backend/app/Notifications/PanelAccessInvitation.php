<?php

namespace App\Notifications;

use App\Models\Setting;
use App\Models\Vendor;
use App\Notifications\Channels\WhatsAppChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;

/**
 * Sent when an admin adds a panel contact (vendor or admin) and again on
 * "resend invite". Carries the one-time link the recipient uses to choose
 * their own password — we never generate a password for them, so no
 * credential ever travels over mail or WhatsApp.
 */
class PanelAccessInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Vendor $vendor,
        public readonly string $setupUrl,
        public readonly bool $isResend = false,
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

        $subject = $this->isResend
            ? "Your {$brand} password link"
            : "Set up your {$brand} account";

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.panel-access-invitation', [
                'vendor' => $this->vendor,
                'setupUrl' => $this->setupUrl,
                'isResend' => $this->isResend,
                'isVendor' => $this->vendor->receivesBiddingNotifications(),
                'expiryHours' => 48,
                'brand' => $brand,
            ]);
    }

    public function toWhatsApp(object $notifiable): string
    {
        $brand = $this->brandName();

        $intro = $this->isResend
            ? "Here's your new password link for {$brand}"
            : "You have been added to {$brand}";

        return "{$intro}. Set your password here (valid 48 hours): {$this->setupUrl}";
    }

    /**
     * The storefront's own name, not APP_NAME — the env value is a deploy
     * identifier and would put the wrong brand in front of the recipient.
     * Shares SiteDataComposer's cache key so an admin's edit shows up here too.
     */
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
