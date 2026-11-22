<?php

declare(strict_types=1);

namespace App\Http\Requests\Generations;

use App\Pixelforge\Api\Limits;
use App\Pixelforge\Client\CachedPricing;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fields that decide the price. Used as it is by the live estimate, where the prompt may still
 * be empty; the submit request adds the rule that it is required.
 */
class EstimateGenerationRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(CachedPricing $pricing, Limits $limits): array
    {
        return [
            'prompt' => ['nullable', 'string', 'max:'.$limits->maxPromptCharacters],
            'quality' => ['required', Rule::in($pricing->current()->qualities())],
            'size' => ['required', Rule::in($pricing->current()->sizes())],
            'reference_images' => ['nullable', 'array', 'max:'.$limits->maxReferenceImages],
            'reference_images.*' => ['string', 'url:http,https', 'distinct'],
        ];
    }

    /**
     * @return list<string>
     */
    public function referenceImageUrls(): array
    {
        return array_values($this->validated('reference_images') ?? []);
    }
}
