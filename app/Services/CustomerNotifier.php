<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Twilio\Rest\Client;

class CustomerNotifier
{
    public function sendEmailNotification(string $email, string $subject, string $message): void
    {
        if (!$this->isEmail($email)) {
            throw new \InvalidArgumentException('Recipient is not a valid email address.');
        }

        if (!$this->sendEmail($email, $subject, $message)) {
            throw new \RuntimeException('Email delivery failed.');
        }
    }

    public function sendSmsNotification(string $phone, string $message): void
    {
        if (!$this->isPhone($phone)) {
            throw new \InvalidArgumentException('Recipient is not a valid phone number.');
        }

        if (!$this->sendSms($phone, $message)) {
            throw new \RuntimeException('SMS delivery failed.');
        }
    }

    public function notifyContacts(?string $email, ?string $phone, string $subject, string $message): void
    {
        $attempted = false;
        $sent = false;

        if ($email && $this->isEmail($email)) {
            $attempted = true;
            $sent = $this->sendEmail($email, $subject, $message) || $sent;
        }

        if ($phone && $this->isPhone($phone)) {
            $attempted = true;
            $sent = $this->sendSms($phone, $message) || $sent;
        }

        if (!$attempted) {
            Log::warning('Customer contact is not a valid email or phone number.', [
                'email' => $email,
                'phone' => $phone,
            ]);
            return;
        }

        if (!$sent) {
            throw new \RuntimeException('Notification delivery failed for all channels.');
        }
    }

    private function sendEmail(string $email, string $subject, string $message): bool
    {
        try {
            Mail::raw($message, function ($mail) use ($email, $subject) {
                $mail->to($email)->subject($subject);
            });
            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send email notification.', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function sendSms(string $phone, string $message): bool
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $from = config('services.twilio.from');

        if (!$sid || !$token || !$from) {
            Log::warning('Twilio credentials missing; SMS not sent.', [
                'phone' => $phone,
            ]);
            return false;
        }

        $to = $this->normalizePhone($phone);

        try {
            $client = new Client($sid, $token);
            $client->messages->create($to, [
                'from' => $from,
                'body' => $message,
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send SMS notification.', [
                'phone' => $to,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function isEmail(string $contact): bool
    {
        return (bool) filter_var($contact, FILTER_VALIDATE_EMAIL);
    }

    private function isPhone(string $contact): bool
    {
        return (bool) preg_match('/^\+?[0-9\-\s\(\)]+$/', $contact);
    }

    private function normalizePhone(string $contact): string
    {
        $digits = preg_replace('/\D+/', '', $contact);
        if (!$digits) {
            return $contact;
        }

        if (str_starts_with($contact, '+')) {
            return '+' . $digits;
        }

        $defaultCountry = config('services.twilio.default_country', '+63');
        $countryDigits = ltrim($defaultCountry, '+');

        if (str_starts_with($digits, $countryDigits)) {
            return '+' . $digits;
        }

        if (str_starts_with($digits, '0')) {
            $digits = ltrim($digits, '0');
        }

        return $defaultCountry . $digits;
    }
}
