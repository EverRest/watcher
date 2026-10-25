<?php

declare(strict_types=1);

namespace Watcher\ApiContext;

/** Route x reduced spec -> one operation or null (contracts/matching.md). Ties and doubt attach nothing. */
final class RouteMatcher
{
    /** @param list<string> $ignorePrefixes config `api_context.ignore_prefixes`, each stripped at most once, in order */
    public function __construct(private readonly array $ignorePrefixes = [])
    {
    }

    public function match(Route $route, ReducedSpec $spec): ?OperationSummary
    {
        if ($route->unmatchable) {
            return null;
        }
        $segments = $route->segments;
        foreach ([...$this->ignorePrefixes, $spec->base] as $prefix) {
            $segments = self::strip($segments, ReducedSpec::parts($prefix));
        }

        $best = [];
        $bestScore = -1;
        foreach ($spec->bySegments()[count($segments)] ?? [] as [$op, $template]) {
            if ($route->method !== null && $route->method !== $op->method) {
                continue;
            }
            $score = self::score($segments, $template);
            if ($score === null || $score < $bestScore) {
                continue;
            }
            if ($score > $bestScore) {
                $best = [];
                $bestScore = $score;
            }
            $best[] = $op;
        }

        return count($best) === 1 ? $best[0] : null;
    }

    /**
     * @param list<Segment> $segments
     * @param list<string>  $prefix
     *
     * @return list<Segment>
     */
    private static function strip(array $segments, array $prefix): array
    {
        $n = count($prefix);
        if ($n === 0 || count($segments) < $n) {
            return $segments;
        }
        foreach ($prefix as $i => $text) {
            if ($segments[$i]->kind !== Segment::LITERAL || $segments[$i]->text !== $text) {
                return $segments;
            }
        }

        return array_slice($segments, $n);
    }

    /**
     * @param list<Segment> $route
     * @param list<Segment> $template
     *
     * @return int|null the template's literal count when every position agrees, null otherwise
     */
    private static function score(array $route, array $template): ?int
    {
        $literals = 0;
        foreach ($template as $i => $t) {
            $r = $route[$i];
            if ($t->kind === Segment::LITERAL && $r->kind === Segment::LITERAL && $t->text === $r->text) {
                ++$literals;
            } elseif (!($t->kind === Segment::PARAM && $r->kind === Segment::PARAM)) {
                return null;
            }
        }

        return $literals;
    }
}
