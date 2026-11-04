<?php

namespace App\Services\Hie;

use App\Models\Facility;
use App\Models\HieCredential;
use App\Models\HieRequestLog;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Transport for the DHA Health Information Exchange (AfyaLink middleware).
 *
 * - OAuth2 client-credentials token per tenant, cached and refreshed once on 401.
 * - Multi-tenant HMIS: every request carries X-Facility-Id / X-Facility-Id-Type
 *   for the active facility's Facility Registry code (DHA facility-identification spec).
 * - Reads are retried on connection failure; writes are never retried, and a
 *   write that times out raises HieException(outcomeUnknown: true).
 * - Every call is logged to hie_request_logs with OTPs/secrets/files redacted.
 */
class HieClient
{
    protected const REDACT = ['otp', 'client_secret', 'client_id', 'password', 'access_token', 'token', 'consent_token', 'file_blob'];

    public function __construct(protected TenantContext $context, protected HieSimulator $simulator) {}

    public function credential(): HieCredential
    {
        $credential = HieCredential::first();

        if (! $credential || ! $credential->enabled) {
            throw HieException::notConfigured();
        }

        return $credential;
    }

    public function isEnabled(): bool
    {
        return (bool) HieCredential::where('enabled', true)->exists();
    }

    public function get(string $path, array $query = [], ?Facility $facility = null): array
    {
        return $this->send('GET', $path, $query, $facility);
    }

    public function post(string $path, array $data = [], ?Facility $facility = null): array
    {
        return $this->send('POST', $path, $data, $facility);
    }

    public function patch(string $path, array $data = [], ?Facility $facility = null): array
    {
        return $this->send('PATCH', $path, $data, $facility);
    }

    /**
     * Multipart POST. $files: [partName => [contents, filename]].
     */
    public function postMultipart(string $path, array $fields, array $files = [], ?Facility $facility = null): array
    {
        return $this->send('POST', $path, $fields, $facility, $files, multipart: true);
    }

    /**
     * Obtain a token, proving credentials and connectivity. Returns a short status.
     */
    public function testConnection(HieCredential $credential): string
    {
        $this->forgetToken($credential);
        $this->token($credential);

        return $credential->isSimulator() ? __('Simulator ready (no real HIE call made).') : __('Token issued by HIE.');
    }

    protected function send(string $method, string $path, array $data, ?Facility $facility, array $files = [], bool $isRetry = false, bool $multipart = false): array
    {
        $credential = $this->credential();
        $facility ??= $this->context->facility();
        $started = microtime(true);
        $response = null;
        $error = null;

        try {
            $request = $this->request($credential, $facility);

            if ($method === 'GET') {
                $request = $request->retry(2, 300, fn ($e) => $e instanceof ConnectionException, throw: false);
            }

            if ($files || $multipart) {
                $request = $request->asMultipart();
                foreach ($files as $name => [$contents, $filename]) {
                    $request = $request->attach($name, $contents, $filename);
                }
                $data = collect($data)->map(fn ($v) => is_array($v) ? json_encode($v) : (string) $v)->all();
            }

            $response = match ($method) {
                'GET' => $request->get($path, $data),
                'POST' => $request->post($path, $data),
                'PATCH' => $request->patch($path, $data),
            };
        } catch (ConnectionException $e) {
            $error = $e->getMessage();
            $this->log($credential, $method, $path, $data, null, $started, $error);

            throw new HieException(
                $method === 'GET' ? __('The national HIE could not be reached. Try again shortly.') : __('The HIE did not confirm this request. Check its status before retrying.'),
                outcomeUnknown: $method !== 'GET',
            );
        }

        if ($response->status() === 401 && ! $isRetry) {
            $this->log($credential, $method, $path, $data, $response, $started);
            $this->forgetToken($credential);

            return $this->send($method, $path, $data, $facility, $files, true, $multipart);
        }

        $this->log($credential, $method, $path, $data, $response, $started);

        $body = $response->json() ?? [];

        if ($response->failed()) {
            throw new HieException($this->errorMessage($response, $body), $response->status(), is_array($body) ? $body : []);
        }

        return is_array($body) ? $body : ['data' => $body];
    }

