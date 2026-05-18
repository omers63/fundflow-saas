<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\NotificationSettings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class TwilioMessagingService
{
    public function sendSms(string $to, string $body): bool
    {
        $from = NotificationSettings::twilioSmsFrom();

        if ($from === null) {
            return false;
        }

        return $this->sendMessage($to, $from, $body);
    }

    public function sendWhatsApp(string $to, string $body): bool
    {
        $from = NotificationSettings::twilioWhatsAppFrom();

        if ($from === null) {
            return false;
        }

        $normalizedTo = str_starts_with($to, 'whatsapp:') ? $to : 'whatsapp:'.$this->normalizeE164($to);
        $normalizedFrom = str_starts_with($from, 'whatsapp:') ? $from : 'whatsapp:'.$from;

        return $this->sendMessage($normalizedTo, $normalizedFrom, $body);
    }

    private function sendMessage(string $to, string $from, string $body): bool
    {
        if (! NotificationSettings::twilioConfigured()) {
            return false;
        }

        $sid = (string) NotificationSettings::all()['twilio_account_sid'];
        $token = (string) NotificationSettings::all()['twilio_auth_token'];

        try {
            $response = Http::withBasicAuth($sid, $token)
                ->asForm()
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'To' => $this->normalizeE164($to),
                    'From' => $from,
                    'Body' => $body,
                ]);

            if ($response->failed()) {
                Log::warning('Twilio message failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $exception) {
            Log::warning('Twilio message exception', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    private function normalizeE164(string $phone): string
    {
        $phone = trim($phone);

        if (str_starts_with($phone, 'whatsapp:')) {
            return $phone;
        }

        if (str_starts_with($phone, '+')) {
            return $phone;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? $phone;

        if (str_starts_with($digits, '00')) {
            return '+'.substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            return '+966'.ltrim($digits, '0');
        }

        return '+'.$digits;
    }
}
