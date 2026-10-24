<?php

declare(strict_types=1);

namespace Watcher\Observe;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Fans one run's events out to the enabled sinks, each with its own include_text copy. A sink failure of any kind
 * (also an Error) is caught, logged by class name only and counted; dispatch() never throws (FR-012).
 */
final class SinkDispatcher
{
    /** @var array<string, array{EventSink, bool}> sink name => [sink, include_text], in EventSchema::SINKS order */
    private readonly array $sinks;

    /** @param array<string, array{EventSink, bool}> $sinks */
    public function __construct(array $sinks, private readonly EventSanitizer $sanitizer, private readonly ?LoggerInterface $log = null)
    {
        $ordered = [];
        foreach (EventSchema::SINKS as $name) {
            if (isset($sinks[$name])) {
                $ordered[$name] = $sinks[$name];
            }
        }
        $this->sinks = $ordered;
    }

    /**
     * @param list<Event> $events
     * @param int|null $remaining seconds left of the run deadline: a network sink gets at most that, and none when it is 0.
     *        Local sinks (log, file, prometheus) always write.
     *
     * @return array<string, int> failures per sink name (1 each), empty when all succeeded
     */
    public function dispatch(array $events, ?int $remaining = null): array
    {
        $failures = [];
        if ($events === []) {
            return $failures;
        }
        foreach ($this->sinks as $name => [$sink, $includeText]) {
            try {
                if ($sink instanceof TimeBoundedSink && $remaining !== null) {
                    if ($remaining <= 0) {
                        throw new SinkFailed('run deadline reached');
                    }
                    $sink->within($remaining);
                }
                $sink->emit(array_map(fn (Event $e): array => $this->sanitizer->sanitize($e, $includeText), $events));
            } catch (Throwable $e) {
                $failures[$name] = 1;
                try {
                    $this->log?->warning('observe sink failed: ' . $name . ' (' . $e::class . ')');
                } catch (Throwable) {
                    // a broken logger must not turn a sink failure into a run failure
                }
            }
        }

        return $failures;
    }

    /**
     * The dry-run view: what would be sent, no sink called. Text is shown if any sink would carry it.
     *
     * @param list<Event> $events
     */
    public function render(array $events): string
    {
        $text = in_array(true, array_column($this->sinks, 1), true);
        $out = "== DRY RUN events ==\n";
        foreach ($events as $event) {
            $out .= json_encode($this->sanitizer->sanitize($event, $text), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
        }

        return $out;
    }
}
