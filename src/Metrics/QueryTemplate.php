<?php

declare(strict_types=1);

namespace Watcher\Metrics;

use Watcher\Config\InvalidConfig;
use Watcher\Schedule\Window;

/**
 * A metric query with `{placeholder}`s, checked once at config load. Principle I/VI: values come from config or the
 * window only and match a strict set, so no quote or brace can be injected; error text carries key paths, never a
 * value or the query. The placeholder pattern needs a bare lower-case name, so PromQL selectors (`{job="x"}`, `{}`)
 * never match. Replacement is one pass, so a substituted value is never scanned again.
 */
final class QueryTemplate
{
    public const MAX_LENGTH = 2000;
    private const VALUE = '/^[A-Za-z0-9_.:\/\-]{1,64}$/D';
    private const PLACEHOLDER = '/\{([a-z][a-z0-9_]*)\}/';
    private const BUILT_IN = ['service', 'window', 'start', 'end', 'baseline_start', 'baseline_end'];

    /** @param array<string, string> $fixed placeholder => validated literal (service and vars) */
    private function __construct(private readonly string $text, private readonly array $fixed)
    {
    }

    /**
     * @param array<string, string> $vars
     *
     * @throws InvalidConfig
     */
    public static function compile(string $name, string $text, string $service, array $vars): self
    {
        if (preg_match(self::VALUE, $service) !== 1) {
            throw new InvalidConfig('service.name has characters not allowed in a metrics query value');
        }
        foreach ($vars as $key => $value) {
            if (in_array((string) $key, self::BUILT_IN, true)) {
                throw new InvalidConfig("metrics.vars.{$key} shadows a built-in placeholder");
            }
            if (preg_match(self::VALUE, (string) $value) !== 1) {
                throw new InvalidConfig("metrics.vars.{$key} has characters not allowed in a query value");
            }
        }
        if (strlen($text) > self::MAX_LENGTH) {
            throw new InvalidConfig("metrics.queries.{$name}.query is longer than " . self::MAX_LENGTH . ' characters');
        }
        $fixed = ['service' => $service] + array_map('strval', $vars);
        preg_match_all(self::PLACEHOLDER, $text, $found);
        foreach ($found[1] as $placeholder) {
            if (!isset($fixed[$placeholder]) && !in_array($placeholder, self::BUILT_IN, true)) {
                throw new InvalidConfig("metrics.queries.{$name}.query uses unknown placeholder {{$placeholder}}");
            }
        }

        return new self($text, $fixed);
    }

    public function render(Window $window, Window $baseline): string
    {
        $values = $this->fixed + [
            'window' => ($window->end->getTimestamp() - $window->start->getTimestamp()) . 's',
            'start' => (string) $window->start->getTimestamp(),
            'end' => (string) $window->end->getTimestamp(),
            'baseline_start' => (string) $baseline->start->getTimestamp(),
            'baseline_end' => (string) $baseline->end->getTimestamp(),
        ];

        return (string) preg_replace_callback(self::PLACEHOLDER, static fn (array $m): string => $values[$m[1]], $this->text);
    }
}
