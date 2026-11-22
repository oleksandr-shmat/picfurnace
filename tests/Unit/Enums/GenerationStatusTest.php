<?php

declare(strict_types=1);

use App\Enums\GenerationStatus as S;

mutates(S::class);

it('only moves forward, may skip steps and never leaves a terminal status', function (S $from, S $to, bool $allowed) {
    expect($from->canMoveTo($to))->toBe($allowed);
})->with([
    'pending to queued' => [S::Pending, S::Queued, true],
    'queued to processing' => [S::Queued, S::Processing, true],
    'processing to succeeded' => [S::Processing, S::Succeeded, true],
    'processing to failed' => [S::Processing, S::Failed, true],
    // the "processing" webhook can be lost, and a webhook can beat our POST response
    'queued straight to succeeded' => [S::Queued, S::Succeeded, true],
    'pending straight to succeeded' => [S::Pending, S::Succeeded, true],
    // a late "processing" event after the result
    'succeeded does not go back to processing' => [S::Succeeded, S::Processing, false],
    'failed does not go back to processing' => [S::Failed, S::Processing, false],
    'succeeded does not become failed' => [S::Succeeded, S::Failed, false],
    'failed does not become succeeded' => [S::Failed, S::Succeeded, false],
    'same status is not a move' => [S::Processing, S::Processing, false],
    'processing does not go back to queued' => [S::Processing, S::Queued, false],
]);

it('knows which statuses are final', function () {
    expect(array_map(fn (S $s) => $s->isTerminal(), S::cases()))
        ->toBe([false, false, false, true, true]);
});
