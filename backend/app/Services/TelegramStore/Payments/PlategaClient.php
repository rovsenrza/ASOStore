<?php

namespace App\Services\TelegramStore\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Platega API (https://docs.platega.io): JSON over HTTPS, authenticated by the
 * X-MerchantId and X-Secret headers. The same pair comes back on every callback.
 */
class PlategaClient
{
    public static function configured(): bool
    {
        return filled(config('services.platega.merchant_id')) && filled(config('services.platega.secret'));
    }

    /** A callback is genuine when it carries our own merchant ID and secret. */
    public function authentic(?string $merchantId, ?string $secret): bool
    {
        return self::configured()
            && is_string($merchantId) && is_string($secret)
            && hash_equals((string) config('services.platega.merchant_id'), trim($merchantId))
            && hash_equals((string) config('services.platega.secret'), trim($secret));
    }

    /**
     * Creates a transaction and returns its payment page.
     *
     * @param  array<string, mixed>  $body  CreateTransactionRequest without paymentMethod
     * @return array{id: string, url: string, expires_in: int}
     */
    public function create(array $body): array
    {
        // Without a method the payer chooses one on Platega's page (v2 endpoint).
        $method = config('services.platega.payment_method');
        $path = $method !== null ? '/transaction/process' : '/v2/transaction/process';
        if ($method !== null) {
            $body = ['paymentMethod' => (int) $method] + $body;
        }

        $data = $this->send(fn (PendingRequest $http) => $http->post($path, $body));
        $id = $data['transactionId'] ?? null;
        $url = $data['url'] ?? $data['redirect'] ?? null;
        if (! is_string($id) || $id === '' || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new PlategaException('Platega returned no transaction or payment URL.');
        }

        return ['id' => $id, 'url' => $url, 'expires_in' => self::seconds($data['expiresIn'] ?? null)];
    }

    /**
     * The transaction as Platega has it now; callbacks are only a hint to ask.
     *
     * @return array{status: string, amount: float, currency: string, payload: string|null}
     */
    public function transaction(string $id): array
    {
        $data = $this->send(fn (PendingRequest $http) => $http->get('/transaction/'.rawurlencode($id)));
        $status = $data['status'] ?? null;
        $amount = $data['paymentDetails']['amount'] ?? null;
        if (! is_string($status) || ! is_numeric($amount)) {
            throw new PlategaException('Platega returned a transaction without status or amount.');
        }

        return [
            'status' => strtoupper($status),
            'amount' => (float) $amount,
            'currency' => strtoupper((string) ($data['paymentDetails']['currency'] ?? '')),
            'payload' => is_string($data['payload'] ?? null) ? $data['payload'] : null,
        ];
    }

    /** "00:15:00" → 900; the documented default when missing or unreadable. */
    public static function seconds(mixed $expiresIn): int
    {
        if (is_string($expiresIn) && preg_match('/^(?:(\d+)\.)?(\d{1,2}):(\d{2}):(\d{2})/', $expiresIn, $m) === 1) {
            return (int) $m[1] * 86400 + (int) $m[2] * 3600 + (int) $m[3] * 60 + (int) $m[4];
        }

        return 900;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @return array<string, mixed>
     */
    private function send(callable $call): array
    {
        if (! self::configured()) {
            throw new PlategaException('Platega is not configured.');
        }

        $http = Http::baseUrl(rtrim((string) config('services.platega.base_url'), '/'))
            ->withHeaders([
                'X-MerchantId' => (string) config('services.platega.merchant_id'),
                'X-Secret' => (string) config('services.platega.secret'),
            ])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(10)
            ->timeout(20);

        try {
            $data = $call($http)->throw()->json();
        } catch (RequestException $exception) {
            $status = $exception->response->status();

            // The status code travels as the exception code: 404 means Platega has no such transaction.
            throw new PlategaException('Platega answered HTTP '.$status.': '.mb_substr($exception->response->body(), 0, 300), $status, $exception);
        } catch (ConnectionException $exception) {
            throw new PlategaException('Platega is unreachable: '.$exception->getMessage(), previous: $exception);
        }

        if (! is_array($data)) {
            throw new PlategaException('Platega answered with something other than JSON.');
        }

        return $data;
    }
}
