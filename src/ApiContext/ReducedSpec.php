<?php

declare(strict_types=1);

namespace Watcher\ApiContext;

/** The reduced, masked document: operations plus base path. This is what the cache holds (data-model.md). */
final class ReducedSpec
{
    public const VERSION = 1;
    private const METHODS = ['GET', 'PUT', 'POST', 'DELETE', 'OPTIONS', 'HEAD', 'PATCH', 'TRACE'];

    /** @var array<int, list<array{0: OperationSummary, 1: list<Segment>}>>|null */
    private ?array $index = null;

    /** @param list<OperationSummary> $ops */
    public function __construct(public readonly string $base, public readonly array $ops, public readonly int $dropped)
    {
    }

    /** @return array<string, mixed> */
    public function toCache(string $readAt): array
    {
        return [
            'v' => self::VERSION,
            'base' => $this->base,
            'ops' => array_map(
                static fn (OperationSummary $o): array => [$o->method, $o->template, $o->summary, $o->tag, $o->deprecated, $o->operationId],
                $this->ops,
            ),
            'dropped' => $this->dropped,
            'readAt' => $readAt,
        ];
    }

    /** @param array<mixed> $a null (a miss) on a wrong version or any malformed row */
    public static function fromCache(array $a): ?self
    {
        if (($a['v'] ?? null) !== self::VERSION || !is_string($a['base'] ?? null) || !is_array($a['ops'] ?? null)
            || !is_int($a['dropped'] ?? null) || $a['dropped'] < 0) {
            return null;
        }
        $ops = [];
        foreach ($a['ops'] as $r) {
            if (!is_array($r) || count($r) !== 6 || !is_string($r[0] ?? null) || !in_array($r[0], self::METHODS, true)
                || !is_string($r[1] ?? null) || !str_starts_with($r[1], '/') || !is_string($r[2] ?? null)
                || !is_string($r[3] ?? null) || !is_bool($r[4] ?? null) || !is_string($r[5] ?? null)) {
                return null;
            }
            $ops[] = new OperationSummary($r[0], $r[1], $r[2], $r[3], $r[4], $r[5]);
        }

        return new self($a['base'], $ops, $a['dropped']);
    }

    /**
     * Operations indexed by segment count, templates already stripped of the base path: a lookup scans one bucket.
     *
     * @return array<int, list<array{0: OperationSummary, 1: list<Segment>}>>
     */
    public function bySegments(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }
        $base = self::parts($this->base);
        $index = [];
        foreach ($this->ops as $op) {
            $parts = self::parts($op->template);
            if ($base !== [] && array_slice($parts, 0, count($base)) === $base) {
                $parts = array_slice($parts, count($base));
            }
            $index[count($parts)][] = [$op, array_map(Segment::fromTemplate(...), $parts)];
        }
        ksort($index);

        return $this->index = $index;
    }

    /** @return list<string> */
    public static function parts(string $path): array
    {
        return array_values(array_filter(explode('/', $path), static fn (string $p): bool => $p !== ''));
    }
}
