<?php

namespace App\Notifications;

use App\Models\OldJewelleryRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminNewOldJewelleryRequest extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly OldJewelleryRequest $oldJewelleryRequest) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New old jewellery request {$this->oldJewelleryRequest->request_number}")
            ->line("A customer submitted a new old jewellery request: {$this->oldJewelleryRequest->request_number}.")
            ->line("Bidding closes at {$this->oldJewelleryRequest->bidding_end_at->format('d M Y, h:i A')}.")
            ->action('Review in admin', url("/admin/old-jewellery-requests/{$this->oldJewelleryRequest->id}"));
    }
}
