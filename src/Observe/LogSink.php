<?php

declare(strict_types=1);

namespace Watcher\Observe;

use Throwable;

/** One JSON object per line to a stream (the host's stderr or stdout) for a log shipper to collect; writes nothing else. */
final class LogSink implements EventSink
{
    /** @param resource $stream */
    public function __construct(private $stream)
    {
    }

    public function emit(array $events): void
    {
        try {
            $out = '';
            foreach ($events as $event) {
                $out .= json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
            }
            if ($out !== '' && fwrite($this->stream, $out) !== strlen($out)) {
                throw new SinkFailed('log write failed');
            }
        } catch (Throwable) {
            throw new SinkFailed('log write failed');
        }
    }
}
