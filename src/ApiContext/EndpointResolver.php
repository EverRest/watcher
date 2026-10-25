<?php

declare(strict_types=1);

namespace Watcher\ApiContext;

use Watcher\Privacy\RedactedGroup;
use Watcher\Privacy\Redactor;

/**
 * Selected groups x reduced spec -> endpoint lines, capped per run (FR-006..FR-013). Code only: the model never picks an endpoint.
 * Every part is masked again at use; a part that cannot be scanned is dropped, a template that cannot be scanned drops the line.
 */
final class EndpointResolver
{
    /** Longest `ep` field in bytes (about 25 tokens, SC-003). */
    public const MODEL_BYTES = 75;

    private readonly RouteMatcher $matcher;

    private readonly RouteExtractor $extractor;

    /** @param list<string> $ignorePrefixes */
    public function __construct(private readonly Redactor $redactor, private readonly int $maxEndpoints, array $ignorePrefixes = [])
    {
        $this->matcher = new RouteMatcher($ignorePrefixes);
        $this->extractor = new RouteExtractor();
    }

    /**
     * True when at least one template names a route: only then is the spec worth reading.
     *
     * @param list<RedactedGroup> $groups
     */
    public function anyRoute(array $groups): bool
    {
        foreach ($groups as $g) {
            if ($this->extractor->extract($g->template) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<RedactedGroup> $groups in candidate priority order
     */
    public function resolve(array $groups, ReducedSpec $spec, string $cache = 'none'): EndpointContext
    {
        $lines = [];
        $model = [];
        $taken = []; // "METHOD template" => true: the same endpoint in a second group is free
        $skipped = [];
        foreach ($groups as $g) {
            $route = $this->extractor->extract($g->template);
            $op = $route === null ? null : $this->matcher->match($route, $spec);
            if ($op === null) {
                continue;
            }
            $id = $op->method . ' ' . $op->template;
            if (!isset($taken[$id])) {
                if (count($taken) >= $this->maxEndpoints) {
                    $skipped[$id] = true;
                    continue;
                }
                $taken[$id] = true;
            }
            $line = $this->line($op);
            if ($line !== null) {
                $lines[$g->fingerprint] = $line;
                $field = $this->field($op);
                if ($field !== null) {
                    $model[$g->fingerprint] = $field;
                }
            }
        }

        return new EndpointContext($lines, $model, count($skipped), $spec->dropped, null, false, $cache);
    }

    /** `METHOD template — summary [tag] (deprecated)`; the operationId only stands in for a missing summary. */
    private function line(OperationSummary $op): ?string
    {
        $template = $this->redactor->redactText($op->template);
        if ($template === null || $template === '') {
            return null;
        }
        $text = $this->part($op->summary !== '' ? $op->summary : $op->operationId);
        $tag = $this->part($op->tag);
        $line = $op->method . ' ' . $template;
        if ($text === '' && $tag === '' && !$op->deprecated) {
            return null; // nothing to say about it: no line
        }
        if ($text !== '') {
            $line .= ' — ' . $text;
        }
        if ($tag !== '') {
            $line .= ' [' . $tag . ']';
        }

        return $op->deprecated ? $line . ' (deprecated)' : $line;
    }

    /** `METHOD template — summary`, the summary trimmed to fit MODEL_BYTES; null when there is no summary or method and template alone do not fit. */
    private function field(OperationSummary $op): ?string
    {
        $template = $this->redactor->redactText($op->template);
        $text = $this->part($op->summary !== '' ? $op->summary : $op->operationId);
        $head = $op->method . ' ' . ($template ?? '');
        $room = self::MODEL_BYTES - strlen($head) - strlen(' — ');
        if ($template === null || $text === '' || $room < 4) {
            return null;
        }
        if (strlen($text) > $room) {
            $text = rtrim(mb_strcut($text, 0, $room - strlen('…'))) . '…';
        }

        return $head . ' — ' . $text;
    }

    private function part(string $s): string
    {
        return $s === '' ? '' : ($this->redactor->redactText($s, false) ?? '');
    }
}
