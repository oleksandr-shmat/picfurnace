<?php

declare(strict_types=1);

namespace App\Pixelforge;

/**
 * A webhook event: an id (the same on every delivery of the event), a type and, for the
 * `generation.*` events, the generation as it was when the event happened.
 */
final readonly class ProviderEvent
{
    private const array GENERATION_EVENT_TYPES = ['generation.processing', 'generation.succeeded', 'generation.failed'];

    public function __construct(
        public string $id,
        public string $type,
        public ?ProviderGeneration $generation,
    ) {}

    /**
     * Normalisation boundary: turns the decoded body of a webhook into typed values and throws
     * InvalidArgumentException on anything unexpected. Other event types (`ping`, types added
     * later) are accepted without looking at their data.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $type = Payload::nonEmptyString($payload['type'] ?? null, 'type');

        $generation = null;

        if (in_array($type, self::GENERATION_EVENT_TYPES, true)) {
            $data = Payload::nonEmptyArray($payload['data'] ?? null, 'data');
            $generation = ProviderGeneration::fromArray(Payload::nonEmptyArray($data['object'] ?? null, 'data.object'));
        }

        return new self(Payload::nonEmptyString($payload['id'] ?? null, 'id'), $type, $generation);
    }
}
