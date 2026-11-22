<?php

declare(strict_types=1);

namespace App\Pixelforge\Api;

use InvalidArgumentException;

/**
 * Token usage reported by Pixelforge for a finished generation.
 */
final readonly class Usage
{
    public function __construct(
        public int $inputText,
        public int $inputImage,
        public int $output,
        public int $reasoning,
    ) {
        foreach ([$inputText, $inputImage, $output, $reasoning] as $tokens) {
            if ($tokens < 0) {
                throw new InvalidArgumentException('Token counts cannot be negative.');
            }
        }
    }

    /**
     * Normalisation boundary: turns the untyped `usage` object of the API into integers and
     * throws on anything unexpected.
     *
     * @param  array<string, mixed>  $usage  The `usage` object of a generation.
     */
    public static function fromArray(array $usage): self
    {
        return new self(
            self::tokens($usage, 'input_text_tokens'),
            self::tokens($usage, 'input_image_tokens'),
            self::tokens($usage, 'output_tokens'),
            self::tokens($usage, 'reasoning_tokens'),
        );
    }

    /**
     * The token counts in the format of the API, as stored with a generation.
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputText,
            'input_image_tokens' => $this->inputImage,
            'output_tokens' => $this->output,
            'reasoning_tokens' => $this->reasoning,
        ];
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private static function tokens(array $usage, string $key): int
    {
        if (! isset($usage[$key]) || ! is_int($usage[$key])) {
            throw new InvalidArgumentException("Usage is missing an integer [{$key}].");
        }

        return $usage[$key];
    }
}
