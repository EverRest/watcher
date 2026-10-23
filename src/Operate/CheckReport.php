<?php

declare(strict_types=1);

namespace Watcher\Operate;

final class CheckReport
{
    /** @param list<CheckResult> $results */
    public function __construct(public readonly array $results)
    {
    }

    public function failed(): int
    {
        return count(array_filter($this->results, static fn (CheckResult $r): bool => $r->status === 'fail'));
    }

    public function ok(): bool
    {
        return $this->failed() === 0;
    }

    /** 0 all pass/skip, 1 at least one fail. */
    public function exitCode(): int
    {
        return $this->ok() ? 0 : 1;
    }

    /** @return array{schema: int, ok: bool, checks: list<array{name: string, status: string, reason: string}>} */
    public function toArray(): array
    {
        return [
            'schema' => 1,
            'ok' => $this->ok(),
            'checks' => array_map(static fn (CheckResult $r): array => $r->toArray(), $this->results),
        ];
    }

    public function text(): string
    {
        $out = '';
        foreach ($this->results as $r) {
            $out .= rtrim(sprintf('%-14s %-4s  %s', $r->name, strtoupper($r->status), $r->reason)) . "\n";
        }

        return $out . ($this->ok() ? 'result: ok' : 'result: ' . $this->failed() . ' failed') . "\n";
    }
}
