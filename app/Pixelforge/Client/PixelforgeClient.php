<?php

declare(strict_types=1);

namespace App\Pixelforge\Client;

use App\Models\Generation;
use App\Pixelforge\Api\Pricing;
use App\Pixelforge\Api\ProviderGeneration;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * HTTP client for the Pixelforge API.
 *
 * It sends requests and turns responses into typed objects or into exceptions that say what to do
 * next (RetryLater, RequestRejected, UnexpectedResponse). It never retries or sleeps itself: when
 * and how often to retry is decided by our own dispatch queue.
 */
final class PixelforgeClient
{
    /** Also used for downloading images: the same Cloudflare proxy sits in front of them. */
    public const string USER_AGENT = 'image-studio/1.0';

    private const int TIMEOUT_SECONDS = 15;

    /** Wait used when Pixelforge gives no usable Retry-After header. */
    private const int DEFAULT_RETRY_AFTER_SECONDS = 5;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {}

    /**
     * Needs no API key.
     */
    public function pricing(): Pricing
    {
        return Pricing::fromArray($this->pricingPayload());
    }

    /**
     * The pricing response as received, checked to be readable. Plain arrays are what can safely be
     * cached: caches do not restore objects of our own classes.
     *
     * @return array<string, mixed>
     */
    public function pricingPayload(): array
    {
        $data = $this->send(fn (PendingRequest $http) => $http->get('/pricing'), authenticated: false);

        try {
            Pricing::fromArray($data);
        } catch (InvalidArgumentException $e) {
            throw new UnexpectedResponse('Unreadable pricing response: '.$e->getMessage(), previous: $e);
        }

        return $data;
    }

    /**
     * Our generation uuid is the Idempotency-Key, so repeating this call after a timeout returns
     * the same Pixelforge generation instead of creating (and billing) a second one. The uuid also
     * goes into `metadata`, which is how webhooks are matched back to our row.
     */
    public function createGeneration(Generation $generation): ProviderGeneration
    {
        $body = [
            'prompt' => $generation->prompt,
            'size' => $generation->size,
            'quality' => $generation->quality,
            'metadata' => [ProviderGeneration::OUR_ID_METADATA_KEY => $generation->uuid],
        ];

        if ($generation->reference_image_urls !== []) {
            $body['reference_images'] = $generation->reference_image_urls;
        }

        $data = $this->send(
            fn (PendingRequest $http) => $http->withHeaders(['Idempotency-Key' => $generation->uuid])->post('/generations', $body),
        );

        return $this->generationFrom($data);
    }

    public function findGeneration(string $providerId): ProviderGeneration
    {
        $data = $this->send(fn (PendingRequest $http) => $http->get('/generations/'.rawurlencode($providerId)));

        return $this->generationFrom($data);
    }

    /**
     * @param  Closure(PendingRequest): Response  $makeRequest
     * @return array<string, mixed> The decoded JSON body of a successful response.
     */
    private function send(Closure $makeRequest, bool $authenticated = true): array
    {
        $http = Http::baseUrl($this->baseUrl)
            ->acceptJson()
            ->withUserAgent(self::USER_AGENT)
            ->timeout(self::TIMEOUT_SECONDS);

        if ($authenticated) {
            $http = $http->withToken($this->apiKey);
        }

        try {
            $response = $makeRequest($http);
        } catch (ConnectionException $e) {
            throw new RetryLater(self::DEFAULT_RETRY_AFTER_SECONDS, 'connection_failed', $e->getMessage(), nothingWasCreated: false);
        }

        if ($response->successful()) {
            return $this->jsonObject($response);
        }

        throw $this->exceptionFor($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonObject(Response $response): array
    {
        $data = $response->json();

        if (! is_array($data)) {
            throw new UnexpectedResponse("Pixelforge answered HTTP {$response->status()} with a body that is not a JSON object.");
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function generationFrom(array $data): ProviderGeneration
    {
        try {
            return ProviderGeneration::fromArray($data);
        } catch (InvalidArgumentException $e) {
            throw new UnexpectedResponse('Unreadable generation response: '.$e->getMessage(), previous: $e);
        }
    }

    private function exceptionFor(Response $response): RetryLater|RequestRejected|UnexpectedResponse
    {
        $status = $response->status();
        $error = $response->json('error');
        $code = is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : 'unknown';
        $message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : "HTTP {$status}";

        // 429 (rate, concurrency or daily limit) and 503 (overloaded) are rejections: nothing was created.
        if ($status === 429 || $status === 503) {
            return new RetryLater($this->retryAfterSeconds($response), $code, $message, nothingWasCreated: true);
        }

        // Any other 5xx, such as a bad gateway page from the proxy: we cannot tell what happened.
        if ($status >= 500) {
            return new RetryLater($this->retryAfterSeconds($response), $code, $message, nothingWasCreated: false);
        }

        // 400 (blocked prompt) and 422 (invalid parameter): this request will never be accepted.
        if ($status === 400 || $status === 422) {
            return new RequestRejected($code, $message);
        }

        return new UnexpectedResponse("Pixelforge answered HTTP {$status} {$code}: {$message}");
    }

    private function retryAfterSeconds(Response $response): int
    {
        $header = $response->header('Retry-After');

        // The header may also hold an HTTP date; only a plain number of seconds is used.
        $seconds = ctype_digit($header) ? (int) $header : 0;

        return $seconds > 0 ? $seconds : self::DEFAULT_RETRY_AFTER_SECONDS;
    }
}
