<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Billing\InsufficientBalance;
use App\Generations\GenerationSubmitter;
use App\Generations\TooManyGenerations;
use App\Http\Requests\Generations\StoreGenerationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class GenerationController extends Controller
{
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
}
