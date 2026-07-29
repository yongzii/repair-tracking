<?php

namespace App\Jobs;

use App\Models\CustomerNotification;
use App\Services\CustomerNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendCustomerNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 30;
    public int $maxExceptions = 3;

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function __construct(
        private ?string $email,
        private ?string $phone,
        private string $subject,
        private string $message,
        private ?int $notificationId = null
    ) {
        $this->onQueue('notifications');
    }

    public function handle(CustomerNotifier $notifier): void
    {
        $notificationId = isset($this->notificationId) ? $this->notificationId : null;
        $notification = $notificationId
            ? CustomerNotification::find($notificationId)
            : null;

        try {
            if ($notification) {
                if ($notification->channel === CustomerNotification::CHANNEL_EMAIL) {
                    $notifier->sendEmailNotification($notification->recipient, $notification->subject ?? $this->subject, $notification->message);
                } else {
                    $notifier->sendSmsNotification($notification->recipient, $notification->message);
                }

                $notification->update([
                    'status' => CustomerNotification::STATUS_SENT,
                    'sent_at' => now(),
                    'failed_at' => null,
                    'error_message' => null,
                ]);
                return;
            }

            $notifier->notifyContacts($this->email, $this->phone, $this->subject, $this->message);
        } catch (Throwable $e) {
            if ($notification) {
                $notification->update([
                    'status' => CustomerNotification::STATUS_FAILED,
                    'failed_at' => now(),
                    'error_message' => mb_strimwidth($e->getMessage(), 0, 1000, '...'),
                ]);
            }

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $notificationId = isset($this->notificationId) ? $this->notificationId : null;

        if ($notificationId) {
            CustomerNotification::whereKey($notificationId)->update([
                'status' => CustomerNotification::STATUS_FAILED,
                'failed_at' => now(),
                'error_message' => mb_strimwidth($e->getMessage(), 0, 1000, '...'),
            ]);
        }

        Log::error('Notification job failed after retries.', [
            'email' => $this->email,
            'phone' => $this->phone,
            'subject' => $this->subject,
            'error' => $e->getMessage(),
        ]);
    }
}
