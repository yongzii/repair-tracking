<?php

namespace App\Jobs;

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
        private string $message
    ) {
        $this->onQueue('notifications');
    }

    public function handle(CustomerNotifier $notifier): void
    {
        $notifier->notifyContacts($this->email, $this->phone, $this->subject, $this->message);
    }

    public function failed(Throwable $e): void
    {
        Log::error('Notification job failed after retries.', [
            'email' => $this->email,
            'phone' => $this->phone,
            'subject' => $this->subject,
            'error' => $e->getMessage(),
        ]);
    }
}
