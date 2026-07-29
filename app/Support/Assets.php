<?php

namespace App\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Asset URLs for the build-less pipeline.
 *
 * There is no bundler. `app.css` and `app.js` are served as written and pull in
 * their partials with native `@import` / ES module imports. That has one
 * consequence worth being explicit about: those partial URLs are hardcoded
 * inside the entry files, so they cannot carry a cache-busting query string of
 * their own.
 *
 * So the stamp here is the newest mtime across the *whole* asset tree, not just
 * the entry file. Editing any partial changes the entry URL, which guarantees
 * the browser re-parses it. The partials themselves stay fresh by revalidation
 * instead of by fingerprinting -- see the Cache-Control rules in
 * public/.htaccess. Fonts are the one immutable case and are cached hard.
 *
 * The directory scan is a few dozen stat calls against warm OS cache, memoised
 * for the life of the request. If that ever shows up in a profile, cache the
 * value and bust it on deploy -- but measure first.
 */
final class Assets
{
    /** Directories whose contents contribute to the cache-busting stamp. */
    private const WATCHED = ['assets/css', 'assets/js'];

    private static ?string $version = null;

    /** A URL for a public asset, stamped so a changed partial can't serve stale. */
    public static function url(string $path): string
    {
        return asset($path).'?v='.self::version();
    }

    public static function version(): string
    {
        return self::$version ??= (string) self::newestModification();
    }

    private static function newestModification(): int
    {
        $newest = 0;

        foreach (self::WATCHED as $directory) {
            $path = public_path($directory);

            if (! is_dir($path)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                $newest = max($newest, $file->getMTime());
            }
        }

        return $newest;
    }
}
