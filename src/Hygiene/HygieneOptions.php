<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

use DateTimeImmutable;

/** What one `watcher hygiene` invocation asks for (contracts/cli.md). */
final class HygieneOptions
{
    public const REPORTS = ['privacy', 'shape', 'proposals'];

    /**
     * Options from the strings of a command line; the message of the exception is what the user is told.
     *
     * @param string|null $report comma-separated report names, null for all
     * @throws \InvalidArgumentException
     */
    public static function fromInput(?string $report, ?string $decline, ?DateTimeImmutable $now, bool $notify, bool $dryRun = false): self
    {
        $reports = self::REPORTS;
        if ($report !== null) {
            $reports = array_values(array_unique(array_map('trim', explode(',', $report))));
            foreach ($reports as $name) {
                if (!in_array($name, self::REPORTS, true)) {
                    throw new \InvalidArgumentException('unknown report: ' . $name . ' (use ' . implode(', ', self::REPORTS) . ')');
                }
            }
        }

        return new self($reports, $notify, $decline === '' ? null : $decline, $now, $dryRun);
    }

    /**
     * @param list<string> $reports any of {@see self::REPORTS}
     * @param bool $notify post each non-empty report to the notice destination
     * @param string|null $decline a proposal id to decline instead of producing reports
     * @param bool $dryRun read-only: no lock that blocks a real pass, nothing written, nothing sent
     */
    public function __construct(
        public readonly array $reports = self::REPORTS,
        public readonly bool $notify = false,
        public readonly ?string $decline = null,
        public readonly ?DateTimeImmutable $now = null,
        public readonly bool $dryRun = false,
    ) {
    }
}
