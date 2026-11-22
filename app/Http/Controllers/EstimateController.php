<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\Generations\EstimateGenerationRequest;
use App\Money\Usd;
use App\Quoting\Quoter;
use Illuminate\Http\JsonResponse;

class EstimateController extends Controller
{
    /**
     * The price range the user would pay, for the live estimate next to the form.
     */
    public function __invoke(EstimateGenerationRequest $request, Quoter $quoter): JsonResponse
    {
        $quote = $quoter->quote(
            (string) $request->validated('prompt'),
            $request->validated('quality'),
            $request->validated('size'),
            count($request->referenceImageUrls()),
        );

        return response()->json([
            'min' => Usd::format($quote->price->min),
            'expected' => Usd::format($quote->price->expected),
            'max' => Usd::format($quote->price->max),
            'markup_percent' => $quote->markupPercent,
        ]);
    }
}
