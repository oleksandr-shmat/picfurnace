<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\InsufficientBalance;
use App\Generations\GenerationLifecycle;
use App\Generations\GenerationSubmitter;
use App\Generations\TooManyGenerations;
use App\Http\Requests\Generations\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Pixelforge\Api\Limits;
use App\Pixelforge\Client\CachedPricing;
use App\Pixelforge\Client\RetryLater;
use App\Pixelforge\Client\UnexpectedResponse;
use App\Quoting\Markup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    private const int DEFAULT_LIST_SIZE = 20;

    private const int MAX_LIST_SIZE = 100;

    /**
     * The page: the form to create a generation and the user's own generations below it.
     */
    public function index(Request $request, CachedPricing $pricing, Limits $limits, Markup $markup): Response
    {
        $listSize = min(max((int) $request->query('limit', self::DEFAULT_LIST_SIZE), 1), self::MAX_LIST_SIZE);

        // One more than shown tells whether there are older ones.
        $generations = Generation::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->limit($listSize + 1)
            ->get();

        return Inertia::render('Studio', [
            'generations' => GenerationResource::collection($generations->take($listSize))->resolve(),
            'hasMore' => $generations->count() > $listSize,
            'imageRetentionDays' => config('images.retention_days'),
            // Only needed for the first visit: the polling reloads ask for `generations` alone.
            'options' => fn () => $this->formOptions($pricing, $limits, $markup),
        ]);
    }

    public function store(StoreGenerationRequest $request, GenerationSubmitter $submitter): RedirectResponse
    {
        try {
            $submitter->submit(
                $request->user(),
                $request->validated('prompt'),
                $request->validated('quality'),
                $request->validated('size'),
                $request->referenceImageUrls(),
            );
        } catch (TooManyGenerations|InsufficientBalance $e) {
            throw ValidationException::withMessages(['prompt' => $e->getMessage()]);
        }

        return back();
    }

    /**
     * Cancels a generation that has not been sent to Pixelforge yet.
     */
    public function destroy(Generation $generation, GenerationLifecycle $lifecycle): RedirectResponse
    {
        Gate::authorize('cancel', $generation);

        if (! $lifecycle->cancel($generation)) {
            throw ValidationException::withMessages(['generation' => 'This generation has already been sent and can no longer be cancelled.']);
        }

        return back();
    }

    /**
     * What the form offers, or null when Pixelforge cannot tell us right now (no pricing, no honest estimate).
     *
     * @return array<string, mixed>|null
     */
    private function formOptions(CachedPricing $pricing, Limits $limits, Markup $markup): ?array
    {
        try {
            $current = $pricing->current();
        } catch (RetryLater|UnexpectedResponse $e) {
            report($e);

            return null;
        }

        return [
            'qualities' => $current->qualities(),
            'sizes' => $current->sizes(),
            'defaultQuality' => 'medium',
            'defaultSize' => '1024x1024',
            'maxPromptCharacters' => $limits->maxPromptCharacters,
            'maxReferenceImages' => $limits->maxReferenceImages,
            'maxUnfinishedGenerations' => config('pixelforge.max_unfinished_generations_per_user'),
            'markupPercent' => $markup->percent,
        ];
    }
}
