<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Generations\Images\ImageArchive;
use App\Models\Generation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class GenerationImageController extends Controller
{
    /**
     * The image of a generation: our copy if we have one, otherwise the Pixelforge link while it works.
     */
    public function __invoke(Generation $generation, ImageArchive $archive): Response|RedirectResponse
    {
        Gate::authorize('view', $generation);

        $copy = $archive->copyResponse($generation);

        if ($copy !== null) {
            return $copy;
        }

        // Not copied yet (it happens within a minute of completion): the signed link still works.
        if ($generation->image_url !== null && $generation->image_expires_at?->isFuture()) {
            return redirect()->away($generation->image_url);
        }

        abort(404);
    }
}