    protected function request(HieCredential $credential, ?Facility $facility): PendingRequest
    {
        $headers = ['Accept' => 'application/json'];

        if ($facility?->fr_code) {
            $headers['X-Facility-Id'] = $facility->fr_code;
            $headers['X-Facility-Id-Type'] = config('hms.hie.facility_id_type');
        }

        return $this->factory($credential)
            ->baseUrl($credential->resolvedBaseUrl())
            ->timeout((int) config('hms.hie.timeout'))
            ->withToken($this->token($credential))
            ->withHeaders($headers);
    }

    protected function factory(HieCredential $credential): Factory|PendingRequest
    {
        if ($credential->isSimulator()) {
            $factory = new Factory;
            $factory->fake(fn ($request) => $this->simulator->handle($request));

            return $factory->asJson();
        }

        return Http::asJson();
    }

    protected function token(HieCredential $credential): string
    {
        return Cache::remember($this->tokenKey($credential), (int) config('hms.hie.token_cache_seconds'), function () use ($credential) {
            if (! $credential->resolvedTokenUrl() || ! $credential->resolvedBaseUrl()) {
                throw new HieException(__('HIE base URL or token URL is missing. Enter the values issued at DHA onboarding.'));
            }

            if (! $credential->isSimulator() && (! $credential->client_id || ! $credential->client_secret)) {
                throw new HieException(__('HIE client ID and secret are required.'));
            }

            try {
                $response = $this->factory($credential)->asForm()->timeout((int) config('hms.hie.timeout'))
                    ->post($credential->resolvedTokenUrl(), [
                        'grant_type' => 'client_credentials',
                        'client_id' => $credential->client_id,
                        'client_secret' => $credential->client_secret,
                    ]);
            } catch (ConnectionException) {
                throw new HieException(__('The HIE token service could not be reached.'));
            }

            $token = $response->json('access_token') ?? $response->json('data.access_token') ?? $response->json('token');

            if ($response->failed() || ! $token) {
                throw new HieException(__('The HIE rejected the client credentials (HTTP :status).', ['status' => $response->status()]), $response->status());
            }

            return $token;
        });
    }

    public function forgetToken(HieCredential $credential): void
    {
        Cache::forget($this->tokenKey($credential));
    }

    protected function tokenKey(HieCredential $credential): string
    {
        return 'hie-token:'.$credential->tenant_id.':'.$credential->environment.':'.$credential->updated_at?->timestamp;
    }

    protected function errorMessage(Response $response, mixed $body): string
    {
        $detail = is_array($body) ? (Arr::get($body, 'message') ?? Arr::get($body, 'error') ?? Arr::get($body, 'detail')) : null;

        if (is_array($detail)) {
            $detail = json_encode($detail);
        }

        return trim(__('HIE request failed (HTTP :status).', ['status' => $response->status()]).' '.($detail ?? ''));
    }

    protected function log(HieCredential $credential, string $method, string $path, array $data, ?Response $response, float $started, ?string $error = null): void
    {
        try {
            $body = $response?->json();

            HieRequestLog::create([
                'tenant_id' => $credential->tenant_id,
                'user_id' => Auth::id(),
                'environment' => $credential->environment,
                'method' => $method,
                'path' => substr($path, 0, 250),
                'status' => $response?->status(),
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'request' => $this->redact($data),
                'response' => is_array($body) ? $this->truncate($this->redact($body)) : null,
                'error' => $error ? substr($error, 0, 250) : null,
            ]);
        } catch (Throwable) {
            // Logging must never break a clinical or billing workflow.
        }
    }

    protected function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACT, true)) {
                $data[$key] = '***';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    protected function truncate(array $body): array
    {
        $json = json_encode($body);

        return strlen((string) $json) > 20000 ? ['truncated' => true, 'preview' => substr($json, 0, 20000)] : $body;
    }
}
