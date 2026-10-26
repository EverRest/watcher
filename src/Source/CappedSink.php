<?php

declare(strict_types=1);

namespace Watcher\Source;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * Response sink that counts the DECODED bytes as the HTTP handler hands them over and aborts the write that would pass the cap,
 * so a chunked body without Content-Length or a gzip bomb is cut while streaming, never buffered whole. Thrown as
 * SourceUnavailable (not a Guzzle exception), so Retry does not repeat it; `$tripped['large']` names the cause to the caller.
 * Bodies over 1 MB spool to a PHP temp file (mode 0600, removed when the stream closes) until the request ends; that is the price of never holding
 * a large reply in memory. Use one per attempt: {@see ResponseLimit::options()} builds a fresh one on every call.
 */
final class CappedSink implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;
    private int $count = 0;

    /** @param \ArrayObject<string, bool> $tripped */
    public function __construct(private readonly int $cap, private readonly \ArrayObject $tripped)
    {
        $this->stream = Utils::streamFor(Utils::tryFopen('php://temp/maxmemory:1048576', 'w+'));
    }

    public function write(string $string): int
    {
        $this->count += strlen($string);
        if ($this->count > $this->cap) {
            $this->tripped->offsetSet('large', true);
            throw new SourceUnavailable('response too large', true);
        }

        return $this->stream->write($string);
    }
}
