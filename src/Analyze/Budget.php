<?php

declare(strict_types=1);

namespace Watcher\Analyze;

use Watcher\Context\DependencyContext;
use Watcher\Privacy\RedactedGroup;
use Watcher\Select\Candidate;

final class Budget
{
    /** UTF-8 bytes / 3, rounded up: deliberately conservative. */
    public static function estimateTokens(string $payload): int
    {
        return (int) ceil(strlen($payload) / 3);
    }

    /**
     * The system prompt exactly as the analyzer sends it: the step's prompt plus the service context.
     *
     * @param 'triage'|'analyze' $kind escalation uses the analyze prompt
     */
    public static function systemPrompt(string $kind, string $serviceContext, bool $advice = true, bool $metrics = false): string
    {
        $prompt = (string) file_get_contents(__DIR__ . '/prompts/' . $kind . '.md');
        if ($advice && $kind === 'analyze') {
            $prompt .= "\n" . file_get_contents(__DIR__ . '/prompts/advice.md');
        }

        if ($metrics) {
            $prompt .= file_get_contents(__DIR__ . '/prompts/metrics.md');
        }

        return $prompt . "\n## Service context\n\n" . $serviceContext;
    }

    /**
     * Input sent whatever the groups are: the system prompt with the service context and the data wrapper of the user message.
     *
     * @param 'triage'|'analyze' $kind
     */
    public static function fixedTokens(string $kind, string $serviceContext, bool $advice = true, bool $metrics = false): int
    {
        return self::estimateTokens(self::systemPrompt($kind, $serviceContext, $advice, $metrics)) + self::estimateTokens(CompactPayload::userMessage('', str_repeat('0', 32)));
    }

    /**
     * Drops the lowest-priority candidates (later ones first on ties) until the serialized payload fits.
     *
     * @param list<Candidate> $candidates
     * @param callable(list<Candidate>): string $serialize
     * @param int $fixedTokens input that is sent whatever is kept (system prompt, service context)
     */
    public static function truncate(array $candidates, int $maxInputTokens, callable $serialize, int $fixedTokens = 0): TruncationResult
    {
        $kept = $candidates;
        while ($kept !== [] && self::estimateTokens($serialize($kept)) + $fixedTokens > $maxInputTokens) {
            $drop = 0;
            foreach ($kept as $i => $c) {
                if ($c->priority <= $kept[$drop]->priority) {
                    $drop = $i;
                }
            }
            array_splice($kept, $drop, 1);
        }

        return new TruncationResult($kept, count($candidates) - count($kept));
    }

    /**
     * The metrics section counts toward the analysis input: when it does not fit, the last metrics go first (anomaly metrics are first
     * in the list), then the whole section. Never the candidates.
     *
     * @param list<RedactedGroup> $groups
     * @param list<DependencyContext> $deps
     */
    public static function fitMetrics(array $groups, ?PayloadExtras $m, int $maxInputTokens, string $serviceContext, bool $advice, array $deps = []): ?PayloadExtras
    {
        $n = $m === null ? 0 : count($m->names);
        while ($m !== null && $n > 0) {
            $kept = $m->limit($n);
            if (self::fixedTokens('analyze', $serviceContext, $advice, true) + self::estimateTokens(CompactPayload::encode($groups, $deps, $kept)) <= $maxInputTokens) {
                return $kept;
            }
            --$n;
        }

        return null;
    }

    /**
     * The dependency section counts toward the analysis input: when it does not fit, dependency top errors go first
     * (lowest count), never the candidates.
     *
     * @param list<RedactedGroup>     $groups
     * @param list<DependencyContext> $deps
     * @param int                     $fixedTokens input sent whatever is kept (system prompt, service context)
     *
     * @return list<DependencyContext>
     */
    public static function fitDependencies(array $groups, array $deps, int $maxInputTokens, int $fixedTokens = 0): array
    {
        while ($deps !== [] && self::estimateTokens(CompactPayload::encode($groups, $deps)) + $fixedTokens > $maxInputTokens) {
            $at = null;
            foreach ($deps as $i => $d) {
                foreach ($d->topErrors as $j => $g) {
                    if ($at === null || $g->count <= $deps[$at[0]]->topErrors[$at[1]]->count) {
                        $at = [$i, $j];
                    }
                }
            }
            if ($at === null) {
                break;
            }
            $top = $deps[$at[0]]->topErrors;
            unset($top[$at[1]]);
            array_splice($deps, $at[0], 1, [$deps[$at[0]]->withTopErrors(array_values($top))]); // splice, not assign: keeps $deps a list
        }

        return $deps;
    }
}
