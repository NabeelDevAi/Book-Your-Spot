<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Handles business and spot media uploads on the public disk -- photos and,
 * since the video upload amendment, short clips alongside them.
 *
 * Both relations share the same shape (path, media_type, caption, sort_order),
 * so one service covers them rather than duplicating upload and cleanup logic.
 * Photos and videos are stored in the SAME relation with a `media_type`
 * column rather than a parallel table: they share every cap-and-delete rule
 * they already have, and a gallery renders them in one pass regardless of
 * kind.
 */
class ImageManager
{
    private const DISK = 'public';

    /**
     * Store uploaded image files against a media relation, respecting a cap.
     *
     * @param  array<UploadedFile>  $files
     * @return int  how many were actually stored
     */
    public function store(HasMany $relation, array $files, string $directory, int $max): int
    {
        return $this->storeMedia($relation, $files, $directory, $max, 'image');
    }

    /**
     * Store uploaded video files against a media relation, with their OWN cap
     * -- separate from (and in addition to) the photo cap above.
     *
     * @param  array<UploadedFile>  $files
     * @return int  how many were actually stored
     */
    public function storeVideos(HasMany $relation, array $files, string $directory, int $max): int
    {
        return $this->storeMedia($relation, $files, $directory, $max, 'video');
    }

    /**
     * @param  array<UploadedFile>  $files
     * @return int  how many were actually stored
     */
    private function storeMedia(HasMany $relation, array $files, string $directory, int $max, string $mediaType): int
    {
        $existing = (clone $relation)->where('media_type', $mediaType)->count();
        $remaining = max(0, $max - $existing);

        if ($remaining === 0) {
            return 0;
        }

        // Sort order continues from the current highest across BOTH media
        // types, so a video dropped in after some photos lands after them
        // rather than colliding on position.
        $nextOrder = (int) $relation->max('sort_order') + 1;
        $stored = 0;

        foreach (array_slice($files, 0, $remaining) as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                continue;
            }

            $path = $file->store($directory, self::DISK);

            $relation->create([
                'path' => $path,
                'media_type' => $mediaType,
                'sort_order' => $nextOrder++,
            ]);

            $stored++;
        }

        return $stored;
    }

    /**
     * Delete a media record and the file behind it.
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
        return max(0, $max - (clone $relation)->where('media_type', 'image')->count());
    }

    /** How many more videos may be added before hitting the cap. */
    public function remainingVideos(HasMany $relation, int $max): int
    {
        return max(0, $max - (clone $relation)->where('media_type', 'video')->count());
    }
}
