<?php

declare(strict_types=1);

namespace App\Generations;

use App\Billing\Billing;
use App\Billing\InsufficientBalance;
use App\Billing\Quoter;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use App\Money\Usd;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Takes a generation from a user: prices it, reserves the money and puts it in our queue.
 * Nothing is sent to Pixelforge here, the dispatcher does that.
 */
final class GenerationSubmitter
{
    public function __construct(
        private readonly Quoter $quoter,
        private readonly Billing $billing,
        private readonly int $maxUnfinishedPerUser,
    ) {}

    /**
     * @param  list<string>  $referenceImageUrls
     *
     * @throws TooManyGenerations when the user already has too many waiting or running.
     * @throws InsufficientBalance when the user cannot afford it.
     */
    public function submit(User $user, string $prompt, string $quality, string $size, array $referenceImageUrls): Generation
    {
        $quote = $this->quoter->quote($prompt, $quality, $size, count($referenceImageUrls));

        return DB::transaction(function () use ($user, $prompt, $quality, $size, $referenceImageUrls, $quote) {
            // Lock the user row, so two simultaneous requests cannot both pass the limit check.
            User::query()->lockForUpdate()->findOrFail($user->id);

            $unfinished = Generation::query()
                ->where('user_id', $user->id)
                ->whereIn('status', [
                    GenerationStatus::Pending->value,
                    GenerationStatus::Queued->value,
                    GenerationStatus::Processing->value,
                ])
                ->count();

            if ($unfinished >= $this->maxUnfinishedPerUser) {
                throw new TooManyGenerations("Only {$this->maxUnfinishedPerUser} generations can wait or run at the same time.");
            }

            $generation = Generation::create([
                'uuid' => Str::uuid()->toString(),
                'user_id' => $user->id,
                'status' => GenerationStatus::Pending,
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_image_urls' => $referenceImageUrls,
                'markup_percent' => $quote->markupPercent,
                'estimated_price_min_nano' => Usd::toInt($quote->price->min),
                'estimated_price_expected_nano' => Usd::toInt($quote->price->expected),
                'estimated_price_max_nano' => Usd::toInt($quote->price->max),
            ]);

            $this->billing->reserve($generation);

            return $generation;
        });
    }
}
