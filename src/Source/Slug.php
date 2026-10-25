<?php

declare(strict_types=1);

namespace Watcher\Source;

/** A path segment taken from config (datasource uid, organisation, project): rawurlencode leaves `.`, so `..` is refused here, before it can climb out of its place in the URL. */
final class Slug
{
    public static function valid(string $s): bool
    {
        return preg_match('/\A[A-Za-z0-9._-]{1,100}\z/', $s) === 1 && trim($s, '.') !== '';
    }
}
