<?php

declare(strict_types=1);

namespace Version;

/**
 * The site's own assets under /css/ and /js/.
 *
 * Asset URLs carry this as a path segment (/css/1.0.0/styles.css) and the
 * directory's .htaccess strips it, so a new version reaches browsers past
 * DreamHost's 30-day cache. Bump it in a bubble's BEGIN commit, or whenever
 * one of these files changes.
 */
final class Site
{
    public const SEMVER = '0.1';
}
