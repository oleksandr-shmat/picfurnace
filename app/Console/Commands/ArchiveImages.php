<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Generations\Images\ImageArchive;
use App\Generations\Images\ImageDownloadFailed;
use Illuminate\Console\Command;

class ArchiveImages extends Command
{
    private const int COPIES_PER_RUN = 20;

    protected $signature = 'generations:archive-images';

    protected $description = 'Copy new images from Pixelforge before their links expire and delete copies past the retention period';

    public function handle(ImageArchive $archive): int
    {
        foreach ($archive->waitingForCopy(self::COPIES_PER_RUN) as $generation) {
            try {
                $archive->saveCopy($generation);
            } catch (ImageDownloadFailed $e) {
                // The next run tries again, as long as the Pixelforge link still works.
                report($e);
            }
        }

        $archive->deleteExpiredCopies();

        return self::SUCCESS;
    }
}
