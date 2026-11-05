<?php

namespace App\Services\Messaging;

use App\Models\IntegrationCredential;
use App\Models\Patient;
use App\Models\SmsMessage;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Outbound SMS through Africa's Talking (the common Kenyan bulk SMS API),
 * with a simulator for development. Numbers are normalised to +2547XXXXXXXX.
 * Every message is stored for audit and delivery troubleshooting.
 */
class SmsService
{
    public static function normalise(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return match (true) {
            preg_match('/^254[17]\d{8}$/', $digits) === 1 => '+'.$digits,
            preg_match('/^0[17]\d{8}$/', $digits) === 1 => '+254'.substr($digits, 1),
            preg_match('/^[17]\d{8}$/', $digits) === 1 => '+254'.$digits,
            default => null,
        };
    }

    public function enabled(): bool
    {
        return IntegrationCredential::for('sms') !== null;
    }

    public function send(string $to, string $message, string $purpose, ?Patient $patient = null): SmsMessage
    {
        $number = self::normalise($to);
        $sms = SmsMessage::create([
            'patient_id' => $patient?->id,
            'to' => $number ?? mb_substr($to, 0, 20),
            'message' => mb_substr($message, 0, 640),
            'purpose' => $purpose,
            'status' => 'queued',
        ]);

        $credential = IntegrationCredential::for('sms');

        if (! $number) {
            $sms->update(['status' => 'failed', 'error' => 'Invalid Kenyan mobile number']);

            return $sms;
        }

        if (! $credential) {
            $sms->update(['status' => 'failed', 'error' => 'SMS is not configured']);

            return $sms;
        }

        if ($credential->environment === 'simulator') {
            $sms->update(['status' => 'sent', 'provider_message_id' => 'SIM-'.$sms->id, 'sent_at' => now()]);

            return $sms;
        }

        try {
            $base = $credential->environment === 'production' ? 'https://api.africastalking.com' : 'https://api.sandbox.africastalking.com';
            $response = Http::asForm()->acceptJson()->timeout(20)
                ->withHeaders(['apiKey' => (string) $credential->get('api_key')])
                ->post($base.'/version1/messaging', array_filter([
                    'username' => $credential->get('username'),
                    'to' => $number,
                    'message' => $sms->message,
                    'from' => $credential->get('sender_id'),
                ]));

            $recipient = $response->json('SMSMessageData.Recipients.0');
            $ok = $response->successful() && in_array($recipient['status'] ?? '', ['Success', 'Sent'], true);

            $sms->update($ok
                ? ['status' => 'sent', 'provider_message_id' => $recipient['messageId'] ?? null, 'sent_at' => now()]
                : ['status' => 'failed', 'error' => mb_substr((string) ($recipient['status'] ?? $response->body()), 0, 250)]);
        } catch (Throwable $e) {
            $sms->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 250)]);
        }

        return $sms;
    }
}
