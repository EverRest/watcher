<?php

declare(strict_types=1);

namespace Watcher\ApiContext;

/** One path segment of a route (literal or param) or of a template (also mixed: text plus braces, never matches). */
final class Segment
{
    public const LITERAL = 'literal';
    public const PARAM = 'param';
    public const MIXED = 'mixed';

    public function __construct(public readonly string $kind, public readonly string $text)
    {
    }

    /** `{id}` alone is a param, any other brace makes the segment mixed. */
    public static function fromTemplate(string $part): self
    {
        if (preg_match('/^\{[^{}\/]+\}$/', $part) === 1) {
            return new self(self::PARAM, $part);
        }

        return new self(strpbrk($part, '{}') === false ? self::LITERAL : self::MIXED, $part);
    }
}
