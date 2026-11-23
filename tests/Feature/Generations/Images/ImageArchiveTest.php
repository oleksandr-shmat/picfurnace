<?php

declare(strict_types=1);

use App\Console\Commands\ArchiveImages;
use App\Generations\Images\ImageArchive;
use App\Generations\Images\ImageDownloadFailed;
use App\Models\Generation;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

mutates(ImageArchive::class, ArchiveImages::class);

const PNG_BYTES = "\x89PNG\r\n\x1a\nnot really a picture";

beforeEach(function () {
    Http::preventStrayRequests();
    Storage::fake('images');
    config(['images.disk' => 'images', 'images.retention_days' => 14]);
    $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', 'UTC'));

    $this->archive = app(ImageArchive::class);
});

/** A succeeded generation whose image is still on Pixelforge. */
function generationWithRemoteImage(array $attributes = []): Generation
{
    return Generation::factory()->succeeded()->create($attributes + [
        'image_url' => 'https://images.pixelforge.test/gen.png?signature=abc',
        'image_expires_at' => now()->addHours(20),
        'completed_at' => now()->subHours(4),
    ]);
}

function remoteImage(): PendingRequest|PromiseInterface
{
    return Http::response(PNG_BYTES, 200, ['Content-Type' => 'image/png']);
}

it('copies the image to our disk', function () {
    Http::fake(['images.pixelforge.test/*' => remoteImage()]);
    $generation = generationWithRemoteImage();

    $this->archive->saveCopy($generation);

    $generation->refresh();
    expect($generation->image_path)->toBe("generations/{$generation->uuid}.png")
        ->and($generation->image_stored_at->equalTo(now()))->toBeTrue();
    Storage::disk('images')->assertExists($generation->image_path);
    expect(Storage::disk('images')->get($generation->image_path))->toBe(PNG_BYTES);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://images.pixelforge.test/gen.png?signature=abc'
        && $request->hasHeader('User-Agent', 'picfurnace/1.0'));
});

it('stores nothing unless it really got an image', function (Closure $answer) {
    Http::fake(['*' => $answer]);
    $generation = generationWithRemoteImage();

    expect(fn () => $this->archive->saveCopy($generation))->toThrow(ImageDownloadFailed::class);

    expect($generation->refresh()->image_path)->toBeNull();
    expect(Storage::disk('images')->allFiles())->toBe([]);
})->with([
    'expired link' => [fn () => Http::response('Forbidden', 403, ['Content-Type' => 'text/plain'])],
    'server error' => [fn () => Http::response('', 500)],
    'a page instead of an image' => [fn () => Http::response('<html>', 200, ['Content-Type' => 'text/html'])],
    'an empty image' => [fn () => Http::response('', 200, ['Content-Type' => 'image/png'])],
    'lost connection' => [function () {
        throw new ConnectionException('timeout');
    }],
]);

it('picks only the images that still need a copy and can still be downloaded', function () {
    $waiting = generationWithRemoteImage();
    generationWithRemoteImage(['image_path' => 'generations/x.png']);
    generationWithRemoteImage(['image_purged_at' => now()]);
    generationWithRemoteImage(['image_expires_at' => now()->subMinute()]);
    Generation::factory()->failed()->create();
    Generation::factory()->succeeded()->create(['image_url' => null]);

    expect($this->archive->waitingForCopy(10)->pluck('id')->all())->toBe([$waiting->id]);
});

it('takes the oldest first and no more than asked', function () {
    $newer = generationWithRemoteImage(['completed_at' => now()->subHours(1)]);
    $older = generationWithRemoteImage(['completed_at' => now()->subHours(5)]);
    generationWithRemoteImage(['completed_at' => now()->subHours(3)]);

    expect($this->archive->waitingForCopy(2)->pluck('id')->first())->toBe($older->id)
        ->and($this->archive->waitingForCopy(2))->toHaveCount(2)
        ->and($this->archive->waitingForCopy(10)->pluck('id')->last())->toBe($newer->id);
});

