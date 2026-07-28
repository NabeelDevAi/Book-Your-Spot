<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Handles business and spot image uploads on the public disk.
 *
 * Both relations share the same shape (path, caption, sort_order), so one
 * service covers them rather than duplicating upload and cleanup logic.
 */
class ImageManager
{
    private const DISK = 'public';

    /**
     * Store uploaded files against a HasMany image relation, respecting a cap.
     *
     * @param  array<UploadedFile>  $files
     * @return int  how many were actually stored
     */
    public function store(HasMany $relation, array $files, string $directory, int $max): int
    {
        $existing = $relation->count();
        $remaining = max(0, $max - $existing);

        if ($remaining === 0) {
            return 0;
        }

        // Sort order continues from the current highest rather than the count,
        // so deleting a middle image doesn't cause two images to share a position.
        $nextOrder = (int) $relation->max('sort_order') + 1;
        $stored = 0;

        foreach (array_slice($files, 0, $remaining) as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            $path = $file->store($directory, self::DISK);

            $relation->create([
                'path' => $path,
                'sort_order' => $nextOrder++,
            ]);

            $stored++;
        }

        return $stored;
    }

    /**
     * Delete an image record and the file behind it.
     *
     * The file is removed after the row, so a failed delete leaves an orphaned
     * file rather than a row pointing at nothing -- a broken image on a listing
     * is worse than a few stray bytes on disk.
     */
    public function delete(Model $image): void
    {
        $path = $image->path;

        $image->delete();

        if ($path && Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** How many more images may be added before hitting the cap. */
    public function remaining(HasMany $relation, int $max): int
    {
        return max(0, $max - $relation->count());
    }
}
