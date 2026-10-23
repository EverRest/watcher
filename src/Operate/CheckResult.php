<?php

declare(strict_types=1);

namespace Watcher\Operate;

/** One line of the check report. `reason` is a fixed-vocabulary phrase: never a secret, URL, log text or raw exception message. */
final class CheckResult
{
    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $reason = '',
    ) {
    }

    public static function pass(string $name, string $reason = ''): self
    {
        return new self($name, 'pass', $reason);
    }

    public static function fail(string $name, string $reason): self
    {
        return new self($name, 'fail', $reason);
    }

    public static function skip(string $name, string $reason): self
    {
        return new self($name, 'skip', $reason);
    }

    /** @return array{name: string, status: string, reason: string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'status' => $this->status, 'reason' => $this->reason];
    }
}
