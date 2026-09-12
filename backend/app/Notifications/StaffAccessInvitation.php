<?php

namespace App\Notifications;

use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;

/**
 * Sent when an admin adds a staff member from the Roles screen, and again on
 * "resend invite". Carries the one-time link the recipient uses to choose
 * their own password — we never generate a password for them, so no
 * credential ever travels over mail.
 *
 * The vendor-facing sibling is PanelAccessInvitation, which additionally
 * mentions bidding and can go out over WhatsApp; staff get mail only.
 */
class StaffAccessInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $setupUrl,
        public readonly string $roleLabel,
        public readonly bool $isResend = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = $this->brandName();

        $subject = $this->isResend
            ? "Your {$brand} password link"
            : "Set up your {$brand} account";

        return (new MailMessage)
            ->subject($subject)
            ->view('emails.staff-access-invitation', [
                'name' => $notifiable->name,
                'setupUrl' => $this->setupUrl,
                'isResend' => $this->isResend,
                'roleLabel' => $this->roleLabel,
                'expiryHours' => 48,
                'brand' => $brand,
            ]);
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
