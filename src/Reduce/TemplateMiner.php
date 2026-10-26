<?php

declare(strict_types=1);

namespace Watcher\Reduce;

use Watcher\Source\LogEvent;

/**
 * Template mining (FR-044, spec 004 US3). While the stream is read only distinct masked messages are kept, with
 * their event count and fingerprint, per bucket (level, errorType, token count, first frame). After the stream (on
 * the first template() or stats() call) each bucket is clustered deterministically: messages sorted by (count desc,
 * text asc); each joins the most similar earlier cluster whose leader it matches on at least `$similarity` of the
 * leader's literal tokens (placeholders like `<N>` neither count nor match), else founds a new one. A token position
 * that differs inside a cluster becomes `<*>`. The result depends only on the multiset of messages, never on arrival
 * order. A fingerprint's template is the one of its largest cluster; null (withheld) when that cluster holds fewer
 * than `$minEvents` events, or when any of the fingerprint's messages was dropped (cap) or left unclustered (budget).
 *
 * `$enabled = false` puts every message of a bucket in one cluster: the pre-similarity behaviour (exact token count).
 * Known limit: a value IDENTICAL in every event of a cluster cannot be told from a constant word and stays in it.
 * ponytail: the bucket scan is O(clusters) per message, bounded by `$comparisonBudget`; a tree index if that bites.
 */
final class TemplateMiner
{
    public const DEFAULT_SIMILARITY = 0.5;
    public const DEFAULT_MAX_DISTINCT = 50_000;
    /** Token operations allowed per stored message slot of the cap (one operation = one literal token examined, A4-02). */
    private const BUDGET_PER_MESSAGE = 1000;
    /** Tokens kept per stored message; the rest is replaced by one placeholder, so messages that differ only past it merge. */
    public const MAX_TOKENS = 64;
    public const DEFAULT_MEMORY_BUDGET = 32 * 1024 * 1024;

    private const PLACEHOLDER = '/^<[A-Z*]+>$/';

    /** @var array<string, list<array{tokens: list<string>, text: string, count: int, fp: string}>> by bucket */
    private array $buckets = [];

    /** @var array<string, array<string, int>> bucket => message text => index in $buckets[bucket] */
    private array $index = [];

    /** @var array<string, true> fingerprints that lost a message to the cap */
    private array $droppedFps = [];

    private int $distinct = 0;

    private int $dropped = 0;

    /** Estimated bytes held by stored messages (see add()). */
    private int $bytes = 0;

    private bool $exhausted = false;

    private readonly int $budget;

    private bool $clustered = false;

    /** @var array<string, ?string> */
    private array $templates = [];

    /** @var list<array{template: string, distinct: int}> */
    private array $variable = [];

    public function __construct(
        private readonly int $minEvents,
        private readonly float $similarity = self::DEFAULT_SIMILARITY,
        private readonly int $maxDistinct = self::DEFAULT_MAX_DISTINCT,
        ?int $comparisonBudget = null,
        private readonly bool $enabled = true,
        private readonly int $memoryBudgetBytes = self::DEFAULT_MEMORY_BUDGET,
    ) {
        $this->budget = $comparisonBudget ?? self::BUDGET_PER_MESSAGE * $maxDistinct;
    }

    /** @return list<string> whitespace tokens of the Normalizer-masked message (an unscannable event yields `<UNSCANNABLE>`, counted in `Normalizer::$unscannable`) */
    public static function tokens(Normalizer $normalizer, LogEvent $event): array
    {
        $text = $normalizer->normalizeOrUnscannable($event->message);

        return $text === '' ? [] : explode(' ', $text);
    }

    /** @param list<string> $tokens */
    public function add(LogEvent $event, array $tokens, string $fingerprint): void
    {
        $bucket = implode('|', [$event->level->value, $event->errorType ?? '', count($tokens), $event->frames[0] ?? '']);
        if (count($tokens) > self::MAX_TOKENS) {
            $tokens = [...array_slice($tokens, 0, self::MAX_TOKENS), '<TRUNC>'];
        }
        $text = implode(' ', $tokens);
        if (isset($this->index[$bucket][$text])) {
            $this->buckets[$bucket][$this->index[$bucket][$text]]['count'] += $event->occurrences;

            return;
        }
        // Rough PHP cost of a stored message: the text twice (index key and value), one packed slot and string header per token.
        $cost = strlen($text) * 2 + count($tokens) * 48 + 256;
        if ($this->distinct >= $this->maxDistinct || $this->bytes + $cost > $this->memoryBudgetBytes) {
            ++$this->dropped;
            $this->droppedFps[$fingerprint] = true;

            return;
        }
        $this->bytes += $cost;
        ++$this->distinct;
        $this->index[$bucket][$text] = count($this->buckets[$bucket] ?? []);
        $this->buckets[$bucket][] = ['tokens' => $tokens, 'text' => $text, 'count' => $event->occurrences, 'fp' => $fingerprint];
        $this->clustered = false;
    }

    public function template(string $fingerprint): ?string
    {
        $this->cluster();

        return $this->templates[$fingerprint] ?? null;
    }