it('deletes a copy once the retention period is over and keeps the generation', function () {
    Storage::disk('images')->put('generations/old.png', 'x');
    Storage::disk('images')->put('generations/recent.png', 'x');
    $old = Generation::factory()->succeeded()->create(['image_path' => 'generations/old.png', 'completed_at' => now()->subDays(14)]);
    $recent = Generation::factory()->succeeded()->create(['image_path' => 'generations/recent.png', 'completed_at' => now()->subDays(14)->addSecond()]);

    expect($this->archive->deleteExpiredCopies())->toBe(1);

    Storage::disk('images')->assertMissing('generations/old.png');
    Storage::disk('images')->assertExists('generations/recent.png');
    $old->refresh();
    expect($old->image_path)->toBeNull()
        ->and($old->image_purged_at->equalTo(now()))->toBeTrue()
        ->and($old->charged_nano)->not->toBeNull()
        ->and($recent->refresh()->image_path)->not->toBeNull()
        ->and($recent->image_purged_at)->toBeNull();
});

it('tells the user until when the image can be seen', function () {
    $generation = Generation::factory()->succeeded()->create(['completed_at' => CarbonImmutable::parse('2026-11-20 08:30:00', 'UTC')]);

    expect($this->archive->availableUntil($generation)->toIso8601ZuluString())->toBe('2026-12-04T08:30:00Z');
});

it('has no date for a generation without an image to show', function (callable $make) {
    expect($this->archive->availableUntil($make()))->toBeNull();
})->with([
    'failed' => [fn () => Generation::factory()->failed()->create()],
    'still waiting' => [fn () => Generation::factory()->create()],
    'already deleted' => [fn () => Generation::factory()->succeeded()->create(['image_purged_at' => now()])],
]);

it('serves its copy and nothing else', function () {
    Storage::disk('images')->put('generations/a.png', PNG_BYTES);

    expect($this->archive->copyResponse(Generation::factory()->succeeded()->create(['image_path' => 'generations/a.png'])))->not->toBeNull()
        ->and($this->archive->copyResponse(Generation::factory()->succeeded()->create(['image_path' => null])))->toBeNull()
        ->and($this->archive->copyResponse(Generation::factory()->succeeded()->create(['image_path' => 'generations/gone.png'])))->toBeNull();
});

it('copies waiting images and deletes expired ones from the artisan command', function () {
    Http::fake(['images.pixelforge.test/*' => remoteImage()]);
    $waiting = generationWithRemoteImage();
    Storage::disk('images')->put('generations/old.png', 'x');
    $old = Generation::factory()->succeeded()->create(['image_path' => 'generations/old.png', 'completed_at' => now()->subDays(15)]);

    $this->artisan('generations:archive-images')->assertSuccessful();

    expect($waiting->refresh()->image_path)->not->toBeNull()
        ->and($old->refresh()->image_path)->toBeNull();
});

it('keeps going when one image cannot be downloaded, and reports it', function () {
    Exceptions::fake();
    Http::fake([
        'images.pixelforge.test/broken*' => Http::response('', 403),
        'images.pixelforge.test/*' => remoteImage(),
    ]);
    $broken = generationWithRemoteImage(['image_url' => 'https://images.pixelforge.test/broken.png', 'completed_at' => now()->subHours(5)]);
    $fine = generationWithRemoteImage(['completed_at' => now()->subHours(2)]);

    $this->artisan('generations:archive-images')->assertSuccessful();

    Exceptions::assertReported(ImageDownloadFailed::class);
    expect($broken->refresh()->image_path)->toBeNull()
        ->and($fine->refresh()->image_path)->not->toBeNull();
});

it('copies at most twenty images per run', function () {
    Http::fake(['images.pixelforge.test/*' => remoteImage()]);
    Generation::factory()->succeeded()->count(21)->create([
        'image_url' => 'https://images.pixelforge.test/gen.png',
        'image_expires_at' => now()->addHours(20),
        'completed_at' => now()->subHours(4),
    ]);

    $this->artisan('generations:archive-images')->assertSuccessful();

    expect(Generation::whereNotNull('image_path')->count())->toBe(20);
});
