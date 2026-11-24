<?php

declare(strict_types=1);

/*
 * Submits one generation for a user in a process of its own, for the concurrency tests
 * (tests/Concurrency). The price list is the recorded one, so no request leaves the machine.
 *
 * Usage: php submit_in_child_process.php <user id>
 *
 * Prints {"result": "submitted", "generation": <id>} or {"result": "insufficient"}.
 */

use App\Billing\InsufficientBalance;
use App\Generations\GenerationSubmitter;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

Http::fake(['*/pricing' => Http::response(json_decode(file_get_contents(__DIR__.'/../Fixtures/pixelforge/pricing.json'), true, flags: JSON_THROW_ON_ERROR))]);

$user = User::query()->findOrFail((int) ($argv[1] ?? 0));

try {
    $generation = $app->make(GenerationSubmitter::class)->submit($user, 'a cat', 'low', '1024x1024', []);

    echo json_encode(['result' => 'submitted', 'generation' => $generation->id]);
} catch (InsufficientBalance) {
    echo json_encode(['result' => 'insufficient']);
}
