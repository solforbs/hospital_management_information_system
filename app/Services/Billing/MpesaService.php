<?php

namespace App\Services\Billing;

use App\Models\IntegrationCredential;
use App\Models\Invoice;
use App\Models\MpesaRequest;
use App\Services\Audit\AuditLogger;
use App\Services\Messaging\SmsService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * M-Pesa Daraja "Lipa na M-Pesa Online" (STK push): the patient gets a PIN
 * prompt on their phone; Safaricom calls back with the result and the
 * payment is posted to the invoice automatically, exactly once per
 * CheckoutRequestID. A simulator completes the payment locally.
 */
class MpesaService
{
    public function __construct(protected BillingService $billing, protected AuditLogger $audit) {}

    public function credential(): ?IntegrationCredential
    {
        return IntegrationCredential::for('mpesa');
    }

    public function stkPush(Invoice $invoice, string $phone, float $amount): MpesaRequest
    {
        $credential = $this->credential();
        if (! $credential) {
            throw ValidationException::withMessages(['mpesa' => __('M-Pesa is not configured for this hospital.')]);
        }

        $msisdn = ltrim((string) SmsService::normalise($phone), '+');
        if ($msisdn === '') {
            throw ValidationException::withMessages(['mpesa_phone' => __('Enter a valid Safaricom number.')]);
        }

        $amount = (float) ceil($amount); // M-Pesa takes whole shillings
        if ($amount < 1 || $amount > (float) ceil((float) $invoice->fresh()->balance)) {
            throw ValidationException::withMessages(['mpesa_amount' => __('Amount must be between 1 and the outstanding balance.')]);
        }

        $request = MpesaRequest::create([
            'invoice_id' => $invoice->id,
            'phone' => $msisdn,
            'amount' => $amount,
            'status' => 'pending',
            'requested_by' => Auth::id(),
        ]);

        if ($credential->environment === 'simulator') {
            $checkout = 'ws_CO_SIM_'.Str::upper(Str::random(12));
            $request->update(['checkout_request_id' => $checkout, 'merchant_request_id' => 'SIM-'.$request->id]);
            // The simulator "customer" accepts immediately.
            $this->handleCallback(['Body' => ['stkCallback' => [
                'MerchantRequestID' => 'SIM-'.$request->id, 'CheckoutRequestID' => $checkout, 'ResultCode' => 0, 'ResultDesc' => 'The service request is processed successfully.',
                'CallbackMetadata' => ['Item' => [['Name' => 'Amount', 'Value' => $amount], ['Name' => 'MpesaReceiptNumber', 'Value' => 'SIM'.Str::upper(Str::random(7))], ['Name' => 'PhoneNumber', 'Value' => $msisdn]]],
            ]]]);

            return $request->fresh();
        }

        $base = $credential->environment === 'production' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
        $shortcode = (string) $credential->get('shortcode');
        $timestamp = now()->setTimezone('Africa/Nairobi')->format('YmdHis');

        $response = Http::withToken($this->token($credential, $base))->acceptJson()->timeout(30)
            ->post($base.'/mpesa/stkpush/v1/processrequest', [
                'BusinessShortCode' => $shortcode,
                'Password' => base64_encode($shortcode.$credential->get('passkey').$timestamp),
                'Timestamp' => $timestamp,
                'TransactionType' => $credential->get('transaction_type', 'CustomerPayBillOnline'),
                'Amount' => (int) $amount,
                'PartyA' => $msisdn,
                'PartyB' => $credential->get('party_b', $shortcode),
                'PhoneNumber' => $msisdn,
                'CallBackURL' => route('hooks.mpesa', [$invoice->tenant->slug, $credential->get('callback_token')]),
                'AccountReference' => mb_substr($invoice->invoice_number, 0, 12),
                'TransactionDesc' => 'Hospital bill',
            ]);

        if (! $response->successful() || (string) $response->json('ResponseCode') !== '0') {
            $request->update(['status' => 'failed', 'result_desc' => mb_substr((string) ($response->json('errorMessage') ?? $response->json('ResponseDescription') ?? $response->body()), 0, 250)]);
            throw ValidationException::withMessages(['mpesa' => __('M-Pesa request failed: :m', ['m' => $request->result_desc])]);
        }

        $request->update(['checkout_request_id' => $response->json('CheckoutRequestID'), 'merchant_request_id' => $response->json('MerchantRequestID')]);
        $this->audit->log('mpesa.stk_push', $invoice, ['amount' => $amount, 'checkout' => $request->checkout_request_id]);

        return $request;
    }

    /** Safaricom STK callback. Idempotent: a CheckoutRequestID is settled once. */
    public function handleCallback(array $payload): ?MpesaRequest
    {
        $cb = data_get($payload, 'Body.stkCallback', []);
        $checkout = $cb['CheckoutRequestID'] ?? null;

        if (! $checkout) {
            return null;
        }

        return DB::transaction(function () use ($cb, $checkout) {
            $request = MpesaRequest::withoutGlobalScope('tenant')->where('checkout_request_id', $checkout)->lockForUpdate()->first();

            if (! $request || $request->status !== 'pending') {
                return $request;
            }

            $code = (string) ($cb['ResultCode'] ?? '');
            $items = collect(data_get($cb, 'CallbackMetadata.Item', []))->pluck('Value', 'Name');

            if ($code !== '0') {
                $request->update(['status' => $code === '1032' ? 'cancelled' : 'failed', 'result_code' => $code, 'result_desc' => mb_substr((string) ($cb['ResultDesc'] ?? ''), 0, 250)]);

                return $request;
            }

            $receipt = (string) ($items['MpesaReceiptNumber'] ?? '');
            $invoice = Invoice::withoutGlobalScope('tenant')->findOrFail($request->invoice_id);
            $amount = min((float) ($items['Amount'] ?? $request->amount), (float) $invoice->balance);

            $payment = $amount > 0 ? $this->billing->receivePayment($invoice, $amount, 'mpesa', $receipt) : null;

            $request->update(['status' => 'success', 'result_code' => '0', 'result_desc' => $cb['ResultDesc'] ?? null, 'mpesa_receipt' => $receipt, 'payment_id' => $payment?->id]);

            return $request;
        });
    }

    protected function token(IntegrationCredential $credential, string $base): string
    {
        return Cache::remember('mpesa-token:'.$credential->tenant_id.':'.$credential->environment, 3300, function () use ($credential, $base) {
            $response = Http::withBasicAuth((string) $credential->get('consumer_key'), (string) $credential->get('consumer_secret'))
                ->timeout(20)->get($base.'/oauth/v1/generate', ['grant_type' => 'client_credentials']);

            $token = $response->json('access_token');
            if (! $token) {
                throw ValidationException::withMessages(['mpesa' => __('M-Pesa rejected the consumer key/secret.')]);
            }

            return $token;
        });
    }
}
