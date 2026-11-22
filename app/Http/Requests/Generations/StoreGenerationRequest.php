<?php

declare(strict_types=1);

namespace App\Http\Requests\Generations;

use App\Pixelforge\CachedPricing;
use App\Pixelforge\Limits;

class StoreGenerationRequest extends EstimateGenerationRequest
{
    public function rules(CachedPricing $pricing, Limits $limits): array
    {
        return ['prompt' => ['required', 'string', 'max:'.$limits->maxPromptCharacters]]
            + parent::rules($pricing, $limits);
    }
}
