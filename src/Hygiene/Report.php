<?php

declare(strict_types=1);

namespace Watcher\Hygiene;

/** One of the four reports (spec 006). The body holds identifiers, categories, levels, counts, shares and times; `Hygiene` adds the envelope. */
interface Report
{
    /** `privacy`, `shape` or `proposals`. */
    public function name(): string;

    /** Nothing to say: it is not sent (FR-008). */
    public function isEmpty(): bool;

    /** @return array<string, mixed> the fields of contracts/report-schema.md for this report */
    public function toArray(): array;

    /**
     * Human form, built by code from the same fields.
     *
     * @param bool $notice true for a chat message: every log-derived string is escaped like the digest's (see {@see ReportText})
     */
    public function text(bool $notice = false): string;
}
