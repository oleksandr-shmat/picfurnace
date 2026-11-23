<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Generations\GenerationLifecycle;
use App\Generations\Images\ImageArchive;
use App\Models\Generation;
use App\Money\Usd;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A generation as the user sees it. The cost Pixelforge charges us is left out on purpose: the user
 * sees only the price they are charged.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status->value,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'referenceImages' => $this->reference_image_urls,
            'estimate' => [
                'min' => $this->money($this->estimated_price_min_nano),
                'expected' => $this->money($this->estimated_price_expected_nano),
                'max' => $this->money($this->estimated_price_max_nano),
            ],
            'charged' => $this->charged_nano === null ? null : $this->money($this->charged_nano),
            'failureMessage' => $this->status === GenerationStatus::Failed ? ($this->failure_message ?? 'The generation failed.') : null,
            'cancelled' => $this->failure_code === GenerationLifecycle::CANCELLED_BY_USER,
            'canCancel' => $this->status === GenerationStatus::Pending && $this->claimed_at === null,
            'image' => $this->image(),
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }

    private function money(int $nanodollars): string
    {
        return Usd::format(Usd::nano($nanodollars));
    }

    /**
     * Where to get the image and until when. When the image is gone (deleted after the retention
     * period, or never available) this says so, so the page can explain instead of showing a broken picture.
     *
     * @return array{url: string|null, availableUntil: string|null}|null
     */
    private function image(): ?array
    {
        if ($this->status !== GenerationStatus::Succeeded) {
            return null;
        }

        $hasCopy = $this->image_path !== null;
        $providerLinkWorks = $this->image_url !== null && $this->image_expires_at?->isFuture();

        if (! $hasCopy && ! $providerLinkWorks) {
            return ['url' => null, 'availableUntil' => null];
        }

        return [
            'url' => route('generations.image', $this->uuid),
            'availableUntil' => app(ImageArchive::class)->availableUntil($this->resource)?->toIso8601String(),
        ];
    }
}
