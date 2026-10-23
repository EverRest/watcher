<?php

declare(strict_types=1);

namespace Watcher\Reduce;

use Watcher\Source\LogEvent;

/**
 * Template mining (FR-044). Events are clustered by (level, errorType, token count of the masked
 * message, first frame); a token position whose values are not identical across ALL events of a
 * cluster becomes `<*>`. Clusters under `$minEvents` yield null (message withheld). Order-independent.
 *
 * Known limit: a value IDENTICAL in every event of a cluster (e.g. one name seen >= k times) cannot
 * be told from a constant word and stays in the template.
 * ponytail: one entry per cluster, unbounded in the number of distinct clusters; cap/evict if needed.
 */
final class TemplateMiner
{
    /** @var array<string, array{tokens: list<string>, varying: array<int, true>, count: int}> */
    private array $clusters = [];

    public function __construct(private readonly int $minEvents)
    {
    }

    /** @return list<string> whitespace tokens of the Normalizer-masked message (throws NormalizationException) */
    public static function tokens(Normalizer $normalizer, LogEvent $event): array
    {
        $text = $normalizer->normalize($event->message);

        return $text === '' ? [] : explode(' ', $text);
    }

    /** @param list<string> $tokens */
    public function keyOf(LogEvent $event, array $tokens): string
    {
        return implode('|', [$event->level->value, $event->errorType ?? '', count($tokens), $event->frames[0] ?? '']);
    }

    /**
     * @param  list<string> $tokens
     * @return string       the cluster key, for template()
     */
    public function add(LogEvent $event, array $tokens): string
    {
        $key = $this->keyOf($event, $tokens);
        if (!isset($this->clusters[$key])) {
            $this->clusters[$key] = ['tokens' => $tokens, 'varying' => [], 'count' => 1];

            return $key;
        }
        $c = &$this->clusters[$key];
        $c['count']++;
        foreach ($tokens as $i => $t) {
            if ($t !== $c['tokens'][$i]) {
                $c['varying'][$i] = true;
            }
        }

        return $key;
    }

    public function template(string $key): ?string
    {
        $c = $this->clusters[$key] ?? null;
        if ($c === null || $c['count'] < $this->minEvents) {
            return null;
        }
        $out = [];
        foreach ($c['tokens'] as $i => $t) {
            $out[] = isset($c['varying'][$i]) ? '<*>' : $t;
        }

        return implode(' ', $out);
    }
}