    /**
     * Clusters with a varying position (`<*>`) and at least `$minEvents` events: the template and how many distinct masked
     * messages it folds. Counts only (spec 006).
     *
     * @return list<array{template: string, distinct: int}>
     */
    public function variability(): array
    {
        $this->cluster();

        return $this->variable;
    }

    public function stats(): MiningStats
    {
        $this->cluster();

        return new MiningStats($this->distinct, $this->dropped, $this->exhausted);
    }

    private function cluster(): void
    {
        if ($this->clustered) {
            return;
        }
        $this->clustered = true;
        $this->templates = [];
        $this->variable = [];
        $this->exhausted = false;
        $used = 0;
        $withheld = $this->droppedFps;
        $threshold = $this->enabled ? $this->similarity : 0.0;
        $keys = array_keys($this->buckets);
        sort($keys, SORT_STRING);

        foreach ($keys as $key) {
            $messages = $this->buckets[$key];
            usort($messages, static fn (array $a, array $b): int => [$b['count'], $a['text']] <=> [$a['count'], $b['text']]);
            /** @var list<array{tokens: list<string>, lit: list<int>, need: int, varying: array<int, true>, count: int, fps: array<string, int>, msgs: int}> $clusters */
            $clusters = [];
            foreach ($messages as $m) {
                $best = $this->enabled || $clusters === [] ? null : 0;
                if ($best === null) {
                    $bestScore = -1.0;
                    foreach ($clusters as $ci => $c) {
                        if ($used >= $this->budget) {
                            $this->exhausted = true;
                            $withheld[$m['fp']] = true;
                            continue 2;
                        }
                        $used += max(1, count($c['lit']));
                        $score = $this->score($c, $m['tokens']);
                        if ($score > $bestScore) {
                            [$best, $bestScore] = [$ci, $score];
                        }
                    }
                    if ($bestScore < 0.0) {
                        $best = null;
                    }
                }
                if ($best === null) {
                    $lit = array_keys(array_filter($m['tokens'], static fn (string $t): bool => preg_match(self::PLACEHOLDER, $t) !== 1));
                    $clusters[] = ['tokens' => $m['tokens'], 'lit' => $lit, 'need' => (int) ceil($threshold * count($lit) - 1e-9),
                        'varying' => [], 'count' => $m['count'], 'fps' => [$m['fp'] => $m['count']], 'msgs' => 1];
                    continue;
                }
                $c = &$clusters[$best];
                $c['count'] += $m['count'];
                ++$c['msgs'];
                $c['fps'][$m['fp']] = ($c['fps'][$m['fp']] ?? 0) + $m['count'];
                foreach ($m['tokens'] as $i => $t) {
                    if ($t !== $c['tokens'][$i]) {
                        $c['varying'][$i] = true;
                    }
                }
                unset($c);
            }
            $this->collect($clusters, $withheld);
        }
        foreach (array_keys($withheld) as $fp) {
            unset($this->templates[$fp]);
        }
    }

    /**
     * Matches over the leader's literal positions, or -1 when below the threshold. No literal tokens: same shape, 1.
     *
     * @param array{tokens: list<string>, lit: list<int>, need: int} $c
     * @param list<string> $tokens
     */
    private function score(array $c, array $tokens): float
    {
        $lit = count($c['lit']);
        if ($lit === 0) {
            return 1.0;
        }
        $miss = 0;
        $maxMiss = $lit - $c['need'];
        foreach ($c['lit'] as $i) {
            if ($tokens[$i] !== $c['tokens'][$i] && ++$miss > $maxMiss) {
                return -1.0;
            }
        }

        return ($lit - $miss) / $lit;
    }

    /** @param array{tokens: list<string>, varying: array<int, true>} $c */
    private static function render(array $c): string
    {
        $parts = [];
        foreach ($c['tokens'] as $i => $t) {
            $parts[] = isset($c['varying'][$i]) ? '<*>' : $t;
        }

        return implode(' ', $parts);
    }

    /**
     * @param list<array{tokens: list<string>, varying: array<int, true>, count: int, fps: array<string, int>, msgs: int}> $clusters
     * @param array<string, true> $withheld
     */
    private function collect(array $clusters, array $withheld): void
    {
        /** @var array<string, array{n: int, ci: int}> $best */
        $best = [];
        foreach ($clusters as $ci => $c) {
            foreach ($c['fps'] as $fp => $n) {
                if (!isset($best[$fp]) || $n > $best[$fp]['n']) {
                    $best[(string) $fp] = ['n' => $n, 'ci' => $ci];
                }
            }
        }
        foreach ($clusters as $c) {
            if ($c['varying'] !== [] && $c['count'] >= $this->minEvents) {
                $this->variable[] = ['template' => self::render($c), 'distinct' => $c['msgs']];
            }
        }
        foreach ($best as $fp => $b) {
            $c = $clusters[$b['ci']];
            $this->templates[(string) $fp] = $c['count'] >= $this->minEvents ? self::render($c) : null;
        }
    }
}
