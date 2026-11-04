<?php

namespace App\Notifications;

use App\Filament\Resources\OldJewelleryRequests\OldJewelleryRequestResource;
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
        $request = $this->oldJewelleryRequest;
        $message = (new MailMessage)
            ->subject("New old jewellery request {$request->request_number}")
            ->line("A customer submitted a new old jewellery request: {$request->request_number}.");

        if ($request->status === 'cancelled') {
            $message->line('It was cancelled immediately — there are no active vendors to invite. Activate a vendor and ask the customer to resubmit, or follow up directly.');
        } else {
            $message->line("Bidding closes at {$request->bidding_end_at->format('d M Y, h:i A')}.");
        }

        return $message->action('Review in admin', OldJewelleryRequestResource::getUrl('view', ['record' => $request]));
    }
}
