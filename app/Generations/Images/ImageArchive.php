<?php

declare(strict_types=1);

namespace App\Generations\Images;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\Client\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Our own copies of generated images.
 *
 * Pixelforge links stop working 24 hours after completion. A copy is made while the link still
 * works and deleted after the retention period; the generation itself (cost, history) stays.
 */
final class ImageArchive
{
    private const int DOWNLOAD_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly string $disk,
        private readonly int $retentionDays,
    ) {}

    /**
     * Succeeded generations whose image has not been copied yet and can still be downloaded.
     *
     * @return Collection<int, Generation>
     */
    public function waitingForCopy(int $limit): Collection
    {
        return Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            ->whereNotNull('image_url')
            ->whereNull('image_path')
            ->whereNull('image_purged_at')
            ->where('image_expires_at', '>', now())
            ->orderBy('completed_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @throws ImageDownloadFailed
     */
    public function saveCopy(Generation $generation): void
    {
        try {
            $response = Http::withUserAgent(PixelforgeClient::USER_AGENT)
                ->timeout(self::DOWNLOAD_TIMEOUT_SECONDS)
                ->get((string) $generation->image_url);
        } catch (ConnectionException $e) {
            throw new ImageDownloadFailed("Could not reach the image of generation [{$generation->uuid}].", previous: $e);
        }

        $isImage = str_starts_with((string) $response->header('Content-Type'), 'image/');

        if (! $response->successful() || ! $isImage || $response->body() === '') {
            throw new ImageDownloadFailed("Image of generation [{$generation->uuid}] answered HTTP {$response->status()} without an image.");
        }

        $path = "generations/{$generation->uuid}.png";
        Storage::disk($this->disk)->put($path, $response->body());

        $generation->update(['image_path' => $path, 'image_stored_at' => now()]);
    }

    /**
     * Deletes the copies that are past the retention period. Returns how many were deleted.
     */
    public function deleteExpiredCopies(): int
    {
        $expired = Generation::query()
            ->whereNotNull('image_path')
            ->where('completed_at', '<=', now()->subDays($this->retentionDays))
            ->get();

        foreach ($expired as $generation) {
            Storage::disk($this->disk)->delete($generation->image_path);

            $generation->update(['image_path' => null, 'image_purged_at' => now()]);
        }

        return $expired->count();
    }

    /**
     * Until when the user can see the image, or null when there is no image to show.
     */
    public function availableUntil(Generation $generation): ?CarbonImmutable
    {
        if ($generation->status !== GenerationStatus::Succeeded || $generation->image_purged_at !== null) {
            return null;
        }

        return CarbonImmutable::instance($generation->completed_at)->addDays($this->retentionDays);
    }

    /**
     * The copy as a response for the user, or null when we have none (not copied yet, or deleted).
     */
    public function copyResponse(Generation $generation): ?StreamedResponse
    {
        if ($generation->image_path === null || ! Storage::disk($this->disk)->exists($generation->image_path)) {
            return null;
        }

        return Storage::disk($this->disk)->response($generation->image_path, "{$generation->uuid}.png");
    }
}
