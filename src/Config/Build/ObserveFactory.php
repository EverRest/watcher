<?php

declare(strict_types=1);

namespace Watcher\Config\Build;

use GuzzleHttp\Client;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Watcher\Config\WatchConfig;
use Watcher\Observe\EventSanitizer;
use Watcher\Observe\FileSink;
use Watcher\Observe\HttpSink;
use Watcher\Observe\LogSink;
use Watcher\Observe\ObserveCounters;
use Watcher\Observe\PrometheusSink;
use Watcher\Observe\SinkDispatcher;
use Watcher\Privacy\Redactor;
use Watcher\Reduce\Normalizer;
use Watcher\Source\Retry;

/** Observation sinks from the `observe` section, as one dispatcher (or none). Used by {@see \Watcher\Config\Factory}, which keeps the public entry points. */
final class ObserveFactory
{
    /**
     * The sinks of the `observe` section as one dispatcher, or null when none is enabled (the package then behaves as before).
     *
     * @param bool $probe for `check --post-test`: leaves out the Prometheus file, which a one-event probe would overwrite
     * @param resource|null $logStream test seam: the stream the log sink writes to instead of stderr/stdout
     * @param bool $stdoutToStderr a `--json` call keeps its stdout one parseable line: a log sink aimed at stdout writes to stderr
     */
    public static function observe(WatchConfig $c, ClockInterface $clock, ?LoggerInterface $log, ObserveCounters $counters, ?Client $http = null, bool $stdoutToStderr = false, $logStream = null, bool $probe = false): ?SinkDispatcher
    {
        $o = $c->observe;
        if (!$o->anyEnabled()) {
            return null;
        }
        $sinks = [];
        if ($o->log['enabled']) {
            $stream = $logStream ?? fopen(self::logTarget($o->log['stream'], $stdoutToStderr), 'w');
            if ($stream !== false) {
                $sinks['log'] = [new LogSink($stream), $o->log['include_text']];
            }
        }
        if ($o->file['enabled']) {
            $f = $o->file;
            $sinks['file'] = [new FileSink($f['path'], $f['rotate'], $f['max_bytes'], $f['max_files'], $f['retention_days'], $clock, $log), $f['include_text']];
        }
        $target = $o->httpTarget();
        if ($o->http['enabled'] && $target !== null) {
            $h = $o->http;
            $sinks['http'] = [new HttpSink($target, $http ?? new Client(self::observeHttpOptions($h['timeout'])), static fn (int $left, callable $sleeper): Retry => new Retry($sleeper, min(15, $left)), $h['batch_size'], $h['timeout'], $h['auth_header'], $h['auth_scheme'], $o->httpToken(), 15), $h['include_text']];
        }
        if ($o->prometheus['enabled'] && !$probe) {
            $p = $o->prometheus;
            $sinks['prometheus'] = [new PrometheusSink($p['dir'], $p['name'], $p['file_mode'], $clock, $counters->snapshot(...)), false];
        }
        $redactor = new Redactor(new Normalizer([], $c->dependencyDomains, extraPatterns: $c->redactionPatterns), $c->redactionPatterns);

        return new SinkDispatcher($sinks, new EventSanitizer($redactor), $log);
    }

    /** Where the log sink writes; a `--json` call keeps stdout for its one summary line. */
    public static function logTarget(string $stream, bool $json): string
    {
        return $stream === 'stdout' && !$json ? 'php://stdout' : 'php://stderr';
    }

    /** @return array{allow_redirects: false, connect_timeout: int} an event endpoint that redirects is an answer, never followed */
    public static function observeHttpOptions(int $timeout): array
    {
        return ['allow_redirects' => false, 'connect_timeout' => $timeout];
    }
}
