<?php

declare(strict_types=1);

namespace App\Pixelforge\Api;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A generation as Pixelforge reports it, in an API response or in the `data.object` of a webhook.
 *
 * The webhook object is a snapshot taken when the event happened, so it can be older than what we
 * already know (a late `processing` event arrives after `succeeded`).
 */
final readonly class ProviderGeneration
{
    /** The metadata key under which we send our own generation uuid, and read it back. */
    public const string OUR_ID_METADATA_KEY = 'generation_uuid';

    public function __construct(
        public string $id,
        public ProviderStatus $status,
        public ?string $ourId,
        public Rates $rates,
        public ?Usage $usage,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?CarbonImmutable $completedAt,
    ) {}

    /**
     * Normalisation boundary: turns the untyped generation object into typed values and throws on
     * anything unexpected, including a `succeeded` generation that cannot be billed (no usage).
     *
     * @param  array<string, mixed>  $data  The generation object.
     */
    public static function fromArray(array $data): self
    {
        $rawStatus = $data['status'] ?? null;
        $status = is_string($rawStatus) ? ProviderStatus::tryFrom($rawStatus) : null;

        if ($status === null) {
            throw new InvalidArgumentException('Invalid [status]: expected queued, processing, succeeded or failed.');
        }

        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $error = is_array($data['error'] ?? null) ? $data['error'] : null;

        $generation = new self(
            id: Payload::nonEmptyString($data['id'] ?? null, 'id'),
            status: $status,
            ourId: Payload::nullableString($metadata[self::OUR_ID_METADATA_KEY] ?? null, 'metadata.'.self::OUR_ID_METADATA_KEY),
            rates: Rates::fromArray(Payload::nonEmptyArray($data['rates'] ?? null, 'rates')),
            usage: is_array($data['usage'] ?? null) ? Usage::fromArray($data['usage']) : null,
            imageUrl: Payload::nullableString($data['image_url'] ?? null, 'image_url'),
            imageUrlExpiresAt: Payload::nullableTimestamp($data['image_url_expires_at'] ?? null, 'image_url_expires_at'),
            errorCode: Payload::nullableString($error['code'] ?? null, 'error.code'),
            errorMessage: Payload::nullableString($error['message'] ?? null, 'error.message'),
            completedAt: Payload::nullableTimestamp($data['completed_at'] ?? null, 'completed_at'),
        );

        $generation->assertCarriesItsResult();

        return $generation;
    }

    private function assertCarriesItsResult(): void
    {
        if ($this->status === ProviderStatus::Succeeded && ($this->usage === null || $this->imageUrl === null)) {
            throw new InvalidArgumentException("Generation [{$this->id}] succeeded without usage or an image URL.");
        }

        if ($this->status === ProviderStatus::Failed && $this->errorCode === null) {
            throw new InvalidArgumentException("Generation [{$this->id}] failed without an error code.");
        }
    }
}
